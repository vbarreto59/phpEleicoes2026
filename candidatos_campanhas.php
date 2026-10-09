<?php
// candidatos_campanhas.php
// ------------------------------------------------------------
// Autenticação
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';

$__u = usuarioLogado();

$__loginLower = mb_strtolower(trim((string)($__u['login'] ?? '')), 'UTF-8');
$__nomeLower  = mb_strtolower(trim((string)($__u['nome']  ?? '')), 'UTF-8');
$__perfil     = $__u['perfil'] ?? 'consulta';

$__loginsDono = ['barreto', 'vbarreto'];
$__nomesDono  = ['barreto', 'valter barreto'];

$__ehDono = in_array($__loginLower, $__loginsDono, true)
         || in_array($__nomeLower,  $__nomesDono,  true);

$__ehSuper      = ($__perfil === 'superadmin');
$__ehAdminNivel = ($__perfil === 'admin');
$__ehAdmin      = ($__ehSuper || $__ehAdminNivel);

$isAdmin = ($__ehAdmin && $__ehDono);

$__rotuloPerfil = function_exists('rotuloPerfil')
                    ? rotuloPerfil($__perfil)
                    : strtoupper($__perfil);
$__classePerfil = function_exists('classePerfil')
                    ? classePerfil($__perfil)
                    : ('perfil-' . $__perfil);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$colunasVotos = $pdo->query("SHOW COLUMNS FROM votos")->fetchAll(PDO::FETCH_COLUMN);
$temAno    = in_array('ano', $colunasVotos, true);
$temNumero = in_array('numero', $colunasVotos, true);

// ============================================================
// AJAX — detalhe sob demanda (lazy loading)
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detalhe') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $dCand  = trim($_GET['cand']  ?? '');
        $dCargo = trim($_GET['cargo'] ?? '');
        $dAno   = $temAno ? trim($_GET['ano'] ?? '') : '';

        if ($dCand === '' || $dCargo === '') {
            echo json_encode(['ok' => false, 'msg' => 'Parâmetros insuficientes.']);
            exit;
        }

        $w = ["v.nomeCandidatoResp = :c", "v.nomeCargoPerg = :cg"];
        $p = [':c' => $dCand, ':cg' => $dCargo];
        if ($temAno && $dAno !== '') {
            $w[]     = "v.ano = :a";
            $p[':a'] = (int)$dAno;
        }
        $ws = 'WHERE ' . implode(' AND ', $w);

        // Por região
        $sqlR = "
            SELECT COALESCE(r.nome, 'Sem região') AS regiao,
                   SUM(v.qtdVotos) AS total
            FROM votos v
            LEFT JOIN municipio_regiao mr
              ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
               = v.nomeMunicipio  COLLATE utf8mb4_general_ci
            LEFT JOIN regioes r ON r.id = mr.regiao_id
            $ws
            GROUP BY r.nome
            ORDER BY total DESC
        ";
        $stmt = $pdo->prepare($sqlR);
        $stmt->execute($p);
        $porRegiao = $stmt->fetchAll();

        // Top 15 municípios
        $sqlM = "
            SELECT
                v.nomeMunicipio,
                COALESCE(r.nome, 'Sem região') AS regiao,
                SUM(v.qtdVotos) AS total
            FROM votos v
            LEFT JOIN municipio_regiao mr
              ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
               = v.nomeMunicipio  COLLATE utf8mb4_general_ci
            LEFT JOIN regioes r ON r.id = mr.regiao_id
            $ws
            GROUP BY v.nomeMunicipio, r.nome
            ORDER BY total DESC
            LIMIT 15
        ";
        $stmt = $pdo->prepare($sqlM);
        $stmt->execute($p);
        $porMunicipio = $stmt->fetchAll();

        echo json_encode([
            'ok'            => true,
            'por_regiao'    => $porRegiao,
            'por_municipio' => $porMunicipio,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ------------------------------------------------------------
// Apagar campanha (POST) — só admin (SEM backup)
// ------------------------------------------------------------
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'apagar_campanha') {
    if (!$isAdmin) {
        http_response_code(403);
        die('Acesso negado. Apenas administradores autorizados podem apagar registros.');
    }
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Token de segurança inválido. Recarregue a página.');
    }

    $delCandidato = trim($_POST['del_candidato'] ?? '');
    $delCargo     = trim($_POST['del_cargo']     ?? '');
    $delAno       = $temAno ? trim($_POST['del_ano'] ?? '') : '';

    if ($delCandidato === '') {
        $_SESSION['flash'] = ['tipo' => 'erro', 'msg' => 'Candidato não informado.'];
        header('Location: ' . $_SERVER['PHP_SELF'] . (empty($_GET) ? '' : '?' . http_build_query($_GET)));
        exit;
    }

    $conds   = ["nomeCandidatoResp = :c"];
    $dparams = [':c' => $delCandidato];

    if ($delCargo !== '') {
        $conds[]        = "nomeCargoPerg = :cg";
        $dparams[':cg'] = $delCargo;
    }
    if ($temAno && $delAno !== '') {
        $conds[]       = "ano = :a";
        $dparams[':a'] = (int)$delAno;
    }
    $whereDel = implode(' AND ', $conds);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM votos WHERE $whereDel");
        $stmt->execute($dparams);
        $removidos = $stmt->rowCount();
        $pdo->commit();

        $_SESSION['flash'] = [
            'tipo' => 'ok',
            'msg'  =>
                "✔ Apagados <b>" . number_format($removidos, 0, ',', '.') . "</b> registro(s) de " .
                "<b>" . htmlspecialchars($delCandidato) . "</b>" .
                ($delCargo !== '' ? " no cargo <b>" . htmlspecialchars($delCargo) . "</b>" : "") .
                ($delAno  !== '' ? " em <b>" . (int)$delAno . "</b>" : "") . "."
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash'] = ['tipo' => 'erro', 'msg' => 'Erro ao apagar: ' . $e->getMessage()];
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . (empty($_GET) ? '' : '?' . http_build_query($_GET)));
    exit;
}

