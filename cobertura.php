<?php
// ------------------------------------------------------------
// cobertura.php
// Cobertura de seções — o que ninguém está trabalhando
// Prioriza seções com VOTOS do candidato na urna e SEM liderança.
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
// DETECÇÃO DE COLUNAS / TABELAS
// ============================================================

// ---------- coluna de votos em `votos` ----------
$colunasVotos = [];
foreach ($pdo->query("SHOW COLUMNS FROM votos") as $c) {
    $colunasVotos[] = $c['Field'];
}
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

// ---------- tabela lider_secao existe? ----------
$temLiderSecao = false;
try { $pdo->query("SELECT 1 FROM lider_secao LIMIT 1"); $temLiderSecao = true; }
catch (Exception $e) { $temLiderSecao = false; }

// ============================================================
// FILTROS
// ============================================================
$filtroCandidato = trim($_GET['candidato'] ?? '');
$filtroCargo     = trim($_GET['cargo']     ?? '');
$filtroMunicipio = trim($_GET['municipio'] ?? '');
$filtroZona      = (int)($_GET['zona']     ?? 0);
$soDescobertas   = isset($_GET['so_descobertas']) && $_GET['so_descobertas'] === '1';

// ============================================================
// LISTAS PARA OS SELECTS
// ============================================================
$listaCandidatos = $pdo->query(
    "SELECT DISTINCT nomeCandidatoResp
       FROM votos
      WHERE nomeCandidatoResp IS NOT NULL AND nomeCandidatoResp <> ''
      ORDER BY nomeCandidatoResp"
)->fetchAll(PDO::FETCH_COLUMN);

$listaCargos = $pdo->query(
    "SELECT DISTINCT nomeCargoPerg
       FROM votos
      WHERE nomeCargoPerg IS NOT NULL AND nomeCargoPerg <> ''
      ORDER BY nomeCargoPerg"
)->fetchAll(PDO::FETCH_COLUMN);

// Municípios (vindos da base de seções, não de votos)
$listaMunicipios = $pdo->query(
    "SELECT DISTINCT nomeMunicipio
       FROM secoes_enderecos
      WHERE nomeMunicipio IS NOT NULL AND nomeMunicipio <> ''
      ORDER BY nomeMunicipio"
)->fetchAll(PDO::FETCH_COLUMN);

// Zonas — depende do município
$listaZonas = [];
if ($filtroMunicipio !== '') {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT numeroZona
           FROM secoes_enderecos
          WHERE nomeMunicipio = :m
          ORDER BY numeroZona"
    );
    $stmt->execute([':m' => $filtroMunicipio]);
    $listaZonas = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $listaZonas = $pdo->query(
        "SELECT DISTINCT numeroZona FROM secoes_enderecos ORDER BY numeroZona"
    )->fetchAll(PDO::FETCH_COLUMN);
}

// ============================================================
// QUERY PRINCIPAL
// ============================================================
// Estratégia:
//   - Base = secoes_enderecos (a "verdade" do território)
//   - LEFT JOIN em votos agregados por (zona, seção) do candidato/cargo
//   - LEFT JOIN em lider_secao agregando nomes de liderança + coordenador
// Sem subqueries correlacionadas — derived tables puras.

// --- Subquery de votos do candidato ---
$whereVotos = "WHERE 1=1";
$paramsVotos = [];
if ($filtroCandidato !== '') {
    $whereVotos .= " AND nomeCandidatoResp = :v_cand";
    $paramsVotos[':v_cand'] = $filtroCandidato;
}
if ($filtroCargo !== '') {
    $whereVotos .= " AND nomeCargoPerg = :v_cargo";
    $paramsVotos[':v_cargo'] = $filtroCargo;
}
$subVotos = "
    (SELECT numeroZona, numeroSecao, SUM(`$COL_VOTOS`) AS votos_cand
       FROM votos
       $whereVotos
       GROUP BY numeroZona, numeroSecao) v
";

