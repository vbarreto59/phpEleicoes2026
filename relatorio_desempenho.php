<?php
// ------------------------------------------------------------
// relatorio_desempenho.php
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
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

// ============================================================
// DETECÇÃO DE ESTRUTURAS
// ============================================================

// ---------- coluna de votos em `votos` ----------
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
if ($COL_VOTOS === null) {
    die("Não foi possível detectar a coluna de votos na tabela `votos`.");
}

// ---------- coluna de expectativa em `liderancas` ----------
$colunasLider = [];
foreach ($pdo->query("SHOW COLUMNS FROM liderancas") as $c) $colunasLider[] = $c['Field'];

$candidatosExpect = [
    'expectativa_votos','expectativa','votos_esperados','qtd_esperada',
    'qtde_esperada','previsao','meta','previsao_votos','votos_previstos',
];
$COL_EXPECT = null;
foreach ($candidatosExpect as $cand) {
    if (in_array($cand, $colunasLider, true)) { $COL_EXPECT = $cand; break; }
}
$EXPECT_SQL = $COL_EXPECT ? "l.`$COL_EXPECT`" : "0";

// ---------- coluna de candidato em `coordenadores` ----------
$colunasCoord = [];
foreach ($pdo->query("SHOW COLUMNS FROM coordenadores") as $c) $colunasCoord[] = $c['Field'];
$temCandidatoCoord = in_array('nomeCandidatoResp', $colunasCoord, true);
$CAND_SQL = $temCandidatoCoord ? "c.nomeCandidatoResp" : "NULL";

// ---------- tabela lider_secao existe? ----------
$temLiderSecao = false;
try { $pdo->query("SELECT 1 FROM lider_secao LIMIT 1"); $temLiderSecao = true; }
catch (Exception $e) { $temLiderSecao = false; }

// ============================================================
// HELPERS
// ============================================================
function classificarSituacao(int $esperado, int $urnas): array {
    if ($esperado <= 0) {
        return ['rotulo' => 'Sem meta', 'classe' => 'sit-sem', 'icone' => '—'];
    }
    if ($urnas >= $esperado) {
        return $urnas > $esperado
            ? ['rotulo' => 'Acima',   'classe' => 'sit-acima',  'icone' => '▲']
            : ['rotulo' => 'Na meta', 'classe' => 'sit-meta',   'icone' => '✔'];
    }
    $pct = ($urnas / $esperado) * 100;
    return $pct >= 70
        ? ['rotulo' => 'Quase',  'classe' => 'sit-quase',  'icone' => '≈']
        : ['rotulo' => 'Abaixo', 'classe' => 'sit-abaixo', 'icone' => '▼'];
}
function fmtPct(?float $p): string {
    return $p === null ? '—' : number_format($p, 1, ',', '.') . '%';
}
function fmtNum(int|float|null $n): string {
    return $n === null ? '—' : number_format((float)$n, 0, ',', '.');
}

// ============================================================
// FILTROS
// ============================================================
$filtroCand   = trim($_GET['cand']   ?? '');
$filtroCoord  = (int)($_GET['coord'] ?? 0);
$filtroLider  = (int)($_GET['lider'] ?? 0);
$filtroStatus = $_GET['status'] ?? 'todas';

if (!in_array($filtroStatus, ['todas','ativas','inativas'], true)) {
    $filtroStatus = 'todas';
}

$listaCandidatos = [];
if ($temCandidatoCoord) {
    $listaCandidatos = $pdo->query(
        "SELECT DISTINCT nomeCandidatoResp FROM coordenadores
          WHERE nomeCandidatoResp IS NOT NULL AND nomeCandidatoResp <> ''
          ORDER BY nomeCandidatoResp"
    )->fetchAll(PDO::FETCH_COLUMN);
}

if ($temCandidatoCoord && $filtroCand !== '') {
    $stmt = $pdo->prepare(
        "SELECT id, nome, ativo, nomeCandidatoResp
           FROM coordenadores
          WHERE nomeCandidatoResp = :c
          ORDER BY ativo DESC, nome"
    );
    $stmt->execute([':c' => $filtroCand]);
    $listaCoords = $stmt->fetchAll();
} else {
    $listaCoords = $pdo->query(
        "SELECT id, nome, ativo" . ($temCandidatoCoord ? ", nomeCandidatoResp" : "") . "
           FROM coordenadores
          ORDER BY ativo DESC, nome"
    )->fetchAll();
}

$listaLideres = $pdo->query(
    "SELECT l.id, l.nome, l.coordenador_id, l.ativo
       FROM liderancas l
       INNER JOIN coordenadores c ON c.id = l.coordenador_id
      ORDER BY c.nome, l.nome"
)->fetchAll();

if ($filtroLider > 0) {
    foreach ($listaLideres as $ll) {
        if ((int)$ll['id'] === $filtroLider) {
            $filtroCoord = (int)$ll['coordenador_id'];
            break;
        }
    }
}

$whereLid    = [];
$paramsLid   = [];
$whereCoord  = [];
$paramsCoord = [];

if ($temCandidatoCoord && $filtroCand !== '') {
    $whereLid[]    = "c.nomeCandidatoResp = :fcand";
    $paramsLid[':fcand'] = $filtroCand;
    $whereCoord[]  = "c.nomeCandidatoResp = :fcand2";
    $paramsCoord[':fcand2'] = $filtroCand;
}
if ($filtroCoord > 0) {
    $whereLid[]    = "l.coordenador_id = :fcoord";
    $paramsLid[':fcoord'] = $filtroCoord;
    $whereCoord[]  = "c.id = :fcoord2";
    $paramsCoord[':fcoord2'] = $filtroCoord;
}
if ($filtroLider > 0) {
    $whereLid[]    = "l.id = :flider";
    $paramsLid[':flider'] = $filtroLider;
}
if ($filtroStatus === 'ativas')   $whereLid[] = "l.ativo = 1";
if ($filtroStatus === 'inativas') $whereLid[] = "l.ativo = 0";

$whereLidStr   = $whereLid   ? ('WHERE ' . implode(' AND ', $whereLid)) : '';
$whereCoordStr = $whereCoord ? ('WHERE ' . implode(' AND ', $whereCoord)) : '';

