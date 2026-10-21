<?php
// ------------------------------------------------------------
// Configuração do banco
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';
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

// ------------------------------------------------------------
// Detecta se a coluna 'ano' existe
// ------------------------------------------------------------
$colunasVotos = $pdo->query("SHOW COLUMNS FROM votos")->fetchAll(PDO::FETCH_COLUMN);
$temAno = in_array('ano', $colunasVotos, true);

// ------------------------------------------------------------
// Filtros recebidos
// ------------------------------------------------------------
$ano     = $_GET['ano']     ?? '';
$cargo   = $_GET['cargo']   ?? '';
$regiao  = $_GET['regiao']  ?? '';
$ordem   = $_GET['ordem']   ?? 'total';

$candidatosRaw = $_GET['candidatos'] ?? [];
if (!is_array($candidatosRaw)) $candidatosRaw = [$candidatosRaw];
$candidatosSelecionados = array_values(array_filter(array_map('trim', $candidatosRaw)));

// ------------------------------------------------------------
// WHERE base
// ------------------------------------------------------------
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
if ($regiao !== '') {
    $filtros[] = "mr.regiao_id = :regiao";
    $params[':regiao'] = (int)$regiao;
}
if (!empty($candidatosSelecionados)) {
    $in = [];
    foreach ($candidatosSelecionados as $i => $c) {
        $ph = ":cand{$i}";
        $in[] = $ph;
        $params[$ph] = $c;
    }
    $filtros[] = "v.nomeCandidatoResp IN (" . implode(',', $in) . ")";
}

$where = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

// ============================================================
// MATRIZ POR MUNICÍPIO
// ============================================================
$matriz = [];
$totalPorCand = [];
$totalPorMun  = [];

if (!empty($candidatosSelecionados)) {
    $sql = "
        SELECT
            v.nomeMunicipio,
            v.nomeCandidatoResp AS candidato,
            SUM(v.qtdVotos)     AS total
        FROM votos v
        LEFT JOIN municipio_regiao mr
          ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
           = v.nomeMunicipio  COLLATE utf8mb4_general_ci
        $where
        GROUP BY v.nomeMunicipio, v.nomeCandidatoResp
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $mun  = $row['nomeMunicipio'];
        $cand = $row['candidato'];
        $tot  = (int)$row['total'];

        if (!isset($matriz[$mun])) {
            $matriz[$mun] = [];
            $totalPorMun[$mun] = 0;
        }
        $matriz[$mun][$cand] = ($matriz[$mun][$cand] ?? 0) + $tot;
        $totalPorMun[$mun]  += $tot;
        $totalPorCand[$cand] = ($totalPorCand[$cand] ?? 0) + $tot;
    }
}

if ($ordem === 'nome') {
    ksort($matriz, SORT_NATURAL | SORT_FLAG_CASE);
} else {
    uksort($matriz, function ($a, $b) use ($totalPorMun) {
        return $totalPorMun[$b] <=> $totalPorMun[$a];
    });
}

$totalGeral = array_sum($totalPorCand);

// ============================================================
// MATRIZ POR REGIÃO
// ============================================================
$matrizRegiao    = [];
$totalPorRegiao  = [];
$totalPorCandReg = [];

if (!empty($candidatosSelecionados)) {
    $sqlReg = "
        SELECT
            COALESCE(r.nome, 'Sem região') AS regiao,
            v.nomeCandidatoResp AS candidato,
            SUM(v.qtdVotos)     AS total
        FROM votos v
        LEFT JOIN municipio_regiao mr
          ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
           = v.nomeMunicipio  COLLATE utf8mb4_general_ci
        LEFT JOIN regioes r
          ON r.id = mr.regiao_id
        $where
        GROUP BY regiao, v.nomeCandidatoResp
    ";
    $stmt = $pdo->prepare($sqlReg);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $reg  = $row['regiao'];
        $cand = $row['candidato'];
        $tot  = (int)$row['total'];

        if (!isset($matrizRegiao[$reg])) {
            $matrizRegiao[$reg] = [];
            $totalPorRegiao[$reg] = 0;
        }
        $matrizRegiao[$reg][$cand] = ($matrizRegiao[$reg][$cand] ?? 0) + $tot;
        $totalPorRegiao[$reg]     += $tot;
        $totalPorCandReg[$cand]   = ($totalPorCandReg[$cand] ?? 0) + $tot;
    }
}

