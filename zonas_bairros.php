<?php
// ------------------------------------------------------------
// zonas_bairros.php
// Lista votos por BAIRRO, com agrupamento opcional por REGIÃO.
// Bairros vêm de secoes_enderecos; região vem da tabela bairros
// (que aponta para regioes_bairro). Inclui KPI e filtro por
// tipo_voto (Opinião / Estrutura) da tabela bairros.
//
// IMPORTANTE: nenhuma query pesada é executada enquanto o filtro
// mínimo (município) não for definido. Isso evita travar a página
// processando todos os registros na primeira entrada.
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

function corRegiao($nome) {
    $map = [
        'Zona Norte' => '#4363d8',
        'Zona Sul'   => '#f58231',
        'Centro'     => '#800000',
        'Zona Oeste' => '#3cb44b',
    ];
    return $map[$nome] ?? '#6c757d';
}

function corTipoVoto($tipo) {
    $map = [
        'Opinião'   => '#6f42c1', // roxo
        'Estrutura' => '#20c997', // teal
    ];
    return $map[$tipo] ?? '#6c757d';
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
// DETECÇÃO DE ESTRUTURAS
// ============================================================
$colsSec   = [];
$temSecEnd = false;
try {
    foreach ($pdo->query("SHOW COLUMNS FROM secoes_enderecos") as $c) {
        $colsSec[] = $c['Field'];
    }
    $temSecEnd = true;
} catch (Exception $e) {
    $temSecEnd = false;
}

$COL_BAIRRO = null;
if ($temSecEnd) {
    foreach (['bairro', 'nomeBairro', 'bairroSecao', 'nmBairro'] as $cand) {
        if (in_array($cand, $colsSec, true)) { $COL_BAIRRO = $cand; break; }
    }
}

// Tabelas de região
$temBairros = false;
$temRegioes = false;
try { $pdo->query("SELECT 1 FROM bairros LIMIT 1");         $temBairros = true; } catch (Exception $e) {}
try { $pdo->query("SELECT 1 FROM regioes_bairro LIMIT 1");  $temRegioes = true; } catch (Exception $e) {}

$temRegiao = ($temBairros && $temRegioes);

// Detecta se a coluna tipo_voto existe em bairros
$temTipoVoto = false;
if ($temBairros) {
    try {
        $colsB = [];
        foreach ($pdo->query("SHOW COLUMNS FROM bairros") as $c) $colsB[] = $c['Field'];
        $temTipoVoto = in_array('tipo_voto', $colsB, true);
    } catch (Exception $e) { $temTipoVoto = false; }
}

$TIPOS_VOTO_VALIDOS = ['Opinião', 'Estrutura'];

// ============================================================
// COLLATION SEGURA
// ============================================================
$COLL = 'utf8mb4_general_ci';

// ============================================================
// FILTROS RECEBIDOS
// ============================================================
$ano       = $_GET['ano']       ?? '';
$candidato = $_GET['candidato'] ?? '';
$municipio = $_GET['municipio'] ?? '';
$bairro    = $_GET['bairro']    ?? '';
$zona      = $_GET['zona']      ?? '';
$regiao    = $_GET['regiao']    ?? '';
$tipoVoto  = $_GET['tipo_voto'] ?? '';
$agrupar   = $_GET['agrupar']   ?? '';
$ordem     = $_GET['ordem']     ?? 'votos';

if (!in_array($ordem, ['votos','secoes','bairro','municipio','regiao','tipo_voto'], true)) {
    $ordem = 'votos';
}
if (!in_array($agrupar, ['', 'regiao'], true)) {
    $agrupar = '';
}
if ($tipoVoto !== '' && !in_array($tipoVoto, $TIPOS_VOTO_VALIDOS, true)) {
    $tipoVoto = '';
}

// ============================================================
// GATE
// ============================================================
$filtroDefinido = ($municipio !== '');

// ============================================================
// JOIN + SELECT de região
// ============================================================
$joinRegiao   = '';
$selectRegiao = [];

if ($temRegiao) {
    // A subquery também expõe b_tipo_voto (se a coluna existir)
    $selTipoSub = $temTipoVoto
        ? ", MIN(b.tipo_voto COLLATE $COLL) AS b_tipo_voto"
        : ", NULL AS b_tipo_voto";

    $joinRegiao = "
        LEFT JOIN (
            SELECT b.nome      COLLATE $COLL AS b_nome,
                   b.municipio COLLATE $COLL AS b_municipio,
                   MIN(rb.id)                AS rb_id,
                   MIN(rb.nome COLLATE $COLL) AS rb_nome
                   $selTipoSub
            FROM bairros b
            INNER JOIN regioes_bairro rb ON rb.id = b.regiao_bairro_id
            GROUP BY b.nome, b.municipio
        ) br
               ON UPPER(TRIM(br.b_nome))      COLLATE $COLL
                = UPPER(TRIM(s.`$COL_BAIRRO`)) COLLATE $COLL
              AND UPPER(TRIM(br.b_municipio)) COLLATE $COLL
                = UPPER(TRIM(v.nomeMunicipio)) COLLATE $COLL
    ";
    $selectRegiao[] = "br.rb_nome AS regiao";
    $selectRegiao[] = "br.rb_id AS regiao_ordem";
    $selectRegiao[] = $temTipoVoto
        ? "br.b_tipo_voto AS tipo_voto"
        : "NULL AS tipo_voto";
} else {
    $selectRegiao[] = "NULL AS regiao";
    $selectRegiao[] = "NULL AS regiao_ordem";
    $selectRegiao[] = "NULL AS tipo_voto";
}

// ============================================================
// WHERE / PARAMS
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
    $filtros[] = "v.nomeMunicipio = :municipio";
    $params[':municipio'] = $municipio;
}
if ($bairro !== '' && $COL_BAIRRO !== null) {
    $filtros[] = "s.`$COL_BAIRRO` = :bairro";
    $params[':bairro'] = $bairro;
}
if ($zona !== '') {
    $filtros[] = "v.numeroZona = :zona";
    $params[':zona'] = (int)$zona;
}
if ($temRegiao && $regiao !== '') {
    if ($regiao === '__sem__') {
        $filtros[] = "br.rb_id IS NULL";
    } else {
        $filtros[] = "br.rb_nome = :regiao";
        $params[':regiao'] = $regiao;
    }
}
if ($temTipoVoto && $tipoVoto !== '') {
    $filtros[] = "br.b_tipo_voto = :tipo_voto";
    $params[':tipo_voto'] = $tipoVoto;
}

$where = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

// ============================================================
// ORDER BY
// ============================================================
$orderByParts = [];
if ($agrupar === 'regiao' && $temRegiao) {
    $orderByParts[] = "COALESCE(br.rb_id, 99999) ASC";
    $orderByParts[] = "total_votos DESC";
    $orderByParts[] = "bairro ASC";
} else {
    switch ($ordem) {
        case 'secoes':
            $orderByParts[] = "qtd_secoes DESC";
            $orderByParts[] = "total_votos DESC";
            $orderByParts[] = "bairro ASC";
            break;
        case 'bairro':
            $orderByParts[] = "bairro ASC";
            break;
        case 'municipio':
            $orderByParts[] = "v.nomeMunicipio ASC";
            $orderByParts[] = "bairro ASC";
            break;
        case 'tipo_voto':
            if ($temTipoVoto) {
                $orderByParts[] = "br.b_tipo_voto ASC";
                $orderByParts[] = "total_votos DESC";
            } else {
                $orderByParts[] = "total_votos DESC";
            }
            $orderByParts[] = "bairro ASC";
            break;
        case 'regiao':
            if ($temRegiao) {
                $orderByParts[] = "COALESCE(br.rb_id, 99999) ASC";
                $orderByParts[] = "bairro ASC";
            } else {
                $orderByParts[] = "bairro ASC";
            }
            break;
        case 'votos':
        default:
            $orderByParts[] = "total_votos DESC";
            $orderByParts[] = "bairro ASC";
            break;
    }
}
$orderBy = 'ORDER BY ' . implode(', ', $orderByParts);

