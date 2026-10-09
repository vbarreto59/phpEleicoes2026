"""
Geocodifica os endereços das zonas eleitorais usando Nominatim (OpenStreetMap).
Atualiza as colunas latitude/longitude na tabela zonas_enderecos.

Banco de dados: REMOTO
Progresso: barra em tempo real com %, velocidade, ETA e distribuição por estratégia.
"""

import json
import os
import sys
import time
import requests
import mysql.connector

# ------------------------------------------------------------
# Configuração do banco REMOTO
# ------------------------------------------------------------
DB_CONFIG = {
    'host':     '189.1.1.185',
    'port':     3306,
    'user':     'cli213_vbarreto',
    'password': 'P@nk2025',
    'database': 'cli213_eleicoes',
    'charset':  'utf8mb4',
    'use_pure': True,
    'connection_timeout': 15,
}

CACHE_FILE = 'geocode_cache.json'
USER_AGENT = 'eleicoes-pe-app/1.0 (contato@exemplo.com)'
SLEEP_SEC  = 1.1       # Nominatim exige no máximo 1 req/s
TIMEOUT    = 15
LIMITE     = None      # ex.: 5 para testar
FORCAR     = False     # True = regeocodifica tudo, ignora cache e status
DEBUG      = False     # True = imprime cada query tentada (deixa a saída verbosa)

NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search'


# ============================================================
# UTILITÁRIOS DE PROGRESSO
# ============================================================
def fmt_num(n):
    return f"{n:,}".replace(",", ".")

def fmt_tempo(seg):
    seg = int(seg)
    h, r = divmod(seg, 3600)
    m, s = divmod(r, 60)
    if h > 0:
        return f"{h:d}h{m:02d}m{s:02d}s"
    if m > 0:
        return f"{m:d}m{s:02d}s"
    return f"{s:d}s"

def barra(pct, largura=28):
    pct = max(0.0, min(100.0, pct))
    cheio = int(largura * pct / 100)
    return "█" * cheio + "░" * (largura - cheio)


def mostrar_progresso(i, total, ok, falha, cache_hits,
                      decorrido, largura_barra=28, final=False):
    """
    Desenha a linha de progresso sobrescrevendo a anterior (\\r).
    """
    pct = (i / total * 100.0) if total > 0 else 0.0
    vel = i / decorrido if decorrido > 0 else 0
    restante = total - i
    eta = (restante / vel) if vel > 0 else 0

    linha = (
        f"\r  [{barra(pct, largura_barra)}] "
        f"{pct:5.1f}%  "
        f"{i:>5}/{total:<5}  "
        f"✔ {ok:<4} ✘ {falha:<4} "
        f"💾 {cache_hits:<4}  "
        f"{vel:5.1f} z/s  "
        f"decorrido {fmt_tempo(decorrido):>7}  "
        f"ETA {fmt_tempo(eta):>7}"
    )

    # Trunca para caber no terminal
    try:
        largura_term = os.get_terminal_size().columns
    except OSError:
        largura_term = 140
    sys.stdout.write(linha[:largura_term])
    sys.stdout.flush()

    if final:
        sys.stdout.write("\n")


