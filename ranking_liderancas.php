<?php
// ------------------------------------------------------------
// ranking_liderancas.php
// Ranking de lideranças — esperado x urnas
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

// ---------- coluna de candidato em `coordenadores` ----------
$colunasCoord = [];
foreach ($pdo->query("SHOW COLUMNS FROM coordenadores") as $c) $colunasCoord[] = $c['Field'];
$temCandidatoCoord = in_array('nomeCandidatoResp', $colunasCoord, true);

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

// ---------- tabela lider_secao existe? ----------
$temLiderSecao = false;
try { $pdo->query("SELECT 1 FROM lider_secao LIMIT 1"); $temLiderSecao = true; }
catch (Exception $e) { $temLiderSecao = false; }

// ============================================================
// FILTROS
// ============================================================
$filtroCandidato   = trim($_GET['candidato']   ?? '');
$filtroCargo       = trim($_GET['cargo']       ?? '');
$filtroCoordenador = (int)($_GET['coordenador_id'] ?? 0);
$filtroStatus      = trim($_GET['status']      ?? ''); // '', 'ativos', 'inativos'

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

// Coordenadores — se tiver filtro de candidato, restringe
if ($filtroCandidato !== '' && $temCandidatoCoord) {
    $stmt = $pdo->prepare(
        "SELECT id, nome, ativo FROM coordenadores
          WHERE nomeCandidatoResp = :c
          ORDER BY ativo DESC, nome"
    );
    $stmt->execute([':c' => $filtroCandidato]);
    $listaCoordenadores = $stmt->fetchAll();
} else {
    $listaCoordenadores = $pdo->query(
        "SELECT id, nome, ativo FROM coordenadores ORDER BY ativo DESC, nome"
    )->fetchAll();
}

// ============================================================
// QUERY PRINCIPAL
// ============================================================

// Filtros em `liderancas` / `coordenadores`
$where  = [];
$params = [];

if ($filtroCoordenador > 0) {
    $where[] = "l.coordenador_id = :coord_id";
    $params[':coord_id'] = $filtroCoordenador;
}
if ($filtroCandidato !== '' && $temCandidatoCoord) {
    $where[] = "c.nomeCandidatoResp = :cand_coord";
    $params[':cand_coord'] = $filtroCandidato;
}
if ($filtroStatus === 'ativos') {
    $where[] = "l.ativo = 1";
} elseif ($filtroStatus === 'inativos') {
    $where[] = "l.ativo = 0";
}
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Filtros dentro da subquery de votos
$condVotos   = "";
$paramsVotos = [];
if ($filtroCandidato !== '') {
    $condVotos .= " AND v.nomeCandidatoResp = :cand_votos";
    $paramsVotos[':cand_votos'] = $filtroCandidato;
}
if ($filtroCargo !== '') {
    $condVotos .= " AND v.nomeCargoPerg = :cargo";
    $paramsVotos[':cargo'] = $filtroCargo;
}

// Coluna de candidato vinda do coordenador
$colCandSelect = $temCandidatoCoord
    ? "c.nomeCandidatoResp AS candidato,"
    : "NULL AS candidato,";

// ------------------------------------------------------------
// Subqueries de seções / urnas
// Sem derived tables (MySQL não permite correlação com l.id lá dentro)
// ------------------------------------------------------------
if ($temLiderSecao) {
    $subSecoes = "
        (SELECT COUNT(DISTINCT CONCAT(ls.numeroZona, '|', ls.numeroSecao))
           FROM lider_secao ls
          WHERE ls.lider_id = l.id) AS secoes_unicas,

        (SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
           FROM votos v
          WHERE (v.numeroZona, v.numeroSecao) IN (
                SELECT ls.numeroZona, ls.numeroSecao
                  FROM lider_secao ls
                 WHERE ls.lider_id = l.id
                 GROUP BY ls.numeroZona, ls.numeroSecao
          )
          $condVotos
        ) AS urnas
    ";
} else {
    $subSecoes = "0 AS secoes_unicas, 0 AS urnas";
}

