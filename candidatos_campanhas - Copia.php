<?php
// ------------------------------------------------------------
// Configuração do banco
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';

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
// Verifica se a coluna 'ano' existe na tabela votos
// ------------------------------------------------------------
$colunasVotos = $pdo->query("SHOW COLUMNS FROM votos")->fetchAll(PDO::FETCH_COLUMN);
$temAno = in_array('ano', $colunasVotos, true);

// ------------------------------------------------------------
// Filtros recebidos
// ------------------------------------------------------------
$ano        = $_GET['ano']        ?? '';
$cargo      = $_GET['cargo']      ?? '';
$candidato  = $_GET['candidato']  ?? '';
$regiao     = $_GET['regiao']     ?? '';
$ordem      = $_GET['ordem']      ?? 'votos';

$filtros = [];
$params  = [];

if ($temAno && $ano !== '') {
    $filtros[] = "v.ano = :ano";
    $params[':ano'] = (int)$ano;
}
if ($cargo !== '') {
    $filtros[] = "v.nomeCargoPerg = :cargo";
    $params[':cargo'] = $cargo;
}
if ($candidato !== '') {
    $filtros[] = "v.nomeCandidatoResp = :candidato";
    $params[':candidato'] = $candidato;
}
if ($regiao !== '') {
    $filtros[] = "mr.regiao_id = :regiao";
    $params[':regiao'] = (int)$regiao;
}

$where = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

// Coluna ano no SELECT/GROUP BY, só se existir
$colAnoSelect = $temAno ? "v.ano," : "";
$colAnoGroup  = $temAno ? "v.ano," : "";
$colAnoOrder  = $temAno ? "v.ano DESC," : "";

switch ($ordem) {
    case 'nome':
        $orderBy = "v.nomeCandidatoResp ASC, v.nomeCargoPerg ASC";
        break;
    case 'cargo':
        $orderBy = "v.nomeCargoPerg ASC, total DESC";
        break;
    case 'ano':
        $orderBy = $temAno ? "v.ano DESC, total DESC" : "total DESC";
        break;
    case 'votos':
    default:
        $orderBy = $temAno ? "v.ano DESC, total DESC" : "total DESC";
        break;
}

// ------------------------------------------------------------
// Campanhas (candidato × cargo × ano)
// ------------------------------------------------------------
$sqlCampanhas = "
    SELECT
        $colAnoSelect
        v.nomeCandidatoResp AS candidato,
        v.nomeCargoPerg     AS cargo,
        SUM(v.qtdVotos)     AS total,
        COUNT(DISTINCT v.nomeMunicipio) AS qtd_municipios,
        COUNT(DISTINCT v.numeroZona)    AS qtd_zonas
    FROM votos v
    LEFT JOIN municipio_regiao mr
      ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
       = v.nomeMunicipio  COLLATE utf8mb4_general_ci
    $where
    GROUP BY $colAnoGroup v.nomeCandidatoResp, v.nomeCargoPerg
    ORDER BY $orderBy
";
$stmt = $pdo->prepare($sqlCampanhas);
$stmt->execute($params);
$campanhas = $stmt->fetchAll();

$totalVotosFiltro = 0;
foreach ($campanhas as $c) $totalVotosFiltro += (int)$c['total'];

// ------------------------------------------------------------
// Ranking de candidatos
// ------------------------------------------------------------
$sqlRankingCand = "
    SELECT
        $colAnoSelect
        v.nomeCandidatoResp AS candidato,
        COUNT(DISTINCT v.nomeCargoPerg) AS qtd_cargos,
        SUM(v.qtdVotos)     AS total
    FROM votos v
    LEFT JOIN municipio_regiao mr
      ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
       = v.nomeMunicipio  COLLATE utf8mb4_general_ci
    $where
    GROUP BY $colAnoGroup v.nomeCandidatoResp
    ORDER BY $colAnoOrder total DESC
    LIMIT 10
";
$stmt = $pdo->prepare($sqlRankingCand);
$stmt->execute($params);
$rankingCandidatos = $stmt->fetchAll();

