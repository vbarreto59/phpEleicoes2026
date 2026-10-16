<?php
// ------------------------------------------------------------
// Configuração do banco
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';
date_default_timezone_set('America/Sao_Paulo');

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]
);

// ------------------------------------------------------------
// Fallback: garante que as variáveis de usuário existam
// ------------------------------------------------------------
if (!isset($__u)) {
    if (function_exists('usuarioLogado')) {
        $__u = usuarioLogado();
    } else {
        $__u = [
            'nome'   => $_SESSION['usuario_nome']   ?? '—',
            'login'  => $_SESSION['usuario_login']  ?? '',
            'perfil' => $_SESSION['usuario_perfil'] ?? '',
        ];
    }
}
$__perfil       = strtolower(trim((string)($__u['perfil'] ?? '')));
$__ehSuper      = ($__perfil === 'superadmin' || $__perfil === 'super');
$__ehAdminNivel = ($__perfil === 'admin' || $__perfil === 'administrador');

$__loginLower = mb_strtolower(trim((string)($__u['login'] ?? '')), 'UTF-8');
$__nomeLower  = mb_strtolower(trim((string)($__u['nome']  ?? '')), 'UTF-8');
$__loginsDono = ['barreto', 'vbarreto'];
$__nomesDono  = ['barreto', 'valter barreto'];
$__ehDono = in_array($__loginLower, $__loginsDono, true)
         || in_array($__nomeLower,  $__nomesDono,  true);

$__ehAdmin = ($__ehSuper || $__ehAdminNivel);
$isAdmin   = ($__ehAdmin && $__ehDono);

if ($__ehSuper) {
    $__classePerfil = 'super';
    $__rotuloPerfil = 'SUPERADMIN';
} elseif ($__ehAdminNivel) {
    $__classePerfil = 'admin';
    $__rotuloPerfil = 'ADMIN';
} else {
    $__classePerfil = 'visitante';
    $__rotuloPerfil = 'VISITANTE';
}

// ------------------------------------------------------------
// Detecta se a coluna 'ano' existe
// ------------------------------------------------------------
$colunasVotos = $pdo->query("SHOW COLUMNS FROM votos")->fetchAll(PDO::FETCH_COLUMN);
$temAno = in_array('ano', $colunasVotos, true);

// ------------------------------------------------------------
// Detecta colunas opcionais em secoes_enderecos
// ------------------------------------------------------------
$colunasSecoes = $pdo->query("SHOW COLUMNS FROM secoes_enderecos")
                     ->fetchAll(PDO::FETCH_COLUMN);

function achaColuna(array $disponiveis, array $candidatos) {
    foreach ($candidatos as $c) {
        if (in_array($c, $disponiveis, true)) return $c;
    }
    return null;
}

$colCoord = achaColuna($colunasSecoes, [
    'coordenador', 'nomeCoordenador', 'nome_coordenador',
    'coord', 'nome_coord', 'nm_coordenador'
]);
$colLider = achaColuna($colunasSecoes, [
    'lideranca', 'nomeLideranca', 'nome_lideranca',
    'lider', 'nome_lider', 'nm_lideranca'
]);

$coordExpr = $colCoord ? "s.`$colCoord`" : "NULL";
$liderExpr = $colLider ? "s.`$colLider`" : "NULL";

$grpExtra = '';
if ($colCoord) $grpExtra .= ", s.`$colCoord`";
if ($colLider) $grpExtra .= ", s.`$colLider`";

// ------------------------------------------------------------
// Helper: QUALQUER vantagem > 0 aparece como "X,X% acima"
// ------------------------------------------------------------
function fmtVantagem($pp) {
    return number_format($pp, 1, ',', '.') . '% acima';
}

// ------------------------------------------------------------
// Filtros recebidos
// ------------------------------------------------------------
$ano           = $_GET['ano']       ?? '';
$regiao        = $_GET['regiao']    ?? '';
$cargo         = $_GET['cargo']     ?? '';
$cand1         = $_GET['cand1']     ?? '';
$cand2         = $_GET['cand2']     ?? '';
$incluirVazias = isset($_GET['vazias']) && $_GET['vazias'] === '1';

$opacidade = isset($_GET['opac'])    ? (float)$_GET['opac']    : 0.90;
$escala    = isset($_GET['escala'])  ? (float)$_GET['escala']  : 1.00;
$limiar    = isset($_GET['limiar'])  ? (int)$_GET['limiar']    : 10;

if ($opacidade < 0.10) $opacidade = 0.10;
if ($opacidade > 1.00) $opacidade = 1.00;
if ($escala    < 0.50) $escala    = 0.50;
if ($escala    > 2.50) $escala    = 2.50;
if ($limiar    < 0)    $limiar    = 0;
if ($limiar    > 30)   $limiar    = 30;

// ------------------------------------------------------------
// Regras de seleção
// ------------------------------------------------------------
$temC1         = ($cand1 !== '');
$temC2         = ($cand2 !== '' && $cand2 !== $cand1);
$temAlgum      = $temC1 || $temC2;
$temComparacao = ($temC1 && $temC2);

// ------------------------------------------------------------
// Coleta de dados
// ------------------------------------------------------------
$pontos      = [];
$maxC1       = 0;
$maxC2       = 0;
$maxTot      = 0;
$totC1       = 0;
$totC2       = 0;
$totalSecoes = 0;

if ($temAlgum) {

    $vConds = [];
    $params = [];

    if ($temComparacao) {
        $vConds[] = "v.nomeCandidatoResp IN (:c1, :c2)";
        $params[':c1'] = $cand1;
        $params[':c2'] = $cand2;
        $params[':s1'] = $cand1;
        $params[':s2'] = $cand2;
    } elseif ($temC1) {
        $vConds[] = "v.nomeCandidatoResp = :c1";
        $params[':c1'] = $cand1;
    } else {
        $vConds[] = "v.nomeCandidatoResp = :c2";
        $params[':c2'] = $cand2;
    }

    if ($temAno && $ano !== '') {
        $vConds[] = "v.ano = :ano";
        $params[':ano'] = (int)$ano;
    }
    if ($cargo !== '') {
        $vConds[] = "v.nomeCargoPerg = :cargo";
        $params[':cargo'] = $cargo;
    }

    $sConds = [];
    if ($regiao !== '') {
        $sConds[] = "EXISTS (
            SELECT 1 FROM municipio_regiao mr
            WHERE mr.nomeMunicipio COLLATE utf8mb4_general_ci
                = s.nomeMunicipio  COLLATE utf8mb4_general_ci
              AND mr.regiao_id = :regiao
        )";
        $params[':regiao'] = (int)$regiao;
    }

    if ($incluirVazias) {
        $joinV  = $vConds ? ' AND ' . implode(' AND ', $vConds) : '';
        $whereS = $sConds ? 'WHERE ' . implode(' AND ', $sConds) : '';

        if ($temComparacao) {
            $sql = "
                SELECT
                    s.numeroZona, s.numeroSecao, s.nomeLocal, s.nomeMunicipio,
                    s.latitude, s.longitude,
                    $coordExpr AS coordenador,
                    $liderExpr AS lideranca,
                    COALESCE(SUM(CASE WHEN v.nomeCandidatoResp = :s1 THEN v.qtdVotos ELSE 0 END), 0) AS votos_c1,
                    COALESCE(SUM(CASE WHEN v.nomeCandidatoResp = :s2 THEN v.qtdVotos ELSE 0 END), 0) AS votos_c2
                FROM secoes_enderecos s
                LEFT JOIN votos v
                    ON v.numeroZona  = s.numeroZona
                   AND v.numeroSecao = s.numeroSecao
                   $joinV
                $whereS
                GROUP BY s.numeroZona, s.numeroSecao, s.nomeLocal,
                         s.nomeMunicipio, s.latitude, s.longitude
                         $grpExtra
            ";
        } else {
            $sql = "
                SELECT
                    s.numeroZona, s.numeroSecao, s.nomeLocal, s.nomeMunicipio,
                    s.latitude, s.longitude,
                    $coordExpr AS coordenador,
                    $liderExpr AS lideranca,
                    COALESCE(SUM(v.qtdVotos), 0) AS votos_c1,
                    0 AS votos_c2
                FROM secoes_enderecos s
                LEFT JOIN votos v
                    ON v.numeroZona  = s.numeroZona
                   AND v.numeroSecao = s.numeroSecao
                   $joinV
                $whereS
                GROUP BY s.numeroZona, s.numeroSecao, s.nomeLocal,
                         s.nomeMunicipio, s.latitude, s.longitude
                         $grpExtra
            ";
        }
    } else {
        $whereParts = $vConds;
        if ($regiao !== '') {
            $whereParts[] = "EXISTS (
                SELECT 1 FROM municipio_regiao mr
                WHERE mr.nomeMunicipio COLLATE utf8mb4_general_ci
                    = s.nomeMunicipio  COLLATE utf8mb4_general_ci
                  AND mr.regiao_id = :regiao
            )";
        }
        $whereSQL = 'WHERE ' . implode(' AND ', $whereParts);

        if ($temComparacao) {
            $sql = "
                SELECT
                    s.numeroZona, s.numeroSecao, s.nomeLocal, s.nomeMunicipio,
                    s.latitude, s.longitude,
                    $coordExpr AS coordenador,
                    $liderExpr AS lideranca,
                    SUM(CASE WHEN v.nomeCandidatoResp = :s1 THEN v.qtdVotos ELSE 0 END) AS votos_c1,
                    SUM(CASE WHEN v.nomeCandidatoResp = :s2 THEN v.qtdVotos ELSE 0 END) AS votos_c2
                FROM votos v
                INNER JOIN secoes_enderecos s
                    ON s.numeroZona  = v.numeroZona
                   AND s.numeroSecao = v.numeroSecao
                $whereSQL
                GROUP BY s.numeroZona, s.numeroSecao, s.nomeLocal,
                         s.nomeMunicipio, s.latitude, s.longitude
                         $grpExtra
            ";
        } else {
            $sql = "
                SELECT
                    s.numeroZona, s.numeroSecao, s.nomeLocal, s.nomeMunicipio,
                    s.latitude, s.longitude,
                    $coordExpr AS coordenador,
                    $liderExpr AS lideranca,
                    SUM(v.qtdVotos) AS votos_c1,
                    0 AS votos_c2
                FROM votos v
                INNER JOIN secoes_enderecos s
                    ON s.numeroZona  = v.numeroZona
                   AND s.numeroSecao = v.numeroSecao
                $whereSQL
                GROUP BY s.numeroZona, s.numeroSecao, s.nomeLocal,
                         s.nomeMunicipio, s.latitude, s.longitude
                         $grpExtra
            ";
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $lat = (float)$row['latitude'];
        $lng = (float)$row['longitude'];
        if ($lat === 0.0 && $lng === 0.0) continue;

        $c1  = (int)$row['votos_c1'];
        $c2  = (int)$row['votos_c2'];
        $tot = $c1 + $c2;

        if (!$incluirVazias && $tot === 0) continue;

        $pontos[] = [
            'lat'   => $lat,
            'lng'   => $lng,
            'zona'  => (int)$row['numeroZona'],
            'secao' => (int)$row['numeroSecao'],
            'local' => $row['nomeLocal'] ?? '',
            'mun'   => $row['nomeMunicipio'] ?? '',
            'coord' => $row['coordenador'] ?? '',
            'lider' => $row['lideranca']   ?? '',
            'c1'    => $c1,
            'c2'    => $c2,
            'tot'   => $tot,
        ];

        $totC1 += $c1;
        $totC2 += $c2;
        $totalSecoes++;

        if ($c1  > $maxC1)  $maxC1  = $c1;
        if ($c2  > $maxC2)  $maxC2  = $c2;
        if ($tot > $maxTot) $maxTot = $tot;
    }
}

