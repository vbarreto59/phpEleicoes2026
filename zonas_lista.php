<?php
// ------------------------------------------------------------
// zonas_lista.php
// Lista de zonas eleitorais agrupadas por ZONA (não por município)
// Uma zona pode abranger vários municípios.
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

// ============================================================
// HELPERS
// ============================================================
function fmtZona3($n) {
    return str_pad((string)(int)$n, 3, '0', STR_PAD_LEFT);
}

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
// DETECÇÃO DE COLUNAS DE secoes_enderecos
// ============================================================
$colsSec = [];
$temSecEnd = false;
try {
    foreach ($pdo->query("SHOW COLUMNS FROM secoes_enderecos") as $c) {
        $colsSec[] = $c['Field'];
    }
    $temSecEnd = true;
} catch (Exception $e) {
    $temSecEnd = false;
}

$COL_SEC_ENDERECO = null;
$COL_SEC_BAIRRO   = null;
$COL_SEC_CEP      = null;
$temNomeLocalSec  = false;

if ($temSecEnd) {
    foreach (['endereco', 'endereco_tse', 'logradouro', 'nomeEndereco', 'enderecoSecao'] as $cand) {
        if (in_array($cand, $colsSec, true)) { $COL_SEC_ENDERECO = $cand; break; }
    }
    foreach (['bairro', 'nomeBairro', 'bairroSecao'] as $cand) {
        if (in_array($cand, $colsSec, true)) { $COL_SEC_BAIRRO = $cand; break; }
    }
    foreach (['cep', 'CEP', 'cepSecao'] as $cand) {
        if (in_array($cand, $colsSec, true)) { $COL_SEC_CEP = $cand; break; }
    }
    $temNomeLocalSec = in_array('nomeLocal', $colsSec, true);
}

// ============================================================
// FILTROS RECEBIDOS
// ============================================================
$ano         = $_GET['ano']         ?? '';
$municipio   = $_GET['municipio']   ?? '';
$candidato   = $_GET['candidato']   ?? '';
$minVotos    = $_GET['min_votos']   ?? '';
$minSecoes   = $_GET['min_secoes']  ?? '';
$ordem       = $_GET['ordem']       ?? 'municipio';

if (!in_array($ordem, ['municipio','zona','votos','secoes'], true)) {
    $ordem = 'municipio';
}

// ============================================================
// MONTA WHERE / PARAMS
// ============================================================
$filtros = [];
$params  = [];

if ($ano !== '' && (int)$ano > 0) {
    $filtros[] = "v.ano = :ano";
    $params[':ano'] = (int)$ano;
}
if ($candidato !== '') {
    $filtros[] = "v.nomeCandidatoResp = :candidato";
    $params[':candidato'] = $candidato;
}
if ($municipio !== '') {
    $subFiltros = [];
    if ($ano !== '' && (int)$ano > 0) {
        $subFiltros[] = "ano = :ano_sub";
        $params[':ano_sub'] = (int)$ano;
    }
    if ($candidato !== '') {
        $subFiltros[] = "nomeCandidatoResp = :cand_sub";
        $params[':cand_sub'] = $candidato;
    }
    $subFiltros[] = "nomeMunicipio = :mun_sub";
    $params[':mun_sub'] = $municipio;

    $subWhere = $subFiltros ? 'WHERE ' . implode(' AND ', $subFiltros) : '';
    $filtros[] = "v.numeroZona IN (
                    SELECT DISTINCT numeroZona FROM votos $subWhere
                  )";
}

$where = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

// HAVING
$having = [];
if ($minVotos !== '' && (int)$minVotos > 0) {
    $having[] = "total_votos >= :minv";
    $params[':minv'] = (int)$minVotos;
}
if ($minSecoes !== '' && (int)$minSecoes > 0) {
    $having[] = "qtd_secoes >= :mins";
    $params[':mins'] = (int)$minSecoes;
}
$havingSql = $having ? 'HAVING ' . implode(' AND ', $having) : '';