// ============================================================
// 1) LIDERANÇAS
// ============================================================
$subSecoesUrnas = $temLiderSecao
    ? "(SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
          FROM votos v
         WHERE (v.numeroZona, v.numeroSecao) IN (
                SELECT ls.numeroZona, ls.numeroSecao
                  FROM lider_secao ls
                 WHERE ls.lider_id = l.id
         ))"
    : "0";
$subQtdSecoes = $temLiderSecao
    ? "(SELECT COUNT(*) FROM lider_secao ls WHERE ls.lider_id = l.id)"
    : "0";

$sqlLid = "
    SELECT l.id, l.nome, l.ativo,
           $EXPECT_SQL AS expectativa_votos,
           c.id   AS coord_id,
           c.nome AS coord_nome,
           c.ativo AS coord_ativo,
           $subSecoesUrnas AS votos_urnas,
           $subQtdSecoes   AS qtd_secoes
    FROM liderancas l
    INNER JOIN coordenadores c ON c.id = l.coordenador_id
    $whereLidStr
    ORDER BY c.nome, l.nome
";
$stmt = $pdo->prepare($sqlLid);
$stmt->execute($paramsLid);
$liderancas = $stmt->fetchAll();

foreach ($liderancas as &$l) {
    $esp = (int)($l['expectativa_votos'] ?? 0);
    $urn = (int)($l['votos_urnas'] ?? 0);
    $l['esperado']  = $esp;
    $l['urnas']     = $urn;
    $l['diferenca'] = $urn - $esp;
    $l['pct']       = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
    $l['situacao']  = classificarSituacao($esp, $urn);
}
unset($l);

// ============================================================
// 2) COORDENADORES
// ============================================================
$subCoordEsperado = "(SELECT COALESCE(SUM($EXPECT_SQL), 0)
                        FROM liderancas l
                       WHERE l.coordenador_id = c.id AND l.ativo = 1)";
$subCoordUrnas = $temLiderSecao
    ? "(SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
          FROM votos v
         WHERE (v.numeroZona, v.numeroSecao) IN (
                SELECT DISTINCT ls.numeroZona, ls.numeroSecao
                  FROM lider_secao ls
                  INNER JOIN liderancas l2 ON l2.id = ls.lider_id
                 WHERE l2.coordenador_id = c.id AND l2.ativo = 1
         ))"
    : "0";
$subCoordZonas = $temLiderSecao
    ? "(SELECT COUNT(DISTINCT ls.numeroZona)
          FROM lider_secao ls
          INNER JOIN liderancas l3 ON l3.id = ls.lider_id
         WHERE l3.coordenador_id = c.id)"
    : "0";

$sqlCoord = "
    SELECT c.id, c.nome, c.ativo,
           $CAND_SQL AS candidato,
           (SELECT COUNT(*) FROM liderancas l WHERE l.coordenador_id = c.id) AS qtd_lid,
           (SELECT COUNT(*) FROM liderancas l WHERE l.coordenador_id = c.id AND l.ativo = 1) AS qtd_lid_ativ,
           $subCoordEsperado AS esperado,
           $subCoordUrnas    AS urnas,
           $subCoordZonas    AS qtd_zonas
    FROM coordenadores c
    $whereCoordStr
    ORDER BY c.nome
";
$stmt = $pdo->prepare($sqlCoord);
$stmt->execute($paramsCoord);
$coordenadores = $stmt->fetchAll();

foreach ($coordenadores as &$c) {
    $esp = (int)$c['esperado'];
    $urn = (int)$c['urnas'];
    $c['diferenca'] = $urn - $esp;
    $c['pct']       = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
    $c['situacao']  = classificarSituacao($esp, $urn);

    $c['qtd_acima'] = 0; $c['qtd_meta'] = 0;
    $c['qtd_quase'] = 0; $c['qtd_abaixo'] = 0; $c['qtd_sem'] = 0;
    foreach ($liderancas as $l) {
        if ((int)$l['coord_id'] !== (int)$c['id']) continue;
        switch ($l['situacao']['classe']) {
            case 'sit-acima':  $c['qtd_acima']++;  break;
            case 'sit-meta':   $c['qtd_meta']++;   break;
            case 'sit-quase':  $c['qtd_quase']++;  break;
            case 'sit-abaixo': $c['qtd_abaixo']++; break;
            default:           $c['qtd_sem']++;    break;
        }
    }
}
unset($c);

// ============================================================
// 3) TOTAIS
// ============================================================
$tot = [
    'coords' => count($coordenadores), 'coords_ativos' => 0,
    'liderancas' => count($liderancas), 'liderancas_ativas' => 0,
    'esperado' => 0, 'urnas' => 0,
    'acima' => 0, 'meta' => 0, 'quase' => 0, 'abaixo' => 0, 'sem' => 0,
];
foreach ($coordenadores as $c) if ((int)$c['ativo'] === 1) $tot['coords_ativos']++;

foreach ($liderancas as $l) {
    if ((int)$l['ativo'] === 1) {
        $tot['liderancas_ativas']++;
        $tot['esperado'] += (int)$l['esperado'];
        $tot['urnas']    += (int)$l['urnas'];
    }
    switch ($l['situacao']['classe']) {
        case 'sit-acima':  $tot['acima']++;  break;
        case 'sit-meta':   $tot['meta']++;   break;
        case 'sit-quase':  $tot['quase']++;  break;
        case 'sit-abaixo': $tot['abaixo']++; break;
        default:           $tot['sem']++;    break;
    }
}
$tot['diferenca'] = $tot['urnas'] - $tot['esperado'];
$tot['pct'] = $tot['esperado'] > 0 ? round(($tot['urnas'] / $tot['esperado']) * 100, 1) : null;

// ============================================================
// 4) RANKINGS
// ============================================================
$rankLid = array_values(array_filter($liderancas, fn($l) =>
    (int)$l['ativo'] === 1 && (int)$l['esperado'] > 0));
usort($rankLid, fn($a, $b) => ($b['pct'] ?? 0) <=> ($a['pct'] ?? 0));

