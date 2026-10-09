<?php
// ------------------------------------------------------------
// Configuração do banco
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ============================================================
// DETECÇÃO DE ESTRUTURAS
// ============================================================

$colunasVotos = [];
foreach ($pdo->query("SHOW COLUMNS FROM votos") as $c) $colunasVotos[] = $c['Field'];

$candidatosVotos = [
    'votos','quantidade','total','qtde','qtd','totalVotos','total_votos',
    'num_votos','votosValidos','numVotos','votos_validos','comparecimento',
    'votosCandidato','qtdVotos',
];
$COL_VOTOS = null;
foreach ($candidatosVotos as $cand) {
    if (in_array($cand, $colunasVotos, true)) { $COL_VOTOS = $cand; break; }
}
if ($COL_VOTOS === null) {
    foreach ($pdo->query("SHOW COLUMNS FROM votos") as $c) {
        $field = $c['Field'];
        $type  = strtolower($c['Type']);
        if (in_array($field, ['numeroZona','numeroSecao','id'], true)) continue;
        if (str_contains($type,'int')||str_contains($type,'decimal')
            ||str_contains($type,'float')||str_contains($type,'double')) {
            $COL_VOTOS = $field; break;
        }
    }
}
if ($COL_VOTOS === null) die("Coluna de votos não detectada.");

$colunasCoord = [];
foreach ($pdo->query("SHOW COLUMNS FROM coordenadores") as $c) $colunasCoord[] = $c['Field'];
$temCandidatoCoord = in_array('nomeCandidatoResp', $colunasCoord, true);

$temLiderSecao = false;
try { $pdo->query("SELECT 1 FROM lider_secao LIMIT 1"); $temLiderSecao = true; }
catch (Exception $e) { $temLiderSecao = false; }

// ============================================================
// AJAX
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_GET['ajax'] === 'liderancas') {
            $coordId = (int)($_GET['coordenador_id'] ?? 0);
            if ($coordId <= 0) { echo json_encode([]); exit; }
            $stmt = $pdo->prepare(
                "SELECT id, nome, ativo FROM liderancas
                 WHERE coordenador_id = :c ORDER BY ativo DESC, nome"
            );
            $stmt->execute([':c' => $coordId]);
            echo json_encode($stmt->fetchAll());
            exit;
        }

        if ($_GET['ajax'] === 'coordenadores_candidato') {
            $cand = trim($_GET['candidato'] ?? '');
            if (!$temCandidatoCoord) {
                $stmt = $pdo->query(
                    "SELECT id, nome, ativo FROM coordenadores ORDER BY ativo DESC, nome"
                );
            } elseif ($cand !== '') {
                $stmt = $pdo->prepare(
                    "SELECT id, nome, ativo FROM coordenadores
                      WHERE nomeCandidatoResp = :c
                      ORDER BY ativo DESC, nome"
                );
                $stmt->execute([':c' => $cand]);
            } else {
                $stmt = $pdo->query(
                    "SELECT id, nome, ativo FROM coordenadores ORDER BY ativo DESC, nome"
                );
            }
            echo json_encode($stmt->fetchAll());
            exit;
        }

        throw new Exception('Ação desconhecida.');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        exit;
    }
}

// ============================================================
// FILTROS
// ============================================================
$coordId     = (int)($_GET['coordenador_id'] ?? 0);
$liderId     = (int)($_GET['lider_id']       ?? 0);
$candidato   = trim($_GET['candidato']       ?? '');
$cargo       = trim($_GET['cargo']           ?? '');
$raio        = isset($_GET['raio']) ? (int)$_GET['raio']    : 24;
$opacidade   = isset($_GET['opac']) ? (float)$_GET['opac']  : 0.7;

if ($raio < 5)  $raio = 5;
if ($raio > 80) $raio = 80;
if ($opacidade < 0.1) $opacidade = 0.1;
if ($opacidade > 1)   $opacidade = 1;

// ------------------------------------------------------------
// MAPA SÓ APARECE SE UM FILTRO DA EQUIPE ESTIVER ATIVO
// (cargo sozinho não conta — não filtra a equipe)
// ------------------------------------------------------------
$mostrarMapa = ($candidato !== '' || $coordId > 0 || $liderId > 0);
$temFiltro   = $mostrarMapa || $cargo !== '';

// ============================================================
// VOTOS POR (ZONA, SEÇÃO)
// ============================================================
$filtrosVotos = [];
$paramsVotos  = [];
if ($candidato !== '') {
    $filtrosVotos[] = "nomeCandidatoResp = :cand";
    $paramsVotos[':cand'] = $candidato;
}
if ($cargo !== '') {
    $filtrosVotos[] = "nomeCargoPerg = :cargo";
    $paramsVotos[':cargo'] = $cargo;
}
$whereVotos = $filtrosVotos ? 'WHERE ' . implode(' AND ', $filtrosVotos) : '';

$votosPorSecao = [];
$stmt = $pdo->prepare(
    "SELECT numeroZona, numeroSecao, SUM(qtdVotos) AS total
     FROM votos
     $whereVotos
     GROUP BY numeroZona, numeroSecao"
);
$stmt->execute($paramsVotos);
foreach ($stmt->fetchAll() as $r) {
    $votosPorSecao[$r['numeroZona'] . '|' . $r['numeroSecao']] = (int)$r['total'];
}