// ------------------------------------------------------------
// Agregação por município
// ------------------------------------------------------------
$porMunicipio = [];

function normalizaMun($s) {
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ]);
}

foreach ($pontos as $p) {
    $mun = $p['mun'] !== '' ? $p['mun'] : '(sem município)';
    if (!isset($porMunicipio[$mun])) {
        $porMunicipio[$mun] = [
            'nome'   => $mun,
            'norm'   => normalizaMun($mun),
            'c1'     => 0,
            'c2'     => 0,
            'tot'    => 0,
            'secoes' => 0,
        ];
    }
    $porMunicipio[$mun]['c1']     += $p['c1'];
    $porMunicipio[$mun]['c2']     += $p['c2'];
    $porMunicipio[$mun]['tot']    += $p['tot'];
    $porMunicipio[$mun]['secoes'] += 1;
}

uasort($porMunicipio, function ($a, $b) {
    if ($b['tot'] === $a['tot']) return strcmp($a['nome'], $b['nome']);
    return $b['tot'] <=> $a['tot'];
});

// ------------------------------------------------------------
// RESUMO: quantos municípios cada candidato lidera
// ------------------------------------------------------------
$resumo = [
    'total'       => count($porMunicipio),
    'lidera_c1'   => 0,
    'lidera_c2'   => 0,
    'empates'     => 0,
    'sem_votos'   => 0,
];

foreach ($porMunicipio as $m) {
    if ($m['tot'] === 0) {
        $resumo['sem_votos']++;
    } elseif ($m['c1'] > $m['c2']) {
        $resumo['lidera_c1']++;
    } elseif ($m['c2'] > $m['c1']) {
        $resumo['lidera_c2']++;
    } else {
        $resumo['empates']++;
    }
}