// ============================================================
// QUERY PRINCIPAL
// ============================================================
$linhas = [];
if ($filtroDefinido && $COL_BAIRRO !== null) {

    $selectCampos = array_merge(
        [
            "s.`$COL_BAIRRO` AS bairro",
            "v.nomeMunicipio AS municipio",
        ],
        $selectRegiao,
        [
            "GROUP_CONCAT(DISTINCT LPAD(v.numeroZona, 3, '0')
                          ORDER BY v.numeroZona SEPARATOR ', ') AS zonas",
            "COUNT(DISTINCT CONCAT(v.numeroZona, '|', v.numeroSecao)) AS qtd_secoes",
            "SUM(v.qtdVotos) AS total_votos",
        ]
    );

    $groupByParts = [
        "s.`$COL_BAIRRO`",
        "v.nomeMunicipio",
    ];
    if ($temRegiao) {
        $groupByParts[] = "br.rb_nome";
        $groupByParts[] = "br.rb_id";
        if ($temTipoVoto) {
            $groupByParts[] = "br.b_tipo_voto";
        }
    }

    $sql = "
        SELECT
            " . implode(",\n            ", $selectCampos) . "
        FROM votos v
        INNER JOIN secoes_enderecos s
                ON s.numeroZona  = v.numeroZona
               AND s.numeroSecao = v.numeroSecao
        $joinRegiao
        $where
          " . ($where !== '' ? 'AND' : 'WHERE') . "
              s.`$COL_BAIRRO` IS NOT NULL
          AND s.`$COL_BAIRRO` <> ''
        GROUP BY " . implode(", ", $groupByParts) . "
        $orderBy
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();
}

// ============================================================
// AGRUPAMENTO POR REGIÃO + KPIs por região
// ============================================================
$porRegiao  = [];
$kpisRegiao = [];

if ($filtroDefinido && $linhas) {
    foreach ($linhas as $l) {
        $r = $l['regiao'] ?: 'Sem região';
        if (!isset($porRegiao[$r])) {
            $porRegiao[$r] = [
                'bairros' => [],
                'votos'   => 0,
                'secoes'  => 0,
                'cor'     => ($r === 'Sem região') ? '#adb5bd' : corRegiao($r),
                'ordem'   => (int)($l['regiao_ordem'] ?? 99999),
            ];
        }
        $porRegiao[$r]['bairros'][] = $l;
        $porRegiao[$r]['votos']  += (int)$l['total_votos'];
        $porRegiao[$r]['secoes'] += (int)$l['qtd_secoes'];

        if (!isset($kpisRegiao[$r])) {
            $kpisRegiao[$r] = [
                'regiao'  => $r,
                'cor'     => ($r === 'Sem região') ? '#adb5bd' : corRegiao($r),
                'ordem'   => (int)($l['regiao_ordem'] ?? 99999),
                'bairros' => 0,
                'secoes'  => 0,
                'votos'   => 0,
                'maxVoto' => 0,
            ];
        }
        $kpisRegiao[$r]['bairros']++;
        $kpisRegiao[$r]['secoes'] += (int)$l['qtd_secoes'];
        $kpisRegiao[$r]['votos']  += (int)$l['total_votos'];
        if ((int)$l['total_votos'] > $kpisRegiao[$r]['maxVoto']) {
            $kpisRegiao[$r]['maxVoto'] = (int)$l['total_votos'];
        }
    }

    uasort($porRegiao,   fn($a, $b) => $a['ordem'] <=> $b['ordem']);
    uasort($kpisRegiao,  fn($a, $b) => $a['ordem'] <=> $b['ordem']);
}

// ============================================================
// KPIs por TIPO DE VOTO
// ============================================================
$kpisTipoVoto = [];

if ($temTipoVoto && $filtroDefinido && $linhas) {
    foreach ($linhas as $l) {
        $tp = $l['tipo_voto'] ?: '— sem —';
        if (!isset($kpisTipoVoto[$tp])) {
            $kpisTipoVoto[$tp] = [
                'tipo'    => $tp,
                'cor'     => ($tp === '— sem —') ? '#adb5bd' : corTipoVoto($tp),
                'bairros' => 0,
                'secoes'  => 0,
                'votos'   => 0,
                'maxVoto' => 0,
            ];
        }
        $kpisTipoVoto[$tp]['bairros']++;
        $kpisTipoVoto[$tp]['secoes'] += (int)$l['qtd_secoes'];
        $kpisTipoVoto[$tp]['votos']  += (int)$l['total_votos'];
        if ((int)$l['total_votos'] > $kpisTipoVoto[$tp]['maxVoto']) {
            $kpisTipoVoto[$tp]['maxVoto'] = (int)$l['total_votos'];
        }
    }

    // Ordena seguindo a ordem de $TIPOS_VOTO_VALIDOS
    $ordemTipo = array_flip($TIPOS_VOTO_VALIDOS);
    uksort($kpisTipoVoto, function($a, $b) use ($ordemTipo) {
        $oa = $ordemTipo[$a] ?? 999;
        $ob = $ordemTipo[$b] ?? 999;
        return $oa <=> $ob;
    });
}

// ============================================================
// TOTAIS / KPIs GLOBAIS
// ============================================================
$totalBairros = count($linhas);
$totalSecoes  = 0;
$totalVotos   = 0;
$maxVotos     = 0;
$municipiosSet = [];
$regioesSet    = [];

foreach ($linhas as $l) {
    $totalSecoes += (int)$l['qtd_secoes'];
    $totalVotos  += (int)$l['total_votos'];
    if ((int)$l['total_votos'] > $maxVotos) $maxVotos = (int)$l['total_votos'];
    if ($l['municipio'] !== '') $municipiosSet[$l['municipio']] = true;
    if (!empty($l['regiao']))   $regioesSet[$l['regiao']] = true;
}
$qtdMunicipios = count($municipiosSet);
$qtdRegioes    = count($regioesSet);

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

$bairrosLista = [];
if ($filtroDefinido && $COL_BAIRRO !== null) {
    $sqlB = "SELECT DISTINCT `$COL_BAIRRO` FROM secoes_enderecos
             WHERE `$COL_BAIRRO` IS NOT NULL AND `$COL_BAIRRO` <> ''
               AND nomeMunicipio = :mun
             ORDER BY `$COL_BAIRRO`";
    $stmtB = $pdo->prepare($sqlB);
    $stmtB->execute([':mun' => $municipio]);
    $bairrosLista = $stmtB->fetchAll(PDO::FETCH_COLUMN);
}

