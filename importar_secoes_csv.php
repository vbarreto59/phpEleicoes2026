<?php
// importar_secoes_csv.php
//
// Script único que:
//   1) Cria as tabelas necessárias (votos, zonas_enderecos, secoes_enderecos)
//      se ainda não existirem, e adiciona colunas que faltarem.
//   2) Importa o CSV de locais de votação do TSE para secoes_enderecos.
//      Cada linha do CSV (um local) vira N linhas (uma por seção).
//
// Uso:
//   - Navegador: http://localhost/phpEleicoes2026/importar_secoes_csv.php
//   - Terminal:  php importar_secoes_csv.php
date_default_timezone_set('America/Sao_Paulo');
set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '512M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ------------------------------------------------------------
// Configuração
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';

$ARQUIVO       = __DIR__ . '/locaisCSV.txt';
$DELIMITADOR   = ',';
$ENCLOSURE     = '"';   // aspas duplas
$ESCAPE        = '';    // SEM escape de barra invertida (padrão futuro do PHP)
$UF_PADRAO     = 'PE';
$LIMITE_LINHAS = null;  // ex.: 5 para testar; null = tudo
$RECRIAR       = false; // true = DROP + CREATE das tabelas (CUIDADO!)

// ------------------------------------------------------------
// Conexão
// ------------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Erro ao conectar no MySQL: " . $e->getMessage() . "\n");
}

echo "============================================================\n";
echo "  IMPORTADOR DE SEÇÕES ELEITORAIS — TSE\n";
echo "============================================================\n\n";

// ------------------------------------------------------------
// 1. ESTRUTURA DO BANCO
// ------------------------------------------------------------
echo "[1/3] Criando/verificando estrutura do banco...\n";

if ($RECRIAR) {
    echo "  ⚠ RECRIAR=true → apagando tabelas existentes.\n";
    $pdo->exec("DROP TABLE IF EXISTS secoes_enderecos");
    $pdo->exec("DROP TABLE IF EXISTS zonas_enderecos");
    $pdo->exec("DROP TABLE IF EXISTS votos");
}

