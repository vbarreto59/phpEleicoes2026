"""
Lê o CSV de zonas eleitorais do TRE e insere na tabela `zonas_enderecos`.
O campo `id` no formato '17-0119' é convertido em numeroZona = 119.

Banco de dados: REMOTO
Progresso: barra em tempo real com %, velocidade, ETA e resumo final.
"""

import csv
import sys
import os
import time
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
    'autocommit': False,
}

ARQUIVO_PADRAO = "zonas-eleitorais-pe.csv"
BATCH_COMMIT   = 200   # commit a cada N linhas processadas


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


def mostrar_progresso(lidos, total_bytes, ok, ign, err,
                      decorrido, largura_barra=28, final=False):
    """
    Desenha a linha de progresso sobrescrevendo a anterior (\\r).
    Baseada em bytes lidos do arquivo (mais suave que contar linhas).
    """
    pct = (lidos / total_bytes * 100.0) if total_bytes > 0 else 0.0
    total_linhas = ok + ign + err
    vel = total_linhas / decorrido if decorrido > 0 else 0
    restante_bytes = max(0, total_bytes - lidos)
    eta = (restante_bytes / (lidos / decorrido)) if lidos > 0 else 0

    linha = (
        f"\r  [{barra(pct, largura_barra)}] "
        f"{pct:5.1f}%  "
        f"✔ {ok:<5} ⊘ {ign:<4} ✘ {err:<3}  "
        f"{vel:6.0f} l/s  "
        f"decorrido {fmt_tempo(decorrido):>7}  "
        f"ETA {fmt_tempo(eta):>7}"
    )

    # Trunca ao tamanho do terminal
    try:
        largura_term = os.get_terminal_size().columns
    except OSError:
        largura_term = 140
    sys.stdout.write(linha[:largura_term])
    sys.stdout.flush()
    if final:
        sys.stdout.write("\n")


# ============================================================
# PARSE
# ============================================================
def parse_zona(id_str: str):
    """Extrai o número da zona: '17-0119' -> 119."""
    partes = id_str.strip().split('-')
    if len(partes) != 2:
        return None
    try:
        return int(partes[1])
    except ValueError:
        return None


# ============================================================
# IMPORTAÇÃO
# ============================================================
def importar(arquivo: str):
    if not os.path.exists(arquivo):
        print(f"❌ ERRO: arquivo '{arquivo}' não encontrado.")
        sys.exit(1)

    total_bytes = os.path.getsize(arquivo)
    mb = total_bytes / (1024 * 1024)

    # Cabeçalho
    print("=" * 90)
    print(f"  IMPORTADOR DE ZONAS ELEITORAIS — TRE")
    print(f"  Arquivo: {arquivo}  ({mb:.2f} MB)")
    print(f"  Destino: {DB_CONFIG['host']}:{DB_CONFIG['port']}/{DB_CONFIG['database']}")
    print(f"  Tabela:  zonas_enderecos  |  Commit em lote de {BATCH_COMMIT}")
    print("=" * 90)

    # Conexão
    print(f"🔌 Conectando a {DB_CONFIG['host']} ...", end=" ", flush=True)
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        print("OK\n")
    except mysql.connector.Error as e:
        print(f"\n❌ Erro ao conectar: {e}")
        sys.exit(1)

    inseridos  = 0
    ignorados  = 0
    erros      = 0
    contador   = 0
    inicio     = time.time()

    # Primeira pintura
    mostrar_progresso(0, total_bytes, 0, 0, 0, 0.0)

    try:
        # utf-8-sig remove BOM do Excel
        with open(arquivo, 'r', encoding='utf-8-sig', newline='') as f:
            reader = csv.DictReader(f)

            # Normaliza cabeçalho (minúsculo, sem espaços)
            colunas_norm = [c.strip().lower() for c in reader.fieldnames]
            mapa = {norm: orig for norm, orig in zip(colunas_norm, reader.fieldnames)}

            # Valida colunas obrigatórias
            for obrig in ('id', 'endereco_tse', 'nome_municipio', 'uf'):
                if obrig not in colunas_norm:
                    print(f"\n❌ Coluna '{obrig}' não encontrada.")
                    print(f"   Colunas do arquivo: {reader.fieldnames}")
                    return
            print(f"✓ Cabeçalho OK ({len(colunas_norm)} colunas)\n")

            for i, linha in enumerate(reader, start=2):
                numero_zona = parse_zona(linha[mapa['id']])
                if numero_zona is None:
                    ignorados += 1
                else:
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
                                if mapa.get('municipio_id')
                                   and linha.get(mapa['municipio_id'], '').strip()
                                else None
                        ))
                        inseridos += 1
                    except Exception as e:
                        erros += 1
                        if erros <= 10:
                            print(f"\n  ⚠ Erro linha {i}: {e}")

                contador += 1

                # Commit em lote (não perde progresso em caso de falha)
                if contador % BATCH_COMMIT == 0:
                    conn.commit()

                # Atualiza barra a cada linha
                lidos = f.buffer.tell() if hasattr(f, 'buffer') else f.tell()
                decorrido = time.time() - inicio
                mostrar_progresso(lidos, total_bytes,
                                  inseridos, ignorados, erros, decorrido)

        # Commit final
        conn.commit()

        # Barra final em 100%
        decorrido = time.time() - inicio
        mostrar_progresso(total_bytes, total_bytes,
                          inseridos, ignorados, erros,
                          decorrido, final=True)

    except KeyboardInterrupt:
        print("\n\n⚠  Interrompido pelo usuário (Ctrl+C).")
        print("   Fazendo commit do que já foi processado...")
        conn.commit()

    finally:
        cursor.close()
        conn.close()

    # Resumo
    decorrido = time.time() - inicio
    total_proc = inseridos + ignorados + erros

    print("\n" + "=" * 90)
    print("  RESUMO")
    print("=" * 90)
    print(f"  Arquivo       : {arquivo}")
    print(f"  Linhas lidas  : {fmt_num(total_proc)}")
    print(f"  ✔ Inseridos   : {fmt_num(inseridos)}")
    print(f"  ⊘ Ignorados   : {fmt_num(ignorados)}  (id malformado)")
    print(f"  ✘ Erros       : {fmt_num(erros)}")
    print(f"  ⏱  Tempo       : {fmt_tempo(decorrido)}")
    if decorrido > 0:
        print(f"  ⚡ Velocidade  : {total_proc/decorrido:,.0f} linhas/s")
    print("=" * 90)


# ============================================================
# MAIN
# ============================================================
if __name__ == "__main__":
    arquivo = sys.argv[1] if len(sys.argv) >= 2 else ARQUIVO_PADRAO
    importar(arquivo)