// ------------------------------------------------------------
// Listas para os selects
// ------------------------------------------------------------
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Mapa Comparativo — Candidatos</title>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    h2 { margin-top:26px; }

    /* ===== Barra de usuário (mesmo modelo do consultar.php) ===== */
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

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:190px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#0069d9; margin-top:4px; }
    .kpi .sub    { font-size:12px; color:#888; margin-top:2px; min-height:14px; }
    .kpi.c1      { border-left-color:#28a745; } .kpi.c1 .valor { color:#28a745; }
    .kpi.c2      { border-left-color:#0d6efd; } .kpi.c2 .valor { color:#0d6efd; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor { color:#6f42c1; }
    .kpi.oculto  { display: none !important; }

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

    .chk-vazias {
        display:inline-flex; align-items:center; gap:6px;
        padding:5px 12px; border-radius:6px;
        border:2px solid #e3e3e3; background:#fff;
        font-size:12.5px; font-weight:bold; color:#555;
        cursor:pointer; user-select:none; margin:4px 10px 4px 0;
    }
    .chk-vazias:hover { border-color:#0069d9; color:#0069d9; }
    .chk-vazias input { margin:0; cursor:pointer; }
    .chk-vazias.ativo { border-color:#6c757d; background:#f0f0f0; color:#333; }

    .select2-container { margin: 4px 10px 4px 0; }
    .select2-container--default .select2-selection--single {
        height: 34px; padding: 3px 6px; border: 1px solid #ccc; border-radius: 4px; background:#fff;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 26px; color:#333;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px; }
    .select2-container .select2-selection--single { height: 34px; }
    .select2-search--dropdown .select2-search__field {
        padding: 6px; font-size: 14px; border-radius: 4px;
    }
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #0069d9;
    }
    #cand1 + .select2-container,
    #cand2 + .select2-container { min-width: 320px; max-width: 440px; }

    .cand-tag {
        display:inline-flex; align-items:center; gap:6px;
        font-weight:bold; font-size:13px;
    }
    .cand-tag .dot {
        display:inline-block; width:12px; height:12px; border-radius:3px;
    }
    .cand-tag.c1 .dot { background:#28a745; }
    .cand-tag.c2 .dot { background:#0d6efd; }

    .modo-btns { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
    .modo-btns button {
        background:#fff; border:2px solid #e3e3e3;
        padding:8px 14px; border-radius:6px; cursor:pointer;
        font-size:13px; color:#555; transition: all .12s ease;
    }
    .modo-btns button:hover:not(:disabled) { border-color:#0069d9; color:#0069d9; }
    .modo-btns button.ativo { border-color:#0069d9; color:#0069d9; font-weight:bold; background:#e7f3ff; }
    .modo-btns button:disabled { opacity: .35; cursor: not-allowed; text-decoration: line-through; }

    .controles {
        background:#fff; padding:10px 14px; border-radius:6px;
        box-shadow: 0 1px 2px rgba(0,0,0,.08); font-size:13px;
        display:flex; flex-wrap:wrap; align-items:center; gap:14px;
        margin-bottom:10px;
    }
    .controles label { font-weight:bold; font-size:12px; }
    .controles input[type=range] { width: 130px; vertical-align:middle; }
    .controles .valor { font-family:monospace; color:#0069d9; font-weight:bold;
                        min-width: 40px; display:inline-block; }
    .controles .sep { border-left:1px solid #eee; padding-left:14px; }
    .controles .grupo-limiar { display:none; }
    .controles.modo-disputa .grupo-limiar { display:inline-flex; align-items:center; gap:14px; }

    .cand-toggle {
        display: inline-flex; align-items: center; gap: 6px;
        cursor: pointer; padding: 5px 12px; border-radius: 6px;
        border: 2px solid #e3e3e3; background: #fff;
        font-size: 12.5px; font-weight: bold;
        transition: all .15s; user-select: none;
    }
    .cand-toggle:hover { border-color: #0069d9; }
    .cand-toggle input { margin: 0; cursor: pointer; }
    .cand-toggle .cor {
        width: 14px; height: 14px; border-radius: 3px;
        display: inline-block; border: 1px solid rgba(0,0,0,.15);
    }
    .cand-toggle.c1 .cor { background: #28a745; }
    .cand-toggle.c2 .cor { background: #0d6efd; }
    .cand-toggle.c1.ativo { border-color: #28a745; background: #f0fff4; }
    .cand-toggle.c2.ativo { border-color: #0d6efd; background: #f0f6ff; }
    .cand-toggle.c1.ativo span.nome { color: #1b5e20; }
    .cand-toggle.c2.ativo span.nome { color: #0d6efd; }
    .cand-toggle.oculto { border-style: dashed; border-color: #ccc; background: #f4f4f4; color: #999; }
    .cand-toggle.oculto span.nome { color: #999; text-decoration: line-through; }
    .cand-toggle.oculto .cor { opacity: .25; }
    .cand-toggle .nome {
        color: #555; max-width: 160px; overflow: hidden;
        text-overflow: ellipsis; white-space: nowrap;
    }

    #mapa { height: 640px; width: 100%; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.15); background:#e5e5e5;
            margin-top: 10px; }

    .legenda-cores {
        background:#fff; padding:10px 16px; border-radius:6px;
        box-shadow: 0 1px 2px rgba(0,0,0,.08);
        display:flex; flex-wrap:wrap; gap:22px; align-items:center;
        font-size:13px; margin-bottom: 10px;
        min-height: 42px;
    }
    .legenda-cores .item { display:flex; align-items:center; gap:8px; }
    .legenda-cores .grad {
        width: 140px; height: 12px; border-radius:3px;
        border: 1px solid #ddd;
    }
    .grad-margem {
        background: linear-gradient(to right,
            #0d47a1, #1976d2, #90caf9, #f5f5f5,
            #a5d6a7, #43a047, #1b5e20);
    }
    .grad-c1 { background: linear-gradient(to right, #e8f5e9, #81c784, #43a047, #1b5e20); }
    .grad-c2 { background: linear-gradient(to right, #e3f2fd, #64b5f6, #1976d2, #0d47a1); }
    .legenda-vazia { color:#856404; font-weight:bold; font-size:13px; }

    .modo-unico-badge {
        display: inline-flex; align-items: center; gap: 6px;
        background: #e7f3ff; color: #0053b3;
        border-left: 4px solid #0069d9;
        padding: 8px 14px; border-radius: 6px;
        font-size: 13px; margin-bottom: 14px;
    }
    .modo-unico-badge b { font-weight: bold; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso code { background:#fff; padding:1px 5px; border-radius:3px; font-size:12px; border:1px solid #eee; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    .leaflet-popup-content { font-family: Arial, sans-serif; font-size: 13px; margin: 10px 12px; }
    .leaflet-popup-content b { color:#0069d9; }

    .leaflet-tooltip {
        background: rgba(255,255,255,.98);
        border: 1px solid #ccc;
        border-radius: 6px;
        box-shadow: 0 2px 8px rgba(0,0,0,.15);
        padding: 8px 10px;
        color: #333;
        max-width: 320px;
    }
    .leaflet-tooltip-top:before,
    .leaflet-tooltip-bottom:before,
    .leaflet-tooltip-left:before,
    .leaflet-tooltip-right:before { display: none; }

    .tt-nenhum {
        margin-top: 6px;
        padding: 5px 8px;
        background: #f0f0f0;
        border-radius: 4px;
        color: #555;
        font-weight: bold;
        text-align: center;
        letter-spacing: .5px;
    }

    .donut-ico { background: transparent; border: none; }

    /* ============================================================
       TABELAS
       ============================================================ */
    .tabela-wrap {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.10);
        margin-top: 22px;
        overflow: hidden;
    }
    .tabela-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        border-bottom: 1px solid #eee;
        flex-wrap: wrap;
        gap: 10px;
        background: #fbfbfb;
    }
    .tabela-header h2 {
        margin: 0;
        font-size: 15px;
        color: #333;
        letter-spacing: .3px;
    }
    .tabela-header .contagem {
        color: #888;
        font-size: 12px;
        font-weight: normal;
        margin-left: 6px;
    }
    .tabela-filtro {
        padding: 6px 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 13px;
        min-width: 220px;
        outline: none;
    }
    .tabela-filtro:focus { border-color: #0069d9; }

    .tabela-scroll {
        max-height: 520px;
        overflow-y: auto;
    }

    table.tabela-municipios {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    table.tabela-municipios thead th {
        position: sticky;
        top: 0;
        background: #f7f7f7;
        text-align: left;
        padding: 10px 14px;
        border-bottom: 2px solid #ddd;
        font-size: 11px;
        text-transform: uppercase;
        color: #666;
        letter-spacing: .6px;
        z-index: 1;
        white-space: nowrap;
    }
    table.tabela-municipios thead th.num { text-align: right; }

    table.tabela-municipios tbody td {
        padding: 8px 14px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: middle;
    }
    table.tabela-municipios tbody td.num {
        text-align: right;
        font-variant-numeric: tabular-nums;
        font-family: 'Courier New', monospace;
        font-weight: bold;
    }
    table.tabela-municipios tbody tr:hover { background: #fafafa; }
    table.tabela-municipios tbody tr:nth-child(even) { background: #fcfcfc; }
    table.tabela-municipios tbody tr:nth-child(even):hover { background: #f5f5f5; }

    .col-c1  { color: #1b5e20; }
    .col-c2  { color: #0d47a1; }
    .col-vant { font-size: 12px; }
    .col-vant .nome-lider { font-weight: bold; }
    .col-vant .pp { color: #999; font-size: 11px; font-family: 'Courier New', monospace; }

    tfoot.total-geral td {
        padding: 12px 14px;
        background: #f7f7f7;
        border-top: 2px solid #ddd;
        font-weight: bold;
        font-size: 13px;
    }
    tfoot.total-geral td.num {
        text-align: right;
        font-family: 'Courier New', monospace;
        font-variant-numeric: tabular-nums;
    }

    table.tabela-municipios.ocultar-c1 .col-c1,
    table.tabela-municipios.ocultar-c2 .col-c2,
    table.tabela-municipios.ocultar-c1 .col-vant,
    table.tabela-municipios.ocultar-c2 .col-vant { display: none; }

    .sem-resultado {
        text-align: center;
        padding: 24px;
        color: #999;
        font-style: italic;
        display: none;
    }

    /* ============================================================
       TABELA RESUMO
       ============================================================ */
    .resumo-wrap {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.10);
        margin-top: 22px;
        overflow: hidden;
        max-width: 720px;
    }
    .resumo-wrap .tabela-header {
        background: #fbfbfb;
    }
    table.tabela-resumo {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    table.tabela-resumo thead th {
        background: #f7f7f7;
        text-align: left;
        padding: 12px 16px;
        border-bottom: 2px solid #ddd;
        font-size: 11px;
        text-transform: uppercase;
        color: #666;
        letter-spacing: .6px;
    }
    table.tabela-resumo thead th.num { text-align: right; }

    table.tabela-resumo tbody td {
        padding: 14px 16px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: middle;
        font-size: 14px;
    }
    table.tabela-resumo tbody td.num {
        text-align: right;
        font-variant-numeric: tabular-nums;
        font-family: 'Courier New', monospace;
        font-weight: bold;
        font-size: 16px;
    }
    table.tabela-resumo tbody tr:hover { background: #fafafa; }

    table.tabela-resumo td.cel-c1 { color: #1b5e20; }
    table.tabela-resumo td.cel-c2 { color: #0d47a1; }
    table.tabela-resumo td.cel-cinza { color: #6c757d; }

    .resumo-dot {
        display: inline-block;
        width: 12px; height: 12px;
        border-radius: 3px;
        margin-right: 6px;
        vertical-align: middle;
    }
    .resumo-dot.c1 { background: #28a745; }
    .resumo-dot.c2 { background: #0d6efd; }
    .resumo-dot.emp { background: #adb5bd; }
    .resumo-dot.sv  { background: #ced4da; }

    .resumo-linha-total td {
        background: #f7f7f7;
        border-top: 2px solid #ddd;
        font-weight: bold;
    }
    .resumo-linha-total td.num {
        font-size: 16px;
    }

    .resumo-barra {
        display: flex;
        height: 8px;
        border-radius: 4px;
        overflow: hidden;
        background: #eee;
        margin-top: 4px;
        max-width: 240px;
        margin-left: auto;
    }
    .resumo-barra > div { height: 100%; }
    .resumo-barra .b-c1 { background: #28a745; }
    .resumo-barra .b-c2 { background: #0d6efd; }
    .resumo-barra .b-emp { background: #adb5bd; }
    .resumo-barra .b-sv  { background: #ced4da; }

    .resumo-wrap.ocultar-c1 .linha-c1 { display: none; }
    .resumo-wrap.ocultar-c2 .linha-c2 { display: none; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Mapa Comparativo — Candidatos</h1>

<div class="barra-usuario <?= $__ehAdmin ? ($__ehSuper ? 'super' : 'admin') : '' ?>">
    <span>👤 <span class="nome-user"><?= htmlspecialchars($__u['nome'] ?? '—') ?></span></span>
    <span class="perfil-tag <?= htmlspecialchars($__classePerfil) ?>">
        <?= htmlspecialchars($__rotuloPerfil) ?>
    </span>
    <?php if ($isAdmin): ?>
        <span class="<?= $__ehSuper ? 'badge-super' : 'badge-admin' ?>">
            <?= $__ehSuper ? '🔓 superadmin' : '🔓 administrador' ?>
        </span>
    <?php elseif ($__ehAdmin && !$__ehDono): ?>
        <span class="badge-admin">🔓 admin (não autorizado a excluir)</span>
    <?php else: ?>
        <span class="badge-visitante">👁️ visitante</span>
    <?php endif; ?>

    <a class="link-debug" href="?debug_sessao=1">🔍 ver sessão</a>
</div>

<?php if (!$temAno): ?>
    <div class="aviso">
        <strong>Dica:</strong> a coluna <code>ano</code> ainda não existe em <code>votos</code>.
        O filtro de ano está desabilitado.
    </div>
<?php endif; ?>

<?php if (!$colCoord && !$colLider): ?>
    <div class="aviso">
        <strong>Aviso:</strong> não encontrei as colunas <code>coordenador</code> nem
        <code>lideranca</code> em <code>secoes_enderecos</code>.
        O tooltip mostrará apenas município e coordenadas.
        <br>
        <small style="color:#666;">
            Se as colunas tiverem outro nome, ajuste a lista em <code>achaColuna()</code>
            no topo do arquivo.
        </small>
    </div>
<?php endif; ?>

<form method="get" action="mapa_calor_dois_v2.php" id="form-filtros">
    <div class="filtros">
        <?php if ($temAno && $anosDisponiveis): ?>
            <label for="ano">Ano:</label>
            <select name="ano" id="ano" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($anosDisponiveis as $a): ?>
                    <option value="<?= (int)$a ?>" <?= (string)$a === (string)$ano ? 'selected' : '' ?>>
                        <?= (int)$a ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <label for="regiao">Região:</label>
        <select name="regiao" id="regiao" onchange="this.form.submit()">
            <option value="">Todas</option>
            <?php foreach ($regioes as $r): ?>
                <option value="<?= (int)$r['id'] ?>"
                    <?= (string)$r['id'] === (string)$regiao ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="cargo">Cargo:</label>
        <select name="cargo" id="cargo" onchange="this.form.submit()">
            <option value="">Todos</option>
            <?php foreach ($cargos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= $c === $cargo ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="chk-vazias <?= $incluirVazias ? 'ativo' : '' ?>">
            <input type="checkbox" name="vazias" value="1"
                   <?= $incluirVazias ? 'checked' : '' ?>
                   onchange="this.form.submit()">
            Incluir seções sem voto (NENHUM)
        </label>
    </div>

    <div class="filtros" style="margin-top:8px;">
        <label for="cand1">
            <span class="cand-tag c1"><span class="dot"></span>Candidato 1:</span>
        </label>
        <select name="cand1" id="cand1">
            <option value="">-- Selecione --</option>
            <?php foreach ($candidatos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= $c === $cand1 ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="cand2">
            <span class="cand-tag c2"><span class="dot"></span>Candidato 2 (opcional):</span>
        </label>
        <select name="cand2" id="cand2">
            <option value="">-- Nenhum --</option>
            <?php foreach ($candidatos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= $c === $cand2 ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn-consultar">Gerar Mapa</button>
        <a href="mapa_calor_dois_v2.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<?php if (!$temAlgum): ?>

    <div class="aviso">
        Selecione <strong>um ou dois candidatos</strong> para gerar o mapa.
        <br>
        <small style="color:#666;">
            Com 1 candidato → mapa de intensidade.
            Com 2 candidatos → modos <b>margem relativa</b>, <b>donut</b> e <b>zonas de disputa</b>.
        </small>
    </div>

<?php elseif (!$pontos): ?>

    <div class="aviso">
        Nenhuma seção com voto e coordenada foi encontrada para os filtros.
        Confirme se <code>secoes_enderecos</code> tem as coordenadas das seções.
    </div>

<?php else: ?>

    <!-- KPIs -->
    <div class="kpis">
        <?php if ($temC1): ?>
            <div class="kpi c1" id="kpiC1">
                <div class="rotulo"><?= htmlspecialchars($cand1) ?></div>
                <div class="valor" id="kpiC1Valor"><?= number_format($totC1, 0, ',', '.') ?></div>
                <div class="sub" id="kpiC1Sub"></div>
            </div>
        <?php endif; ?>

        <?php if ($temC2): ?>
            <div class="kpi c2" id="kpiC2">
                <div class="rotulo"><?= htmlspecialchars($cand2) ?></div>
                <div class="valor" id="kpiC2Valor"><?= number_format($totC2, 0, ',', '.') ?></div>
                <div class="sub" id="kpiC2Sub"></div>
            </div>
        <?php endif; ?>

        <?php if ($temComparacao): ?>
            <div class="kpi roxo" id="kpiDif">
                <div class="rotulo">Diferença</div>
                <div class="valor" id="kpiDifValor">0</div>
                <div class="sub" id="kpiDifSub"></div>
            </div>
        <?php endif; ?>

        <div class="kpi cinza">
            <div class="rotulo">Seções no mapa</div>
            <div class="valor" id="kpiSecoesValor"><?= number_format($totalSecoes, 0, ',', '.') ?></div>
        </div>
    </div>

    <?php if (!$temComparacao): ?>
        <div class="modo-unico-badge">
            ℹ️ Exibindo <b><?= htmlspecialchars($temC1 ? $cand1 : $cand2) ?></b>.
            Selecione um segundo candidato para habilitar a comparação.
        </div>
    <?php else: ?>
        <h2>Visualização</h2>

        <div class="modo-btns" id="modo-btns">
            <button type="button" data-modo="margem" class="ativo">🎨 Ambos</button>
            
            
            <button type="button" data-modo="c1">🟢 Apenas <?= htmlspecialchars($cand1) ?></button>
            <button type="button" data-modo="c2">🔵 Apenas <?= htmlspecialchars($cand2) ?></button>
        </div>
    <?php endif; ?>

    <div class="controles" id="controles">
        <label for="ctrlEscala">Escala:</label>
        <input type="range" id="ctrlEscala" min="0.5" max="2.5" step="0.1" value="<?= htmlspecialchars($escala) ?>">
        <span class="valor" id="ctrlEscalaVal"><?= htmlspecialchars(number_format($escala, 1)) ?>×</span>

        <span class="sep">
            <label for="ctrlOpac">Opacidade:</label>
            <input type="range" id="ctrlOpac" min="0.1" max="1" step="0.05" value="<?= htmlspecialchars($opacidade) ?>">
            <span class="valor" id="ctrlOpacVal"><?= htmlspecialchars(number_format($opacidade, 2)) ?></span>
        </span>

        <span class="grupo-limiar">
            <label for="ctrlLimiar">Limiar de disputa:</label>
            <input type="range" id="ctrlLimiar" min="0" max="30" step="1" value="<?= (int)$limiar ?>">
            <span class="valor" id="ctrlLimiarVal"><?= (int)$limiar ?>% acima</span>
        </span>

        <?php if ($temComparacao): ?>
            <span class="sep" id="grupo-toggles">
                <label class="cand-toggle c1 ativo" id="toggleC1">
                    <input type="checkbox" id="mostrarC1" checked>
                    <span class="cor"></span>
                    <span class="nome" title="<?= htmlspecialchars($cand1) ?>">
                        <?= htmlspecialchars($cand1) ?>
                    </span>
                </label>
                <label class="cand-toggle c2 ativo" id="toggleC2" style="margin-left:8px;">
                    <input type="checkbox" id="mostrarC2" checked>
                    <span class="cor"></span>
                    <span class="nome" title="<?= htmlspecialchars($cand2) ?>">
                        <?= htmlspecialchars($cand2) ?>
                    </span>
                </label>
            </span>
        <?php endif; ?>
    </div>

    <div class="legenda-cores" id="legenda-cores"></div>

    <div id="mapa"></div>

    <!-- ============================================================
         TABELA COMPARATIVA POR MUNICÍPIO
         ============================================================ -->
    <?php if ($porMunicipio): ?>
    <div class="tabela-wrap">
        <div class="tabela-header">
            <h2>
                📊 Comparativo por município
                <span class="contagem">(<?= number_format(count($porMunicipio), 0, ',', '.') ?> municípios)</span>
            </h2>
            <input type="text" id="buscaMunicipio" class="tabela-filtro"
                   placeholder="🔎 Filtrar município...">
        </div>
        <div class="tabela-scroll">
            <table class="tabela-municipios" id="tabelaMun">
                <thead>
                    <tr>
                        <th>Município</th>
                        <th class="num">Seções</th>
                        <th class="num col-c1"><?= htmlspecialchars($cand1 ?: 'Candidato 1') ?></th>
                        <th class="num col-c2"><?= htmlspecialchars($cand2 ?: 'Candidato 2') ?></th>
                        <th class="col-vant">Liderança</th>
                    </tr>
                </thead>
                <tbody id="tabelaMunBody">
                    <?php foreach ($porMunicipio as $m): ?>
                        <?php
                            $totM  = $m['tot'];
                            $diffM = $m['c1'] - $m['c2'];
                            if ($totM === 0) {
                                $vantHTML = '<span style="color:#aaa;">—</span>';
                            } elseif ($diffM === 0) {
                                $vantHTML = '<span style="color:#999;">Empate</span>';
                            } else {
                                $quem = $diffM > 0 ? $cand1 : $cand2;
                                $cor  = $diffM > 0 ? '#1b5e20' : '#0d47a1';
                                $ppM  = abs($diffM / $totM) * 100;
                                $vantHTML =
                                    '<span class="nome-lider" style="color:' . $cor . ';">● ' .
                                    htmlspecialchars($quem) . '</span>' .
                                    ' <span class="pp">+' . fmtVantagem($ppM) . '</span>';
                            }
                        ?>
                        <tr data-mun="<?= htmlspecialchars($m['norm']) ?>">
                            <td><?= htmlspecialchars($m['nome']) ?></td>
                            <td class="num"><?= number_format($m['secoes'], 0, ',', '.') ?></td>
                            <td class="num col-c1"><?= number_format($m['c1'], 0, ',', '.') ?></td>
                            <td class="num col-c2"><?= number_format($m['c2'], 0, ',', '.') ?></td>
                            <td class="col-vant"><?= $vantHTML ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="total-geral">
                    <tr>
                        <td>TOTAL GERAL</td>
                        <td class="num"><?= number_format($totalSecoes, 0, ',', '.') ?></td>
                        <td class="num col-c1"><?= number_format($totC1, 0, ',', '.') ?></td>
                        <td class="num col-c2"><?= number_format($totC2, 0, ',', '.') ?></td>
                        <td class="col-vant">
                            <?php
                            $difT = $totC1 - $totC2;
                            $totT = $totC1 + $totC2;
                            if ($totT === 0) {
                                echo '<span style="color:#aaa;">—</span>';
                            } elseif ($difT === 0) {
                                echo '<span style="color:#999;">Empate</span>';
                            } else {
                                $quem = $difT > 0 ? $cand1 : $cand2;
                                $cor  = $difT > 0 ? '#1b5e20' : '#0d47a1';
                                $pp   = abs($difT / $totT) * 100;
                                echo '<span class="nome-lider" style="color:' . $cor . ';">● ' .
                                     htmlspecialchars($quem) . '</span>' .
                                     ' <span class="pp">+' . fmtVantagem($pp) . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="sem-resultado" id="semResultadoMun">
            Nenhum município encontrado com o filtro atual.
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         TABELA RESUMO: quantos municípios cada candidato lidera
         ============================================================ -->
    <?php if ($temComparacao && $resumo['total'] > 0): ?>
        <?php
            $totalConsiderados = $resumo['total'];
            $pctC1  = $totalConsiderados > 0 ? ($resumo['lidera_c1'] / $totalConsiderados) * 100 : 0;
            $pctC2  = $totalConsiderados > 0 ? ($resumo['lidera_c2'] / $totalConsiderados) * 100 : 0;
            $pctEmp = $totalConsiderados > 0 ? ($resumo['empates']   / $totalConsiderados) * 100 : 0;
            $pctSV  = $totalConsiderados > 0 ? ($resumo['sem_votos'] / $totalConsiderados) * 100 : 0;
        ?>
        <div class="resumo-wrap" id="resumoWrap">
            <div class="tabela-header">
                <h2>🏆 Municípios em que cada candidato lidera</h2>
            </div>
            <table class="tabela-resumo">
                <thead>
                    <tr>
                        <th>Candidato</th>
                        <th class="num">Municípios liderando</th>
                        <th class="num">% dos municípios</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="linha-c1">
                        <td class="cel-c1">
                            <span class="resumo-dot c1"></span>
                            <b><?= htmlspecialchars($cand1) ?></b>
                        </td>
                        <td class="num cel-c1"><?= number_format($resumo['lidera_c1'], 0, ',', '.') ?></td>
                        <td class="num cel-c1">
                            <?= number_format($pctC1, 1, ',', '.') ?>%
                            <div class="resumo-barra">
                                <div class="b-c1" style="width: <?= $pctC1 ?>%;"></div>
                            </div>
                        </td>
                    </tr>
                    <tr class="linha-c2">
                        <td class="cel-c2">
                            <span class="resumo-dot c2"></span>
                            <b><?= htmlspecialchars($cand2) ?></b>
                        </td>
                        <td class="num cel-c2"><?= number_format($resumo['lidera_c2'], 0, ',', '.') ?></td>
                        <td class="num cel-c2">
                            <?= number_format($pctC2, 1, ',', '.') ?>%
                            <div class="resumo-barra">
                                <div class="b-c2" style="width: <?= $pctC2 ?>%;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php if ($resumo['empates'] > 0): ?>
                    <tr class="linha-emp">
                        <td class="cel-cinza">
                            <span class="resumo-dot emp"></span>
                            <b>Empates</b>
                        </td>
                        <td class="num cel-cinza"><?= number_format($resumo['empates'], 0, ',', '.') ?></td>
                        <td class="num cel-cinza">
                            <?= number_format($pctEmp, 1, ',', '.') ?>%
                            <div class="resumo-barra">
                                <div class="b-emp" style="width: <?= $pctEmp ?>%;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($resumo['sem_votos'] > 0): ?>
                    <tr class="linha-sv">
                        <td class="cel-cinza">
                            <span class="resumo-dot sv"></span>
                            <b>Sem votos dos dois</b>
                        </td>
                        <td class="num cel-cinza"><?= number_format($resumo['sem_votos'], 0, ',', '.') ?></td>
                        <td class="num cel-cinza">
                            <?= number_format($pctSV, 1, ',', '.') ?>%
                            <div class="resumo-barra">
                                <div class="b-sv" style="width: <?= $pctSV ?>%;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="resumo-linha-total">
                        <td>Total de municípios</td>
                        <td class="num"><?= number_format($totalConsiderados, 0, ',', '.') ?></td>
                        <td class="num">100,0%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>

    <script>
        const PONTOS         = <?= json_encode($pontos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const CAND1          = <?= json_encode($cand1, JSON_UNESCAPED_UNICODE) ?>;
        const CAND2          = <?= json_encode($cand2, JSON_UNESCAPED_UNICODE) ?>;
        const TEM_C1         = <?= $temC1 ? 'true' : 'false' ?>;
        const TEM_C2         = <?= $temC2 ? 'true' : 'false' ?>;
        const TEM_COMP       = <?= $temComparacao ? 'true' : 'false' ?>;
        const INCLUIR_VAZIAS = <?= $incluirVazias ? 'true' : 'false' ?>;

        const MAX_C1   = <?= (int)$maxC1 ?>;
        const MAX_C2   = <?= (int)$maxC2 ?>;
        const MAX_TOT  = <?= (int)$maxTot ?>;

        let ESCALA_ATUAL = <?= json_encode((float)$escala) ?>;
        let OPAC_ATUAL   = <?= json_encode((float)$opacidade) ?>;
        let LIMIAR_ATUAL = <?= (int)$limiar ?>;
    </script>

    <script>
        (function () {
            function formatNum(n) { return Number(n).toLocaleString('pt-BR'); }
            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, c => ({
                    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
                })[c]);
            }
            function fmtVantagem(pp) {
                return pp.toLocaleString('pt-BR', {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 1
                }) + '% acima';
            }

            const COR_C1      = '#28a745';
            const COR_C1_TXT  = '#1b5e20';
            const COR_C2      = '#0d6efd';
            const COR_C2_TXT  = '#0d47a1';

            const mapa = L.map('mapa', { scrollWheelZoom: true })
                           .setView([-8.05, -34.9], 10);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(mapa);

            if (!PONTOS.length) return;

            (function espalhar() {
                const grupos = {};
                PONTOS.forEach(p => {
                    const key = p.lat.toFixed(5) + ',' + p.lng.toFixed(5);
                    (grupos[key] = grupos[key] || []).push(p);
                });
                Object.values(grupos).forEach(g => {
                    if (g.length < 2) return;
                    const raio = 0.0005 + 0.0002 * Math.min(g.length, 10);
                    g.forEach((p, i) => {
                        const ang = (2 * Math.PI * i) / g.length;
                        p.lat += raio * Math.cos(ang);
                        p.lng += raio * Math.sin(ang);
                    });
                });
            })();

            const bounds = L.latLngBounds([]);
            PONTOS.forEach(p => bounds.extend([p.lat, p.lng]));
            if (bounds.isValid()) {
                mapa.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 });
            }

            let visC1 = true, visC2 = true;
            let efC1  = true, efC2  = true;
            let nDesenhadas = 0;
            let modoAtual = TEM_COMP ? 'margem' : (TEM_C1 ? 'c1' : 'c2');

            const ESCALA_CORES = [
                [-1.00, [ 13,  71, 161]],
                [-0.50, [ 25, 118, 210]],
                [-0.20, [100, 181, 246]],
                [-0.05, [187, 222, 251]],
                [ 0.00, [245, 245, 245]],
                [ 0.05, [200, 230, 201]],
                [ 0.20, [165, 214, 167]],
                [ 0.50, [ 67, 160,  71]],
                [ 1.00, [ 27,  94,  32]]
            ];
            function corMargem(t) {
                t = Math.max(-1, Math.min(1, t));
                for (let i = 0; i < ESCALA_CORES.length - 1; i++) {
                    const [p0, c0] = ESCALA_CORES[i];
                    const [p1, c1] = ESCALA_CORES[i + 1];
                    if (t >= p0 && t <= p1) {
                        const k = (p1 - p0) === 0 ? 0 : (t - p0) / (p1 - p0);
                        return `rgb(${Math.round(c0[0] + k * (c1[0] - c0[0]))},` +
                               `${Math.round(c0[1] + k * (c1[1] - c0[1]))},` +
                               `${Math.round(c0[2] + k * (c1[2] - c0[2]))})`;
                    }
                }
                return 'rgb(245,245,245)';
            }
            function corC1(v) {
                const t = MAX_C1 > 0 ? v / MAX_C1 : 0;
                return `rgb(${Math.round(232 - 205 * t)},${Math.round(245 - 151 * t)},${Math.round(233 - 201 * t)})`;
            }
            function corC2(v) {
                const t = MAX_C2 > 0 ? v / MAX_C2 : 0;
                return `rgb(${Math.round(227 - 214 * t)},${Math.round(242 - 171 * t)},${Math.round(253 -  92 * t)})`;
            }
            function margem(p) {
                const tot = p.c1 + p.c2;
                return tot > 0 ? (p.c1 - p.c2) / tot : 0;
            }
            function raioPorPeso(tot) {
                const base = 4 + 14 * Math.sqrt(tot / Math.max(MAX_TOT, 1));
                return Math.max(3, base * ESCALA_ATUAL);
            }

            function tooltipHTML(p) {
                const mostraC1 = TEM_C1 && efC1;
                const mostraC2 = TEM_C2 && efC2;
                const tot      = p.c1 + p.c2;

                let html = '<div style="min-width:220px;font-size:12px;line-height:1.55;">';
                html += '<div style="font-weight:bold;">Seção ' + p.secao +
                        ' — Zona ' + p.zona + '</div>';

                if (p.mun) {
                    html += '<div style="color:#333;font-size:12px;margin-top:2px;">🏙️ ' +
                            escapeHtml(p.mun) + '</div>';
                }
                if (p.local) {
                    html += '<div style="color:#666;font-size:11px;">📍 ' +
                            escapeHtml(p.local) + '</div>';
                }
                html += '<div style="color:#999;font-size:10.5px;margin-top:1px;">' +
                        '🌐 ' + p.lat.toFixed(5) + ', ' + p.lng.toFixed(5) + '</div>';

                const temCoord = p.coord && String(p.coord).trim() !== '';
                const temLider = p.lider && String(p.lider).trim() !== '';
                if (temCoord) {
                    html += '<div style="color:#444;font-size:11px;margin-top:3px;">' +
                            '👤 Coordenador: <b>' + escapeHtml(p.coord) + '</b></div>';
                }
                if (temLider) {
                    html += '<div style="color:#444;font-size:11px;' +
                            (temCoord ? '' : 'margin-top:3px;') + '">' +
                            '👥 Liderança: <b>' + escapeHtml(p.lider) + '</b></div>';
                }

                if (tot === 0 || (!mostraC1 && !mostraC2)) {
                    html += '<div class="tt-nenhum">NENHUM</div>';
                    html += '<div style="color:#999;font-size:10.5px;text-align:center;margin-top:2px;">' +
                            'sem votos dos candidatos selecionados</div>';
                    html += '</div>';
                    return html;
                }

                html += '<hr style="border:none;border-top:1px solid #eee;margin:5px 0;">';

                if (mostraC1) {
                    html += '<div style="display:flex;justify-content:space-between;gap:10px;">' +
                            '<span style="color:' + COR_C1 + ';">● ' + escapeHtml(CAND1) + '</span>' +
                            '<b>' + formatNum(p.c1) + '</b></div>';
                }
                if (mostraC2) {
                    html += '<div style="display:flex;justify-content:space-between;gap:10px;">' +
                            '<span style="color:' + COR_C2 + ';">● ' + escapeHtml(CAND2) + '</span>' +
                            '<b>' + formatNum(p.c2) + '</b></div>';
                }

                if (mostraC1 && mostraC2) {
                    const diff = p.c1 - p.c2;
                    html += '<hr style="border:none;border-top:1px solid #eee;margin:5px 0;">';
                    if (diff === 0) {
                        html += '<div style="color:#999;text-align:center;">Empate</div>';
                    } else {
                        const quem = diff > 0 ? CAND1 : CAND2;
                        const cor  = diff > 0 ? COR_C1 : COR_C2;
                        const pp   = Math.abs(diff / tot * 100);
                        html += '<div style="text-align:center;">Lidera: ' +
                                '<b style="color:' + cor + ';">' + escapeHtml(quem) + '</b>' +
                                ' <span style="color:#666;">(' + fmtVantagem(pp) + ')</span></div>';
                    }
                }

                html += '</div>';
                return html;
            }

            function popupHTML(p) {
                const mostraC1 = TEM_C1 && efC1;
                const mostraC2 = TEM_C2 && efC2;
                const tot      = p.c1 + p.c2;

                let html = '<div style="min-width:250px;">';
                html += '<div><b>Seção ' + p.secao + '</b> — Zona ' + p.zona + '</div>';
                if (p.mun)   html += '<div style="font-size:12px;color:#333;margin-top:2px;">🏙️ ' +
                                     escapeHtml(p.mun) + '</div>';
                if (p.local) html += '<div style="font-size:12px;color:#555;">📍 ' +
                                     escapeHtml(p.local) + '</div>';
                html += '<div style="font-size:11px;color:#999;margin-top:2px;">🌐 ' +
                        p.lat.toFixed(5) + ', ' + p.lng.toFixed(5) + '</div>';

                const temCoord = p.coord && String(p.coord).trim() !== '';
                const temLider = p.lider && String(p.lider).trim() !== '';
                if (temCoord) {
                    html += '<div style="font-size:11px;color:#444;margin-top:3px;">' +
                            '👤 Coordenador: <b>' + escapeHtml(p.coord) + '</b></div>';
                }
                if (temLider) {
                    html += '<div style="font-size:11px;color:#444;">' +
                            '👥 Liderança: <b>' + escapeHtml(p.lider) + '</b></div>';
                }

                if (tot === 0 || (!mostraC1 && !mostraC2)) {
                    html += '<hr style="border:none;border-top:1px solid #eee;margin:6px 0;">';
                    html += '<div class="tt-nenhum">NENHUM</div>';
                    html += '<div style="color:#999;font-size:11px;margin-top:4px;text-align:center;">' +
                            'sem votos dos candidatos selecionados</div>';
                    html += '</div>';
                    return html;
                }

                html += '<hr style="border:none;border-top:1px solid #eee;margin:6px 0;">';

                if (mostraC1) {
                    html += '<div style="display:flex;justify-content:space-between;gap:14px;">' +
                            '<span style="color:' + COR_C1 + ';font-weight:bold;">● ' + escapeHtml(CAND1) + '</span>' +
                            '<b>' + formatNum(p.c1) + '</b></div>';
                }
                if (mostraC2) {
                    html += '<div style="display:flex;justify-content:space-between;gap:14px;">' +
                            '<span style="color:' + COR_C2 + ';font-weight:bold;">● ' + escapeHtml(CAND2) + '</span>' +
                            '<b>' + formatNum(p.c2) + '</b></div>';
                }

                if (mostraC1 && mostraC2) {
                    const p1 = (p.c1 / tot) * 100;
                    const p2 = 100 - p1;
                    html += '<div style="margin-top:8px;display:flex;height:14px;border-radius:7px;' +
                            'overflow:hidden;border:1px solid #eee;">' +
                            '<div style="width:' + p1 + '%;background:' + COR_C1 + ';"></div>' +
                            '<div style="width:' + p2 + '%;background:' + COR_C2 + ';"></div></div>';
                    html += '<div style="display:flex;justify-content:space-between;' +
                            'font-size:11px;color:#666;margin-top:2px;">' +
                            '<span>' + p1.toFixed(1) + '%</span>' +
                            '<span>' + p2.toFixed(1) + '%</span></div>';

                    const diff = p.c1 - p.c2;
                    if (diff === 0) {
                        html += '<div style="margin-top:6px;font-size:12px;color:#999;text-align:center;">Empate exato</div>';
                    } else {
                        const quem = diff > 0 ? CAND1 : CAND2;
                        const cor  = diff > 0 ? COR_C1 : COR_C2;
                        const pp   = Math.abs(diff / tot * 100);
                        html += '<div style="margin-top:6px;font-size:12px;text-align:center;">' +
                                '<b style="color:' + cor + ';">' + escapeHtml(quem) + '</b>' +
                                ' lidera por ' + fmtVantagem(pp) + '</div>';
                    }
                }

                html += '</div>';
                return html;
            }

            const camada = L.layerGroup().addTo(mapa);
            function limpar() { camada.clearLayers(); nDesenhadas = 0; }
            function adicionar(layer, p) {
                layer.bindPopup(() => popupHTML(p))
                     .bindTooltip(() => tooltipHTML(p), {
                         sticky: true, direction: 'top', opacity: 1
                     })
                     .addTo(camada);
                nDesenhadas++;
            }
            function adicionarVazia(p) {
                adicionar(L.circleMarker([p.lat, p.lng], {
                    radius: 3,
                    fillColor: '#c8c8c8', fillOpacity: 0.35,
                    color: '#999', weight: 0.4
                }), p);
            }

            function desenharMargem() {
                limpar();
                PONTOS.forEach(p => {
                    if (p.tot === 0) { adicionarVazia(p); return; }
                    adicionar(L.circleMarker([p.lat, p.lng], {
                        radius: raioPorPeso(p.tot),
                        fillColor: corMargem(margem(p)),
                        fillOpacity: OPAC_ATUAL,
                        color: 'rgba(0,0,0,.30)', weight: 0.6
                    }), p);
                });
            }

            function desenharDonut() {
                limpar();
                PONTOS.forEach(p => {
                    if (p.tot === 0) { adicionarVazia(p); return; }
                    const r = raioPorPeso(p.tot) + 1;
                    const c = 2 * Math.PI * r;
                    const pct1 = p.c1 / p.tot;
                    const size = 2 * r + 2;
                    const cx   = r + 1;
                    const sw   = Math.max(2, r * 0.85);
                    const ri   = r - sw / 2;
                    const svg = `
                      <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
                        <circle cx="${cx}" cy="${cx}" r="${ri}" fill="none" stroke="${COR_C2}" stroke-width="${sw}"/>
                        <circle cx="${cx}" cy="${cx}" r="${ri}" fill="none" stroke="${COR_C1}" stroke-width="${sw}"
                                stroke-dasharray="${(c * pct1).toFixed(2)} ${c.toFixed(2)}"
                                transform="rotate(-90 ${cx} ${cx})"/>
                        <circle cx="${cx}" cy="${cx}" r="${Math.max(1, ri - sw / 2)}" fill="#fff"/>
                      </svg>`;
                    adicionar(L.marker([p.lat, p.lng], {
                        icon: L.divIcon({
                            html: svg, className: 'donut-ico',
                            iconSize: [size, size], iconAnchor: [cx, cx]
                        }),
                        interactive: true
                    }), p);
                });
            }

            function desenharDisputa() {
                limpar();
                const lim = LIMIAR_ATUAL / 100;
                PONTOS.forEach(p => {
                    if (p.tot === 0) return;
                    const m = margem(p);
                    if (Math.abs(m) > lim) return;
                    adicionar(L.circleMarker([p.lat, p.lng], {
                        radius: raioPorPeso(p.tot) + 1.5,
                        fillColor: corMargem(m),
                        fillOpacity: OPAC_ATUAL,
                        color: '#111', weight: 1.4
                    }), p);
                });
            }

            function desenharUnico(qual) {
                limpar();
                const usarC1 = (qual === 'c1');
                if (usarC1 && !TEM_C1) return;
                if (!usarC1 && !TEM_C2) return;
                const maxRef = usarC1 ? MAX_C1 : MAX_C2;
                PONTOS.forEach(p => {
                    const v = usarC1 ? p.c1 : p.c2;
                    if (v <= 0) {
                        if (INCLUIR_VAZIAS) adicionarVazia(p);
                        return;
                    }
                    const rBase = 4 + 14 * Math.sqrt(v / Math.max(maxRef, 1));
                    const r = Math.max(3, rBase * ESCALA_ATUAL);
                    adicionar(L.circleMarker([p.lat, p.lng], {
                        radius: r,
                        fillColor: usarC1 ? corC1(v) : corC2(v),
                        fillOpacity: OPAC_ATUAL,
                        color: 'rgba(0,0,0,.30)', weight: 0.6
                    }), p);
                });
            }

            const elLegenda = document.getElementById('legenda-cores');
            function atualizarLegenda(modoEf, modoOrig) {
                if (modoEf === 'vazio') {
                    elLegenda.innerHTML =
                        `<span class="legenda-vazia">⚠ Nenhum candidato visível — ative ao menos um.</span>`;
                    return;
                }
                if (modoEf === 'margem' || modoEf === 'disputa') {
                    const extra = modoEf === 'disputa'
                        ? ` · mostrando só seções com diferença ≤ ${LIMIAR_ATUAL}% acima` : '';
                    const vazias = INCLUIR_VAZIAS
                        ? ' · círculos cinza = sem voto (NENHUM)' : '';
                    elLegenda.innerHTML = `
                      <div class="item">
                        <span style="color:${COR_C2_TXT};font-weight:bold;">${escapeHtml(CAND2)} forte</span>
                        <span class="grad grad-margem"></span>
                        <span style="color:${COR_C1_TXT};font-weight:bold;">${escapeHtml(CAND1)} forte</span>
                      </div>
                      <span style="font-size:11px;color:#666;">
                        branco ≈ empate · tamanho do círculo = total de votos entre os dois${extra}${vazias}
                      </span>`;
                } else if (modoEf === 'donut') {
                    const vazias = INCLUIR_VAZIAS
                        ? ' · círculos cinza = sem voto (NENHUM)' : '';
                    elLegenda.innerHTML = `
                      <div class="item"><span style="color:${COR_C1};font-weight:bold;">● ${escapeHtml(CAND1)}</span></div>
                      <div class="item"><span style="color:${COR_C2};font-weight:bold;">● ${escapeHtml(CAND2)}</span></div>
                      <span style="font-size:11px;color:#666;">
                        fatia interna = proporção em cada seção · tamanho = total${vazias}
                      </span>`;
                } else if (modoEf === 'c1') {
                    const origem = (modoOrig === 'c1' || !TEM_COMP)
                        ? '' : ' <span style="font-size:11px;color:#888;">(C2 oculto)</span>';
                    elLegenda.innerHTML = `
                      <div class="item">
                        <span style="color:${COR_C1};font-weight:bold;">${escapeHtml(CAND1)}</span>
                        <span class="grad grad-c1"></span>
                        <span style="font-size:11px;color:#888;">menos → mais</span>
                      </div>${origem}`;
                } else if (modoEf === 'c2') {
                    const origem = (modoOrig === 'c2' || !TEM_COMP)
                        ? '' : ' <span style="font-size:11px;color:#888;">(C1 oculto)</span>';
                    elLegenda.innerHTML = `
                      <div class="item">
                        <span style="color:${COR_C2};font-weight:bold;">${escapeHtml(CAND2)}</span>
                        <span class="grad grad-c2"></span>
                        <span style="font-size:11px;color:#888;">menos → mais</span>
                      </div>${origem}`;
                } else {
                    elLegenda.innerHTML = '';
                }
            }

            const modosBtns  = document.querySelectorAll('#modo-btns button');
            const ckC1       = document.getElementById('mostrarC1');
            const ckC2       = document.getElementById('mostrarC2');
            const togC1      = document.getElementById('toggleC1');
            const togC2      = document.getElementById('toggleC2');
            const kpiC1      = document.getElementById('kpiC1');
            const kpiC2      = document.getElementById('kpiC2');
            const kpiC1Valor = document.getElementById('kpiC1Valor');
            const kpiC2Valor = document.getElementById('kpiC2Valor');
            const kpiC1Sub   = document.getElementById('kpiC1Sub');
            const kpiC2Sub   = document.getElementById('kpiC2Sub');
            const kpiDif      = document.getElementById('kpiDif');
            const kpiDifValor = document.getElementById('kpiDifValor');
            const kpiDifSub   = document.getElementById('kpiDifSub');
            const kpiSecoesValor = document.getElementById('kpiSecoesValor');
            const cont       = document.getElementById('controles');
            const tabelaMun  = document.getElementById('tabelaMun');
            const resumoWrap = document.getElementById('resumoWrap');

            function modoEfetivo() {
                if (!TEM_COMP) return TEM_C1 ? 'c1' : 'c2';
                if (!visC1 && !visC2) return 'vazio';
                if (visC1 && !visC2)  return 'c1';
                if (!visC1 && visC2)  return 'c2';
                return modoAtual;
            }
            function definirVisibilidadeEfetiva(m) {
                if (m === 'vazio')   { efC1 = false; efC2 = false; }
                else if (m === 'c1') { efC1 = TEM_C1; efC2 = false; }
                else if (m === 'c2') { efC1 = false;  efC2 = TEM_C2; }
                else                 { efC1 = TEM_C1; efC2 = TEM_C2; }
            }

            function atualizarBotoes() {
                if (!TEM_COMP) return;
                const m = modoEfetivo();
                modosBtns.forEach(btn => {
                    const modo = btn.dataset.modo;
                    let disabled = false;
                    if (!visC1 && !visC2) disabled = true;
                    else if (visC1 && !visC2 && modo !== 'c1') disabled = true;
                    else if (!visC1 && visC2 && modo !== 'c2') disabled = true;
                    btn.disabled = disabled;
                    btn.classList.toggle('ativo', modo === m && !disabled);
                });
            }
            function atualizarEstiloToggle(tog, ativo) {
                if (!tog) return;
                tog.classList.toggle('ativo',  ativo);
                tog.classList.toggle('oculto', !ativo);
            }

            function atualizarKPIs() {
                let t1 = 0, t2 = 0, s1 = 0, s2 = 0;
                PONTOS.forEach(p => {
                    t1 += p.c1; t2 += p.c2;
                    if (p.c1 > 0) s1++;
                    if (p.c2 > 0) s2++;
                });

                if (kpiC1) {
                    if (efC1) {
                        kpiC1.classList.remove('oculto');
                        kpiC1Valor.textContent = formatNum(t1);
                        kpiC1Sub.textContent   = 'votos em ' + formatNum(s1) + ' seções';
                    } else {
                        kpiC1.classList.add('oculto');
                        kpiC1Valor.textContent = '';
                        kpiC1Sub.textContent   = '';
                    }
                }
                if (kpiC2) {
                    if (efC2) {
                        kpiC2.classList.remove('oculto');
                        kpiC2Valor.textContent = formatNum(t2);
                        kpiC2Sub.textContent   = 'votos em ' + formatNum(s2) + ' seções';
                    } else {
                        kpiC2.classList.add('oculto');
                        kpiC2Valor.textContent = '';
                        kpiC2Sub.textContent   = '';
                    }
                }

                if (kpiDif) {
                    const comparando = efC1 && efC2;
                    kpiDif.classList.toggle('oculto', !comparando);
                    if (comparando) {
                        const dif = Math.abs(t1 - t2);
                        const tot = t1 + t2;
                        kpiDifValor.textContent = formatNum(dif);
                        if (t1 === t2) {
                            kpiDifSub.textContent = 'Empate técnico';
                        } else {
                            const lider = t1 > t2 ? CAND1 : CAND2;
                            const pp    = tot > 0 ? (dif / tot) * 100 : 0;
                            kpiDifSub.textContent = lider + ' lidera por ' + fmtVantagem(pp);
                        }
                    } else {
                        kpiDifValor.textContent = '';
                        kpiDifSub.textContent   = '';
                    }
                }

                if (kpiSecoesValor) kpiSecoesValor.textContent = formatNum(nDesenhadas);
            }

            function atualizarTabela() {
                if (tabelaMun) {
                    tabelaMun.classList.toggle('ocultar-c1', !efC1);
                    tabelaMun.classList.toggle('ocultar-c2', !efC2);
                }
                if (resumoWrap) {
                    resumoWrap.classList.toggle('ocultar-c1', !efC1);
                    resumoWrap.classList.toggle('ocultar-c2', !efC2);
                }
            }

            function redesenhar() {
                const m = modoEfetivo();
                definirVisibilidadeEfetiva(m);

                if (m === 'vazio')        limpar();
                else if (m === 'c1')      desenharUnico('c1');
                else if (m === 'c2')      desenharUnico('c2');
                else if (m === 'margem')  desenharMargem();
                else if (m === 'donut')   desenharDonut();
                else if (m === 'disputa') desenharDisputa();

                atualizarBotoes();
                atualizarEstiloToggle(togC1, visC1);
                atualizarEstiloToggle(togC2, visC2);
                atualizarKPIs();
                atualizarTabela();
                atualizarLegenda(m, modoAtual);
                cont.classList.toggle('modo-disputa', m === 'disputa');
            }

            modosBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    if (btn.disabled) return;
                    modoAtual = btn.dataset.modo;
                    redesenhar();
                });
            });

            function aoAlternar() {
                mapa.closePopup();
                if (visC1 && visC2 && (modoAtual === 'c1' || modoAtual === 'c2')) {
                    modoAtual = 'margem';
                }
                redesenhar();
            }
            if (ckC1) ckC1.addEventListener('change', () => { visC1 = ckC1.checked; aoAlternar(); });
            if (ckC2) ckC2.addEventListener('change', () => { visC2 = ckC2.checked; aoAlternar(); });

            function normaliza(s) {
                return String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
            }
            const inputBusca = document.getElementById('buscaMunicipio');
            const semRes     = document.getElementById('semResultadoMun');
            if (inputBusca) {
                inputBusca.addEventListener('input', () => {
                    const termo = normaliza(inputBusca.value.trim());
                    let visiveis = 0;
                    document.querySelectorAll('#tabelaMunBody tr').forEach(tr => {
                        const mun = tr.dataset.mun || '';
                        const ok  = !termo || mun.indexOf(termo) > -1;
                        tr.style.display = ok ? '' : 'none';
                        if (ok) visiveis++;
                    });
                    if (semRes) semRes.style.display = visiveis === 0 ? 'block' : 'none';
                });
            }

            const ctrlEscala = document.getElementById('ctrlEscala');
            const ctrlOpac   = document.getElementById('ctrlOpac');
            const ctrlLimiar = document.getElementById('ctrlLimiar');
            const elEscala   = document.getElementById('ctrlEscalaVal');
            const elOpac     = document.getElementById('ctrlOpacVal');
            const elLimiar   = document.getElementById('ctrlLimiarVal');

            if (ctrlEscala) ctrlEscala.addEventListener('input', () => {
                ESCALA_ATUAL = parseFloat(ctrlEscala.value);
                elEscala.textContent = ESCALA_ATUAL.toFixed(1) + '×';
                redesenhar();
            });
            if (ctrlOpac) ctrlOpac.addEventListener('input', () => {
                OPAC_ATUAL = parseFloat(ctrlOpac.value);
                elOpac.textContent = OPAC_ATUAL.toFixed(2);
                redesenhar();
            });
            if (ctrlLimiar) ctrlLimiar.addEventListener('input', () => {
                LIMIAR_ATUAL = parseInt(ctrlLimiar.value, 10);
                elLimiar.textContent = LIMIAR_ATUAL + '% acima';
                if (modoEfetivo() === 'disputa') redesenhar();
            });

            redesenhar();
        })();
    </script>

<?php endif; ?>

<!-- jQuery + Select2 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
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

        ['#cand1', '#cand2'].forEach(sel => {
            jQuery(sel).select2({
                language: 'pt-BR',
                placeholder: 'Buscar candidato...',
                allowClear: true,
                width: 'resolve',
                minimumInputLength: 0,
                matcher: matcherSemAcento
            });
        });
    })();
</script>

</body>
</html>
<!--  -->