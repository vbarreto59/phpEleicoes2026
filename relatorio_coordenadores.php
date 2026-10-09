<?php
// ------------------------------------------------------------
// relatorio_coordenadores.php
// Relatório de coordenadores agrupado por candidato
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
if ($COL_VOTOS === null) die("Coluna de votos não detectada em `votos`.");

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

// ---------- tabela lider_secao existe? ----------
$temLiderSecao = false;
try { $pdo->query("SELECT 1 FROM lider_secao LIMIT 1"); $temLiderSecao = true; }
catch (Exception $e) { $temLiderSecao = false; }

// ============================================================
// HELPERS
// ============================================================
function fmtNum(int|float|null $n): string {
    return $n === null ? '—' : number_format((float)$n, 0, ',', '.');
}
function fmtPct(?float $p): string {
    return $p === null ? '—' : number_format($p, 1, ',', '.') . '%';
}
function classificarPct(?float $pct): array {
    if ($pct === null) return ['rotulo' => 'Sem meta', 'classe' => 'sit-sem'];
    if ($pct >= 100)   return ['rotulo' => 'Na meta',  'classe' => 'sit-ok'];
    if ($pct >= 70)    return ['rotulo' => 'Quase',    'classe' => 'sit-med'];
    return ['rotulo' => 'Abaixo', 'classe' => 'sit-ruim'];
}

// ============================================================
// FILTROS
// ============================================================
$filtroCand   = trim($_GET['cand']   ?? '');
$filtroStatus = $_GET['status']      ?? 'todos';   // todos | ativos | inativos
$busca        = trim($_GET['busca']  ?? '');

if (!in_array($filtroStatus, ['todos','ativos','inativos'], true)) {
    $filtroStatus = 'todos';
}

// Lista de candidatos
$listaCandidatos = [];
if ($temCandidatoCoord) {
    $listaCandidatos = $pdo->query(
        "SELECT DISTINCT nomeCandidatoResp FROM coordenadores
          WHERE nomeCandidatoResp IS NOT NULL AND nomeCandidatoResp <> ''
          ORDER BY nomeCandidatoResp"
    )->fetchAll(PDO::FETCH_COLUMN);
}

// ============================================================
// QUERY PRINCIPAL — COORDENADORES
// ============================================================
$where  = [];
$params = [];

if ($temCandidatoCoord && $filtroCand !== '') {
    $where[] = "c.nomeCandidatoResp = :fcand";
    $params[':fcand'] = $filtroCand;
}
if ($filtroStatus === 'ativos')   $where[] = "c.ativo = 1";
if ($filtroStatus === 'inativos') $where[] = "c.ativo = 0";
if ($busca !== '') {
    $where[] = "c.nome LIKE :fbusca";
    $params[':fbusca'] = '%' . $busca . '%';
}

$whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$colCand = $temCandidatoCoord ? "c.nomeCandidatoResp AS candidato," : "NULL AS candidato,";

$subCoordZonas = "(SELECT COUNT(DISTINCT cz.numeroZona) FROM coordenador_zona cz
                   WHERE cz.coordenador_id = c.id)";

$subLiderTotal = "(SELECT COUNT(*) FROM liderancas l WHERE l.coordenador_id = c.id)";
$subLiderAtiva = "(SELECT COUNT(*) FROM liderancas l WHERE l.coordenador_id = c.id AND l.ativo = 1)";

$subEsperado = "(SELECT COALESCE(SUM($EXPECT_SQL), 0)
                  FROM liderancas l
                 WHERE l.coordenador_id = c.id AND l.ativo = 1)";

$subUrnas = $temLiderSecao
    ? "(SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
          FROM votos v
         WHERE (v.numeroZona, v.numeroSecao) IN (
                SELECT DISTINCT ls.numeroZona, ls.numeroSecao
                  FROM lider_secao ls
                  INNER JOIN liderancas l2 ON l2.id = ls.lider_id
                 WHERE l2.coordenador_id = c.id AND l2.ativo = 1
         ))"
    : "0";

