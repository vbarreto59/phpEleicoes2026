<?php
// ------------------------------------------------------------
// Configuração do banco
// zonas.php
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

// ------------------------------------------------------------
// Filtros recebidos
// ------------------------------------------------------------
$municipio = $_GET['municipio'] ?? '';
$zona      = $_GET['zona']      ?? '';
$local     = $_GET['local']     ?? '';
$cargo     = $_GET['cargo']     ?? '';
$candidato = $_GET['candidato'] ?? '';

// Seções (array). Cada item no formato "zona|secao"
$secoesRaw = $_GET['secoes'] ?? [];
if (!is_array($secoesRaw)) $secoesRaw = [$secoesRaw];

$secoesValidas   = [];   // [ ['zona'=>int, 'secao'=>int], ... ]
$secoesValidasSet = [];  // "zona|secao" => true (para marcar no <select>)
foreach ($secoesRaw as $s) {
    $s = trim((string)$s);
    if ($s === '') continue;
    [$zS, $sS] = array_pad(explode('|', $s, 2), 2, '');
    $zS = (int)$zS;
    $sS = (int)$sS;
    if ($zS > 0 && $sS > 0) {
        $secoesValidas[] = ['zona' => $zS, 'secao' => $sS];
        $secoesValidasSet[$zS . '|' . $sS] = true;
    }
}

// Local (formato "zona|nomeLocal")
$zLocal    = '';
$nomeLocal = '';
if ($local !== '') {
    [$zLocal, $nomeLocal] = array_pad(explode('|', $local, 2), 2, '');
}

// ------------------------------------------------------------
// Monta WHERE / params
// ------------------------------------------------------------
$filtros = [];
$params  = [];

if ($municipio !== '') {
    $filtros[] = "v.nomeMunicipio = :municipio";
    $params[':municipio'] = $municipio;
}
if ($zona !== '') {
    $filtros[] = "v.numeroZona = :zona";
    $params[':zona'] = (int)$zona;
}
if ($zLocal !== '' && $nomeLocal !== '') {
    $filtros[] = "EXISTS (
        SELECT 1 FROM secoes_enderecos s
        WHERE s.numeroZona = v.numeroZona
          AND s.numeroSecao = v.numeroSecao
          AND s.numeroZona = :localZona
          AND s.nomeLocal  = :localNome
    )";
    $params[':localZona'] = (int)$zLocal;
    $params[':localNome'] = $nomeLocal;
}
if (!empty($secoesValidas)) {
    $tuples = [];
    foreach ($secoesValidas as $i => $par) {
        $tuples[] = "(:sz{$i}, :ss{$i})";
        $params[":sz{$i}"] = $par['zona'];
        $params[":ss{$i}"] = $par['secao'];
    }
    $filtros[] = "(v.numeroZona, v.numeroSecao) IN (" . implode(',', $tuples) . ")";
}
if ($cargo !== '') {
    $filtros[] = "v.nomeCargoPerg = :cargo";
    $params[':cargo'] = $cargo;
}
if ($candidato !== '') {
    $filtros[] = "v.nomeCandidatoResp = :candidato";
    $params[':candidato'] = $candidato;
}

$where = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

// ------------------------------------------------------------
// Total por zona + seções + pontos do heatmap
// ------------------------------------------------------------
$zonas              = [];
$totalMunicipio     = 0;
$totalSecoes        = 0;
$totalSecoesComVoto = 0;
$porZona            = [];

$pontosHeat      = [];
$maxVotosSecao   = 0;
$totalVotosSecao = 0;
$secoesHeat      = 0;

$zonasComCoord = [];

