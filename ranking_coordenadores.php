<?php
// ------------------------------------------------------------
// ranking_coordenadores.php
// Ranking de coordenadores — esperado x urnas
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
$filtroCandidato = trim($_GET['candidato'] ?? '');
$filtroCargo     = trim($_GET['cargo']     ?? '');

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

// ============================================================
// QUERY PRINCIPAL
// ============================================================

// Filtro de candidato em `coordenadores`
$whereCoord  = [];
$paramsCoord = [];
if ($filtroCandidato !== '' && $temCandidatoCoord) {
    $whereCoord[] = "c.nomeCandidatoResp = :cand_coord";
    $paramsCoord[':cand_coord'] = $filtroCandidato;
}
$whereCoordStr = $whereCoord ? 'WHERE ' . implode(' AND ', $whereCoord) : '';

// Filtros aplicados dentro da subquery de votos
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

$colCandSelect = $temCandidatoCoord
    ? "c.nomeCandidatoResp AS candidato,"
    : "NULL AS candidato,";

// ------------------------------------------------------------
// Subqueries de seções/urnas
// ------------------------------------------------------------
// IMPORTANTE: MySQL/MariaDB NÃO permite correlação com c.id dentro
// de derived tables (subquery no FROM). Por isso usamos:
//   - COUNT(DISTINCT CONCAT(...)) em subquery escalar correlacionada
//   - IN (subquery correlacionada) para filtrar votos
// ------------------------------------------------------------
if ($temLiderSecao) {
    $subSecoes = "
        (SELECT COUNT(DISTINCT CONCAT(ls.numeroZona, '|', ls.numeroSecao))
           FROM lider_secao ls
           INNER JOIN liderancas l ON l.id = ls.lider_id
          WHERE l.coordenador_id = c.id AND l.ativo = 1) AS secoes_unicas,

        (SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
           FROM votos v
          WHERE (v.numeroZona, v.numeroSecao) IN (
                SELECT ls.numeroZona, ls.numeroSecao
                  FROM lider_secao ls
                  INNER JOIN liderancas l ON l.id = ls.lider_id
                 WHERE l.coordenador_id = c.id AND l.ativo = 1
                 GROUP BY ls.numeroZona, ls.numeroSecao
          )
          $condVotos
        ) AS urnas
    ";
} else {
    $subSecoes = "0 AS secoes_unicas, 0 AS urnas";
}

// ------------------------------------------------------------
// Subqueries de expectativa
// ------------------------------------------------------------
if ($COL_EXPECT !== null) {
    $subEsperado = "
        (SELECT COALESCE(SUM(l.`$COL_EXPECT`), 0)
           FROM liderancas l
          WHERE l.coordenador_id = c.id AND l.ativo = 1) AS esperado,
        (SELECT COALESCE(SUM(l.`$COL_EXPECT`), 0)
           FROM liderancas l
          WHERE l.coordenador_id = c.id) AS esperado_total
    ";
} else {
    $subEsperado = "0 AS esperado, 0 AS esperado_total";
}

$sql = "
    SELECT
        c.id,
        c.nome AS coordenador,
        c.ativo,
        $colCandSelect

        (SELECT COUNT(*) FROM liderancas l
          WHERE l.coordenador_id = c.id) AS liderancas_total,
        (SELECT COUNT(*) FROM liderancas l
          WHERE l.coordenador_id = c.id AND l.ativo = 1) AS liderancas_ativas,

        $subEsperado,
        $subSecoes

    FROM coordenadores c
    $whereCoordStr
    ORDER BY urnas DESC, esperado DESC, c.nome
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($paramsCoord, $paramsVotos));
$coordenadores = $stmt->fetchAll();

// ============================================================
// PÓS-PROCESSAMENTO
// ============================================================
foreach ($coordenadores as &$c) {
    $esp = (int)$c['esperado'];
    $urn = (int)$c['urnas'];
    $c['diferenca'] = $urn - $esp;
    $c['pct'] = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
}
unset($c);

// ============================================================
// TOTAIS / KPIs
// ============================================================
$totalCoords         = count($coordenadores);
$totalAtivos         = 0;
$totalLiderancas     = 0;
$totalLiderancasAtiv = 0;
$totalEsperado       = 0;
$totalUrnas          = 0;
$totalSecoes         = 0;
$qtdAcima100         = 0;
$melhorCoord         = null;