if ($ordem === 'nome') {
    ksort($matrizRegiao, SORT_NATURAL | SORT_FLAG_CASE);
} else {
    uksort($matrizRegiao, function ($a, $b) use ($totalPorRegiao) {
        return $totalPorRegiao[$b] <=> $totalPorRegiao[$a];
    });
}

// ------------------------------------------------------------
// Dados para o gráfico de barras (regiões × candidatos)
// ------------------------------------------------------------
$graficoRegioes = [
    'labels'      => array_keys($matrizRegiao),
    'candidatos'  => $candidatosSelecionados,
    'series'      => [],
];

if (!empty($candidatosSelecionados)) {
    foreach ($candidatosSelecionados as $c) {
        $valores = [];
        foreach (array_keys($matrizRegiao) as $reg) {
            $valores[] = (int)($matrizRegiao[$reg][$c] ?? 0);
        }
        $graficoRegioes['series'][$c] = $valores;
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

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function abreviarCandidato(string $nome): string {
    $nome = trim($nome);
    if ($nome === '') return '???';

    // Se o nome começa com "CORONEL" (case-insensitive), usa o segundo nome
    // para gerar a abreviação.
    if (preg_match('/^CORONEL\s+(.+)$/iu', $nome, $m)) {
        $resto = trim($m[1]);
        if ($resto !== '') {
            return mb_strtoupper(mb_substr($resto, 0, 3, 'UTF-8'), 'UTF-8');
        }
    }

    return mb_strtoupper(mb_substr($nome, 0, 3, 'UTF-8'), 'UTF-8');
}

function classeCelula(int $v, int $maiorLinha): string {
    if ($v === 0) return 'num zero';
    if ($maiorLinha > 0 && $v === $maiorLinha) return 'num maior';
    return 'num';
}

function maiorDaLinha(array $linha, array $candidatos): int {
    $maior = 0;
    foreach ($candidatos as $c) {
        $v = $linha[$c] ?? 0;
        if ($v > $maior) $maior = $v;
    }
    return $maior;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Comparar Candidatos por Município</title>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<!-- Chart.js para o gráfico de barras -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    h2 { margin-top:30px; }

    /* ===== Barra de usuário (mesmo modelo do consultar.php) ===== */
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
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }

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
    .select2-container--default .select2-selection--multiple {
        min-height: 34px; padding: 3px 6px; border: 1px solid #ccc; border-radius: 4px; background:#fff;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background: #0069d9; color: #fff; border: none; padding: 1px 6px; font-size: 12px;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        color: #fff; margin-right: 4px;
    }
    .select2-search--dropdown .select2-search__field {
        padding: 6px; font-size: 14px; border-radius: 4px;
    }
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #0069d9;
    }
    #candidatos + .select2-container { min-width: 420px; max-width: 560px; }
    #candidatos + .select2-container .select2-selection--multiple {
        max-height: 120px; overflow-y: auto;
    }

    /* ============================================================
       TABELA MATRIZ (com scroll + cabeçalho fixo)
       ============================================================ */
    .tabela-wrap {
        /* Scroll vertical E horizontal */
        overflow: auto;
        max-height: 72vh;                /* altura máxima do "viewport" da tabela */
        background:#fff;
        border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.1);
        /* Necessário para criar contexto de empilhamento p/ sticky */
        position: relative;
        /* Scrollbar estilizada */
        scrollbar-width: thin;
        scrollbar-color: #b0c4de #f0f4f8;
    }
    .tabela-wrap::-webkit-scrollbar { width: 10px; height: 10px; }
    .tabela-wrap::-webkit-scrollbar-track { background: #f0f4f8; }
    .tabela-wrap::-webkit-scrollbar-thumb {
        background: #b0c4de; border-radius: 5px;
        border: 2px solid #f0f4f8;
    }
    .tabela-wrap::-webkit-scrollbar-thumb:hover { background: #8ba5c4; }

    table.mtz { border-collapse: collapse; width: 100%; background:#fff; }
    table.mtz th, table.mtz td {
        border: 1px solid #e3e3e3; padding: 6px 8px;
        text-align: left; font-size: 13px; white-space: nowrap;
    }

    /* ---- Cabeçalho fixo ---- */
    table.mtz thead th {
        background:#0069d9; color:#fff;
        position: sticky; top: 0;
        z-index: 3;                                     /* fica acima das células do corpo */
        box-shadow: inset 0 -1px 0 #0047a0,              /* borda inferior "fake" */
                    0 2px 6px rgba(0,0,0,.10);          /* sombra suave sob o header */
    }
    table.mtz thead th.col-cand {
        text-align: right; min-width: 64px; width: 64px;
        background:#0053b3;
        font-family: monospace; letter-spacing: .5px;
        box-shadow: inset 0 -1px 0 #003d80,
                    0 2px 6px rgba(0,0,0,.10);
    }

    table.mtz td.num { text-align: right; font-family: monospace; }
    table.mtz tr:nth-child(even) td { background:#f9f9f9; }
    table.mtz tr:hover td { background:#eef6ff; }
    table.mtz td.mun { font-weight: 600; }
    table.mtz td.zero { color:#ccc; }
    table.mtz td.maior {
        background:#d4f5dd !important;
        color:#1c7a34; font-weight: bold;
    }
    table.mtz tr:hover td.maior { background:#c1edcd !important; }

    /* ---- Rodapé (total) fixo no fundo ---- */
    table.mtz tfoot td {
        font-weight: bold; background:#e6f0fa;
        position: sticky; bottom: 0;
        z-index: 2;
        box-shadow: inset 0 1px 0 #c5d6ea,
                    0 -2px 6px rgba(0,0,0,.06);
    }

    /* ---- Variante da tabela de regiões (roxo) ---- */
    table.mtz.regioes thead th {
        background:#6f42c1;
        box-shadow: inset 0 -1px 0 #5a34a0,
                    0 2px 6px rgba(0,0,0,.10);
    }
    table.mtz.regioes thead th.col-cand {
        background:#5a34a0;
        box-shadow: inset 0 -1px 0 #482a80,
                    0 2px 6px rgba(0,0,0,.10);
    }
    table.mtz.regioes tfoot td {
        background:#ece2ff;
        box-shadow: inset 0 1px 0 #d0bef5,
                    0 -2px 6px rgba(0,0,0,.06);
    }

    .legenda-abrev {
        background:#fff; padding:10px 14px; border-radius:6px;
        box-shadow: 0 1px 2px rgba(0,0,0,.08); font-size:12px; margin-bottom:14px;
        display:flex; flex-wrap:wrap; gap:14px;
    }
    .legenda-abrev .item { display:flex; align-items:center; gap:6px; }
    .legenda-abrev .sigla {
        font-family: monospace; background:#0053b3; color:#fff;
        padding: 2px 6px; border-radius: 4px; font-weight: bold;
    }
    .legenda-abrev.reg .sigla { background:#5a34a0; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso code { background:#fff; padding:1px 5px; border-radius:3px; font-size:12px; border:1px solid #eee; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    .dica-maior {
        font-size:12px; color:#555; margin: 8px 0 14px;
        background:#e7f3ff; border-left:4px solid #0069d9;
        padding:8px 12px; border-radius:4px;
        display:inline-block;
    }
    .dica-maior .amostra {
        background:#d4f5dd; color:#1c7a34; font-weight:bold;
        padding:2px 8px; border-radius:4px; font-family:monospace;
    }

    /* Gráfico */
    .grafico-wrap {
        background:#fff; border-radius:8px; padding:20px;
        box-shadow: 0 1px 3px rgba(0,0,0,.1);
        margin-top: 12px;
    }
    .grafico-box {
        position: relative;
        height: 520px;
        width: 100%;
    }
    .grafico-titulo {
        font-size: 14px; font-weight: bold; color:#6f42c1;
        margin-bottom: 12px; text-align:center;
    }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Comparar Candidatos por Município</h1>

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

<?php if (!$temAno): ?>
    <div class="aviso">
        <strong>Dica:</strong> a coluna <code>ano</code> ainda não existe em <code>votos</code>.
        O filtro de ano está desabilitado. Para ativar:
        <code>ALTER TABLE votos ADD COLUMN ano INT NULL AFTER nomeMunicipio;</code>
    </div>
<?php endif; ?>

<form method="get" action="comparar_candidatos.php" id="form-filtros">
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

        <label for="ordem">Ordenar:</label>
        <select name="ordem" id="ordem">
            <option value="total" <?= $ordem === 'total' ? 'selected' : '' ?>>Total (maior)</option>
            <option value="nome"  <?= $ordem === 'nome'  ? 'selected' : '' ?>>Nome</option>
        </select>
    </div>

    <div class="filtros" style="margin-top:8px;">
        <label for="candidatos">Candidatos:</label>
        <select name="candidatos[]" id="candidatos" multiple>
            <?php foreach ($candidatos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= in_array($c, $candidatosSelecionados, true) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn-consultar">Comparar</button>
        <a href="comparar_candidatos.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<?php if (empty($candidatosSelecionados)): ?>

    <div class="aviso">
        Selecione <strong>um ou mais candidatos</strong> acima e clique em Comparar.
        Dica: o campo aceita múltipla escolha — clique em cada nome para adicionar.
    </div>

<?php elseif (!$matriz): ?>

    <div class="aviso">
        Nenhum voto encontrado para os candidatos e filtros selecionados.
    </div>

<?php else: ?>

    <!-- KPIs -->
    <div class="kpis">
        <div class="kpi">
            <div class="rotulo">Candidatos comparados</div>
            <div class="valor"><?= count($candidatosSelecionados) ?></div>
        </div>
        <div class="kpi verde">
            <div class="rotulo">Total de votos no filtro</div>
            <div class="valor"><?= number_format($totalGeral, 0, ',', '.') ?></div>
        </div>
        <div class="kpi roxo">
            <div class="rotulo">Municípios com voto</div>
            <div class="valor"><?= number_format(count($matriz), 0, ',', '.') ?></div>
        </div>
        <div class="kpi laranja">
            <div class="rotulo">Regiões com voto</div>
            <div class="valor"><?= number_format(count($matrizRegiao), 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- Legenda de abreviações -->
    <div class="legenda-abrev">
        <strong style="color:#0069d9;">Legenda dos cabeçalhos:</strong>
        <?php foreach ($candidatosSelecionados as $c): ?>
            <span class="item">
                <span class="sigla"><?= htmlspecialchars(abreviarCandidato($c)) ?></span>
                <?= htmlspecialchars($c) ?>
            </span>
        <?php endforeach; ?>
    </div>

    <!-- Dica sobre destaque -->
    <div class="dica-maior">
        Em cada linha, o candidato com <strong>maior votação</strong> aparece
        destacado assim: <span class="amostra">12.345</span>
    </div>

    <!-- ============================================================ -->
    <!-- TABELA 1: POR MUNICÍPIO                                      -->
    <!-- ============================================================ -->
    <h2>Por município</h2>

    <div class="tabela-wrap">
        <table class="mtz">
            <thead>
                <tr>
                    <th>Município</th>
                    <?php foreach ($candidatosSelecionados as $c): ?>
                        <th class="col-cand" title="<?= htmlspecialchars($c) ?>">
                            <?= htmlspecialchars(abreviarCandidato($c)) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($matriz as $mun => $linha):
                    $maiorLinha = maiorDaLinha($linha, $candidatosSelecionados);
                ?>
                    <tr>
                        <td class="mun"><?= htmlspecialchars($mun) ?></td>
                        <?php foreach ($candidatosSelecionados as $c):
                            $v = $linha[$c] ?? 0;
                        ?>
                            <td class="<?= classeCelula($v, $maiorLinha) ?>">
                                <?= $v > 0 ? number_format($v, 0, ',', '.') : '—' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <?php foreach ($candidatosSelecionados as $c): ?>
                        <td class="num">
                            <?= number_format($totalPorCand[$c] ?? 0, 0, ',', '.') ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- ============================================================ -->
    <!-- TABELA 2: POR REGIÃO                                         -->
    <!-- ============================================================ -->
    <h2>Por região</h2>

    <?php if (!$matrizRegiao): ?>
        <div class="aviso">
            Nenhuma região com votos para os filtros atuais. Verifique se os municípios
            estão associados em <code>municipio_regiao</code>.
        </div>
    <?php else: ?>

        <div class="legenda-abrev reg">
            <strong style="color:#6f42c1;">Mesma legenda:</strong>
            <?php foreach ($candidatosSelecionados as $c): ?>
                <span class="item">
                    <span class="sigla"><?= htmlspecialchars(abreviarCandidato($c)) ?></span>
                    <?= htmlspecialchars($c) ?>
                </span>
            <?php endforeach; ?>
        </div>

        <div class="tabela-wrap">
            <table class="mtz regioes">
                <thead>
                    <tr>
                        <th>Região</th>
                        <?php foreach ($candidatosSelecionados as $c): ?>
                            <th class="col-cand" title="<?= htmlspecialchars($c) ?>">
                                <?= htmlspecialchars(abreviarCandidato($c)) ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matrizRegiao as $reg => $linha):
                        $maiorLinha = maiorDaLinha($linha, $candidatosSelecionados);
                    ?>
                        <tr>
                            <td class="mun"><?= htmlspecialchars($reg) ?></td>
                            <?php foreach ($candidatosSelecionados as $c):
                                $v = $linha[$c] ?? 0;
                            ?>
                                <td class="<?= classeCelula($v, $maiorLinha) ?>">
                                    <?= $v > 0 ? number_format($v, 0, ',', '.') : '—' ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <?php foreach ($candidatosSelecionados as $c): ?>
                            <td class="num">
                                <?= number_format($totalPorCandReg[$c] ?? 0, 0, ',', '.') ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- GRÁFICO DE BARRAS POR REGIÃO                                 -->
        <!-- ============================================================ -->
        <h2>Gráfico de barras por região</h2>

        <div class="grafico-wrap">
            <div class="grafico-titulo">
                Votos por região — comparativo entre os candidatos selecionados
            </div>
            <div class="grafico-box">
                <canvas id="grafico-barras-regioes"></canvas>
            </div>
        </div>

        <script>
            const DADOS_BARRAS = <?= json_encode($graficoRegioes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

            (function () {
                if (typeof Chart === 'undefined') return;
                if (!DADOS_BARRAS || !DADOS_BARRAS.labels || !DADOS_BARRAS.labels.length) return;

                const CORES = [
                    '#0069d9', '#dc3545', '#28a745', '#fd7e14', '#6f42c1',
                    '#20c997', '#e83e8c', '#ffc107', '#17a2b8', '#6610f2',
                    '#fd7e14', '#343a40', '#e6194B', '#3cb44b', '#4363d8',
                    '#f58231', '#911eb4', '#42d4f4', '#f032e6', '#bfef45',
                ];

                const cands = Object.keys(DADOS_BARRAS.series);
                const datasets = cands.map((cand, i) => {
                    const cor = CORES[i % CORES.length];
                    return {
                        label: cand,
                        data: DADOS_BARRAS.series[cand],
                        backgroundColor: cor,
                        borderColor: cor,
                        borderWidth: 1,
                        borderRadius: 4,
                        maxBarThickness: 44,
                    };
                });

                const ctx = document.getElementById('grafico-barras-regioes').getContext('2d');

                function formatNum(n) {
                    return Number(n).toLocaleString('pt-BR');
                }

                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: DADOS_BARRAS.labels,
                        datasets: datasets,
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: {
                            x: {
                                title: {
                                    display: true,
                                    text: 'Região',
                                    font: { weight: 'bold' },
                                },
                                ticks: {
                                    autoSkip: false,
                                    maxRotation: 45,
                                    minRotation: 0,
                                },
                                grid: { display: false },
                            },
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Votos',
                                    font: { weight: 'bold' },
                                },
                                ticks: {
                                    callback: function (value) {
                                        return formatNum(value);
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 14,
                                    boxHeight: 14,
                                    padding: 12,
                                    font: { size: 12 },
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        return ' ' + ctx.dataset.label + ': ' +
                                               formatNum(ctx.parsed.y) + ' votos';
                                    },
                                    footer: function (items) {
                                        const total = items.reduce(
                                            (acc, it) => acc + it.parsed.y, 0
                                        );
                                        return 'Total na região: ' + formatNum(total);
                                    }
                                }
                            }
                        }
                    }
                });
            })();
        </script>

    <?php endif; ?>

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

        jQuery('#candidatos').select2({
            language: 'pt-BR',
            placeholder: 'Selecione um ou mais candidatos...',
            allowClear: true,
            width: 'resolve',
            closeOnSelect: false,
            minimumInputLength: 0,
            matcher: matcherSemAcento
        });

        ['#ano', '#cargo', '#regiao', '#ordem'].forEach(function (sel) {
            jQuery(sel).on('change', function () {
                document.getElementById('form-filtros').submit();
            });
        });
    })();
</script>

</body>
</html>