$sql = "
    SELECT c.id, c.nome, c.ativo,
           $colCand
           $subCoordZonas     AS qtd_zonas,
           $subLiderTotal     AS qtd_liderancas,
           $subLiderAtiva     AS qtd_liderancas_ativas,
           $subEsperado       AS votos_esperados,
           $subUrnas          AS votos_urnas
    FROM coordenadores c
    $whereStr
    ORDER BY candidato, c.nome
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$coordenadores = $stmt->fetchAll();

foreach ($coordenadores as &$c) {
    $esp = (int)$c['votos_esperados'];
    $urn = (int)$c['votos_urnas'];
    $c['diferenca'] = $urn - $esp;
    $c['pct']       = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
    $c['situacao']  = classificarPct($c['pct']);
}
unset($c);

// ============================================================
// AGRUPAMENTO POR CANDIDATO
// ============================================================
$porCandidato = [];
foreach ($coordenadores as $c) {
    $chave = ($c['candidato'] ?? '') !== '' ? $c['candidato'] : '(Sem candidato)';
    $porCandidato[$chave][] = $c;
}
ksort($porCandidato);

// ============================================================
// TOTAIS
// ============================================================
$totGeral = [
    'coordenadores' => count($coordenadores),
    'ativos'        => 0,
    'zonas'         => 0,
    'liderancas'    => 0,
    'liderancas_ativas' => 0,
    'esperado'      => 0,
    'urnas'         => 0,
];
foreach ($coordenadores as $c) {
    if ((int)$c['ativo'] === 1) $totGeral['ativos']++;
    $totGeral['zonas']              += (int)$c['qtd_zonas'];
    $totGeral['liderancas']         += (int)$c['qtd_liderancas'];
    $totGeral['liderancas_ativas']  += (int)$c['qtd_liderancas_ativas'];
    $totGeral['esperado']           += (int)$c['votos_esperados'];
    $totGeral['urnas']              += (int)$c['votos_urnas'];
}
$totGeral['diferenca'] = $totGeral['urnas'] - $totGeral['esperado'];
$totGeral['pct'] = $totGeral['esperado'] > 0
    ? round(($totGeral['urnas'] / $totGeral['esperado']) * 100, 1) : null;

// Totais por candidato
$totPorCand = [];
foreach ($porCandidato as $cand => $lista) {
    $t = [
        'coordenadores' => count($lista),
        'ativos'        => 0,
        'zonas'         => 0,
        'liderancas'    => 0,
        'liderancas_ativas' => 0,
        'esperado'      => 0,
        'urnas'         => 0,
    ];
    foreach ($lista as $c) {
        if ((int)$c['ativo'] === 1) $t['ativos']++;
        $t['zonas']             += (int)$c['qtd_zonas'];
        $t['liderancas']        += (int)$c['qtd_liderancas'];
        $t['liderancas_ativas'] += (int)$c['qtd_liderancas_ativas'];
        $t['esperado']          += (int)$c['votos_esperados'];
        $t['urnas']             += (int)$c['votos_urnas'];
    }
    $t['diferenca'] = $t['urnas'] - $t['esperado'];
    $t['pct'] = $t['esperado'] > 0 ? round(($t['urnas'] / $t['esperado']) * 100, 1) : null;
    $totPorCand[$cand] = $t;
}