// ORDER BY
switch ($ordem) {
    case 'votos':
        $orderBy = "ORDER BY total_votos DESC, v.numeroZona";
        break;
    case 'secoes':
        $orderBy = "ORDER BY qtd_secoes DESC, v.numeroZona";
        break;
    case 'zona':
        $orderBy = "ORDER BY v.numeroZona";
        break;
    case 'municipio':
    default:
        $orderBy = "ORDER BY MIN(v.nomeMunicipio), v.numeroZona";
        break;
}

// ============================================================
// SUBCONSULTAS — 1ª SEÇÃO DA ZONA (para referência de endereço)
// ============================================================
$selectPrimeiraSecao = '';
if ($COL_SEC_ENDERECO !== null) {
    $bairroPart = $COL_SEC_BAIRRO
        ? ", (SELECT se.`$COL_SEC_BAIRRO` FROM secoes_enderecos se
               WHERE se.numeroZona = v.numeroZona
               ORDER BY se.numeroSecao ASC LIMIT 1) AS primeira_secao_bairro"
        : ", NULL AS primeira_secao_bairro";

    $cepPart = $COL_SEC_CEP
        ? ", (SELECT se.`$COL_SEC_CEP` FROM secoes_enderecos se
               WHERE se.numeroZona = v.numeroZona
               ORDER BY se.numeroSecao ASC LIMIT 1) AS primeira_secao_cep"
        : ", NULL AS primeira_secao_cep";

    $localPart = $temNomeLocalSec
        ? ", (SELECT se.nomeLocal FROM secoes_enderecos se
               WHERE se.numeroZona = v.numeroZona
               ORDER BY se.numeroSecao ASC LIMIT 1) AS primeira_secao_local"
        : ", NULL AS primeira_secao_local";

    $selectPrimeiraSecao = "
        , (SELECT se.numeroSecao FROM secoes_enderecos se
             WHERE se.numeroZona = v.numeroZona
             ORDER BY se.numeroSecao ASC LIMIT 1) AS primeira_secao_num
        , (SELECT se.`$COL_SEC_ENDERECO` FROM secoes_enderecos se
             WHERE se.numeroZona = v.numeroZona
             ORDER BY se.numeroSecao ASC LIMIT 1) AS primeira_secao_endereco
        $bairroPart
        $cepPart
        $localPart
    ";
}

// ============================================================
// QUERY PRINCIPAL
// ============================================================
$sql = "
    SELECT
        v.numeroZona,
        GROUP_CONCAT(DISTINCT v.nomeMunicipio
                     ORDER BY v.nomeMunicipio SEPARATOR ' • ') AS municipios,
        COUNT(DISTINCT CONCAT(v.nomeMunicipio, '|', v.numeroSecao)) AS qtd_secoes,
        SUM(v.qtdVotos) AS total_votos,
        MIN(z.endereco_tse)   AS endereco_tse,
        MIN(z.bairro)         AS zona_bairro,
        MIN(z.cep)            AS zona_cep,
        MIN(z.nomeMunicipio)  AS zona_municipio_sede,
        MIN(z.latitude)       AS latitude,
        MIN(z.longitude)      AS longitude
        $selectPrimeiraSecao
    FROM votos v
    LEFT JOIN zonas_enderecos z ON z.numeroZona = v.numeroZona
    $where
    GROUP BY v.numeroZona
    $havingSql
    $orderBy
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$linhas = $stmt->fetchAll();

// ============================================================
// TOTAIS / KPIs
// ============================================================
$totalZonas   = count($linhas);
$totalSecoes  = 0;
$totalVotos   = 0;
$maxVotos     = 0;
$municipiosSet = [];

foreach ($linhas as $l) {
    $totalSecoes += (int)$l['qtd_secoes'];
    $totalVotos  += (int)$l['total_votos'];
    if ((int)$l['total_votos'] > $maxVotos) $maxVotos = (int)$l['total_votos'];

    foreach (explode('•', (string)$l['municipios']) as $m) {
        $m = trim($m);
        if ($m !== '') $municipiosSet[$m] = true;
    }
}
$qtdMunicipios = count($municipiosSet);

