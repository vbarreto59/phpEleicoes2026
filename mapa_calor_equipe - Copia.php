<?php
// ------------------------------------------------------------
// mapa_calor_equipe.php
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';
// Define o fuso horário para o horário de Brasília
date_default_timezone_set('America/Sao_Paulo');

// ============================================================
// USUÁRIO LOGADO
// ============================================================
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

// ============================================================
// CONEXÃO PDO
// ============================================================
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

$temTipoVotavel   = in_array('cdTipoVotavel',  $colunasVotos, true);
$temCargoPergunta = in_array('cargoPergunta', $colunasVotos, true);

$colunasCoord = [];
foreach ($pdo->query("SHOW COLUMNS FROM coordenadores") as $c) $colunasCoord[] = $c['Field'];
$temNumeroCandCoord = in_array('numero_candidato', $colunasCoord, true);
$temAnoCampanha     = in_array('ano_campanha',     $colunasCoord, true);

$temLiderSecao = false;
$temCoordSecao = false;
try { $pdo->query("SELECT 1 FROM lider_secao LIMIT 1");       $temLiderSecao = true; } catch (Exception $e) {}
try { $pdo->query("SELECT 1 FROM coordenador_secao LIMIT 1"); $temCoordSecao = true; } catch (Exception $e) {}

// ============================================================
// HELPERS
// ============================================================
function fmtSecao(int $z, int $s): string {
    return '#Z' . $z . '-S' . $s;
}

