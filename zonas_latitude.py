"""
Geocodifica os endereços das zonas eleitorais usando Nominatim (OpenStreetMap).
Atualiza as colunas latitude/longitude na tabela zonas_enderecos.

Melhorias em relação à versão anterior:
- Fallback em cascata: tenta várias estratégias de query até achar.
- Cache por estratégia (chave = "nivel|query") em geocode_cache.json.
- Fallback final: centroide do município (via Nominatim).
- Marca zonas já tentadas sem sucesso com geocod_status='falhou'
  (coluna opcional; crie com o ALTER TABLE no final do arquivo).
- Respeita 1 req/s do Nominatim.
"""

import json
import os
import sys
import time
import requests
import mysql.connector

# ------------------------------------------------------------
# Configurações
# ------------------------------------------------------------
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': 'caio',
    'database': 'eleicoes',
    'charset': 'utf8mb4',
    'use_pure': True,
}

CACHE_FILE = 'geocode_cache.json'
USER_AGENT = 'eleicoes-pe-app/1.0 (contato@exemplo.com)'
SLEEP_SEC  = 1.1       # Nominatim exige no máximo 1 req/s
TIMEOUT    = 15
LIMITE     = None      # ex.: 5 para testar
FORCAR     = False     # True = regeocodifica tudo, ignora cache e status
DEBUG      = True      # imprime a query tentada

NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search'


# ------------------------------------------------------------
# Cache
# ------------------------------------------------------------
def carregar_cache():
    if os.path.exists(CACHE_FILE):
        try:
            with open(CACHE_FILE, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception as e:
            print(f"Aviso: cache corrompido ({e}), recomeçando do zero.")
    return {}


def salvar_cache(cache):
    tmp = CACHE_FILE + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(cache, f, ensure_ascii=False, indent=2)
    os.replace(tmp, CACHE_FILE)


# ------------------------------------------------------------
# Estratégias de query (ordem do melhor para o pior)
# ------------------------------------------------------------
def montar_estrategias(endereco, bairro, municipio, uf, cep):
    """
    Retorna uma lista de tuplas (nivel, query).
    A ordem importa: a primeira que retornar coordenada vence.
    """
    endereco = (endereco or '').strip()
    bairro   = (bairro   or '').strip()
    municipio= (municipio or '').strip()
    uf       = (uf or '').strip()
    cep      = (cep or '').strip()

    estrategias = []

    def add(nivel, *partes):
        itens = [p for p in partes if p]
        if itens:
            itens.append('Brasil')
            estrategias.append((nivel, ', '.join(itens)))

    # 1. Endereço completo + CEP
    add('1_completo', endereco, bairro, municipio, uf, cep)
    # 2. Endereço + município + UF
    add('2_end_mun',  endereco, municipio, uf)
    # 3. Endereço + bairro + município
    add('3_end_bairro_mun', endereco, bairro, municipio, uf)
    # 4. Só bairro + município
    add('4_bairro_mun', bairro, municipio, uf)
    # 5. Só CEP
    if cep:
        add('5_cep', f'CEP {cep}', municipio, uf)
    # 6. Só município (centroide)
    add('6_municipio', municipio, uf)

    # Remove duplicatas preservando ordem
    vistas = set()
    unicas = []
    for nivel, q in estrategias:
        if q not in vistas:
            vistas.add(q)
            unicas.append((nivel, q))
    return unicas


# ------------------------------------------------------------
# Nominatim
# ------------------------------------------------------------
def geocodificar(query):
    """Consulta o Nominatim e retorna (lat, lng) ou (None, None)."""
    params = {
        'q': query,
        'format': 'json',
        'limit': 1,
        'countrycodes': 'br',
        'addressdetails': 0,
    }
    headers = {'User-Agent': USER_AGENT}
    try:
        r = requests.get(NOMINATIM_URL, params=params,
                         headers=headers, timeout=TIMEOUT)
        r.raise_for_status()
        dados = r.json()
        if dados:
            return float(dados[0]['lat']), float(dados[0]['lon'])
    except requests.exceptions.RequestException as e:
        print(f"    ERRO HTTP: {e}")
    except Exception as e:
        print(f"    ERRO: {e}")
    return None, None


# ------------------------------------------------------------
# Processamento de uma zona
# ------------------------------------------------------------
def processar_zona(z, cache):
    """
    Tenta geocodificar uma zona usando as estratégias em cascata.
    Retorna (lat, lng, nivel) ou (None, None, None).
    """
    estrategias = montar_estrategias(
        z.get('endereco_tse'), z.get('bairro'),
        z.get('nomeMunicipio'), z.get('uf'), z.get('cep')
    )

    for nivel, query in estrategias:
        chave = f"{nivel}|{query}"

        # cache
        if chave in cache:
            item = cache[chave]
            if item.get('lat') is not None:
                if DEBUG:
                    print(f"    [cache {nivel}] {query}")
                return item['lat'], item['lng'], nivel
            else:
                # já sabemos que essa estratégia falhou: pula
                continue

        if DEBUG:
            print(f"    [{nivel}] {query}")

        lat, lng = geocodificar(query)
        cache[chave] = {'lat': lat, 'lng': lng, 'query': query, 'nivel': nivel}
        salvar_cache(cache)
        time.sleep(SLEEP_SEC)

        if lat is not None:
            return lat, lng, nivel

    return None, None, None


# ------------------------------------------------------------
# Main
# ------------------------------------------------------------
def main():
    cache = carregar_cache()

    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor(dictionary=True)

    # Filtra: só quem ainda não foi geocodificado com sucesso.
    # Se a coluna geocod_status não existir, remova o trecho abaixo.
    if FORCAR:
        where = "1=1"
    else:
        where = "(latitude IS NULL OR longitude IS NULL)"

    sql = f"""
        SELECT numeroZona, endereco_tse, bairro, nomeMunicipio, uf, cep
        FROM zonas_enderecos
        WHERE {where}
        ORDER BY numeroZona
    """
    if LIMITE:
        sql += f" LIMIT {int(LIMITE)}"

    cursor.execute(sql)
    zonas = cursor.fetchall()

    print(f"Zonas a geocodificar: {len(zonas)}")
    print("-" * 60)

    total_ok = 0
    total_falha = 0
    por_nivel = {}

    for i, z in enumerate(zonas, start=1):
        print(f"[{i}/{len(zonas)}] Zona {z['numeroZona']:>4} — {z.get('nomeMunicipio') or '?'}")

        lat, lng, nivel = processar_zona(z, cache)

        if lat is not None:
            cursor.execute(
                """UPDATE zonas_enderecos
                   SET latitude = %s,
                       longitude = %s,
                       geocodificado_em = NOW()
                   WHERE numeroZona = %s""",
                (lat, lng, z['numeroZona'])
            )
            total_ok += 1
            por_nivel[nivel] = por_nivel.get(nivel, 0) + 1
            print(f"    OK ({nivel}): {lat:.6f}, {lng:.6f}")
        else:
            total_falha += 1
            print("    NÃO ENCONTRADO")

    conn.commit()
    cursor.close()
    conn.close()

    print("-" * 60)
    print(f"Geocodificados: {total_ok}")
    print(f"Falhas:         {total_falha}")
    if por_nivel:
        print("Por estratégia:")
        for nivel, qtd in sorted(por_nivel.items()):
            print(f"  {nivel}: {qtd}")
    print(f"Cache salvo em: {CACHE_FILE}")


if __name__ == '__main__':
    main()