$zonasLista = [];
if ($filtroDefinido) {
    $stmtZ = $pdo->prepare(
        "SELECT DISTINCT numeroZona FROM votos
          WHERE nomeMunicipio = :mun
          ORDER BY numeroZona"
    );
    $stmtZ->execute([':mun' => $municipio]);
    $zonasLista = $stmtZ->fetchAll(PDO::FETCH_COLUMN);
}

$regioesLista = [];
if ($temRegiao) {
    $regioesLista = $pdo->query(
        "SELECT nome FROM regioes_bairro ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Votos por Bairro / Região</title>

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
    .filtros select, .filtros button {
        padding:6px 8px; border-radius:4px; border:1px solid #ccc; font-size:14px;
        font-family: inherit;
    }
    .filtros .check-agrupar {
        display:inline-flex; align-items:center; gap:6px;
        background:#f4f8ff; padding:6px 12px; border-radius:6px;
        border:1px solid #d7e6fa; cursor:pointer;
        font-size:13px; font-weight:bold; color:#0069d9;
    }
    .filtros .check-agrupar input { margin:0; }
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
    #candidato + .select2-container   { min-width: 240px; max-width: 340px; }
    #municipio + .select2-container   { min-width: 200px; max-width: 300px; }
    #bairro + .select2-container      { min-width: 200px; max-width: 300px; }
    #zona + .select2-container        { min-width: 120px; }
    #regiao + .select2-container      { min-width: 170px; }
    #tipo_voto + .select2-container   { min-width: 150px; }

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

    /* KPIs por Região */
    .regioes-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 16px;
        margin-bottom: 26px;
    }
    .regiao-card {
        background:#fff; border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        overflow: hidden;
        display: flex; flex-direction: column;
        transition: transform .15s ease, box-shadow .15s ease;
    }
    .regiao-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,.12);
    }
    .regiao-card .cabecalho {
        padding: 10px 16px;
        color: #fff;
        display: flex; align-items: center; justify-content: space-between;
        font-weight: bold; font-size: 14px;
        text-transform: uppercase; letter-spacing: .5px;
    }
    .regiao-card .cabecalho .qtd-bairros {
        font-size: 11px; font-weight: normal;
        background: rgba(255,255,255,.22);
        padding: 2px 8px; border-radius: 10px;
    }
    .regiao-card .corpo {
        padding: 14px 16px;
        display: grid; grid-template-columns: 1fr 1fr;
        gap: 8px 12px;
    }
    .regiao-card .corpo .linha {
        display: flex; flex-direction: column;
    }
    .regiao-card .corpo .linha .rotulo {
        font-size: 10.5px; color: #888;
        text-transform: uppercase; letter-spacing: .5px;
        margin-bottom: 2px;
    }
    .regiao-card .corpo .linha .valor {
        font-size: 18px; font-weight: bold; color: #333;
    }
    .regiao-card .barra-participacao {
        height: 5px; background: #f0f2f5; width: 100%;
        margin-top: 4px; border-radius: 3px; overflow: hidden;
    }
    .regiao-card .barra-participacao > span {
        display: block; height: 100%;
        transition: width .3s ease;
    }
    .regiao-card .rodape-pct {
        padding: 0 16px 14px;
        font-size: 12px; color: #666;
        display: flex; justify-content: space-between; align-items: center;
    }
    .regiao-card .rodape-pct b { color: #333; }

    /* ============================================================
       KPIs por Tipo de Voto (reaproveita o estilo dos regiao-card)
       ============================================================ */
    .tipos-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
        margin-bottom: 26px;
    }
    .tipo-card {
        background:#fff; border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        overflow: hidden;
        display: flex; flex-direction: column;
        transition: transform .15s ease, box-shadow .15s ease;
    }
    .tipo-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,.12);
    }
    .tipo-card .cabecalho {
        padding: 10px 16px;
        color: #fff;
        display: flex; align-items: center; justify-content: space-between;
        font-weight: bold; font-size: 14px;
        letter-spacing: .5px;
    }
    .tipo-card .cabecalho .qtd-bairros {
        font-size: 11px; font-weight: normal;
        background: rgba(255,255,255,.22);
        padding: 2px 8px; border-radius: 10px;
    }
    .tipo-card .corpo {
        padding: 14px 16px;
        display: grid; grid-template-columns: 1fr 1fr;
        gap: 8px 12px;
    }
    .tipo-card .corpo .linha {
        display: flex; flex-direction: column;
    }
    .tipo-card .corpo .linha .rotulo {
        font-size: 10.5px; color: #888;
        text-transform: uppercase; letter-spacing: .5px;
        margin-bottom: 2px;
    }
    .tipo-card .corpo .linha .valor {
        font-size: 18px; font-weight: bold; color: #333;
    }
    .tipo-card .barra-participacao {
        height: 5px; background: #f0f2f5; width: 100%;
        margin-top: 4px; border-radius: 3px; overflow: hidden;
    }
    .tipo-card .barra-participacao > span {
        display: block; height: 100%;
        transition: width .3s ease;
    }
    .tipo-card .rodape-pct {
        padding: 0 16px 14px;
        font-size: 12px; color: #666;
        display: flex; justify-content: space-between; align-items: center;
    }
    .tipo-card .rodape-pct b { color: #333; }

    /* Barra-resumo única de tipos de voto (opcional) */
    .tipos-resumo {
        background:#fff; border-radius: 10px;
        padding: 14px 20px 12px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        margin-bottom: 26px;
        border-left: 5px solid #6c757d;
    }
    .tipos-resumo h3 {
        margin: 0 0 8px 0;
        font-size: 13px; color:#555;
        text-transform: uppercase; letter-spacing: .5px;
        font-weight: bold;
    }
    .tipos-resumo .barra-tipos {
        display: flex; height: 22px; border-radius: 6px; overflow: hidden;
        box-shadow: inset 0 1px 2px rgba(0,0,0,.08);
        background: #f0f2f5;
        margin: 6px 0 10px;
    }
    .tipos-resumo .barra-tipos .seg {
        display: block; height: 100%;
        transition: width .4s ease;
    }
    .tipos-resumo .barra-tipos .seg + .seg { border-left: 2px solid #fff; }
    .tipos-resumo .legenda-tipos {
        display: flex; flex-wrap: wrap; gap: 16px;
    }
    .tipos-resumo .legenda-tipos .item {
        display: inline-flex; align-items: center; gap: 8px;
        font-size: 13px;
    }
    .tipos-resumo .legenda-tipos .bolinha {
        width: 10px; height: 10px; border-radius: 50%;
    }
    .tipos-resumo .legenda-tipos .nome { color:#333; }
    .tipos-resumo .legenda-tipos .qtd  { font-weight: bold; }
    .tipos-resumo .legenda-tipos .pct  { color:#888; font-size: 11.5px; }

    /* Tabela */
    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; }
    th { background:#0069d9; color:#fff; font-size:13px; }
    tr:nth-child(even) { background:#f2f2f2; }
    tfoot td { font-weight:bold; background:#e6f0fa; }
    .num { text-align:right; }
    .barra {
        background:#e6f0fa; border-radius:4px; height:12px; overflow:hidden;
        display:inline-block; width:110px; vertical-align:middle; margin-left:6px;
    }
    .barra > span { display:block; height:100%; background:#0069d9; }

    .bairro-tag {
        display:inline-block; background:#f58231; color:#fff;
        padding:3px 12px; border-radius:12px; font-weight:bold; font-size:13px;
    }
    .municipio-tag {
        display:inline-block; background:#f0f4f8; color:#333;
        padding:2px 8px; border-radius:6px; font-size:12px;
        border:1px solid #e0e6ec;
    }
    .zona-mini {
        display:inline-block; background:#e6f0fa; color:#0069d9;
        padding:1px 7px; border-radius:8px; font-size:11px;
        font-family: monospace; font-weight:bold;
        margin: 1px 2px 1px 0;
        text-decoration: none;
    }
    .zona-mini.atual { background:#0069d9; color:#fff; }
    .zona-mini:hover { background:#cddff5; }
    .zona-mini.atual:hover { background:#0053b3; }

    .regiao-tag {
        display:inline-block; color:#fff;
        padding:3px 12px; border-radius:12px;
        font-weight:bold; font-size:11.5px;
        letter-spacing:.3px; text-transform:uppercase;
    }
    .regiao-tag.regiao-sem {
        background:#e9ecef !important; color:#6c757d;
        font-style:italic; text-transform:none;
    }

    .tipo-tag {
        display:inline-block; color:#fff;
        padding:3px 12px; border-radius:12px;
        font-weight:bold; font-size:11.5px;
        letter-spacing:.3px;
    }
    .tipo-tag.tipo-vazio {
        background:#e9ecef !important; color:#6c757d;
        font-style: italic; text-transform:none;
    }

    /* Cabeçalho de região (modo agrupado) */
    .regiao-bloco { margin-bottom: 30px; }
    .regiao-header {
        display:flex; align-items:center; gap:14px; flex-wrap:wrap;
        background:#fff; padding:12px 18px;
        border-radius:8px 8px 0 0;
        border-left:8px solid #6c757d;
        box-shadow:0 1px 3px rgba(0,0,0,.08);
        margin-bottom:0;
    }
    .regiao-header .nome {
        font-size:18px; font-weight:bold; color:#333;
        text-transform:uppercase; letter-spacing:.5px;
    }
    .regiao-header .resumo {
        font-size:13px; color:#666; margin-left:auto;
        display:flex; gap:18px; flex-wrap:wrap;
    }
    .regiao-header .resumo b { color:#333; }
    .regiao-header + table {
        border-radius:0 0 8px 8px; overflow:hidden;
        box-shadow:0 1px 3px rgba(0,0,0,.08);
        margin-top:0;
    }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso code { background:#fff; padding:1px 5px; border-radius:3px;
                  font-size:12px; border:1px solid #eee; }
    .aviso-erro { background:#f8d7da; border-left-color:#dc3545; color:#721c24; }
    .aviso-info { background:#e7f3ff; border-left-color:#0069d9; color:#004085; }

    /* Placeholder inicial */
    .msg-sem-filtro {
        background:#fff; border-left:5px solid #0069d9;
        padding:32px 36px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.08);
        text-align:center; margin-bottom: 24px;
    }
    .msg-sem-filtro .icone { font-size:52px; line-height:1; margin-bottom:14px; }
    .msg-sem-filtro h2 { margin:0 0 10px; font-size:20px; color:#0069d9; }
    .msg-sem-filtro p { margin:0 0 6px; font-size:14px; color:#666; line-height:1.6; }
    .msg-sem-filtro .passos {
        margin: 18px auto 0; max-width: 560px; text-align: left;
        background:#f4f8ff; border-radius:8px; padding:14px 20px;
        font-size:13.5px; color:#333; line-height:1.8;
    }
    .msg-sem-filtro .passos b { color:#0069d9; }
    .msg-sem-filtro .dica {
        margin-top:16px; font-size:13px;
        background:#e7f3ff; color:#004085;
        padding:8px 14px; border-radius:6px; display:inline-block;
    }

    .badges-filtros { margin-bottom:14px; }
    .badge-filtro {
        background:#e6f0fa; color:#0069d9; padding:4px 12px;
        border-radius:12px; font-size:12px; font-weight:bold;
        display:inline-flex; align-items:center; gap:6px;
        margin-right:6px; margin-bottom:6px;
    }
    .badge-filtro.regiao-badge { background:#ede8fb; color:#4a1d96; }
    .badge-filtro.tipo-badge   { background:#e8f8f1; color:#0f5132; }
    .badge-filtro .x {
        cursor:pointer; color:inherit; text-decoration:none;
        font-size:14px; line-height:1; font-weight:bold;
    }
    .badge-filtro .x:hover { color:#dc3545; }

    .top-bairros {
        display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px;
    }
    .top-card {
        background:#fff; border-radius:8px; padding:10px 14px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:170px;
        border-left:4px solid #f58231;
    }
    .top-card .pos  { font-size:11px; color:#888; font-weight:bold; }
    .top-card .nome { font-size:14px; font-weight:bold; color:#333; margin:2px 0; }
    .top-card .votos{ font-size:13px; color:#f58231; font-weight:bold; }
    .top-card .reg  { font-size:10px; color:#fff; padding:1px 6px;
                      border-radius:6px; display:inline-block; margin-bottom:2px; }
</style>
</head>
<body>

<a href="consultar.php" class="link-voltar">&larr; Voltar para a consulta</a>

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

<h1>Votos por Bairro / Região</h1>
<div style="color:#666;font-size:13px;margin-bottom:18px;">
    Lista os bairros com a quantidade de seções e o total de votos, agrupados
    por <strong>região geográfica</strong> (Zona Norte, Zona Sul, Centro, Zona Oeste)
    e com KPI por <strong>tipo de voto</strong> (Opinião / Estrutura).
    As regiões e tipos vêm da tabela <code>bairros</code> (ligada a <code>regioes_bairro</code>).
    <br><strong>Selecione um município e clique em "Consultar" para ver os dados.</strong>
</div>

<?php if ($COL_BAIRRO === null): ?>

    <div class="aviso aviso-erro">
        <strong>Não foi possível identificar a coluna de bairro</strong> na tabela
        <code>secoes_enderecos</code>.
        <?php if (!$temSecEnd): ?>
            <br>A tabela <code>secoes_enderecos</code> não existe no banco.
        <?php else: ?>
            <br>Colunas encontradas: <code><?= htmlspecialchars(implode(', ', $colsSec)) ?></code>
        <?php endif; ?>
    </div>

<?php else: ?>

    <?php if (!$temRegiao): ?>
        <div class="aviso aviso-info">
            ⚠️ As tabelas <code>bairros</code> e/ou <code>regioes_bairro</code> não foram
            encontradas. A coluna/filtro de <strong>Região</strong> ficará desabilitada.
        </div>
    <?php endif; ?>

    <?php if ($temBairros && !$temTipoVoto): ?>
        <div class="aviso aviso-info">
            ℹ️ A coluna <code>tipo_voto</code> não existe em <code>bairros</code>.
            O filtro e o KPI de <strong>tipo de voto</strong> ficarão ocultados.
            Para habilitar:<br>
            <code>ALTER TABLE bairros ADD COLUMN tipo_voto ENUM('Opinião','Estrutura') NOT NULL DEFAULT 'Estrutura' AFTER regiao_bairro_id;</code>
        </div>
    <?php endif; ?>

<form method="get" action="zonas_bairros.php" id="form-filtros">
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

        <label for="candidato">Candidato:</label>
        <select name="candidato" id="candidato" style="min-width:240px;">
            <option value="">Todos</option>
            <?php foreach ($candidatosLista as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= $c === $candidato ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="municipio">Município: <span style="color:#dc3545;">*</span></label>
        <select name="municipio" id="municipio" style="min-width:200px;">
            <option value="">— Selecione —</option>
            <?php foreach ($municipiosLista as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>"
                    <?= $m === $municipio ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($temRegiao): ?>
        <label for="regiao">Região:</label>
        <select name="regiao" id="regiao" style="min-width:170px;">
            <option value="">Todas</option>
            <?php foreach ($regioesLista as $r): ?>
                <option value="<?= htmlspecialchars($r) ?>"
                    <?= $r === $regiao ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r) ?>
                </option>
            <?php endforeach; ?>
            <option value="__sem__" <?= $regiao === '__sem__' ? 'selected' : '' ?>>
                — Sem região —
            </option>
        </select>
        <?php endif; ?>

        <?php if ($temTipoVoto): ?>
        <label for="tipo_voto">Tipo de voto:</label>
        <select name="tipo_voto" id="tipo_voto" style="min-width:150px;">
            <option value="">Todos</option>
            <?php foreach ($TIPOS_VOTO_VALIDOS as $tp): ?>
                <option value="<?= htmlspecialchars($tp) ?>"
                    <?= $tp === $tipoVoto ? 'selected' : '' ?>>
                    <?= htmlspecialchars($tp) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <label for="bairro">Bairro:</label>
        <select name="bairro" id="bairro" style="min-width:200px;"
                <?= !$filtroDefinido ? 'disabled' : '' ?>>
            <option value=""><?= $filtroDefinido ? 'Todos' : '— Selecione município —' ?></option>
            <?php foreach ($bairrosLista as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>"
                    <?= $b === $bairro ? 'selected' : '' ?>>
                    <?= htmlspecialchars($b) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="zona">Zona:</label>
        <select name="zona" id="zona" style="min-width:120px;"
                <?= !$filtroDefinido ? 'disabled' : '' ?>>
            <option value=""><?= $filtroDefinido ? 'Todas' : '—' ?></option>
            <?php foreach ($zonasLista as $zn): ?>
                <option value="<?= (int)$zn ?>"
                    <?= ((string)$zn === (string)$zona) ? 'selected' : '' ?>>
                    <?= fmtZona3($zn) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="ordem">Ordenar por:</label>
        <select name="ordem" id="ordem">
            <?php
            $opcoesOrdem = [
                'votos'     => 'Mais votos',
                'secoes'    => 'Mais seções',
                'bairro'    => 'Bairro (A-Z)',
                'municipio' => 'Município',
            ];
            if ($temRegiao)   $opcoesOrdem['regiao']    = 'Região';
            if ($temTipoVoto) $opcoesOrdem['tipo_voto'] = 'Tipo de voto';
            foreach ($opcoesOrdem as $k => $rot):
            ?>
                <option value="<?= $k ?>" <?= $ordem === $k ? 'selected' : '' ?>>
                    <?= htmlspecialchars($rot) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($temRegiao): ?>
        <label class="check-agrupar" for="agrupar" title="Exibe os bairros agrupados por região">
            <input type="checkbox" name="agrupar" id="agrupar" value="regiao"
                <?= $agrupar === 'regiao' ? 'checked' : '' ?>>
            🗂️ Agrupar por região
        </label>
        <?php endif; ?>

        <button type="submit" class="btn-consultar">Consultar</button>
        <a href="zonas_bairros.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<?php
// Badges de filtros ativos
$temFiltro = ($ano !== '' || $candidato !== '' || $municipio !== ''
              || $bairro !== '' || $zona !== '' || $regiao !== ''
              || $tipoVoto !== '');

if ($temFiltro):
    $baseQ = ['ano' => $ano, 'candidato' => $candidato, 'municipio' => $municipio,
              'bairro' => $bairro, 'zona' => $zona, 'regiao' => $regiao,
              'tipo_voto' => $tipoVoto,
              'ordem' => $ordem, 'agrupar' => $agrupar];
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

    <?php if ($candidato !== ''): ?>
        <?php $q = $baseQ; $q['candidato'] = ''; ?>
        <span class="badge-filtro">
            🗳 <?= htmlspecialchars($candidato) ?>
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($municipio !== ''): ?>
        <?php $q = $baseQ; $q['municipio'] = ''; $q['bairro'] = ''; $q['regiao'] = ''; ?>
        <span class="badge-filtro">
            🏙️ <?= htmlspecialchars($municipio) ?>
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($regiao !== ''): ?>
        <?php $q = $baseQ; $q['regiao'] = ''; ?>
        <span class="badge-filtro regiao-badge">
            🌎 <?= $regiao === '__sem__' ? 'Sem região' : htmlspecialchars($regiao) ?>
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($tipoVoto !== ''): ?>
        <?php $q = $baseQ; $q['tipo_voto'] = ''; ?>
        <span class="badge-filtro tipo-badge">
            🏷️ <?= htmlspecialchars($tipoVoto) ?>
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($bairro !== ''): ?>
        <?php $q = $baseQ; $q['bairro'] = ''; ?>
        <span class="badge-filtro">
            🏘️ <?= htmlspecialchars($bairro) ?>
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($zona !== ''): ?>
        <?php $q = $baseQ; $q['zona'] = ''; ?>
        <span class="badge-filtro">
            📍 Zona <?= fmtZona3($zona) ?>
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($agrupar === 'regiao'): ?>
        <?php $q = $baseQ; $q['agrupar'] = ''; ?>
        <span class="badge-filtro regiao-badge">
            🗂️ Agrupado por região
            <a class="x" href="?<?= http_build_query($q) ?>">×</a>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$filtroDefinido): ?>

    <div class="msg-sem-filtro">
        <div class="icone">🏘️</div>
        <h2>Selecione um município para visualizar os bairros</h2>
        <p>
            Esta página processa os votos por bairro. Para evitar sobrecarga,
            os dados só são exibidos <b>após selecionar o município</b> e
            clicar em <b>Consultar</b>.
        </p>

        <div class="passos">
            <b>Como usar:</b><br>
            • 🏙️ Escolha um <b>município</b> (obrigatório)<br>
            • 📅 Opcional: filtre por <b>ano</b> e <b>candidato</b><br>
            • 🌎 Opcional: filtre por <b>região</b> (Zona Norte, Zona Sul, etc.)<br>
            • 🏷️ Opcional: filtre por <b>tipo de voto</b> (Opinião / Estrutura)<br>
            • 🏘️ Opcional: filtre por <b>bairro</b> ou <b>zona</b><br>
            • 🗂️ Marque <b>"Agrupar por região"</b> para ver os blocos por região<br>
            <br>
            Depois clique em <b>Consultar</b>.
        </div>

        <div class="dica">
            💡 A página só consulta o banco quando o município está selecionado —
            isso mantém a navegação rápida.
        </div>
    </div>

<?php elseif (!$linhas): ?>

    <div class="aviso">
        Nenhum bairro encontrado para os filtros selecionados.
        <br>Verifique se os campos <code>bairro</code> estão preenchidos em
        <code>secoes_enderecos</code> para o município selecionado e se
        os votos correspondem às seções cadastradas.
    </div>

<?php else: ?>

    <!-- KPIs GLOBAIS -->
    <div class="kpis">
        <div class="kpi laranja">
            <div class="rotulo">Bairros listados</div>
            <div class="valor"><?= number_format($totalBairros, 0, ',', '.') ?></div>
        </div>

        <?php if ($temRegiao): ?>
        <div class="kpi roxo">
            <div class="rotulo">Regiões</div>
            <div class="valor"><?= number_format($qtdRegioes, 0, ',', '.') ?></div>
            <div class="sub">com bairros nos filtros</div>
        </div>
        <?php endif; ?>

        <div class="kpi azul">
            <div class="rotulo">Municípios abrangidos</div>
            <div class="valor"><?= number_format($qtdMunicipios, 0, ',', '.') ?></div>
        </div>

        <div class="kpi laranja">
            <div class="rotulo">Total de seções</div>
            <div class="valor"><?= number_format($totalSecoes, 0, ',', '.') ?></div>
            <div class="sub">somatório dos bairros</div>
        </div>

        <div class="kpi verde">
            <div class="rotulo">Total de votos</div>
            <div class="valor"><?= number_format($totalVotos, 0, ',', '.') ?></div>
        </div>

        <div class="kpi cinza">
            <div class="rotulo">Média votos / bairro</div>
            <div class="valor">
                <?= number_format($totalBairros > 0 ? $totalVotos / $totalBairros : 0, 0, ',', '.') ?>
            </div>
        </div>

        <div class="kpi teal">
            <div class="rotulo">Maior bairro</div>
            <div class="valor"><?= number_format($maxVotos, 0, ',', '.') ?></div>
            <div class="sub">votos</div>
        </div>
    </div>

    <!-- Top 5 bairros -->
    <?php
    $top = $linhas;
    usort($top, fn($a, $b) => (int)$b['total_votos'] <=> (int)$a['total_votos']);
    $top = array_slice($top, 0, 5);
    if ($top):
    ?>
    <h2>🏆 Top <?= count($top) ?> bairros por votos</h2>
    <div class="top-bairros">
        <?php foreach ($top as $i => $t):
            $corReg = !empty($t['regiao']) ? corRegiao($t['regiao']) : null;
            $tpVoto = $temTipoVoto ? (string)($t['tipo_voto'] ?? '') : '';
        ?>
            <div class="top-card">
                <div class="pos"><?= $i + 1 ?>º lugar</div>
                <div class="nome"><?= htmlspecialchars($t['bairro']) ?></div>
                <div style="font-size:11px;color:#666;margin-bottom:4px;">
                    <?= htmlspecialchars($t['municipio']) ?>
                </div>
                <?php if ($corReg !== null): ?>
                    <div class="reg" style="background:<?= $corReg ?>;">
                        <?= htmlspecialchars($t['regiao']) ?>
                    </div>
                <?php endif; ?>
                <?php if ($tpVoto !== ''): ?>
                    <div class="reg" style="background:<?= corTipoVoto($tpVoto) ?>;">
                        <?= htmlspecialchars($tpVoto) ?>
                    </div>
                <?php endif; ?>
                <div class="votos"><?= number_format((int)$t['total_votos'], 0, ',', '.') ?> votos</div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         KPI por TIPO DE VOTO (barra-resumo única)
         ============================================================ -->
    <?php if ($temTipoVoto && $kpisTipoVoto):
        $totalTipoVotos = 0;
        foreach ($kpisTipoVoto as $k) $totalTipoVotos += $k['votos'];
    ?>
        <h2>🏷️ Distribuição por tipo de voto — <?= htmlspecialchars($municipio) ?></h2>

        <div class="tipos-resumo">
            <div class="barra-tipos">
                <?php foreach ($kpisTipoVoto as $tp => $info):
                    $pct = $totalTipoVotos > 0 ? ($info['votos'] / $totalTipoVotos) * 100 : 0;
                    if ($pct <= 0) continue;
                ?>
                    <span class="seg"
                          style="width: <?= number_format($pct, 3, '.', '') ?>%;
                                 background: <?= $info['cor'] ?>;"
                          title="<?= htmlspecialchars($tp) ?>: <?= number_format($info['votos'], 0, ',', '.') ?> votos (<?= number_format($pct, 1, ',', '.') ?>%)">
                    </span>
                <?php endforeach; ?>
            </div>

            <div class="legenda-tipos">
                <?php foreach ($kpisTipoVoto as $tp => $info):
                    $pct = $totalTipoVotos > 0 ? ($info['votos'] / $totalTipoVotos) * 100 : 0;
                ?>
                    <span class="item">
                        <span class="bolinha" style="background: <?= $info['cor'] ?>;"></span>
                        <span class="nome"><?= htmlspecialchars($tp) ?>:</span>
                        <span class="qtd" style="color: <?= $info['cor'] ?>;">
                            <?= number_format($info['votos'], 0, ',', '.') ?> votos
                        </span>
                        <span class="pct">(<?= number_format($pct, 1, ',', '.') ?>%)</span>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tipos-kpis">
            <?php foreach ($kpisTipoVoto as $tp => $info):
                $pctVotos   = $totalTipoVotos > 0 ? ($info['votos'] / $totalTipoVotos) * 100 : 0;
                $pctBairros = $totalBairros > 0 ? ($info['bairros'] / $totalBairros) * 100 : 0;
                $mediaBairro = $info['bairros'] > 0 ? $info['votos'] / $info['bairros'] : 0;
            ?>
                <div class="tipo-card">
                    <div class="cabecalho" style="background: <?= $info['cor'] ?>;">
                        <span><?= htmlspecialchars($tp) ?></span>
                        <span class="qtd-bairros">
                            <?= number_format($info['bairros'], 0, ',', '.') ?>
                            bairro<?= $info['bairros'] !== 1 ? 's' : '' ?>
                        </span>
                    </div>

                    <div class="corpo">
                        <div class="linha">
                            <span class="rotulo">Votos</span>
                            <span class="valor" style="color: <?= $info['cor'] ?>;">
                                <?= number_format($info['votos'], 0, ',', '.') ?>
                            </span>
                        </div>
                        <div class="linha">
                            <span class="rotulo">Seções</span>
                            <span class="valor">
                                <?= number_format($info['secoes'], 0, ',', '.') ?>
                            </span>
                        </div>
                        <div class="linha">
                            <span class="rotulo">Média / bairro</span>
                            <span class="valor" style="font-size:15px;">
                                <?= number_format($mediaBairro, 0, ',', '.') ?>
                            </span>
                        </div>
                        <div class="linha">
                            <span class="rotulo">Maior bairro</span>
                            <span class="valor" style="font-size:15px;">
                                <?= number_format($info['maxVoto'], 0, ',', '.') ?>
                            </span>
                        </div>
                    </div>

                    <div class="barra-participacao">
                        <span style="width: <?= number_format($pctVotos, 2, '.', '') ?>%; background: <?= $info['cor'] ?>;"></span>
                    </div>

                    <div class="rodape-pct">
                        <span>
                            <b><?= number_format($pctVotos, 2, ',', '.') ?>%</b>
                            dos votos
                        </span>
                        <span style="color:#999;">
                            <?= number_format($pctBairros, 1, ',', '.') ?>% dos bairros
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ============================================================
         KPIs por REGIÃO
         ============================================================ -->
    <?php if ($temRegiao && $kpisRegiao): ?>
        <h2>🌎 Resumo por região — <?= htmlspecialchars($municipio) ?></h2>
        <div class="regioes-kpis">
            <?php foreach ($kpisRegiao as $kr):
                $pctVotos = $totalVotos > 0 ? ($kr['votos'] / $totalVotos) * 100 : 0;
                $pctBairros = $totalBairros > 0 ? ($kr['bairros'] / $totalBairros) * 100 : 0;
                $mediaBairro = $kr['bairros'] > 0 ? $kr['votos'] / $kr['bairros'] : 0;
            ?>
                <div class="regiao-card">
                    <div class="cabecalho" style="background: <?= $kr['cor'] ?>;">
                        <span><?= htmlspecialchars($kr['regiao']) ?></span>
                        <span class="qtd-bairros">
                            <?= number_format($kr['bairros'], 0, ',', '.') ?>
                            bairro<?= $kr['bairros'] !== 1 ? 's' : '' ?>
                        </span>
                    </div>

                    <div class="corpo">
                        <div class="linha">
                            <span class="rotulo">Votos</span>
                            <span class="valor" style="color: <?= $kr['cor'] ?>;">
                                <?= number_format($kr['votos'], 0, ',', '.') ?>
                            </span>
                        </div>
                        <div class="linha">
                            <span class="rotulo">Seções</span>
                            <span class="valor">
                                <?= number_format($kr['secoes'], 0, ',', '.') ?>
                            </span>
                        </div>
                        <div class="linha">
                            <span class="rotulo">Média / bairro</span>
                            <span class="valor" style="font-size:15px;">
                                <?= number_format($mediaBairro, 0, ',', '.') ?>
                            </span>
                        </div>
                        <div class="linha">
                            <span class="rotulo">Maior bairro</span>
                            <span class="valor" style="font-size:15px;">
                                <?= number_format($kr['maxVoto'], 0, ',', '.') ?>
                            </span>
                        </div>
                    </div>

                    <div class="barra-participacao">
                        <span style="width: <?= number_format($pctVotos, 2, '.', '') ?>%; background: <?= $kr['cor'] ?>;"></span>
                    </div>

                    <div class="rodape-pct">
                        <span>
                            <b><?= number_format($pctVotos, 2, ',', '.') ?>%</b>
                            dos votos
                        </span>
                        <span style="color:#999;">
                            <?= number_format($pctBairros, 1, ',', '.') ?>% dos bairros
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    // ============================================================
    // RENDERIZAÇÃO — modo AGRUPADO POR REGIÃO
    // ============================================================
    if ($agrupar === 'regiao' && $temRegiao && $porRegiao):

        $maxVotosGlobal = 0;
        foreach ($linhas as $tmp) {
            if ((int)$tmp['total_votos'] > $maxVotosGlobal) {
                $maxVotosGlobal = (int)$tmp['total_votos'];
            }
        }
    ?>
        <h2>Detalhamento por região</h2>

        <?php foreach ($porRegiao as $nomeReg => $info):
            $cor = $info['cor'];
        ?>
            <div class="regiao-bloco">
                <div class="regiao-header" style="border-left-color: <?= $cor ?>;">
                    <span class="regiao-tag" style="background: <?= $cor ?>;">
                        <?= htmlspecialchars($nomeReg) ?>
                    </span>
                    <span class="resumo">
                        <span><b><?= number_format(count($info['bairros']), 0, ',', '.') ?></b> bairro(s)</span>
                        <span><b><?= number_format($info['secoes'], 0, ',', '.') ?></b> seções</span>
                        <span><b><?= number_format($info['votos'], 0, ',', '.') ?></b> votos</span>
                        <span><b><?= $totalVotos > 0 ? number_format(($info['votos'] / $totalVotos) * 100, 2, ',', '.') : '0,00' ?>%</b> do total</span>
                    </span>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>Bairro</th>
                            <th style="width:180px;">Município</th>
                            <?php if ($temTipoVoto): ?>
                                <th style="width:120px;">Tipo</th>
                            <?php endif; ?>
                            <th style="width:200px;">Zonas</th>
                            <th class="num" style="width:90px;">Seções</th>
                            <th class="num" style="width:110px;">Votos</th>
                            <th class="num" style="width:90px;">% do total</th>
                            <th style="width:130px;">Proporção</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $pos = 1; foreach ($info['bairros'] as $l):
                            $pct     = $totalVotos > 0 ? ($l['total_votos'] / $totalVotos) * 100 : 0;
                            $largura = $maxVotosGlobal > 0 ? ($l['total_votos'] / $maxVotosGlobal) * 100 : 0;

                            $listaZonas = array_values(array_filter(
                                array_map('trim', explode(',', (string)$l['zonas'])),
                                fn($x) => $x !== ''
                            ));

                            $tpVoto = $temTipoVoto ? (string)($l['tipo_voto'] ?? '') : '';
                            $corTp  = $tpVoto !== '' ? corTipoVoto($tpVoto) : null;

                            $qBase = [
                                'ano'       => $ano,
                                'candidato' => $candidato,
                                'municipio' => $municipio,
                                'bairro'    => $bairro,
                                'regiao'    => $regiao,
                                'tipo_voto' => $tipoVoto,
                                'ordem'     => $ordem,
                                'agrupar'   => $agrupar,
                            ];
                        ?>
                            <tr>
                                <td><?= $pos++ ?></td>
                                <td>
                                    <span class="bairro-tag"><?= htmlspecialchars($l['bairro']) ?></span>
                                </td>
                                <td>
                                    <span class="municipio-tag">
                                        <?= htmlspecialchars($l['municipio']) ?>
                                    </span>
                                </td>
                                <?php if ($temTipoVoto): ?>
                                    <td>
                                        <?php if ($corTp !== null): ?>
                                            <span class="tipo-tag" style="background: <?= $corTp ?>;">
                                                <?= htmlspecialchars($tpVoto) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="tipo-tag tipo-vazio">— sem —</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <?php foreach ($listaZonas as $zonaItem):
                                        $zNum = (int)$zonaItem;
                                        $qLink = $qBase;
                                        $qLink['zona'] = ((string)$zNum === (string)$zona) ? '' : (string)$zNum;
                                        $atual = ((string)$zNum === (string)$zona);
                                    ?>
                                        <a class="zona-mini <?= $atual ? 'atual' : '' ?>"
                                           href="?<?= htmlspecialchars(http_build_query($qLink)) ?>"
                                           title="<?= $atual ? 'Remover filtro desta zona' : 'Filtrar por zona ' . $zonaItem ?>">
                                            <?= htmlspecialchars($zonaItem) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </td>
                                <td class="num"><?= number_format((int)$l['qtd_secoes'], 0, ',', '.') ?></td>
                                <td class="num"><strong><?= number_format((int)$l['total_votos'], 0, ',', '.') ?></strong></td>
                                <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                                <td>
                                    <span class="barra">
                                        <span style="width: <?= number_format($largura, 2, '.', '') ?>%; background:<?= $cor ?>;"></span>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="<?= $temTipoVoto ? 5 : 4 ?>">Subtotal <?= htmlspecialchars($nomeReg) ?></td>
                            <td class="num"><?= number_format($info['secoes'], 0, ',', '.') ?></td>
                            <td class="num"><?= number_format($info['votos'], 0, ',', '.') ?></td>
                            <td class="num"><?= $totalVotos > 0 ? number_format(($info['votos'] / $totalVotos) * 100, 2, ',', '.') : '0,00' ?>%</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endforeach; ?>

        <!-- Total geral -->
        <table>
            <tfoot>
                <tr>
                    <td colspan="<?= $temTipoVoto ? 5 : 4 ?>" style="background:#0069d9;color:#fff;">
                        TOTAL GERAL (<?= number_format($totalBairros, 0, ',', '.') ?> bairro<?= $totalBairros !== 1 ? 's' : '' ?>
                        · <?= number_format($qtdRegioes, 0, ',', '.') ?> região<?= $qtdRegioes !== 1 ? 'ões' : '' ?>)
                    </td>
                    <td class="num" style="background:#0069d9;color:#fff;"><?= number_format($totalSecoes, 0, ',', '.') ?></td>
                    <td class="num" style="background:#0069d9;color:#fff;"><?= number_format($totalVotos, 0, ',', '.') ?></td>
                    <td class="num" style="background:#0069d9;color:#fff;">100,00%</td>
                    <td style="background:#0069d9;color:#fff;"></td>
                </tr>
            </tfoot>
        </table>

    <?php
    // ============================================================
    // RENDERIZAÇÃO — modo PLANO (tabela única)
    // ============================================================
    else: ?>

        <h2>Detalhamento por bairro</h2>
        <table>
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th>Bairro</th>
                    <?php if ($temRegiao): ?>
                        <th style="width:140px;">Região</th>
                    <?php endif; ?>
                    <?php if ($temTipoVoto): ?>
                        <th style="width:120px;">Tipo</th>
                    <?php endif; ?>
                    <th style="width:160px;">Município</th>
                    <th style="width:200px;">Zonas</th>
                    <th class="num" style="width:90px;">Seções</th>
                    <th class="num" style="width:110px;">Votos</th>
                    <th class="num" style="width:90px;">% do total</th>
                    <th style="width:130px;">Proporção</th>
                </tr>
            </thead>
            <tbody>
                <?php $pos = 1; foreach ($linhas as $l):
                    $pct     = $totalVotos > 0 ? ($l['total_votos'] / $totalVotos) * 100 : 0;
                    $largura = $maxVotos > 0 ? ($l['total_votos'] / $maxVotos) * 100 : 0;

                    $listaZonas = array_values(array_filter(
                        array_map('trim', explode(',', (string)$l['zonas'])),
                        fn($x) => $x !== ''
                    ));

                    $corReg = !empty($l['regiao']) ? corRegiao($l['regiao']) : null;
                    $tpVoto = $temTipoVoto ? (string)($l['tipo_voto'] ?? '') : '';
                    $corTp  = $tpVoto !== '' ? corTipoVoto($tpVoto) : null;

                    $qBase = [
                        'ano'       => $ano,
                        'candidato' => $candidato,
                        'municipio' => $municipio,
                        'bairro'    => $bairro,
                        'regiao'    => $regiao,
                        'tipo_voto' => $tipoVoto,
                        'ordem'     => $ordem,
                        'agrupar'   => $agrupar,
                    ];
                ?>
                    <tr>
                        <td><?= $pos++ ?></td>
                        <td>
                            <span class="bairro-tag"><?= htmlspecialchars($l['bairro']) ?></span>
                        </td>
                        <?php if ($temRegiao): ?>
                            <td>
                                <?php if ($corReg !== null): ?>
                                    <span class="regiao-tag" style="background: <?= $corReg ?>;">
                                        <?= htmlspecialchars($l['regiao']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="regiao-tag regiao-sem">sem região</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($temTipoVoto): ?>
                            <td>
                                <?php if ($corTp !== null): ?>
                                    <span class="tipo-tag" style="background: <?= $corTp ?>;">
                                        <?= htmlspecialchars($tpVoto) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="tipo-tag tipo-vazio">— sem —</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <span class="municipio-tag">
                                <?= htmlspecialchars($l['municipio']) ?>
                            </span>
                        </td>
                        <td>
                            <?php foreach ($listaZonas as $zonaItem):
                                $zNum = (int)$zonaItem;
                                $qLink = $qBase;
                                $qLink['zona'] = ((string)$zNum === (string)$zona) ? '' : (string)$zNum;
                                $atual = ((string)$zNum === (string)$zona);
                            ?>
                                <a class="zona-mini <?= $atual ? 'atual' : '' ?>"
                                   href="?<?= htmlspecialchars(http_build_query($qLink)) ?>"
                                   title="<?= $atual ? 'Remover filtro desta zona' : 'Filtrar por zona ' . $zonaItem ?>">
                                    <?= htmlspecialchars($zonaItem) ?>
                                </a>
                            <?php endforeach; ?>
                        </td>
                        <td class="num"><?= number_format((int)$l['qtd_secoes'], 0, ',', '.') ?></td>
                        <td class="num"><strong><?= number_format((int)$l['total_votos'], 0, ',', '.') ?></strong></td>
                        <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                        <td>
                            <span class="barra">
                                <span style="width: <?= number_format($largura, 2, '.', '') ?>%;<?= $corReg ? 'background:' . $corReg . ';' : '' ?>"></span>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <?php
                    $colspanTotal = 4;
                    if ($temRegiao)   $colspanTotal++;
                    if ($temTipoVoto) $colspanTotal++;
                    ?>
                    <td colspan="<?= $colspanTotal ?>">Total (<?= number_format($totalBairros, 0, ',', '.') ?> bairro<?= $totalBairros !== 1 ? 's' : '' ?>)</td>
                    <td class="num"><?= number_format($totalSecoes, 0, ',', '.') ?></td>
                    <td class="num"><?= number_format($totalVotos, 0, ',', '.') ?></td>
                    <td class="num">100,00%</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

    <?php endif; ?>

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

        function initSel($el, placeholder) {
            if (!$el || !$el.length) return;
            if ($el.prop('disabled')) return;
            $el.select2({
                language: 'pt-BR',
                placeholder: placeholder,
                allowClear: true,
                width: 'resolve',
                matcher: matcherSemAcento
            });
        }

        const $ano       = jQuery('#ano');
        const $candidato = jQuery('#candidato');
        const $municipio = jQuery('#municipio');
        const $regiao    = jQuery('#regiao');
        const $tipoVoto  = jQuery('#tipo_voto');
        const $bairro    = jQuery('#bairro');
        const $zona      = jQuery('#zona');
        const $ordem     = jQuery('#ordem');

        initSel($ano,       'Todos os anos');
        initSel($candidato, 'Buscar candidato...');
        initSel($municipio, 'Buscar município...');
        initSel($regiao,    'Buscar região...');
        initSel($tipoVoto,  'Buscar tipo...');
        initSel($bairro,    'Buscar bairro...');
        initSel($zona,      'Todas as zonas');
        initSel($ordem,     'Ordenar por...');

        if ($ano.length) {
            $ano.on('select2:select select2:clear', function () {
                if ($candidato.length) {
                    $candidato.val('').trigger('change.select2');
                }
            });
        }

        if ($municipio.length) {
            $municipio.on('select2:select select2:clear', function () {
                if ($bairro.length) $bairro.val('').trigger('change.select2');
                if ($zona.length)   $zona.val('').trigger('change.select2');
            });
        }
    })();
</script>

</body>
</html>