// ============================================================
// AJAX
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        // ---------- LIDERANÇAS DE UM COORDENADOR ----------
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

        // ---------- COORDENADORES DE UM ANO+CANDIDATO ----------
        if ($_GET['ajax'] === 'coordenadores_listar') {
            $ano = (int)($_GET['ano'] ?? 0);
            $num = (int)($_GET['candidato'] ?? 0);

            $sql  = "SELECT c.id, c.nome, c.ativo, c.numero_candidato, c.ano_campanha
                       FROM coordenadores c WHERE 1=1";
            $pars = [];
            if ($ano > 0 && $temAnoCampanha)     { $sql .= " AND c.ano_campanha = :ano";     $pars[':ano'] = $ano; }
            if ($num > 0 && $temNumeroCandCoord) { $sql .= " AND c.numero_candidato = :num"; $pars[':num'] = $num; }
            $sql .= " ORDER BY c.ativo DESC, c.nome";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($pars);
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
$ano         = (int)($_GET['ano']       ?? 0);
$candidato   = (int)($_GET['candidato'] ?? 0);
$coordId     = (int)($_GET['coordenador_id'] ?? 0);
$liderId     = (int)($_GET['lider_id']       ?? 0);
$cargo       = trim($_GET['cargo']           ?? '');
$raio        = isset($_GET['raio']) ? (int)$_GET['raio']    : 24;
$opacidade   = isset($_GET['opac']) ? (float)$_GET['opac']  : 0.7;

if ($raio < 5)  $raio = 5;
if ($raio > 80) $raio = 80;
if ($opacidade < 0.1) $opacidade = 0.1;
if ($opacidade > 1)   $opacidade = 1;

// Mapa só aparece com ano+candidato definidos
$mostrarMapa = ($ano > 0 && $candidato > 0);
$temFiltro   = ($ano > 0 || $candidato > 0 || $coordId > 0 || $liderId > 0 || $cargo !== '');

// ============================================================
// LISTAS PARA OS SELECTS
// ============================================================
$listaAnos = $pdo->query(
    "SELECT DISTINCT ano FROM votos WHERE ano > 0 ORDER BY ano DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$listaCandidatos = [];
if ($ano > 0) {
    $stmt = $pdo->prepare(
        "SELECT numero, MIN(nomeCandidatoResp) AS nome, MIN(nomeCargoPerg) AS cargo
           FROM votos
          WHERE ano = :ano AND numero > 0 AND nomeCandidatoResp <> ''
          GROUP BY numero
          ORDER BY nome"
    );
    $stmt->execute([':ano' => $ano]);
    $listaCandidatos = $stmt->fetchAll();
}

$listaCargos = $pdo->query(
    "SELECT DISTINCT nomeCargoPerg FROM votos
      WHERE nomeCargoPerg IS NOT NULL AND nomeCargoPerg <> ''
      ORDER BY nomeCargoPerg"
)->fetchAll(PDO::FETCH_COLUMN);

// Coordenadores do ano/candidato (para o select inicial)
$coordenadores = [];
if ($mostrarMapa) {
    $sql  = "SELECT c.id, c.nome, c.ativo, c.numero_candidato, c.ano_campanha
               FROM coordenadores c
              WHERE 1=1";
    $pars = [];
    if ($temAnoCampanha)     { $sql .= " AND c.ano_campanha = :ano";     $pars[':ano'] = $ano; }
    if ($temNumeroCandCoord) { $sql .= " AND c.numero_candidato = :num"; $pars[':num'] = $candidato; }
    $sql .= " ORDER BY c.ativo DESC, c.nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($pars);
    $coordenadores = $stmt->fetchAll();
}
$coordenadoresPermitidos = [];
foreach ($coordenadores as $c) $coordenadoresPermitidos[(int)$c['id']] = true;

// Lideranças permitidas
$liderancasTodas = [];
if ($coordenadoresPermitidos) {
    $in = implode(',', array_map('intval', array_keys($coordenadoresPermitidos)));
    $stmt = $pdo->query(
        "SELECT id, coordenador_id, nome, ativo FROM liderancas
          WHERE coordenador_id IN ($in)
          ORDER BY ativo DESC, nome"
    );
    foreach ($stmt->fetchAll() as $r) {
        $liderancasTodas[] = [
            'id'             => (int)$r['id'],
            'coordenador_id' => (int)$r['coordenador_id'],
            'nome'           => $r['nome'],
            'ativo'          => (int)$r['ativo'],
        ];
    }
}

// ============================================================
// FILTRO PADRONIZADO DE VOTOS DO CANDIDATO
// ============================================================
function filtroCandVotosMapa(string $aliasV, string $aliasC, string $colVotos, bool $temTipo, bool $temCargo): string {
    $f  = " AND {$aliasV}.numero = {$aliasC}.numero_candidato ";
    $f .= " AND {$aliasV}.ano    = {$aliasC}.ano_campanha ";
    $f .= " AND {$aliasV}.nomeCandidatoResp <> '' ";
    if ($temTipo)  $f .= " AND {$aliasV}.cdTipoVotavel = 1 ";
    if ($temCargo) {
        $f .= " AND {$aliasV}.cargoPergunta = (
                    SELECT v2.cargoPergunta FROM votos v2
                     WHERE v2.numero = {$aliasC}.numero_candidato
                       AND v2.ano    = {$aliasC}.ano_campanha
                       AND v2.nomeCandidatoResp <> ''
                     GROUP BY v2.cargoPergunta
                     ORDER BY SUM(v2.`$colVotos`) DESC
                     LIMIT 1
                 ) ";
    }
    return $f;
}

// ============================================================
// VOTOS POR (ZONA, SEÇÃO) — já filtrado por candidato/ano
// ============================================================
$votosPorSecao = [];
if ($mostrarMapa) {
    $condCargoExtra = '';
    $paramsVotos = [':ano' => $ano, ':num' => $candidato];
    if ($cargo !== '') {
        $condCargoExtra = " AND v.nomeCargoPerg = :cargoExtra ";
        $paramsVotos[':cargoExtra'] = $cargo;
    }

    $filtroCand = filtroCandVotosMapa('v', 'c', $COL_VOTOS, $temTipoVotavel, $temCargoPergunta);

    $sql = "
        SELECT v.numeroZona, v.numeroSecao, SUM(v.`$COL_VOTOS`) AS total
          FROM votos v
          INNER JOIN coordenadores c
                  ON c.numero_candidato = :num
                 AND c.ano_campanha     = :ano
         WHERE 1=1
           $filtroCand
           $condCargoExtra
         GROUP BY v.numeroZona, v.numeroSecao
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($paramsVotos);
    foreach ($stmt->fetchAll() as $r) {
        $votosPorSecao[$r['numeroZona'] . '|' . $r['numeroSecao']] = (int)$r['total'];
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
            c.nome        AS coordenador_nome,
            c.numero_candidato,
            c.ano_campanha,
            l.nome        AS lider_nome,
            ls.numeroZona,
            ls.numeroSecao,
            s.nomeMunicipio,
            s.nomeLocal,
            s.latitude,
            s.longitude
        FROM lider_secao ls
        INNER JOIN liderancas l    ON l.id = ls.lider_id
        INNER JOIN coordenadores c ON c.id = l.coordenador_id
        LEFT JOIN secoes_enderecos s
               ON s.numeroZona  = ls.numeroZona
              AND s.numeroSecao = ls.numeroSecao
    ";
    $filtros = [];
    $params  = [];
    if ($coordId > 0) {
        $filtros[] = "l.coordenador_id = :cid";
        $params[':cid'] = $coordId;
    }
    if ($liderId > 0) {
        $filtros[] = "ls.lider_id = :lid";
        $params[':lid'] = $liderId;
    }
    if ($temNumeroCandCoord) {
        $filtros[] = "c.numero_candidato = :num";
        $params[':num'] = $candidato;
    }
    if ($temAnoCampanha) {
        $filtros[] = "c.ano_campanha = :ano";
        $params[':ano'] = $ano;
    }
    if ($filtros) $sql .= ' WHERE ' . implode(' AND ', $filtros);
    $sql .= ' ORDER BY c.nome, l.nome, ls.numeroZona, ls.numeroSecao';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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

// Mapa numero_candidato -> nome exibido
$nomeCandidato = '';
foreach ($listaCandidatos as $cc) {
    if ((int)$cc['numero'] === $candidato) { $nomeCandidato = $cc['nome']; break; }
}

foreach ($linhasEquipe as $row) {
    $lat = (float)$row['latitude'];
    $lng = (float)$row['longitude'];
    $temCoord = !empty($row['latitude']) && !empty($row['longitude'])
             && !($lat === 0.0 && $lng === 0.0);
    if (!$temCoord) { $secoesSemCoord++; continue; }

    $chave = $row['numeroZona'] . '|' . $row['numeroSecao'];
    $votos = $votosPorSecao[$chave] ?? 0;
    $zona  = (int)$row['numeroZona'];

    // Agrupamento:
    //   - Se filtrou por lider → grupo é a liderança
    //   - Se filtrou por coordenador (ou nenhum) → grupo é o coordenador
    if ($liderId > 0) {
        $grupoId   = 'L' . $row['lider_id'];
        $grupoNome = $row['lider_nome'];
        $grupoTipo = 'lideranca';
    } else {
        $grupoId   = 'C' . $row['coordenador_id'];
        $grupoNome = $row['coordenador_nome'];
        $grupoTipo = 'coordenador';
    }

    if (!isset($grupos[$grupoId])) {
        $grupos[$grupoId] = [
            'id'       => $grupoId,
            'nome'     => $grupoNome,
            'tipo'     => $grupoTipo,
            'candidato'=> $nomeCandidato,
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
        'candidato' => $nomeCandidato,
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
// ZONAS (polígonos)
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
    .barra-usuario .saudacao { color:#888; font-weight:normal; margin-right:3px; }

    .perfil-tag {
        font-size:10px; padding:2px 8px; border-radius:8px;
        font-weight:bold; letter-spacing:.5px;
        background:#e6f0fa; color:#0069d9;
    }
    .perfil-tag.perfil-super    { background:#dc3545; color:#fff; }
    .perfil-tag.perfil-admin    { background:#fff3cd; color:#856404; }
    .perfil-tag.perfil-consulta { background:#e6f0fa; color:#0069d9; }

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

    .filtros { background:#fff; padding:14px 16px; border-radius:8px;
               box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
               display:flex; flex-wrap:wrap; align-items:center; gap:10px;
               border-left:5px solid #0069d9; }
    .filtros label { font-weight:bold; font-size:13px; }
    .filtros select, .filtros button {
        padding:7px 10px; border-radius:4px; border:1px solid #ccc; font-size:14px;
        font-family: inherit;
    }
    .btn-consultar { background:#0069d9; color:#fff; border:none; cursor:pointer;
                     font-weight:bold; }
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
    #ano + .select2-container         { min-width: 110px; }
    #candidato + .select2-container   { min-width: 260px; max-width: 360px; }
    #coordenador + .select2-container { min-width: 240px; max-width: 320px; }
    #lideranca + .select2-container   { min-width: 220px; max-width: 300px; }
    #cargo + .select2-container       { min-width: 150px; }

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

<div class="barra-usuario <?= $isAdmin ? ($__ehSuper ? 'super' : 'admin') : '' ?>">
    <span>
        👤 <span class="saudacao">Olá,</span>
        <span class="nome-user"><?= htmlspecialchars($__u['nome'] ?? '—') ?></span>
    </span>
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
</div>

<h1>Mapa de Calor — Coordenadores e Lideranças</h1>
<div class="subtitulo">
    Cada seção é um ponto colorido pela cor do grupo responsável.
    O <strong>polígono azul</strong> marca a <strong>área geográfica da zona eleitoral</strong>.
    Seções exibidas no formato <strong>#Zzona-Ssecao</strong>.
</div>

<p class="info-detect">
    Coluna de votos: <strong><?= htmlspecialchars($COL_VOTOS) ?></strong>
    &nbsp;·&nbsp;
    <code>numero_candidato</code> + <code>ano_campanha</code> em <code>coordenadores</code>:
    <?php if ($temNumeroCandCoord && $temAnoCampanha): ?><strong>ok</strong>
    <?php else: ?><span class="aviso-inline">colunas ausentes</span><?php endif; ?>
    &nbsp;·&nbsp;
    Tabela <code>lider_secao</code>:
    <?php if ($temLiderSecao): ?><strong>existe</strong>
    <?php else: ?><span class="aviso-inline">não existe</span><?php endif; ?>
    &nbsp;·&nbsp;
    Filtro de votos: <code>numero + ano + tipo + cargo</code>
</p>

<form method="get" action="mapa_calor_equipe.php" id="form-filtros" class="filtros">

    <label for="ano">Ano:</label>
    <select name="ano" id="ano" style="min-width:110px;">
        <option value="0">Ano</option>
        <?php foreach ($listaAnos as $a): ?>
            <option value="<?= (int)$a ?>" <?= ((int)$a === $ano) ? 'selected' : '' ?>>
                <?= (int)$a ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="candidato">Candidato:</label>
    <select name="candidato" id="candidato" style="min-width:260px;">
        <option value="0">Todos</option>
        <?php foreach ($listaCandidatos as $c): ?>
            <option value="<?= (int)$c['numero'] ?>"
                <?= ((int)$c['numero'] === $candidato) ? 'selected' : '' ?>>
                Nº <?= (int)$c['numero'] ?> — <?= htmlspecialchars($c['nome']) ?>
                <?= $c['cargo'] ? ' (' . htmlspecialchars($c['cargo']) . ')' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if ($mostrarMapa): ?>
    <label for="coordenador">Coordenador:</label>
    <select name="coordenador_id" id="coordenador" style="min-width:220px;">
        <option value="">Todos</option>
        <?php foreach ($coordenadores as $c): ?>
            <option value="<?= (int)$c['id'] ?>"
                <?= ((int)$c['id'] === $coordId) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['nome']) ?>
                <?= (int)$c['ativo'] === 0 ? ' (inativo)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="lideranca">Liderança:</label>
    <select name="lider_id" id="lideranca" style="min-width:220px;">
        <option value="">Todas</option>
        <?php foreach ($liderancasTodas as $l): ?>
            <option value="<?= (int)$l['id'] ?>"
                    data-coord="<?= (int)$l['coordenador_id'] ?>"
                <?= ((int)$l['id'] === $liderId) ? 'selected' : '' ?>>
                <?= htmlspecialchars($l['nome']) ?>
                <?= (int)$l['ativo'] === 0 ? ' (inativa)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="cargo">Cargo:</label>
    <select name="cargo" id="cargo" style="min-width:160px;">
        <option value="">Todos</option>
        <?php foreach ($listaCargos as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"
                <?= $c === $cargo ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <button type="submit" class="btn-consultar">Aplicar</button>
    <a href="mapa_calor_equipe.php" class="btn-limpar">Limpar</a>
</form>

<?php if ($temFiltro): ?>
<div class="badges-filtros">
    <span style="font-size:12px; color:#666; margin-right:8px;">Filtros ativos:</span>

    <?php if ($ano > 0): ?>
        <span class="badge-filtro">
            📅 <?= (int)$ano ?>
            <a class="x" href="?<?= http_build_query(['ano' => 0, 'candidato' => 0, 'coordenador_id' => 0, 'lider_id' => 0, 'cargo' => $cargo]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($candidato > 0): ?>
        <span class="badge-filtro">
            🗳 Nº <?= (int)$candidato ?> — <?= htmlspecialchars($nomeCandidato) ?>
            <a class="x" href="?<?= http_build_query(['ano' => $ano, 'candidato' => 0, 'coordenador_id' => 0, 'lider_id' => 0, 'cargo' => $cargo]) ?>">×</a>
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
            <a class="x" href="?<?= http_build_query(['ano' => $ano, 'candidato' => $candidato, 'coordenador_id' => 0, 'lider_id' => 0, 'cargo' => $cargo]) ?>">×</a>
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
            <a class="x" href="?<?= http_build_query(['ano' => $ano, 'candidato' => $candidato, 'coordenador_id' => $coordId, 'lider_id' => 0, 'cargo' => $cargo]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($cargo !== ''): ?>
        <span class="badge-filtro">
            💼 <?= htmlspecialchars($cargo) ?>
            <a class="x" href="?<?= http_build_query(['ano' => $ano, 'candidato' => $candidato, 'coordenador_id' => $coordId, 'lider_id' => $liderId, 'cargo' => '']) ?>">×</a>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$mostrarMapa): ?>

    <div class="msg-sem-filtro">
        <div class="icone">🗺️</div>
        <h2>Selecione o ano e o candidato para visualizar o mapa</h2>
        <p>
            O mapa só é exibido quando o <b>ano</b> e o <b>candidato</b> estão definidos.
        </p>

        <div class="passos">
            <b>Como usar:</b><br>
            • 📅 Escolha um <b>ano</b><br>
            • 🗳 Escolha um <b>candidato</b> (número da urna)<br>
            • Opcional: filtre por <b>coordenador</b>, <b>liderança</b> ou <b>cargo</b><br>
            <br>
            Depois clique em <b>Aplicar</b>.
        </div>

        <div class="dica">
            💡 O filtro por cargo refina a contagem de votos quando há
            mais de um cargo com o mesmo número de urna.
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
                Nº <?= (int)$candidato ?> — <?= htmlspecialchars($nomeCandidato) ?>
            </div>
            <div class="sub">Ano <?= (int)$ano ?></div>
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
            function fmtSecao(z, s) { return '#Z' + z + '-S' + s; }

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
                            <div><b>${fmtSecao(p.zona, p.secao)}</b></div>
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
                        '<span class="zona">' + fmtSecao(p.zona, p.secao) + '</span>' +
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
    const ANO_SELECIONADO   = <?= (int)$ano ?>;
    const CAND_SELECIONADO  = <?= (int)$candidato ?>;
    const COORD_SELECIONADO = <?= (int)$coordId ?>;
    const LIDER_SELECIONADA = <?= (int)$liderId ?>;

    (function () {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;

        const $ano   = jQuery('#ano');
        const $cand  = jQuery('#candidato');
        const $coord = jQuery('#coordenador');
        const $lider = jQuery('#lideranca');
        const $cargo = jQuery('#cargo');

        function removerAcentos(s) {
            return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        function matcherSemAcento(params, data) {
            if (jQuery.trim(params.term) === '') return data;
            const termo = removerAcentos(params.term.toLowerCase());
            const texto = removerAcentos((data.text || '').toLowerCase());
            return texto.indexOf(termo) > -1 ? data : null;
        }

        function initSel($el, placeholder) {
            if (!$el || !$el.length) return;
            $el.select2({
                language: 'pt-BR',
                placeholder: placeholder,
                allowClear: true,
                width: 'resolve',
                matcher: matcherSemAcento
            });
        }

        function popularLiderancas(coordId, liderSel) {
            if (!$lider || !$lider.length) return;

            if ($lider.hasClass('select2-hidden-accessible')) $lider.select2('destroy');
            $lider.html('<option value="">Carregando...</option>');

            let url = 'mapa_calor_equipe.php?ajax=liderancas';
            if (coordId > 0) url += '&coordenador_id=' + coordId;

            fetch(url)
                .then(r => r.json())
                .then(lista => {
                    $lider.html('<option value="">Todas</option>');
                    (lista || []).forEach(l => {
                        const $opt = jQuery('<option>')
                            .val(l.id)
                            .attr('data-coord', coordId)
                            .text(l.nome + (parseInt(l.ativo) === 0 ? ' (inativa)' : ''));
                        if (parseInt(l.id) === parseInt(liderSel)) $opt.prop('selected', true);
                        $lider.append($opt);
                    });
                    initSel($lider, 'Buscar liderança...');
                })
                .catch(() => {
                    $lider.html('<option value="">Erro ao carregar</option>');
                    initSel($lider, 'Buscar liderança...');
                });
        }

        function popularCoordenadores(ano, candidato, coordSel) {
            if (!$coord || !$coord.length) return;

            if ($coord.hasClass('select2-hidden-accessible')) $coord.select2('destroy');
            $coord.html('<option value="">Carregando...</option>');

            let url = 'mapa_calor_equipe.php?ajax=coordenadores_listar';
            if (ano > 0)        url += '&ano=' + ano;
            if (candidato > 0)  url += '&candidato=' + candidato;

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
                    popularLiderancas(coordSel || 0, LIDER_SELECIONADA);
                })
                .catch(() => {
                    $coord.html('<option value="">Erro ao carregar</option>');
                    initSel($coord, 'Buscar coordenador...');
                });
        }

        initSel($ano,   'Ano');
        initSel($cand,  'Buscar candidato...');
        initSel($coord, 'Buscar coordenador...');
        initSel($lider, 'Buscar liderança...');
        initSel($cargo, 'Buscar cargo...');

        // ---------- submit dos selects ----------
        if ($ano && $ano.length) {
            $ano.on('select2:select select2:clear', function () {
                // ao trocar ano, zera candidato
                if ($cand && $cand.length) {
                    $cand.val('0').trigger('change.select2');
                }
                document.getElementById('form-filtros').submit();
            });
        }

        if ($cand && $cand.length) {
            $cand.on('select2:select select2:clear', function () {
                if ($coord && $coord.length) {
                    $coord.val('').trigger('change.select2');
                }
                if ($lider && $lider.length) {
                    $lider.val('').trigger('change.select2');
                }
                document.getElementById('form-filtros').submit();
            });
        }

        if ($coord && $coord.length) {
            $coord.on('select2:select select2:clear', function () {
                const coordVal = parseInt($coord.val() || 0);
                if ($lider && $lider.length) {
                    popularLiderancas(coordVal, 0);
                    $lider.val('').trigger('change.select2');
                }
                document.getElementById('form-filtros').submit();
            });
        }

        if ($lider && $lider.length) {
            $lider.on('select2:select select2:clear', function () {
                document.getElementById('form-filtros').submit();
            });
        }

        if ($cargo && $cargo.length) {
            $cargo.on('select2:select select2:clear', function () {
                document.getElementById('form-filtros').submit();
            });
        }

        // Inicializa coordenadores (só se o mapa está visível)
        <?php if ($mostrarMapa): ?>
        popularCoordenadores(ANO_SELECIONADO, CAND_SELECIONADO, COORD_SELECIONADO);
        <?php endif; ?>
    })();
</script>

</body>
</html>