// --- Subquery de lideranças por seção ---
if ($temLiderSecao) {
    $subLider = "
        (SELECT
            ls.numeroZona,
            ls.numeroSecao,
            COUNT(DISTINCT ls.lider_id) AS qtd_liderancas,
            GROUP_CONCAT(DISTINCT l.nome ORDER BY l.nome SEPARATOR ' • ') AS liderancas,
            GROUP_CONCAT(DISTINCT c.nome ORDER BY c.nome SEPARATOR ' • ') AS coordenadores
          FROM lider_secao ls
          INNER JOIN liderancas l ON l.id = ls.lider_id
          LEFT JOIN coordenadores c ON c.id = l.coordenador_id
          GROUP BY ls.numeroZona, ls.numeroSecao) ls
    ";
} else {
    // tabela não existe — cria um "vazio"
    $subLider = "(SELECT 1 AS numeroZona, 1 AS numeroSecao,
                        0 AS qtd_liderancas,
                        NULL AS liderancas,
                        NULL AS coordenadores
                  WHERE 1=0) ls";
}

// --- Filtros no SELECT externo ---
$whereSec = [];
$paramsSec = [];
if ($filtroMunicipio !== '') {
    $whereSec[] = "s.nomeMunicipio = :s_mun";
    $paramsSec[':s_mun'] = $filtroMunicipio;
}
if ($filtroZona > 0) {
    $whereSec[] = "s.numeroZona = :s_zona";
    $paramsSec[':s_zona'] = $filtroZona;
}
if ($soDescobertas) {
    $whereSec[] = "COALESCE(ls.qtd_liderancas, 0) = 0";
}
$whereSecStr = $whereSec ? 'WHERE ' . implode(' AND ', $whereSec) : '';

// --- Ordenação: prioriza seções com votos do candidato e sem liderança ---
// Se candidato está definido: votos desc, aptos desc.
// Se não: aptos desc, votos desc.
if ($filtroCandidato !== '') {
    $orderBy = "COALESCE(v.votos_cand, 0) DESC, s.qtdAptos DESC,
                s.numeroZona, s.numeroSecao";
} else {
    $orderBy = "s.qtdAptos DESC, COALESCE(v.votos_cand, 0) DESC,
                s.numeroZona, s.numeroSecao";
}

$sql = "
    SELECT
        s.numeroZona,
        s.numeroSecao,
        s.nomeMunicipio,
        s.nomeLocal,
        s.qtdAptos,
        s.latitude,
        s.longitude,
        COALESCE(v.votos_cand, 0)      AS votos_cand,
        COALESCE(ls.qtd_liderancas, 0) AS qtd_liderancas,
        ls.liderancas,
        ls.coordenadores
    FROM secoes_enderecos s
    LEFT JOIN $subVotos
           ON v.numeroZona  = s.numeroZona
          AND v.numeroSecao = s.numeroSecao
    LEFT JOIN $subLider
           ON ls.numeroZona  = s.numeroZona
          AND ls.numeroSecao = s.numeroSecao
    $whereSecStr
    ORDER BY $orderBy
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($paramsSec, $paramsVotos));
$secoes = $stmt->fetchAll();

// ============================================================
// PÓS-PROCESSAMENTO + CLASSIFICAÇÃO
// ============================================================
$totais = [
    'secoes'           => 0,
    'aptos'            => 0,
    'votos_cand'       => 0,
    'cobertas'         => 0,
    'descobertas'      => 0,
    'votos_cobertos'   => 0,
    'votos_descobertos'=> 0,
    'saudavel'         => 0,
    'engajamento'      => 0,
    'oportunidade'     => 0,
    'vazia'            => 0,
];

// Agregação por zona
$porZona = [];