# ============================================================
# CACHE
# ============================================================
def carregar_cache():
    if os.path.exists(CACHE_FILE):
        try:
            with open(CACHE_FILE, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception as e:
            print(f"⚠  Cache corrompido ({e}), recomeçando do zero.")
    return {}

def salvar_cache(cache):
    tmp = CACHE_FILE + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(cache, f, ensure_ascii=False, indent=2)
    os.replace(tmp, CACHE_FILE)


# ============================================================
# ESTRATÉGIAS DE QUERY
# ============================================================
def montar_estrategias(endereco, bairro, municipio, uf, cep):
    """
    Retorna lista de tuplas (nivel, query).
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

    add('1_completo',       endereco, bairro, municipio, uf, cep)
    add('2_end_mun',        endereco, municipio, uf)
    add('3_end_bairro_mun', endereco, bairro, municipio, uf)
    add('4_bairro_mun',     bairro, municipio, uf)
    if cep:
        add('5_cep', f'CEP {cep}', municipio, uf)
    add('6_municipio',      municipio, uf)

    # Remove duplicatas preservando a ordem
    vistas, unicas = set(), []
    for nivel, q in estrategias:
        if q not in vistas:
            vistas.add(q)
            unicas.append((nivel, q))
    return unicas


# ============================================================
# NOMINATIM
# ============================================================
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
        print(f"\n    ⚠ ERRO HTTP: {e}")
    except Exception as e:
        print(f"\n    ⚠ ERRO: {e}")
    return None, None


# ============================================================
# PROCESSAMENTO DE UMA ZONA
# ============================================================
def processar_zona(z, cache, contadores):
    """
    Tenta geocodificar uma zona usando as estratégias em cascata.
    Atualiza contadores['cache'] quando bate no cache.
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
                contadores['cache'] += 1
                if DEBUG:
                    print(f"\n    [cache {nivel}] {query}")
                return item['lat'], item['lng'], nivel
            else:
                # já sabemos que essa estratégia falhou → pula
                continue

        if DEBUG:
            print(f"\n    [{nivel}] {query}")

        lat, lng = geocodificar(query)
        cache[chave] = {'lat': lat, 'lng': lng, 'query': query, 'nivel': nivel}
        salvar_cache(cache)
        time.sleep(SLEEP_SEC)

        if lat is not None:
            return lat, lng, nivel

    return None, None, None


# ============================================================
# MAIN
# ============================================================
def main():
    # Cabeçalho
    print("=" * 90)
    print(f"  GEOCODIFICADOR DE ZONAS ELEITORAIS — Nominatim (OSM)")
    print(f"  Destino: {DB_CONFIG['host']}:{DB_CONFIG['port']}/{DB_CONFIG['database']}")
    print(f"  Cache:   {CACHE_FILE}  |  Forçar: {FORCAR}  |  Limite: {LIMITE or 'sem limite'}")
    print("=" * 90)

    cache = carregar_cache()
    print(f"📦 Cache carregado: {fmt_num(len(cache))} entradas\n")

    # Conexão
    print(f"🔌 Conectando a {DB_CONFIG['host']} ...", end=" ", flush=True)
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        print("OK\n")
    except mysql.connector.Error as e:
        print(f"\n❌ Erro ao conectar: {e}")
        sys.exit(1)

    # Filtro
    where = "1=1" if FORCAR else "(latitude IS NULL OR longitude IS NULL)"

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
    total = len(zonas)

    if total == 0:
        print("✨ Nada a geocodificar — todas as zonas já têm coordenadas.")
        cursor.close()
        conn.close()
        return

    print(f"🎯 Zonas a geocodificar: {fmt_num(total)}\n")

    # Contadores
    contadores = {'cache': 0}
    total_ok   = 0
    total_falha = 0
    por_nivel  = {}
    inicio     = time.time()

    # Primeira pintura
    mostrar_progresso(0, total, 0, 0, 0, 0.0)

    # Loop principal
    for i, z in enumerate(zonas, start=1):
        lat, lng, nivel = processar_zona(z, cache, contadores)

        if lat is not None:
            cursor.execute(
                """UPDATE zonas_enderecos
                   SET latitude = %s,
                       longitude = %s,
                       geocodificado_em = NOW()
                   WHERE numeroZona = %s""",
                (lat, lng, z['numeroZona'])
            )
            conn.commit()
            total_ok += 1
            por_nivel[nivel] = por_nivel.get(nivel, 0) + 1
        else:
            total_falha += 1

        # Atualiza barra a cada zona
        decorrido = time.time() - inicio
        mostrar_progresso(i, total, total_ok, total_falha,
                          contadores['cache'], decorrido)

    # Barra final
    decorrido = time.time() - inicio
    mostrar_progresso(total, total, total_ok, total_falha,
                      contadores['cache'], decorrido, final=True)

    cursor.close()
    conn.close()

    # Resumo
    print("\n" + "=" * 90)
    print("  RESUMO")
    print("=" * 90)
    print(f"  Total processado   : {fmt_num(total)} zonas")
    print(f"  ✔ Geocodificadas   : {fmt_num(total_ok)}  ({total_ok/total*100:.1f}%)")
    print(f"  ✘ Não encontradas  : {fmt_num(total_falha)}  ({total_falha/total*100:.1f}%)")
    print(f"  💾 Cache (hits)    : {fmt_num(contadores['cache'])}")
    print(f"  ⏱  Tempo total     : {fmt_tempo(decorrido)}")
    if decorrido > 0:
        print(f"  ⚡ Velocidade      : {total/decorrido:.2f} zonas/s")

    if por_nivel:
        print("\n  Distribuição por estratégia (níveis que acharam):")
        for nivel, qtd in sorted(por_nivel.items()):
            print(f"    {nivel:<20} {qtd:>5} zonas")

    print(f"\n  Cache salvo em: {CACHE_FILE}")
    print("=" * 90)


if __name__ == '__main__':
    main()