// ============================================================
// COORDENADORES
// ============================================================
$colCand = $temCandidatoCoord ? ", nomeCandidatoResp" : "";
$whereCoords = [];
$paramsCoords = [];
if ($temCandidatoCoord && $candidato !== '') {
    $whereCoords[] = "nomeCandidatoResp = :cand";
    $paramsCoords[':cand'] = $candidato;
}
$whereCoordsStr = $whereCoords ? 'WHERE ' . implode(' AND ', $whereCoords) : '';

$stmt = $pdo->prepare(
    "SELECT id, nome, ativo$colCand
       FROM coordenadores
       $whereCoordsStr
       ORDER BY ativo DESC, nome"
);
$stmt->execute($paramsCoords);
$coordenadores = $stmt->fetchAll();

$coordenadoresPermitidos = [];
foreach ($coordenadores as $c) $coordenadoresPermitidos[(int)$c['id']] = true;

$coordCandidato = [];
foreach ($coordenadores as $c) {
    $coordCandidato[(int)$c['id']] = $c['nomeCandidatoResp'] ?? '';
}

// ============================================================
// LIDERANÇAS
// ============================================================
$liderancasTodas = [];
if ($coordenadoresPermitidos) {
    $stmt = $pdo->query(
        "SELECT id, coordenador_id, nome, ativo FROM liderancas
         ORDER BY ativo DESC, nome"
    );
    foreach ($stmt->fetchAll() as $r) {
        $cid = (int)$r['coordenador_id'];
        if (!isset($coordenadoresPermitidos[$cid])) continue;
        $liderancasTodas[] = [
            'id'             => (int)$r['id'],
            'coordenador_id' => $cid,
            'nome'           => $r['nome'],
            'ativo'          => (int)$r['ativo'],
        ];
    }
}

// ============================================================
// SEÇÕES DAS LIDERANÇAS
// ============================================================
$linhasEquipe = [];
if ($temLiderSecao && $coordenadoresPermitidos && $mostrarMapa) {
    $sql = "
        SELECT
            ls.lider_id,
            l.coordenador_id,
            c.nome   AS coordenador_nome,
            l.nome   AS lider_nome,
            ls.numeroZona,
            ls.numeroSecao,
            s.nomeMunicipio,
            s.nomeLocal,
            s.latitude,
            s.longitude
        FROM lider_secao ls
        INNER JOIN liderancas l   ON l.id = ls.lider_id
        INNER JOIN coordenadores c ON c.id = l.coordenador_id
        LEFT JOIN secoes_enderecos s
               ON s.numeroZona  = ls.numeroZona
              AND s.numeroSecao = ls.numeroSecao
    ";
    $filtrosEquipe = [];
    $paramsEquipe  = [];
    if ($coordId > 0) {
        $filtrosEquipe[] = "l.coordenador_id = :cid";
        $paramsEquipe[':cid'] = $coordId;
    }
    if ($liderId > 0) {
        $filtrosEquipe[] = "ls.lider_id = :lid";
        $paramsEquipe[':lid'] = $liderId;
    }
    if ($temCandidatoCoord && $candidato !== '') {
        $filtrosEquipe[] = "c.nomeCandidatoResp = :ecand";
        $paramsEquipe[':ecand'] = $candidato;
    }
    if ($filtrosEquipe) $sql .= ' WHERE ' . implode(' AND ', $filtrosEquipe);
    $sql .= ' ORDER BY c.nome, l.nome, ls.numeroZona, ls.numeroSecao';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($paramsEquipe);
    $linhasEquipe = $stmt->fetchAll();
}

// ============================================================
// GRUPOS + PONTOS POR ZONA
// ============================================================
$grupos          = [];
$pontosPorZona   = [];
$maxVotosGrupo   = 0;
$secoesSemCoord  = 0;
$totalVotosMapa  = 0;
$totalSecoesMapa = 0;

foreach ($linhasEquipe as $row) {
    $lat = (float)$row['latitude'];
    $lng = (float)$row['longitude'];
    $temCoord = !empty($row['latitude']) && !empty($row['longitude'])
             && !($lat === 0.0 && $lng === 0.0);
    if (!$temCoord) { $secoesSemCoord++; continue; }

    $chave = $row['numeroZona'] . '|' . $row['numeroSecao'];
    $votos = $votosPorSecao[$chave] ?? 0;
    $zona  = (int)$row['numeroZona'];

    if ($liderId > 0 || $coordId > 0) {
        $grupoId   = 'L' . $row['lider_id'];
        $grupoNome = $row['lider_nome'];
        $grupoTipo = 'lideranca';
    } else {
        $grupoId   = 'C' . $row['coordenador_id'];
        $grupoNome = $row['coordenador_nome'];
        $grupoTipo = 'coordenador';
    }

    $candDoGrupo = $coordCandidato[(int)$row['coordenador_id']] ?? '';

    if (!isset($grupos[$grupoId])) {
        $grupos[$grupoId] = [
            'id'       => $grupoId,
            'nome'     => $grupoNome,
            'tipo'     => $grupoTipo,
            'candidato'=> $candDoGrupo,
            'pontos'   => [],
        ];
    }

    $ponto = [
        'lat'       => $lat,
        'lng'       => $lng,
        'votos'     => $votos,
        'zona'      => $zona,
        'secao'     => (int)$row['numeroSecao'],
        'municipio' => $row['nomeMunicipio'] ?? '',
        'local'     => $row['nomeLocal']     ?? '',
        'coord'     => $row['coordenador_nome'],
        'lider'     => $row['lider_nome'],
        'candidato' => $candDoGrupo,
    ];
    $grupos[$grupoId]['pontos'][] = $ponto;

    if (!isset($pontosPorZona[$zona])) {
        $pontosPorZona[$zona] = ['pontos' => [], 'votos' => 0, 'secoes' => 0];
    }
    $pontosPorZona[$zona]['pontos'][] = ['lat' => $lat, 'lng' => $lng];
    $pontosPorZona[$zona]['votos']    += $votos;
    $pontosPorZona[$zona]['secoes']++;

    $totalVotosMapa  += $votos;
    $totalSecoesMapa++;
    if ($votos > $maxVotosGrupo) $maxVotosGrupo = $votos;
}