// ------------------------------------------------------------
// Ranking de cargos
// ------------------------------------------------------------
$sqlRankingCargo = "
    SELECT
        $colAnoSelect
        v.nomeCargoPerg AS cargo,
        COUNT(DISTINCT v.nomeCandidatoResp) AS qtd_candidatos,
        SUM(v.qtdVotos) AS total
    FROM votos v
    LEFT JOIN municipio_regiao mr
      ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
       = v.nomeMunicipio  COLLATE utf8mb4_general_ci
    $where
    GROUP BY $colAnoGroup v.nomeCargoPerg
    ORDER BY $colAnoOrder total DESC
";
$stmt = $pdo->prepare($sqlRankingCargo);
$stmt->execute($params);
$rankingCargos = $stmt->fetchAll();

// ------------------------------------------------------------
// Detalhamento por região/município de cada campanha
// ------------------------------------------------------------
$sqlDetalhe = "
    SELECT
        $colAnoSelect
        v.nomeCandidatoResp AS candidato,
        v.nomeCargoPerg     AS cargo,
        COALESCE(r.nome, 'Sem região') AS regiao,
        v.nomeMunicipio,
        SUM(v.qtdVotos) AS total
    FROM votos v
    LEFT JOIN municipio_regiao mr
      ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
       = v.nomeMunicipio  COLLATE utf8mb4_general_ci
    LEFT JOIN regioes r
      ON r.id = mr.regiao_id
    $where
    GROUP BY $colAnoGroup v.nomeCandidatoResp, v.nomeCargoPerg,
             r.nome, v.nomeMunicipio
    ORDER BY r.nome, v.nomeMunicipio
";
$stmt = $pdo->prepare($sqlDetalhe);
$stmt->execute($params);

$detalhe = [];
foreach ($stmt->fetchAll() as $row) {
    $chave = ($temAno ? ($row['ano'] ?? '—') . '|' : '')
           . $row['candidato'] . '||' . $row['cargo'];

    $detalhe[$chave]['por_regiao'][$row['regiao']] =
        ($detalhe[$chave]['por_regiao'][$row['regiao']] ?? 0) + (int)$row['total'];

    $detalhe[$chave]['por_municipio'][] = [
        'regiao'    => $row['regiao'],
        'municipio' => $row['nomeMunicipio'],
        'total'     => (int)$row['total'],
    ];
}

// ------------------------------------------------------------
// KPIs gerais (independentes do filtro)
// ------------------------------------------------------------
$totalGeralBanco = (int)$pdo->query(
    "SELECT COALESCE(SUM(qtdVotos),0) FROM votos"
)->fetchColumn();

$totalCandidatos = (int)$pdo->query(
    "SELECT COUNT(DISTINCT nomeCandidatoResp) FROM votos"
)->fetchColumn();

$totalCargos = (int)$pdo->query(
    "SELECT COUNT(DISTINCT nomeCargoPerg) FROM votos"
)->fetchColumn();

if ($temAno) {
    $totalCampanhasBanco = (int)$pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT DISTINCT nomeCandidatoResp, nomeCargoPerg, ano FROM votos
         ) t"
    )->fetchColumn();
    $totalAnos = (int)$pdo->query(
        "SELECT COUNT(DISTINCT ano) FROM votos WHERE ano IS NOT NULL"
    )->fetchColumn();
} else {
    $totalCampanhasBanco = (int)$pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT DISTINCT nomeCandidatoResp, nomeCargoPerg FROM votos
         ) t"
    )->fetchColumn();
    $totalAnos = 0;
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

