<?php
// ------------------------------------------------------------
// Configuração do banco
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
date_default_timezone_set('America/Sao_Paulo');

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ------------------------------------------------------------
// Detecta se a coluna 'ano' existe
// ------------------------------------------------------------
$colunasVotos = $pdo->query("SHOW COLUMNS FROM votos")->fetchAll(PDO::FETCH_COLUMN);
$temAno = in_array('ano', $colunasVotos, true);

// ------------------------------------------------------------
// Filtros recebidos
// ------------------------------------------------------------
$ano       = $_GET['ano']       ?? '';
$regiao    = $_GET['regiao']    ?? '';
$cargo     = $_GET['cargo']     ?? '';
$cand1     = $_GET['cand1']     ?? '';
$cand2     = $_GET['cand2']     ?? '';
$opacidade = isset($_GET['opac']) ? (float)$_GET['opac'] : 0.65;
$raio      = isset($_GET['raio']) ? (int)$_GET['raio']    : 22;

if ($opacidade < 0.1) $opacidade = 0.1;
if ($opacidade > 1)   $opacidade = 1;
if ($raio < 5)  $raio = 5;
if ($raio > 80) $raio = 80;

// ------------------------------------------------------------
// Regras de seleção
// ------------------------------------------------------------
$temC1 = ($cand1 !== '');
$temC2 = ($cand2 !== '' && $cand2 !== $cand1);
$temAlgum = $temC1 || $temC2;
$temComparacao = ($temC1 && $temC2);

// ------------------------------------------------------------
// Coleta de dados
// ------------------------------------------------------------
$pontos      = [];
$maxC1       = 0;
$maxC2       = 0;
$maxDiff     = 0;
$totC1       = 0;
$totC2       = 0;
$totalSecoes = 0;

