<?php
// ------------------------------------------------------------
// comparar_candidatos.php
// Comparação entre dois candidatos no mesmo território
// Mostra onde o candidato B (adversário) está ganhando do A.
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';
date_default_timezone_set('America/Sao_Paulo');

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
// DETECÇÃO DE COLUNA DE VOTOS
// ============================================================
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

// ============================================================
// FILTROS
// ============================================================
$candA      = trim($_GET['cand_a']    ?? '');
$candB      = trim($_GET['cand_b']    ?? '');
$filtroCargo = trim($_GET['cargo']    ?? '');
$filtroMun   = trim($_GET['municipio'] ?? '');

$erro = '';
if ($candA !== '' && $candB !== '' && $candA === $candB) {
    $erro = 'Os dois candidatos são o mesmo. Escolha candidatos diferentes.';
}

// Só executa a query se os dois estiverem definidos e forem diferentes
$podeComparar = ($candA !== '' && $candB !== '' && $candA !== $candB);

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

$listaMunicipios = $pdo->query(
    "SELECT DISTINCT nomeMunicipio
       FROM votos
      WHERE nomeMunicipio IS NOT NULL AND nomeMunicipio <> ''
      ORDER BY nomeMunicipio"
)->fetchAll(PDO::FETCH_COLUMN);

// ============================================================
// QUERY PRINCIPAL
// ============================================================
$porMunicipio = [];  // município => ['A'=>, 'B'=>, 'total'=>, 'zonas'=>[]]
$totais = [
    'votos_a'       => 0,
    'votos_b'       => 0,
    'zonas'         => 0,
    'zonas_a_ganha' => 0,
    'zonas_b_ganha' => 0,
    'zonas_empate'  => 0,
    'municipios'    => 0,
    'municipios_a_ganha' => 0,
    'municipios_b_ganha' => 0,
    'municipios_empate'  => 0,
];

if ($podeComparar) {
    $where  = ["nomeCandidatoResp IN (:cand_a, :cand_b)"];
    $params = [
        ':cand_a' => $candA,
        ':cand_b' => $candB,
    ];
    if ($filtroCargo !== '') {
        $where[] = "nomeCargoPerg = :cargo";
        $params[':cargo'] = $filtroCargo;
    }
    if ($filtroMun !== '') {
        $where[] = "nomeMunicipio = :mun";
        $params[':mun'] = $filtroMun;
    }
    $whereStr = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT
            numeroZona,
            nomeMunicipio,
            SUM(CASE WHEN nomeCandidatoResp = :cand_a THEN `$COL_VOTOS` ELSE 0 END) AS votos_a,
            SUM(CASE WHEN nomeCandidatoResp = :cand_b THEN `$COL_VOTOS` ELSE 0 END) AS votos_b
        FROM votos
        $whereStr
        GROUP BY numeroZona, nomeMunicipio
        ORDER BY nomeMunicipio, numeroZona
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    foreach ($linhas as $r) {
        $mun = $r['nomeMunicipio'] !== '' ? $r['nomeMunicipio'] : '(sem município)';
        $za  = (int)$r['votos_a'];
        $zb  = (int)$r['votos_b'];

        if (!isset($porMunicipio[$mun])) {
            $porMunicipio[$mun] = [
                'municipio' => $mun,
                'votos_a'   => 0,
                'votos_b'   => 0,
                'zonas'     => [],
            ];
        }
        $porMunicipio[$mun]['votos_a'] += $za;
        $porMunicipio[$mun]['votos_b'] += $zb;
        $porMunicipio[$mun]['zonas'][] = [
            'zona'    => (int)$r['numeroZona'],
            'votos_a' => $za,
            'votos_b' => $zb,
            'dif'     => $za - $zb,
        ];

        $totais['votos_a'] += $za;
        $totais['votos_b'] += $zb;
        $totais['zonas']++;

        if ($za > $zb)       $totais['zonas_a_ganha']++;
        elseif ($za < $zb)   $totais['zonas_b_ganha']++;
        else                 $totais['zonas_empate']++;
    }

    // Totais por município
    foreach ($porMunicipio as &$m) {
        $m['dif'] = $m['votos_a'] - $m['votos_b'];
        $m['total'] = $m['votos_a'] + $m['votos_b'];
        $m['pct_a'] = $m['total'] > 0
            ? round(100 * $m['votos_a'] / $m['total'], 1)
            : null;

        // Ordena zonas por diferença ascendente (B ganhando primeiro)
        usort($m['zonas'], fn($a, $b) => $a['dif'] <=> $b['dif']);

        if ($m['dif'] > 0)      $totais['municipios_a_ganha']++;
        elseif ($m['dif'] < 0)  $totais['municipios_b_ganha']++;
        else                    $totais['municipios_empate']++;
    }
    unset($m);

    $totais['municipios'] = count($porMunicipio);

    // Ordena municípios por diferença ascendente (B ganhando primeiro)
    uasort($porMunicipio, fn($a, $b) => $a['dif'] <=> $b['dif']);
}