$temFiltro = $ano || $cargo || $candidato || $regiao;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Candidatos e Campanhas</title>

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
    }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#0069d9; margin-top:4px; }
    .kpi .sub    { font-size:12px; color:#888; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi.teal    { border-left-color:#20c997; } .kpi.teal .valor { color:#20c997; }

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
        height: 34px; padding: 3px 6px; border: 1px solid #ccc; border-radius: 4px;
        background: #fff;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 26px; color: #333;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 32px;
    }
    .select2-container .select2-selection--single { height: 34px; }
    .select2-search--dropdown .select2-search__field {
        padding: 6px; font-size: 14px; border-radius: 4px;
    }
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #0069d9;
    }
    #candidato + .select2-container { min-width: 320px; max-width: 440px; }

    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; }
    th { background:#0069d9; color:#fff; }
    tr:nth-child(even) { background:#f2f2f2; }
    tfoot td { font-weight:bold; background:#e6f0fa; }
    .num { text-align:right; }
    .barra {
        background:#e6f0fa; border-radius:4px; height:12px; overflow:hidden;
        display:inline-block; width:140px; vertical-align:middle;
    }
    .barra > span { display:block; height:100%; background:#0069d9; }

    details { background:#fff; border-radius:6px; padding:10px 15px; margin-bottom:8px;
              box-shadow:0 1px 2px rgba(0,0,0,.06); }
    summary { cursor:pointer; font-weight:bold; color:#0069d9; padding:4px 0; }
    summary:hover { text-decoration:underline; }
    details table { margin-top:10px; }
    details table th { font-size:12px; }
    details table td { font-size:13px; }

    .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

    .card {
        background:#fff; border-radius:8px; padding:15px 20px;
        box-shadow:0 1px 3px rgba(0,0,0,.1);
    }
    .card h3 { margin: 0 0 10px; font-size: 15px; color:#0069d9;
               border-bottom: 1px solid #eee; padding-bottom:8px; }
    .card table { margin: 0; }
    .card table th { background:#0069d9; color:#fff; font-size:12px; }
    .card table td { font-size:13px; }

    .badge {
        display:inline-block; padding:2px 8px; border-radius:12px;
        font-size:11px; font-weight:bold; text-transform:uppercase;
        letter-spacing:.4px;
    }
    .badge-ano   { background:#e7f3ff; color:#0069d9; }
    .badge-cargo { background:#e6f0fa; color:#0069d9; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso code { background:#fff; padding:1px 5px; border-radius:3px;
                  font-size:12px; border:1px solid #eee; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Candidatos e Campanhas</h1>

<?php if (!$temAno): ?>
    <div class="aviso">
        <strong>Atenção:</strong> a coluna <code>ano</code> ainda não existe na tabela
        <code>votos</code>. A página funciona sem ela, mas o filtro e o agrupamento por
        ano ficam desabilitados. Para ativar, rode:
        <br><br>
        <code>ALTER TABLE votos ADD COLUMN ano INT NULL AFTER nomeMunicipio, ADD INDEX idx_ano (ano);</code>
        <br>
        <code>UPDATE votos SET ano = 2024 WHERE ano IS NULL;</code>
    </div>
<?php endif; ?>

<!-- KPIs -->
<div class="kpis">
    <div class="kpi">
        <div class="rotulo">Candidatos</div>
        <div class="valor"><?= number_format($totalCandidatos, 0, ',', '.') ?></div>
    </div>
    <div class="kpi roxo">
        <div class="rotulo">Cargos</div>
        <div class="valor"><?= number_format($totalCargos, 0, ',', '.') ?></div>
    </div>
    <div class="kpi laranja">
        <div class="rotulo">Campanhas<?= $temAno ? ' (cand × cargo × ano)' : ' (cand × cargo)' ?></div>
        <div class="valor"><?= number_format($totalCampanhasBanco, 0, ',', '.') ?></div>
    </div>
    <?php if ($temAno && $totalAnos > 0): ?>
        <div class="kpi teal">
            <div class="rotulo">Anos distintos</div>
            <div class="valor"><?= number_format($totalAnos, 0, ',', '.') ?></div>
        </div>
    <?php endif; ?>
    <div class="kpi verde">
        <div class="rotulo">Total de votos no banco</div>
        <div class="valor"><?= number_format($totalGeralBanco, 0, ',', '.') ?></div>
    </div>
    <?php if ($temFiltro): ?>
        <div class="kpi vermelho">
            <div class="rotulo">Votos no filtro atual</div>
            <div class="valor"><?= number_format($totalVotosFiltro, 0, ',', '.') ?></div>
            <div class="sub"><?= number_format(count($campanhas), 0, ',', '.') ?> campanha(s)</div>
        </div>
    <?php endif; ?>
</div>

<!-- Filtros -->
<form method="get" action="candidatos_campanhas.php" id="form-filtros">
    <div class="filtros">
        <?php if ($temAno && $anosDisponiveis): ?>
            <label for="ano">Ano:</label>
            <select name="ano" id="ano">
                <option value="">Todos</option>
                <?php foreach ($anosDisponiveis as $a): ?>
                    <option value="<?= (int)$a ?>"
                        <?= (string)$a === (string)$ano ? 'selected' : '' ?>>
                        <?= (int)$a ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

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

        <label for="regiao">Região:</label>
        <select name="regiao" id="regiao">
            <option value="">Todas</option>
            <?php foreach ($regioes as $r): ?>
                <option value="<?= (int)$r['id'] ?>"
                    <?= (string)$r['id'] === (string)$regiao ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="ordem">Ordenar por:</label>
        <select name="ordem" id="ordem">
            <option value="votos" <?= $ordem === 'votos' ? 'selected' : '' ?>>Votos (maior)</option>
            <option value="nome"  <?= $ordem === 'nome'  ? 'selected' : '' ?>>Nome do candidato</option>
            <option value="cargo" <?= $ordem === 'cargo' ? 'selected' : '' ?>>Cargo</option>
            <?php if ($temAno): ?>
                <option value="ano" <?= $ordem === 'ano' ? 'selected' : '' ?>>Ano (mais recente)</option>
            <?php endif; ?>
        </select>

        <button type="submit" class="btn-consultar">Consultar</button>
        <a href="candidatos_campanhas.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<!-- Tabela principal -->
<h2>Campanhas <?= $temFiltro ? '(filtradas)' : '' ?></h2>

<?php if (!$campanhas): ?>
    <div class="aviso">
        Nenhuma campanha encontrada para os filtros selecionados.
    </div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <?php if ($temAno): ?><th>Ano</th><?php endif; ?>
                <th>Candidato</th>
                <th>Cargo</th>
                <th class="num">Municípios</th>
                <th class="num">Zonas</th>
                <th class="num">Votos</th>
                <th class="num">% do filtro</th>
                <th>Proporção</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $pos = 1;
            $maiorTotal = $campanhas[0]['total'] ?? 0;
            foreach ($campanhas as $c):
                $chave = ($temAno ? ($c['ano'] ?? '—') . '|' : '')
                       . $c['candidato'] . '||' . $c['cargo'];
                $pct     = $totalVotosFiltro > 0 ? ($c['total'] / $totalVotosFiltro) * 100 : 0;
                $largura = $maiorTotal > 0 ? ($c['total'] / $maiorTotal) * 100 : 0;
                $colspan = $temAno ? 9 : 8;
            ?>
                <tr class="linha-campanha" data-chave="<?= htmlspecialchars($chave) ?>">
                    <td><?= $pos++ ?></td>
                    <?php if ($temAno): ?>
                        <td>
                            <?php if (!empty($c['ano'])): ?>
                                <span class="badge badge-ano"><?= (int)$c['ano'] ?></span>
                            <?php else: ?>
                                <span style="color:#b94a48;">—</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td><?= htmlspecialchars($c['candidato']) ?></td>
                    <td><span class="badge badge-cargo"><?= htmlspecialchars($c['cargo']) ?></span></td>
                    <td class="num"><?= number_format($c['qtd_municipios'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format($c['qtd_zonas'], 0, ',', '.') ?></td>
                    <td class="num"><strong><?= number_format((int)$c['total'], 0, ',', '.') ?></strong></td>
                    <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                    <td>
                        <span class="barra"><span style="width: <?= number_format($largura, 2, '.', '') ?>%"></span></span>
                    </td>
                </tr>
                <tr class="linha-detalhe" style="display:none;">
                    <td colspan="<?= $colspan ?>">
                        <div class="grid-2">
                            <div class="card">
                                <h3>Distribuição por região</h3>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Região</th>
                                            <th class="num">Votos</th>
                                            <th class="num">% do candidato</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (($detalhe[$chave]['por_regiao'] ?? []) as $reg => $tot):
                                            $pctReg = $c['total'] > 0 ? ($tot / $c['total']) * 100 : 0;
                                        ?>
                                            <tr>
                                                <td><?= htmlspecialchars($reg) ?></td>
                                                <td class="num"><?= number_format($tot, 0, ',', '.') ?></td>
                                                <td class="num"><?= number_format($pctReg, 2, ',', '.') ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="card">
                                <h3>Top municípios</h3>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Município</th>
                                            <th>Região</th>
                                            <th class="num">Votos</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $muns = $detalhe[$chave]['por_municipio'] ?? [];
                                        usort($muns, fn($a, $b) => $b['total'] <=> $a['total']);
                                        $muns = array_slice($muns, 0, 10);
                                        foreach ($muns as $m):
                                        ?>
                                            <tr>
                                                <td><?= htmlspecialchars($m['municipio']) ?></td>
                                                <td><?= htmlspecialchars($m['regiao']) ?></td>
                                                <td class="num"><?= number_format($m['total'], 0, ',', '.') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="<?= $temAno ? 6 : 5 ?>">Total do filtro</td>
                <td class="num"><?= number_format($totalVotosFiltro, 0, ',', '.') ?></td>
                <td class="num">100,00%</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <p style="font-size:12px;color:#888;">
        Dica: clique em uma linha para expandir a distribuição por região e município.
    </p>
<?php endif; ?>

<!-- Rankings -->
<h2>Rankings</h2>

<div class="grid-2">
    <!-- Top candidatos -->
    <div class="card">
        <h3>Top 10 candidatos (soma de todos os cargos)</h3>
        <?php if (!$rankingCandidatos): ?>
            <p style="color:#888;">Sem dados para os filtros atuais.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($temAno): ?><th>Ano</th><?php endif; ?>
                        <th>Candidato</th>
                        <th class="num">Cargos</th>
                        <th class="num">Votos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($rankingCandidatos as $rc): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <?php if ($temAno): ?>
                                <td>
                                    <?php if (!empty($rc['ano'])): ?>
                                        <span class="badge badge-ano"><?= (int)$rc['ano'] ?></span>
                                    <?php else: ?>
                                        <span style="color:#b94a48;">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($rc['candidato']) ?></td>
                            <td class="num"><?= number_format($rc['qtd_cargos'], 0, ',', '.') ?></td>
                            <td class="num"><strong><?= number_format((int)$rc['total'], 0, ',', '.') ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Cargos -->
    <div class="card">
        <h3>Cargos e seus totais</h3>
        <?php if (!$rankingCargos): ?>
            <p style="color:#888;">Sem dados para os filtros atuais.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($temAno): ?><th>Ano</th><?php endif; ?>
                        <th>Cargo</th>
                        <th class="num">Candidatos</th>
                        <th class="num">Votos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($rankingCargos as $rc): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <?php if ($temAno): ?>
                                <td>
                                    <?php if (!empty($rc['ano'])): ?>
                                        <span class="badge badge-ano"><?= (int)$rc['ano'] ?></span>
                                    <?php else: ?>
                                        <span style="color:#b94a48;">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><span class="badge badge-cargo"><?= htmlspecialchars($rc['cargo']) ?></span></td>
                            <td class="num"><?= number_format($rc['qtd_candidatos'], 0, ',', '.') ?></td>
                            <td class="num"><strong><?= number_format((int)$rc['total'], 0, ',', '.') ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- jQuery + Select2 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
    // ---------- Select2 com busca sem acento ----------
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
            placeholder: 'Buscar candidato...',
            allowClear: true,
            width: 'resolve',
            minimumInputLength: 0,
            matcher: matcherSemAcento
        });

        ['#ano', '#cargo', '#regiao', '#ordem'].forEach(function (sel) {
            jQuery(sel).on('change', function () {
                document.getElementById('form-filtros').submit();
            });
        });
    })();

    // ---------- Expandir linha ----------
    (function () {
        document.querySelectorAll('tr.linha-campanha').forEach(function (tr) {
            tr.style.cursor = 'pointer';
            tr.title = 'Clique para expandir';
            tr.addEventListener('click', function (e) {
                if (e.target.closest('a, button, input, select')) return;
                const detalhe = tr.nextElementSibling;
                if (detalhe && detalhe.classList.contains('linha-detalhe')) {
                    const visivel = detalhe.style.display !== 'none';
                    detalhe.style.display = visivel ? 'none' : 'table-row';
                }
            });
        });
    })();
</script>

</body>
</html>
<!--  -->