if ($temAlgum) {
    $where  = [];
    $params = [];

    if ($temComparacao) {
        $where[] = "v.nomeCandidatoResp IN (:c1, :c2)";
        $params[':c1'] = $cand1;
        $params[':c2'] = $cand2;
    } elseif ($temC1) {
        $where[] = "v.nomeCandidatoResp = :c1";
        $params[':c1'] = $cand1;
    } else {
        $where[] = "v.nomeCandidatoResp = :c2";
        $params[':c2'] = $cand2;
    }

    if ($temAno && $ano !== '') {
        $where[] = "v.ano = :ano";
        $params[':ano'] = (int)$ano;
    }
    if ($regiao !== '') {
        $where[] = "mr.regiao_id = :regiao";
        $params[':regiao'] = (int)$regiao;
    }
    if ($cargo !== '') {
        $where[] = "v.nomeCargoPerg = :cargo";
        $params[':cargo'] = $cargo;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    if ($temComparacao) {
        // ------------------ Modo COMPARAÇÃO ------------------
        $sql = "
            SELECT
                s.numeroZona,
                s.numeroSecao,
                s.nomeLocal,
                s.nomeMunicipio,
                s.latitude,
                s.longitude,
                SUM(CASE WHEN v.nomeCandidatoResp = :c1 THEN v.qtdVotos ELSE 0 END) AS votos_c1,
                SUM(CASE WHEN v.nomeCandidatoResp = :c2 THEN v.qtdVotos ELSE 0 END) AS votos_c2
            FROM votos v
            INNER JOIN secoes_enderecos s
                ON s.numeroZona  = v.numeroZona
               AND s.numeroSecao = v.numeroSecao
            LEFT JOIN municipio_regiao mr
                ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
                 = s.nomeMunicipio  COLLATE utf8mb4_general_ci
            $whereSQL
            GROUP BY s.numeroZona, s.numeroSecao, s.nomeLocal,
                     s.nomeMunicipio, s.latitude, s.longitude
        ";
    } else {
        // ------------------ Modo ÚNICO ------------------
        $sql = "
            SELECT
                s.numeroZona,
                s.numeroSecao,
                s.nomeLocal,
                s.nomeMunicipio,
                s.latitude,
                s.longitude,
                SUM(v.qtdVotos) AS votos_c1,
                0 AS votos_c2
            FROM votos v
            INNER JOIN secoes_enderecos s
                ON s.numeroZona  = v.numeroZona
               AND s.numeroSecao = v.numeroSecao
            LEFT JOIN municipio_regiao mr
                ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
                 = s.nomeMunicipio  COLLATE utf8mb4_general_ci
            $whereSQL
            GROUP BY s.numeroZona, s.numeroSecao, s.nomeLocal,
                     s.nomeMunicipio, s.latitude, s.longitude
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $lat = (float)$row['latitude'];
        $lng = (float)$row['longitude'];
        if ($lat === 0.0 && $lng === 0.0) continue;

        $c1 = (int)$row['votos_c1'];
        $c2 = (int)$row['votos_c2'];
        if ($c1 === 0 && $c2 === 0) continue;

        $pontos[] = [
            'lat'    => $lat,
            'lng'    => $lng,
            'zona'   => (int)$row['numeroZona'],
            'secao'  => (int)$row['numeroSecao'],
            'local'  => $row['nomeLocal'] ?? '',
            'mun'    => $row['nomeMunicipio'] ?? '',
            'c1'     => $c1,
            'c2'     => $c2,
        ];

        $totC1 += $c1;
        $totC2 += $c2;
        $totalSecoes++;

        if ($c1 > $maxC1) $maxC1 = $c1;
        if ($c2 > $maxC2) $maxC2 = $c2;
        $d = abs($c1 - $c2);
        if ($d > $maxDiff) $maxDiff = $d;
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
<title>Mapa de Calor — Candidatos</title>

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
    h1 { color:#333; }
    h2 { margin-top:30px; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:190px;
        border-left:5px solid #0069d9;
        transition: opacity .2s, filter .2s;
    }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px;
                   display:flex; align-items:center; gap:6px; }
    .kpi .rotulo .oculto-tag {
        font-size: 9px;
        background: #eee;
        color: #888;
        padding: 1px 6px;
        border-radius: 8px;
        letter-spacing: 0;
        text-transform: none;
        display: none;
    }
    .kpi.oculto .oculto-tag { display: inline-block; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#0069d9; margin-top:4px; }
    .kpi .sub    { font-size:12px; color:#888; margin-top:2px; }
    .kpi.c1      { border-left-color:#dc3545; } .kpi.c1 .valor { color:#dc3545; }
    .kpi.c2      { border-left-color:#0d6efd; } .kpi.c2 .valor { color:#0d6efd; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor { color:#6f42c1; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }

    .kpi.oculto {
        opacity: 0.35;
        filter: grayscale(1);
    }
    .kpi.oculto .valor,
    .kpi.oculto .rotulo {
        text-decoration: line-through;
    }

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

    /* Select2 */
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
    .cand-tag.c1 .dot { background:#dc3545; }
    .cand-tag.c2 .dot { background:#0d6efd; }

    .modo-btns {
        display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px;
    }
    .modo-btns button {
        background:#fff; border:2px solid #e3e3e3;
        padding:8px 14px; border-radius:6px; cursor:pointer;
        font-size:13px; color:#555; transition: all .12s ease;
    }
    .modo-btns button:hover:not(:disabled) { border-color:#0069d9; color:#0069d9; }
    .modo-btns button.ativo {
        border-color:#0069d9; color:#0069d9; font-weight:bold;
        background:#e7f3ff;
    }
    .modo-btns button:disabled {
        opacity: .4;
        cursor: not-allowed;
        text-decoration: line-through;
    }

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

    /* Toggles dos candidatos */
    .cand-toggle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        padding: 5px 12px;
        border-radius: 6px;
        border: 2px solid #e3e3e3;
        background: #fff;
        font-size: 12.5px;
        font-weight: bold;
        transition: all .15s;
        user-select: none;
    }
    .cand-toggle:hover { border-color: #0069d9; }
    .cand-toggle input { margin: 0; cursor: pointer; }
    .cand-toggle .cor {
        width: 14px; height: 14px; border-radius: 3px;
        display: inline-block;
        border: 1px solid rgba(0,0,0,.15);
    }
    .cand-toggle.c1 .cor { background: #dc3545; }
    .cand-toggle.c2 .cor { background: #0d6efd; }
    .cand-toggle.c1.ativo { border-color: #dc3545; background: #fff5f6; }
    .cand-toggle.c2.ativo { border-color: #0d6efd; background: #f0f6ff; }
    .cand-toggle.c1.ativo span.nome { color: #dc3545; }
    .cand-toggle.c2.ativo span.nome { color: #0d6efd; }
    .cand-toggle.oculto {
        border-style: dashed;
        border-color: #ccc;
        background: #f4f4f4;
        color: #999;
    }
    .cand-toggle.oculto span.nome {
        color: #999;
        text-decoration: line-through;
    }
    .cand-toggle.oculto .cor {
        opacity: .25;
    }
    .cand-toggle .nome {
        color: #555;
        max-width: 160px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .cand-toggle .contador {
        background: #eee;
        color: #555;
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 8px;
        font-weight: bold;
        margin-left: 2px;
    }
    .cand-toggle.c1.ativo .contador { background: #f8d7da; color: #8a2a33; }
    .cand-toggle.c2.ativo .contador { background: #cfe2ff; color: #0a3a80; }

    #mapa { height: 620px; width: 100%; border-radius: 8px;
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
        width: 110px; height: 12px; border-radius:3px;
        border: 1px solid #ddd;
    }
    .grad-c1 { background: linear-gradient(to right, #ffe6e6, #ff8a80, #e53935, #8e0000); }
    .grad-c2 { background: linear-gradient(to right, #e3f2fd, #64b5f6, #1976d2, #0d47a1); }
    .grad-dif {
        background: linear-gradient(to right,
            #0d47a1, #64b5f6, #eeeeee, #ff8a65, #b71c1c);
    }
    .legenda-vazia {
        color:#b94a48; font-weight:bold; font-size:13px;
    }

    .modo-unico-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #e7f3ff;
        color: #0053b3;
        border-left: 4px solid #0069d9;
        padding: 8px 14px;
        border-radius: 6px;
        font-size: 13px;
        margin-bottom: 14px;
    }
    .modo-unico-badge b { font-weight: bold; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso code { background:#fff; padding:1px 5px; border-radius:3px; font-size:12px; border:1px solid #eee; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    .leaflet-popup-content { font-family: Arial, sans-serif; font-size: 13px; }
    .leaflet-popup-content b { color:#0069d9; }
    .popup-c1 { color:#dc3545; font-weight:bold; }
    .popup-c2 { color:#0d6efd; font-weight:bold; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Mapa de Calor — Candidatos</h1>

<?php if (!$temAno): ?>
    <div class="aviso">
        <strong>Dica:</strong> a coluna <code>ano</code> ainda não existe em <code>votos</code>.
        O filtro de ano está desabilitado.
    </div>
<?php endif; ?>

<form method="get" action="mapa_calor_dois.php" id="form-filtros">
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
        <a href="mapa_calor_dois.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<?php if (!$temAlgum): ?>

    <div class="aviso">
        Selecione <strong>um ou dois candidatos</strong> para gerar o mapa.
        <br>
        <small style="color:#666;">
            Com 1 candidato → heatmap único.
            Com 2 candidatos → modos sobreposto / apenas um / diferença.
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
                <div class="rotulo">
                    <?= htmlspecialchars($cand1) ?>
                    <span class="oculto-tag">ocultado</span>
                </div>
                <div class="valor"><?= number_format($totC1, 0, ',', '.') ?></div>
                <div class="sub">
                    votos em <?= number_format($totalSecoes, 0, ',', '.') ?> seções
                </div>
            </div>
        <?php endif; ?>

        <?php if ($temC2): ?>
            <div class="kpi c2" id="kpiC2">
                <div class="rotulo">
                    <?= htmlspecialchars($cand2) ?>
                    <span class="oculto-tag">ocultado</span>
                </div>
                <div class="valor"><?= number_format($totC2, 0, ',', '.') ?></div>
                <div class="sub">
                    votos em <?= number_format($totalSecoes, 0, ',', '.') ?> seções
                </div>
            </div>
        <?php endif; ?>

        <?php if ($temComparacao): ?>
            <div class="kpi roxo">
                <div class="rotulo">Diferença</div>
                <div class="valor">
                    <?= number_format(abs($totC1 - $totC2), 0, ',', '.') ?>
                </div>
                <div class="sub">
                    <?php
                    $lider = $totC1 > $totC2 ? $cand1 : ($totC2 > $totC1 ? $cand2 : 'Empate');
                    if ($lider === 'Empate') {
                        echo 'Empate técnico';
                    } else {
                        $vantagem = abs($totC1 - $totC2);
                        $total    = $totC1 + $totC2;
                        $pct      = $total > 0 ? ($vantagem / $total) * 100 : 0;
                        echo htmlspecialchars($lider) . ' lidera por ' .
                             number_format($pct, 1, ',', '.') . '%';
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="kpi cinza">
            <div class="rotulo">Seções no mapa</div>
            <div class="valor"><?= number_format($totalSecoes, 0, ',', '.') ?></div>
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
            <button type="button" data-modo="sobreposto" class="ativo">
                🔴🔵 Sobreposto
            </button>
            <button type="button" data-modo="c1" id="btnModoC1">
                🔴 Apenas <?= htmlspecialchars($cand1) ?>
            </button>
            <button type="button" data-modo="c2" id="btnModoC2">
                🔵 Apenas <?= htmlspecialchars($cand2) ?>
            </button>
            <button type="button" data-modo="diferenca" id="btnModoDif">
                ⚖️ Diferença
            </button>
        </div>
    <?php endif; ?>

    <div class="controles">
        <label for="ctrlRaio">Intensidade:</label>
        <input type="range" id="ctrlRaio" min="5" max="80" step="1" value="<?= (int)$raio ?>">
        <span class="valor" id="ctrlRaioVal"><?= (int)$raio ?></span>

        <label for="ctrlOpac">Opacidade:</label>
        <input type="range" id="ctrlOpac" min="0.1" max="1" step="0.05" value="<?= htmlspecialchars($opacidade) ?>">
        <span class="valor" id="ctrlOpacVal"><?= htmlspecialchars(number_format($opacidade, 2)) ?></span>

        <span class="sep">
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                <input type="checkbox" id="mostrarPontos" checked> mostrar seções
            </label>
        </span>

        <?php if ($temComparacao): ?>
            <span class="sep">
                <label class="cand-toggle c1 ativo" id="toggleC1">
                    <input type="checkbox" id="mostrarC1" checked>
                    <span class="cor"></span>
                    <span class="nome" title="<?= htmlspecialchars($cand1) ?>">
                        <?= htmlspecialchars($cand1) ?>
                    </span>
                    <span class="contador" id="contC1">0</span>
                </label>
            </span>
            <span>
                <label class="cand-toggle c2 ativo" id="toggleC2">
                    <input type="checkbox" id="mostrarC2" checked>
                    <span class="cor"></span>
                    <span class="nome" title="<?= htmlspecialchars($cand2) ?>">
                        <?= htmlspecialchars($cand2) ?>
                    </span>
                    <span class="contador" id="contC2">0</span>
                </label>
            </span>
        <?php endif; ?>
    </div>

    <div class="legenda-cores" id="legenda-cores"></div>

    <div id="mapa"></div>

    <script>
        const PONTOS   = <?= json_encode($pontos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const CAND1    = <?= json_encode($cand1, JSON_UNESCAPED_UNICODE) ?>;
        const CAND2    = <?= json_encode($cand2, JSON_UNESCAPED_UNICODE) ?>;
        const TEM_C1   = <?= $temC1 ? 'true' : 'false' ?>;
        const TEM_C2   = <?= $temC2 ? 'true' : 'false' ?>;
        const TEM_COMP = <?= $temComparacao ? 'true' : 'false' ?>;

        const MAX_C1   = <?= (int)$maxC1 ?>;
        const MAX_C2   = <?= (int)$maxC2 ?>;
        const MAX_DIFF = <?= (int)$maxDiff ?>;
        const RAIO     = <?= (int)$raio ?>;
        const OPAC     = <?= json_encode((float)$opacidade) ?>;
    </script>

    <script>
        (function () {
            function formatNum(n) { return Number(n).toLocaleString('pt-BR'); }
            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, c => ({
                    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
                })[c]);
            }

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

            const GRAD_C1 = { 0.0:'#ffe6e6', 0.4:'#ff8a80', 0.7:'#e53935', 1.0:'#8e0000' };
            const GRAD_C2 = { 0.0:'#e3f2fd', 0.4:'#64b5f6', 0.7:'#1976d2', 1.0:'#0d47a1' };
            const GRAD_DIF = {
                0.0:'#0d47a1', 0.35:'#64b5f6', 0.5:'#eeeeee',
                0.65:'#ff8a65', 1.0:'#b71c1c'
            };

            const dataC1 = PONTOS.map(p => [
                p.lat, p.lng, MAX_C1 > 0 ? (p.c1 / MAX_C1) : 0
            ]);
            const dataC2 = PONTOS.map(p => [
                p.lat, p.lng, MAX_C2 > 0 ? (p.c2 / MAX_C2) : 0
            ]);
            const dataDif = PONTOS.map(p => {
                if (MAX_DIFF === 0) return [p.lat, p.lng, 0.5];
                const diff = p.c1 - p.c2;
                const w = 0.5 + 0.5 * (diff / MAX_DIFF);
                return [p.lat, p.lng, Math.max(0, Math.min(1, w))];
            });

            const heatC1 = L.heatLayer(dataC1, {
                radius: RAIO, blur: Math.round(RAIO * 0.8),
                maxZoom: 17, minOpacity: 0.2, opacity: OPAC,
                gradient: GRAD_C1
            });
            const heatC2 = L.heatLayer(dataC2, {
                radius: RAIO, blur: Math.round(RAIO * 0.8),
                maxZoom: 17, minOpacity: 0.2, opacity: OPAC,
                gradient: GRAD_C2
            });
            const heatDif = L.heatLayer(dataDif, {
                radius: RAIO + 4, blur: Math.round(RAIO * 1.0),
                maxZoom: 17, minOpacity: 0.2, opacity: OPAC,
                gradient: GRAD_DIF
            });

            const marcadoresC1  = [];
            const marcadoresC2  = [];
            const marcadoresEmp = [];

            PONTOS.forEach(p => {
                const raioPx = 4 + 8 * Math.sqrt(
                    Math.max(p.c1, p.c2) / Math.max(MAX_C1, MAX_C2, 1)
                );

                let lider, corFill;
                if (TEM_COMP) {
                    lider = p.c1 > p.c2 ? 'c1' : (p.c2 > p.c1 ? 'c2' : 'emp');
                    corFill = lider === 'c1' ? '#dc3545'
                            : lider === 'c2' ? '#0d6efd' : '#999';
                } else {
                    lider = TEM_C1 ? 'c1' : 'c2';
                    corFill = TEM_C1 ? '#dc3545' : '#0d6efd';
                }

                const linhas = [];
                if (TEM_C1) {
                    linhas.push(`
                        <div class="popup-linha-c1">
                            <span class="popup-c1">● ${escapeHtml(CAND1)}</span>:
                            <b>${formatNum(p.c1)}</b> votos
                        </div>`);
                }
                if (TEM_C2) {
                    linhas.push(`
                        <div class="popup-linha-c2">
                            <span class="popup-c2">● ${escapeHtml(CAND2)}</span>:
                            <b>${formatNum(p.c2)}</b> votos
                        </div>`);
                }

                let linhaLider = '';
                if (TEM_COMP) {
                    linhaLider = `
                        <div class="popup-lider" style="margin-top:4px;font-size:12px;color:#666;">
                            ${lider === 'emp'
                                ? 'Empate'
                                : 'Lidera: ' + escapeHtml(lider === 'c1' ? CAND1 : CAND2) +
                                  ' (+' + formatNum(Math.abs(p.c1 - p.c2)) + ')'}
                        </div>`;
                }

                const html = `
                    <div style="min-width:220px;">
                        <div><b>Seção ${p.secao}</b> — Zona ${p.zona}</div>
                        ${p.local ? '<div style="font-size:12px;color:#555;margin-top:3px;">' +
                                     escapeHtml(p.local) + '</div>' : ''}
                        ${p.mun   ? '<div style="font-size:12px;color:#888;">' +
                                     escapeHtml(p.mun) + '</div>' : ''}
                        <hr style="border:none;border-top:1px solid #eee;margin:6px 0;">
                        ${linhas.join('')}
                        ${linhaLider}
                    </div>`;

                const marker = L.circleMarker([p.lat, p.lng], {
                    radius: raioPx,
                    color: '#444',
                    weight: 1,
                    fillColor: corFill,
                    fillOpacity: 0.35,
                    opacity: 0
                }).addTo(mapa);

                marker.bindPopup(html);

                let tt = 'Seção ' + p.secao + ' (Zona ' + p.zona + ')';
                if (TEM_C1 && TEM_C2) {
                    tt += '<br>' + CAND1 + ': ' + formatNum(p.c1) +
                          ' · ' + CAND2 + ': ' + formatNum(p.c2);
                } else if (TEM_C1) {
                    tt += '<br>' + CAND1 + ': ' + formatNum(p.c1) + ' votos';
                } else if (TEM_C2) {
                    tt += '<br>' + CAND2 + ': ' + formatNum(p.c2) + ' votos';
                }
                marker.bindTooltip(tt, { sticky: true });

                if (lider === 'c1')       marcadoresC1.push(marker);
                else if (lider === 'c2')  marcadoresC2.push(marker);
                else                      marcadoresEmp.push(marker);
            });

            const elContC1 = document.getElementById('contC1');
            const elContC2 = document.getElementById('contC2');
            if (elContC1) elContC1.textContent = marcadoresC1.length;
            if (elContC2) elContC2.textContent = marcadoresC2.length;

            const modosBtns  = document.querySelectorAll('#modo-btns button');
            const btnModoC1  = document.getElementById('btnModoC1');
            const btnModoC2  = document.getElementById('btnModoC2');
            const ckC1       = document.getElementById('mostrarC1');
            const ckC2       = document.getElementById('mostrarC2');
            const ckPts      = document.getElementById('mostrarPontos');
            const togC1      = document.getElementById('toggleC1');
            const togC2      = document.getElementById('toggleC2');
            const kpiC1      = document.getElementById('kpiC1');
            const kpiC2      = document.getElementById('kpiC2');

            let modoAtual = TEM_COMP ? 'sobreposto' : 'unico';

            function definirVisibilidade(arr, visivel) {
                const mostrar = ckPts.checked && visivel;
                arr.forEach(m => {
                    m.setStyle({ opacity: mostrar ? 1 : 0 });
                    if (!mostrar && m.isPopupOpen && m.isPopupOpen()) {
                        m.closePopup();
                    }
                });
            }

            function aplicarVisibilidade() {
                // -------- MODO ÚNICO --------
                if (!TEM_COMP) {
                    if (mapa.hasLayer(heatC1))  mapa.removeLayer(heatC1);
                    if (mapa.hasLayer(heatC2))  mapa.removeLayer(heatC2);
                    if (mapa.hasLayer(heatDif)) mapa.removeLayer(heatDif);

                    if (TEM_C1) {
                        heatC1.addTo(mapa);
                        definirVisibilidade(marcadoresC1, true);
                        definirVisibilidade(marcadoresEmp, true);
                    } else if (TEM_C2) {
                        heatC2.addTo(mapa);
                        definirVisibilidade(marcadoresC2, true);
                        definirVisibilidade(marcadoresEmp, true);
                    }

                    atualizarLegendaUnico();
                    return;
                }

                // -------- MODO COMPARAÇÃO --------
                if (mapa.hasLayer(heatC1))  mapa.removeLayer(heatC1);
                if (mapa.hasLayer(heatC2))  mapa.removeLayer(heatC2);
                if (mapa.hasLayer(heatDif)) mapa.removeLayer(heatDif);

                if (modoAtual === 'sobreposto') {
                    if (ckC1.checked) heatC1.addTo(mapa);
                    if (ckC2.checked) heatC2.addTo(mapa);
                } else if (modoAtual === 'c1' && ckC1.checked) {
                    heatC1.addTo(mapa);
                } else if (modoAtual === 'c2' && ckC2.checked) {
                    heatC2.addTo(mapa);
                } else if (modoAtual === 'diferenca') {
                    heatDif.addTo(mapa);
                }

                const c1Visivel = ckC1.checked;
                const c2Visivel = ckC2.checked;
                const algumVisivel = c1Visivel || c2Visivel;

                definirVisibilidade(marcadoresC1, c1Visivel);
                definirVisibilidade(marcadoresC2, c2Visivel);
                definirVisibilidade(marcadoresEmp, algumVisivel);

                if (kpiC1) kpiC1.classList.toggle('oculto', !c1Visivel);
                if (kpiC2) kpiC2.classList.toggle('oculto', !c2Visivel);

                atualizarLegendaComparacao();

                if (btnModoC1) btnModoC1.disabled = !c1Visivel;
                if (btnModoC2) btnModoC2.disabled = !c2Visivel;

                if (modoAtual === 'diferenca' && (!c1Visivel || !c2Visivel)) {
                    modoAtual = 'sobreposto';
                    modosBtns.forEach(b =>
                        b.classList.toggle('ativo', b.dataset.modo === 'sobreposto'));
                }
                if (modoAtual === 'c1' && !c1Visivel) {
                    modoAtual = 'sobreposto';
                    modosBtns.forEach(b =>
                        b.classList.toggle('ativo', b.dataset.modo === 'sobreposto'));
                    aplicarVisibilidade();
                    return;
                }
                if (modoAtual === 'c2' && !c2Visivel) {
                    modoAtual = 'sobreposto';
                    modosBtns.forEach(b =>
                        b.classList.toggle('ativo', b.dataset.modo === 'sobreposto'));
                    aplicarVisibilidade();
                    return;
                }

                atualizarEstiloToggle(togC1, ckC1);
                atualizarEstiloToggle(togC2, ckC2);

                document.querySelectorAll('.leaflet-popup').forEach(pop => {
                    const l1 = pop.querySelector('.popup-linha-c1');
                    const l2 = pop.querySelector('.popup-linha-c2');
                    if (l1) l1.style.display = c1Visivel ? '' : 'none';
                    if (l2) l2.style.display = c2Visivel ? '' : 'none';
                });
            }

            function atualizarEstiloToggle(tog, ck) {
                if (!tog || !ck) return;
                if (ck.checked) {
                    tog.classList.remove('oculto');
                    tog.classList.add('ativo');
                } else {
                    tog.classList.remove('ativo');
                    tog.classList.add('oculto');
                }
            }

            function atualizarLegendaUnico() {
                const legenda = document.getElementById('legenda-cores');
                const nome = TEM_C1 ? CAND1 : CAND2;
                const classe = TEM_C1 ? 'grad-c1' : 'grad-c2';
                const cor = TEM_C1 ? '#dc3545' : '#0d6efd';
                legenda.innerHTML = `
                    <div class="item">
                        <span style="color:${cor};font-weight:bold;">● ${escapeHtml(nome)}</span>
                        <span class="grad ${classe}"></span>
                        <span style="font-size:11px;color:#888;">menos → mais</span>
                    </div>`;
            }

            function atualizarLegendaComparacao() {
                const legenda = document.getElementById('legenda-cores');
                const partes = [];
                const mostrarC1 = ckC1.checked;
                const mostrarC2 = ckC2.checked;

                if (modoAtual === 'sobreposto') {
                    if (mostrarC1) partes.push(`
                        <div class="item">
                            <span style="color:#dc3545;font-weight:bold;">● ${escapeHtml(CAND1)}</span>
                            <span class="grad grad-c1"></span>
                            <span style="font-size:11px;color:#888;">menos → mais</span>
                        </div>`);
                    if (mostrarC2) partes.push(`
                        <div class="item">
                            <span style="color:#0d6efd;font-weight:bold;">● ${escapeHtml(CAND2)}</span>
                            <span class="grad grad-c2"></span>
                            <span style="font-size:11px;color:#888;">menos → mais</span>
                        </div>`);
                } else if (modoAtual === 'c1' && mostrarC1) {
                    partes.push(`
                        <div class="item">
                            <span style="color:#dc3545;font-weight:bold;">${escapeHtml(CAND1)}</span>
                            <span class="grad grad-c1"></span>
                            <span style="font-size:11px;color:#888;">menos → mais</span>
                        </div>`);
                } else if (modoAtual === 'c2' && mostrarC2) {
                    partes.push(`
                        <div class="item">
                            <span style="color:#0d6efd;font-weight:bold;">${escapeHtml(CAND2)}</span>
                            <span class="grad grad-c2"></span>
                            <span style="font-size:11px;color:#888;">menos → mais</span>
                        </div>`);
                } else if (modoAtual === 'diferenca') {
                    partes.push(`
                        <div class="item">
                            <span style="color:#0d47a1;font-weight:bold;">${escapeHtml(CAND2)} +</span>
                            <span class="grad grad-dif"></span>
                            <span style="color:#b71c1c;font-weight:bold;">${escapeHtml(CAND1)} +</span>
                        </div>
                        <span style="font-size:11px;color:#888;">
                            tons frios = vantagem de ${escapeHtml(CAND2)};
                            tons quentes = vantagem de ${escapeHtml(CAND1)}
                        </span>`);
                }

                if (!partes.length) {
                    legenda.innerHTML =
                        `<span class="legenda-vazia">⚠ Nenhum candidato selecionado para exibir.</span>`;
                } else {
                    legenda.innerHTML = partes.join('');
                }
            }

            modosBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    if (btn.disabled) return;
                    modosBtns.forEach(b => b.classList.remove('ativo'));
                    btn.classList.add('ativo');
                    modoAtual = btn.dataset.modo;
                    aplicarVisibilidade();
                });
            });

            if (ckC1) ckC1.addEventListener('change', aplicarVisibilidade);
            if (ckC2) ckC2.addEventListener('change', aplicarVisibilidade);
            ckPts.addEventListener('change', aplicarVisibilidade);

            aplicarVisibilidade();

            const rEl = document.getElementById('ctrlRaio');
            const oEl = document.getElementById('ctrlOpac');
            const rVal = document.getElementById('ctrlRaioVal');
            const oVal = document.getElementById('ctrlOpacVal');

            function aplicarOpcoes() {
                const r = parseInt(rEl.value, 10);
                const o = parseFloat(oEl.value);
                const b = Math.round(r * 0.8);

                heatC1.setOptions({ radius: r, blur: b, opacity: o });
                heatC2.setOptions({ radius: r, blur: b, opacity: o });
                heatDif.setOptions({ radius: r + 4, blur: Math.round(r), opacity: o });
            }

            rEl.addEventListener('input', () => {
                rVal.textContent = rEl.value;
                aplicarOpcoes();
            });
            oEl.addEventListener('input', () => {
                oVal.textContent = parseFloat(oEl.value).toFixed(2);
                aplicarOpcoes();
            });
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