// ============================================================
// LISTAS PARA OS SELECTS
// ============================================================
$anos = $pdo->query(
    "SELECT DISTINCT ano FROM votos WHERE ano > 0 ORDER BY ano DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$municipiosLista = $pdo->query(
    "SELECT DISTINCT nomeMunicipio FROM votos
      WHERE nomeMunicipio IS NOT NULL AND nomeMunicipio <> ''
      ORDER BY nomeMunicipio"
)->fetchAll(PDO::FETCH_COLUMN);

$candidatosLista = [];
if ($ano !== '' && (int)$ano > 0) {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT nomeCandidatoResp FROM votos
          WHERE ano = :ano AND nomeCandidatoResp <> ''
          ORDER BY nomeCandidatoResp"
    );
    $stmt->execute([':ano' => (int)$ano]);
    $candidatosLista = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $candidatosLista = $pdo->query(
        "SELECT DISTINCT nomeCandidatoResp FROM votos
          WHERE nomeCandidatoResp <> ''
          ORDER BY nomeCandidatoResp"
    )->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lista de Zonas Eleitorais</title>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom:6px; }
    h2 { margin-top:30px; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    /* Card do usuário logado */
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

    .badge-admin { display:inline-flex; align-items:center; gap:5px;
        background:#fff3cd; color:#856404; padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; letter-spacing:.3px; }
    .badge-super { display:inline-flex; align-items:center; gap:5px;
        background:#dc3545; color:#fff; padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; letter-spacing:.3px; }
    .badge-visitante { display:inline-flex; align-items:center; gap:5px;
        background:#e9ecef; color:#495057; padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; letter-spacing:.3px; }

    /* Filtros */
    form { background:#fff; padding:15px; border-radius:8px;
           box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px; }
    .filtros { display:flex; flex-wrap:wrap; align-items:center; gap:10px; }
    .filtros label { font-weight:bold; font-size:13px; }
    .filtros select, .filtros input[type=number], .filtros button {
        padding:6px 8px; border-radius:4px; border:1px solid #ccc; font-size:14px;
        font-family: inherit;
    }
    .filtros input[type=number] { width: 110px; }
    .btn-consultar { background:#0069d9; color:#fff; border:none; cursor:pointer;
                     font-weight:bold; padding:7px 14px; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none;
                  padding:7px 14px; border-radius:4px; font-size:14px; }
    .btn-limpar:hover { background:#5a6268; }

    /* Select2 */
    .select2-container { margin: 0; }
    .select2-container--default .select2-selection--single {
        height: 36px; padding: 3px 6px;
        border: 1px solid #ccc; border-radius: 4px; background:#fff;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 28px; color:#333;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 34px; }
    .select2-search--dropdown .select2-search__field { padding: 6px; font-size:14px; }
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #0069d9;
    }
    #ano + .select2-container         { min-width: 110px; }
    #municipio + .select2-container   { min-width: 220px; max-width: 320px; }
    #candidato + .select2-container   { min-width: 260px; max-width: 380px; }

    /* KPIs */
    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:14px 22px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:180px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:26px; font-weight:bold; color:#0069d9; margin-top:2px; }
    .kpi .sub    { font-size:11px; color:#888; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.azul    { border-left-color:#0d6efd; } .kpi.azul .valor   { color:#0d6efd; }
    .kpi.teal    { border-left-color:#20c997; } .kpi.teal .valor   { color:#20c997; }

    /* Tabela */
    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; }
    th { background:#0069d9; color:#fff; font-size:13px; }
    tr:nth-child(even) { background:#f2f2f2; }
    tfoot td { font-weight:bold; background:#e6f0fa; }
    .num { text-align:right; }
    .coord { font-family: monospace; font-size:12px; white-space:nowrap; }
    .coord .vazio { color:#b94a48; font-style:italic; font-family: Arial, sans-serif; }
    .endereco { font-size:13px; color:#555; line-height:1.3; }
    .endereco .bairro { color:#888; }
    .endereco .vazio  { color:#b94a48; font-style:italic; }
    .barra {
        background:#e6f0fa; border-radius:4px; height:12px; overflow:hidden;
        display:inline-block; width:120px; vertical-align:middle; margin-left:8px;
    }
    .barra > span { display:block; height:100%; background:#0069d9; }
    .zona-tag {
        display:inline-block; background:#0069d9; color:#fff;
        padding:3px 12px; border-radius:12px; font-weight:bold; font-size:14px;
        font-family: monospace; letter-spacing:1px;
    }

    /* Municípios da zona (tags) */
    .municipios-lista {
        display:flex; flex-wrap:wrap; gap:4px; max-width:340px;
    }
    .municipio-tag {
        display:inline-block; background:#f0f4f8; color:#333;
        padding:2px 8px; border-radius:6px; font-size:12px;
        border:1px solid #e0e6ec; text-decoration:none;
        transition: all .15s;
    }
    .municipio-tag:hover {
        background:#e1ecf7; border-color:#b8d4ec; color:#0069d9;
    }
    .municipio-tag.atual {
        background:#0069d9; color:#fff; border-color:#0069d9;
        font-weight:bold;
    }
    .municipio-tag.atual:hover { background:#0053b3; border-color:#0053b3; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso code { background:#fff; padding:1px 5px; border-radius:3px;
                  font-size:12px; border:1px solid #eee; }

    .badges-filtros { margin-bottom:14px; }
    .badge-filtro {
        background:#e6f0fa; color:#0069d9; padding:4px 12px;
        border-radius:12px; font-size:12px; font-weight:bold;
        display:inline-flex; align-items:center; gap:6px;
        margin-right:6px; margin-bottom:6px;
    }
    .badge-filtro .x {
        cursor:pointer; color:inherit; text-decoration:none;
        font-size:14px; line-height:1; font-weight:bold;
    }
    .badge-filtro .x:hover { color:#dc3545; }

    .info-zona {
        font-size:11px; color:#888; margin-top:2px;
    }

    /* Bloco da 1ª seção (referência) */
    .ref-secao {
        margin-top: 8px;
        padding: 6px 10px;
        background: #f4f8ff;
        border-left: 3px solid #4d94ff;
        border-radius: 4px;
        font-size: 12px;
        color: #333;
        line-height: 1.4;
    }
    .ref-secao .ref-titulo {
        font-size: 10.5px;
        text-transform: uppercase;
        color: #4d94ff;
        font-weight: bold;
        letter-spacing: .5px;
        margin-bottom: 2px;
    }
    .ref-secao .ref-secao-num {
        display:inline-block;
        background:#4d94ff; color:#fff;
        padding: 1px 6px; border-radius: 8px;
        font-size: 10.5px; font-weight: bold;
        margin-right: 4px;
        font-family: monospace;
    }
    .ref-secao .ref-local {
        color: #555; font-style: italic;
    }
    .ref-secao .ref-vazio {
        color: #b94a48; font-style: italic;
    }
</style>
</head>
<body>

<a href="consultar.php" class="link-voltar">&larr; Voltar para a consulta</a>

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

<h1>Lista de Zonas Eleitorais</h1>
<div style="color:#666;font-size:13px;margin-bottom:18px;">
    Lista as zonas eleitorais com a quantidade de seções e o total de votos.
    Cada zona pode abranger <strong>vários municípios</strong> — todos são listados.
    Zonas formatadas com <strong>3 dígitos</strong> (ex.: <code>001</code>).
    Sem mapa.
</div>

<form method="get" action="zonas_lista.php" id="form-filtros">
    <div class="filtros">

        <label for="ano">Ano:</label>
        <select name="ano" id="ano" style="min-width:110px;">
            <option value="">Todos</option>
            <?php foreach ($anos as $a): ?>
                <option value="<?= (int)$a ?>"
                    <?= ((string)$a === (string)$ano) ? 'selected' : '' ?>>
                    <?= (int)$a ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="municipio">Município:</label>
        <select name="municipio" id="municipio" style="min-width:220px;">
            <option value="">Todos</option>
            <?php foreach ($municipiosLista as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>"
                    <?= $m === $municipio ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="candidato">Candidato:</label>
        <select name="candidato" id="candidato" style="min-width:260px;">
            <option value="">Todos</option>
            <?php foreach ($candidatosLista as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= $c === $candidato ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="min_votos">Mín. votos:</label>
        <input type="number" name="min_votos" id="min_votos" min="0" step="1"
               placeholder="ex: 100"
               value="<?= htmlspecialchars($minVotos) ?>">

        <label for="min_secoes">Mín. seções:</label>
        <input type="number" name="min_secoes" id="min_secoes" min="0" step="1"
               placeholder="ex: 5"
               value="<?= htmlspecialchars($minSecoes) ?>">

        <label for="ordem">Ordenar por:</label>
        <select name="ordem" id="ordem">
            <?php
            $opcoesOrdem = [
                'municipio' => 'Município / Zona',
                'zona'      => 'Zona',
                'votos'     => 'Mais votos',
                'secoes'    => 'Mais seções',
            ];
            foreach ($opcoesOrdem as $k => $rot):
            ?>
                <option value="<?= $k ?>" <?= $ordem === $k ? 'selected' : '' ?>>
                    <?= htmlspecialchars($rot) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn-consultar">Consultar</button>
        <a href="zonas_lista.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<?php
// Badges de filtros ativos
$temFiltro = ($ano !== '' || $municipio !== '' || $candidato !== ''
              || ($minVotos !== '' && (int)$minVotos > 0)
              || ($minSecoes !== '' && (int)$minSecoes > 0));

if ($temFiltro):
    $baseQ = ['ano' => $ano, 'municipio' => $municipio, 'candidato' => $candidato,
              'min_votos' => $minVotos, 'min_secoes' => $minSecoes, 'ordem' => $ordem];
?>
<div class="badges-filtros">
    <span style="font-size:12px; color:#666; margin-right:8px;">Filtros ativos:</span>

    <?php if ($ano !== ''): ?>
        <?php $q = $baseQ; $q['ano'] = ''; ?>
        <span class="badge-filtro">
            📅 <?= (int)$ano ?>
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($municipio !== ''): ?>
        <?php $q = $baseQ; $q['municipio'] = ''; ?>
        <span class="badge-filtro">
            🏙️ <?= htmlspecialchars($municipio) ?>
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($candidato !== ''): ?>
        <?php $q = $baseQ; $q['candidato'] = ''; ?>
        <span class="badge-filtro">
            🗳 <?= htmlspecialchars($candidato) ?>
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($minVotos !== '' && (int)$minVotos > 0): ?>
        <?php $q = $baseQ; $q['min_votos'] = ''; ?>
        <span class="badge-filtro">
            🔢 ≥ <?= (int)$minVotos ?> votos
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($minSecoes !== '' && (int)$minSecoes > 0): ?>
        <?php $q = $baseQ; $q['min_secoes'] = ''; ?>
        <span class="badge-filtro">
            📍 ≥ <?= (int)$minSecoes ?> seções
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$linhas): ?>

    <div class="aviso">
        Nenhuma zona encontrada para os filtros selecionados.
        <?php if ($municipio === '' && $ano === '' && $candidato === ''
                  && $minVotos === '' && $minSecoes === ''): ?>
            <br>Não há dados na tabela <code>votos</code>.
        <?php endif; ?>
    </div>

<?php else: ?>

    <!-- KPIs -->
    <div class="kpis">
        <div class="kpi azul">
            <div class="rotulo">Zonas listadas</div>
            <div class="valor"><?= number_format($totalZonas, 0, ',', '.') ?></div>
        </div>

        <div class="kpi roxo">
            <div class="rotulo">Municípios abrangidos</div>
            <div class="valor"><?= number_format($qtdMunicipios, 0, ',', '.') ?></div>
            <div class="sub">em todas as zonas listadas</div>
        </div>

        <div class="kpi laranja">
            <div class="rotulo">Total de seções</div>
            <div class="valor"><?= number_format($totalSecoes, 0, ',', '.') ?></div>
            <div class="sub">somatório das zonas</div>
        </div>

        <div class="kpi verde">
            <div class="rotulo">Total de votos</div>
            <div class="valor"><?= number_format($totalVotos, 0, ',', '.') ?></div>
        </div>

        <div class="kpi cinza">
            <div class="rotulo">Média votos / zona</div>
            <div class="valor">
                <?= number_format($totalZonas > 0 ? $totalVotos / $totalZonas : 0, 0, ',', '.') ?>
            </div>
        </div>

        <div class="kpi teal">
            <div class="rotulo">Maior zona</div>
            <div class="valor"><?= number_format($maxVotos, 0, ',', '.') ?></div>
            <div class="sub">votos</div>
        </div>
    </div>

    <!-- Tabela -->
    <h2>Zonas de votação</h2>
    <table>
        <thead>
            <tr>
                <th style="width:50px;">#</th>
                <th style="width:80px;">Zona</th>
                <th>Municípios da zona</th>
                <th>Endereço da zona / Sede</th>
                <th style="width:170px;">Coordenadas</th>
                <th class="num" style="width:90px;">Seções</th>
                <th class="num" style="width:110px;">Votos</th>
                <th class="num" style="width:90px;">% do total</th>
                <th style="width:140px;">Proporção</th>
            </tr>
        </thead>
        <tbody>
            <?php $pos = 1; foreach ($linhas as $l):
                $pct      = $totalVotos > 0 ? ($l['total_votos'] / $totalVotos) * 100 : 0;
                $largura  = $maxVotos > 0 ? ($l['total_votos'] / $maxVotos) * 100 : 0;
                $temCoord = !empty($l['latitude']) && !empty($l['longitude']);

                $listaMuns = array_values(array_filter(
                    array_map('trim', explode('•', (string)$l['municipios'])),
                    fn($x) => $x !== ''
                ));
                $qtdMuns = count($listaMuns);

                $qBase = [
                    'ano'        => $ano,
                    'candidato'  => $candidato,
                    'min_votos'  => $minVotos,
                    'min_secoes' => $minSecoes,
                    'ordem'      => $ordem,
                ];

                // Dados da 1ª seção
                $temRefSecao = !empty($l['primeira_secao_num']);
                $refEndereco = trim((string)($l['primeira_secao_endereco'] ?? ''));
                $refBairro   = trim((string)($l['primeira_secao_bairro']   ?? ''));
                $refCep      = trim((string)($l['primeira_secao_cep']      ?? ''));
                $refLocal    = trim((string)($l['primeira_secao_local']    ?? ''));
            ?>
                <tr>
                    <td><?= $pos++ ?></td>
                    <td>
                        <span class="zona-tag"><?= fmtZona3($l['numeroZona']) ?></span>
                    </td>
                    <td>
                        <div class="municipios-lista">
                            <?php foreach ($listaMuns as $mun):
                                $qLink = $qBase;
                                $qLink['municipio'] = (strcasecmp($mun, $municipio) === 0) ? '' : $mun;
                                $atual = (strcasecmp($mun, $municipio) === 0);
                            ?>
                                <a class="municipio-tag <?= $atual ? 'atual' : '' ?>"
                                   href="?<?= htmlspecialchars(http_build_query($qLink)) ?>"
                                   title="<?= $atual ? 'Remover filtro deste município' : 'Filtrar por ' . htmlspecialchars($mun) ?>">
                                    <?= htmlspecialchars($mun) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($qtdMuns > 1): ?>
                            <div class="info-zona">
                                <?= $qtdMuns ?> municípios nesta zona
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($l['endereco_tse'])): ?>
                            <div class="endereco">
                                <?= htmlspecialchars($l['endereco_tse']) ?>
                                <?php if (!empty($l['zona_bairro'])): ?>
                                    <span class="bairro">— <?= htmlspecialchars($l['zona_bairro']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($l['zona_cep'])): ?>
                                    <br><span class="bairro">CEP: <?= htmlspecialchars($l['zona_cep']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($l['zona_municipio_sede'])): ?>
                                    <br><span class="bairro">Sede: <?= htmlspecialchars($l['zona_municipio_sede']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="endereco vazio">Endereço da zona não cadastrado</span>
                        <?php endif; ?>

                        <?php if ($temRefSecao): ?>
                            <div class="ref-secao">
                                <div class="ref-titulo">📍 Referência — 1ª seção da zona</div>
                                <div>
                                    <span class="ref-secao-num">Seção <?= htmlspecialchars((string)$l['primeira_secao_num']) ?></span>
                                    <?php if ($refEndereco !== ''): ?>
                                        <?= htmlspecialchars($refEndereco) ?>
                                        <?php if ($refBairro !== ''): ?>
                                            <span class="bairro">— <?= htmlspecialchars($refBairro) ?></span>
                                        <?php endif; ?>
                                        <?php if ($refCep !== ''): ?>
                                            <br><span class="bairro">CEP: <?= htmlspecialchars($refCep) ?></span>
                                        <?php endif; ?>
                                    <?php elseif ($refLocal !== ''): ?>
                                        <span class="ref-local"><?= htmlspecialchars($refLocal) ?></span>
                                    <?php else: ?>
                                        <span class="ref-vazio">endereço da seção não cadastrado</span>
                                    <?php endif; ?>
                                    <?php if ($refLocal !== '' && $refEndereco !== ''): ?>
                                        <br><span class="ref-local">Local: <?= htmlspecialchars($refLocal) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="coord">
                        <?php if ($temCoord): ?>
                            <?= number_format((float)$l['latitude'], 6, '.', '') ?>,
                            <?= number_format((float)$l['longitude'], 6, '.', '') ?>
                        <?php else: ?>
                            <span class="vazio">não disponível</span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= number_format((int)$l['qtd_secoes'], 0, ',', '.') ?></td>
                    <td class="num"><strong><?= number_format((int)$l['total_votos'], 0, ',', '.') ?></strong></td>
                    <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                    <td>
                        <span class="barra">
                            <span style="width: <?= number_format($largura, 2, '.', '') ?>%"></span>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">Total (<?= number_format($totalZonas, 0, ',', '.') ?> zona<?= $totalZonas !== 1 ? 's' : '' ?>)</td>
                <td class="num"><?= number_format($totalSecoes, 0, ',', '.') ?></td>
                <td class="num"><?= number_format($totalVotos, 0, ',', '.') ?></td>
                <td class="num">100,00%</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

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

        function initSel($el, placeholder) {
            if (!$el || !$el.length) return;
            $el.select2({
                language: 'pt-BR',
                placeholder: placeholder,
                allowClear: true,
                width: 'resolve',
                matcher: matcherSemAcento
            });
        }

        const $ano       = jQuery('#ano');
        const $municipio = jQuery('#municipio');
        const $candidato = jQuery('#candidato');
        const $ordem     = jQuery('#ordem');

        initSel($ano,       'Todos os anos');
        initSel($municipio, 'Buscar município...');
        initSel($candidato, 'Buscar candidato...');
        initSel($ordem,     'Ordenar por...');

        // Ao mudar o ano → recarrega para atualizar lista de candidatos
        if ($ano.length) {
            $ano.on('select2:select select2:clear', function () {
                if ($candidato.length) {
                    $candidato.val('').trigger('change.select2');
                }
                document.getElementById('form-filtros').submit();
            });
        }
    })();
</script>

</body>
</html>