$grupos = array_values(array_filter($grupos, fn($g) => count($g['pontos']) > 0));

// ============================================================
// ZONAS (polígono)
// ============================================================
$zonasParaPoligono = [];
foreach ($pontosPorZona as $zonaNum => $info) {
    $zonasParaPoligono[] = [
        'zona'   => (int)$zonaNum,
        'pontos' => $info['pontos'],
        'secoes' => $info['secoes'],
        'votos'  => $info['votos'],
        'valida' => count($info['pontos']) >= 3,
    ];
}
usort($zonasParaPoligono, fn($a, $b) => $a['zona'] <=> $b['zona']);

// ============================================================
// PALETAS
// ============================================================
$paletas = [
    ['base' => '#e6194B', 'leve' => '#ffe0e6', 'medio' => '#ff8a9a', 'forte' => '#8a0022'],
    ['base' => '#3cb44b', 'leve' => '#e0f7e3', 'medio' => '#8be096', 'forte' => '#1e6e2a'],
    ['base' => '#4363d8', 'leve' => '#e0e7ff', 'medio' => '#8fa3f0', 'forte' => '#1a2f8a'],
    ['base' => '#f58231', 'leve' => '#fff0e0', 'medio' => '#fcc088', 'forte' => '#a04a00'],
    ['base' => '#911eb4', 'leve' => '#f2e0f7', 'medio' => '#c98cd9', 'forte' => '#4a0f5c'],
    ['base' => '#42d4f4', 'leve' => '#e0faff', 'medio' => '#8ae5f7', 'forte' => '#0a7d94'],
    ['base' => '#f032e6', 'leve' => '#ffe0fd', 'medio' => '#f78eef', 'forte' => '#8a0f7d'],
    ['base' => '#bfef45', 'leve' => '#f5ffd9', 'medio' => '#dcf78a', 'forte' => '#5a7a00'],
    ['base' => '#9A6324', 'leve' => '#f7ecdf', 'medio' => '#c5a075', 'forte' => '#4a2f10'],
    ['base' => '#800000', 'leve' => '#f7dddd', 'medio' => '#c98888', 'forte' => '#3d0000'],
    ['base' => '#000075', 'leve' => '#ddddff', 'medio' => '#8888bb', 'forte' => '#00004a'],
    ['base' => '#00aaff', 'leve' => '#d9f2ff', 'medio' => '#88d4ff', 'forte' => '#005588'],
];
foreach ($grupos as $i => &$g) { $g['cor'] = $paletas[$i % count($paletas)]; }
unset($g);

// ============================================================
// LISTAS PARA SELECTS
// ============================================================
$candidatos = $pdo->query(
    "SELECT DISTINCT nomeCandidatoResp FROM votos ORDER BY nomeCandidatoResp"
)->fetchAll(PDO::FETCH_COLUMN);