$totalGeral  = $totais['votos_a'] + $totais['votos_b'];
$totalDif    = $totais['votos_a'] - $totais['votos_b'];
$totalPctA   = $totalGeral > 0
    ? round(100 * $totais['votos_a'] / $totalGeral, 1)
    : null;
$totalPctB   = $totalGeral > 0
    ? round(100 * $totais['votos_b'] / $totalGeral, 1)
    : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Comparar Candidatos</title>
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
    }
    .filtros .linha {
        display:flex; flex-wrap:wrap; align-items:center;
        gap:12px; margin-bottom:10px;
    }
    .filtros .linha:last-child { margin-bottom:0; }
    .filtros label { font-weight:bold; font-size:13px; }
    .filtros select, .filtros button {
        padding:8px 10px; border-radius:4px; border:1px solid #ccc;
        font-size:14px; font-family:inherit;
    }
    .filtros .campo-a select { border: 2px solid #28a745; }
    .filtros .campo-b select { border: 2px solid #dc3545; }
    .btn-consultar { background:#0069d9; color:#fff; border:none; cursor:pointer; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none;
                  padding:8px 14px; border-radius:4px; font-size:14px; }
    .btn-limpar:hover { background:#5a6268; }

    .aviso-erro {
        background:#f8d7da; border-left:5px solid #dc3545;
        padding:12px 16px; border-radius:6px; margin-bottom:20px;
        color:#721c24;
    }
    .aviso-info {
        background:#e7f3ff; border-left:5px solid #0069d9;
        padding:14px 18px; border-radius:6px; margin-bottom:20px;
        color:#004085;
    }
    .aviso-cargo {
        background:#fff3cd; border-left:5px solid #ffc107;
        padding:10px 14px; border-radius:6px; margin-bottom:18px;
        font-size:12.5px; color:#856404;
    }

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
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi .valor.sm { font-size:15px; line-height:1.3; word-break:break-word; }

    /* Barra comparativa no topo */
    .barra-versus {
        display:flex; background:#fff; border-radius:8px; overflow:hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,.1);
        margin-bottom:20px; height:44px;
        font-weight:bold; font-size:15px;
        border:1px solid #e0e0e0;
    }
    .barra-versus .lado-a {
        background:#28a745; color:#fff;
        display:flex; align-items:center; padding:0 16px;
        transition: width .3s;
    }
    .barra-versus .lado-b {
        background:#dc3545; color:#fff;
        display:flex; align-items:center; padding:0 16px;
        justify-content:flex-end;
        transition: width .3s;
    }
    .barra-versus .centro-info {
        background:#f8f9fa; color:#333;
        display:flex; align-items:center; justify-content:center;
        padding:0 16px; white-space:nowrap;
    }

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
    tr.linha-b-ganha { background:#fdecea !important; }
    tr.linha-b-ganha:hover { background:#fbd8dc !important; }
    tr.linha-a-ganha { background:#f1fbf4 !important; }
    tr.linha-a-ganha:hover { background:#e3f7e9 !important; }
    tr.linha-total { background:#eef6ff; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #0069d9; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-a       { background:#d4f5dd; color:#1c7a34; }
    .badge-b       { background:#fdecea; color:#a71d2a; }
    .badge-pos     { background:#d4f5dd; color:#1c7a34; }
    .badge-neg     { background:#fdecea; color:#a71d2a; }
    .badge-zero    { background:#f0f0f0; color:#666; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    .mini-barra {
        display:inline-flex; width:120px; height:12px;
        border-radius:6px; overflow:hidden;
        border:1px solid #ddd; vertical-align:middle;
    }
    .mini-barra .a { background:#28a745; }
    .mini-barra .b { background:#dc3545; }

    .info-col {
        font-size:12px; color:#666; margin-bottom:14px;
        background:#fff; padding:8px 14px; border-radius:6px;
        border-left:4px solid #17a2b8;
        box-shadow: 0 1px 2px rgba(0,0,0,.05);
    }
    .info-col code { background:#f4f4f4; padding:1px 5px; border-radius:3px; }

    /* Accordion por município */
    details.mun-bloco {
        background:#fff; border-radius:8px; padding:10px 16px;
        margin-bottom:8px; box-shadow:0 1px 3px rgba(0,0,0,.06);
    }
    details.mun-bloco > summary {
        cursor:pointer; font-weight:bold; color:#333;
        padding:6px 0; font-size:14px;
        display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    }
    details.mun-bloco > summary:hover { color:#0069d9; }
    details.mun-bloco > summary .pct-a {
        background:#28a745; color:#fff;
        padding:1px 8px; border-radius:8px; font-size:11px;
    }
    details.mun-bloco > summary .pct-b {
        background:#dc3545; color:#fff;
        padding:1px 8px; border-radius:8px; font-size:11px;
    }
    details.mun-bloco > summary .resumo {
        font-weight:normal; color:#666; font-size:12px;
        margin-left:auto;
    }
    details.mun-bloco table {
        margin-top:8px; font-size:12.5px;
    }
    details.mun-bloco table th { font-size:11px; padding:6px 8px; }
    details.mun-bloco table td { padding:5px 8px; }
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

<h1>Comparar Candidatos</h1>
<div class="subtitulo">
    Escolha <strong>dois candidatos</strong> e veja, por município e zona,
    onde um está ganhando do outro. Diferenças negativas (linhas vermelhas)
    são onde o <strong>candidato B vence</strong> — prioridade de campo.
</div>

<p class="info-col">
    Coluna de votos: <code><?= htmlspecialchars($COL_VOTOS) ?></code>
    &nbsp;·&nbsp;
    Agrupamento: <strong>município + zona</strong>
</p>

<!-- ============================================================
     FILTROS
     ============================================================ -->
<form method="get" action="comparar_candidatos.php" class="filtros">
    <div class="linha">
        <span class="campo-a">
            <label for="cand_a">🟢 Candidato A (nosso):</label>
            <select name="cand_a" id="cand_a" style="min-width:240px;" required>
                <option value="">— Selecione —</option>
                <?php foreach ($listaCandidatos as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"
                        <?= $c === $candA ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </span>

        <span class="campo-b">
            <label for="cand_b">🔴 Candidato B (adversário):</label>
            <select name="cand_b" id="cand_b" style="min-width:240px;" required>
                <option value="">— Selecione —</option>
                <?php foreach ($listaCandidatos as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"
                        <?= $c === $candB ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </span>
    </div>

    <div class="linha">
        <label for="cargo">Cargo:</label>
        <select name="cargo" id="cargo" style="min-width:180px;">
            <option value="">— Todos (mistura cargos) —</option>
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
                    <?= $m === $filtroMun ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn-consultar">🔎 Comparar</button>
        <a href="comparar_candidatos.php" class="btn-limpar">✖ Limpar</a>
    </div>
</form>

<?php if ($erro !== ''): ?>
    <div class="aviso-erro">⚠️ <?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<?php if (!$podeComparar && $erro === ''): ?>

    <div class="aviso-info">
        <strong>Escolha dois candidatos diferentes acima</strong> e clique em Comparar.<br>
        A comparação mostra a votação de cada um por município e zona,
        destacando onde o candidato B (adversário) está à frente.
        <br><br>
        <small>💡 Filtre também por <strong>Cargo</strong> — senão votos de cargos diferentes
        do mesmo nome entram na mesma soma.</small>
    </div>

<?php elseif ($podeComparar && !$porMunicipio): ?>

    <div class="aviso-info">
        Nenhum voto encontrado para os candidatos <strong><?= htmlspecialchars($candA) ?></strong>
        e <strong><?= htmlspecialchars($candB) ?></strong> com os filtros aplicados.
    </div>

<?php elseif ($podeComparar): ?>

    <?php if ($filtroCargo === ''): ?>
        <div class="aviso-cargo">
            ⚠️ Você não filtrou por <strong>Cargo</strong>. Se os candidatos aparecem em
            mais de um cargo em <code>votos</code>, os totais podem somar cargos diferentes.
        </div>
    <?php endif; ?>

    <!-- ============================================================
         Barra "versus"
         ============================================================ -->
    <div class="barra-versus">
        <div class="lado-a" style="width: <?= $totalPctA ?? 50 ?>%;">
            🟢 <?= htmlspecialchars($candA) ?>
            — <?= number_format($totais['votos_a'], 0, ',', '.') ?>
            (<?= $totalPctA !== null ? number_format($totalPctA, 1, ',', '.') . '%' : '—' ?>)
        </div>
        <div class="centro-info">
            <?php if ($totalDif > 0): ?>
                A lidera por <strong>+<?= number_format($totalDif, 0, ',', '.') ?></strong>
            <?php elseif ($totalDif < 0): ?>
                B lidera por <strong><?= number_format($totalDif, 0, ',', '.') ?></strong>
            <?php else: ?>
                Empate técnico
            <?php endif; ?>
        </div>
        <div class="lado-b" style="width: <?= $totalPctB ?? 50 ?>%;">
            <?= number_format($totais['votos_b'], 0, ',', '.') ?>
            (<?= $totalPctB !== null ? number_format($totalPctB, 1, ',', '.') . '%' : '—' ?>)
            — <?= htmlspecialchars($candB) ?> 🔴
        </div>
    </div>

    <!-- ============================================================
         KPIs
         ============================================================ -->
    <div class="kpis">
        <div class="kpi verde">
            <div class="rotulo"><?= htmlspecialchars($candA) ?></div>
            <div class="valor"><?= number_format($totais['votos_a'], 0, ',', '.') ?></div>
            <div class="sub">
                <?= $totalPctA !== null ? number_format($totalPctA, 1, ',', '.') . '% do total' : '—' ?>
            </div>
        </div>

        <div class="kpi vermelho">
            <div class="rotulo"><?= htmlspecialchars($candB) ?></div>
            <div class="valor"><?= number_format($totais['votos_b'], 0, ',', '.') ?></div>
            <div class="sub">
                <?= $totalPctB !== null ? number_format($totalPctB, 1, ',', '.') . '% do total' : '—' ?>
            </div>
        </div>

        <div class="kpi <?= $totalDif >= 0 ? 'verde' : 'vermelho' ?>">
            <div class="rotulo">Diferença (A − B)</div>
            <div class="valor">
                <?= ($totalDif > 0 ? '+' : '') . number_format($totalDif, 0, ',', '.') ?>
            </div>
            <div class="sub">
                <?= $totalDif > 0 ? '🟢 A está à frente'
                    : ($totalDif < 0 ? '🔴 B está à frente' : 'empate') ?>
            </div>
        </div>

        <div class="kpi cinza">
            <div class="rotulo">Municípios</div>
            <div class="valor"><?= number_format($totais['municipios'], 0, ',', '.') ?></div>
            <div class="sub">
                🟢 A vence <?= $totais['municipios_a_ganha'] ?>
                · 🔴 B vence <?= $totais['municipios_b_ganha'] ?>
            </div>
        </div>

        <div class="kpi roxo">
            <div class="rotulo">Zonas</div>
            <div class="valor"><?= number_format($totais['zonas'], 0, ',', '.') ?></div>
            <div class="sub">
                🟢 A vence <?= $totais['zonas_a_ganha'] ?>
                · 🔴 B vence <?= $totais['zonas_b_ganha'] ?>
            </div>
        </div>

        <?php if ($totais['zonas_b_ganha'] > 0): ?>
            <div class="kpi vermelho">
                <div class="rotulo">🔥 Prioridade de campo</div>
                <div class="valor"><?= number_format($totais['zonas_b_ganha'], 0, ',', '.') ?></div>
                <div class="sub">zonas onde B vence</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================
         TABELA POR MUNICÍPIO
         ============================================================ -->
    <h2 style="margin-top:24px;">Por município</h2>
    <p style="font-size:12.5px; color:#666; margin-bottom:12px;">
        Ordenado por diferença ascendente — <strong>onde B ganha aparece primeiro</strong>.
    </p>

    <table>
        <thead>
            <tr>
                <th>Município</th>
                <th class="num" style="width:110px;"><?= htmlspecialchars($candA) ?></th>
                <th class="num" style="width:110px;"><?= htmlspecialchars($candB) ?></th>
                <th class="num" style="width:110px;">Diferença</th>
                <th class="centro" style="width:180px;">Proporção</th>
                <th class="centro" style="width:100px;">Vencedor</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($porMunicipio as $m):
                $a = (int)$m['votos_a'];
                $b = (int)$m['votos_b'];
                $d = (int)$m['dif'];
                $total = (int)$m['total'];

                $pctA = $total > 0 ? round(100 * $a / $total, 1) : 0;
                $pctB = $total > 0 ? round(100 * $b / $total, 1) : 0;

                if ($d > 0)      { $trCls = 'linha-a-ganha'; $vencedor = '<span class="badge badge-a">🟢 A</span>'; }
                elseif ($d < 0)  { $trCls = 'linha-b-ganha'; $vencedor = '<span class="badge badge-b">🔴 B</span>'; }
                else             { $trCls = '';              $vencedor = '<span class="badge badge-zero">empate</span>'; }

                $difCls = $d > 0 ? 'badge-pos' : ($d < 0 ? 'badge-neg' : 'badge-zero');
            ?>
                <tr class="<?= $trCls ?>">
                    <td><strong><?= htmlspecialchars($m['municipio']) ?></strong></td>
                    <td class="num">
                        <span class="badge badge-a"><?= number_format($a, 0, ',', '.') ?></span>
                    </td>
                    <td class="num">
                        <span class="badge badge-b"><?= number_format($b, 0, ',', '.') ?></span>
                    </td>
                    <td class="num">
                        <span class="badge <?= $difCls ?>">
                            <?= ($d > 0 ? '+' : '') . number_format($d, 0, ',', '.') ?>
                        </span>
                    </td>
                    <td class="centro">
                        <span class="mini-barra" title="A: <?= $pctA ?>% · B: <?= $pctB ?>%">
                            <span class="a" style="width: <?= $pctA ?>%;"></span>
                            <span class="b" style="width: <?= $pctB ?>%;"></span>
                        </span>
                        <br>
                        <small style="color:#666;">
                            <?= number_format($pctA, 1, ',', '.') ?>% / <?= number_format($pctB, 1, ',', '.') ?>%
                        </small>
                    </td>
                    <td class="centro"><?= $vencedor ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="linha-total">
                <td>Total geral</td>
                <td class="num"><?= number_format($totais['votos_a'], 0, ',', '.') ?></td>
                <td class="num"><?= number_format($totais['votos_b'], 0, ',', '.') ?></td>
                <td class="num">
                    <?= ($totalDif > 0 ? '+' : '') . number_format($totalDif, 0, ',', '.') ?>
                </td>
                <td class="centro">
                    <?= $totalPctA !== null ? number_format($totalPctA, 1, ',', '.') . '% / ' .
                                              number_format($totalPctB, 1, ',', '.') . '%' : '—' ?>
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <!-- ============================================================
         DRILL-DOWN POR ZONA
         ============================================================ -->
    <h2 style="margin-top:30px;">Detalhe por zona</h2>
    <p style="font-size:12.5px; color:#666; margin-bottom:12px;">
        Cada bloco é um município. Dentro dele, as zonas ordenadas por diferença
        ascendente — as que o <strong>candidato B vence</strong> aparecem primeiro.
    </p>

    <?php foreach ($porMunicipio as $m):
        $aMun = (int)$m['votos_a'];
        $bMun = (int)$m['votos_b'];
        $dMun = (int)$m['dif'];

        if ($aMun > $bMun)      { $tagVenc = '<span class="pct-a">🟢 A vence</span>'; }
        elseif ($bMun > $aMun)  { $tagVenc = '<span class="pct-b">🔴 B vence</span>'; }
        else                    { $tagVenc = '<span class="badge badge-zero">empate</span>'; }
    ?>
        <details class="mun-bloco" <?= $dMun < 0 ? 'open' : '' ?>>
            <summary>
                <?= htmlspecialchars($m['municipio']) ?>
                <?= $tagVenc ?>
                <span class="resumo">
                    <?= number_format($aMun, 0, ',', '.') ?> ×
                    <?= number_format($bMun, 0, ',', '.') ?>
                    (<?= ($dMun > 0 ? '+' : '') . number_format($dMun, 0, ',', '.') ?>)
                </span>
            </summary>

            <table>
                <thead>
                    <tr>
                        <th class="centro" style="width:70px;">Zona</th>
                        <th class="num" style="width:100px;"><?= htmlspecialchars($candA) ?></th>
                        <th class="num" style="width:100px;"><?= htmlspecialchars($candB) ?></th>
                        <th class="num" style="width:100px;">Diferença</th>
                        <th class="centro" style="width:80px;">Vencedor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($m['zonas'] as $z):
                        $za = (int)$z['votos_a'];
                        $zb = (int)$z['votos_b'];
                        $zd = (int)$z['dif'];

                        if ($zd > 0)     { $trCls = 'linha-a-ganha'; $vtxt = '<span class="badge badge-a">🟢 A</span>'; }
                        elseif ($zd < 0) { $trCls = 'linha-b-ganha'; $vtxt = '<span class="badge badge-b">🔴 B</span>'; }
                        else             { $trCls = '';              $vtxt = '<span class="badge badge-zero">empate</span>'; }

                        $zCls = $zd > 0 ? 'badge-pos' : ($zd < 0 ? 'badge-neg' : 'badge-zero');
                    ?>
                        <tr class="<?= $trCls ?>">
                            <td class="centro"><strong><?= (int)$z['zona'] ?></strong></td>
                            <td class="num"><?= number_format($za, 0, ',', '.') ?></td>
                            <td class="num"><?= number_format($zb, 0, ',', '.') ?></td>
                            <td class="num">
                                <span class="badge <?= $zCls ?>">
                                    <?= ($zd > 0 ? '+' : '') . number_format($zd, 0, ',', '.') ?>
                                </span>
                            </td>
                            <td class="centro"><?= $vtxt ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endforeach; ?>

<?php endif; ?>

</body>
</html>