if ($municipio !== '') {
    // ---- Zonas ---------------------------------------------
    $sql = "SELECT
                v.numeroZona,
                SUM(v.qtdVotos) AS total,
                z.endereco_tse,
                z.bairro        AS zona_bairro,
                z.cep           AS zona_cep,
                z.nomeMunicipio AS zona_municipio_sede,
                z.latitude,
                z.longitude
            FROM votos v
            LEFT JOIN zonas_enderecos z ON z.numeroZona = v.numeroZona
            $where
            GROUP BY v.numeroZona, z.endereco_tse, z.bairro, z.cep,
                     z.nomeMunicipio, z.latitude, z.longitude
            ORDER BY v.numeroZona";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $zonas = $stmt->fetchAll();

    foreach ($zonas as $z) {
        $totalMunicipio += (int)$z['total'];
        if (!empty($z['latitude']) && !empty($z['longitude'])) {
            $zonasComCoord[] = (int)$z['numeroZona'];
        }
    }

    // ---- Seções cadastradas (zona/local/seção) -------------
    $sqlCad = "SELECT numeroZona, numeroSecao, nomeLocal, qtdAptos,
                      latitude, longitude
               FROM secoes_enderecos
               WHERE nomeMunicipio = :m";
    $paramsCad = [':m' => $municipio];

    if ($zona !== '') {
        $sqlCad .= " AND numeroZona = :z";
        $paramsCad[':z'] = (int)$zona;
    }
    if ($zLocal !== '' && $nomeLocal !== '') {
        $sqlCad .= " AND numeroZona = :zL AND nomeLocal = :nL";
        $paramsCad[':zL'] = (int)$zLocal;
        $paramsCad[':nL'] = $nomeLocal;
    }
    if (!empty($secoesValidas)) {
        $tuples = [];
        foreach ($secoesValidas as $i => $par) {
            $tuples[] = "(:cz{$i}, :cs{$i})";
            $paramsCad[":cz{$i}"] = $par['zona'];
            $paramsCad[":cs{$i}"] = $par['secao'];
        }
        $sqlCad .= " AND (numeroZona, numeroSecao) IN (" . implode(',', $tuples) . ")";
    }
    $sqlCad .= " ORDER BY numeroZona, numeroSecao";
    $stmt = $pdo->prepare($sqlCad);
    $stmt->execute($paramsCad);
    $secoesCadastradas = [];
    foreach ($stmt->fetchAll() as $row) {
        $secoesCadastradas[$row['numeroZona']][(int)$row['numeroSecao']] = [
            'nomeLocal' => $row['nomeLocal'],
            'qtdAptos'  => $row['qtdAptos'],
            'latitude'  => $row['latitude'],
            'longitude' => $row['longitude'],
        ];
    }

    // ---- Votos por (zona, seção) ---------------------------
    $sqlVotos = "SELECT v.numeroZona, v.numeroSecao, SUM(v.qtdVotos) AS total
                 FROM votos v
                 $where
                 GROUP BY v.numeroZona, v.numeroSecao
                 ORDER BY v.numeroZona, v.numeroSecao";
    $stmt = $pdo->prepare($sqlVotos);
    $stmt->execute($params);
    $votosPorZonaSecao = [];
    foreach ($stmt->fetchAll() as $row) {
        $votosPorZonaSecao[$row['numeroZona']][(int)$row['numeroSecao']] = (int)$row['total'];
    }

    // ---- Merge --------------------------------------------
    $zonasComSecoes = array_unique(array_merge(
        array_keys($secoesCadastradas),
        array_keys($votosPorZonaSecao)
    ));

    foreach ($zonasComSecoes as $zonaNum) {
        $secs = [];

        if (isset($secoesCadastradas[$zonaNum])) {
            foreach ($secoesCadastradas[$zonaNum] as $sec => $info) {
                $votos = $votosPorZonaSecao[$zonaNum][$sec] ?? 0;
                $secs[$sec] = [
                    'numeroSecao' => $sec,
                    'nomeLocal'   => $info['nomeLocal'],
                    'qtdAptos'    => $info['qtdAptos'],
                    'latitude'    => $info['latitude'],
                    'longitude'   => $info['longitude'],
                    'total'       => $votos,
                ];
            }
        }

        if (isset($votosPorZonaSecao[$zonaNum])) {
            foreach ($votosPorZonaSecao[$zonaNum] as $sec => $votos) {
                if (!isset($secs[$sec])) {
                    $secs[$sec] = [
                        'numeroSecao' => $sec,
                        'nomeLocal'   => null,
                        'qtdAptos'    => null,
                        'latitude'    => null,
                        'longitude'   => null,
                        'total'       => $votos,
                    ];
                }
            }
        }

        ksort($secs);
        $porZona[$zonaNum] = array_values($secs);

        $totalSecoes += count($porZona[$zonaNum]);
        foreach ($porZona[$zonaNum] as $s) {
            if ($s['total'] > 0) $totalSecoesComVoto++;
        }
    }

    // ---- Heatmap ------------------------------------------
    foreach ($porZona as $zonaNum => $listaSecoes) {
        foreach ($listaSecoes as $s) {
            if (empty($s['latitude']) || empty($s['longitude'])) continue;
            if ((int)$s['total'] <= 0) continue;

            $lat = (float)$s['latitude'];
            $lng = (float)$s['longitude'];
            if ($lat === 0.0 && $lng === 0.0) continue;

            $pontosHeat[] = [
                'lat'   => $lat,
                'lng'   => $lng,
                'total' => (int)$s['total'],
                'zona'  => $zonaNum,
                'secao' => $s['numeroSecao'],
                'local' => $s['nomeLocal'] ?? '',
                'aptos' => $s['qtdAptos'],
            ];

            $totalVotosSecao += (int)$s['total'];
            $secoesHeat++;
            if ($s['total'] > $maxVotosSecao) $maxVotosSecao = (int)$s['total'];
        }
    }
}