// ---- votos -------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS votos (
        id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        numeroZona         INT             NOT NULL,
        numeroSecao        INT             NOT NULL,
        nomeMunicipio      VARCHAR(120)    NOT NULL,
        nomeCargoPerg      VARCHAR(80)     NULL,
        nomeCandidatoResp  VARCHAR(200)    NULL,
        qtdVotos           INT             NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_zona        (numeroZona),
        KEY idx_secao       (numeroZona, numeroSecao),
        KEY idx_municipio   (nomeMunicipio),
        KEY idx_cargo       (nomeCargoPerg),
        KEY idx_candidato   (nomeCandidatoResp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "  ✓ tabela 'votos' OK\n";

// ---- zonas_enderecos ---------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS zonas_enderecos (
        numeroZona       INT           NOT NULL,
        endereco_tse     VARCHAR(255)  NULL,
        bairro           VARCHAR(120)  NULL,
        cep              VARCHAR(10)   NULL,
        nomeMunicipio    VARCHAR(120)  NULL,
        uf               CHAR(2)       NULL,
        latitude         DECIMAL(10,6) NULL,
        longitude        DECIMAL(10,6) NULL,
        geocodificado_em DATETIME      NULL,
        PRIMARY KEY (numeroZona),
        KEY idx_municipio (nomeMunicipio)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "  ✓ tabela 'zonas_enderecos' OK\n";

// ---- secoes_enderecos --------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS secoes_enderecos (
        numeroZona       INT           NOT NULL,
        numeroSecao      INT           NOT NULL,
        codigoLocal      INT           NULL,
        nomeLocal        VARCHAR(200)  NULL,
        qtdAptos         INT           NULL,
        endereco         VARCHAR(255)  NULL,
        bairro           VARCHAR(120)  NULL,
        cep              VARCHAR(10)   NULL,
        nomeMunicipio    VARCHAR(120)  NULL,
        uf               CHAR(2)       NULL,
        latitude         DECIMAL(10,6) NULL,
        longitude        DECIMAL(10,6) NULL,
        geocodificado_em DATETIME      NULL,
        PRIMARY KEY (numeroZona, numeroSecao),
        KEY idx_municipio (nomeMunicipio),
        KEY idx_bairro    (bairro),
        KEY idx_local     (codigoLocal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "  ✓ tabela 'secoes_enderecos' OK\n";

// ---- garante colunas novas em bancos antigos ---------------
function garantirColuna(PDO $pdo, string $tabela, string $coluna, string $definicao): void {
    $cols = $pdo->query("SHOW COLUMNS FROM `$tabela`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($coluna, $cols, true)) {
        $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao");
        echo "  + coluna adicionada: $tabela.$coluna\n";
    }
}

garantirColuna($pdo, 'secoes_enderecos', 'codigoLocal', 'INT NULL AFTER numeroSecao');
garantirColuna($pdo, 'secoes_enderecos', 'qtdAptos',    'INT NULL AFTER nomeLocal');
garantirColuna($pdo, 'secoes_enderecos', 'nomeLocal',   'VARCHAR(200) NULL AFTER codigoLocal');
garantirColuna($pdo, 'zonas_enderecos',  'geocodificado_em', 'DATETIME NULL');

echo "\n";

// ------------------------------------------------------------
// 2. ABRE O CSV
// ------------------------------------------------------------
echo "[2/3] Abrindo arquivo CSV...\n";

if (!file_exists($ARQUIVO)) {
    die("  ✘ Arquivo não encontrado: $ARQUIVO\n");
}

$fh = fopen($ARQUIVO, 'r');
if (!$fh) {
    die("  ✘ Não foi possível abrir o arquivo.\n");
}

// Lê o cabeçalho — passa $escape explicitamente (PHP 8.4+)
$cabecalho = fgetcsv($fh, 0, $DELIMITADOR, $ENCLOSURE, $ESCAPE);
if (!$cabecalho) {
    die("  ✘ Cabeçalho inválido.\n");
}
$cabecalho[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecalho[0]);
$idx = array_flip($cabecalho);

$esperadas = [
    'Zona', 'Código do Local', 'Nome do Local', 'Município',
    'Endereço', 'Bairro', 'Seções', 'Seção-Aptos',
    'Quantidade de Aptos', 'Latitude', 'Longitude',
];
foreach ($esperadas as $c) {
    if (!isset($idx[$c])) {
        die("  ✘ Coluna '$c' não encontrada no CSV.\n" .
            "     Encontrado: " . implode(' | ', $cabecalho) . "\n");
    }
}
echo "  ✓ Cabeçalho lido: " . count($cabecalho) . " colunas\n\n";

// ------------------------------------------------------------
// 3. IMPORTA
// ------------------------------------------------------------
echo "[3/3] Importando...\n\n";

$sql = "INSERT INTO secoes_enderecos
          (numeroZona, numeroSecao, codigoLocal, nomeLocal, qtdAptos,
           endereco, bairro, nomeMunicipio, uf,
           latitude, longitude, geocodificado_em)
        VALUES
          (:numeroZona, :numeroSecao, :codigoLocal, :nomeLocal, :qtdAptos,
           :endereco, :bairro, :municipio, :uf,
           :latitude, :longitude, :geocod)
        ON DUPLICATE KEY UPDATE
           codigoLocal   = VALUES(codigoLocal),
           nomeLocal     = VALUES(nomeLocal),
           qtdAptos      = VALUES(qtdAptos),
           endereco      = VALUES(endereco),
           bairro        = VALUES(bairro),
           nomeMunicipio = VALUES(nomeMunicipio),
           uf            = VALUES(uf),
           latitude      = COALESCE(VALUES(latitude), latitude),
           longitude     = COALESCE(VALUES(longitude), longitude),
           geocodificado_em = COALESCE(VALUES(geocodificado_em), geocodificado_em)";
$stmt = $pdo->prepare($sql);

$linha       = 1;
$locais      = 0;
$secoes      = 0;
$comCoord    = 0;
$erros       = 0;
$porZona     = [];
$porMunic    = [];
$inicio      = microtime(true);

$pdo->beginTransaction();

// Loop de leitura — passa $escape explicitamente (PHP 8.4+)
while (($row = fgetcsv($fh, 0, $DELIMITADOR, $ENCLOSURE, $ESCAPE)) !== false) {
    $linha++;

    if ($LIMITE_LINHAS !== null && $locais >= $LIMITE_LINHAS) break;
    if (count($row) < 5 || trim($row[0]) === '') continue;

    $zona        = trim($row[$idx['Zona']] ?? '');
    $codLocal    = trim($row[$idx['Código do Local']] ?? '');
    $nomeLocal   = trim($row[$idx['Nome do Local']] ?? '');
    $municipio   = trim($row[$idx['Município']] ?? '');
    $endereco    = trim($row[$idx['Endereço']] ?? '');
    $bairro      = trim($row[$idx['Bairro']] ?? '');
    $secoesStr   = trim($row[$idx['Seções']] ?? '');
    $secaoAptosS = trim($row[$idx['Seção-Aptos']] ?? '');
    $latRaw      = trim($row[$idx['Latitude']] ?? '');
    $lngRaw      = trim($row[$idx['Longitude']] ?? '');

    if ($zona === '' || $secoesStr === '') continue;

    // 0 ou vazio -> NULL
    $lat = ($latRaw === '' || (float)$latRaw === 0.0) ? null : (float)$latRaw;
    $lng = ($lngRaw === '' || (float)$lngRaw === 0.0) ? null : (float)$lngRaw;

    // "396-331, 397-332" -> [396 => 331, 397 => 332]
    $aptosPorSecao = [];
    if ($secaoAptosS !== '') {
        foreach (explode(',', $secaoAptosS) as $par) {
            $par = trim($par);
            if ($par === '' || strpos($par, '-') === false) continue;
            [$s, $a] = explode('-', $par, 2);
            $aptosPorSecao[(int)trim($s)] = (int)trim($a);
        }
    }

    $listaSecoes = array_filter(array_map('trim', explode(',', $secoesStr)));

    foreach ($listaSecoes as $sec) {
        $sec = (int)$sec;
        if ($sec <= 0) continue;

        try {
            $stmt->execute([
                ':numeroZona'  => (int)$zona,
                ':numeroSecao' => $sec,
                ':codigoLocal' => $codLocal !== '' ? (int)$codLocal : null,
                ':nomeLocal'   => $nomeLocal !== '' ? $nomeLocal : null,
                ':qtdAptos'    => $aptosPorSecao[$sec] ?? null,
                ':endereco'    => $endereco  !== '' ? $endereco  : null,
                ':bairro'      => $bairro    !== '' ? $bairro    : null,
                ':municipio'   => $municipio !== '' ? $municipio : null,
                ':uf'          => $UF_PADRAO,
                ':latitude'    => $lat,
                ':longitude'   => $lng,
                ':geocod'      => ($lat !== null && $lng !== null) ? date('Y-m-d H:i:s') : null,
            ]);
            $secoes++;
            if ($lat !== null && $lng !== null) $comCoord++;
            $porZona[$zona]       = ($porZona[$zona] ?? 0) + 1;
            $porMunic[$municipio] = ($porMunic[$municipio] ?? 0) + 1;
        } catch (Exception $e) {
            $erros++;
            echo "  ✘ linha $linha (zona $zona, seção $sec): " . $e->getMessage() . "\n";
        }
    }
    $locais++;

    if ($locais % 200 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        $t = round(microtime(true) - $inicio, 1);
        echo "  ... $locais locais / $secoes seções (${t}s)\n";
        flush();
    }
}

$pdo->commit();
fclose($fh);

$duracao = round(microtime(true) - $inicio, 1);

// ------------------------------------------------------------
// RESUMO
// ------------------------------------------------------------
echo "\n============================================================\n";
echo "  RESUMO DA IMPORTAÇÃO\n";
echo "============================================================\n";
echo "Locais processados:   $locais\n";
echo "Seções importadas:    $secoes\n";
echo "Seções com coord.:    $comCoord\n";
echo "Erros:                $erros\n";
echo "Tempo:                {$duracao}s\n";

if ($locais > 0) {
    echo "\nTop 5 municípios (seções):\n";
    arsort($porMunic);
    $i = 0;
    foreach ($porMunic as $m => $q) {
        if ($i++ >= 5) break;
        echo "  " . str_pad($m, 30) . " $q\n";
    }
}

// ------------------------------------------------------------
// CONFERÊNCIA NO BANCO
// ------------------------------------------------------------
echo "\n------------------------------------------------------------\n";
echo "  CONFERÊNCIA NO BANCO\n";
echo "------------------------------------------------------------\n";

$tot = $pdo->query("SELECT COUNT(*) FROM secoes_enderecos")->fetchColumn();
$cc  = $pdo->query("SELECT COUNT(*) FROM secoes_enderecos WHERE latitude IS NOT NULL")->fetchColumn();
$sc  = $pdo->query("SELECT COUNT(*) FROM secoes_enderecos WHERE latitude IS NULL")->fetchColumn();
$zn  = $pdo->query("SELECT COUNT(DISTINCT numeroZona) FROM secoes_enderecos")->fetchColumn();

echo "Total de linhas em secoes_enderecos: $tot\n";
echo "  com coordenada:                    $cc\n";
echo "  sem coordenada:                    $sc\n";
echo "Zonas distintas:                     $zn\n";
echo "\nPronto! Acesse editar_secoes.php para revisar/editar.\n";