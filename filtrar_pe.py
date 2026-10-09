"""
Lê o CSV 'zonas-eleitorais.csv' com dados de zonas eleitorais de todo o Brasil
e gera 'zonas-eleitorais-pe.csv' contendo apenas as linhas de Pernambuco (UF = PE).
"""

import csv
import sys
import os

# ------------------------------------------------------------
# Configurações
# ------------------------------------------------------------
ARQUIVO_ENTRADA = "zonas-eleitorais.csv"
ARQUIVO_SAIDA   = "zonas-eleitorais-pe.csv"
UF_FILTRO       = "PE"

# ------------------------------------------------------------
# Função principal
# ------------------------------------------------------------
def filtrar_por_uf(entrada: str, saida: str, uf: str):
    if not os.path.exists(entrada):
        print(f"ERRO: arquivo '{entrada}' não encontrado.")
        sys.exit(1)

    # utf-8-sig remove o BOM que o Excel costuma inserir
    with open(entrada, "r", encoding="utf-8-sig", newline="") as f_in:
        reader = csv.DictReader(f_in)

        if reader.fieldnames is None:
            print("ERRO: o arquivo parece estar vazio.")
            sys.exit(1)

        # Normaliza os nomes das colunas (remove espaços e deixa minúsculo)
        colunas_originais = reader.fieldnames
        colunas_norm = [c.strip().lower() for c in colunas_originais]

        if "uf" not in colunas_norm:
            print(f"ERRO: coluna 'uf' não encontrada. Colunas disponíveis: {colunas_originais}")
            sys.exit(1)

        # Mapeia nome normalizado -> nome original (para preservar cabeçalho)
        mapa = {norm: orig for norm, orig in zip(colunas_norm, colunas_originais)}

        with open(saida, "w", encoding="utf-8-sig", newline="") as f_out:
            writer = csv.DictWriter(f_out, fieldnames=colunas_originais)
            writer.writeheader()

            total_lido = 0
            total_pe   = 0

            for linha in reader:
                total_lido += 1
                valor_uf = (linha.get(mapa["uf"]) or "").strip().upper()
                if valor_uf == uf:
                    writer.writerow(linha)
                    total_pe += 1

    print(f"Arquivo lido:    {entrada}")
    print(f"Linhas totais:   {total_lido}")
    print(f"Linhas de {uf}:    {total_pe}")
    print(f"Arquivo gerado:  {saida}")


# ------------------------------------------------------------
# Execução
# ------------------------------------------------------------
if __name__ == "__main__":
    # Permite sobrescrever os nomes pela linha de comando:
    #   python filtrar_pe.py entrada.csv saida.csv
    if len(sys.argv) >= 2:
        ARQUIVO_ENTRADA = sys.argv[1]
    if len(sys.argv) >= 3:
        ARQUIVO_SAIDA = sys.argv[2]

    filtrar_por_uf(ARQUIVO_ENTRADA, ARQUIVO_SAIDA, UF_FILTRO)