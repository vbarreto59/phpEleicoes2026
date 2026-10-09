"""
Lê o CSV de zonas eleitorais do TRE e insere na tabela `zonas_enderecos`.
O campo `id` no formato '17-0119' é convertido em numeroZona = 119.
"""

import csv
import sys
import os
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

ARQUIVO_PADRAO = "zonas-eleitorais-pe.csv"


def parse_zona(id_str: str):
    """Extrai o número da zona: '17-0119' -> 119."""
    partes = id_str.strip().split('-')
    if len(partes) != 2:
        return None
    try:
        return int(partes[1])
    except ValueError:
        return None


def importar(arquivo: str):
    if not os.path.exists(arquivo):
        print(f"ERRO: arquivo '{arquivo}' não encontrado.")
        sys.exit(1)

    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()

    inseridos = 0
    ignorados = 0
    erros = 0

    # utf-8-sig remove BOM do Excel
    with open(arquivo, 'r', encoding='utf-8-sig', newline='') as f:
        reader = csv.DictReader(f)

        # Normaliza cabeçalho (minúsculo, sem espaços)
        colunas_norm = [c.strip().lower() for c in reader.fieldnames]
        mapa = {norm: orig for norm, orig in zip(colunas_norm, reader.fieldnames)}

        # Colunas obrigatórias
        for obrig in ('id', 'endereco_tse', 'nome_municipio', 'uf'):
            if obrig not in colunas_norm:
                print(f"ERRO: coluna '{obrig}' não encontrada. "
                      f"Colunas do arquivo: {reader.fieldnames}")
                sys.exit(1)

        for i, linha in enumerate(reader, start=2):
            numero_zona = parse_zona(linha[mapa['id']])
            if numero_zona is None:
                ignorados += 1
                continue

            try:
                cursor.execute("""
                    INSERT INTO zonas_enderecos
                        (codigo_tse, numeroZona, endereco_tse, cep,
                         bairro, nomeMunicipio, uf, municipio_id)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        endereco_tse  = VALUES(endereco_tse),
                        cep           = VALUES(cep),
                        bairro        = VALUES(bairro),
                        nomeMunicipio = VALUES(nomeMunicipio),
                        uf            = VALUES(uf),
                        municipio_id  = VALUES(municipio_id)
                """, (
                    linha[mapa['id']].strip(),
                    numero_zona,
                    (linha.get(mapa.get('endereco_tse', ''), '') or '').strip(),
                    (linha.get(mapa.get('cep', ''), '') or '').strip(),
                    (linha.get(mapa.get('bairro', ''), '') or '').strip(),
                    linha[mapa['nome_municipio']].strip(),
                    linha[mapa['uf']].strip().upper(),
                    int(linha[mapa['municipio_id']].strip())
                        if mapa.get('municipio_id') and linha.get(mapa['municipio_id'], '').strip()
                        else None
                ))
                inseridos += 1
            except Exception as e:
                erros += 1
                if erros <= 10:
                    print(f"  Erro na linha {i}: {e}")

    conn.commit()
    cursor.close()
    conn.close()

    print("------------------------------------------------------------")
    print(f"Arquivo:   {arquivo}")
    print(f"Inseridos: {inseridos}")
    print(f"Ignorados: {ignorados}")
    print(f"Erros:     {erros}")


if __name__ == "__main__":
    arquivo = sys.argv[1] if len(sys.argv) >= 2 else ARQUIVO_PADRAO
    importar(arquivo)