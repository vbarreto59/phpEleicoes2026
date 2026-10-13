<?php
// ------------------------------------------------------------
// comparar_colunas.php
//
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ------------------------------------------------------------
// Sessão
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__u = [
    'id'    => $_SESSION['usuario_id']    ?? null,
    'nome'  => $_SESSION['usuario_nome']  ?? '—',
    'login' => $_SESSION['usuario_login'] ?? '',
];

$perfilSessao = strtolower(trim((string)($_SESSION['usuario_perfil'] ?? '')));

$__ehSuper = in_array($perfilSessao, ['super', 'superadmin', 'super_admin'], true);
$__ehAdmin = $__ehSuper || in_array($perfilSessao, ['admin', 'administrador', 'adm'], true);
$__ehDono  = $__ehAdmin;
$isAdmin   = $__ehAdmin;

if ($__ehSuper) {
    $__classePerfil = 'super';
    $__rotuloPerfil = 'SUPERADMIN';
} elseif ($__ehAdmin) {
    $__classePerfil = 'admin';
    $__rotuloPerfil = 'ADMIN';
} else {
    $__classePerfil = 'visitante';
    $__rotuloPerfil = 'VISITANTE';
}

$LIMITE_RESULTADO = 5000;
$MAX_CANDIDATOS   = 3;

// ------------------------------------------------------------
// Endpoints AJAX (cascata)
// ------------------------------------------------------------
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($_GET['action'] === 'municipios') {
            $stmt = $pdo->query(
                "SELECT DISTINCT nomeMunicipio FROM votos ORDER BY nomeMunicipio"
            );
            echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
            exit;
        }

        if ($_GET['action'] === 'zonas') {
            $municipio = $_GET['municipio'] ?? '';
            $stmt = $pdo->prepare(
                "SELECT DISTINCT numeroZona
                 FROM votos
                 WHERE nomeMunicipio = :m
                 ORDER BY numeroZona"
            );
            $stmt->execute([':m' => $municipio]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['erro' => $e->getMessage()]);
        exit;
    }
}

// ------------------------------------------------------------
// Filtros recebidos
// ------------------------------------------------------------
$municipio   = $_GET['municipio']   ?? '';
$zona        = $_GET['zona']        ?? '';
$cargo       = $_GET['cargo']       ?? '';
$exibirZonas = isset($_GET['exibir_zonas']) && $_GET['exibir_zonas'] === '1';

$candidatosSelecionados = $_GET['candidato'] ?? [];
if (!is_array($candidatosSelecionados)) {
    $candidatosSelecionados = ($candidatosSelecionados !== '')
        ? [$candidatosSelecionados]
        : [];
}
$candidatosSelecionados = array_values(array_unique(array_filter(
    array_map('trim', $candidatosSelecionados),
    fn($v) => $v !== ''
)));
if (count($candidatosSelecionados) > $MAX_CANDIDATOS) {
    $candidatosSelecionados = array_slice($candidatosSelecionados, 0, $MAX_CANDIDATOS);
}

// ------------------------------------------------------------
// WHERE
// ------------------------------------------------------------
$filtros = [];
$params  = [];

if ($municipio !== '') {
    $filtros[] = "v.nomeMunicipio = ?";
    $params[] = $municipio;
}
if ($zona !== '') {
    $filtros[] = "v.numeroZona = ?";
    $params[] = (int)$zona;
}
if ($candidatosSelecionados) {
    $qtd = count($candidatosSelecionados);
    $ph  = implode(', ', array_fill(0, $qtd, '?'));
    $filtros[] = "v.nomeCandidatoResp IN ($ph)";
    foreach ($candidatosSelecionados as $nome) {
        $params[] = $nome;
    }
}
if ($cargo !== '') {
    $filtros[] = "v.nomeCargoPerg = ?";
    $params[] = $cargo;
}

$where     = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';
$temFiltro = (bool)$filtros;

// ------------------------------------------------------------
// Consulta principal
// ------------------------------------------------------------
$linhasBrutas  = [];
$truncado      = false;
$totalGeral    = 0;
$totalLinhas   = 0;
$totalPorCand  = [];