// ============================================================
// FILTROS
// ============================================================
$ano        = $_GET['ano']        ?? '';
$cargo      = $_GET['cargo']      ?? '';
$candidato  = $_GET['candidato']  ?? '';
$regiao     = $_GET['regiao']     ?? '';
$ordem      = $_GET['ordem']      ?? 'votos';

$filtros = [];
$params  = [];

if ($temAno && $ano !== '') {
    $filtros[] = "v.ano = :ano";
    $params[':ano'] = (int)$ano;
}
if ($cargo !== '') {
    $filtros[] = "v.nomeCargoPerg = :cargo";
    $params[':cargo'] = $cargo;
}
if ($candidato !== '') {
    $filtros[] = "v.nomeCandidatoResp = :candidato";
    $params[':candidato'] = $candidato;
}
// >>> Só adiciona filtro de região se o usuário filtrar (evita JOIN desnecessário)
if ($regiao !== '') {
    $filtros[] = "EXISTS (
        SELECT 1 FROM municipio_regiao mr
         WHERE mr.nomeMunicipio COLLATE utf8mb4_general_ci
             = v.nomeMunicipio  COLLATE utf8mb4_general_ci
           AND mr.regiao_id = :regiao
    )";
    $params[':regiao'] = (int)$regiao;
}

$where = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

$colAnoSelect = $temAno ? "v.ano," : "";
$colAnoGroup  = $temAno ? "v.ano," : "";
$colAnoOrder  = $temAno ? "v.ano DESC," : "";

$colNumeroSelect = $temNumero ? "MAX(v.numero) AS numero," : "";

switch ($ordem) {
    case 'nome':  $orderBy = "v.nomeCandidatoResp ASC, v.nomeCargoPerg ASC"; break;
    case 'cargo': $orderBy = "v.nomeCargoPerg ASC, total DESC"; break;
    case 'ano':   $orderBy = $temAno ? "v.ano DESC, total DESC" : "total DESC"; break;
    case 'votos':
    default:      $orderBy = $temAno ? "v.ano DESC, total DESC" : "total DESC"; break;
}

// ============================================================
// CAMPANHAS (sem JOIN se não filtrar região)
// ============================================================
$sqlCampanhas = "
    SELECT
        $colAnoSelect
        $colNumeroSelect
        v.nomeCandidatoResp AS candidato,
        v.nomeCargoPerg     AS cargo,
        SUM(v.qtdVotos)     AS total,
        COUNT(DISTINCT v.nomeMunicipio) AS qtd_municipios,
        COUNT(DISTINCT v.numeroZona)    AS qtd_zonas
    FROM votos v
    $where
    GROUP BY $colAnoGroup v.nomeCandidatoResp, v.nomeCargoPerg
    ORDER BY $orderBy
";
$stmt = $pdo->prepare($sqlCampanhas);
$stmt->execute($params);
$campanhas = $stmt->fetchAll();

$totalVotosFiltro = 0;
foreach ($campanhas as $c) $totalVotosFiltro += (int)$c['total'];

// ============================================================
// RANKINGS — derivados de $campanhas (zero queries extras)
// ============================================================
$aggCand = [];
foreach ($campanhas as $c) {
    $chave = ($temAno ? ($c['ano'] ?? '—') . '|' : '') . $c['candidato'];
    if (!isset($aggCand[$chave])) {
        $aggCand[$chave] = [
            'ano'        => $c['ano'] ?? null,
            'candidato'  => $c['candidato'],
            'qtd_cargos' => 0,
            'total'      => 0,
        ];
    }
    $aggCand[$chave]['qtd_cargos'] += 1;
    $aggCand[$chave]['total']      += (int)$c['total'];
}
$rankingCandidatos = array_values($aggCand);
usort($rankingCandidatos, function ($a, $b) use ($temAno) {
    if ($temAno) {
        $cmp = ($b['ano'] ?? 0) <=> ($a['ano'] ?? 0);
        if ($cmp !== 0) return $cmp;
    }
    return $b['total'] <=> $a['total'];
});
$rankingCandidatos = array_slice($rankingCandidatos, 0, 10);

$aggCargo = [];
foreach ($campanhas as $c) {
    $chave = ($temAno ? ($c['ano'] ?? '—') . '|' : '') . $c['cargo'];
    if (!isset($aggCargo[$chave])) {
        $aggCargo[$chave] = [
            'ano'            => $c['ano'] ?? null,
            'cargo'          => $c['cargo'],
            'qtd_candidatos' => 0,
            'total'          => 0,
        ];
    }
    $aggCargo[$chave]['qtd_candidatos'] += 1;
    $aggCargo[$chave]['total']          += (int)$c['total'];
}
$rankingCargos = array_values($aggCargo);
usort($rankingCargos, function ($a, $b) use ($temAno) {
    if ($temAno) {
        $cmp = ($b['ano'] ?? 0) <=> ($a['ano'] ?? 0);
        if ($cmp !== 0) return $cmp;
    }
    return $b['total'] <=> $a['total'];
});

// ============================================================
// KPIs — UMA query em vez de 5
// ============================================================
$colCountAno = $temAno ? "COUNT(DISTINCT ano)" : "0";