// ------------------------------------------------------------
// Listas para os selects
// ------------------------------------------------------------
$municipios = $pdo->query(
    "SELECT DISTINCT nomeMunicipio FROM votos ORDER BY nomeMunicipio"
)->fetchAll(PDO::FETCH_COLUMN);

$zonasDisponiveis = [];
if ($municipio !== '') {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT numeroZona
         FROM votos
         WHERE nomeMunicipio = :m
         ORDER BY numeroZona"
    );
    $stmt->execute([':m' => $municipio]);
    $zonasDisponiveis = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$locaisDisponiveis = [];
if ($municipio !== '') {
    $sqlLoc = "SELECT DISTINCT numeroZona, nomeLocal
               FROM secoes_enderecos
               WHERE nomeMunicipio = :m
                 AND nomeLocal IS NOT NULL
                 AND nomeLocal <> ''";
    $paramsLoc = [':m' => $municipio];
    if ($zona !== '') {
        $sqlLoc .= " AND numeroZona = :z";
        $paramsLoc[':z'] = (int)$zona;
    }
    $sqlLoc .= " ORDER BY numeroZona, nomeLocal";
    $stmt = $pdo->prepare($sqlLoc);
    $stmt->execute($paramsLoc);
    $locaisDisponiveis = $stmt->fetchAll();
}

// Seções disponíveis (filtradas por município/zona/local)
$secoesDisponiveis = [];
if ($municipio !== '') {
    $sqlSec = "SELECT DISTINCT numeroZona, numeroSecao, nomeLocal
               FROM secoes_enderecos
               WHERE nomeMunicipio = :m";
    $paramsSec = [':m' => $municipio];
    if ($zona !== '') {
        $sqlSec .= " AND numeroZona = :z";
        $paramsSec[':z'] = (int)$zona;
    }
    if ($zLocal !== '' && $nomeLocal !== '') {
        $sqlSec .= " AND numeroZona = :zL AND nomeLocal = :nL";
        $paramsSec[':zL'] = (int)$zLocal;
        $paramsSec[':nL'] = $nomeLocal;
    }
    $sqlSec .= " ORDER BY numeroZona, numeroSecao";
    $stmt = $pdo->prepare($sqlSec);
    $stmt->execute($paramsSec);
    $secoesDisponiveis = $stmt->fetchAll();
}

$cargos = $pdo->query(
    "SELECT DISTINCT nomeCargoPerg FROM votos ORDER BY nomeCargoPerg"
)->fetchAll(PDO::FETCH_COLUMN);

$candidatos = $pdo->query(
    "SELECT DISTINCT nomeCandidatoResp FROM votos ORDER BY nomeCandidatoResp"
)->fetchAll(PDO::FETCH_COLUMN);

