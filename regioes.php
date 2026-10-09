<?php
// ------------------------------------------------------------
// regioes.php — versão sem colunas geradas
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
// NORMALIZAÇÃO — maiúsculas + sem acento + espaço único
// ============================================================
function normalizarMun($s) {
    $s = trim((string)$s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ]);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

// ============================================================
// CACHE DE CONVEX HULL (1 hora)
// ============================================================
$CACHE_HULL_FILE = sys_get_temp_dir() . '/hull_regioes.json';
$CACHE_HULL_TTL  = 3600;

function lerCacheHull($arquivo, $ttl) {
    if (!file_exists($arquivo)) return null;
    if (time() - filemtime($arquivo) > $ttl) return null;
    $json = @file_get_contents($arquivo);
    if ($json === false) return null;
    $d = json_decode($json, true);
    return is_array($d) ? $d : null;
}
function gravarCacheHull($arquivo, $dados) {
    @file_put_contents($arquivo, json_encode($dados, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ============================================================
// Fecho convexo
// ============================================================
function convexHull(array $points): array {
    $n = count($points);
    if ($n < 3) return $points;

    usort($points, function ($a, $b) {
        return $a['lat'] <=> $b['lat'] ?: $a['lng'] <=> $b['lng'];
    });

    $cross = function ($O, $A, $B) {
        return ($A['lat'] - $O['lat']) * ($B['lng'] - $O['lng'])
             - ($A['lng'] - $O['lng']) * ($B['lat'] - $O['lat']);
    };

    $lower = [];
    foreach ($points as $p) {
        while (count($lower) >= 2 &&
               $cross($lower[count($lower)-2], $lower[count($lower)-1], $p) <= 0) {
            array_pop($lower);
        }
        $lower[] = $p;
    }

    $upper = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $p = $points[$i];
        while (count($upper) >= 2 &&
               $cross($upper[count($upper)-2], $upper[count($upper)-1], $p) <= 0) {
            array_pop($upper);
        }
        $upper[] = $p;
    }

    array_pop($lower);
    array_pop($upper);

    return array_merge($lower, $upper);
}

function centroide(array $pontos): array {
    $lat = 0; $lng = 0;
    foreach ($pontos as $p) { $lat += $p['lat']; $lng += $p['lng']; }
    $n = max(1, count($pontos));
    return ['lat' => $lat / $n, 'lng' => $lng / $n];
}

// ============================================================
// FILTRO
// ============================================================
$candidato  = $_GET['candidato'] ?? '';
$temFiltro  = ($candidato !== '');

// ============================================================
// 1) MAPA MUNICÍPIO NORMALIZADO → REGIÃO
//    Carrega a tabela pequena em PHP. Zero JOIN no SQL.
// ============================================================
$mapaRegiaoPorNorm   = [];
$regioesPorId        = [];

$stmt = $pdo->query("
    SELECT mr.nomeMunicipio, mr.regiao_id,
           COALESCE(r.nome, 'Sem região') AS regiao
      FROM municipio_regiao mr
      LEFT JOIN regioes r ON r.id = mr.regiao_id
");
foreach ($stmt->fetchAll() as $row) {
    $chave = normalizarMun($row['nomeMunicipio']);
    $mapaRegiaoPorNorm[$chave] = $row['regiao'];
    $regioesPorId[(int)$row['regiao_id']] = $row['regiao'];
}

// ============================================================
// 2) KPIs — queries mínimas
// ============================================================
$totalRegioes = (int)$pdo->query(
    "SELECT COUNT(DISTINCT regiao_id) FROM municipio_regiao"
)->fetchColumn();

$totalBanco = (int)$pdo->query(
    "SELECT COALESCE(SUM(qtdVotos),0) FROM votos"
)->fetchColumn();

if ($temFiltro) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(qtdVotos),0)
          FROM votos
         WHERE nomeCandidatoResp = :c
    ");
    $stmt->execute([':c' => $candidato]);
    $totalGeral = (int)$stmt->fetchColumn();
} else {
    $totalGeral = $totalBanco;
}

// ============================================================
// 3) AGREGAÇÃO — só em `votos`, sem JOIN
// ============================================================
$where  = $temFiltro ? "WHERE nomeCandidatoResp = ?" : "";
$params = $temFiltro ? [$candidato] : [];

$stmt = $pdo->prepare("
    SELECT nomeMunicipio, SUM(qtdVotos) AS total
      FROM votos
      $where
      GROUP BY nomeMunicipio
      ORDER BY total DESC
");
$stmt->execute($params);
$linhasMun = $stmt->fetchAll();

// Agrega em PHP por região
$porRegiaoId   = [];
$munsPorRegiao = [];

foreach ($linhasMun as $row) {
    $regiaoNome = $mapaRegiaoPorNorm[normalizarMun($row['nomeMunicipio'])]
                  ?? 'Sem região';

    if (!isset($porRegiaoId[$regiaoNome])) {
        $porRegiaoId[$regiaoNome] = [
            'regiao'         => $regiaoNome,
            'total'          => 0,
            'qtd_municipios' => 0,
            'id'             => null,
        ];
        $munsPorRegiao[$regiaoNome] = [];
    }

    $tot = (int)$row['total'];
    $porRegiaoId[$regiaoNome]['total']          += $tot;
    $porRegiaoId[$regiaoNome]['qtd_municipios'] += 1;

    $munsPorRegiao[$regiaoNome][] = [
        'nomeMunicipio' => $row['nomeMunicipio'],
        'total'         => $tot,
    ];
}

$regioes = array_values($porRegiaoId);
usort($regioes, fn($a, $b) => $b['total'] <=> $a['total']);

// Associa id da região (para o mapa)
if ($regioes) {
    $nomesReg = array_column($regioes, 'regiao');
    $idsPorNome = [];
    foreach ($regioesPorId as $rid => $rnome) {
        $idsPorNome[$rnome] = $rid;
    }
    foreach ($regioes as &$r) {
        $r['id'] = $idsPorNome[$r['regiao']] ?? null;
    }
    unset($r);
}

// ============================================================
// 4) MUNICÍPIOS SEM REGIÃO
// ============================================================
$semRegiao      = [];
$totalSemRegiao = 0;

foreach ($linhasMun as $row) {
    $chave = normalizarMun($row['nomeMunicipio']);
    if (!isset($mapaRegiaoPorNorm[$chave])) {
        $semRegiao[]    = $row['nomeMunicipio'];
        $totalSemRegiao += (int)$row['total'];
    }
}

// ============================================================
// 5) MAPA — só quando filtro ativo, com cache de hull
// ============================================================
$dadosMapa      = [];
$totalPontos    = 0;
$regioesComPts  = 0;

if ($temFiltro && $regioes) {

    $cacheValido = lerCacheHull($CACHE_HULL_FILE, $CACHE_HULL_TTL);

    if ($cacheValido !== null) {
        foreach ($regioes as $r) {
            if (!isset($cacheValido[$r['regiao']])) continue;
            $c = $cacheValido[$r['regiao']];
            $dadosMapa[] = [
                'id'         => $r['id'],
                'nome'       => $r['regiao'],
                'codigo'     => '',
                'total'      => (int)$r['total'],
                'municipios' => (int)$r['qtd_municipios'],
                'hull'       => $c['hull'],
                'centro'     => $c['centro'],
                'nPontos'    => $c['nPontos'],
            ];
            $totalPontos   += $c['nPontos'];
            $regioesComPts++;
        }
    } else {
        // Carrega TODAS as seções com coordenada + município
        $sqlPontos = "
            SELECT nomeMunicipio, latitude, longitude
              FROM secoes_enderecos
             WHERE latitude  IS NOT NULL
               AND longitude IS NOT NULL
               AND NOT (latitude = 0 AND longitude = 0)
        ";

        $pontosPorRegiao = [];
        foreach ($pdo->query($sqlPontos)->fetchAll() as $row) {
            $chave = normalizarMun($row['nomeMunicipio']);
            $reg   = $mapaRegiaoPorNorm[$chave] ?? null;
            if ($reg === null) continue;

            $pontosPorRegiao[$reg][] = [
                'lat' => (float)$row['latitude'],
                'lng' => (float)$row['longitude'],
            ];
            $totalPontos++;
        }

        // Calcula hull de cada região e grava no cache
        $cacheNovo = [];
        foreach ($pontosPorRegiao as $regNome => $pts) {
            if (count($pts) < 3) continue;
            $hull = convexHull($pts);
            $cent = centroide($hull);
            $cacheNovo[$regNome] = [
                'hull'    => array_map(fn($p) => [round($p['lat'], 6), round($p['lng'], 6)], $hull),
                'centro'  => [round($cent['lat'], 6), round($cent['lng'], 6)],
                'nPontos' => count($pts),
            ];
        }
        gravarCacheHull($CACHE_HULL_FILE, $cacheNovo);

        foreach ($regioes as $r) {
            if (!isset($cacheNovo[$r['regiao']])) continue;
            $c = $cacheNovo[$r['regiao']];
            $dadosMapa[] = [
                'id'         => $r['id'],
                'nome'       => $r['regiao'],
                'codigo'     => '',
                'total'      => (int)$r['total'],
                'municipios' => (int)$r['qtd_municipios'],
                'hull'       => $c['hull'],
                'centro'     => $c['centro'],
                'nPontos'    => $c['nPontos'],
            ];
            $regioesComPts++;
        }
    }
}

// ============================================================
// 6) LISTA DE CANDIDATOS
// ============================================================
$listaCandidatos = $pdo->query(
    "SELECT DISTINCT nomeCandidatoResp FROM votos ORDER BY nomeCandidatoResp"
)->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Votos por Região — <?= htmlspecialchars($candidato ?: 'Todos os candidatos') ?></title>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    h2 { margin-top:30px; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:200px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#0069d9; margin-top:4px; }
    .kpi .sub    { font-size:12px; color:#888; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor { color:#6f42c1; }

    .form-filtros {
        background:#fff; padding:14px 20px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
        display:flex; flex-wrap:nowrap; align-items:center; gap:10px;
    }
    .form-filtros label { font-weight:bold; color:#333; font-size:14px; white-space:nowrap; flex-shrink:0; }
    .form-filtros .campo-select { flex:1 1 auto; min-width:0; }
    .form-filtros button,
    .form-filtros a.btn-limpar-form {
        padding:8px 18px; border-radius:4px; border:none; cursor:pointer;
        font-size:14px; font-weight:bold; text-decoration:none;
        display:inline-flex; align-items:center; justify-content:center;
        white-space:nowrap; flex-shrink:0; transition: background .15s;
        height:36px; box-sizing:border-box;
    }
    .btn-consultar { background:#0069d9; color:#fff; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar-form { background:#6c757d; color:#fff; }
    .btn-limpar-form:hover { background:#5a6268; }

    .form-filtros .select2-container { width: 100% !important; margin: 0 !important; }
    .form-filtros .select2-container--default .select2-selection--single {
        height: 36px; padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; background: #fff;
    }
    .form-filtros .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 26px; color: #333;
    }
    .form-filtros .select2-container--default .select2-selection--single .select2-selection__arrow { height: 34px; }
    .form-filtros .select2-search--dropdown .select2-search__field { padding: 6px; font-size: 14px; border-radius: 4px; }

    @media (max-width: 640px) {
        .form-filtros { flex-wrap:wrap; }
        .form-filtros .campo-select { flex:1 1 100%; }
        .form-filtros button,
        .form-filtros a.btn-limpar-form { flex:1 1 48%; }
    }

    .msg-sem-filtro {
        background:#fff; border-left:5px solid #0069d9;
        padding:28px 32px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.08);
        text-align:center; margin-bottom: 24px;
    }
    .msg-sem-filtro .icone { font-size:48px; line-height:1; margin-bottom:12px; }
    .msg-sem-filtro h2 { margin:0 0 8px; font-size:18px; color:#0069d9; }
    .msg-sem-filtro p { margin:0; font-size:14px; color:#666; line-height:1.5; }
    .msg-sem-filtro .dica {
        margin-top:14px; font-size:13px; background:#e7f3ff; color:#004085;
        padding:8px 12px; border-radius:6px; display:inline-block;
    }

    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; }
    th { background:#0069d9; color:#fff; }
    tr:nth-child(even) { background:#f2f2f2; }
    tfoot td { font-weight:bold; background:#e6f0fa; }
    .num { text-align:right; }
    .barra {
        background:#e6f0fa; border-radius:4px; height:12px; overflow:hidden;
        display:inline-block; width:120px; vertical-align:middle; margin-left:8px;
    }
    .barra > span { display:block; height:100%; background:#0069d9; }

    details { background:#fff; border-radius:6px; padding:10px 15px; margin-bottom:10px;
              box-shadow:0 1px 2px rgba(0,0,0,.06); }
    summary { cursor:pointer; font-weight:bold; color:#0069d9; padding:4px 0; }
    summary:hover { text-decoration:underline; }

    .alerta {
        background:#fff3cd; border-left:5px solid #ffc107;
        padding:12px 16px; border-radius:6px; margin-bottom:20px;
    }
    .alerta.erro { background:#f8d7da; border-left-color:#dc3545; color:#721c24; }
    .alerta strong { color:#856404; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    #mapa { height: 620px; width: 100%; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.15); background:#e5e5e5;
            margin-bottom: 8px; }
    .mapa-info { font-size:13px; color:#555; margin: 6px 0 14px; }

    .legenda-mapa {
        background:#fff; padding:10px 14px; border-radius:6px;
        box-shadow: 0 1px 2px rgba(0,0,0,.08); font-size:13px;
        display:flex; flex-wrap:wrap; gap:14px; margin-bottom: 20px;
    }
    .legenda-mapa .item { display:flex; align-items:center; gap:6px; }
    .legenda-mapa .cor  { width:14px; height:14px; border-radius:3px;
                          display:inline-block; border:1px solid rgba(0,0,0,.15); }

    .regiao-label { background: transparent !important; border: none !important; pointer-events: none; }
    .regiao-label .caixa {
        background: rgba(255,255,255,.94);
        border: 2px solid #333; border-radius: 6px;
        padding: 4px 8px; font-family: Arial, sans-serif;
        font-size: 12px; font-weight: bold; color: #222;
        text-align: center; box-shadow: 0 2px 6px rgba(0,0,0,.25);
        white-space: nowrap; line-height: 1.25;
    }
    .regiao-label .caixa .votos { display:block; font-weight: normal; font-size: 11px; color:#444; }

    .leaflet-popup-content { font-family: Arial, sans-serif; font-size: 13px; }
    .leaflet-popup-content b { color:#0069d9; }

    .grafico-wrap {
        background:#fff; border-radius:8px; padding:20px;
        box-shadow: 0 1px 3px rgba(0,0,0,.1); margin-bottom: 20px;
    }
    .grafico-grid {
        display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start;
    }
    @media (max-width: 900px) { .grafico-grid { grid-template-columns: 1fr; } }
    .grafico-box { position: relative; height: 460px; }
    .grafico-titulo {
        font-size: 14px; font-weight: bold; color:#0069d9;
        margin-bottom: 8px; text-align:center;
    }
    .grafico-tabela table { margin: 0; }
    .grafico-tabela th { background:#0069d9; color:#fff; }

    .debug-info {
        background:#1e1e1e; color:#d4d4d4;
        padding:10px 14px; border-radius:6px;
        font-family:'Courier New', monospace; font-size:11.5px;
        margin-bottom:14px; border-left:5px solid #ffc107;
    }
    .debug-info b { color:#ffc107; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Votos por Região</h1>

<form method="get" action="regioes.php" class="form-filtros">
    <label for="candidato">Candidato:</label>

    <div class="campo-select">
        <select name="candidato" id="candidato">
            <option value="">— Selecione um candidato —</option>
            <?php foreach ($listaCandidatos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= $c === $candidato ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" class="btn-consultar">🔎 Consultar</button>
    <a href="regioes.php" class="btn-limpar-form">✖ Limpar</a>
</form>

<div class="kpis">
    <div class="kpi">
        <div class="rotulo"><?= $temFiltro ? 'Votos do candidato' : 'Total geral (todos)' ?></div>
        <div class="valor"><?= number_format($totalGeral, 0, ',', '.') ?></div>
        <?php if ($temFiltro): ?>
            <div class="sub">
                <?= number_format($totalBanco > 0 ? ($totalGeral / $totalBanco) * 100 : 0, 2, ',', '.') ?>%
                do total do banco
            </div>
        <?php endif; ?>
    </div>
    <div class="kpi verde">
        <div class="rotulo">Regiões com dados</div>
        <div class="valor"><?= number_format(count($regioes), 0, ',', '.') ?></div>
    </div>
    <?php if ($totalSemRegiao > 0): ?>
    <div class="kpi laranja">
        <div class="rotulo">Sem região</div>
        <div class="valor"><?= number_format($totalSemRegiao, 0, ',', '.') ?></div>
    </div>
    <?php endif; ?>
    <div class="kpi cinza">
        <div class="rotulo">Total no banco (todos)</div>
        <div class="valor"><?= number_format($totalBanco, 0, ',', '.') ?></div>
    </div>
</div>

<?php if ($semRegiao): ?>
    <div class="alerta">
        <strong>Atenção:</strong> os municípios abaixo estão em <code>votos</code>
        mas não estão em <code>municipio_regiao</code>:
        <ul>
            <?php foreach (array_slice($semRegiao, 0, 30) as $m): ?>
                <li><?= htmlspecialchars($m) ?></li>
            <?php endforeach; ?>
            <?php if (count($semRegiao) > 30): ?>
                <li><em>... e mais <?= count($semRegiao) - 30 ?> município(s)</em></li>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($temFiltro): ?>
    <div class="debug-info">
        🔍 <b>Diagnóstico:</b>
        candidato = <b><?= htmlspecialchars($candidato) ?></b> ·
        regiões com votos = <b><?= count($regioes) ?></b> ·
        seções com coordenada = <b><?= number_format($totalPontos, 0, ',', '.') ?></b> ·
        regiões com polígono (≥ 3 pontos) = <b><?= $regioesComPts ?></b> ·
        dados enviados ao JS = <b><?= count($dadosMapa) ?> região(ões)</b>
    </div>
<?php endif; ?>

<h2>Mapa das regiões</h2>

<?php if (!$temFiltro): ?>

    <div class="msg-sem-filtro">
        <div class="icone">🗺️</div>
        <h2>Selecione um candidato para visualizar o mapa</h2>
        <p>Escolha um candidato no filtro acima e clique em <strong>Consultar</strong>.</p>
        <div class="dica">
            💡 Enquanto nenhum candidato estiver selecionado, apenas os totais
            gerais são exibidos.
        </div>
    </div>

<?php elseif (!$dadosMapa): ?>

    <div class="alerta erro">
        <strong>Mapa indisponível.</strong>
        Nenhuma região tem coordenadas suficientes (≥ 3 pontos em
        <code>secoes_enderecos</code>) para desenhar um polígono.
        <br><br>
        <b>Diagnóstico:</b> <?= number_format($totalPontos, 0, ',', '.') ?>
        seções com coordenada encontradas, mas
        <?= $regioesComPts ?> região(ões) com ≥ 3 pontos.
    </div>

<?php else: ?>

    <div class="mapa-info">
        Cada polígono é o <strong>fecho convexo</strong> das seções eleitorais
        da região, mostrando os votos de <b><?= htmlspecialchars($candidato) ?></b>.
    </div>

    <div class="legenda-mapa" id="legenda-mapa"></div>
    <div id="mapa"></div>

    <script>
        const REGIOES = <?= json_encode($dadosMapa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <script>
        (function () {
            'use strict';

            const CORES = [
                '#e6194B', '#3cb44b', '#4363d8', '#f58231', '#911eb4',
                '#42d4f4', '#f032e6', '#bfef45', '#fabed4', '#469990',
                '#dcbeff', '#9A6324', '#800000', '#aaffc3', '#808000',
                '#ffd8b1', '#000075', '#00aaff', '#a9a9a9', '#ff7f00',
            ];

            function formatNum(n) { return Number(n).toLocaleString('pt-BR'); }
            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, c => ({
                    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
                })[c]);
            }

            if (typeof L === 'undefined' || !REGIOES || !REGIOES.length) return;

            const mapa = L.map('mapa', { scrollWheelZoom: true })
                           .setView([-8.0, -38.0], 7);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(mapa);

            const bounds = L.latLngBounds([]);
            const legenda = document.getElementById('legenda-mapa');

            REGIOES.forEach((reg, idx) => {
                const cor = CORES[idx % CORES.length];
                const latlngs = reg.hull;
                if (!latlngs || latlngs.length < 3) return;

                const poligono = L.polygon(latlngs, {
                    color: cor, weight: 2, opacity: 0.9,
                    fillColor: cor, fillOpacity: 0.30,
                }).addTo(mapa);

                poligono.bindPopup(`
                    <div style="min-width:200px;">
                        <div style="font-weight:bold;color:${cor};font-size:14px;">
                            ${escapeHtml(reg.nome)}
                        </div>
                        <hr style="border:none;border-top:1px solid #eee;margin:6px 0;">
                        <div><b>${formatNum(reg.total)}</b> votos</div>
                        <div style="font-size:12px;color:#555;">
                            ${formatNum(reg.municipios)} município(s)
                        </div>
                    </div>
                `);
                poligono.bindTooltip(
                    reg.nome + ' — ' + formatNum(reg.total) + ' votos',
                    { sticky: true }
                );

                const labelIcon = L.divIcon({
                    className: 'regiao-label',
                    html: `<div class="caixa" style="border-color:${cor};">
                              ${escapeHtml(reg.nome)}
                              <span class="votos">${formatNum(reg.total)} votos</span>
                           </div>`,
                    iconSize: [140, 40],
                    iconAnchor: [70, 20],
                });
                L.marker(reg.centro, {
                    icon: labelIcon, interactive: false, keyboard: false,
                }).addTo(mapa);

                reg.hull.forEach(p => bounds.extend(p));

                const item = document.createElement('span');
                item.className = 'item';
                item.innerHTML = `<span class="cor" style="background:${cor};"></span>
                                  ${escapeHtml(reg.nome)} — ${formatNum(reg.total)}`;
                legenda.appendChild(item);
            });

            if (bounds.isValid()) {
                mapa.fitBounds(bounds, { padding: [30, 30] });
            }
            setTimeout(() => mapa.invalidateSize(), 200);
        })();
    </script>
<?php endif; ?>

<h2>Distribuição dos votos por região</h2>

<?php if (!$regioes): ?>
    <div class="alerta">
        Nenhuma região com votos<?= $temFiltro ? ' para este candidato' : '' ?>.
    </div>
<?php else: ?>
    <div class="grafico-wrap">
        <div class="grafico-grid">
            <div>
                <div class="grafico-titulo">
                    <?= $temFiltro
                        ? 'Votos de ' . htmlspecialchars($candidato) . ' por região (%)'
                        : 'Votos por região (%)' ?>
                </div>
                <div class="grafico-box">
                    <canvas id="grafico-pizza"></canvas>
                </div>
            </div>
            <div class="grafico-tabela">
                <div class="grafico-titulo">Detalhamento</div>
                <table>
                    <thead>
                        <tr>
                            <th>Região</th>
                            <th class="num">Votos</th>
                            <th class="num">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regioes as $r):
                            $pct = $totalGeral > 0 ? ($r['total'] / $totalGeral) * 100 : 0;
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($r['regiao']) ?></td>
                                <td class="num"><?= number_format((int)$r['total'], 0, ',', '.') ?></td>
                                <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="num"><?= number_format($totalGeral, 0, ',', '.') ?></td>
                            <td class="num">100,00%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <script>
        const DADOS_PIZZA = <?= json_encode(array_map(function ($r) {
            return ['nome' => $r['regiao'], 'total' => (int)$r['total']];
        }, $regioes), JSON_UNESCAPED_UNICODE) ?>;

        (function () {
            const CORES = [
                '#e6194B', '#3cb44b', '#4363d8', '#f58231', '#911eb4',
                '#42d4f4', '#f032e6', '#bfef45', '#fabed4', '#469990',
                '#dcbeff', '#9A6324', '#800000', '#aaffc3', '#808000',
                '#ffd8b1', '#000075', '#00aaff', '#a9a9a9', '#ff7f00',
            ];

            if (typeof Chart === 'undefined' || !DADOS_PIZZA.length) return;

            const ctx = document.getElementById('grafico-pizza').getContext('2d');
            const labels = DADOS_PIZZA.map(d => d.nome);
            const valores = DADOS_PIZZA.map(d => d.total);
            const cores = DADOS_PIZZA.map((_, i) => CORES[i % CORES.length]);
            const total = valores.reduce((a, b) => a + b, 0);

            function formatNum(n) { return Number(n).toLocaleString('pt-BR'); }

            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: valores,
                        backgroundColor: cores,
                        borderColor: '#fff',
                        borderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 14, boxHeight: 14, padding: 10,
                                font: { size: 12 },
                                generateLabels: function (chart) {
                                    const ds = chart.data.datasets[0];
                                    return chart.data.labels.map((label, i) => {
                                        const v = ds.data[i];
                                        const pct = total > 0 ? (v / total) * 100 : 0;
                                        return {
                                            text: label + ' (' + pct.toFixed(1) + '%)',
                                            fillStyle: ds.backgroundColor[i],
                                            strokeStyle: ds.backgroundColor[i],
                                            lineWidth: 0,
                                            hidden: false,
                                            index: i,
                                        };
                                    });
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    const v = ctx.parsed;
                                    const pct = total > 0 ? (v / total) * 100 : 0;
                                    return ' ' + ctx.label + ': ' +
                                           formatNum(v) + ' votos (' +
                                           pct.toFixed(2) + '%)';
                                }
                            }
                        }
                    }
                }
            });
        })();
    </script>
<?php endif; ?>

<h2><?= $temFiltro ? 'Total por região — ' . htmlspecialchars($candidato) : 'Total por região' ?></h2>

<?php if (!$regioes): ?>
    <div class="alerta">Nenhum dado encontrado.</div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Região</th>
                <th class="num">Municípios</th>
                <th class="num">Votos</th>
                <th class="num">% do total</th>
                <th>Proporção</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $pos = 1;
            $maiorReg = $regioes[0]['total'] ?? 0;
            foreach ($regioes as $r):
                $pct     = $totalGeral > 0 ? ($r['total'] / $totalGeral) * 100 : 0;
                $largura = $maiorReg > 0 ? ($r['total'] / $maiorReg) * 100 : 0;
            ?>
                <tr>
                    <td><?= $pos++ ?></td>
                    <td><?= htmlspecialchars($r['regiao']) ?></td>
                    <td class="num"><?= number_format($r['qtd_municipios'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format((int)$r['total'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                    <td>
                        <span class="barra"><span style="width: <?= number_format($largura, 2, '.', '') ?>%"></span></span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                <td class="num">
                    <?php
                        $somaMunicipios = 0;
                        foreach ($regioes as $r) $somaMunicipios += $r['qtd_municipios'];
                        echo number_format($somaMunicipios, 0, ',', '.');
                    ?>
                </td>
                <td class="num"><?= number_format($totalGeral, 0, ',', '.') ?></td>
                <td class="num">100,00%</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
<?php endif; ?>

<?php if ($regioes): ?>
    <h2>Detalhamento por município</h2>

    <?php foreach ($regioes as $r): ?>
        <details>
            <summary>
                <?= htmlspecialchars($r['regiao']) ?>
                — <?= number_format((int)$r['total'], 0, ',', '.') ?> votos
                em <?= number_format($r['qtd_municipios'], 0, ',', '.') ?> municípios
            </summary>
            <table>
                <thead>
                    <tr>
                        <th>Município</th>
                        <th class="num">Votos</th>
                        <th class="num">% da região</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($munsPorRegiao[$r['regiao']] ?? [] as $m):
                        $pctReg = $r['total'] > 0 ? ($m['total'] / $r['total']) * 100 : 0;
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($m['nomeMunicipio']) ?></td>
                            <td class="num"><?= number_format((int)$m['total'], 0, ',', '.') ?></td>
                            <td class="num"><?= number_format($pctReg, 2, ',', '.') ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endforeach; ?>
<?php endif; ?>

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

        jQuery('#candidato').select2({
            language: 'pt-BR',
            placeholder: '— Selecione um candidato —',
            allowClear: true,
            width: '100%',
            minimumInputLength: 0,
            matcher: matcherSemAcento
        });

        jQuery('#candidato').on('change', function () {
            if (jQuery(this).val() === '') {
                window.location.href = 'regioes.php';
            }
        });
    })();
</script>

</body>
</html>