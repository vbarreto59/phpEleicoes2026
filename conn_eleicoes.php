<?php
// ============================================================
//  conn_eleicoes.php
//  Arquivo de conexão centralizado com o banco de dados.
//  É incluído via require_once por todos os scripts do sistema.
//
//  DETECÇÃO AUTOMÁTICA DE AMBIENTE
//    - Se o servidor for localhost/127.0.0.1  → usa a config 'local'
//    - Se for qualquer outro host            → usa a config 'producao'
//
//  Compatibilidade:
//    - PHP 8.4 e anteriores  → usa PDO::MYSQL_ATTR_INIT_COMMAND
//    - PHP 8.5 e superiores  → usa Pdo\Mysql::ATTR_INIT_COMMAND
//  A escolha é feita em tempo de execução, sem warnings.
// ============================================================
date_default_timezone_set('America/Sao_Paulo');
// ============================================================
//  CONFIGURAÇÕES POR AMBIENTE
// ============================================================
$CONFIG = [
    'producao' => [
        'host'    => '189.1.1.185',
        'porta'   => 3306,
        'usuario' => 'cli213_vbarreto',
        'senha'   => 'P@nk2025',
        'banco'   => 'cli213_eleicoes',
    ],
    'local' => [
        'host'    => 'localhost',
        'porta'   => 3306,
        'usuario' => 'root',
        'senha'   => 'caio',
        'banco'   => 'eleicoes',
    ],
];

// ============================================================
//  DETECÇÃO DE AMBIENTE
// ============================================================
// Ordem de prioridade:
//   1) Constante APP_ENV   (defina antes de incluir este arquivo)
//   2) Variável de ambiente APP_ENV
//   3) Auto-detecção pelo host da requisição
// ============================================================

function __detectarAmbiente(array $config) {
    // 1) Constante definida pelo dev
    if (defined('APP_ENV')) {
        $env = strtolower(trim((string)APP_ENV));
        if (isset($config[$env])) return $env;
    }

    // 2) Variável de ambiente
    $envVar = getenv('APP_ENV');
    if ($envVar !== false && $envVar !== '') {
        $env = strtolower(trim($envVar));
        if (isset($config[$env])) return $env;
    }

    // 3) Auto-detecção pelo host
    //    Considera "local" se o host for localhost / 127.0.0.1 / ::1
    //    ou se estiver rodando em CLI
    $hostsParaChecar = [];

    if (PHP_SAPI === 'cli') {
        // Linha de comando (ex.: scripts de importação) → assume local
        return 'local';
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        // Remove a porta, se houver
        $hostsParaChecar[] = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']));
    }
    if (!empty($_SERVER['SERVER_NAME'])) {
        $hostsParaChecar[] = strtolower($_SERVER['SERVER_NAME']);
    }
    if (!empty($_SERVER['SERVER_ADDR'])) {
        $hostsParaChecar[] = strtolower($_SERVER['SERVER_ADDR']);
    }

    $hostsLocais = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    foreach ($hostsParaChecar as $h) {
        if (in_array($h, $hostsLocais, true)) {
            return 'local';
        }
        // Também considera local se o IP estiver na faixa 127.0.0.0/8
        if (preg_match('/^127\./', $h)) {
            return 'local';
        }
        // Ou se for um IP privado típico de rede interna (opcional)
        // 10.x, 192.168.x, 172.16–31.x  →  NÃO trata como local,
        // pois pode ser um servidor remoto de homologação.
    }

    // Fallback: se nada casou, assume produção
    return 'producao';
}

$AMBIENTE = __detectarAmbiente($CONFIG);

if (!isset($CONFIG[$AMBIENTE])) {
    die("Ambiente inválido: $AMBIENTE");
}

$cfg = $CONFIG[$AMBIENTE];

// ============================================================
//  VARIÁVEIS GLOBAIS
// ============================================================
$DB_HOST = $cfg['host'];
$DB_PORT = $cfg['porta'];
$DB_NAME = $cfg['banco'];
$DB_USER = $cfg['usuario'];
$DB_PASS = $cfg['senha'];

// Expõe também para outros arquivos (index.php usa isso para o banner)
$GLOBALS['APP_ENV_ATUAL']     = $AMBIENTE;
$GLOBALS['APP_DB_ATUAL']      = $DB_NAME;
$GLOBALS['APP_DB_HOST_ATUAL'] = $DB_HOST;

// ------------------------------------------------------------
// Constantes auxiliares (usadas em alguns scripts antigos)
// ------------------------------------------------------------
if (!defined('DB_HOST')) define('DB_HOST', $DB_HOST);
if (!defined('DB_PORT')) define('DB_PORT', $DB_PORT);
if (!defined('DB_NAME')) define('DB_NAME', $DB_NAME);
if (!defined('DB_USER')) define('DB_USER', $DB_USER);
if (!defined('DB_PASS')) define('DB_PASS', $DB_PASS);

if (!defined('APP_ENV'))       define('APP_ENV', $AMBIENTE);
if (!defined('APP_ENV_LOCAL')) define('APP_ENV_LOCAL', $AMBIENTE === 'local');

// ============================================================
//  CONEXÃO PDO
// ============================================================
// A constante PDO::MYSQL_ATTR_INIT_COMMAND foi depreciada no
// PHP 8.5. O código abaixo detecta automaticamente qual usar.
// ============================================================

if (defined('Pdo\\Mysql::ATTR_INIT_COMMAND')) {
    $ATTR_INIT_COMMAND = \Pdo\Mysql::ATTR_INIT_COMMAND;
} else {
    $ATTR_INIT_COMMAND = @constant('PDO::MYSQL_ATTR_INIT_COMMAND');
}

// ------------------------------------------------------------
// Opções do PDO
// ------------------------------------------------------------
$opcoesPDO = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 15,
];

if ($ATTR_INIT_COMMAND !== null) {
    $opcoesPDO[$ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
}

// ------------------------------------------------------------
// DSN
// ------------------------------------------------------------
$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";

// ------------------------------------------------------------
// Cria a conexão $pdo (nome usado por todos os scripts)
// ------------------------------------------------------------
try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $opcoesPDO);
} catch (PDOException $e) {
    // Em produção, evite exibir a mensagem crua. Aqui mantida para debug.
    http_response_code(500);
    die("Erro ao conectar ao banco de dados: " . $e->getMessage());
}

// ============================================================
//  FIM
// ============================================================