$localLabel = '';
if ($zLocal !== '' && $nomeLocal !== '') {
    $localLabel = "Zona $zLocal — $nomeLocal";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Total de Votos por Zona</title>

<!-- Leaflet -->
<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>

<!-- Select2 (busca nos selects) -->
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
    .kpi.azul    { border-left-color:#0d6efd; } .kpi.azul .valor { color:#0d6efd; font-size:20px; }
    .kpi.teal    { border-left-color:#20c997; } .kpi.teal .valor { color:#20c997; }

    form { background:#fff; padding:15px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px; }
    label { display:inline-block; margin-right:10px; font-weight:bold; }
    select, button { padding:6px 8px; margin:4px 10px 4px 0; border-radius:4px; border:1px solid #ccc; font-size:14px; }
    button { cursor:pointer; border:none; }
    .btn-consultar { background:#0069d9; color:#fff; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none; padding:6px 12px; border-radius:4px; display:inline-block; margin:4px 0; }
    .btn-limpar:hover { background:#5a6268; }
    .filtros { display:flex; flex-wrap:wrap; align-items:center; }
    select#zona:disabled, select#local:disabled, select#secoes:disabled { background:#f0f0f0; color:#999; }

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
    .select2-container--default .select2-selection--multiple {
        min-height: 34px; padding: 3px 6px; border: 1px solid #ccc; border-radius: 4px;
        background: #fff;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background: #0069d9; color: #fff; border: none; padding: 1px 6px;
        font-size: 12px;
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
    #local + .select2-container { min-width: 340px; max-width: 420px; }
    #zona + .select2-container  { min-width: 120px; }
    #secoes + .select2-container { min-width: 340px; max-width: 480px; }
    #secoes + .select2-container .select2-selection--multiple {
        max-height: 120px; overflow-y: auto;
    }

    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; }
    th { background:#0069d9; color:#fff; }
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
    details { background:#fff; border-radius:6px; padding:10px 15px; margin-bottom:8px;
              box-shadow:0 1px 2px rgba(0,0,0,.06); }
    summary { cursor:pointer; font-weight:bold; color:#0069d9; padding:4px 0; }
    summary:hover { text-decoration:underline; }
    summary .endereco-summary { font-weight:normal; color:#555; font-size:13px; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }
    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }

    details table { margin-top:10px; }
    details table th { font-size:12px; }
    details table td { font-size:13px; }
    .linha-sem-voto td { color:#999; }
    .linha-sem-voto .tag { display:inline-block; background:#eee; color:#777;
                           font-size:10px; padding:1px 6px; border-radius:8px;
                           margin-left:6px; text-transform:uppercase; letter-spacing:.5px; }
    .local-nome { font-size:12px; color:#555; }
    .local-vazio { color:#b94a48; font-style:italic; font-size:12px; }

    #mapa-calor { height: 620px; width: 100%; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.15); background:#e5e5e5;
            margin-top: 10px; }
    .mapa-info { font-size:13px; color:#555; margin: 6px 0 14px; }

    .heat-controles {
        background:#fff; padding:10px 14px; border-radius:6px;
        box-shadow:0 1px 2px rgba(0,0,0,.08); font-size:13px;
        display:flex; flex-wrap:wrap; align-items:center; gap:14px;
        margin-bottom:8px;
    }
    .heat-controles label { font-weight:bold; font-size:12px; }
    .heat-controles input[type=range] { width: 150px; vertical-align:middle; }
    .heat-controles .valor { font-family:monospace; color:#0069d9; font-weight:bold;
                             min-width: 34px; display:inline-block; }

    .legenda-heat {
        background: rgba(255,255,255,.92); padding: 6px 10px; border-radius:6px;
        box-shadow: 0 1px 3px rgba(0,0,0,.2); font-size: 12px;
    }
    .legenda-heat .gradiente {
        width: 140px; height: 10px; border-radius: 4px;
        background: linear-gradient(to right, #0000ff, #00ffff, #00ff00, #ffff00, #ff0000);
        margin: 4px 0;
    }
    .legenda-heat .extremos { display:flex; justify-content:space-between; color:#555; }

    .leaflet-popup-content { font-family: Arial, sans-serif; font-size: 13px; }
    .leaflet-popup-content b { color:#0069d9; }

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

<h1>Total de Votos por Zona</h1>

<form method="get" action="zonas.php" id="form-filtros">
    <div class="filtros">
        <label for="municipio">Município:</label>
        <select name="municipio" id="municipio" required
                onchange="this.form.submit()">
            <option value="">-- Selecione --</option>
            <?php foreach ($municipios as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>"
                    <?= $m === $municipio ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="zona">Zona:</label>
        <select name="zona" id="zona"
                <?= $municipio === '' ? 'disabled' : '' ?>
                onchange="this.form.submit()">
            <option value="">Todas</option>
            <?php foreach ($zonasDisponiveis as $zn): ?>
                <option value="<?= htmlspecialchars($zn) ?>"
                    <?= (string)$zn === (string)$zona ? 'selected' : '' ?>>
                    <?= htmlspecialchars($zn) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="local">Local:</label>
        <select name="local" id="local"
                <?= $municipio === '' ? 'disabled' : '' ?>>
            <option value="">Todos</option>
            <?php foreach ($locaisDisponiveis as $lc):
                $val = $lc['numeroZona'] . '|' . $lc['nomeLocal'];
            ?>
                <option value="<?= htmlspecialchars($val) ?>"
                    <?= $val === $local ? 'selected' : '' ?>>
                    Zona <?= htmlspecialchars($lc['numeroZona']) ?>
                    — <?= htmlspecialchars($lc['nomeLocal']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="secoes">Seções:</label>
        <select name="secoes[]" id="secoes" multiple
                <?= $municipio === '' ? 'disabled' : '' ?>>
            <?php foreach ($secoesDisponiveis as $sc):
                $val = $sc['numeroZona'] . '|' . $sc['numeroSecao'];
                $sel = isset($secoesValidasSet[$val]);
            ?>
                <option value="<?= htmlspecialchars($val) ?>"
                    <?= $sel ? 'selected' : '' ?>>
                    Zona <?= htmlspecialchars($sc['numeroZona']) ?>
                    — Seção <?= htmlspecialchars($sc['numeroSecao']) ?>
                    <?php if (!empty($sc['nomeLocal'])): ?>
                        (<?= htmlspecialchars($sc['nomeLocal']) ?>)
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>

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

        <button type="submit" class="btn-consultar">Consultar</button>
        <a href="zonas.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<?php if ($municipio === ''): ?>

    <div class="aviso">
        Selecione um <strong>município</strong> para ver o total de votos por zona.
    </div>

<?php else: ?>

    <?php if (!$zonas): ?>
        <p>Nenhum voto encontrado para os filtros selecionados.</p>
    <?php else: ?>

        <!-- KPIs -->
        <div class="kpis">
            <div class="kpi">
                <div class="rotulo">Município</div>
                <div class="valor" style="font-size:20px;"><?= htmlspecialchars($municipio) ?></div>
            </div>
            <?php if ($zona !== ''): ?>
                <div class="kpi roxo">
                    <div class="rotulo">Zona filtrada</div>
                    <div class="valor"><?= htmlspecialchars($zona) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($localLabel !== ''): ?>
                <div class="kpi azul">
                    <div class="rotulo">Local filtrado</div>
                    <div class="valor"><?= htmlspecialchars($nomeLocal) ?></div>
                    <div class="sub"><?= htmlspecialchars('Zona ' . $zLocal) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($secoesValidas)): ?>
                <div class="kpi teal">
                    <div class="rotulo">Seções filtradas</div>
                    <div class="valor"><?= count($secoesValidas) ?></div>
                </div>
            <?php endif; ?>
            <div class="kpi verde">
                <div class="rotulo">Total de votos</div>
                <div class="valor"><?= number_format($totalMunicipio, 0, ',', '.') ?></div>
            </div>
            <div class="kpi cinza">
                <div class="rotulo"><?= $zona !== '' ? 'Zona' : 'Zonas eleitorais' ?></div>
                <div class="valor"><?= number_format(count($zonas), 0, ',', '.') ?></div>
            </div>
            <div class="kpi laranja">
                <div class="rotulo">Seções eleitorais</div>
                <div class="valor"><?= number_format($totalSecoes, 0, ',', '.') ?></div>
                <div class="sub"><?= number_format($totalSecoesComVoto, 0, ',', '.') ?> com voto</div>
            </div>
            <div class="kpi vermelho">
                <div class="rotulo">Seções no heatmap</div>
                <div class="valor"><?= number_format($secoesHeat, 0, ',', '.') ?></div>
                <div class="sub"><?= number_format($totalVotosSecao, 0, ',', '.') ?> votos</div>
            </div>
        </div>

        <!-- Tabela principal -->
        <h2>
            <?php if (!empty($secoesValidas)): ?>
                Total das seções filtradas
            <?php elseif ($localLabel !== ''): ?>
                Total do local
            <?php elseif ($zona !== ''): ?>
                Total da zona <?= htmlspecialchars($zona) ?>
            <?php else: ?>
                Total por zona
            <?php endif; ?>
        </h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Zona</th>
                    <th>Local / Endereço</th>
                    <th>Coordenadas</th>
                    <th class="num">Votos</th>
                    <th class="num">% do total</th>
                    <th>Proporção</th>
                </tr>
            </thead>
            <tbody>
                <?php $pos = 1; foreach ($zonas as $z):
                    $pct = $totalMunicipio > 0 ? ($z['total'] / $totalMunicipio) * 100 : 0;
                    $largura = $zonas[0]['total'] > 0
                        ? ($z['total'] / $zonas[0]['total']) * 100
                        : 0;
                    $temCoord = !empty($z['latitude']) && !empty($z['longitude']);
                ?>
                    <tr>
                        <td><?= $pos++ ?></td>
                        <td><strong><?= htmlspecialchars($z['numeroZona']) ?></strong></td>
                        <td>
                            <?php if (!empty($z['endereco_tse'])): ?>
                                <div class="endereco">
                                    <?= htmlspecialchars($z['endereco_tse']) ?>
                                    <?php if (!empty($z['zona_bairro'])): ?>
                                        <span class="bairro">— <?= htmlspecialchars($z['zona_bairro']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($z['zona_cep'])): ?>
                                        <br><span class="bairro">CEP: <?= htmlspecialchars($z['zona_cep']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($z['zona_municipio_sede'])
                                              && $z['zona_municipio_sede'] !== $municipio): ?>
                                        <br><span class="bairro">Sede: <?= htmlspecialchars($z['zona_municipio_sede']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="endereco vazio">Endereço não cadastrado</span>
                            <?php endif; ?>
                        </td>
                        <td class="coord">
                            <?php if ($temCoord): ?>
                                <?= number_format((float)$z['latitude'], 6, '.', '') ?>,
                                <?= number_format((float)$z['longitude'], 6, '.', '') ?>
                            <?php else: ?>
                                <span class="vazio">não disponível</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= number_format((int)$z['total'], 0, ',', '.') ?></td>
                        <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                        <td>
                            <span class="barra"><span style="width: <?= number_format($largura, 2, '.', '') ?>%"></span></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Total</td>
                    <td class="num"><?= number_format($totalMunicipio, 0, ',', '.') ?></td>
                    <td class="num">100,00%</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <!-- Mapa de calor -->
        <h2>Mapa de calor por seção</h2>

        <?php if (!$pontosHeat): ?>
            <div class="aviso">
                Nenhuma seção com voto e coordenada foi encontrada para os filtros.
                Confirme se <code>secoes_enderecos</code> está preenchida com
                <code>latitude</code> e <code>longitude</code>.
            </div>
        <?php else: ?>
            <div class="mapa-info">
                Cada ponto é uma <strong>seção eleitoral</strong>. A intensidade da cor
                é proporcional ao total de votos da seção (respeitando os filtros
                de cargo/candidato/local/seção).
                <?php if ($totalVotosSecao < $totalMunicipio): ?>
                    <br>
                    <span style="color:#b94a48;">
                        Atenção: <?= number_format($totalMunicipio - $totalVotosSecao, 0, ',', '.') ?>
                        voto(s) em seções sem coordenada não aparecem no heatmap.
                    </span>
                <?php endif; ?>
            </div>

            <div class="heat-controles">
                <label for="heatRaio">Intensidade:</label>
                <input type="range" id="heatRaio" min="5" max="80" step="1" value="25">
                <span class="valor" id="heatRaioVal">25</span>

                <label for="heatOpac">Opacidade:</label>
                <input type="range" id="heatOpac" min="0.1" max="1" step="0.05" value="0.65">
                <span class="valor" id="heatOpacVal">0.65</span>

                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="checkbox" id="mostrarPontos"> mostrar pontos
                </label>
            </div>

            <div id="mapa-calor"></div>

            <script>
                const PONTOS_HEAT = <?= json_encode($pontosHeat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const MAX_VOTOS_SECAO = <?= (int)$maxVotosSecao ?>;

                (function () {
                    const mapa = L.map('mapa-calor', {
                        scrollWheelZoom: true
                    }).setView([-8.05, -34.9], 11);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                    }).addTo(mapa);

                    if (!PONTOS_HEAT.length) return;

                    (function espalhar() {
                        const grupos = {};
                        PONTOS_HEAT.forEach(p => {
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

                    const heatData = PONTOS_HEAT.map(p => {
                        const peso = MAX_VOTOS_SECAO > 0 ? (p.total / MAX_VOTOS_SECAO) : 0;
                        return [p.lat, p.lng, peso];
                    });

                    const heatLayer = L.heatLayer(heatData, {
                        radius: 25,
                        blur: 20,
                        maxZoom: 17,
                        minOpacity: 0.25,
                        gradient: {
                            0.0: '#0000ff',
                            0.25: '#00ffff',
                            0.5: '#00ff00',
                            0.75: '#ffff00',
                            1.0: '#ff0000'
                        }
                    }).addTo(mapa);

                    const bounds = L.latLngBounds([]);
                    const marcadores = [];

                    PONTOS_HEAT.forEach(p => {
                        bounds.extend([p.lat, p.lng]);

                        const raioPx = 4 + 8 * Math.sqrt(
                            MAX_VOTOS_SECAO > 0 ? (p.total / MAX_VOTOS_SECAO) : 0
                        );

                        const html = `
                            <div style="min-width:180px;">
                                <div><b>Seção ${p.secao}</b> — Zona ${p.zona}</div>
                                ${p.local ? '<div style="font-size:12px;color:#555;margin-top:3px;">' +
                                            escapeHtml(p.local) + '</div>' : ''}
                                <div style="margin-top:6px;">
                                    <b>${formatNum(p.total)}</b> votos
                                    ${MAX_VOTOS_SECAO > 0
                                        ? '<span style="color:#888;">(' +
                                          ((p.total / MAX_VOTOS_SECAO) * 100).toFixed(1) +
                                          '% do maior)</span>'
                                        : ''}
                                </div>
                                ${p.aptos ? '<div style="font-size:12px;color:#888;margin-top:2px;">' +
                                            formatNum(p.aptos) + ' aptos</div>' : ''}
                            </div>`;

                        const marker = L.circleMarker([p.lat, p.lng], {
                            radius: raioPx,
                            color: '#003a7a',
                            weight: 1,
                            fillColor: '#0069d9',
                            fillOpacity: 0.15,
                            opacity: 0
                        }).addTo(mapa);

                        marker.bindPopup(html);
                        marker.bindTooltip(
                            'Seção ' + p.secao + ' (Zona ' + p.zona + ') — ' +
                            formatNum(p.total) + ' votos',
                            { sticky: true }
                        );
                        marcadores.push(marker);
                    });

                    if (bounds.isValid()) {
                        mapa.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 });
                    }

                    const rEl = document.getElementById('heatRaio');
                    const oEl = document.getElementById('heatOpac');
                    const rVal = document.getElementById('heatRaioVal');
                    const oVal = document.getElementById('heatOpacVal');

                    rEl.addEventListener('input', () => {
                        const r = parseInt(rEl.value, 10);
                        rVal.textContent = r;
                        heatLayer.setOptions({ radius: r, blur: Math.round(r * 0.8) });
                    });
                    // Nota: leaflet.heat não suporta a opção "opacity" — o slider
                    // ajusta a opacidade via CSS no container da layer.
                    oEl.addEventListener('input', () => {
                        const o = parseFloat(oEl.value);
                        oVal.textContent = o.toFixed(2);
                        const c = heatLayer.getContainer && heatLayer.getContainer();
                        if (c) c.style.opacity = o;
                    });
                    // aplica opacidade inicial
                    (function () {
                        const c = heatLayer.getContainer && heatLayer.getContainer();
                        if (c) c.style.opacity = parseFloat(oEl.value);
                    })();

                    document.getElementById('mostrarPontos')
                        .addEventListener('change', e => {
                            marcadores.forEach(m => {
                                m.setStyle({ opacity: e.target.checked ? 1 : 0 });
                            });
                        });

                    const legenda = L.control({ position: 'bottomright' });
                    legenda.onAdd = function () {
                        const div = L.DomUtil.create('div', 'legenda-heat');
                        div.innerHTML = `
                            <div style="font-weight:bold;color:#0069d9;">Intensidade</div>
                            <div class="gradiente"></div>
                            <div class="extremos">
                                <span>menos votos</span>
                                <span>mais votos</span>
                            </div>`;
                        return div;
                    };
                    legenda.addTo(mapa);
                })();

                function formatNum(n) {
                    return Number(n).toLocaleString('pt-BR');
                }
                function escapeHtml(s) {
                    return String(s).replace(/[&<>"']/g, c => ({
                        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
                    })[c]);
                }
            </script>
        <?php endif; ?>

        <!-- Detalhamento por seção -->
        <h2>Detalhamento por seção</h2>

        <?php foreach ($zonas as $z):
            $secoesZona = $porZona[$z['numeroZona']] ?? [];
            $qtdTotal   = count($secoesZona);
            $qtdComVoto = 0;
            foreach ($secoesZona as $s) { if ($s['total'] > 0) $qtdComVoto++; }
        ?>
            <details <?= ($zona !== '' || $localLabel !== '' || !empty($secoesValidas)) ? 'open' : '' ?>>
                <summary>
                    Zona <?= htmlspecialchars($z['numeroZona']) ?>
                    — <?= number_format((int)$z['total'], 0, ',', '.') ?> votos
                    em <?= $qtdTotal ?> seções
                    <span style="color:#888;font-weight:normal;font-size:12px;">
                        (<?= $qtdComVoto ?> com voto<?= $qtdTotal > $qtdComVoto ? ', ' . ($qtdTotal - $qtdComVoto) . ' sem voto' : '' ?>)
                    </span>
                    <?php if (!empty($z['endereco_tse'])): ?>
                        <br>
                        <span class="endereco-summary">
                            <?= htmlspecialchars($z['endereco_tse']) ?>
                            <?php if (!empty($z['zona_bairro'])): ?>
                                — <?= htmlspecialchars($z['zona_bairro']) ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </summary>
                <table>
                    <thead>
                        <tr>
                            <th style="width:70px;">Seção</th>
                            <th>Local de votação</th>
                            <th class="num" style="width:90px;">Aptos</th>
                            <th class="num" style="width:90px;">Votos</th>
                            <th class="num" style="width:90px;">% da zona</th>
                            <th class="num" style="width:110px;">Comparec.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $somaAptos   = 0;
                        $somaVotos   = 0;
                        foreach ($secoesZona as $s):
                            $pctSec = $z['total'] > 0 ? ($s['total'] / $z['total']) * 100 : 0;
                            $aptos  = $s['qtdAptos'] !== null ? (int)$s['qtdAptos'] : null;
                            $comp   = ($aptos !== null && $aptos > 0)
                                        ? ($s['total'] / $aptos) * 100
                                        : null;

                            if ($aptos !== null) $somaAptos += $aptos;
                            $somaVotos += (int)$s['total'];
                        ?>
                            <tr class="<?= $s['total'] === 0 ? 'linha-sem-voto' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($s['numeroSecao']) ?></strong>
                                    <?php if ($s['total'] === 0): ?>
                                        <span class="tag">sem voto</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($s['nomeLocal'])): ?>
                                        <span class="local-nome"><?= htmlspecialchars($s['nomeLocal']) ?></span>
                                    <?php else: ?>
                                        <span class="local-vazio">local não cadastrado</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num">
                                    <?= $aptos !== null ? number_format($aptos, 0, ',', '.') : '—' ?>
                                </td>
                                <td class="num">
                                    <?= number_format((int)$s['total'], 0, ',', '.') ?>
                                </td>
                                <td class="num">
                                    <?= number_format($pctSec, 2, ',', '.') ?>%
                                </td>
                                <td class="num">
                                    <?= $comp !== null ? number_format($comp, 2, ',', '.') . '%' : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">Total da zona</td>
                            <td class="num"><?= $somaAptos > 0 ? number_format($somaAptos, 0, ',', '.') : '—' ?></td>
                            <td class="num"><?= number_format($somaVotos, 0, ',', '.') ?></td>
                            <td class="num">100,00%</td>
                            <td class="num">
                                <?= $somaAptos > 0
                                    ? number_format(($somaVotos / $somaAptos) * 100, 2, ',', '.') . '%'
                                    : '—' ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </details>
        <?php endforeach; ?>

    <?php endif; ?>

<?php endif; ?>

<!-- jQuery + Select2 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
    (function () {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') {
            return;
        }

        // Matcher sem acento e case-insensitive
        function removerAcentos(s) {
            return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        function matcherSemAcento(params, data) {
            if (jQuery.trim(params.term) === '') return data;

            const termo = removerAcentos(params.term.toLowerCase());
            const texto = removerAcentos((data.text || '').toLowerCase());

            if (texto.indexOf(termo) > -1) return data;

            if (data.children && data.children.length) {
                const filhos = [];
                data.children.forEach(function (child) {
                    if (removerAcentos((child.text || '').toLowerCase()).indexOf(termo) > -1) {
                        filhos.push(child);
                    }
                });
                if (filhos.length) {
                    const copia = jQuery.extend({}, data);
                    copia.children = filhos;
                    return copia;
                }
            }
            return null;
        }

        // ---- Local (single) ----------------------------------------
        const $local = jQuery('#local');
        if ($local.length && !$local.prop('disabled')) {
            $local.select2({
                language: 'pt-BR',
                placeholder: 'Digite para buscar um local...',
                allowClear: true,
                width: 'resolve',
                minimumInputLength: 0,
                matcher: matcherSemAcento
            });
            $local.on('select2:select', function () {
                document.getElementById('form-filtros').submit();
            });
            $local.on('select2:clear', function () {
                document.getElementById('form-filtros').submit();
            });
        }

        // ---- Seções (multiple) ------------------------------------
        const $secoes = jQuery('#secoes');
        if ($secoes.length && !$secoes.prop('disabled')) {
            $secoes.select2({
                language: 'pt-BR',
                placeholder: 'Todas as seções',
                allowClear: true,
                width: 'resolve',
                closeOnSelect: false,
                minimumInputLength: 0,
                matcher: matcherSemAcento
            });
            // Não submete automaticamente em multi-seleção;
            // o usuário clica em "Consultar" quando terminar.
        }
    })();
</script>

</body>
</html>