foreach ($coordenadores as $c) {
    if ((int)$c['ativo'] === 1) $totalAtivos++;
    $totalLiderancas     += (int)$c['liderancas_total'];
    $totalLiderancasAtiv += (int)$c['liderancas_ativas'];
    $totalEsperado       += (int)$c['esperado'];
    $totalUrnas          += (int)$c['urnas'];
    $totalSecoes         += (int)$c['secoes_unicas'];

    if ($c['pct'] !== null && $c['pct'] >= 100) $qtdAcima100++;

    if ($melhorCoord === null) $melhorCoord = $c;
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
<title>Ranking de Coordenadores</title>
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
    th { background:#0069d9; color:#fff; font-size:12px;
         text-transform:uppercase; letter-spacing:.5px; }
    th.num, td.num { text-align:right; }
    th.centro, td.centro { text-align:center; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#eef6ff; }
    tr.linha-total { background:#eef6ff; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #0069d9; }
    .posicao { font-weight:bold; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-zona    { background:#e6f0fa; color:#0069d9; }
    .badge-lider   { background:#f0e6ff; color:#6f42c1; }
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
        display:inline-block; width:110px; vertical-align:middle; margin-left:6px;
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

<h1>Ranking de Coordenadores</h1>
<div class="subtitulo">
    Compara a <strong>expectativa de votos</strong> (soma das lideranças ativas)
    com os <strong>votos reais nas urnas</strong> das seções atribuídas a cada coordenador.
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
    &nbsp;·&nbsp;
    <code>nomeCandidatoResp</code> em <code>coordenadores</code>:
    <?php if ($temCandidatoCoord): ?>
        <strong>existe</strong>
    <?php else: ?>
        <span class="aviso">NÃO EXISTE</span>
    <?php endif; ?>
</p>

<!-- ============================================================
     FILTROS
     ============================================================ -->
<form method="get" action="ranking_coordenadores.php" class="filtros">
    <label for="candidato">Candidato:</label>
    <select name="candidato" id="candidato" style="min-width:260px;">
        <option value="">— Todos —</option>
        <?php foreach ($listaCandidatos as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"
                <?= $c === $filtroCandidato ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="cargo">Cargo:</label>
    <select name="cargo" id="cargo" style="min-width:180px;">
        <option value="">— Todos —</option>
        <?php foreach ($listaCargos as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"
                <?= $c === $filtroCargo ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="btn-consultar">🔎 Aplicar</button>
    <a href="ranking_coordenadores.php" class="btn-limpar">✖ Limpar</a>
</form>

<?php if (!$coordenadores): ?>

    <div class="msg-vazia">
        <p>Nenhum coordenador encontrado para os filtros aplicados.</p>
    </div>

<?php else: ?>

    <!-- ============================================================
         KPIs
         ============================================================ -->
    <div class="kpis">
        <div class="kpi">
            <div class="rotulo">Coordenadores</div>
            <div class="valor"><?= number_format($totalCoords, 0, ',', '.') ?></div>
            <div class="sub"><?= number_format($totalAtivos, 0, ',', '.') ?> ativos</div>
        </div>

        <div class="kpi laranja">
            <div class="rotulo">Votos esperados</div>
            <div class="valor"><?= number_format($totalEsperado, 0, ',', '.') ?></div>
            <div class="sub"><?= number_format($totalLiderancasAtiv, 0, ',', '.') ?> lideranças ativas</div>
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
            <div class="sub">de <?= number_format($totalCoords, 0, ',', '.') ?> coordenadores</div>
        </div>

        <?php if ($melhorCoord): ?>
            <div class="kpi verde">
                <div class="rotulo">🏆 Maior volume</div>
                <div class="valor sm"><?= htmlspecialchars($melhorCoord['coordenador']) ?></div>
                <div class="sub">
                    <?= number_format((int)$melhorCoord['urnas'], 0, ',', '.') ?> votos
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
                <th>Coordenador</th>
                <th>Candidato</th>
                <th class="centro" style="width:80px;">Líderes</th>
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
            foreach ($coordenadores as $c):
                $esp = (int)$c['esperado'];
                $urn = (int)$c['urnas'];
                $dif = (int)$c['diferenca'];
                $pct = $c['pct'];

                // classe da %
                if ($pct === null)   { $pctCls = 'badge-zero';     $barCls = '';     $largura = 0; }
                elseif ($pct >= 100) { $pctCls = 'badge-pct-ok';   $barCls = 'ok';   $largura = 100; }
                elseif ($pct >= 70)  { $pctCls = 'badge-pct-med';  $barCls = 'med';  $largura = $pct; }
                else                 { $pctCls = 'badge-pct-ruim'; $barCls = 'ruim'; $largura = $pct; }

                // classe da diferença
                if ($dif > 0)     $difCls = 'badge-pos';
                elseif ($dif < 0) $difCls = 'badge-neg';
                else              $difCls = 'badge-zero';

                // medalha
                $posTxt = $i;
                if ($i === 1) $posTxt = '🥇';
                elseif ($i === 2) $posTxt = '🥈';
                elseif ($i === 3) $posTxt = '🥉';
            ?>
                <tr>
                    <td class="centro posicao"><?= $posTxt ?></td>

                    <td>
                        <strong><?= htmlspecialchars($c['coordenador']) ?></strong>
                        <?php if ((int)$c['ativo'] === 0): ?>
                            <span class="badge badge-inativo">inativo</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($c['candidato'])): ?>
                            <span class="badge badge-cand"><?= htmlspecialchars($c['candidato']) ?></span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="centro">
                        <?php if ((int)$c['liderancas_total'] > 0): ?>
                            <span class="badge badge-lider">
                                <?= (int)$c['liderancas_ativas'] ?>/<?= (int)$c['liderancas_total'] ?>
                            </span>
                        <?php else: ?>
                            <span class="vazio">0</span>
                        <?php endif; ?>
                    </td>

                    <td class="centro">
                        <?php if ((int)$c['secoes_unicas'] > 0): ?>
                            <span class="badge badge-zona"><?= (int)$c['secoes_unicas'] ?></span>
                        <?php else: ?>
                            <span class="vazio">0</span>
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
                        <?= (int)$c['ativo'] === 1
                            ? '<span class="badge badge-ativo">Ativo</span>'
                            : '<span class="badge badge-inativo">Inativo</span>' ?>
                    </td>
                </tr>
                <?php $i++; ?>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="linha-total">
                <td colspan="3" style="text-align:right;">Total geral</td>
                <td class="centro"><?= number_format($totalLiderancasAtiv, 0, ',', '.') ?>/<?= number_format($totalLiderancas, 0, ',', '.') ?></td>
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