$rowKpi = $pdo->query("
    SELECT
        COUNT(DISTINCT nomeCandidatoResp) AS total_candidatos,
        COUNT(DISTINCT nomeCargoPerg)     AS total_cargos,
        SUM(qtdVotos)                     AS total_geral,
        $colCountAno                      AS total_anos
    FROM votos
")->fetch();

$totalCandidatos = (int)$rowKpi['total_candidatos'];
$totalCargos     = (int)$rowKpi['total_cargos'];
$totalGeralBanco = (int)$rowKpi['total_geral'];
$totalAnos       = (int)$rowKpi['total_anos'];

if ($temAno) {
    $totalCampanhasBanco = (int)$pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT DISTINCT nomeCandidatoResp, nomeCargoPerg, ano FROM votos
         ) t"
    )->fetchColumn();
} else {
    $totalCampanhasBanco = (int)$pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT DISTINCT nomeCandidatoResp, nomeCargoPerg FROM votos
         ) t"
    )->fetchColumn();
}

// ============================================================
// LISTAS PARA SELECTS
// ============================================================
$anosDisponiveis = [];
if ($temAno) {
    $anosDisponiveis = $pdo->query(
        "SELECT DISTINCT ano FROM votos WHERE ano IS NOT NULL ORDER BY ano DESC"
    )->fetchAll(PDO::FETCH_COLUMN);
}

$cargos = $pdo->query(
    "SELECT DISTINCT nomeCargoPerg FROM votos ORDER BY nomeCargoPerg"
)->fetchAll(PDO::FETCH_COLUMN);

$candidatos = $pdo->query(
    "SELECT DISTINCT nomeCandidatoResp FROM votos ORDER BY nomeCandidatoResp"
)->fetchAll(PDO::FETCH_COLUMN);

$regioes = $pdo->query(
    "SELECT id, nome FROM regioes ORDER BY nome"
)->fetchAll();

$temFiltro = $ano || $cargo || $candidato || $regiao;

$numColunas = ($temAno ? 8 : 7) + 1 + 1 + ($isAdmin ? 1 : 0);