$temFiltro = ($filtroCand !== '' || $filtroStatus !== 'todos' || $busca !== '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatório de Coordenadores</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom:4px; }
    .info-col { font-size:11.5px; color:#666; margin-bottom:16px; }
    .info-col code { background:#f4f4f4; padding:1px 5px; border-radius:3px; }
    .info-col .aviso { color:#856404; background:#fff3cd;
        padding:2px 8px; border-radius:6px; font-weight:bold; margin-left:6px; }

    /* Filtros */
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
    .filtros .campo select,
    .filtros .campo input[type=text] {
        padding:8px 10px; border:1px solid #ccc; border-radius:4px;
        font-size:13px; background:#fff; font-family:inherit; width:100%;
    }
    .filtros .campo select:focus,
    .filtros .campo input[type=text]:focus {
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

    /* KPIs */
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

    /* Seções por candidato */
    .cand-bloco {
        background:#fff; border-radius:10px; margin-bottom:24px;
        box-shadow: 0 2px 8px rgba(0,0,0,.08);
        overflow:hidden;
    }
    .cand-header {
        background: linear-gradient(90deg, #4a1d96, #6f42c1);
        color:#fff; padding:14px 22px;
        display:flex; align-items:center; justify-content:space-between;
        flex-wrap:wrap; gap:12px;
    }
    .cand-header .titulo {
        display:flex; align-items:center; gap:10px;
        font-size:16px; font-weight:bold;
    }
    .cand-header .titulo .ico { font-size:20px; }
    .cand-header .resumo {
        display:flex; gap:18px; flex-wrap:wrap;
        font-size:12.5px; opacity:.95;
    }
    .cand-header .resumo .item {
        display:flex; align-items:center; gap:5px;
    }
    .cand-header .resumo .item .num {
        font-weight:bold; font-size:14px;
    }
    .cand-header .resumo .item .lbl {
        font-weight:normal; opacity:.85;
    }

    .cand-bloco .cand-body { padding: 0; }

    /* Tabelas */
    table { border-collapse:collapse; width:100%; background:#fff; }
    th, td { border:1px solid #eee; padding:8px 10px; text-align:left; vertical-align:top; font-size:13px; }
    th { background:#f4f0fb; color:#4a1d96; font-size:11.5px; text-transform:uppercase; letter-spacing:.5px; }
    tr:hover { background:#faf7ff; }
    tr.linha-total { background:#f6f0ff; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #6f42c1; }
    .num { text-align:right; }
    .centro { text-align:center; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
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
    .sit-ok   { background:#28a745; color:#fff; }
    .sit-med  { background:#fff3cd; color:#856404; border:1px solid #f0d175; }
    .sit-ruim { background:#fdecea; color:#a71d2a; border:1px solid #e4a4ab; }
    .sit-sem  { background:#f0f0f0; color:#888; }

    .barra { position:relative; height:18px; background:#eee; border-radius:9px;
             overflow:hidden; min-width:110px; }
    .barra .fill { height:100%; transition:width .3s; }
    .barra .fill.ok   { background:linear-gradient(90deg,#28a745,#5ec27a); }
    .barra .fill.med  { background:linear-gradient(90deg,#ffc107,#ffdb70); }
    .barra .fill.ruim { background:linear-gradient(90deg,#dc3545,#e87682); }
    .barra .fill.zero { background:#ccc; }
    .barra .txt { position:absolute; inset:0; text-align:center;
                  font-size:10.5px; font-weight:bold; line-height:18px; color:#222; }

    .medalha { font-size:15px; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    .btn-imprimir { background:#0069d9; color:#fff; padding:8px 14px;
                    border-radius:4px; border:none; cursor:pointer;
                    font-size:14px; }
    .btn-imprimir:hover { background:#0053b3; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }

    @media print {
        .no-print { display:none !important; }
        body { background:#fff; margin:0; }
        .cand-bloco { box-shadow:none; page-break-inside: avoid; }
        .cand-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>

<a href="index.php" class="link-voltar no-print">&larr; Voltar ao menu</a>

<div style="display:flex; justify-content:space-between; align-items:flex-start;">
    <div>
        <h1>Relatório de Coordenadores</h1>
        <p class="info-col">
            Coluna de votos: <strong><?= htmlspecialchars($COL_VOTOS) ?></strong>
            &nbsp;·&nbsp;
            Expectativa em <code>liderancas</code>:
            <?php if ($COL_EXPECT): ?><strong><?= htmlspecialchars($COL_EXPECT) ?></strong>
            <?php else: ?><span class="aviso">não existe — usando 0</span><?php endif; ?>
            &nbsp;·&nbsp;
            Tabela <code>lider_secao</code>:
            <?php if ($temLiderSecao): ?><strong>existe</strong>
            <?php else: ?><span class="aviso">não existe</span><?php endif; ?>
            &nbsp;·&nbsp;
            Coluna <code>nomeCandidatoResp</code> em <code>coordenadores</code>:
            <?php if ($temCandidatoCoord): ?><strong>existe</strong>
            <?php else: ?><span class="aviso">NÃO EXISTE — agrupamento fica sem candidato</span><?php endif; ?>
            &nbsp;·&nbsp; Gerado em <?= date('d/m/Y H:i') ?>
        </p>
    </div>
    <button class="btn-imprimir no-print" onclick="window.print()">🖨 Imprimir</button>
</div>

<!-- ============================================================
     FILTROS
     ============================================================ -->
<form class="filtros no-print" method="get" action="relatorio_coordenadores.php">

    <?php if ($temCandidatoCoord && $listaCandidatos): ?>
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

    <div class="campo" style="max-width:200px;">
        <label for="f-status">Status</label>
        <select name="status" id="f-status">
            <option value="todos"    <?= $filtroStatus === 'todos'    ? 'selected' : '' ?>>Todos</option>
            <option value="ativos"   <?= $filtroStatus === 'ativos'   ? 'selected' : '' ?>>Só ativos</option>
            <option value="inativos" <?= $filtroStatus === 'inativos' ? 'selected' : '' ?>>Só inativos</option>
        </select>
    </div>

    <div class="campo">
        <label for="f-busca">Buscar por nome</label>
        <input type="text" name="busca" id="f-busca"
               placeholder="Digite parte do nome..."
               value="<?= htmlspecialchars($busca) ?>">
    </div>

    <div class="acoes">
        <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
        <a href="relatorio_coordenadores.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<?php if ($temFiltro): ?>
<div class="no-print" style="margin-bottom:16px;">
    <span style="font-size:12px; color:#666; margin-right:8px;">Filtros ativos:</span>

    <?php if ($filtroCand !== ''): ?>
        <span class="badge-filtro">
            🗳 <?= htmlspecialchars($filtroCand) ?>
            <a class="x" href="?<?= http_build_query(['cand' => '', 'status' => $filtroStatus, 'busca' => $busca]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($filtroStatus !== 'todos'): ?>
        <span class="badge-filtro">
            <?= $filtroStatus === 'ativos' ? '🟢 Só ativos' : '⚪ Só inativos' ?>
            <a class="x" href="?<?= http_build_query(['cand' => $filtroCand, 'status' => 'todos', 'busca' => $busca]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($busca !== ''): ?>
        <span class="badge-filtro">
            🔎 <?= htmlspecialchars($busca) ?>
            <a class="x" href="?<?= http_build_query(['cand' => $filtroCand, 'status' => $filtroStatus, 'busca' => '']) ?>">×</a>
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
        <div class="valor"><?= fmtNum($totGeral['coordenadores']) ?></div>
        <div class="mini"><?= fmtNum($totGeral['ativos']) ?> ativos</div>
    </div>
    <div class="kpi">
        <div class="rotulo">Lideranças</div>
        <div class="valor"><?= fmtNum($totGeral['liderancas']) ?></div>
        <div class="mini"><?= fmtNum($totGeral['liderancas_ativas']) ?> ativas</div>
    </div>
    <div class="kpi laranja">
        <div class="rotulo">Votos esperados</div>
        <div class="valor"><?= fmtNum($totGeral['esperado']) ?></div>
    </div>
    <div class="kpi teal">
        <div class="rotulo">Votos das urnas</div>
        <div class="valor"><?= fmtNum($totGeral['urnas']) ?></div>
    </div>
    <div class="kpi <?= $totGeral['diferenca'] >= 0 ? 'verde' : 'vermelho' ?>">
        <div class="rotulo">Diferença</div>
        <div class="valor">
            <?= ($totGeral['diferenca'] > 0 ? '+' : '') . fmtNum($totGeral['diferenca']) ?>
        </div>
        <div class="mini"><?= fmtPct($totGeral['pct']) ?> do esperado</div>
    </div>
</div>

<!-- ============================================================
     UM BLOCO POR CANDIDATO
     ============================================================ -->
<?php if (!$porCandidato): ?>
    <div class="aviso">
        Nenhum coordenador encontrado<?= $temFiltro ? ' para os filtros selecionados' : '' ?>.
    </div>
<?php else: ?>
    <?php foreach ($porCandidato as $cand => $lista):
        $t = $totPorCand[$cand];
    ?>
        <div class="cand-bloco">

            <!-- Cabeçalho do candidato -->
            <div class="cand-header">
                <div class="titulo">
                    <span class="ico">🗳</span>
                    <?php if ($cand === '(Sem candidato)'): ?>
                        <em style="opacity:.8;">Sem candidato vinculado</em>
                    <?php else: ?>
                        <?= htmlspecialchars($cand) ?>
                    <?php endif; ?>
                </div>

                <div class="resumo">
                    <span class="item">
                        <span class="num"><?= fmtNum($t['coordenadores']) ?></span>
                        <span class="lbl">coord.<?= $t['ativos'] !== $t['coordenadores'] ? ' (' . $t['ativos'] . ' ativos)' : '' ?></span>
                    </span>
                    <span class="item">
                        <span class="num"><?= fmtNum($t['liderancas']) ?></span>
                        <span class="lbl">lideranças</span>
                    </span>
                    <span class="item">
                        <span class="num"><?= fmtNum($t['esperado']) ?></span>
                        <span class="lbl">esperados</span>
                    </span>
                    <span class="item">
                        <span class="num"><?= fmtNum($t['urnas']) ?></span>
                        <span class="lbl">urnas</span>
                    </span>
                    <span class="item">
                        <span class="num"><?= fmtPct($t['pct']) ?></span>
                        <span class="lbl">do esperado</span>
                    </span>
                </div>
            </div>

            <!-- Tabela de coordenadores do candidato -->
            <div class="cand-body">
                <table>
                    <thead>
                        <tr>
                            <th style="width:50px;" class="centro">#</th>
                            <th>Coordenador</th>
                            <th class="centro" style="width:70px;">Zonas</th>
                            <th class="centro" style="width:80px;">Líderes</th>
                            <th class="num" style="width:110px;">Esperado</th>
                            <th class="num" style="width:110px;">Urnas</th>
                            <th class="num" style="width:100px;">Diferença</th>
                            <th style="width:180px;">Desempenho</th>
                            <th class="centro" style="width:90px;">Situação</th>
                            <th class="centro" style="width:80px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Ordena coordenadores do candidato por % (decrescente)
                        usort($lista, function ($a, $b) {
                            return ($b['pct'] ?? -1) <=> ($a['pct'] ?? -1);
                        });
                        $i = 1;
                        foreach ($lista as $c):
                            $pos = $i++;
                            $med = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : $pos));
                            $pct = $c['pct'] ?? 0;
                            $largura = min(100, max(0, $pct));
                            $cls = $pct >= 100 ? 'ok' : ($pct >= 70 ? 'med' : 'ruim');
                            if ($c['pct'] === null) $cls = 'zero';
                            $dif = (int)$c['diferenca'];
                        ?>
                            <tr>
                                <td class="centro"><span class="medalha"><?= $med ?></span></td>
                                <td><strong><?= htmlspecialchars($c['nome']) ?></strong></td>
                                <td class="centro"><?= fmtNum($c['qtd_zonas']) ?></td>
                                <td class="centro">
                                    <?= fmtNum($c['qtd_liderancas_ativas']) ?>
                                    <?php if ((int)$c['qtd_liderancas'] > (int)$c['qtd_liderancas_ativas']): ?>
                                        <span class="vazio" style="font-size:10px;">
                                            /<?= (int)$c['qtd_liderancas'] ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="num">
                                    <?= (int)$c['votos_esperados'] > 0
                                        ? '<span class="badge badge-votos">' . fmtNum($c['votos_esperados']) . '</span>'
                                        : '<span class="vazio">—</span>' ?>
                                </td>
                                <td class="num">
                                    <?= (int)$c['votos_urnas'] > 0
                                        ? '<span class="badge badge-urnas">' . fmtNum($c['votos_urnas']) . '</span>'
                                        : '<span class="vazio">—</span>' ?>
                                </td>
                                <td class="num">
                                    <?php if ((int)$c['votos_esperados'] > 0 || (int)$c['votos_urnas'] > 0): ?>
                                        <span class="badge <?= $dif > 0 ? 'badge-pos' : ($dif < 0 ? 'badge-neg' : 'badge-zero') ?>">
                                            <?= ($dif > 0 ? '+' : '') . fmtNum($dif) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="vazio">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['pct'] !== null): ?>
                                        <div class="barra">
                                            <div class="fill <?= $cls ?>" style="width:<?= $largura ?>%"></div>
                                            <div class="txt"><?= fmtPct($c['pct']) ?></div>
                                        </div>
                                    <?php else: ?>
                                        <span class="vazio">sem meta</span>
                                    <?php endif; ?>
                                </td>
                                <td class="centro">
                                    <span class="sit <?= $c['situacao']['classe'] ?>">
                                        <?= $c['situacao']['rotulo'] ?>
                                    </span>
                                </td>
                                <td class="centro">
                                    <?= (int)$c['ativo'] === 1
                                        ? '<span class="badge badge-ativo">Ativo</span>'
                                        : '<span class="badge badge-inativo">Inativo</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="linha-total">
                            <td colspan="2" style="text-align:right;">
                                <strong>Subtotal · <?= htmlspecialchars($cand) ?></strong>
                            </td>
                            <td class="centro"><?= fmtNum($t['zonas']) ?></td>
                            <td class="centro"><?= fmtNum($t['liderancas_ativas']) ?></td>
                            <td class="num"><?= fmtNum($t['esperado']) ?></td>
                            <td class="num"><?= fmtNum($t['urnas']) ?></td>
                            <td class="num">
                                <?= ($t['diferenca'] > 0 ? '+' : '') . fmtNum($t['diferenca']) ?>
                            </td>
                            <td class="centro" colspan="2">
                                <span class="badge <?= ($t['pct'] ?? 0) >= 100 ? 'badge-pct-ok' : (($t['pct'] ?? 0) >= 70 ? 'badge-pct-med' : 'badge-pct-ruim') ?>">
                                    <?= fmtPct($t['pct']) ?>
                                </span>
                            </td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    <?php endforeach; ?>

    <!-- ============================================================
         TOTAL GERAL (quando houver mais de um candidato)
         ============================================================ -->
    <?php if (count($porCandidato) > 1): ?>
        <div class="cand-bloco" style="background:#f6f0ff;">
            <div class="cand-header" style="background:linear-gradient(90deg, #333, #555);">
                <div class="titulo">
                    <span class="ico">Σ</span>
                    Total Geral (todos os candidatos)
                </div>
                <div class="resumo">
                    <span class="item">
                        <span class="num"><?= fmtNum($totGeral['coordenadores']) ?></span>
                        <span class="lbl">coordenadores</span>
                    </span>
                    <span class="item">
                        <span class="num"><?= fmtNum($totGeral['liderancas_ativas']) ?></span>
                        <span class="lbl">lideranças ativas</span>
                    </span>
                    <span class="item">
                        <span class="num"><?= fmtNum($totGeral['esperado']) ?></span>
                        <span class="lbl">esperados</span>
                    </span>
                    <span class="item">
                        <span class="num"><?= fmtNum($totGeral['urnas']) ?></span>
                        <span class="lbl">urnas</span>
                    </span>
                    <span class="item">
                        <span class="num"><?= fmtPct($totGeral['pct']) ?></span>
                        <span class="lbl">do esperado</span>
                    </span>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<p style="font-size:11px; color:#888; margin-top:30px; text-align:center;">
    Relatório gerado automaticamente · <?= date('d/m/Y \à\s H:i') ?>
</p>

</body>
</html>