$cargos = $pdo->query(
    "SELECT DISTINCT nomeCargoPerg FROM votos ORDER BY nomeCargoPerg"
)->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Mapa de Calor — Coordenadores e Lideranças</title>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom: 4px; }
    .subtitulo { color:#666; font-size:13px; margin-bottom:18px; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:14px 22px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:180px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:26px; font-weight:bold; color:#0069d9; margin-top:2px; }
    .kpi .sub    { font-size:11px; color:#888; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi.violeta { border-left-color:#4a1d96; } .kpi.violeta .valor { color:#4a1d96; }

    /* Filtros */
    .filtros { background:#fff; padding:14px 16px; border-radius:8px;
               box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
               display:flex; flex-wrap:wrap; align-items:center; gap:10px; }
    .filtros label { font-weight:bold; font-size:13px; }
    .filtros select, .filtros button {
        padding:7px 10px; border-radius:4px; border:1px solid #ccc; font-size:14px;
        font-family: inherit;
    }
    .btn-consultar { background:#0069d9; color:#fff; border:none; cursor:pointer; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none; padding:7px 12px;
                  border-radius:4px; font-size:14px; }
    .btn-limpar:hover { background:#5a6268; }

    .select2-container { margin: 0; }
    .select2-container--default .select2-selection--single {
        height: 36px; padding: 3px 6px; border: 1px solid #ccc; border-radius: 4px; background:#fff;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 28px; color:#333;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 34px; }
    .select2-container .select2-selection--single { height: 36px; }
    .select2-search--dropdown .select2-search__field { padding: 6px; font-size:14px; }
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #0069d9;
    }
    #candidato + .select2-container   { min-width: 260px; max-width: 360px; }
    #coordenador + .select2-container { min-width: 240px; max-width: 320px; }
    #lideranca + .select2-container   { min-width: 220px; max-width: 300px; }
    #cargo + .select2-container       { min-width: 150px; }

    /* ------------------------------------------------------------
       Mensagem: sem filtro
       ------------------------------------------------------------ */
    .msg-sem-filtro {
        background:#fff; border-left:5px solid #0069d9;
        padding:32px 36px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.08);
        text-align:center; margin-bottom: 24px;
    }
    .msg-sem-filtro .icone { font-size:52px; line-height:1; margin-bottom:14px; }
    .msg-sem-filtro h2 { margin:0 0 10px; font-size:20px; color:#0069d9; }
    .msg-sem-filtro p { margin:0 0 6px; font-size:14px; color:#666; line-height:1.6; }
    .msg-sem-filtro .passos {
        margin: 18px auto 0; max-width: 520px; text-align: left;
        background:#f4f8ff; border-radius:8px; padding:14px 20px;
        font-size:13.5px; color:#333; line-height:1.8;
    }
    .msg-sem-filtro .passos b { color:#0069d9; }
    .msg-sem-filtro .dica {
        margin-top:16px; font-size:13px;
        background:#e7f3ff; color:#004085;
        padding:8px 14px; border-radius:6px; display:inline-block;
    }

    #mapa { height: 640px; width: 100%; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.15); background:#e5e5e5;
            margin-top: 10px; }

    .controles {
        background:#fff; padding:10px 14px; border-radius:6px;
        box-shadow: 0 1px 2px rgba(0,0,0,.08); font-size:13px;
        display:flex; flex-wrap:wrap; align-items:center; gap:14px;
        margin-bottom:10px;
    }
    .controles label { font-weight:bold; font-size:12px; }
    .controles input[type=range] { width: 130px; vertical-align:middle; }
    .controles .valor { font-family:monospace; color:#0069d9; font-weight:bold;
                        min-width: 34px; display:inline-block; }
    .controles .sep { border-left:1px solid #eee; padding-left:14px; }

    .legenda-grupos {
        background:#fff; padding:10px 14px; border-radius:6px;
        box-shadow: 0 1px 2px rgba(0,0,0,.08);
        display:flex; flex-wrap:wrap; gap:14px;
        font-size:13px; margin-bottom:10px;
    }
    .legenda-grupos .item { display:flex; align-items:center; gap:8px; }
    .legenda-grupos .cor {
        width:16px; height:16px; border-radius:4px; display:inline-block;
        border:1px solid rgba(0,0,0,.15);
    }
    .legenda-grupos .nome { font-weight:600; }
    .legenda-grupos .qtd { font-size:11px; color:#888; }
    .legenda-grupos .cand {
        font-size:10.5px; background:#e7d9ff; color:#4a1d96;
        padding:1px 6px; border-radius:8px; font-weight:bold;
    }

    .badges-filtros { margin-bottom:14px; }
    .badge-filtro {
        background:#e6f0fa; color:#0069d9; padding:4px 12px;
        border-radius:12px; font-size:12px; font-weight:bold;
        display:inline-flex; align-items:center; gap:6px;
        margin-right:6px; margin-bottom:6px;
    }
    .badge-filtro .x {
        cursor:pointer; color:inherit; text-decoration:none;
        font-size:14px; line-height:1; font-weight:bold;
    }
    .badge-filtro .x:hover { color:#dc3545; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso code { background:#fff; padding:1px 5px; border-radius:3px;
                  font-size:12px; border:1px solid #eee; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    .leaflet-popup-content { font-family: Arial, sans-serif; font-size: 13px; }
    .leaflet-popup-content b { color:#0069d9; }

    .leaflet-tooltip.tip-equipe {
        background: rgba(255,255,255,.97);
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 12px;
        padding: 4px 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.15);
    }
    .leaflet-tooltip.tip-equipe .zona  { color:#0069d9; font-weight:bold; }
    .leaflet-tooltip.tip-equipe .secao { color:#6f42c1; font-weight:bold; }

    .zona-label {
        background: transparent !important;
        border: none !important;
        pointer-events: none;
    }
    .zona-label .caixa {
        background: rgba(255,255,255,.94);
        border: 2px solid #003a7a;
        border-radius: 6px;
        padding: 3px 8px;
        font-family: Arial, sans-serif;
        font-size: 12px;
        font-weight: bold;
        color: #003a7a;
        text-align: center;
        box-shadow: 0 2px 6px rgba(0,0,0,.25);
        white-space: nowrap;
        line-height: 1.2;
    }
    .zona-label .caixa .sub {
        display:block; font-weight: normal; font-size: 10px; color:#555;
    }

    .info-detect {
        font-size:11.5px; color:#666; margin-bottom:14px;
        background:#fff; padding:6px 12px; border-radius:6px;
        border-left:4px solid #17a2b8;
    }
    .info-detect code { background:#f4f4f4; padding:1px 5px; border-radius:3px; }
    .info-detect .aviso-inline {
        color:#856404; background:#fff3cd;
        padding:2px 8px; border-radius:6px; font-weight:bold; margin-left:6px;
    }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Mapa de Calor — Coordenadores e Lideranças</h1>
<div class="subtitulo">
    Cada seção é um ponto colorido pela cor do grupo responsável.
    O <strong>polígono azul</strong> marca a <strong>área geográfica da zona eleitoral</strong>,
    traçada a partir das localizações dos locais de votação dela.
</div>

<p class="info-detect">
    Coluna de votos: <strong><?= htmlspecialchars($COL_VOTOS) ?></strong>
    &nbsp;·&nbsp;
    Coluna de candidato em <code>coordenadores</code>:
    <?php if ($temCandidatoCoord): ?><strong>nomeCandidatoResp</strong>
    <?php else: ?><span class="aviso-inline">NÃO EXISTE — filtro de candidato não restringe a equipe</span><?php endif; ?>
    &nbsp;·&nbsp;
    Tabela <code>lider_secao</code>:
    <?php if ($temLiderSecao): ?><strong>existe</strong>
    <?php else: ?><span class="aviso-inline">não existe</span><?php endif; ?>
</p>

<form method="get" action="mapa_calor_equipe.php" id="form-filtros" class="filtros">

    <label for="candidato">Candidato:</label>
    <select name="candidato" id="candidato">
        <option value="">Todos</option>
        <?php foreach ($candidatos as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"
                <?= $c === $candidato ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="coordenador">Coordenador:</label>
    <select name="coordenador_id" id="coordenador">
        <option value="">Todos</option>
        <?php foreach ($coordenadores as $c): ?>
            <option value="<?= (int)$c['id'] ?>"
                <?= (int)$c['id'] === $coordId ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['nome']) ?>
                <?= (int)$c['ativo'] === 0 ? ' (inativo)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="lideranca">Liderança:</label>
    <select name="lider_id" id="lideranca">
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

    <button type="submit" class="btn-consultar">Aplicar</button>
    <a href="mapa_calor_equipe.php" class="btn-limpar">Limpar</a>
</form>

<?php if ($temFiltro): ?>
<div class="badges-filtros">
    <span style="font-size:12px; color:#666; margin-right:8px;">Filtros ativos:</span>

    <?php if ($candidato !== ''): ?>
        <span class="badge-filtro">
            🗳 <?= htmlspecialchars($candidato) ?>
            <a class="x" href="?<?= http_build_query(['candidato' => '', 'coordenador_id' => $coordId, 'lider_id' => $liderId, 'cargo' => $cargo]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($coordId > 0):
        $nomeC = '';
        foreach ($coordenadores as $c) {
            if ((int)$c['id'] === $coordId) { $nomeC = $c['nome']; break; }
        }
    ?>
        <span class="badge-filtro">
            👔 <?= htmlspecialchars($nomeC) ?>
            <a class="x" href="?<?= http_build_query(['candidato' => $candidato, 'coordenador_id' => 0, 'lider_id' => $liderId, 'cargo' => $cargo]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($liderId > 0):
        $nomeL = '';
        foreach ($liderancasTodas as $l) {
            if ((int)$l['id'] === $liderId) { $nomeL = $l['nome']; break; }
        }
    ?>
        <span class="badge-filtro">
            👤 <?= htmlspecialchars($nomeL) ?>
            <a class="x" href="?<?= http_build_query(['candidato' => $candidato, 'coordenador_id' => $coordId, 'lider_id' => 0, 'cargo' => $cargo]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($cargo !== ''): ?>
        <span class="badge-filtro">
            💼 <?= htmlspecialchars($cargo) ?>
            <a class="x" href="?<?= http_build_query(['candidato' => $candidato, 'coordenador_id' => $coordId, 'lider_id' => $liderId, 'cargo' => '']) ?>">×</a>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$mostrarMapa): ?>

    <div class="msg-sem-filtro">
        <div class="icone">🗺️</div>
        <h2>Selecione um filtro para visualizar o mapa</h2>
        <p>
            Para evitar carregamento desnecessário, o mapa só é exibido
            quando ao menos um dos filtros da equipe está definido.
        </p>

        <div class="passos">
            <b>Escolha uma das opções:</b><br>
            • 🗳 Um <b>Candidato</b> — mostra o time inteiro dele<br>
            • 👔 Um <b>Coordenador</b> — mostra só as seções dele<br>
            • 👤 Uma <b>Liderança</b> — mostra só as seções dela<br>
            <br>
            Depois clique em <b>Aplicar</b>.
        </div>

        <div class="dica">
            💡 Os filtros de <b>Cargo</b> sozinho não carregam o mapa —
            ele apenas refina a contagem de votos quando combinado com
            candidato, coordenador ou liderança.
        </div>
    </div>

<?php elseif (!$grupos): ?>

    <div class="aviso">
        Nenhuma seção com coordenada encontrada para os filtros aplicados.
        <?php if (!$temLiderSecao): ?>
            <br><strong>Atenção:</strong> a tabela <code>lider_secao</code> não existe no banco.
        <?php else: ?>
            Verifique se <code>lider_secao</code> e <code>secoes_enderecos</code>
            estão preenchidas.
        <?php endif; ?>
        <?php if ($candidato !== ''): ?>
            <br><strong>Filtro ativo:</strong> <?= htmlspecialchars($candidato) ?> —
            apenas coordenadores deste candidato foram considerados.
        <?php endif; ?>
        <?php if ($secoesSemCoord > 0): ?>
            <br><strong><?= $secoesSemCoord ?></strong> seção(ões) foram
            ignoradas por não terem coordenada.
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="kpis">
        <div class="kpi violeta">
            <div class="rotulo">Candidato</div>
            <div class="valor" style="font-size:16px; line-height:1.3; word-break:break-word;">
                <?= $candidato !== '' ? htmlspecialchars($candidato) : '— Todos —' ?>
            </div>
        </div>

        <div class="kpi">
            <div class="rotulo">
                <?= $liderId > 0 ? 'Liderança'
                    : ($coordId > 0 ? 'Coordenador' : 'Grupos no mapa') ?>
            </div>
            <div class="valor" style="font-size:18px;">
                <?php
                if ($liderId > 0) {
                    echo htmlspecialchars($grupos[0]['nome']);
                } elseif ($coordId > 0) {
                    foreach ($coordenadores as $c) {
                        if ((int)$c['id'] === $coordId) {
                            echo htmlspecialchars($c['nome']);
                            break;
                        }
                    }
                } else {
                    echo count($grupos) . ' grupo(s)';
                }
                ?>
            </div>
        </div>

        <div class="kpi verde">
            <div class="rotulo">Total de votos no mapa</div>
            <div class="valor"><?= number_format($totalVotosMapa, 0, ',', '.') ?></div>
        </div>

        <div class="kpi cinza">
            <div class="rotulo">Seções no mapa</div>
            <div class="valor"><?= number_format($totalSecoesMapa, 0, ',', '.') ?></div>
            <?php if ($secoesSemCoord > 0): ?>
                <div class="sub"><?= $secoesSemCoord ?> sem coord. (ignoradas)</div>
            <?php endif; ?>
        </div>

        <div class="kpi roxo">
            <div class="rotulo">Zonas (polígonos)</div>
            <div class="valor"><?= number_format(count($zonasParaPoligono), 0, ',', '.') ?></div>
        </div>

        <div class="kpi laranja">
            <div class="rotulo">Maior seção</div>
            <div class="valor"><?= number_format($maxVotosGrupo, 0, ',', '.') ?></div>
        </div>
    </div>

    <div class="controles">
        <label for="ctrlRaio">Intensidade:</label>
        <input type="range" id="ctrlRaio" min="5" max="80" step="1" value="<?= (int)$raio ?>">
        <span class="valor" id="ctrlRaioVal"><?= (int)$raio ?></span>

        <label for="ctrlOpac">Opacidade:</label>
        <input type="range" id="ctrlOpac" min="0.1" max="1" step="0.05"
               value="<?= htmlspecialchars($opacidade) ?>">
        <span class="valor" id="ctrlOpacVal"><?= htmlspecialchars(number_format($opacidade, 2)) ?></span>

        <span class="sep">
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                <input type="checkbox" id="mostrarPontos"> mostrar seções
            </label>
        </span>

        <span>
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                <input type="checkbox" id="mostrarZonas" checked> polígonos das zonas
            </label>
        </span>

        <span>
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                <input type="checkbox" id="mostrarLabelsZona"> mostrar rótulos das zonas
            </label>
        </span>
    </div>

    <div class="legenda-grupos" id="legenda-grupos">
        <?php foreach ($grupos as $g): ?>
            <div class="item" data-grupo="<?= htmlspecialchars($g['id']) ?>">
                <span class="cor" style="background: <?= htmlspecialchars($g['cor']['base']) ?>;"></span>
                <span>
                    <span class="nome"><?= htmlspecialchars($g['nome']) ?></span>
                    <?php if ($g['candidato'] !== ''): ?>
                        <span class="cand"><?= htmlspecialchars($g['candidato']) ?></span>
                    <?php endif; ?>
                    <span class="qtd">
                        (<?= count($g['pontos']) ?> seç. ·
                        <?= number_format(array_sum(array_column($g['pontos'], 'votos')), 0, ',', '.') ?> votos)
                    </span>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="mapa"></div>

    <script>
        const GRUPOS  = <?= json_encode($grupos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const ZONAS   = <?= json_encode($zonasParaPoligono, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const MAX_VOTOS    = <?= (int)$maxVotosGrupo ?>;
        const RAIO_INICIAL = <?= (int)$raio ?>;
        const OPAC_INICIAL = <?= json_encode((float)$opacidade) ?>;

        (function () {
            function formatNum(n) { return Number(n).toLocaleString('pt-BR'); }
            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, c => ({
                    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
                })[c]);
            }

            function cross(O, A, B) {
                return (A.lat - O.lat) * (B.lng - O.lng) - (A.lng - O.lng) * (B.lat - O.lat);
            }
            function convexHull(points) {
                if (points.length < 3) return points.slice();
                const pts = points.slice().sort((a, b) =>
                    a.lat - b.lat || a.lng - b.lng
                );
                const lower = [];
                for (const p of pts) {
                    while (lower.length >= 2 &&
                           cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) {
                        lower.pop();
                    }
                    lower.push(p);
                }
                const upper = [];
                for (let i = pts.length - 1; i >= 0; i--) {
                    const p = pts[i];
                    while (upper.length >= 2 &&
                           cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) {
                        upper.pop();
                    }
                    upper.push(p);
                }
                lower.pop(); upper.pop();
                return lower.concat(upper);
            }
            function centroide(points) {
                let lat = 0, lng = 0;
                for (const p of points) { lat += p.lat; lng += p.lng; }
                return { lat: lat / points.length, lng: lng / points.length };
            }

            const mapa = L.map('mapa', { scrollWheelZoom: true })
                           .setView([-8.05, -34.9], 8);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(mapa);

            if (!GRUPOS.length) return;

            GRUPOS.forEach(g => {
                const gruposCoord = {};
                g.pontos.forEach(p => {
                    const key = p.lat.toFixed(5) + ',' + p.lng.toFixed(5);
                    (gruposCoord[key] = gruposCoord[key] || []).push(p);
                });
                Object.values(gruposCoord).forEach(arr => {
                    if (arr.length < 2) return;
                    const r = 0.0004 + 0.00015 * Math.min(arr.length, 12);
                    arr.forEach((p, i) => {
                        const ang = (2 * Math.PI * i) / arr.length;
                        p.lat += r * Math.cos(ang);
                        p.lng += r * Math.sin(ang);
                    });
                });
            });

            const bounds = L.latLngBounds([]);

            const camadaZonas  = L.layerGroup().addTo(mapa);
            const camadaLabels = L.layerGroup();

            ZONAS.forEach(z => {
                z.pontos.forEach(p => bounds.extend([p.lat, p.lng]));
                if (!z.valida) return;

                const hull = convexHull(z.pontos);
                const latlngs = hull.map(p => [p.lat, p.lng]);

                const poligono = L.polygon(latlngs, {
                    color: '#003a7a', weight: 2.5, opacity: 0.9,
                    fill: true, fillColor: '#4d94ff', fillOpacity: 0.12,
                    smoothFactor: 1, interactive: true,
                }).addTo(camadaZonas);

                poligono.bindPopup(`
                    <div style="min-width:200px;">
                        <div style="font-weight:bold;color:#003a7a;font-size:14px;">
                            Área da Zona ${z.zona}
                        </div>
                        <div style="font-size:11px;color:#888;margin-top:2px;">
                            polígono traçado pelos locais de votação
                        </div>
                        <hr style="border:none;border-top:1px solid #eee;margin:6px 0;">
                        <div><b>${formatNum(z.secoes)}</b> seções com coordenada</div>
                        <div style="font-size:12px;color:#555;">
                            ${formatNum(z.votos)} votos
                        </div>
                    </div>
                `);
                poligono.bindTooltip(
                    'Zona ' + z.zona + ' — ' + formatNum(z.secoes) + ' seç.',
                    { sticky: true }
                );

                const c = centroide(hull);
                const icon = L.divIcon({
                    className: 'zona-label',
                    html: `<div class="caixa">
                              Zona ${z.zona}
                              <span class="sub">${formatNum(z.votos)} votos</span>
                           </div>`,
                    iconSize: [110, 40],
                    iconAnchor: [55, 20],
                });
                L.marker([c.lat, c.lng], {
                    icon, interactive: false, keyboard: false,
                }).addTo(camadaLabels);
            });

            if (bounds.isValid()) {
                mapa.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 });
            }

            const heatLayers = [];
            GRUPOS.forEach(g => {
                const dados = g.pontos.map(p => {
                    const peso = MAX_VOTOS > 0 ? (p.votos / MAX_VOTOS) : 0;
                    return [p.lat, p.lng, peso];
                });

                const gradiente = {
                    0.0: g.cor.leve,
                    0.4: g.cor.medio,
                    0.75: g.cor.base,
                    1.0: g.cor.forte,
                };

                const layer = L.heatLayer(dados, {
                    radius: RAIO_INICIAL,
                    blur: Math.round(RAIO_INICIAL * 0.8),
                    maxZoom: 17,
                    minOpacity: 0.3,
                    opacity: OPAC_INICIAL,
                    gradient: gradiente,
                }).addTo(mapa);

                heatLayers.push(layer);
            });

            const marcadores = [];
            GRUPOS.forEach(g => {
                g.pontos.forEach(p => {
                    const raioPx = 4 + 8 * Math.sqrt(
                        MAX_VOTOS > 0 ? (p.votos / MAX_VOTOS) : 0
                    );

                    const html = `
                        <div style="min-width:230px;">
                            <div><b>Zona ${p.zona}</b> · <b>Seção ${p.secao}</b></div>
                            ${p.local ? '<div style="font-size:12px;color:#555;margin-top:3px;">' +
                                         escapeHtml(p.local) + '</div>' : ''}
                            ${p.municipio ? '<div style="font-size:12px;color:#888;">' +
                                             escapeHtml(p.municipio) + '</div>' : ''}
                            <hr style="border:none;border-top:1px solid #eee;margin:6px 0;">
                            ${p.candidato ? '<div style="font-size:12px;margin-bottom:3px;">' +
                                             '<span style="color:#4a1d96;">🗳</span> ' +
                                             '<b style="color:#4a1d96;">' + escapeHtml(p.candidato) + '</b></div>' : ''}
                            <div style="font-size:12px;">
                                <span style="color:#0069d9;">👤</span>
                                <b>${escapeHtml(p.coord)}</b>
                            </div>
                            <div style="font-size:12px;">
                                <span style="color:#6f42c1;">🤝</span>
                                <b>${escapeHtml(p.lider)}</b>
                            </div>
                            <div style="margin-top:6px;">
                                <b>${formatNum(p.votos)}</b> votos
                            </div>
                        </div>`;

                    const marker = L.circleMarker([p.lat, p.lng], {
                        radius: raioPx,
                        color: g.cor.forte,
                        weight: 1,
                        fillColor: g.cor.base,
                        fillOpacity: 0.35,
                        opacity: 0,
                    }).addTo(mapa);

                    marker.bindPopup(html);
                    marker.bindTooltip(
                        'Zona <span class="zona">' + p.zona + '</span>' +
                        ' · Seção <span class="secao">' + p.secao + '</span>' +
                        ' — <span class="votos">' + formatNum(p.votos) + ' votos</span>',
                        { sticky: true, className: 'tip-equipe', direction: 'top', offset: [0, -4] }
                    );
                    marcadores.push(marker);
                });
            });

            const rEl  = document.getElementById('ctrlRaio');
            const oEl  = document.getElementById('ctrlOpac');
            const rVal = document.getElementById('ctrlRaioVal');
            const oVal = document.getElementById('ctrlOpacVal');

            function aplicarOpcoes() {
                const r = parseInt(rEl.value, 10);
                const o = parseFloat(oEl.value);
                const b = Math.round(r * 0.8);
                heatLayers.forEach(layer => {
                    layer.setOptions({ radius: r, blur: b, opacity: o });
                });
            }
            rEl.addEventListener('input', () => {
                rVal.textContent = rEl.value;
                aplicarOpcoes();
            });
            oEl.addEventListener('input', () => {
                oVal.textContent = parseFloat(oEl.value).toFixed(2);
                aplicarOpcoes();
            });

            document.getElementById('mostrarPontos')
                .addEventListener('change', e => {
                    marcadores.forEach(m => {
                        m.setStyle({ opacity: e.target.checked ? 1 : 0 });
                    });
                });

            document.getElementById('mostrarZonas')
                .addEventListener('change', e => {
                    if (e.target.checked) mapa.addLayer(camadaZonas);
                    else                  mapa.removeLayer(camadaZonas);
                });

            document.getElementById('mostrarLabelsZona')
                .addEventListener('change', e => {
                    if (e.target.checked) mapa.addLayer(camadaLabels);
                    else                  mapa.removeLayer(camadaLabels);
                });

            document.querySelectorAll('.legenda-grupos .item').forEach(el => {
                el.style.cursor = 'pointer';
                el.title = 'Clique para enquadrar este grupo';
                el.addEventListener('click', () => {
                    const gid = el.dataset.grupo;
                    const g = GRUPOS.find(x => x.id === gid);
                    if (!g) return;
                    const b = L.latLngBounds(g.pontos.map(p => [p.lat, p.lng]));
                    if (b.isValid()) {
                        mapa.fitBounds(b, { padding: [60, 60], maxZoom: 14 });
                    }
                });
            });
        })();
    </script>

<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
    const LIDERANCAS_TODAS    = <?= json_encode($liderancasTodas, JSON_UNESCAPED_UNICODE) ?>;
    const COORD_SELECIONADO   = <?= (int)$coordId ?>;
    const LIDER_SELECIONADA   = <?= (int)$liderId ?>;
    const CAND_SELECIONADO    = <?= json_encode($candidato) ?>;

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

        const $coord = jQuery('#coordenador');
        const $lider = jQuery('#lideranca');
        const $cand  = jQuery('#candidato');
        const $cargo = jQuery('#cargo');

        function initSel($el, placeholder) {
            $el.select2({
                language: 'pt-BR',
                placeholder: placeholder,
                allowClear: true,
                width: 'resolve',
                minimumInputLength: 0,
                matcher: matcherSemAcento
            });
        }

        function popularLiderancas(coordId, liderSel) {
            const opcoes = (coordId > 0)
                ? LIDERANCAS_TODAS.filter(l => l.coordenador_id === coordId)
                : LIDERANCAS_TODAS;

            if ($lider.hasClass('select2-hidden-accessible')) $lider.select2('destroy');
            $lider.empty();
            $lider.append('<option value="">Todas</option>');
            opcoes.forEach(l => {
                const $opt = jQuery('<option>').val(l.id)
                    .text(l.nome + (l.ativo === 0 ? ' (inativa)' : ''));
                if (parseInt(l.id) === parseInt(liderSel)) $opt.prop('selected', true);
                $lider.append($opt);
            });
            initSel($lider, 'Buscar liderança...');
        }

        function popularCoordenadores(candidato, coordSel) {
            if ($coord.hasClass('select2-hidden-accessible')) $coord.select2('destroy');
            $coord.html('<option value="">Carregando...</option>');

            let url = 'mapa_calor_equipe.php?ajax=coordenadores_candidato';
            if (candidato) url += '&candidato=' + encodeURIComponent(candidato);

            fetch(url)
                .then(r => r.json())
                .then(lista => {
                    $coord.html('<option value="">Todos</option>');
                    (lista || []).forEach(c => {
                        const $opt = jQuery('<option>')
                            .val(c.id)
                            .text(c.nome + (parseInt(c.ativo) === 0 ? ' (inativo)' : ''));
                        if (parseInt(c.id) === parseInt(coordSel)) $opt.prop('selected', true);
                        $coord.append($opt);
                    });
                    initSel($coord, 'Buscar coordenador...');

                    if (coordSel && !$coord.find('option[value="' + coordSel + '"]').length) {
                        $coord.val('').trigger('change.select2');
                        popularLiderancas(0, 0);
                    } else {
                        popularLiderancas(coordSel || 0, LIDER_SELECIONADA);
                    }
                })
                .catch(() => {
                    $coord.html('<option value="">Erro ao carregar</option>');
                    initSel($coord, 'Buscar coordenador...');
                });
        }

        initSel($cand, 'Buscar candidato...');
        initSel($cargo, 'Buscar cargo...');
        initSel($coord, 'Buscar coordenador...');
        initSel($lider, 'Buscar liderança...');

        $cand.on('select2:select select2:clear', function () {
            const cand = $cand.val() || '';
            popularCoordenadores(cand, 0);
        });

        $coord.on('select2:select select2:clear', function () {
            popularLiderancas(parseInt($coord.val() || 0), 0);
        });

        [$cand, $coord, $lider, $cargo].forEach($el => {
            $el.on('select2:select', function () {
                document.getElementById('form-filtros').submit();
            });
            $el.on('select2:clear', function () {
                document.getElementById('form-filtros').submit();
            });
        });

        popularCoordenadores(CAND_SELECIONADO, COORD_SELECIONADO);
    })();
</script>

</body>
</html>