$debugSessao = isset($_GET['debug_sessao']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Candidatos e Campanhas</title>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    h2 { margin-top:30px; }

    .barra-usuario {
        background:#fff; padding:10px 16px; border-radius:6px;
        box-shadow:0 1px 3px rgba(0,0,0,.08);
        display:flex; align-items:center; gap:12px; flex-wrap:wrap;
        font-size:13.5px; margin-bottom:18px;
        border-left:5px solid #6c757d;
    }
    .barra-usuario.admin { border-left-color:#ffc107; }
    .barra-usuario.super { border-left-color:#dc3545; }
    .barra-usuario .nome-user { font-weight:bold; color:#333; }
    .badge-admin {
        display:inline-flex; align-items:center; gap:5px;
        background:#fff3cd; color:#856404;
        padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; letter-spacing:.3px;
    }
    .badge-super {
        display:inline-flex; align-items:center; gap:5px;
        background:#dc3545; color:#fff;
        padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; letter-spacing:.3px;
    }
    .badge-visitante {
        display:inline-flex; align-items:center; gap:5px;
        background:#e9ecef; color:#495057;
        padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; letter-spacing:.3px;
    }
    .perfil-tag {
        font-size:10px; padding:2px 8px; border-radius:8px;
        font-weight:bold; letter-spacing:.5px;
        background:#e6f0fa; color:#0069d9;
    }
    .perfil-tag.perfil-super    { background:#dc3545; color:#fff; }
    .perfil-tag.perfil-admin    { background:#fff3cd; color:#856404; }
    .perfil-tag.perfil-consulta { background:#e6f0fa; color:#0069d9; }

    .link-debug {
        margin-left:auto; font-size:12px; color:#0069d9; text-decoration:none;
        background:#e7f3ff; padding:4px 10px; border-radius:12px;
    }
    .link-debug:hover { background:#cfe2ff; text-decoration:underline; }

    .debug-box {
        background:#1e1e1e; color:#d4d4d4;
        padding:16px 20px; border-radius:8px;
        font-family:'Courier New', monospace; font-size:12.5px;
        line-height:1.6; overflow-x:auto; margin-bottom:18px;
        border-left:5px solid #ffc107;
    }
    .debug-box h3 { margin:0 0 10px; color:#ffc107; font-size:14px; font-family:Arial, sans-serif; }
    .debug-box .destaque { color:#4ec9b0; }
    .debug-box .aviso    { color:#f48771; }
    .debug-box .ok       { color:#b5e8b5; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:190px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#0069d9; margin-top:4px; }
    .kpi .sub    { font-size:12px; color:#888; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi.teal    { border-left-color:#20c997; } .kpi.teal .valor { color:#20c997; }

    form { background:#fff; padding:15px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px; }
    label { display:inline-block; margin-right:10px; font-weight:bold; }
    select, button { padding:6px 8px; margin:4px 10px 4px 0; border-radius:4px; border:1px solid #ccc; font-size:14px; }
    button { cursor:pointer; border:none; }
    .btn-consultar { background:#0069d9; color:#fff; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none; padding:6px 12px;
                  border-radius:4px; display:inline-block; margin:4px 0; }
    .btn-limpar:hover { background:#5a6268; }
    .filtros { display:flex; flex-wrap:wrap; align-items:center; }

    .select2-container { margin: 4px 10px 4px 0; }
    .select2-container--default .select2-selection--single {
        height: 34px; padding: 3px 6px; border: 1px solid #ccc; border-radius: 4px; background:#fff;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 26px; color:#333;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px; }
    .select2-container .select2-selection--single { height: 34px; }
    .select2-search--dropdown .select2-search__field { padding: 6px; font-size: 14px; border-radius: 4px; }
    .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color: #0069d9; }
    #candidato + .select2-container { min-width: 320px; max-width: 440px; }

    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; }
    th { background:#0069d9; color:#fff; }
    tr:nth-child(even) { background:#f2f2f2; }
    tfoot td { font-weight:bold; background:#e6f0fa; }
    .num { text-align:right; }
    .barra {
        background:#e6f0fa; border-radius:4px; height:12px; overflow:hidden;
        display:inline-block; width:140px; vertical-align:middle;
    }
    .barra > span { display:block; height:100%; background:#0069d9; }

    td.col-numero {
        text-align: center;
        font-weight: bold;
        font-size: 14px;
        color: #0053b3;
        font-family: 'Courier New', monospace;
        min-width: 50px;
    }
    th.col-numero { text-align: center; }
    .numero-vazio { color:#ccc; font-weight: normal; }

    .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

    .card {
        background:#fff; border-radius:8px; padding:15px 20px;
        box-shadow:0 1px 3px rgba(0,0,0,.1);
    }
    .card h3 { margin: 0 0 10px; font-size: 15px; color:#0069d9;
               border-bottom: 1px solid #eee; padding-bottom:8px; }
    .card table { margin: 0; }
    .card table th { background:#0069d9; color:#fff; font-size:12px; }
    .card table td { font-size:13px; }

    .badge {
        display:inline-block; padding:2px 8px; border-radius:12px;
        font-size:11px; font-weight:bold; text-transform:uppercase; letter-spacing:.4px;
    }
    .badge-ano   { background:#e7f3ff; color:#0069d9; }
    .badge-cargo { background:#e6f0fa; color:#0069d9; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso code { background:#fff; padding:1px 5px; border-radius:3px;
                  font-size:12px; border:1px solid #eee; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    .flash {
        padding: 12px 18px; border-radius: 6px; margin-bottom: 18px;
        font-size: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.08); line-height: 1.5;
    }
    .flash.ok   { background:#d4edda; border-left:5px solid #28a745; color:#155724; }
    .flash.erro { background:#f8d7da; border-left:5px solid #dc3545; color:#721c24; }
    .flash code {
        background:#fff; padding:1px 6px; border-radius:3px;
        font-size:12.5px; border:1px solid #cfe2d4;
    }

    .btn-lixeira {
        background: transparent; border: none; cursor: pointer;
        font-size: 18px; padding: 4px 6px; border-radius: 4px;
        transition: background .12s, transform .12s; opacity: .75;
    }
    .btn-lixeira:hover { background: #ffe0e0; opacity: 1; transform: scale(1.15); }
    .col-acoes { text-align: center; width: 52px; }

    /* Spinner / carregando no detalhe */
    .detalhe-loading {
        padding: 24px; text-align: center; color: #888; font-size: 13px;
    }
    .detalhe-loading .spinner {
        display: inline-block; width: 22px; height: 22px;
        border: 3px solid #e0e0e0; border-top-color: #0069d9;
        border-radius: 50%; animation: spin .8s linear infinite;
        vertical-align: middle; margin-right: 8px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* Modal */
    #modal-apagar {
        position: fixed; inset: 0;
        background: rgba(0,0,0,.55); backdrop-filter: blur(2px);
        display: none; align-items: center; justify-content: center;
        z-index: 9999; opacity: 0; transition: opacity .18s ease;
    }
    #modal-apagar.ativo { display: flex; opacity: 1; }
    #modal-apagar .caixa {
        background: #fff; border-radius: 12px;
        padding: 26px 30px; width: 520px; max-width: 94vw;
        box-shadow: 0 12px 40px rgba(0,0,0,.35);
        border-top: 6px solid #dc3545; max-height: 92vh; overflow-y: auto;
    }
    #modal-apagar .cabecalho { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
    #modal-apagar .cabecalho .icone { font-size: 34px; line-height: 1; }
    #modal-apagar .cabecalho h3 { margin: 0; font-size: 18px; color: #b71c1c; }
    #modal-apagar .corpo { font-size: 14px; color: #444; line-height: 1.6; }
    #modal-apagar .corpo .destaque {
        display: block; background: #f8f9fa; border-left: 4px solid #dc3545;
        padding: 10px 14px; border-radius: 6px; margin: 12px 0; font-size: 13.5px;
    }
    #modal-apagar .corpo .destaque b { color: #b71c1c; }
    #modal-apagar .destaque-numero {
        display: inline-block;
        background: #0053b3; color: #fff;
        padding: 4px 12px; border-radius: 6px;
        font-family: 'Courier New', monospace;
        font-size: 18px; font-weight: bold;
        letter-spacing: 1px;
        margin-left: 4px;
    }
    #modal-apagar .aviso-forte {
        margin-top: 12px; font-weight: bold; color: #b71c1c;
        text-align: center; letter-spacing: .5px; font-size: 12.5px;
    }
    #modal-apagar .campo { margin-top: 16px; }
    #modal-apagar .campo label {
        display: block; font-size: 13px; font-weight: bold; color: #333; margin-bottom: 6px;
    }
    #modal-apagar .campo input {
        width: 100%; padding: 10px 12px; border: 2px solid #ddd; border-radius: 6px;
        font-size: 18px; font-family: 'Courier New', monospace; box-sizing: border-box;
        text-align: center; letter-spacing: 2px; font-weight: bold;
    }
    #modal-apagar .campo input:focus { outline: none; border-color: #dc3545; }
    #modal-apagar .rodape { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
    #modal-apagar .btn-cancelar {
        background: #6c757d; color: #fff; padding: 9px 18px; border-radius: 6px;
        border: none; font-size: 14px; cursor: pointer;
    }
    #modal-apagar .btn-cancelar:hover { background: #5a6268; }
    #modal-apagar .btn-apagar {
        background: #dc3545; color: #fff; padding: 9px 20px; border-radius: 6px;
        border: none; font-size: 14px; font-weight: bold; cursor: pointer;
    }
    #modal-apagar .btn-apagar:hover:not(:disabled) { background: #b71c1c; }
    #modal-apagar .btn-apagar:disabled { background: #e0aeb4; cursor: not-allowed; opacity: .7; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Candidatos e Campanhas</h1>

<div class="barra-usuario <?= $isAdmin ? ($__ehSuper ? 'super' : 'admin') : '' ?>">
    <span>👤 <span class="nome-user"><?= htmlspecialchars($__u['nome'] ?? '—') ?></span></span>
    <span class="perfil-tag <?= htmlspecialchars($__classePerfil) ?>">
        <?= htmlspecialchars($__rotuloPerfil) ?>
    </span>
    <?php if ($isAdmin): ?>
        <span class="<?= $__ehSuper ? 'badge-super' : 'badge-admin' ?>">
            <?= $__ehSuper ? '🔓 superadmin' : '🔓 administrador' ?>
        </span>
    <?php elseif ($__ehAdmin && !$__ehDono): ?>
        <span class="badge-visitante">👁️ admin (não autorizado a excluir)</span>
    <?php else: ?>
        <span class="badge-visitante">👁️ visitante</span>
    <?php endif; ?>

    <a class="link-debug" href="?debug_sessao=1<?= $temFiltro ? '&' . http_build_query($_GET) : '' ?>">
        🔍 ver sessão
    </a>
</div>

<?php if ($debugSessao): ?>
    <div class="debug-box">
        <h3>🔍 Diagnóstico do usuário logado</h3>
<pre style="margin:0; white-space:pre-wrap;">
<span class="destaque">Login:</span>              <?= $__loginLower !== '' ? htmlspecialchars($__loginLower) : '<span class="aviso">(vazio)</span>' ?>

<span class="destaque">Nome:</span>               <?= $__nomeLower !== '' ? htmlspecialchars($__nomeLower) : '<span class="aviso">(vazio)</span>' ?>

<span class="destaque">Perfil:</span>             <?= htmlspecialchars($__perfil) ?>

<span class="destaque">É dono?</span>            <?= $__ehDono ? '<span class="ok">SIM ✔</span>' : '<span class="aviso">NÃO ✘</span>' ?>

<span class="destaque">Perfil admin/super?</span> <?= $__ehAdmin ? '<span class="ok">SIM ✔</span>' : '<span class="aviso">NÃO ✘</span>' ?>

<span class="destaque">Pode apagar?</span>       <?= $isAdmin ? '<span class="ok">SIM ✔</span>' : '<span class="aviso">NÃO ✘</span>' ?>

<span class="destaque">Coluna 'numero' existe em votos?</span> <?= $temNumero ? '<span class="ok">SIM ✔</span>' : '<span class="aviso">NÃO ✘</span>' ?>

<span class="destaque">Array retornado por usuarioLogado():</span>
<?= htmlspecialchars(print_r($__u, true)) ?>
</pre>
    </div>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="flash <?= htmlspecialchars($flash['tipo']) ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

<?php if (!$temAno): ?>
    <div class="aviso">
        <strong>Atenção:</strong> a coluna <code>ano</code> ainda não existe em <code>votos</code>.
        Para ativar: <code>ALTER TABLE votos ADD COLUMN ano INT NULL AFTER nomeMunicipio, ADD INDEX idx_ano (ano);</code>
    </div>
<?php endif; ?>

<?php if (!$temNumero): ?>
    <div class="aviso">
        <strong>Atenção:</strong> a coluna <code>numero</code> não existe em <code>votos</code>.
        A coluna "Nº" ficará vazia. Para ativar:
        <br>
        <code>ALTER TABLE votos ADD COLUMN numero INT NULL AFTER cargoPergunta, ADD INDEX idx_numero (numero);</code>
    </div>
<?php endif; ?>

<div class="kpis">
    <div class="kpi"><div class="rotulo">Candidatos</div>
        <div class="valor"><?= number_format($totalCandidatos, 0, ',', '.') ?></div></div>
    <div class="kpi roxo"><div class="rotulo">Cargos</div>
        <div class="valor"><?= number_format($totalCargos, 0, ',', '.') ?></div></div>
    <div class="kpi laranja">
        <div class="rotulo">Campanhas<?= $temAno ? ' (cand × cargo × ano)' : ' (cand × cargo)' ?></div>
        <div class="valor"><?= number_format($totalCampanhasBanco, 0, ',', '.') ?></div></div>
    <?php if ($temAno && $totalAnos > 0): ?>
        <div class="kpi teal"><div class="rotulo">Anos distintos</div>
            <div class="valor"><?= number_format($totalAnos, 0, ',', '.') ?></div></div>
    <?php endif; ?>
    <div class="kpi verde"><div class="rotulo">Total de votos no banco</div>
        <div class="valor"><?= number_format($totalGeralBanco, 0, ',', '.') ?></div></div>
    <?php if ($temFiltro): ?>
        <div class="kpi vermelho"><div class="rotulo">Votos no filtro atual</div>
            <div class="valor"><?= number_format($totalVotosFiltro, 0, ',', '.') ?></div>
            <div class="sub"><?= number_format(count($campanhas), 0, ',', '.') ?> campanha(s)</div></div>
    <?php endif; ?>
</div>

<form method="get" action="candidatos_campanhas.php" id="form-filtros">
    <div class="filtros">
        <?php if ($temAno && $anosDisponiveis): ?>
            <label for="ano">Ano:</label>
            <select name="ano" id="ano">
                <option value="">Todos</option>
                <?php foreach ($anosDisponiveis as $a): ?>
                    <option value="<?= (int)$a ?>"
                        <?= (string)$a === (string)$ano ? 'selected' : '' ?>><?= (int)$a ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <label for="cargo">Cargo:</label>
        <select name="cargo" id="cargo">
            <option value="">Todos</option>
            <?php foreach ($cargos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $c === $cargo ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="candidato">Candidato:</label>
        <select name="candidato" id="candidato">
            <option value="">Todos</option>
            <?php foreach ($candidatos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $c === $candidato ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="regiao">Região:</label>
        <select name="regiao" id="regiao">
            <option value="">Todas</option>
            <?php foreach ($regioes as $r): ?>
                <option value="<?= (int)$r['id'] ?>"
                    <?= (string)$r['id'] === (string)$regiao ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="ordem">Ordenar por:</label>
        <select name="ordem" id="ordem">
            <option value="votos" <?= $ordem === 'votos' ? 'selected' : '' ?>>Votos (maior)</option>
            <option value="nome"  <?= $ordem === 'nome'  ? 'selected' : '' ?>>Nome do candidato</option>
            <option value="cargo" <?= $ordem === 'cargo' ? 'selected' : '' ?>>Cargo</option>
            <?php if ($temAno): ?>
                <option value="ano" <?= $ordem === 'ano' ? 'selected' : '' ?>>Ano (mais recente)</option>
            <?php endif; ?>
        </select>

        <button type="submit" class="btn-consultar">Consultar</button>
        <a href="candidatos_campanhas.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<h2>Campanhas <?= $temFiltro ? '(filtradas)' : '' ?></h2>

<?php if (!$campanhas): ?>
    <div class="aviso">Nenhuma campanha encontrada para os filtros selecionados.</div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <?php if ($temAno): ?><th>Ano</th><?php endif; ?>
                <th class="col-numero">Nº</th>
                <th>Candidato</th>
                <th>Cargo</th>
                <th class="num">Municípios</th>
                <th class="num">Zonas</th>
                <th class="num">Votos</th>
                <th class="num">% do filtro</th>
                <th>Proporção</th>
                <?php if ($isAdmin): ?><th class="col-acoes">Ações</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php
            $pos = 1;
            $maiorTotal = $campanhas[0]['total'] ?? 0;
            foreach ($campanhas as $c):
                $chave = ($temAno ? ($c['ano'] ?? '—') . '|' : '')
                       . $c['candidato'] . '||' . $c['cargo'];
                $pct     = $totalVotosFiltro > 0 ? ($c['total'] / $totalVotosFiltro) * 100 : 0;
                $largura = $maiorTotal > 0 ? ($c['total'] / $maiorTotal) * 100 : 0;
                $numero  = $temNumero ? (int)($c['numero'] ?? 0) : 0;
            ?>
                <tr class="linha-campanha"
                    data-chave="<?= htmlspecialchars($chave) ?>"
                    data-candidato="<?= htmlspecialchars($c['candidato']) ?>"
                    data-cargo="<?= htmlspecialchars($c['cargo']) ?>"
                    data-ano="<?= !empty($c['ano']) ? (int)$c['ano'] : '' ?>">
                    <td><?= $pos++ ?></td>
                    <?php if ($temAno): ?>
                        <td>
                            <?php if (!empty($c['ano'])): ?>
                                <span class="badge badge-ano"><?= (int)$c['ano'] ?></span>
                            <?php else: ?>
                                <span style="color:#b94a48;">—</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="col-numero">
                        <?= $numero > 0 ? (int)$numero : '<span class="numero-vazio">—</span>' ?>
                    </td>
                    <td><?= htmlspecialchars($c['candidato']) ?></td>
                    <td><span class="badge badge-cargo"><?= htmlspecialchars($c['cargo']) ?></span></td>
                    <td class="num"><?= number_format($c['qtd_municipios'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format($c['qtd_zonas'], 0, ',', '.') ?></td>
                    <td class="num"><strong><?= number_format((int)$c['total'], 0, ',', '.') ?></strong></td>
                    <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                    <td>
                        <span class="barra"><span style="width: <?= number_format($largura, 2, '.', '') ?>%"></span></span>
                    </td>
                    <?php if ($isAdmin): ?>
                        <td class="col-acoes">
                            <button type="button"
                                    class="btn-lixeira"
                                    title="Apagar todos os registros deste candidato/cargo/ano"
                                    data-candidato="<?= htmlspecialchars($c['candidato']) ?>"
                                    data-cargo="<?= htmlspecialchars($c['cargo']) ?>"
                                    data-ano="<?= !empty($c['ano']) ? (int)$c['ano'] : '' ?>"
                                    data-numero="<?= $numero > 0 ? (int)$numero : '' ?>"
                                    data-total="<?= (int)$c['total'] ?>">
                                🗑️
                            </button>
                        </td>
                    <?php endif; ?>
                </tr>
                <tr class="linha-detalhe" style="display:none;">
                    <td colspan="<?= $numColunas ?>"></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="<?= ($temAno ? 6 : 5) + 1 ?>">Total do filtro</td>
                <td class="num"><?= number_format($totalVotosFiltro, 0, ',', '.') ?></td>
                <td class="num">100,00%</td>
                <td></td>
                <?php if ($isAdmin): ?><td></td><?php endif; ?>
            </tr>
        </tfoot>
    </table>

    <p style="font-size:12px;color:#888;">
        Dica: clique em uma linha para expandir a distribuição por região e município
        (os dados são carregados sob demanda).
        <?php if ($isAdmin): ?>
            Como admin, você pode apagar todos os registros de um candidato pelo ícone 🗑️.
        <?php else: ?>
            O ícone 🗑️ só aparece para <code>barreto</code> / <code>vbarreto</code>.
            <a href="?debug_sessao=1">Ver sessão →</a>
        <?php endif; ?>
    </p>
<?php endif; ?>

<h2>Rankings</h2>

<div class="grid-2">
    <div class="card">
        <h3>Top 10 candidatos</h3>
        <?php if (!$rankingCandidatos): ?>
            <p style="color:#888;">Sem dados para os filtros atuais.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($temAno): ?><th>Ano</th><?php endif; ?>
                        <th>Candidato</th>
                        <th class="num">Cargos</th>
                        <th class="num">Votos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($rankingCandidatos as $rc): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <?php if ($temAno): ?>
                                <td>
                                    <?php if (!empty($rc['ano'])): ?>
                                        <span class="badge badge-ano"><?= (int)$rc['ano'] ?></span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($rc['candidato']) ?></td>
                            <td class="num"><?= number_format($rc['qtd_cargos'], 0, ',', '.') ?></td>
                            <td class="num"><strong><?= number_format((int)$rc['total'], 0, ',', '.') ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Cargos e seus totais</h3>
        <?php if (!$rankingCargos): ?>
            <p style="color:#888;">Sem dados para os filtros atuais.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($temAno): ?><th>Ano</th><?php endif; ?>
                        <th>Cargo</th>
                        <th class="num">Candidatos</th>
                        <th class="num">Votos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($rankingCargos as $rc): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <?php if ($temAno): ?>
                                <td>
                                    <?php if (!empty($rc['ano'])): ?>
                                        <span class="badge badge-ano"><?= (int)$rc['ano'] ?></span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><span class="badge badge-cargo"><?= htmlspecialchars($rc['cargo']) ?></span></td>
                            <td class="num"><?= number_format($rc['qtd_candidatos'], 0, ',', '.') ?></td>
                            <td class="num"><strong><?= number_format((int)$rc['total'], 0, ',', '.') ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL — confirmação pelo NÚMERO (sem backup) -->
<?php if ($isAdmin): ?>
<div id="modal-apagar" aria-hidden="true">
    <div class="caixa">
        <div class="cabecalho">
            <div class="icone">⚠️</div>
            <h3>Confirmar exclusão permanente</h3>
        </div>
        <div class="corpo">
            Você está prestes a apagar <b>todos os registros</b> desta campanha na tabela
            <code>votos</code>. Esta ação <b>não pode ser desfeita</b>.

            <div class="destaque" id="modalDetalhe"></div>

            <div class="campo">
                <label for="confirmaNumero">
                    Para confirmar, digite o <b>número do candidato</b> abaixo:
                </label>
                <input type="text"
                       id="confirmaNumero"
                       inputmode="numeric"
                       pattern="[0-9]*"
                       maxlength="6"
                       autocomplete="off"
                       spellcheck="false"
                       placeholder="ex.: 13">
            </div>

            <div class="aviso-forte" id="avisoNumero">
                ⚠ O botão só será habilitado quando o número estiver correto.
            </div>
        </div>
        <div class="rodape">
            <button type="button" class="btn-cancelar" id="btnCancelar">Cancelar</button>
            <button type="button" class="btn-apagar" id="btnApagar" disabled>Apagar definitivamente</button>
        </div>
    </div>
</div>

<form method="post" id="formApagar" style="display:none;">
    <input type="hidden" name="acao" value="apagar_campanha">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="del_candidato" id="fDelCandidato">
    <input type="hidden" name="del_cargo"     id="fDelCargo">
    <input type="hidden" name="del_ano"       id="fDelAno">
</form>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
    // ============================================================
    // Select2 + auto-submit dos filtros
    // ============================================================
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
            placeholder: 'Buscar candidato...',
            allowClear: true,
            width: 'resolve',
            minimumInputLength: 0,
            matcher: matcherSemAcento
        });

        ['#ano', '#cargo', '#regiao', '#ordem'].forEach(function (sel) {
            jQuery(sel).on('change', function () {
                document.getElementById('form-filtros').submit();
            });
        });
    })();

    // ============================================================
    // Detalhe sob demanda (lazy loading)
    // ============================================================
    (function () {
        const CACHE = {};

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            })[c]);
        }
        function fmtNum(n) { return Number(n).toLocaleString('pt-BR'); }

        function renderDetalhe(dados) {
            let regioesHtml = '';
            (dados.por_regiao || []).forEach(function (r) {
                regioesHtml +=
                    '<tr><td>' + escapeHtml(r.regiao) + '</td>' +
                    '<td class="num">' + fmtNum(r.total) + '</td></tr>';
            });
            if (!regioesHtml) {
                regioesHtml = '<tr><td colspan="2" style="text-align:center;color:#999;">Sem dados</td></tr>';
            }

            let munHtml = '';
            (dados.por_municipio || []).forEach(function (m) {
                munHtml +=
                    '<tr><td>' + escapeHtml(m.nomeMunicipio) + '</td>' +
                    '<td>' + escapeHtml(m.regiao) + '</td>' +
                    '<td class="num">' + fmtNum(m.total) + '</td></tr>';
            });
            if (!munHtml) {
                munHtml = '<tr><td colspan="3" style="text-align:center;color:#999;">Sem dados</td></tr>';
            }

            return '<div class="grid-2">' +
                '<div class="card"><h3>Distribuição por região</h3>' +
                  '<table><thead><tr><th>Região</th>' +
                  '<th class="num">Votos</th></tr></thead>' +
                  '<tbody>' + regioesHtml + '</tbody></table></div>' +
                '<div class="card"><h3>Top 15 municípios</h3>' +
                  '<table><thead><tr><th>Município</th><th>Região</th>' +
                  '<th class="num">Votos</th></tr></thead>' +
                  '<tbody>' + munHtml + '</tbody></table></div>' +
                '</div>';
        }

        document.querySelectorAll('tr.linha-campanha').forEach(function (tr) {
            tr.style.cursor = 'pointer';
            tr.title = 'Clique para expandir';

            tr.addEventListener('click', function (e) {
                if (e.target.closest('a, button, input, select')) return;

                const detalhe = tr.nextElementSibling;
                if (!detalhe || !detalhe.classList.contains('linha-detalhe')) return;

                const visivel = detalhe.style.display !== 'none';
                if (visivel) {
                    detalhe.style.display = 'none';
                    return;
                }

                detalhe.style.display = 'table-row';
                const celula = detalhe.querySelector('td[colspan]');
                const chave  = tr.dataset.chave || '';

                // Cache
                if (CACHE[chave]) {
                    celula.innerHTML = CACHE[chave];
                    return;
                }

                // Carrega via AJAX
                celula.innerHTML =
                    '<div class="detalhe-loading">' +
                    '<span class="spinner"></span>Carregando detalhes…' +
                    '</div>';

                const qs = new URLSearchParams({
                    ajax:  'detalhe',
                    cand:  tr.dataset.candidato || '',
                    cargo: tr.dataset.cargo     || '',
                });
                if (tr.dataset.ano) qs.append('ano', tr.dataset.ano);

                fetch('candidatos_campanhas.php?' + qs.toString())
                    .then(r => r.json())
                    .then(j => {
                        if (!j.ok) {
                            celula.innerHTML =
                                '<div style="padding:20px;color:#b94a48;">' +
                                'Erro: ' + escapeHtml(j.msg || 'desconhecido') +
                                '</div>';
                            return;
                        }
                        const html = renderDetalhe(j);
                        CACHE[chave] = html;
                        celula.innerHTML = html;
                    })
                    .catch(() => {
                        celula.innerHTML =
                            '<div style="padding:20px;color:#b94a48;">Erro de rede.</div>';
                    });
            });
        });
    })();

    // ============================================================
    // Modal de exclusão (admin)
    // ============================================================
    <?php if ($isAdmin): ?>
    (function () {
        const modal        = document.getElementById('modal-apagar');
        const elDet        = document.getElementById('modalDetalhe');
        const inputConf    = document.getElementById('confirmaNumero');
        const avisoNumero  = document.getElementById('avisoNumero');
        const btnApagar    = document.getElementById('btnApagar');
        const btnCancelar  = document.getElementById('btnCancelar');
        const formApagar   = document.getElementById('formApagar');
        const fDelCand     = document.getElementById('fDelCandidato');
        const fDelCargo    = document.getElementById('fDelCargo');
        const fDelAno      = document.getElementById('fDelAno');

        let alvoAtual = null;
        const fmtNum = n => Number(n).toLocaleString('pt-BR');
        const escHtml = s => String(s).replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        })[c]);

        function abrirModal(dados) {
            alvoAtual = dados;

            const temNum    = (dados.numero && String(dados.numero).trim() !== '');
            const numeroRef = temNum ? String(dados.numero) : '0';

            elDet.innerHTML =
                '<b>Candidato:</b> ' + escHtml(dados.candidato) + '<br>' +
                '<b>Cargo:</b> ' + escHtml(dados.cargo) + '<br>' +
                (dados.ano ? '<b>Ano:</b> ' + dados.ano + '<br>' : '') +
                '<b>Número:</b> ' +
                  (temNum
                    ? '<span class="destaque-numero">' + escHtml(numeroRef) + '</span>'
                    : '<span style="color:#b94a48;font-weight:bold;">(sem número cadastrado)</span>'
                  ) + '<br>' +
                '<b>Votos que serão apagados:</b> ' + fmtNum(dados.total);

            inputConf.value = '';
            inputConf.dataset.numeroRef = numeroRef;
            btnApagar.disabled = true;
            btnApagar.textContent = 'Apagar definitivamente';

            if (temNum) {
                avisoNumero.textContent = '⚠ O botão só será habilitado quando o número estiver correto.';
            } else {
                avisoNumero.innerHTML =
                    '⚠ Este candidato não tem número cadastrado. Digite <b>0</b> para liberar o botão.';
            }

            modal.classList.add('ativo');
            modal.setAttribute('aria-hidden', 'false');
            setTimeout(() => inputConf.focus(), 80);
        }

        function fecharModal() {
            modal.classList.remove('ativo');
            modal.setAttribute('aria-hidden', 'true');
            alvoAtual = null;
            inputConf.value = '';
            btnApagar.disabled = true;
        }

        inputConf.addEventListener('input', () => {
            inputConf.value = inputConf.value.replace(/\D/g, '');
            if (!alvoAtual) return;
            const ref = inputConf.dataset.numeroRef || '0';
            btnApagar.disabled = (inputConf.value.trim() !== ref);
        });

        inputConf.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !btnApagar.disabled) { e.preventDefault(); btnApagar.click(); }
            if (e.key === 'Escape') fecharModal();
        });

        btnCancelar.addEventListener('click', fecharModal);
        modal.addEventListener('click', (e) => { if (e.target === modal) fecharModal(); });

        btnApagar.addEventListener('click', () => {
            if (!alvoAtual || btnApagar.disabled) return;
            fDelCand.value  = alvoAtual.candidato;
            fDelCargo.value = alvoAtual.cargo;
            fDelAno.value   = alvoAtual.ano || '';
            btnApagar.disabled = true;
            btnApagar.textContent = 'Apagando…';
            formApagar.submit();
        });

        document.querySelectorAll('.btn-lixeira').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const dados = {
                    candidato: btn.dataset.candidato,
                    cargo:     btn.dataset.cargo,
                    ano:       btn.dataset.ano,
                    numero:    btn.dataset.numero,
                    total:     parseInt(btn.dataset.total, 10) || 0,
                };

                const numExib = dados.numero ? dados.numero : '(sem número)';
                const msg1 =
                    'Deseja apagar TODOS os registros do candidato:\n\n' +
                    '  ' + dados.candidato + '\n' +
                    '  Número: ' + numExib + '\n' +
                    '  Cargo: ' + dados.cargo + (dados.ano ? ' | Ano: ' + dados.ano : '') + '\n\n' +
                    'Total: ' + fmtNum(dados.total) + ' votos.\n\n' +
                    'Esta ação NÃO pode ser desfeita. Continuar?';
                if (!confirm(msg1)) return;
                abrirModal(dados);
            });
        });
    })();
    <?php endif; ?>
</script>

</body>
</html>