foreach ($secoes as &$s) {
    $temLider  = (int)$s['qtd_liderancas'] > 0;
    $temVoto   = (int)$s['votos_cand'] > 0;

    if ($temLider && $temVoto) {
        $s['status'] = 'saudavel';
        $s['status_rotulo'] = 'Saudável';
        $s['status_cor']    = 'status-ok';
    } elseif ($temLider && !$temVoto) {
        $s['status'] = 'engajamento';
        $s['status_rotulo'] = 'Sem voto';
        $s['status_cor']    = 'status-med';
    } elseif (!$temLider && $temVoto) {
        $s['status'] = 'oportunidade';
        $s['status_rotulo'] = 'Oportunidade';
        $s['status_cor']    = 'status-laranja';
    } else {
        $s['status'] = 'vazia';
        $s['status_rotulo'] = 'Sem nada';
        $s['status_cor']    = 'status-cinza';
    }

    $totais['secoes']++;
    $totais['aptos']      += (int)$s['qtdAptos'];
    $totais['votos_cand'] += (int)$s['votos_cand'];

    if ($temLider) {
        $totais['cobertas']++;
        $totais['votos_cobertos'] += (int)$s['votos_cand'];
    } else {
        $totais['descobertas']++;
        $totais['votos_descobertos'] += (int)$s['votos_cand'];
    }

    $totais[$s['status']]++;

    // Agrega por zona
    $z = (int)$s['numeroZona'];
    if (!isset($porZona[$z])) {
        $porZona[$z] = [
            'zona'              => $z,
            'secoes'            => 0,
            'cobertas'          => 0,
            'descobertas'       => 0,
            'aptos'             => 0,
            'votos_cand'        => 0,
            'votos_descobertos' => 0,
        ];
    }
    $porZona[$z]['secoes']++;
    $porZona[$z]['aptos']      += (int)$s['qtdAptos'];
    $porZona[$z]['votos_cand'] += (int)$s['votos_cand'];
    if ($temLider) {
        $porZona[$z]['cobertas']++;
    } else {
        $porZona[$z]['descobertas']++;
        $porZona[$z]['votos_descobertos'] += (int)$s['votos_cand'];
    }
}
unset($s);

// Ordena zonas por votos descobertos desc (maior oportunidade primeiro)
uasort($porZona, function ($a, $b) {
    return $b['votos_descobertos'] <=> $a['votos_descobertos']
        ?: $b['secoes'] <=> $a['secoes'];
});

$pctCobertura = $totais['secoes'] > 0
    ? round(100 * $totais['cobertas'] / $totais['secoes'], 1)
    : null;