if ($temFiltro) {
    if ($exibirZonas) {
        $sql = "SELECT
                    v.nomeMunicipio,
                    v.numeroZona,
                    v.nomeCargoPerg,
                    v.nomeCandidatoResp,
                    SUM(v.qtdVotos) AS total
                FROM votos v
                $where
                GROUP BY v.nomeMunicipio, v.numeroZona,
                         v.nomeCargoPerg, v.nomeCandidatoResp
                ORDER BY v.nomeMunicipio, v.numeroZona,
                         v.nomeCandidatoResp
                LIMIT " . ($LIMITE_RESULTADO + 1);
    } else {
        $sql = "SELECT
                    v.nomeMunicipio,
                    v.nomeCargoPerg,
                    v.nomeCandidatoResp,
                    SUM(v.qtdVotos) AS total
                FROM votos v
                $where
                GROUP BY v.nomeMunicipio,
                         v.nomeCargoPerg, v.nomeCandidatoResp
                ORDER BY v.nomeMunicipio,
                         v.nomeCandidatoResp
                LIMIT " . ($LIMITE_RESULTADO + 1);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $linhasBrutas = $stmt->fetchAll();

    if (count($linhasBrutas) > $LIMITE_RESULTADO) {
        $truncado = true;
        $linhasBrutas = array_slice($linhasBrutas, 0, $LIMITE_RESULTADO);
    }
}

// ------------------------------------------------------------
// PIVOT — por município (padrão) ou município + zona
// ------------------------------------------------------------
$pivot = [];
foreach ($linhasBrutas as $r) {
    if ($exibirZonas) {
        $chave = $r['nomeMunicipio'] . '|' . $r['numeroZona'];

        if (!isset($pivot[$chave])) {
            $pivot[$chave] = [
                'municipio' => $r['nomeMunicipio'],
                'zona'      => (int)$r['numeroZona'],
                'cargo'     => $r['nomeCargoPerg'],
                'votos'     => [],
            ];
        }
    } else {
        $chave = $r['nomeMunicipio'];

        if (!isset($pivot[$chave])) {
            $pivot[$chave] = [
                'municipio' => $r['nomeMunicipio'],
                'cargo'     => $r['nomeCargoPerg'],
                'votos'     => [],
            ];
        }
    }

    $cand = $r['nomeCandidatoResp'];
    $tot  = (int)$r['total'];

    $pivot[$chave]['votos'][$cand] = ($pivot[$chave]['votos'][$cand] ?? 0) + $tot;

    $totalPorCand[$cand] = ($totalPorCand[$cand] ?? 0) + $tot;
    $totalGeral += $tot;
}

$totalLinhas = count($pivot);

// ------------------------------------------------------------
// ESTATÍSTICAS POR CANDIDATO
// ------------------------------------------------------------
$statsCand = [];
foreach ($candidatosSelecionados as $c) {
    $statsCand[$c] = [
        'nome'    => $c,
        'votos'   => 0,
        'ganhou'  => 0,
        'empatou' => 0,
        'perdeu'  => 0,
        'pct'     => 0,
    ];
}

foreach ($pivot as $linha) {
    $votosLinha = $linha['votos'];
    if (!$votosLinha) continue;

    $maxLinha = max($votosLinha);
    if ($maxLinha <= 0) continue;

    $vencedores = array_keys(array_filter($votosLinha, fn($v) => $v === $maxLinha));
    $empate = (count($vencedores) > 1);

    foreach ($candidatosSelecionados as $c) {
        $v = (int)($votosLinha[$c] ?? 0);
        $statsCand[$c]['votos'] += $v;

        if ($v === $maxLinha && $v > 0) {
            if ($empate) $statsCand[$c]['empatou']++;
            else         $statsCand[$c]['ganhou']++;
        } else {
            $statsCand[$c]['perdeu']++;
        }
    }
}

foreach ($statsCand as &$s) {
    $s['pct'] = $totalGeral > 0 ? ($s['votos'] / $totalGeral) * 100 : 0;
}
unset($s);

// ------------------------------------------------------------
// Colunas dos candidatos
// ------------------------------------------------------------
$candidatosCols = $candidatosSelecionados;
if (!$candidatosCols && $totalPorCand) {
    $candidatosCols = array_keys($totalPorCand);
}

$coresCandidato = [];
$paleta = [
    ['bg' => '#ffe0e0', 'tx' => '#8a0000', 'base' => '#dc3545'],
    ['bg' => '#e0ecff', 'tx' => '#003a8a', 'base' => '#0d6efd'],
    ['bg' => '#e0f7e3', 'tx' => '#1e6e2a', 'base' => '#28a745'],
];
foreach ($candidatosCols as $i => $nome) {
    $coresCandidato[$nome] = $paleta[$i % count($paleta)];
}

// Ordena o pivot — apenas município e (opcional) zona
uasort($pivot, function ($a, $b) use ($exibirZonas) {
    if ($exibirZonas) {
        return [$a['municipio'], $a['zona'] ?? 0]
           <=> [$b['municipio'], $b['zona'] ?? 0];
    }
    return [$a['municipio']] <=> [$b['municipio']];
});

// ------------------------------------------------------------
// KPIs gerais
// ------------------------------------------------------------
$totalBanco = (int)$pdo->query(
    "SELECT COALESCE(SUM(qtdVotos),0) FROM votos"
)->fetchColumn();

// ------------------------------------------------------------
// Listas para os selects
// ------------------------------------------------------------
$municipios = $pdo->query(
    "SELECT DISTINCT nomeMunicipio FROM votos ORDER BY nomeMunicipio"
)->fetchAll(PDO::FETCH_COLUMN);

$candidatos = $pdo->query(
    "SELECT DISTINCT nomeCandidatoResp FROM votos ORDER BY nomeCandidatoResp"
)->fetchAll(PDO::FETCH_COLUMN);

$cargos = $pdo->query(
    "SELECT DISTINCT nomeCargoPerg FROM votos ORDER BY nomeCargoPerg"
)->fetchAll(PDO::FETCH_COLUMN);

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Comparação de Candidatos por Município/Zona</title>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }

    /* ===== Barra de usuário ===== */
    .barra-usuario {
        display: flex;
        align-items: center;
        gap: 12px;
        background: #fff;
        padding: 10px 16px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.08);
        margin-bottom: 18px;
        font-size: 13px;
    }
    .barra-usuario.super { border-left: 5px solid #6f42c1; }
    .barra-usuario.admin { border-left: 5px solid #0d6efd; }
    .nome-user { font-weight: 600; }
    .perfil-tag {
        background: #e9ecef; color: #495057;
        padding: 2px 10px; border-radius: 12px;
        font-size: 11px; font-weight: 600; text-transform: uppercase;
    }
    .perfil-tag.admin { background:#0d6efd; color:#fff; }
    .perfil-tag.super { background:#6f42c1; color:#fff; }
    .badge-super     { background:#6f42c1; color:#fff; padding:2px 10px; border-radius:12px; font-size:11px; }
    .badge-admin     { background:#0d6efd; color:#fff; padding:2px 10px; border-radius:12px; font-size:11px; }
    .badge-visitante { background:#e9ecef; color:#495057; padding:2px 10px; border-radius:12px; font-size:11px; }
    .link-debug {
        margin-left: auto; color: #6c757d;
        text-decoration: none; font-size: 12px;
    }
    .link-debug:hover { color: #0d6efd; text-decoration: underline; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:18px; }
    .kpi {
        background:#fff; border-radius:8px; padding:14px 22px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:180px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:24px; font-weight:bold; color:#0069d9; margin-top:2px; }
    .kpi .sub    { font-size:11px; color:#888; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }

    .kpis-candidatos { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:22px; }
    .kpi-cand {
        background:#fff; border-radius:10px; padding: 16px 22px;
        box-shadow: 0 2px 6px rgba(0,0,0,.10);
        min-width: 260px; flex: 1 1 260px;
        border-top: 6px solid #999;
    }
    .kpi-cand .cabecalho { display:flex; align-items:center; gap:8px; margin-bottom:12px; }
    .kpi-cand .cabecalho .dot { width:14px; height:14px; border-radius:4px; flex-shrink:0; }
    .kpi-cand .cabecalho .nome {
        font-size:15px; font-weight:bold;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    .kpi-cand .linha-principal {
        display:flex; align-items:baseline; gap:8px; margin-bottom:10px;
    }
    .kpi-cand .linha-principal .num {
        font-size:28px; font-weight:bold; font-family:'Courier New', monospace;
    }
    .kpi-cand .linha-principal .pct { font-size:14px; color:#666; }
    .kpi-cand .grid-mini {
        display:grid; grid-template-columns:repeat(3, 1fr); gap:8px;
        padding-top:10px; border-top:1px solid #f0f0f0;
    }
    .kpi-cand .grid-mini .item { text-align:center; }
    .kpi-cand .grid-mini .item .v {
        font-size:16px; font-weight:bold; font-family:'Courier New', monospace;
    }
    .kpi-cand .grid-mini .item .r {
        font-size:10px; text-transform:uppercase; color:#888;
        letter-spacing:.5px; margin-top:2px;
    }
    .kpi-cand .grid-mini .ganhou  .v { color: #1c7a34; }
    .kpi-cand .grid-mini .empatou .v { color: #b8860b; }
    .kpi-cand .grid-mini .perdeu  .v { color: #a71d2a; }

    form { background:#fff; padding:15px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px; }
    label { display:inline-block; margin-right:10px; font-weight:bold; }
    select, button { padding:6px 8px; margin:4px 10px 4px 0; border-radius:4px; border:1px solid #ccc; font-size:14px; }
    button { cursor:pointer; border:none; }
    .btn-consultar { background:#0069d9; color:#fff; padding:8px 18px; font-weight:bold; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none; padding:8px 14px; border-radius:4px; display:inline-block; margin:4px 0; font-weight:bold; }
    .btn-limpar:hover { background:#5a6268; }
    .filtros { display:flex; flex-wrap:wrap; align-items:center; }

    /* Toggle "Exibir zonas" */
    .chk-toggle {
        display:inline-flex;
        align-items:center;
        gap:8px;
        background:#f1f5fb;
        border:1px solid #cdd9e8;
        padding:7px 14px;
        border-radius:6px;
        cursor:pointer;
        font-weight:600;
        font-size:13px;
        color:#0053b3;
        margin:4px 10px 4px 0;
        user-select:none;
        transition: background .15s;
    }
    .chk-toggle:hover { background:#e4ecf7; }
    .chk-toggle input { margin:0; transform: scale(1.1); cursor:pointer; }
    .chk-toggle.ativo {
        background:#0069d9;
        color:#fff;
        border-color:#0053b3;
    }

    .select2-container { margin: 4px 10px 4px 0; }
    .select2-container--default .select2-selection--multiple {
        min-height: 38px; padding: 4px 6px; border: 1px solid #ccc; border-radius: 4px; background:#fff;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background: #0069d9; color: #fff; border: none;
        padding: 2px 8px; font-size: 12.5px; border-radius: 10px; margin-right: 4px;
    }
    .select2-search--dropdown .select2-search__field { padding: 6px; font-size: 14px; border-radius: 4px; }
    #candidato + .select2-container { min-width: 380px; max-width: 520px; }

    .tabela-wrap {
        max-height: 75vh; overflow: auto; border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.1); background:#fff;
    }
    table.tabela-comp { border-collapse: collapse; width:100%; font-size: 13px; }
    table.tabela-comp th,
    table.tabela-comp td {
        border: 1px solid #e0e0e0;
        padding: 7px 12px;
        text-align: left;
        white-space: nowrap;
    }
    table.tabela-comp thead th.fixa {
        background: #0053b3; color: #fff;
        position: sticky; top: 0; z-index: 3;
    }
    table.tabela-comp thead th.col-cand {
        position: sticky; top: 0; z-index: 3;
        text-align: right; color: #fff;
    }
    table.tabela-comp thead th.col-cand .sub-cargo {
        display: block; font-size: 10px; font-weight: normal;
        opacity: .85; margin-top: 2px;
    }
    table.tabela-comp tbody tr:nth-child(even) td { background: #fafafa; }
    table.tabela-comp tbody tr:hover td { background: #f4f8ff; }
    table.tabela-comp td.col-num {
        text-align: right; font-family: 'Courier New', monospace;
        font-variant-numeric: tabular-nums;
    }
    table.tabela-comp td.cel-cand {
        text-align: right; font-family: 'Courier New', monospace;
        font-variant-numeric: tabular-nums;
        font-weight: normal; color: #333;
        transition: background .12s;
    }
    table.tabela-comp td.cel-cand.zero { color: #ccc; }
    table.tabela-comp td.cel-cand.venceu { font-weight: bold; color: #222; }
    table.tabela-comp td.cel-cand .medalha { font-size: 10px; margin-right: 2px; opacity: .9; }
    table.tabela-comp tfoot td {
        background: #f6f0ff; font-weight: bold;
        border-top: 2px solid #6f42c1; padding: 10px 12px;
        position: sticky; bottom: 0; z-index: 2;
    }
    table.tabela-comp tfoot td.cel-cand {
        text-align: right; font-family: 'Courier New', monospace; font-size: 14px;
    }

    .aviso-inicial {
        background:#fff; border-left:5px solid #0069d9;
        padding:24px 28px; border-radius:8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.08);
        text-align:center; margin-top:10px;
    }
    .aviso-inicial .icone { font-size: 42px; line-height: 1; margin-bottom: 12px; }
    .aviso-inicial h2 { margin: 0 0 8px; font-size: 18px; color:#0069d9; }
    .aviso-inicial p { margin: 0; font-size: 14px; color:#666; line-height: 1.5; }
    .aviso-inicial .dica {
        margin-top: 14px; font-size: 13px;
        background:#e7f3ff; color:#004085;
        padding: 8px 12px; border-radius: 6px; display: inline-block;
    }
    .aviso-vazio {
        background:#fff3cd; border-left:5px solid #ffc107;
        padding: 14px 18px; border-radius:6px; margin-top:10px;
        font-size: 14px; color: #856404;
    }
    .aviso-truncado {
        background:#cfe2ff; border-left:5px solid #0069d9;
        padding: 12px 16px; border-radius:6px;
        margin-top:10px; margin-bottom: 12px;
        font-size: 13.5px; color: #004085;
    }
    .dica-comparacao {
        background:#f4f8ff; border-left: 4px solid #0069d9;
        padding: 10px 14px; border-radius: 6px;
        font-size: 12.5px; color: #333; margin-bottom: 14px;
    }
    .dica-comparacao b { color: #0069d9; }

    .debug-box {
        background:#1e1e1e; color:#d4d4d4;
        padding:14px 18px; border-radius:8px;
        font-family:'Courier New', monospace; font-size:12px;
        line-height:1.6; overflow-x:auto; margin-bottom:18px;
        border-left:5px solid #ffc107;
    }
    .debug-box h3 { margin:0 0 8px; color:#ffc107; font-size:13px; font-family:Arial, sans-serif; }
    .debug-box .ok    { color:#b5e8b5; }
    .debug-box .aviso { color:#f48771; }
    .debug-box .destaque { color:#4ec9b0; }
</style>
</head>
<body>

<h1>Comparação de Candidatos — por Município / Zona</h1>

<div class="barra-usuario <?= $isAdmin ? ($__ehSuper ? 'super' : 'admin') : '' ?>">
    <span>👤 <span class="nome-user"><?= htmlspecialchars($__u['nome'] ?? '—') ?></span></span>
    <span class="perfil-tag <?= htmlspecialchars($__classePerfil) ?>">
        <?= htmlspecialchars($__rotuloPerfil) ?>
    </span>
    <?php if ($isAdmin): ?>
        <span class="<?= $__ehSuper ? 'badge-super' : 'badge-admin' ?>">
            <?= $__ehSuper ? '🔓 superadmin' : '🔓 administrador' ?>
        </span>
    <?php else: ?>
        <span class="badge-visitante">👁️ visitante</span>
    <?php endif; ?>

    <a class="link-debug" href="?debug_sessao=1<?= $temFiltro ? '&' . http_build_query($_GET) : '' ?>">
        🔍 ver sessão
    </a>
</div>

<?php if (isset($_GET['debug_sessao']) && $_GET['debug_sessao'] === '1'): ?>
    <div class="debug-box">
        <h3>🔍 SESSÃO</h3>
<pre style="margin:0; white-space:pre-wrap;">
<?php print_r($_SESSION); ?>
</pre>
    </div>
<?php endif; ?>

<?php if ($debug): ?>
    <div class="debug-box">
        <h3>🔍 DEBUG</h3>
<pre style="margin:0; white-space:pre-wrap;">
<span class="destaque">Exibir zonas:</span> <?= $exibirZonas ? 'SIM' : 'NÃO' ?>

<span class="destaque">Candidatos:</span> <?= htmlspecialchars(implode(', ', $candidatosSelecionados)) ?>

<span class="destaque">Linhas no pivot:</span> <?= $totalLinhas ?>

<span class="destaque">Total de votos:</span> <?= number_format($totalGeral, 0, ',', '.') ?>

<span class="destaque">Stats por candidato:</span>
<?php foreach ($statsCand as $c => $s): ?>
  · <?= htmlspecialchars($c) ?>
      votos:  <?= number_format($s['votos'], 0, ',', '.') ?>

      ganhou: <?= $s['ganhou'] ?> | empatou: <?= $s['empatou'] ?> | perdeu: <?= $s['perdeu'] ?>

<?php endforeach; ?>
</pre>
    </div>
<?php endif; ?>

<!-- KPIs gerais -->
<div class="kpis">
    <div class="kpi">
        <div class="rotulo">Total de votos (filtro)</div>
        <div class="valor"><?= number_format($totalGeral, 0, ',', '.') ?></div>
    </div>
    <div class="kpi verde">
        <div class="rotulo">Total geral no banco</div>
        <div class="valor"><?= number_format($totalBanco, 0, ',', '.') ?></div>
    </div>
    <div class="kpi cinza">
        <div class="rotulo">
            <?= $exibirZonas ? 'Municípios / Zonas' : 'Municípios' ?> comparados
        </div>
        <div class="valor"><?= number_format($totalLinhas, 0, ',', '.') ?></div>
    </div>
</div>

<form method="get" action="comparar_colunas.php" id="form-filtros">
    <div class="filtros">
        <label for="municipio">Município:</label>
        <select name="municipio" id="municipio">
            <option value="">Todos</option>
            <?php foreach ($municipios as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>"
                    <?= $m === $municipio ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="zona">Zona:</label>
        <select name="zona" id="zona">
            <option value="">Todas</option>
        </select>

        <label for="cargo">Cargo:</label>
        <select name="cargo" id="cargo">
            <option value="">Todos</option>
            <?php foreach ($cargos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= $c === $cargo ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="candidato">
            Candidato(s):
            <span style="font-weight:normal;color:#888;font-size:11px;">
                até <?= $MAX_CANDIDATOS ?>
            </span>
        </label>
        <select name="candidato[]" id="candidato" multiple="multiple">
            <?php foreach ($candidatos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= in_array($c, $candidatosSelecionados, true) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="exibir_zonas" class="chk-toggle <?= $exibirZonas ? 'ativo' : '' ?>">
            <input type="checkbox" name="exibir_zonas" value="1" id="exibir_zonas"
                <?= $exibirZonas ? 'checked' : '' ?>>
            Exibir zonas eleitorais
        </label>

        <button type="submit" class="btn-consultar">🔎 Comparar</button>
        <a href="comparar_colunas.php" class="btn-limpar">✖ Limpar</a>
    </div>
</form>

<?php if (!$temFiltro): ?>

    <div class="aviso-inicial">
        <div class="icone">⚖️</div>
        <h2>Selecione 2 ou 3 candidatos para comparar</h2>
        <p>
            Os votos serão exibidos <strong>lado a lado</strong> por
            <strong>município</strong> (e opcionalmente por <strong>zona eleitoral</strong>).
            O candidato vencedor em cada linha é destacado com a cor dele.
        </p>
        <div class="dica">
            💡 Use os filtros de <strong>Município</strong> ou <strong>Zona</strong>
            para delimitar a comparação. Marque
            <strong>“Exibir zonas eleitorais”</strong> para detalhar por zona.
        </div>
    </div>

<?php elseif (!$pivot): ?>

    <div class="aviso-vazio">
        <strong>Nenhum resultado encontrado</strong> para os filtros selecionados.
        Tente ampliar a busca ou remover algum filtro.
    </div>

<?php else: ?>

    <?php if ($truncado): ?>
        <div class="aviso-truncado">
            <strong>Atenção:</strong> o resultado foi limitado a
            <b><?= number_format($LIMITE_RESULTADO, 0, ',', '.') ?></b> linhas.
            Refine os filtros para ver resultados mais específicos.
        </div>
    <?php endif; ?>

    <!-- KPIs por candidato -->
    <div class="kpis-candidatos">
        <?php foreach ($candidatosCols as $cand):
            $cor = $coresCandidato[$cand] ?? ['bg' => '#e0ecff', 'tx' => '#0d47a1', 'base' => '#0d6efd'];
            $s   = $statsCand[$cand] ?? ['votos' => 0, 'ganhou' => 0, 'empatou' => 0, 'perdeu' => 0, 'pct' => 0];
        ?>
            <div class="kpi-cand" style="border-top-color: <?= htmlspecialchars($cor['base']) ?>;">
                <div class="cabecalho">
                    <span class="dot" style="background: <?= htmlspecialchars($cor['base']) ?>;"></span>
                    <span class="nome" style="color: <?= htmlspecialchars($cor['tx']) ?>;"
                          title="<?= htmlspecialchars($cand) ?>">
                        <?= htmlspecialchars($cand) ?>
                    </span>
                </div>

                <div class="linha-principal">
                    <span class="num" style="color: <?= htmlspecialchars($cor['tx']) ?>;">
                        <?= number_format($s['votos'], 0, ',', '.') ?>
                    </span>
                    <span class="pct">
                        votos (<?= number_format($s['pct'], 1, ',', '.') ?>%)
                    </span>
                </div>

                <div class="grid-mini">
                    <div class="item ganhou">
                        <div class="v"><?= number_format($s['ganhou'], 0, ',', '.') ?></div>
                        <div class="r">🏆 Ganhou</div>
                    </div>
                    <div class="item empatou">
                        <div class="v"><?= number_format($s['empatou'], 0, ',', '.') ?></div>
                        <div class="r">🤝 Empatou</div>
                    </div>
                    <div class="item perdeu">
                        <div class="v"><?= number_format($s['perdeu'], 0, ',', '.') ?></div>
                        <div class="r">▼ Perdeu</div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="dica-comparacao">
        🏆 <b>Vencedor em cada linha:</b> a célula do candidato com mais votos
        ganha o fundo na cor dele. <b>Empates</b> ficam com fundo mas sem troféu.
        <?php if (!$exibirZonas): ?>
            <br>ℹ️ Exibindo <b>totais por município</b>. Marque
            <b>“Exibir zonas eleitorais”</b> para detalhar por zona.
        <?php else: ?>
            <br>ℹ️ Exibindo <b>totais por município + zona eleitoral</b>.
        <?php endif; ?>
    </div>

    <div class="tabela-wrap">
        <table class="tabela-comp">
            <thead>
                <tr>
                    <th class="fixa">Município</th>
                    <?php if ($exibirZonas): ?>
                        <th class="fixa" style="text-align:right;">Zona</th>
                    <?php endif; ?>
                    <?php foreach ($candidatosCols as $cand):
                        $cor = $coresCandidato[$cand] ?? ['bg' => '#e0ecff', 'tx' => '#0d47a1', 'base' => '#0d6efd'];
                    ?>
                        <th class="col-cand"
                            style="background:<?= htmlspecialchars($cor['base']) ?>;">
                            <?= htmlspecialchars($cand) ?>
                            <span class="sub-cargo"><?= htmlspecialchars($cargo ?: '—') ?></span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pivot as $linha):
                    $votosLinha = $linha['votos'];
                    $maxLinha   = !empty($votosLinha) ? max($votosLinha) : 0;
                    $empate     = ($maxLinha > 0)
                                    ? count(array_filter($votosLinha, fn($v) => $v === $maxLinha)) > 1
                                    : false;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($linha['municipio']) ?></td>
                        <?php if ($exibirZonas): ?>
                            <td class="col-num"><?= (int)($linha['zona'] ?? 0) ?></td>
                        <?php endif; ?>

                        <?php foreach ($candidatosCols as $cand):
                            $cor  = $coresCandidato[$cand] ?? ['bg' => '#e0ecff', 'tx' => '#0d47a1', 'base' => '#0d6efd'];
                            $votos = (int)($linha['votos'][$cand] ?? 0);
                            $classe = 'cel-cand';
                            $estilo = '';
                            $medalha = '';

                            if ($votos === 0) {
                                $classe .= ' zero';
                            } elseif ($votos === $maxLinha && !$empate) {
                                $classe .= ' venceu';
                                $estilo = 'background:' . $cor['bg'] . ';color:' . $cor['tx'] . ';';
                                $medalha = '<span class="medalha">🏆</span>';
                            } elseif ($votos === $maxLinha && $empate) {
                                $classe .= ' venceu';
                                $estilo = 'background:' . $cor['bg'] . ';color:' . $cor['tx'] . ';';
                            }
                        ?>
                            <td class="<?= $classe ?>" style="<?= $estilo ?>">
                                <?= $medalha ?>
                                <?= $votos > 0 ? number_format($votos, 0, ',', '.') : '—' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="<?= $exibirZonas ? 2 : 1 ?>" style="text-align:right;">TOTAL GERAL</td>
                    <?php foreach ($candidatosCols as $cand):
                        $cor = $coresCandidato[$cand] ?? ['bg' => '#e0ecff', 'tx' => '#0d47a1', 'base' => '#0d6efd'];
                        $tot = (int)($statsCand[$cand]['votos'] ?? 0);
                        $pct = $totalGeral > 0 ? ($tot / $totalGeral) * 100 : 0;
                    ?>
                        <td class="cel-cand" style="color: <?= htmlspecialchars($cor['tx']) ?>;">
                            <?= number_format($tot, 0, ',', '.') ?>
                            <div style="font-size:10px;color:#888;font-weight:normal;">
                                <?= number_format($pct, 1, ',', '.') ?>%
                            </div>
                        </td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>

<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
const MAX_CANDIDATOS = <?= (int)$MAX_CANDIDATOS ?>;

(function () {
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;

    function removerAcentos(s) {
        return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    function matcherSemAcento(params, data) {
        if (jQuery.trim(params.term) === '') return data;
        const termo = removerAcentos(params.term.toLowerCase());
        const texto = removerAcentos((data.text || '').toLowerCase());
        return texto.indexOf(termo) > -1 ? data : null;
    }

    jQuery('#candidato').select2({
        language: 'pt-BR',
        placeholder: '— Selecione até ' + MAX_CANDIDATOS + ' candidatos —',
        allowClear: true,
        closeOnSelect: false,
        width: 'resolve',
        minimumInputLength: 0,
        matcher: matcherSemAcento,
        maximumSelectionLength: MAX_CANDIDATOS,
    });
})();

// ============================================================
// Cascata: Município -> Zona
// ============================================================
const selMunicipio = document.getElementById('municipio');
const selZona      = document.getElementById('zona');

const valorMunicipioAtual = <?= json_encode($municipio) ?>;
const valorZonaAtual      = <?= json_encode($zona) ?>;

async function carregarZonas(municipio, zonaSelecionada = '') {
    selZona.innerHTML = '<option value="">Todas</option>';

    if (!municipio) return;

    const resp  = await fetch(`comparar_colunas.php?action=zonas&municipio=${encodeURIComponent(municipio)}`);
    const zonas = await resp.json();

    zonas.forEach(z => {
        const opt = document.createElement('option');
        opt.value = z;
        opt.textContent = z;
        if (String(z) === String(zonaSelecionada)) opt.selected = true;
        selZona.appendChild(opt);
    });
}

selMunicipio.addEventListener('change', () => carregarZonas(selMunicipio.value));

(async () => {
    if (valorMunicipioAtual) await carregarZonas(valorMunicipioAtual, valorZonaAtual);
})();

// ============================================================
// Toggle "Exibir zonas eleitorais" — auto-envia o form
// ============================================================
const chkZonas = document.getElementById('exibir_zonas');
if (chkZonas) {
    chkZonas.addEventListener('change', () => {
        document.getElementById('form-filtros').submit();
    });
}
</script>

</body>
</html>