$rankCoord = array_values(array_filter($coordenadores, fn($c) =>
    (int)$c['ativo'] === 1 && (int)$c['esperado'] > 0));
usort($rankCoord, fn($a, $b) => ($b['pct'] ?? 0) <=> ($a['pct'] ?? 0));

// ============================================================
// 5) TOP SEÇÕES
// ============================================================
$topSecoes = [];
if ($temLiderSecao) {
    $whereTopSec = []; $paramsTopSec = [];
    if ($temCandidatoCoord && $filtroCand !== '') {
        $whereTopSec[] = "c.nomeCandidatoResp = :tsCand";
        $paramsTopSec[':tsCand'] = $filtroCand;
    }
    if ($filtroCoord > 0) {
        $whereTopSec[] = "l.coordenador_id = :fs1";
        $paramsTopSec[':fs1'] = $filtroCoord;
    }
    if ($filtroLider > 0) {
        $whereTopSec[] = "l.id = :fs2";
        $paramsTopSec[':fs2'] = $filtroLider;
    }
    if ($filtroStatus === 'ativas')   $whereTopSec[] = "l.ativo = 1";
    if ($filtroStatus === 'inativas') $whereTopSec[] = "l.ativo = 0";
    $whereTopSecStr = $whereTopSec ? ('AND ' . implode(' AND ', $whereTopSec)) : '';

    $sqlSecoes = "
        SELECT ls.numeroZona, ls.numeroSecao,
               COUNT(DISTINCT ls.lider_id) AS qtd_lideres,
               COALESCE(MAX(v.`$COL_VOTOS`), 0) AS votos
        FROM lider_secao ls
        INNER JOIN liderancas l ON l.id = ls.lider_id
        INNER JOIN coordenadores c ON c.id = l.coordenador_id
        LEFT JOIN votos v
               ON v.numeroZona  = ls.numeroZona
              AND v.numeroSecao = ls.numeroSecao
        WHERE 1=1 $whereTopSecStr
        GROUP BY ls.numeroZona, ls.numeroSecao
        ORDER BY votos DESC
        LIMIT 15
    ";
    $stmt = $pdo->prepare($sqlSecoes);
    $stmt->execute($paramsTopSec);
    $topSecoes = $stmt->fetchAll();
}

// ============================================================
// 6) SEÇÕES DESCOBERTAS
// ============================================================
$secoesDescobertas     = [];
$qtdDescobertasTotal   = 0;
$votosDescobertosTotal = 0;

if ($temLiderSecao && $filtroLider === 0) {
    $whereDesc = '';
    $paramsDesc = [];
    if ($filtroCoord > 0) {
        $whereDesc = "AND v.numeroZona IN (
            SELECT DISTINCT cz.numeroZona FROM coordenador_zona cz
             WHERE cz.coordenador_id = :fd1)";
        $paramsDesc[':fd1'] = $filtroCoord;
    }

    $sqlDescobertas = "
        SELECT v.numeroZona, v.numeroSecao, v.`$COL_VOTOS` AS votos
        FROM votos v
        WHERE NOT EXISTS (
            SELECT 1 FROM lider_secao ls
             WHERE ls.numeroZona = v.numeroZona
               AND ls.numeroSecao = v.numeroSecao
        )
        $whereDesc
        ORDER BY v.`$COL_VOTOS` DESC
        LIMIT 20
    ";
    $stmt = $pdo->prepare($sqlDescobertas);
    $stmt->execute($paramsDesc);
    $secoesDescobertas = $stmt->fetchAll();

    $sqlCountDesc = "
        SELECT COUNT(*) AS qtd, COALESCE(SUM(v.`$COL_VOTOS`), 0) AS votos
        FROM votos v
        WHERE NOT EXISTS (
            SELECT 1 FROM lider_secao ls
             WHERE ls.numeroZona = v.numeroZona
               AND ls.numeroSecao = v.numeroSecao
        )
        $whereDesc
    ";
    $stmt = $pdo->prepare($sqlCountDesc);
    $stmt->execute($paramsDesc);
    $rowDesc = $stmt->fetch();
    $qtdDescobertasTotal   = (int)($rowDesc['qtd']   ?? 0);
    $votosDescobertosTotal = (int)($rowDesc['votos'] ?? 0);
}