$pctCoberturaVotos = $totais['votos_cand'] > 0
    ? round(100 * $totais['votos_cobertos'] / $totais['votos_cand'], 1)
    : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Cobertura de Seções</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom:4px; }
    .subtitulo { color:#666; font-size:13px; margin-bottom:18px; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9;
                   text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    /* ============================================================
       CARD DO USUÁRIO LOGADO
       ============================================================ */
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

    /* Filtros */
    .filtros {
        background:#fff; padding:14px 18px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
        display:flex; flex-wrap:wrap; align-items:center; gap:12px;
    }
    .filtros label { font-weight:bold; font-size:13px; }
    .filtros select, .filtros button {
        padding:8px 10px; border-radius:4px; border:1px solid #ccc;
        font-size:14px; font-family:inherit;
    }
    .filtros .chk {
        display:flex; align-items:center; gap:6px;
        font-weight:normal; cursor:pointer; font-size:13px;
    }
    .filtros .chk input { width:auto; }
    .btn-consultar { background:#0069d9; color:#fff; border:none; cursor:pointer; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none;
                  padding:8px 14px; border-radius:4px; font-size:14px; }
    .btn-limpar:hover { background:#5a6268; }

    /* KPIs */
    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:14px 22px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:170px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666;
                   letter-spacing:1px; }
    .kpi .valor  { font-size:26px; font-weight:bold; color:#0069d9; margin-top:2px; }
    .kpi .sub    { font-size:11px; color:#888; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.teal    { border-left-color:#17a2b8; } .kpi.teal .valor  { color:#17a2b8; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi .valor.sm { font-size:16px; line-height:1.3; word-break:break-word; }

    /* Legenda de status */
    .legenda {
        background:#fff; padding:10px 16px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:20px;
        display:flex; flex-wrap:wrap; gap:14px; font-size:12px;
        align-items:center;
    }
    .legenda .item { display:flex; align-items:center; gap:6px; }
    .legenda .bolinha {
        width:12px; height:12px; border-radius:50%; display:inline-block;
    }
    .legenda .b-ok      { background:#28a745; }
    .legenda .b-med     { background:#ffc107; }
    .legenda .b-laranja { background:#fd7e14; }
    .legenda .b-cinza   { background:#adb5bd; }

    /* Tabela */
    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left;
             vertical-align:middle; font-size:13px; }
    th { background:#0069d9; color:#fff; font-size:12px;
         text-transform:uppercase; letter-spacing:.5px; }
    th.num, td.num { text-align:right; }
    th.centro, td.centro { text-align:center; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#eef6ff; }

    tr.linha-oportunidade { background:#fff4e6 !important; }
    tr.linha-oportunidade:hover { background:#ffe8cc !important; }
    tr.linha-engajamento  { background:#fff9e0 !important; }
    tr.linha-vazia        { color:#999; }
    tr.linha-saudavel     { background:#f1fbf4 !important; }
    tr.linha-saudavel:hover { background:#e3f7e9 !important; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-zona    { background:#e6f0fa; color:#0069d9; }
    .badge-coord   { background:#f0e6ff; color:#6f42c1; }
    .badge-lider   { background:#e7d9ff; color:#4a1d96; }
    .badge-votos   { background:#fff0e0; color:#c25b00; }
    .badge-urnas   { background:#d9edf7; color:#0c5460; }
    .badge-aptos   { background:#e9ecef; color:#495057; }
    .badge-oport   { background:#fd7e14; color:#fff; }
    .badge-semvoto { background:#ffc107; color:#5a4300; }
    .badge-ok      { background:#d4f5dd; color:#1c7a34; }
    .badge-vazio   { background:#e9ecef; color:#6c757d; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    .status-dot {
        display:inline-block; width:10px; height:10px;
        border-radius:50%; margin-right:6px;
        vertical-align:middle;
    }
    .status-ok      .status-dot { background:#28a745; }
    .status-med     .status-dot { background:#ffc107; }
    .status-laranja .status-dot { background:#fd7e14; }
    .status-cinza   .status-dot { background:#adb5bd; }

    .info-col {
        font-size:12px; color:#666; margin-bottom:14px;
        background:#fff; padding:8px 14px; border-radius:6px;
        border-left:4px solid #17a2b8;
        box-shadow: 0 1px 2px rgba(0,0,0,.05);
    }
    .info-col code { background:#f4f4f4; padding:1px 5px; border-radius:3px; }
    .info-col .aviso { color:#856404; background:#fff3cd;
        padding:2px 8px; border-radius:6px; font-weight:bold; margin-left:6px; }

    .msg-vazia {
        background:#fff; padding:30px; border-radius:8px;
        text-align:center; color:#666;
        box-shadow:0 1px 3px rgba(0,0,0,.08);
    }

    /* Accordion por zona */
    details.zona-bloco {
        background:#fff; border-radius:8px; padding:10px 16px;
        margin-bottom:8px; box-shadow:0 1px 3px rgba(0,0,0,.06);
    }
    details.zona-bloco > summary {
        cursor:pointer; font-weight:bold; color:#0069d9;
        padding:6px 0; font-size:14px;
    }
    details.zona-bloco > summary:hover { text-decoration:underline; }
    details.zona-bloco > summary .resumo {
        font-weight:normal; color:#555; font-size:12.5px;
        margin-left:8px;
    }
    details.zona-bloco > summary .oportunidade {
        background:#fd7e14; color:#fff;
        padding:1px 8px; border-radius:8px;
        font-size:11px; font-weight:bold; margin-left:6px;
    }
    details.zona-bloco > summary .saudavel-badge {
        background:#28a745; color:#fff;
        padding:1px 8px; border-radius:8px;
        font-size:11px; font-weight:bold; margin-left:6px;
    }
    details.zona-bloco table {
        margin-top:8px; font-size:12.5px;
    }
    details.zona-bloco table th { font-size:11px; padding:6px 8px; }
    details.zona-bloco table td { padding:5px 8px; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<!-- ============================================================
     CARD DO USUÁRIO LOGADO
     ============================================================ -->
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

<h1>Cobertura de Seções</h1>
<div class="subtitulo">
    Onde o candidato <strong>tem voto mas ninguém está trabalhando</strong>.
    As seções são ordenadas pelos <strong>votos reais na urna</strong> —
    quanto mais votos sem liderança, maior a oportunidade perdida.
</div>

<p class="info-col">
    Coluna de votos: <code><?= htmlspecialchars($COL_VOTOS) ?></code>
    &nbsp;·&nbsp;
    Tabela <code>lider_secao</code>:
    <?php if ($temLiderSecao): ?>
        <strong>existe</strong>
    <?php else: ?>
        <span class="aviso">NÃO EXISTE — todas as seções aparecerão como descobertas</span>
    <?php endif; ?>
    &nbsp;·&nbsp;
    Base de território: <code>secoes_enderecos</code>
</p>

<!-- ============================================================
     FILTROS
     ============================================================ -->
<form method="get" action="cobertura.php" class="filtros">
    <label for="candidato">Candidato:</label>
    <select name="candidato" id="candidato" style="min-width:240px;">
        <option value="">— Todos (votos = 0) —</option>
        <?php foreach ($listaCandidatos as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"
                <?= $c === $filtroCandidato ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="cargo">Cargo:</label>
    <select name="cargo" id="cargo" style="min-width:160px;">
        <option value="">— Todos —</option>
        <?php foreach ($listaCargos as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"
                <?= $c === $filtroCargo ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="municipio">Município:</label>
    <select name="municipio" id="municipio" style="min-width:200px;">
        <option value="">— Todos —</option>
        <?php foreach ($listaMunicipios as $m): ?>
            <option value="<?= htmlspecialchars($m) ?>"
                <?= $m === $filtroMunicipio ? 'selected' : '' ?>>
                <?= htmlspecialchars($m) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="zona">Zona:</label>
    <select name="zona" id="zona" style="min-width:120px;">
        <option value="0">Todas</option>
        <?php foreach ($listaZonas as $z): ?>
            <option value="<?= (int)$z ?>"
                <?= (int)$z === $filtroZona ? 'selected' : '' ?>>
                <?= htmlspecialchars($z) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="chk">
        <input type="checkbox" name="so_descobertas" value="1"
            <?= $soDescobertas ? 'checked' : '' ?>>
        Só mostrar descobertas
    </label>

    <button type="submit" class="btn-consultar">🔎 Aplicar</button>
    <a href="cobertura.php" class="btn-limpar">✖ Limpar</a>
</form>

<?php if (!$secoes): ?>

    <div class="msg-vazia">
        <p>Nenhuma seção encontrada para os filtros aplicados.</p>
    </div>

<?php else: ?>

    <!-- ============================================================
         KPIs
         ============================================================ -->
    <div class="kpis">
        <div class="kpi">
            <div class="rotulo">Seções no território</div>
            <div class="valor"><?= number_format($totais['secoes'], 0, ',', '.') ?></div>
            <div class="sub"><?= number_format($totais['aptos'], 0, ',', '.') ?> aptos</div>
        </div>

        <div class="kpi <?= $pctCobertura !== null && $pctCobertura >= 70 ? 'verde' : 'laranja' ?>">
            <div class="rotulo">Cobertura</div>
            <div class="valor"><?= $pctCobertura !== null ? number_format($pctCobertura, 1, ',', '.') . '%' : '—' ?></div>
            <div class="sub">
                <?= number_format($totais['cobertas'], 0, ',', '.') ?> com liderança
                · <?= number_format($totais['descobertas'], 0, ',', '.') ?> sem
            </div>
        </div>

        <div class="kpi teal">
            <div class="rotulo">Votos do candidato</div>
            <div class="valor"><?= number_format($totais['votos_cand'], 0, ',', '.') ?></div>
            <div class="sub">
                <?= $pctCoberturaVotos !== null
                    ? number_format($pctCoberturaVotos, 1, ',', '.') . '% em seções cobertas'
                    : '—' ?>
            </div>
        </div>

        <div class="kpi laranja">
            <div class="rotulo">🔥 Oportunidades</div>
            <div class="valor"><?= number_format($totais['votos_descobertos'], 0, ',', '.') ?></div>
            <div class="sub">
                votos em seções <strong>sem liderança</strong>
                (<?= number_format($totais['oportunidade'], 0, ',', '.') ?> seções)
            </div>
        </div>

        <div class="kpi cinza">
            <div class="rotulo">Sem voto nem líder</div>
            <div class="valor"><?= number_format($totais['vazia'], 0, ',', '.') ?></div>
            <div class="sub">território bruto inexplorado</div>
        </div>

        <?php if ($totais['engajamento'] > 0): ?>
            <div class="kpi vermelho">
                <div class="rotulo">⚠️ Líder sem voto</div>
                <div class="valor"><?= number_format($totais['engajamento'], 0, ',', '.') ?></div>
                <div class="sub">seções com liderança mas zero voto</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================
         LEGENDA DE STATUS
         ============================================================ -->
    <div class="legenda">
        <strong>Legenda:</strong>
        <span class="item"><span class="bolinha b-laranja"></span>
            <span><strong>Oportunidade</strong> — tem voto, mas ninguém trabalha</span></span>
        <span class="item"><span class="bolinha b-med"></span>
            <span><strong>Sem voto</strong> — tem liderança, mas não converteu</span></span>
        <span class="item"><span class="bolinha b-ok"></span>
            <span><strong>Saudável</strong> — liderança + votos</span></span>
        <span class="item"><span class="bolinha b-cinza"></span>
            <span><strong>Sem nada</strong> — território bruto</span></span>
    </div>

    <!-- ============================================================
         TABELA DETALHADA
         ============================================================ -->
    <table>
        <thead>
            <tr>
                <th class="centro" style="width:40px;">#</th>
                <th style="width:70px;">Zona</th>
                <th style="width:80px;">Seção</th>
                <th>Município / Local</th>
                <th class="num" style="width:90px;">Aptos</th>
                <th class="num" style="width:110px;">Votos cand.</th>
                <th>Liderança / Coordenador</th>
                <th style="width:120px;">Situação</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $i = 1;
            foreach ($secoes as $s):
                $linhaCls = '';
                if ($s['status'] === 'oportunidade') $linhaCls = 'linha-oportunidade';
                elseif ($s['status'] === 'engajamento') $linhaCls = 'linha-engajamento';
                elseif ($s['status'] === 'saudavel')   $linhaCls = 'linha-saudavel';
                elseif ($s['status'] === 'vazia')      $linhaCls = 'linha-vazia';
            ?>
                <tr class="<?= $linhaCls ?>">
                    <td class="centro"><?= $i++ ?></td>
                    <td><strong><?= htmlspecialchars($s['numeroZona']) ?></strong></td>
                    <td><strong><?= htmlspecialchars($s['numeroSecao']) ?></strong></td>
                    <td>
                        <?php if (!empty($s['nomeMunicipio'])): ?>
                            <div style="font-weight:bold; color:#0069d9; font-size:12px;">
                                <?= htmlspecialchars($s['nomeMunicipio']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($s['nomeLocal'])): ?>
                            <div style="font-size:12px; color:#555;">
                                <?= htmlspecialchars($s['nomeLocal']) ?>
                            </div>
                        <?php else: ?>
                            <span class="vazio">local não cadastrado</span>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <?php if ((int)$s['qtdAptos'] > 0): ?>
                            <span class="badge badge-aptos"><?= number_format((int)$s['qtdAptos'], 0, ',', '.') ?></span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <?php if ((int)$s['votos_cand'] > 0): ?>
                            <span class="badge badge-urnas"><?= number_format((int)$s['votos_cand'], 0, ',', '.') ?></span>
                        <?php else: ?>
                            <span class="vazio">0</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['qtd_liderancas'] > 0): ?>
                            <div style="font-size:12.5px; margin-bottom:2px;">
                                <span class="badge badge-lider">👥 <?= (int)$s['qtd_liderancas'] ?></span>
                                <?= htmlspecialchars($s['liderancas'] ?? '') ?>
                            </div>
                            <?php if (!empty($s['coordenadores'])): ?>
                                <div style="font-size:11.5px; color:#6f42c1;">
                                    <strong>Coord:</strong> <?= htmlspecialchars($s['coordenadores']) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="vazio">— sem liderança —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-dot"></span>
                        <span class="<?= $s['status_cor'] ?>" style="font-weight:bold; font-size:12px;">
                            <?= htmlspecialchars($s['status_rotulo']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ============================================================
         VISÃO AGREGADA POR ZONA
         ============================================================ -->
    <h2 style="margin-top:30px;">Visão por zona</h2>
    <p style="font-size:12.5px; color:#666; margin-bottom:14px;">
        Zonas ordenadas por <strong>votos sem liderança</strong> — as do topo são as
        que mais têm a ganhar com uma ação de cobertura.
    </p>

    <?php foreach ($porZona as $z):
        $pctCobZona = $z['secoes'] > 0
            ? round(100 * $z['cobertas'] / $z['secoes'], 1)
            : null;
        $todasCobertas = ($z['descobertas'] === 0);
    ?>
        <details class="zona-bloco" <?= $z['votos_descobertos'] > 0 ? 'open' : '' ?>>
            <summary>
                Zona <?= (int)$z['zona'] ?>
                <?php if ($todasCobertas): ?>
                    <span class="saudavel-badge">✔ 100% coberta</span>
                <?php elseif ($z['votos_descobertos'] > 0): ?>
                    <span class="oportunidade">
                        🔥 <?= number_format($z['votos_descobertos'], 0, ',', '.') ?> votos sem líder
                    </span>
                <?php endif; ?>
                <span class="resumo">
                    <?= $z['secoes'] ?> seções ·
                    <?= $z['cobertas'] ?> cobertas ·
                    <?= $z['descobertas'] ?> descobertas
                    <?php if ($pctCobZona !== null): ?>
                        (<?= number_format($pctCobZona, 1, ',', '.') ?>% de cobertura)
                    <?php endif; ?>
                </span>
            </summary>

            <table>
                <thead>
                    <tr>
                        <th class="centro">Seções</th>
                        <th class="centro">Cobertas</th>
                        <th class="centro">Descobertas</th>
                        <th class="num">Aptos</th>
                        <th class="num">Votos cand.</th>
                        <th class="num">Votos sem líder</th>
                        <th class="centro">Cobertura</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="centro"><?= $z['secoes'] ?></td>
                        <td class="centro">
                            <span class="badge badge-ok"><?= $z['cobertas'] ?></span>
                        </td>
                        <td class="centro">
                            <?php if ($z['descobertas'] > 0): ?>
                                <span class="badge badge-oport"><?= $z['descobertas'] ?></span>
                            <?php else: ?>
                                <span class="vazio">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= number_format($z['aptos'], 0, ',', '.') ?></td>
                        <td class="num"><?= number_format($z['votos_cand'], 0, ',', '.') ?></td>
                        <td class="num">
                            <?php if ($z['votos_descobertos'] > 0): ?>
                                <span class="badge badge-oport">
                                    <?= number_format($z['votos_descobertos'], 0, ',', '.') ?>
                                </span>
                            <?php else: ?>
                                <span class="vazio">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="centro">
                            <?= $pctCobZona !== null
                                ? number_format($pctCobZona, 1, ',', '.') . '%'
                                : '—' ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </details>
    <?php endforeach; ?>

<?php endif; ?>

</body>
</html>