// ------------------------------------------------------------
// Expectativa (vem direto da coluna em `liderancas`)
// ------------------------------------------------------------
if ($COL_EXPECT !== null) {
    $subEsperado = "COALESCE(l.`$COL_EXPECT`, 0) AS esperado";
} else {
    $subEsperado = "0 AS esperado";
}

$sql = "
    SELECT
        l.id,
        l.nome AS lideranca,
        l.ativo,
        c.nome AS coordenador,
        c.ativo AS coordenador_ativo,
        $colCandSelect
        $subEsperado,
        $subSecoes

    FROM liderancas l
    LEFT JOIN coordenadores c ON c.id = l.coordenador_id
    $whereStr
    ORDER BY urnas DESC, esperado DESC, l.nome
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, $paramsVotos));
$liderancas = $stmt->fetchAll();

// ============================================================
// PÓS-PROCESSAMENTO
// ============================================================
foreach ($liderancas as &$l) {
    $esp = (int)$l['esperado'];
    $urn = (int)$l['urnas'];
    $l['diferenca'] = $urn - $esp;
    $l['pct'] = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
}
unset($l);

// ============================================================
// TOTAIS / KPIs
// ============================================================
$totalLiderancas     = count($liderancas);
$totalAtivas         = 0;
$totalEsperado       = 0;
$totalUrnas          = 0;
$totalSecoes         = 0;
$qtdAcima100         = 0;
$melhorVol           = null;
$piorPct             = null;
$semSecao            = 0;

foreach ($liderancas as $l) {
    if ((int)$l['ativo'] === 1) $totalAtivas++;
    $totalEsperado += (int)$l['esperado'];
    $totalUrnas    += (int)$l['urnas'];
    $totalSecoes   += (int)$l['secoes_unicas'];

    if ((int)$l['secoes_unicas'] === 0) $semSecao++;

    if ($l['pct'] !== null && $l['pct'] >= 100) $qtdAcima100++;

    // Maior volume
    if ($melhorVol === null
        || (int)$l['urnas'] > (int)$melhorVol['urnas']) {
        $melhorVol = $l;
    }

    // Pior % (só quem tem esperado > 0)
    if ($l['pct'] !== null) {
        if ($piorPct === null || $l['pct'] < $piorPct['pct']) {
            $piorPct = $l;
        }
    }
}