// ============================================================
// RÓTULOS DOS FILTROS
// ============================================================
$nomeCoordFiltro = '';
if ($filtroCoord > 0) {
    foreach ($listaCoords as $cc) {
        if ((int)$cc['id'] === $filtroCoord) { $nomeCoordFiltro = $cc['nome']; break; }
    }
}
$nomeLiderFiltro = '';
if ($filtroLider > 0) {
    foreach ($listaLideres as $ll) {
        if ((int)$ll['id'] === $filtroLider) { $nomeLiderFiltro = $ll['nome']; break; }
    }
}
$temFiltro = ($filtroCand !== '' || $filtroCoord > 0 || $filtroLider > 0 || $filtroStatus !== 'todas');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatório de Desempenho</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom:4px; }
    h2.sec { font-size:16px; color:#6f42c1; margin:26px 0 10px; }
    .info-col { font-size:11px; color:#666; margin-bottom:16px; }
    .info-col code { background:#f4f4f4; padding:1px 5px; border-radius:3px; }
    .info-col .aviso { color:#856404; background:#fff3cd;
        padding:2px 8px; border-radius:6px; font-weight:bold; margin-left:6px; }

    /* ===== Barra de usuário (mesmo modelo das outras páginas) ===== */
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

    .filtros {
        background:#fff; border-radius:8px; padding:16px 20px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
        display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end;
        border-left:5px solid #0069d9;
    }
    .filtros .campo { display:flex; flex-direction:column; min-width:220px; flex:1; }
    .filtros .campo label {
        font-size:11px; font-weight:bold; color:#555;
        text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;
    }
    .filtros .campo select {
        padding:8px 10px; border:1px solid #ccc; border-radius:4px;
        font-size:13px; background:#fff; font-family:inherit; width:100%;
        cursor:pointer;
    }
    .filtros .campo select:focus {
        outline:none; border-color:#0069d9;
        box-shadow:0 0 0 2px rgba(0,105,217,.15);
    }
    .filtros .acoes { display:flex; gap:8px; align-items:flex-end; }
    .btn-filtrar {
        background:#0069d9; color:#fff; padding:8px 16px;
        border:none; border-radius:4px; cursor:pointer;
        font-size:13px; font-weight:bold; font-family:inherit;
    }
    .btn-filtrar:hover { background:#0053b3; }
    .btn-limpar {
        background:#fff; color:#666; border:1px solid #ccc;
        padding:8px 14px; border-radius:4px; cursor:pointer;
        font-size:13px; font-family:inherit; text-decoration:none;
        display:inline-flex; align-items:center;
    }
    .btn-limpar:hover { background:#f4f4f4; color:#333; }

    .badge-filtro {
        background:#e6f0fa; color:#0069d9; padding:4px 12px;
        border-radius:12px; font-size:12px; font-weight:bold;
        display:inline-flex; align-items:center; gap:6px;
        margin-right:6px; margin-bottom:6px;
    }
    .badge-filtro .x {
        cursor:pointer; color:#0069d9; text-decoration:none;
        font-size:14px; line-height:1; font-weight:bold;
    }
    .badge-filtro .x:hover { color:#dc3545; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:15px; }
    .kpi {
        background:#fff; border-radius:8px; padding:14px 22px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:170px;
        border-left:5px solid #6f42c1;
    }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:26px; font-weight:bold; color:#6f42c1; margin-top:4px; }
    .kpi .mini   { font-size:11px; color:#999; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.azul    { border-left-color:#0069d9; } .kpi.azul .valor  { color:#0069d9; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.teal    { border-left-color:#17a2b8; } .kpi.teal .valor  { color:#17a2b8; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi.amarelo { border-left-color:#ffc107; } .kpi.amarelo .valor { color:#b8860b; }

    table { border-collapse:collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; font-size:13px; }
    th { background:#6f42c1; color:#fff; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#f6f0ff; }
    tr.linha-total { background:#f6f0ff; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #6f42c1; }
    tr.destaque-ouro  { background:#fffbe6; }
    tr.destaque-prata { background:#f4f4f4; }
    tr.destaque-bronze{ background:#fdf2e3; }
    .num { text-align:right; }
    .centro { text-align:center; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-zona  { background:#f0e6ff; color:#6f42c1; }
    .badge-coord { background:#e6f0fa; color:#0069d9; }
    .badge-cand  { background:#e7d9ff; color:#4a1d96; font-weight:bold; }
    .badge-ativo { background:#d4f5dd; color:#1c7a34; }
    .badge-inativo{ background:#f0f0f0; color:#888; }
    .badge-votos { background:#fff0e0; color:#c25b00; }
    .badge-urnas { background:#d9edf7; color:#0c5460; }
    .badge-pos   { background:#d4f5dd; color:#1c7a34; }
    .badge-neg   { background:#fdecea; color:#a71d2a; }
    .badge-zero  { background:#f0f0f0; color:#666; }
    .badge-pct-ok  { background:#d4f5dd; color:#1c7a34; }
    .badge-pct-med { background:#fff3cd; color:#856404; }
    .badge-pct-ruim{ background:#fdecea; color:#a71d2a; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    .sit { display:inline-block; padding:3px 10px; border-radius:12px;
           font-size:11px; font-weight:bold; white-space:nowrap; }
    .sit-acima  { background:#d4f5dd; color:#0e5c22; border:1px solid #87c99a; }
    .sit-meta   { background:#28a745; color:#fff; }
    .sit-quase  { background:#fff3cd; color:#856404; border:1px solid #f0d175; }
    .sit-abaixo { background:#fdecea; color:#a71d2a; border:1px solid #e4a4ab; }
    .sit-sem    { background:#f0f0f0; color:#888; }
    .sit .ico   { font-size:12px; margin-right:3px; }

    .barra { position:relative; height:20px; background:#eee; border-radius:10px;
             overflow:hidden; min-width:120px; }
    .barra .fill { height:100%; transition:width .3s; }
    .barra .fill.ok   { background:linear-gradient(90deg,#28a745,#5ec27a); }
    .barra .fill.med  { background:linear-gradient(90deg,#ffc107,#ffdb70); }
    .barra .fill.ruim { background:linear-gradient(90deg,#dc3545,#e87682); }
    .barra .fill.zero { background:#ccc; }
    .barra .txt { position:absolute; inset:0; text-align:center;
                  font-size:11px; font-weight:bold; line-height:20px; color:#222; }

    .medalha { font-size:16px; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }
    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
    @media (max-width: 900px) { .grid-2 { grid-template-columns:1fr; } }

    .card { background:#fff; border-radius:8px; padding:16px 20px;
            box-shadow:0 1px 3px rgba(0,0,0,.1); }
    .card h3 { margin:0 0 12px; font-size:14px; color:#6f42c1;
               text-transform:uppercase; letter-spacing:1px; }

    .btn-imprimir { background:#0069d9; color:#fff; padding:8px 14px;
                    border-radius:4px; border:none; cursor:pointer;
                    font-size:14px; margin-right:8px; }
    .btn-imprimir:hover { background:#0053b3; }
    @media print {
        .no-print { display:none !important; }
        body { background:#fff; margin:0; }
        table { page-break-inside:auto; }
        tr    { page-break-inside:avoid; }
    }
</style>
</head>
<body>

<a href="index.php" class="link-voltar no-print">&larr; Voltar ao menu</a>

<h1>Relatório de Desempenho</h1>

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

<div style="display:flex; justify-content:space-between; align-items:flex-start;">
    <div>
        <p class="info-col" style="margin-top:0;">
            Coluna de votos: <strong><?= htmlspecialchars($COL_VOTOS) ?></strong> ·
            Expectativa em <code>liderancas</code>:
            <?php if ($COL_EXPECT): ?><strong><?= htmlspecialchars($COL_EXPECT) ?></strong>
            <?php else: ?><span class="aviso">não existe — usando 0</span><?php endif; ?>
            ·
            Tabela <code>lider_secao</code>:
            <?php if ($temLiderSecao): ?><strong>existe</strong>
            <?php else: ?><span class="aviso">não existe</span><?php endif; ?>
            · Gerado em <?= date('d/m/Y H:i') ?>
        </p>
    </div>
    <button class="btn-imprimir no-print" onclick="window.print()">🖨 Imprimir</button>
</div>

<!-- ============================================================
     PAINEL DE FILTROS
     ============================================================ -->
<form class="filtros no-print" method="get" action="relatorio_desempenho.php" id="form-filtros">

    <?php if ($temCandidatoCoord): ?>
    <div class="campo">
        <label for="f-cand">Candidato</label>
        <select name="cand" id="f-cand">
            <option value="">— Todos os candidatos —</option>
            <?php foreach ($listaCandidatos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= $c === $filtroCand ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="campo">
        <label for="f-coord">Coordenador</label>
        <select name="coord" id="f-coord">
            <option value="0">— Todos os coordenadores —</option>
            <?php foreach ($listaCoords as $cc): ?>
                <option value="<?= (int)$cc['id'] ?>"
                        data-cand="<?= htmlspecialchars($cc['nomeCandidatoResp'] ?? '') ?>"
                    <?= $filtroCoord === (int)$cc['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cc['nome']) ?>
                    <?= (int)$cc['ativo'] === 0 ? ' (inativo)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo">
        <label for="f-lider">Liderança</label>
        <select name="lider" id="f-lider">
            <option value="0">— Todas as lideranças —</option>
            <?php foreach ($listaLideres as $ll): ?>
                <option value="<?= (int)$ll['id'] ?>"
                        data-coord="<?= (int)$ll['coordenador_id'] ?>"
                    <?= $filtroLider === (int)$ll['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ll['nome']) ?>
                    <?= (int)$ll['ativo'] === 0 ? ' (inativa)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo" style="max-width:180px;">
        <label for="f-status">Status</label>
        <select name="status" id="f-status">
            <option value="todas"    <?= $filtroStatus === 'todas'    ? 'selected' : '' ?>>Todas</option>
            <option value="ativas"   <?= $filtroStatus === 'ativas'   ? 'selected' : '' ?>>Só ativas</option>
            <option value="inativas" <?= $filtroStatus === 'inativas' ? 'selected' : '' ?>>Só inativas</option>
        </select>
    </div>

    <div class="acoes">
        <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
        <a href="relatorio_desempenho.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<!-- Badges dos filtros ativos -->
<?php if ($temFiltro): ?>
<div class="no-print" style="margin-bottom:16px;">
    <span style="font-size:12px; color:#666; margin-right:8px;">Filtros ativos:</span>

    <?php if ($filtroCand !== ''): ?>
        <span class="badge-filtro">
            🗳 <?= htmlspecialchars($filtroCand) ?>
            <a class="x" href="?<?= http_build_query(['cand' => '', 'coord' => 0, 'lider' => 0, 'status' => $filtroStatus]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($filtroCoord > 0): ?>
        <span class="badge-filtro">
            👔 <?= htmlspecialchars($nomeCoordFiltro) ?>
            <a class="x" href="?<?= http_build_query(['cand' => $filtroCand, 'coord' => 0, 'lider' => $filtroLider, 'status' => $filtroStatus]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($filtroLider > 0): ?>
        <span class="badge-filtro">
            👤 <?= htmlspecialchars($nomeLiderFiltro) ?>
            <a class="x" href="?<?= http_build_query(['cand' => $filtroCand, 'coord' => $filtroCoord, 'lider' => 0, 'status' => $filtroStatus]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($filtroStatus !== 'todas'): ?>
        <span class="badge-filtro">
            <?= $filtroStatus === 'ativas' ? '🟢 Só ativas' : '⚪ Só inativas' ?>
            <a class="x" href="?<?= http_build_query(['cand' => $filtroCand, 'coord' => $filtroCoord, 'lider' => $filtroLider, 'status' => 'todas']) ?>">×</a>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============================================================
     KPIs GERAIS
     ============================================================ -->
<div class="kpis">
    <div class="kpi azul">
        <div class="rotulo">Coordenadores</div>
        <div class="valor"><?= fmtNum($tot['coords']) ?></div>
        <div class="mini"><?= fmtNum($tot['coords_ativos']) ?> ativos</div>
    </div>
    <div class="kpi">
        <div class="rotulo">Lideranças</div>
        <div class="valor"><?= fmtNum($tot['liderancas']) ?></div>
        <div class="mini"><?= fmtNum($tot['liderancas_ativas']) ?> ativas</div>
    </div>
    <div class="kpi laranja">
        <div class="rotulo">Votos esperados</div>
        <div class="valor"><?= fmtNum($tot['esperado']) ?></div>
        <div class="mini">soma das lideranças ativas</div>
    </div>
    <div class="kpi teal">
        <div class="rotulo">Votos das urnas</div>
        <div class="valor"><?= fmtNum($tot['urnas']) ?></div>
    </div>
    <div class="kpi <?= $tot['diferenca'] >= 0 ? 'verde' : 'vermelho' ?>">
        <div class="rotulo">Diferença</div>
        <div class="valor"><?= ($tot['diferenca'] > 0 ? '+' : '') . fmtNum($tot['diferenca']) ?></div>
        <div class="mini"><?= fmtPct($tot['pct']) ?> do esperado</div>
    </div>
</div>

<h2 class="sec">Distribuição das lideranças por situação</h2>
<div class="kpis">
    <div class="kpi verde">
        <div class="rotulo">▲ Acima da meta</div>
        <div class="valor"><?= fmtNum($tot['acima']) ?></div>
    </div>
    <div class="kpi verde">
        <div class="rotulo">✔ Na meta</div>
        <div class="valor"><?= fmtNum($tot['meta']) ?></div>
    </div>
    <div class="kpi amarelo">
        <div class="rotulo">≈ Quase (≥70%)</div>
        <div class="valor"><?= fmtNum($tot['quase']) ?></div>
    </div>
    <div class="kpi vermelho">
        <div class="rotulo">▼ Abaixo (&lt;70%)</div>
        <div class="valor"><?= fmtNum($tot['abaixo']) ?></div>
    </div>
    <div class="kpi">
        <div class="rotulo">— Sem meta</div>
        <div class="valor"><?= fmtNum($tot['sem']) ?></div>
    </div>
</div>

<!-- ============================================================
     RANKING DE COORDENADORES
     ============================================================ -->
<h2 class="sec">🏆 Ranking de coordenadores (por % de atingimento)</h2>

<?php if (!$rankCoord): ?>
    <div class="aviso">Nenhum coordenador ativo com meta definida para os filtros selecionados.</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th style="width:50px;" class="centro">#</th>
            <?php if ($temCandidatoCoord): ?><th>Candidato</th><?php endif; ?>
            <th>Coordenador</th>
            <th class="centro" style="width:70px;">Zonas</th>
            <th class="centro" style="width:70px;">Líderes</th>
            <th class="num" style="width:100px;">Esperado</th>
            <th class="num" style="width:100px;">Urnas</th>
            <th class="num" style="width:90px;">Diferença</th>
            <th style="width:200px;">Desempenho</th>
            <th class="centro" style="width:110px;">Situação</th>
            <th class="centro" style="width:180px;">Distribuição</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rankCoord as $i => $c): ?>
            <?php
            $pos = $i + 1;
            $med = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : $pos));
            $pct = $c['pct'] ?? 0;
            $largura = min(100, max(0, $pct));
            $cls = $pct >= 100 ? 'ok' : ($pct >= 70 ? 'med' : 'ruim');
            $dif = (int)$c['diferenca'];
            $linhaCls = $pos === 1 ? 'destaque-ouro'
                      : ($pos === 2 ? 'destaque-prata'
                      : ($pos === 3 ? 'destaque-bronze' : ''));
            ?>
            <tr class="<?= $linhaCls ?>">
                <td class="centro"><span class="medalha"><?= $med ?></span></td>
                <?php if ($temCandidatoCoord): ?>
                    <td>
                        <?php if (!empty($c['candidato'])): ?>
                            <span class="badge badge-cand"><?= htmlspecialchars($c['candidato']) ?></span>
                        <?php else: ?><span class="vazio">—</span><?php endif; ?>
                    </td>
                <?php endif; ?>
                <td><strong><?= htmlspecialchars($c['nome']) ?></strong></td>
                <td class="centro"><?= fmtNum($c['qtd_zonas']) ?></td>
                <td class="centro"><?= fmtNum($c['qtd_lid_ativ']) ?></td>
                <td class="num"><span class="badge badge-votos"><?= fmtNum($c['esperado']) ?></span></td>
                <td class="num"><span class="badge badge-urnas"><?= fmtNum($c['urnas']) ?></span></td>
                <td class="num">
                    <span class="badge <?= $dif > 0 ? 'badge-pos' : ($dif < 0 ? 'badge-neg' : 'badge-zero') ?>">
                        <?= ($dif > 0 ? '+' : '') . fmtNum($dif) ?>
                    </span>
                </td>
                <td>
                    <div class="barra">
                        <div class="fill <?= $cls ?>" style="width:<?= $largura ?>%"></div>
                        <div class="txt"><?= fmtPct($c['pct']) ?></div>
                    </div>
                </td>
                <td class="centro">
                    <span class="sit <?= $c['situacao']['classe'] ?>">
                        <span class="ico"><?= $c['situacao']['icone'] ?></span><?= $c['situacao']['rotulo'] ?>
                    </span>
                </td>
                <td class="centro" style="font-size:11px;">
                    <?php if ((int)$c['qtd_acima'] > 0): ?>
                        <span class="badge badge-pos">▲<?= (int)$c['qtd_acima'] ?></span>
                    <?php endif; ?>
                    <?php if ((int)$c['qtd_meta'] > 0): ?>
                        <span class="badge badge-pos">✔<?= (int)$c['qtd_meta'] ?></span>
                    <?php endif; ?>
                    <?php if ((int)$c['qtd_quase'] > 0): ?>
                        <span class="badge badge-pct-med">≈<?= (int)$c['qtd_quase'] ?></span>
                    <?php endif; ?>
                    <?php if ((int)$c['qtd_abaixo'] > 0): ?>
                        <span class="badge badge-neg">▼<?= (int)$c['qtd_abaixo'] ?></span>
                    <?php endif; ?>
                    <?php if ((int)$c['qtd_sem'] > 0): ?>
                        <span class="badge badge-zero">—<?= (int)$c['qtd_sem'] ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- ============================================================
     RANKING DE LIDERANÇAS
     ============================================================ -->
<h2 class="sec">🏆 Ranking de lideranças (por % de atingimento)</h2>

<?php if (!$rankLid): ?>
    <div class="aviso">Nenhuma liderança ativa com meta definida para os filtros selecionados.</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th style="width:50px;" class="centro">#</th>
            <th>Liderança</th>
            <th>Coordenador</th>
            <th class="num" style="width:100px;">Esperado</th>
            <th class="num" style="width:100px;">Urnas</th>
            <th class="num" style="width:90px;">Diferença</th>
            <th style="width:200px;">Desempenho</th>
            <th class="centro" style="width:110px;">Situação</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach (array_slice($rankLid, 0, 30) as $i => $l): ?>
            <?php
            $pos = $i + 1;
            $med = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : $pos));
            $pct = $l['pct'] ?? 0;
            $largura = min(100, max(0, $pct));
            $cls = $pct >= 100 ? 'ok' : ($pct >= 70 ? 'med' : 'ruim');
            $dif = (int)$l['diferenca'];
            $linhaCls = $pos === 1 ? 'destaque-ouro'
                      : ($pos === 2 ? 'destaque-prata'
                      : ($pos === 3 ? 'destaque-bronze' : ''));
            ?>
            <tr class="<?= $linhaCls ?>">
                <td class="centro"><span class="medalha"><?= $med ?></span></td>
                <td><strong><?= htmlspecialchars($l['nome']) ?></strong></td>
                <td><span class="badge badge-coord"><?= htmlspecialchars($l['coord_nome']) ?></span></td>
                <td class="num"><span class="badge badge-votos"><?= fmtNum($l['esperado']) ?></span></td>
                <td class="num"><span class="badge badge-urnas"><?= fmtNum($l['urnas']) ?></span></td>
                <td class="num">
                    <span class="badge <?= $dif > 0 ? 'badge-pos' : ($dif < 0 ? 'badge-neg' : 'badge-zero') ?>">
                        <?= ($dif > 0 ? '+' : '') . fmtNum($dif) ?>
                    </span>
                </td>
                <td>
                    <div class="barra">
                        <div class="fill <?= $cls ?>" style="width:<?= $largura ?>%"></div>
                        <div class="txt"><?= fmtPct($l['pct']) ?></div>
                    </div>
                </td>
                <td class="centro">
                    <span class="sit <?= $l['situacao']['classe'] ?>">
                        <span class="ico"><?= $l['situacao']['icone'] ?></span><?= $l['situacao']['rotulo'] ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($rankLid) > 30): ?>
            <tr>
                <td colspan="8" class="centro vazio">
                    ... <?= count($rankLid) - 30 ?> outras lideranças não exibidas
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- ============================================================
     SEÇÕES MAIS FORTES vs. DESCOBERTAS
     ============================================================ -->
<div class="grid-2">

    <div class="card">
        <h3>🔥 Top 15 seções (mais votos)</h3>
        <?php if (!$temLiderSecao): ?>
            <p class="vazio">Tabela <code>lider_secao</code> não existe.</p>
        <?php elseif (!$topSecoes): ?>
            <p class="vazio">Nenhuma seção vinculada para os filtros selecionados.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width:50px;" class="centro">#</th>
                        <th>Seção</th>
                        <th class="centro" style="width:70px;">Líderes</th>
                        <th class="num" style="width:100px;">Votos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topSecoes as $i => $s): ?>
                        <tr>
                            <td class="centro"><?= $i + 1 ?></td>
                            <td>
                                <span class="badge badge-zona">Zona <?= (int)$s['numeroZona'] ?></span>
                                <strong>Seção <?= (int)$s['numeroSecao'] ?></strong>
                            </td>
                            <td class="centro"><?= (int)$s['qtd_lideres'] ?></td>
                            <td class="num">
                                <span class="badge badge-urnas"><?= fmtNum($s['votos']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>⚠ Seções descobertas (sem liderança)</h3>
        <?php if (!$temLiderSecao): ?>
            <p class="vazio">Tabela <code>lider_secao</code> não existe.</p>
        <?php elseif ($filtroLider > 0): ?>
            <p class="vazio">Esta análise não se aplica quando há um líder específico selecionado.</p>
        <?php else: ?>
            <p style="margin:0 0 10px; font-size:12px; color:#666;">
                <strong><?= fmtNum($qtdDescobertasTotal) ?></strong> seções ·
                <strong><?= fmtNum($votosDescobertosTotal) ?></strong> votos sem cobertura
                <?= $filtroCoord > 0 ? ' (zonas do coordenador filtrado)' : '' ?>
            </p>
            <?php if (!$secoesDescobertas): ?>
                <p class="vazio">Parabéns! Todas as seções têm liderança cadastrada.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:50px;" class="centro">#</th>
                            <th>Seção</th>
                            <th class="num" style="width:100px;">Votos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($secoesDescobertas as $i => $s): ?>
                            <tr>
                                <td class="centro"><?= $i + 1 ?></td>
                                <td>
                                    <span class="badge badge-zona">Zona <?= (int)$s['numeroZona'] ?></span>
                                    <strong>Seção <?= (int)$s['numeroSecao'] ?></strong>
                                </td>
                                <td class="num">
                                    <span class="badge badge-urnas"><?= fmtNum($s['votos']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<!-- ============================================================
     TABELA ANALÍTICA
     ============================================================ -->
<h2 class="sec">📋 Análise detalhada por coordenador</h2>

<table>
    <thead>
        <tr>
            <?php if ($temCandidatoCoord): ?><th>Candidato</th><?php endif; ?>
            <th>Coordenador</th>
            <th class="centro" style="width:60px;">Status</th>
            <th class="centro" style="width:70px;">Líderes</th>
            <th class="num" style="width:100px;">Esperado</th>
            <th class="num" style="width:100px;">Urnas</th>
            <th class="num" style="width:90px;">Diferença</th>
            <th class="centro" style="width:80px;">%</th>
            <th class="centro" style="width:70px;">▲ Acima</th>
            <th class="centro" style="width:70px;">✔ Meta</th>
            <th class="centro" style="width:70px;">≈ Quase</th>
            <th class="centro" style="width:70px;">▼ Abaixo</th>
            <th class="centro" style="width:70px;">— Sem</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$coordenadores): ?>
            <tr><td colspan="<?= $temCandidatoCoord ? 13 : 12 ?>" class="centro vazio" style="padding:20px;">
                Nenhum coordenador para os filtros selecionados.
            </td></tr>
        <?php else: ?>
            <?php foreach ($coordenadores as $c): ?>
                <?php $dif = (int)$c['diferenca']; ?>
                <tr>
                    <?php if ($temCandidatoCoord): ?>
                        <td>
                            <?php if (!empty($c['candidato'])): ?>
                                <span class="badge badge-cand"><?= htmlspecialchars($c['candidato']) ?></span>
                            <?php else: ?><span class="vazio">—</span><?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td><strong><?= htmlspecialchars($c['nome']) ?></strong></td>
                    <td class="centro">
                        <?= (int)$c['ativo'] === 1
                            ? '<span class="badge badge-ativo">Ativo</span>'
                            : '<span class="badge badge-inativo">Inativo</span>' ?>
                    </td>
                    <td class="centro"><?= fmtNum($c['qtd_lid_ativ']) ?></td>
                    <td class="num"><span class="badge badge-votos"><?= fmtNum($c['esperado']) ?></span></td>
                    <td class="num"><span class="badge badge-urnas"><?= fmtNum($c['urnas']) ?></span></td>
                    <td class="num">
                        <span class="badge <?= $dif > 0 ? 'badge-pos' : ($dif < 0 ? 'badge-neg' : 'badge-zero') ?>">
                            <?= ($dif > 0 ? '+' : '') . fmtNum($dif) ?>
                        </span>
                    </td>
                    <td class="centro">
                        <?php if ($c['pct'] !== null): ?>
                            <span class="badge <?= $c['pct'] >= 100 ? 'badge-pct-ok' : ($c['pct'] >= 70 ? 'badge-pct-med' : 'badge-pct-ruim') ?>">
                                <?= fmtPct($c['pct']) ?>
                            </span>
                        <?php else: ?><span class="vazio">—</span><?php endif; ?>
                    </td>
                    <td class="centro"><?= (int)$c['qtd_acima']  ?: '<span class="vazio">0</span>' ?></td>
                    <td class="centro"><?= (int)$c['qtd_meta']   ?: '<span class="vazio">0</span>' ?></td>
                    <td class="centro"><?= (int)$c['qtd_quase']  ?: '<span class="vazio">0</span>' ?></td>
                    <td class="centro"><?= (int)$c['qtd_abaixo'] ?: '<span class="vazio">0</span>' ?></td>
                    <td class="centro"><?= (int)$c['qtd_sem']    ?: '<span class="vazio">0</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="linha-total">
                <td colspan="<?= $temCandidatoCoord ? 4 : 3 ?>" style="text-align:right;">Total geral</td>
                <td class="num"><?= fmtNum($tot['esperado']) ?></td>
                <td class="num"><?= fmtNum($tot['urnas']) ?></td>
                <td class="num"><?= ($tot['diferenca'] > 0 ? '+' : '') . fmtNum($tot['diferenca']) ?></td>
                <td class="centro"><?= fmtPct($tot['pct']) ?></td>
                <td class="centro"><?= fmtNum($tot['acima']) ?></td>
                <td class="centro"><?= fmtNum($tot['meta']) ?></td>
                <td class="centro"><?= fmtNum($tot['quase']) ?></td>
                <td class="centro"><?= fmtNum($tot['abaixo']) ?></td>
                <td class="centro"><?= fmtNum($tot['sem']) ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- ============================================================
     LEGENDA
     ============================================================ -->
<h2 class="sec">Legenda das classificações</h2>
<table>
    <thead>
        <tr>
            <th style="width:130px;">Situação</th>
            <th style="width:230px;">Regra</th>
            <th>Significado</th>
        </tr>
    </thead>
    <tbody>
        <tr><td><span class="sit sit-acima"><span class="ico">▲</span>Acima</span></td>
            <td>Urnas <strong>maior</strong> que a meta</td>
            <td>A liderança superou a expectativa de votos</td></tr>
        <tr><td><span class="sit sit-meta"><span class="ico">✔</span>Na meta</span></td>
            <td>Urnas <strong>igual</strong> à meta</td>
            <td>Atingiu exatamente o esperado</td></tr>
        <tr><td><span class="sit sit-quase"><span class="ico">≈</span>Quase</span></td>
            <td>Urnas entre <strong>70% e 99%</strong> da meta</td>
            <td>Atingiu boa parte, mas ainda abaixo</td></tr>
        <tr><td><span class="sit sit-abaixo"><span class="ico">▼</span>Abaixo</span></td>
            <td>Urnas <strong>menor</strong> que 70% da meta</td>
            <td>Desempenho muito abaixo do esperado</td></tr>
        <tr><td><span class="sit sit-sem">— Sem meta</span></td>
            <td>Não há expectativa cadastrada</td>
            <td>Sem base para avaliar desempenho</td></tr>
    </tbody>
</table>

<p style="font-size:11px; color:#888; margin-top:30px; text-align:center;">
    Relatório gerado automaticamente · <?= date('d/m/Y \à\s H:i') ?>
</p>

<script>
    // ============================================================
    // Cascata: Candidato → Coordenador → Liderança
    // ============================================================
    (function () {
        const selCand  = document.getElementById('f-cand');
        const selCoord = document.getElementById('f-coord');
        const selLider = document.getElementById('f-lider');

        const opcoesCoord = Array.from(selCoord.querySelectorAll('option'))
            .filter(op => op.value !== '0')
            .map(op => ({
                value: op.value,
                text:  op.textContent,
                cand:  op.dataset.cand || ''
            }));

        const opcoesLider = Array.from(selLider.querySelectorAll('option'))
            .filter(op => op.value !== '0')
            .map(op => ({
                value: op.value,
                text:  op.textContent,
                coord: op.dataset.coord
            }));

        function repopularCoords() {
            if (!selCand) return;
            const candSel = selCand.value;
            const coordAtual = selCoord.value;

            selCoord.innerHTML = '<option value="0">— Todos os coordenadores —</option>';
            opcoesCoord.forEach(o => {
                if (candSel && o.cand !== candSel) return;
                const opt = document.createElement('option');
                opt.value = o.value;
                opt.textContent = o.text;
                opt.dataset.cand = o.cand;
                if (o.value === coordAtual) opt.selected = true;
                selCoord.appendChild(opt);
            });
            if (coordAtual !== '0'
                && !selCoord.querySelector(`option[value="${coordAtual}"]`)) {
                selCoord.value = '0';
            }
            repopularLideres();
        }

        function repopularLideres() {
            const coordSel = selCoord.value;
            const liderAtual = selLider.value;

            selLider.innerHTML = '<option value="0">— Todas as lideranças —</option>';
            opcoesLider.forEach(o => {
                if (coordSel !== '0' && o.coord !== coordSel) return;
                const opt = document.createElement('option');
                opt.value = o.value;
                opt.textContent = o.text;
                opt.dataset.coord = o.coord;
                if (o.value === liderAtual) opt.selected = true;
                selLider.appendChild(opt);
            });
            if (liderAtual !== '0'
                && !selLider.querySelector(`option[value="${liderAtual}"]`)) {
                selLider.value = '0';
            }
        }

        if (selCand) selCand.addEventListener('change', repopularCoords);
        selCoord.addEventListener('change', repopularLideres);

        selLider.addEventListener('change', function () {
            const opt = this.options[this.selectedIndex];
            if (!opt) return;
            const coord = opt.dataset.coord;
            if (coord && coord !== '0') {
                selCoord.value = coord;
                repopularLideres();
                selLider.value = opt.value;
            }
        });
    })();
</script>

</body>
</html>