$totalDiferenca = $totalUrnas - $totalEsperado;
$totalPct = $totalEsperado > 0
    ? round(($totalUrnas / $totalEsperado) * 100, 1)
    : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ranking de Lideranças</title>
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
    .kpi .valor.sm { font-size:16px; line-height:1.3; word-break:break-word; }

    /* Tabela */
    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left;
             vertical-align:middle; font-size:13px; }
    th { background:#6f42c1; color:#fff; font-size:12px;
         text-transform:uppercase; letter-spacing:.5px; }
    th.num, td.num { text-align:right; }
    th.centro, td.centro { text-align:center; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#f4eefc; }
    tr.linha-total { background:#f4eefc; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #6f42c1; }
    .posicao { font-weight:bold; }
    tr.destaque-bom  { background:#eafaf0 !important; }
    tr.destaque-ruim { background:#fdf1f2 !important; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-zona    { background:#e6f0fa; color:#0069d9; }
    .badge-coord   { background:#f0e6ff; color:#6f42c1; }
    .badge-ativo   { background:#d4f5dd; color:#1c7a34; }
    .badge-inativo { background:#f0f0f0; color:#888; }
    .badge-cand    { background:#e7d9ff; color:#4a1d96; font-weight:bold; }
    .badge-votos   { background:#fff0e0; color:#c25b00; }
    .badge-urnas   { background:#d9edf7; color:#0c5460; }
    .badge-pos     { background:#d4f5dd; color:#1c7a34; }
    .badge-neg     { background:#fdecea; color:#a71d2a; }
    .badge-zero    { background:#f0f0f0; color:#666; }
    .badge-pct-ok  { background:#d4f5dd; color:#1c7a34; }
    .badge-pct-med { background:#fff3cd; color:#856404; }
    .badge-pct-ruim{ background:#fdecea; color:#a71d2a; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    .barra {
        background:#e6f0fa; border-radius:4px; height:10px; overflow:hidden;
        display:inline-block; width:100px; vertical-align:middle; margin-left:6px;
    }
    .barra > span { display:block; height:100%; }
    .barra > span.ok   { background:#28a745; }
    .barra > span.med  { background:#ffc107; }
    .barra > span.ruim { background:#dc3545; }

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

    .link-coord {
        color:#6f42c1; text-decoration:none;
        border-bottom:1px dashed #6f42c1;
    }
    .link-coord:hover { color:#4a1d96; border-bottom-style:solid; }
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

<h1>Ranking de Lideranças</h1>
<div class="subtitulo">
    <strong>Drill-down</strong> do ranking de coordenadores.
    Compara a <strong>expectativa</strong> cadastrada em cada liderança
    com os <strong>votos reais nas urnas</strong> das seções pelas quais ela responde.
</div>

<p class="info-col">
    Coluna de votos: <code><?= htmlspecialchars($COL_VOTOS) ?></code>
    &nbsp;·&nbsp;
    Coluna de expectativa em <code>liderancas</code>:
    <?php if ($COL_EXPECT): ?>
        <code><?= htmlspecialchars($COL_EXPECT) ?></code>
    <?php else: ?>
        <span class="aviso">NÃO EXISTE — esperado = 0</span>
    <?php endif; ?>
    &nbsp;·&nbsp;
    Tabela <code>lider_secao</code>:
    <?php if ($temLiderSecao): ?>
        <strong>existe</strong>
    <?php else: ?>
        <span class="aviso">não existe — urnas = 0</span>
    <?php endif; ?>
</p>

<!-- ============================================================
     FILTROS
     ============================================================ -->
<form method="get" action="ranking_liderancas.php" class="filtros">
    <label for="candidato">Candidato:</label>
    <select name="candidato" id="candidato" style="min-width:240px;">
        <option value="">— Todos —</option>
        <?php foreach ($listaCandidatos as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"
                <?= $c === $filtroCandidato ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="coordenador_id">Coordenador:</label>
    <select name="coordenador_id" id="coordenador_id" style="min-width:220px;">
        <option value="0">— Todos —</option>
        <?php foreach ($listaCoordenadores as $c): ?>
            <option value="<?= (int)$c['id'] ?>"
                <?= (int)$c['id'] === $filtroCoordenador ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['nome']) ?>
                <?= (int)$c['ativo'] === 0 ? ' (inativo)' : '' ?>
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

    <label for="status">Status:</label>
    <select name="status" id="status" style="min-width:140px;">
        <option value=""          <?= $filtroStatus === ''         ? 'selected' : '' ?>>— Todos —</option>
        <option value="ativos"    <?= $filtroStatus === 'ativos'   ? 'selected' : '' ?>>Somente ativas</option>
        <option value="inativos"  <?= $filtroStatus === 'inativos' ? 'selected' : '' ?>>Somente inativas</option>
    </select>

    <button type="submit" class="btn-consultar">🔎 Aplicar</button>
    <a href="ranking_liderancas.php" class="btn-limpar">✖ Limpar</a>
</form>

<?php if (!$liderancas): ?>

    <div class="msg-vazia">
        <p>Nenhuma liderança encontrada para os filtros aplicados.</p>
    </div>

<?php else: ?>

    <!-- ============================================================
         KPIs
         ============================================================ -->
    <div class="kpis">
        <div class="kpi">
            <div class="rotulo">Lideranças</div>
            <div class="valor"><?= number_format($totalLiderancas, 0, ',', '.') ?></div>
            <div class="sub"><?= number_format($totalAtivas, 0, ',', '.') ?> ativas</div>
        </div>

        <div class="kpi laranja">
            <div class="rotulo">Votos esperados</div>
            <div class="valor"><?= number_format($totalEsperado, 0, ',', '.') ?></div>
            <div class="sub">soma das expectativas</div>
        </div>

        <div class="kpi teal">
            <div class="rotulo">Votos nas urnas</div>
            <div class="valor"><?= number_format($totalUrnas, 0, ',', '.') ?></div>
            <div class="sub"><?= number_format($totalSecoes, 0, ',', '.') ?> seções únicas</div>
        </div>

        <div class="kpi <?= $totalDiferenca >= 0 ? 'verde' : 'vermelho' ?>">
            <div class="rotulo">Diferença</div>
            <div class="valor">
                <?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?>
            </div>
            <div class="sub">
                <?= $totalPct !== null
                    ? number_format($totalPct, 1, ',', '.') . '% do esperado'
                    : 'sem base' ?>
            </div>
        </div>

        <div class="kpi roxo">
            <div class="rotulo">Acima de 100%</div>
            <div class="valor"><?= number_format($qtdAcima100, 0, ',', '.') ?></div>
            <div class="sub">de <?= number_format($totalLiderancas, 0, ',', '.') ?></div>
        </div>

        <?php if ($semSecao > 0): ?>
            <div class="kpi vermelho">
                <div class="rotulo">Sem seção atribuída</div>
                <div class="valor"><?= number_format($semSecao, 0, ',', '.') ?></div>
                <div class="sub">cadastro incompleto</div>
            </div>
        <?php endif; ?>

        <?php if ($melhorVol): ?>
            <div class="kpi verde">
                <div class="rotulo">🏆 Maior volume</div>
                <div class="valor sm"><?= htmlspecialchars($melhorVol['lideranca']) ?></div>
                <div class="sub">
                    <?= number_format((int)$melhorVol['urnas'], 0, ',', '.') ?> votos
                </div>
            </div>
        <?php endif; ?>

        <?php if ($piorPct && $piorPct['pct'] !== null && $piorPct['pct'] < 100): ?>
            <div class="kpi vermelho">
                <div class="rotulo">⚠️ Pior desempenho</div>
                <div class="valor sm"><?= htmlspecialchars($piorPct['lideranca']) ?></div>
                <div class="sub">
                    <?= number_format($piorPct['pct'], 1, ',', '.') ?>% do esperado
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================
         TABELA
         ============================================================ -->
    <table>
        <thead>
            <tr>
                <th class="centro" style="width:50px;">#</th>
                <th>Liderança</th>
                <th>Coordenador</th>
                <th>Candidato</th>
                <th class="centro" style="width:80px;">Seções</th>
                <th class="num" style="width:110px;">Esperado</th>
                <th class="num" style="width:110px;">Urnas</th>
                <th class="num" style="width:100px;">Diferença</th>
                <th class="centro" style="width:130px;">%</th>
                <th style="width:70px;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $i = 1;
            foreach ($liderancas as $l):
                $esp = (int)$l['esperado'];
                $urn = (int)$l['urnas'];
                $dif = (int)$l['diferenca'];
                $pct = $l['pct'];

                // classe da %
                if ($pct === null)   { $pctCls = 'badge-zero';     $barCls = '';     $largura = 0; }
                elseif ($pct >= 100) { $pctCls = 'badge-pct-ok';   $barCls = 'ok';   $largura = 100; }
                elseif ($pct >= 70)  { $pctCls = 'badge-pct-med';  $barCls = 'med';  $largura = $pct; }
                else                 { $pctCls = 'badge-pct-ruim'; $barCls = 'ruim'; $largura = $pct; }

                // classe da diferença
                if ($dif > 0)     $difCls = 'badge-pos';
                elseif ($dif < 0) $difCls = 'badge-neg';
                else              $difCls = 'badge-zero';

                // destaque visual: top performer e pior % (com esperado > 0)
                $trCls = '';
                if ($melhorVol && (int)$melhorVol['id'] === (int)$l['id'] && (int)$l['urnas'] > 0) {
                    $trCls = 'destaque-bom';
                } elseif ($piorPct && (int)$piorPct['id'] === (int)$l['id']
                          && $pct !== null && $pct < 70) {
                    $trCls = 'destaque-ruim';
                }

                // medalha
                $posTxt = $i;
                if ($i === 1) $posTxt = '🥇';
                elseif ($i === 2) $posTxt = '🥈';
                elseif ($i === 3) $posTxt = '🥉';

                // link para o ranking de coordenadores já filtrado
                $linkCoord = 'ranking_liderancas.php?coordenador_id=' . (int)$l['id'];
            ?>
                <tr class="<?= $trCls ?>">
                    <td class="centro posicao"><?= $posTxt ?></td>

                    <td>
                        <strong><?= htmlspecialchars($l['lideranca']) ?></strong>
                        <?php if ((int)$l['ativo'] === 0): ?>
                            <span class="badge badge-inativo">inativa</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($l['coordenador'])): ?>
                            <a class="link-coord"
                               href="ranking_coordenadores.php?candidato=<?= urlencode($filtroCandidato) ?>"
                               title="Ver ranking de coordenadores">
                                <?= htmlspecialchars($l['coordenador']) ?>
                            </a>
                            <?php if ((int)$l['coordenador_ativo'] === 0): ?>
                                <span class="badge badge-inativo">inativo</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="vazio">— sem coordenador —</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($l['candidato'])): ?>
                            <span class="badge badge-cand"><?= htmlspecialchars($l['candidato']) ?></span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="centro">
                        <?php if ((int)$l['secoes_unicas'] > 0): ?>
                            <span class="badge badge-zona"><?= (int)$l['secoes_unicas'] ?></span>
                        <?php else: ?>
                            <span class="badge badge-neg" title="Sem seção atribuída">0</span>
                        <?php endif; ?>
                    </td>

                    <td class="num">
                        <?php if ($esp > 0): ?>
                            <span class="badge badge-votos"><?= number_format($esp, 0, ',', '.') ?></span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="num">
                        <?php if ($urn > 0): ?>
                            <span class="badge badge-urnas"><?= number_format($urn, 0, ',', '.') ?></span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="num">
                        <?php if ($esp > 0 || $urn > 0): ?>
                            <span class="badge <?= $difCls ?>">
                                <?= ($dif > 0 ? '+' : '') . number_format($dif, 0, ',', '.') ?>
                            </span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="centro">
                        <?php if ($pct !== null): ?>
                            <span class="badge <?= $pctCls ?>">
                                <?= number_format($pct, 1, ',', '.') ?>%
                            </span>
                            <br>
                            <span class="barra" title="<?= number_format($pct, 1, ',', '.') ?>%">
                                <span class="<?= $barCls ?>" style="width: <?= number_format($largura, 1, '.', '') ?>%"></span>
                            </span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?= (int)$l['ativo'] === 1
                            ? '<span class="badge badge-ativo">Ativa</span>'
                            : '<span class="badge badge-inativo">Inativa</span>' ?>
                    </td>
                </tr>
                <?php $i++; ?>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="linha-total">
                <td colspan="4" style="text-align:right;">Total geral</td>
                <td class="centro"><?= number_format($totalSecoes, 0, ',', '.') ?></td>
                <td class="num"><?= number_format($totalEsperado, 0, ',', '.') ?></td>
                <td class="num"><?= number_format($totalUrnas, 0, ',', '.') ?></td>
                <td class="num">
                    <?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?>
                </td>
                <td class="centro">
                    <?= $totalPct !== null
                        ? number_format($totalPct, 1, ',', '.') . '%'
                        : '—' ?>
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>

<?php endif; ?>

</body>
</html>