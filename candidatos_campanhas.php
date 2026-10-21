<?php
// candidatos_campanhas.php
// ------------------------------------------------------------
// Autenticação
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';
date_default_timezone_set('America/Sao_Paulo');

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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// Aumenta o limite do GROUP_CONCAT
$pdo->exec("SET SESSION group_concat_max_len = 1000000");

$colunasVotos = $pdo->query("SHOW COLUMNS FROM votos")->fetchAll(PDO::FETCH_COLUMN);
$temAno    = in_array('ano', $colunasVotos, true);
$temNumero = in_array('numero', $colunasVotos, true);

// ------------------------------------------------------------
// Detectar coluna de seção
// ------------------------------------------------------------
$candidatasSecao = [
    'numeroSecao','numero_secao','secao','nrSecao','NR_SECAO',
    'numSecao','num_secao','secaoNumero','secao_numero',
    'cdSecao','cd_secao','codSecao','cod_secao',
    'nr_secao','secao_num'
];
$colSecao = '';
foreach ($candidatasSecao as $cand) {
    if (in_array($cand, $colunasVotos, true)) { $colSecao = $cand; break; }
}
if ($colSecao === '') {
    foreach ($colunasVotos as $col) {
        $norm = strtolower($col);
        $norm = str_replace(
            ['ç','ã','á','à','â','é','ê','í','ó','õ','ú'],
            ['c','a','a','a','a','e','e','i','o','o','u'],
            $norm
        );
        if (strpos($norm, 'secao') !== false) {
            $colSecao = $col;
            break;
        }
    }
}
$temSecao = ($colSecao !== '');

// ============================================================
// AJAX — detalhe sob demanda
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detalhe') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $dCand  = trim($_GET['cand']  ?? '');
        $dCargo = trim($_GET['cargo'] ?? '');
        $dAno   = $temAno ? trim($_GET['ano'] ?? '') : '';

        if ($dCand === '' || $dCargo === '') {
            echo json_encode(['ok' => false, 'msg' => 'Parâmetros insuficientes.']);
            exit;
        }

        $w = ["v.nomeCandidatoResp = :c", "v.nomeCargoPerg = :cg"];
        $p = [':c' => $dCand, ':cg' => $dCargo];
        if ($temAno && $dAno !== '') {
            $w[]     = "v.ano = :a";
            $p[':a'] = (int)$dAno;
        }
        $ws = 'WHERE ' . implode(' AND ', $w);

        $sqlR = "
            SELECT COALESCE(r.nome, 'Sem região') AS regiao,
                   SUM(v.qtdVotos) AS total
            FROM votos v
            LEFT JOIN municipio_regiao mr
              ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
               = v.nomeMunicipio  COLLATE utf8mb4_general_ci
            LEFT JOIN regioes r ON r.id = mr.regiao_id
            $ws
            GROUP BY r.nome
            ORDER BY total DESC
        ";
        $stmt = $pdo->prepare($sqlR);
        $stmt->execute($p);
        $porRegiao = $stmt->fetchAll();

        $sqlM = "
            SELECT
                v.nomeMunicipio,
                COALESCE(r.nome, 'Sem região') AS regiao,
                SUM(v.qtdVotos) AS total
            FROM votos v
            LEFT JOIN municipio_regiao mr
              ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
               = v.nomeMunicipio  COLLATE utf8mb4_general_ci
            LEFT JOIN regioes r ON r.id = mr.regiao_id
            $ws
            GROUP BY v.nomeMunicipio, r.nome
            ORDER BY total DESC
            LIMIT 15
        ";
        $stmt = $pdo->prepare($sqlM);
        $stmt->execute($p);
        $porMunicipio = $stmt->fetchAll();

        echo json_encode([
            'ok'            => true,
            'por_regiao'    => $porRegiao,
            'por_municipio' => $porMunicipio,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ------------------------------------------------------------
// Apagar campanha (POST) — só admin
// ------------------------------------------------------------
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'apagar_campanha') {
    if (!$isAdmin) {
        http_response_code(403);
        die('Acesso negado.');
    }
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Token de segurança inválido.');
    }

    $delCandidato = trim($_POST['del_candidato'] ?? '');
    $delCargo     = trim($_POST['del_cargo']     ?? '');
    $delAno       = $temAno ? trim($_POST['del_ano'] ?? '') : '';

    if ($delCandidato === '') {
        $_SESSION['flash'] = ['tipo' => 'erro', 'msg' => 'Candidato não informado.'];
        header('Location: ' . $_SERVER['PHP_SELF'] . (empty($_GET) ? '' : '?' . http_build_query($_GET)));
        exit;
    }

    $conds   = ["nomeCandidatoResp = :c"];
    $dparams = [':c' => $delCandidato];

    if ($delCargo !== '') {
        $conds[]        = "nomeCargoPerg = :cg";
        $dparams[':cg'] = $delCargo;
    }
    if ($temAno && $delAno !== '') {
        $conds[]       = "ano = :a";
        $dparams[':a'] = (int)$delAno;
    }
    $whereDel = implode(' AND ', $conds);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM votos WHERE $whereDel");
        $stmt->execute($dparams);
        $removidos = $stmt->rowCount();
        $pdo->commit();

        $_SESSION['flash'] = [
            'tipo' => 'ok',
            'msg'  =>
                "✔ Apagados <b>" . number_format($removidos, 0, ',', '.') . "</b> registro(s) de " .
                "<b>" . htmlspecialchars($delCandidato) . "</b>" .
                ($delCargo !== '' ? " no cargo <b>" . htmlspecialchars($delCargo) . "</b>" : "") .
                ($delAno  !== '' ? " em <b>" . (int)$delAno . "</b>" : "") . "."
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash'] = ['tipo' => 'erro', 'msg' => 'Erro ao apagar: ' . $e->getMessage()];
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . (empty($_GET) ? '' : '?' . http_build_query($_GET)));
    exit;
}

// ============================================================
// FILTROS
// ============================================================
$ano       = $_GET['ano']       ?? '';
$cargo     = $_GET['cargo']     ?? '';
$candidato = $_GET['candidato'] ?? '';
$regiao    = $_GET['regiao']    ?? '';
$ordem     = $_GET['ordem']     ?? 'votos';

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
    $filtros[] = "EXISTS (
        SELECT 1 FROM municipio_regiao mr
         WHERE mr.nomeMunicipio COLLATE utf8mb4_general_ci
             = v.nomeMunicipio  COLLATE utf8mb4_general_ci
           AND mr.regiao_id = :regiao
    )";
    $params[':regiao'] = (int)$regiao;
}

$where = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

// ============================================================
// REGRA DE EXIBIÇÃO CONDICIONAL
//   A página SEMPRE inicia em branco.
//   Só exibe dados quando:
//     - coluna "ano" existe: ano E candidato preenchidos
//     - coluna "ano" ausente: apenas candidato preenchido
// ============================================================
$temAlgumFiltro = ($temAno && $ano !== '') || $cargo !== '' || $candidato !== '' || $regiao !== '';

if (!$temAno) {
    // Sem coluna "ano": basta o candidato
    $temFiltrosObrigatorios = ($candidato !== '');
} else {
    $temFiltrosObrigatorios = ($ano !== '' && $candidato !== '');
}

$mostrarDados = $temFiltrosObrigatorios;

// ------------------------------------------------------------
// Defaults (para quando a exibição estiver bloqueada)
// ------------------------------------------------------------
$campanhas            = [];
$porRegiao            = [];
$municipiosDetalhado  = [];
$rankingCandidatos    = [];
$rankingCargos        = [];
$totalCandidatos      = 0;
$totalCargos          = 0;
$totalGeralFiltro     = 0;
$totalAnos            = 0;
$totalMunicipios      = 0;
$totalZonas           = 0;
$totalCampanhasFiltro = 0;
$totalCandidatosBanco = 0;
$totalCargosBanco     = 0;
$totalGeralBanco      = 0;
$totalAnosBanco       = 0;
$totalCampanhasBanco  = 0;
$pctFiltroBanco       = 0;
$maxVotosRegiao       = 0;
$totalVotosFiltro     = 0;

// ============================================================
// CONSULTAS PESADAS (só executam se for para exibir)
// ============================================================
if ($mostrarDados) {

    $colAnoSelect    = $temAno ? "v.ano," : "";
    $colAnoGroup     = $temAno ? "v.ano," : "";
    $colNumeroSelect = $temNumero ? "MAX(v.numero) AS numero," : "";

    switch ($ordem) {
        case 'nome':  $orderBy = "v.nomeCandidatoResp ASC, v.nomeCargoPerg ASC"; break;
        case 'cargo': $orderBy = "v.nomeCargoPerg ASC, total DESC"; break;
        case 'ano':   $orderBy = $temAno ? "v.ano DESC, total DESC" : "total DESC"; break;
        case 'votos':
        default:      $orderBy = $temAno ? "v.ano DESC, total DESC" : "total DESC"; break;
    }

    $sqlCampanhas = "
        SELECT
            $colAnoSelect
            $colNumeroSelect
            v.nomeCandidatoResp AS candidato,
            v.nomeCargoPerg     AS cargo,
            SUM(v.qtdVotos)     AS total,
            COUNT(DISTINCT v.nomeMunicipio) AS qtd_municipios,
            COUNT(DISTINCT v.numeroZona)    AS qtd_zonas
        FROM votos v
        $where
        GROUP BY $colAnoGroup v.nomeCandidatoResp, v.nomeCargoPerg
        ORDER BY $orderBy
    ";
    $stmt = $pdo->prepare($sqlCampanhas);
    $stmt->execute($params);
    $campanhas = $stmt->fetchAll();

    $totalVotosFiltro = 0;
    foreach ($campanhas as $c) $totalVotosFiltro += (int)$c['total'];

    // ---- KPIs filtrados ----
    $sqlKpiFiltro = "
        SELECT
            COUNT(DISTINCT v.nomeCandidatoResp) AS total_candidatos,
            COUNT(DISTINCT v.nomeCargoPerg)     AS total_cargos,
            SUM(v.qtdVotos)                     AS total_geral,
            " . ($temAno ? "COUNT(DISTINCT v.ano)" : "0") . " AS total_anos,
            COUNT(DISTINCT v.nomeMunicipio)     AS total_municipios,
            COUNT(DISTINCT v.numeroZona)        AS total_zonas
        FROM votos v
        $where
    ";
    $stmt = $pdo->prepare($sqlKpiFiltro);
    $stmt->execute($params);
    $rowKpiFiltro = $stmt->fetch();

    $totalCandidatos  = (int)($rowKpiFiltro['total_candidatos']  ?? 0);
    $totalCargos      = (int)($rowKpiFiltro['total_cargos']      ?? 0);
    $totalGeralFiltro = (int)($rowKpiFiltro['total_geral']       ?? 0);
    $totalAnos        = (int)($rowKpiFiltro['total_anos']        ?? 0);
    $totalMunicipios  = (int)($rowKpiFiltro['total_municipios']  ?? 0);
    $totalZonas       = (int)($rowKpiFiltro['total_zonas']       ?? 0);

    if ($temAno) {
        $sqlCampCount = "SELECT COUNT(*) FROM (
            SELECT DISTINCT v.nomeCandidatoResp, v.nomeCargoPerg, v.ano
            FROM votos v
            $where
        ) t";
    } else {
        $sqlCampCount = "SELECT COUNT(*) FROM (
            SELECT DISTINCT v.nomeCandidatoResp, v.nomeCargoPerg
            FROM votos v
            $where
        ) t";
    }
    $stmt = $pdo->prepare($sqlCampCount);
    $stmt->execute($params);
    $totalCampanhasFiltro = (int)$stmt->fetchColumn();

    // ---- KPIs globais (referência) ----
    $colCountAno = $temAno ? "COUNT(DISTINCT ano)" : "0";
    $rowKpiBanco = $pdo->query("
        SELECT
            COUNT(DISTINCT nomeCandidatoResp) AS total_candidatos,
            COUNT(DISTINCT nomeCargoPerg)     AS total_cargos,
            SUM(qtdVotos)                     AS total_geral,
            $colCountAno                      AS total_anos
        FROM votos
    ")->fetch();

    $totalCandidatosBanco = (int)$rowKpiBanco['total_candidatos'];
    $totalCargosBanco     = (int)$rowKpiBanco['total_cargos'];
    $totalGeralBanco      = (int)$rowKpiBanco['total_geral'];
    $totalAnosBanco       = (int)$rowKpiBanco['total_anos'];

    if ($temAno) {
        $totalCampanhasBanco = (int)$pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT DISTINCT nomeCandidatoResp, nomeCargoPerg, ano FROM votos
             ) t"
        )->fetchColumn();
    } else {
        $totalCampanhasBanco = (int)$pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT DISTINCT nomeCandidatoResp, nomeCargoPerg FROM votos
             ) t"
        )->fetchColumn();
    }

    // ---- Votos por região ----
    $sqlRegioesFiltro = "
        SELECT
            COALESCE(r.nome, 'Sem região') AS regiao,
            COUNT(DISTINCT v.nomeMunicipio) AS qtd_municipios,
            COUNT(DISTINCT v.numeroZona)    AS qtd_zonas,
            COUNT(DISTINCT v.nomeCandidatoResp) AS qtd_candidatos,
            SUM(v.qtdVotos)                 AS total
        FROM votos v
        LEFT JOIN municipio_regiao mr
          ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
           = v.nomeMunicipio  COLLATE utf8mb4_general_ci
        LEFT JOIN regioes r ON r.id = mr.regiao_id
        $where
        GROUP BY r.nome
        ORDER BY total DESC
    ";
    $stmt = $pdo->prepare($sqlRegioesFiltro);
    $stmt->execute($params);
    $porRegiao = $stmt->fetchAll();

    $maxVotosRegiao = 0;
    foreach ($porRegiao as $r) {
        if ((int)$r['total'] > $maxVotosRegiao) $maxVotosRegiao = (int)$r['total'];
    }

    // ---- Listagem detalhada por município ----
    $colSecaoSelect = $temSecao ? ",
            COUNT(DISTINCT v.`$colSecao`) AS qtd_secoes,
            GROUP_CONCAT(DISTINCT v.`$colSecao` ORDER BY v.`$colSecao` SEPARATOR ',') AS secoes_lista
        " : "";

    $sqlMunicipios = "
        SELECT
            v.nomeMunicipio,
            COALESCE(r.nome, 'Sem região') AS regiao,
            SUM(v.qtdVotos) AS total,
            COUNT(DISTINCT v.numeroZona) AS qtd_zonas,
            COUNT(DISTINCT v.nomeCandidatoResp) AS qtd_candidatos,
            GROUP_CONCAT(DISTINCT v.numeroZona ORDER BY v.numeroZona SEPARATOR ',') AS zonas_lista
            $colSecaoSelect
        FROM votos v
        LEFT JOIN municipio_regiao mr
          ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
           = v.nomeMunicipio  COLLATE utf8mb4_general_ci
        LEFT JOIN regioes r ON r.id = mr.regiao_id
        $where
        GROUP BY v.nomeMunicipio, r.nome
        ORDER BY total DESC
    ";
    $stmt = $pdo->prepare($sqlMunicipios);
    $stmt->execute($params);
    $municipiosDetalhado = $stmt->fetchAll();

    // ---- Rankings ----
    $aggCand = [];
    foreach ($campanhas as $c) {
        $chave = ($temAno ? ($c['ano'] ?? '—') . '|' : '') . $c['candidato'];
        if (!isset($aggCand[$chave])) {
            $aggCand[$chave] = [
                'ano'        => $c['ano'] ?? null,
                'candidato'  => $c['candidato'],
                'qtd_cargos' => 0,
                'total'      => 0,
            ];
        }
        $aggCand[$chave]['qtd_cargos'] += 1;
        $aggCand[$chave]['total']      += (int)$c['total'];
    }
    $rankingCandidatos = array_values($aggCand);
    usort($rankingCandidatos, function ($a, $b) use ($temAno) {
        if ($temAno) {
            $cmp = ($b['ano'] ?? 0) <=> ($a['ano'] ?? 0);
            if ($cmp !== 0) return $cmp;
        }
        return $b['total'] <=> $a['total'];
    });
    $rankingCandidatos = array_slice($rankingCandidatos, 0, 10);

    $aggCargo = [];
    foreach ($campanhas as $c) {
        $chave = ($temAno ? ($c['ano'] ?? '—') . '|' : '') . $c['cargo'];
        if (!isset($aggCargo[$chave])) {
            $aggCargo[$chave] = [
                'ano'            => $c['ano'] ?? null,
                'cargo'          => $c['cargo'],
                'qtd_candidatos' => 0,
                'total'          => 0,
            ];
        }
        $aggCargo[$chave]['qtd_candidatos'] += 1;
        $aggCargo[$chave]['total']          += (int)$c['total'];
    }
    $rankingCargos = array_values($aggCargo);
    usort($rankingCargos, function ($a, $b) use ($temAno) {
        if ($temAno) {
            $cmp = ($b['ano'] ?? 0) <=> ($a['ano'] ?? 0);
            if ($cmp !== 0) return $cmp;
        }
        return $b['total'] <=> $a['total'];
    });

    $pctFiltroBanco = $totalGeralBanco > 0
        ? ($totalGeralFiltro / $totalGeralBanco) * 100
        : 0;
}

// ------------------------------------------------------------
// Helper: gera chips HTML COM ESPAÇO entre eles
// ------------------------------------------------------------
function renderTags($lista, $prefixo, $classeCss) {
    if (empty($lista)) return '<span style="color:#bbb;">—</span>';
    $itens = array_filter(array_map('trim', explode(',', (string)$lista)), function ($x) {
        return $x !== '';
    });
    usort($itens, function ($a, $b) { return ((int)$a) <=> ((int)$b); });

    $partes = [];
    foreach ($itens as $it) {
        $partes[] = '<span class="' . $classeCss . '">'
                  . $prefixo . htmlspecialchars($it)
                  . '</span>';
    }
    return implode(' ', $partes);
}

// ------------------------------------------------------------
// Helper: texto puro para data-search (vai no <td>)
// ------------------------------------------------------------
function tagsSearchText($lista, $prefixo) {
    if (empty($lista)) return '';
    $itens = array_filter(array_map('trim', explode(',', (string)$lista)), function ($x) {
        return $x !== '';
    });
    usort($itens, function ($a, $b) { return ((int)$a) <=> ((int)$b); });

    $out = [];
    foreach ($itens as $it) {
        $out[] = $prefixo . $it;
    }
    return implode(' ', $out);
}

// ============================================================
// LISTAS PARA SELECTS (sempre carregam)
// ============================================================
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

$temFiltro  = $ano || $cargo || $candidato || $regiao;
$numColunas = ($temAno ? 8 : 7) + 1 + 1 + ($isAdmin ? 1 : 0);

$debugSessao = isset($_GET['debug_sessao']);
$debugSecao  = isset($_GET['debug_secao']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Candidatos e Campanhas</title>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    h2 { margin-top:30px; }

    .barra-usuario {
        display: flex; align-items: center; gap: 12px;
        background: #fff; padding: 10px 16px; border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 18px; font-size: 13px;
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
    .perfil-tag.perfil-admin    { background:#0d6efd; color:#fff; }
    .perfil-tag.perfil-super,
    .perfil-tag.perfil-superadmin { background:#6f42c1; color:#fff; }
    .perfil-tag.perfil-consulta,
    .perfil-tag.perfil-visitante  { background:#e9ecef; color:#495057; }

    .badge-super     { background:#6f42c1; color:#fff; padding:2px 10px; border-radius:12px; font-size:11px; }
    .badge-admin     { background:#0d6efd; color:#fff; padding:2px 10px; border-radius:12px; font-size:11px; }
    .badge-visitante { background:#e9ecef; color:#495057; padding:2px 10px; border-radius:12px; font-size:11px; }

    .link-debug { margin-left: auto; color: #6c757d; text-decoration: none; font-size: 12px; }
    .link-debug:hover { color: #0d6efd; text-decoration: underline; }

    .debug-box {
        background:#1e1e1e; color:#d4d4d4;
        padding:16px 20px; border-radius:8px;
        font-family:'Courier New', monospace; font-size:12.5px;
        line-height:1.6; overflow-x:auto; margin-bottom:18px;
        border-left:5px solid #ffc107;
    }
    .debug-box h3 { margin:0 0 10px; color:#ffc107; font-size:14px; font-family:Arial, sans-serif; }
    .debug-box .destaque { color:#4ec9b0; }
    .debug-box .aviso    { color:#f48771; }
    .debug-box .ok       { color:#b5e8b5; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:12px; }
    .kpi {
        background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:190px;
        border-left:5px solid #0069d9;
        transition: transform .12s, box-shadow .12s;
    }
    .kpi:hover { transform: translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.12); }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#0069d9; margin-top:4px; }
    .kpi .sub    { font-size:12px; color:#888; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.cinza   { border-left-color:#6c757d; } .kpi.cinza .valor { color:#6c757d; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi.teal    { border-left-color:#20c997; } .kpi.teal .valor { color:#20c997; }
    .kpi.ativo   { box-shadow:0 3px 10px rgba(0,105,217,.2); }

    .kpi-banco {
        background: #fafafa; border-left: 4px solid #ccc;
        padding: 8px 16px; border-radius: 6px;
        display: inline-flex; flex-wrap:wrap; align-items:baseline;
        gap: 18px; font-size: 12px; color: #666;
        margin-bottom: 22px;
    }
    .kpi-banco strong { color:#333; font-size:14px; }
    .kpi-banco .tag  { text-transform:uppercase; font-size:10.5px;
                       letter-spacing:.6px; color:#888; margin-right:4px; }

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

    .select2-container { margin: 4px 10px 4px 0; }
    .select2-container--default .select2-selection--single {
        height: 34px; padding: 3px 6px; border: 1px solid #ccc; border-radius: 4px; background:#fff;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 26px; color:#333;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px; }
    .select2-container .select2-selection--single { height: 34px; }
    .select2-search--dropdown .select2-search__field { padding: 6px; font-size: 14px; border-radius: 4px; }
    .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color: #0069d9; }
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
    .barra.barra-teal  > span { background:#20c997; }
    .barra.barra-roxo  > span { background:#6f42c1; }
    .barra.barra-verde > span { background:#28a745; }

    td.col-numero {
        text-align: center; font-weight: bold; font-size: 14px;
        color: #0053b3; font-family: 'Courier New', monospace; min-width: 50px;
    }
    th.col-numero { text-align: center; }
    .numero-vazio { color:#ccc; font-weight: normal; }

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
        font-size:11px; font-weight:bold; text-transform:uppercase; letter-spacing:.4px;
    }
    .badge-ano   { background:#e7f3ff; color:#0069d9; }
    .badge-cargo { background:#e6f0fa; color:#0069d9; }
    .badge-regiao { background:#e6f9f0; color:#0f8a54; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso code { background:#fff; padding:1px 5px; border-radius:3px;
                  font-size:12px; border:1px solid #eee; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    /* Tela em branco inicial */
    .estado-inicial {
        background: #fff;
        border-radius: 12px;
        padding: 56px 40px;
        text-align: center;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        margin-bottom: 24px;
        border-top: 6px solid #0069d9;
    }
    .estado-inicial .icone {
        font-size: 56px;
        line-height: 1;
        margin-bottom: 18px;
        display: block;
    }
    .estado-inicial h2 {
        margin: 0 0 12px;
        font-size: 22px;
        color: #0b3d91;
    }
    .estado-inicial p {
        margin: 0 0 20px;
        font-size: 15px;
        color: #555;
        line-height: 1.6;
        max-width: 640px;
        margin-left: auto;
        margin-right: auto;
    }
    .estado-inicial .passos {
        display: inline-flex;
        gap: 14px;
        flex-wrap: wrap;
        justify-content: center;
        margin-top: 8px;
    }
    .estado-inicial .passo {
        background: #eaf3fb;
        border: 1px solid #cfe2ff;
        border-radius: 10px;
        padding: 12px 18px;
        font-size: 13.5px;
        color: #0053b3;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .estado-inicial .passo .num-passo {
        background: #0069d9;
        color: #fff;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: bold;
    }
    .estado-inicial small {
        display: block;
        margin-top: 20px;
        color: #888;
        font-size: 12.5px;
    }

    .flash {
        padding: 12px 18px; border-radius: 6px; margin-bottom: 18px;
        font-size: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.08); line-height: 1.5;
    }
    .flash.ok   { background:#d4edda; border-left:5px solid #28a745; color:#155724; }
    .flash.erro { background:#f8d7da; border-left:5px solid #dc3545; color:#721c24; }
    .flash code {
        background:#fff; padding:1px 6px; border-radius:3px;
        font-size:12.5px; border:1px solid #cfe2d4;
    }

    .btn-lixeira {
        background: transparent; border: none; cursor: pointer;
        font-size: 18px; padding: 4px 6px; border-radius: 4px;
        transition: background .12s, transform .12s; opacity: .75;
    }
    .btn-lixeira:hover { background: #ffe0e0; opacity: 1; transform: scale(1.15); }
    .col-acoes { text-align: center; width: 52px; }

    .detalhe-loading { padding: 24px; text-align: center; color: #888; font-size: 13px; }
    .detalhe-loading .spinner {
        display: inline-block; width: 22px; height: 22px;
        border: 3px solid #e0e0e0; border-top-color: #0069d9;
        border-radius: 50%; animation: spin .8s linear infinite;
        vertical-align: middle; margin-right: 8px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    #modal-apagar {
        position: fixed; inset: 0;
        background: rgba(0,0,0,.55); backdrop-filter: blur(2px);
        display: none; align-items: center; justify-content: center;
        z-index: 9999; opacity: 0; transition: opacity .18s ease;
    }
    #modal-apagar.ativo { display: flex; opacity: 1; }
    #modal-apagar .caixa {
        background: #fff; border-radius: 12px;
        padding: 26px 30px; width: 520px; max-width: 94vw;
        box-shadow: 0 12px 40px rgba(0,0,0,.35);
        border-top: 6px solid #dc3545; max-height: 92vh; overflow-y: auto;
    }
    #modal-apagar .cabecalho { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
    #modal-apagar .cabecalho .icone { font-size: 34px; line-height: 1; }
    #modal-apagar .cabecalho h3 { margin: 0; font-size: 18px; color: #b71c1c; }
    #modal-apagar .corpo { font-size: 14px; color: #444; line-height: 1.6; }
    #modal-apagar .corpo .destaque {
        display: block; background: #f8f9fa; border-left: 4px solid #dc3545;
        padding: 10px 14px; border-radius: 6px; margin: 12px 0; font-size: 13.5px;
    }
    #modal-apagar .corpo .destaque b { color: #b71c1c; }
    #modal-apagar .destaque-numero {
        display: inline-block; background: #0053b3; color: #fff;
        padding: 4px 12px; border-radius: 6px;
        font-family: 'Courier New', monospace;
        font-size: 18px; font-weight: bold; letter-spacing: 1px; margin-left: 4px;
    }
    #modal-apagar .aviso-forte {
        margin-top: 12px; font-weight: bold; color: #b71c1c;
        text-align: center; letter-spacing: .5px; font-size: 12.5px;
    }
    #modal-apagar .campo { margin-top: 16px; }
    #modal-apagar .campo label {
        display: block; font-size: 13px; font-weight: bold; color: #333; margin-bottom: 6px;
    }
    #modal-apagar .campo input {
        width: 100%; padding: 10px 12px; border: 2px solid #ddd; border-radius: 6px;
        font-size: 18px; font-family: 'Courier New', monospace; box-sizing: border-box;
        text-align: center; letter-spacing: 2px; font-weight: bold;
    }
    #modal-apagar .campo input:focus { outline: none; border-color: #dc3545; }
    #modal-apagar .rodape { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
    #modal-apagar .btn-cancelar {
        background: #6c757d; color: #fff; padding: 9px 18px; border-radius: 6px;
        border: none; font-size: 14px; cursor: pointer;
    }
    #modal-apagar .btn-cancelar:hover { background: #5a6268; }
    #modal-apagar .btn-apagar {
        background: #dc3545; color: #fff; padding: 9px 20px; border-radius: 6px;
        border: none; font-size: 14px; font-weight: bold; cursor: pointer;
    }
    #modal-apagar .btn-apagar:hover:not(:disabled) { background: #b71c1c; }
    #modal-apagar .btn-apagar:disabled { background: #e0aeb4; cursor: not-allowed; opacity: .7; }

    /* ============ Regiões ============ */
    .regioes-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 14px; margin-bottom: 28px;
    }
    .regiao-card {
        background:#fff; border-radius:8px; padding:14px 16px;
        box-shadow:0 1px 3px rgba(0,0,0,.1);
        border-left:5px solid #20c997;
        transition: transform .12s, box-shadow .12s; cursor: default;
    }
    .regiao-card:hover { transform: translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.12); }
    .regiao-card .rg-nome {
        font-weight:bold; color:#0f8a54; font-size:14px;
        display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;
    }
    .regiao-card .rg-nome .rg-pos {
        background:#e6f9f0; color:#0f8a54;
        padding:1px 8px; border-radius:12px; font-size:11px; font-weight:600;
    }
    .regiao-card .rg-total { font-size:22px; font-weight:bold; color:#0069d9; margin:4px 0 2px; }
    .regiao-card .rg-total small { font-size:11px; color:#888; font-weight:normal; margin-left:4px; }
    .regiao-card .rg-sub { font-size:11px; color:#666; margin-bottom:6px; }
    .regiao-card .rg-barra {
        background:#eaf7f1; border-radius:4px; height:8px;
        overflow:hidden; margin-top:6px;
    }
    .regiao-card .rg-barra > span {
        display:block; height:100%; background:#20c997; transition: width .4s ease;
    }
    .regiao-card .rg-pct {
        font-size:11px; color:#0f8a54; margin-top:4px;
        text-align:right; font-weight:600;
    }

    /* ============ DataTable ============ */
    .dt-wrapper {
        background: #fff; border-radius: 8px; padding: 18px 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,.1); margin-bottom: 24px;
    }
    .dt-header {
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 12px; margin-bottom: 12px;
        border-bottom: 1px solid #eee; padding-bottom: 10px;
    }
    .dt-header h3 {
        margin: 0; font-size: 16px; color: #0069d9;
        display: flex; align-items: center; gap: 8px;
    }
    .dt-header .dt-badge {
        font-size: 11px; padding: 2px 10px; border-radius: 12px;
        background: #e7f3ff; color: #0069d9; font-weight: 600;
        text-transform: uppercase; letter-spacing: .5px;
    }
    .dt-header .dt-total { font-size: 12px; color: #888; }

    .dt-filtros {
        display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px;
        background: #f6f9fd; border: 1px solid #dbe6f5; border-radius: 8px;
        padding: 12px 16px; margin-bottom: 14px;
    }
    .dt-filtros .filtro-item {
        display: flex; flex-direction: column; gap: 4px;
        position: relative;
    }
    .dt-filtros .filtro-item label {
        font-size: 12px; font-weight: bold; color: #0b3d91;
        letter-spacing: .3px;
    }
    .dt-filtros .filtro-item input {
        padding: 7px 30px 7px 12px;
        border: 1.5px solid #ccd; border-radius: 6px;
        font-size: 14px; font-family: 'Courier New', monospace;
        width: 180px; transition: border-color .12s, box-shadow .12s;
        background: #fff;
    }
    .dt-filtros .filtro-item input:focus {
        outline: none; border-color: #0069d9;
        box-shadow: 0 0 0 3px rgba(0,105,217,.15);
    }
    .dt-filtros .filtro-clear {
        position: absolute; right: 6px; bottom: 7px;
        background: transparent; border: none; cursor: pointer;
        color: #999; font-size: 16px; line-height: 1;
        padding: 2px 6px; border-radius: 4px;
    }
    .dt-filtros .filtro-clear:hover { background: #f0f0f0; color: #dc3545; }
    .dt-filtros .filtro-limpar-todos {
        background: #6c757d; color: #fff; padding: 8px 16px;
        border-radius: 6px; border: none; cursor: pointer;
        font-size: 13px; font-weight: bold; align-self: flex-end;
        margin-bottom: 1px;
    }
    .dt-filtros .filtro-limpar-todos:hover { background: #5a6268; }
    .dt-filtros .filtro-info {
        font-size: 11.5px; color: #666; margin-left: auto; align-self: center;
    }
    .dt-filtros .filtro-info code {
        background:#fff; padding:1px 5px; border-radius:3px;
        border:1px solid #dbe6f5; font-size:11px;
    }

    #tabela-municipios_wrapper { width: 100%; }
    #tabela-municipios {
        font-size: 13px;
        border-collapse: collapse;
    }
    #tabela-municipios thead th {
        background: #0069d9; color: #fff; font-size: 12px;
        text-align: left; padding: 8px 10px; border: 1px solid #0053b3;
        white-space: nowrap;
    }
    #tabela-municipios thead th.sorting,
    #tabela-municipios thead th.sorting_asc,
    #tabela-municipios thead th.sorting_desc { position: relative; padding-right: 22px; }
    #tabela-municipios tbody td {
        padding: 7px 10px; border: 1px solid #e6e6e6;
        vertical-align: top;
    }
    #tabela-municipios tbody tr:hover { background: #f4f8ff !important; }
    #tabela-municipios tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
    #tabela-municipios tbody td.pos { text-align: center; color: #888; width: 40px; }

    .tags-cell {
        max-width: 260px; max-height: 90px; overflow-y: auto;
        line-height: 1.6;
        padding-right: 4px;
    }
    .tags-cell::-webkit-scrollbar { width: 6px; }
    .tags-cell::-webkit-scrollbar-thumb { background: #ccd; border-radius: 3px; }

    .tag-zona, .tag-secao {
        display: inline-block;
        padding: 1px 6px;
        margin: 2px 3px 2px 0;
        border-radius: 10px;
        font-size: 10.5px;
        font-family: 'Courier New', monospace;
        font-weight: 700;
        white-space: nowrap;
    }
    .tag-zona  { background: #e7f3ff; color: #0053b3; border: 1px solid #cfe2ff; }
    .tag-secao { background: #e6f9f0; color: #0f8a54; border: 1px solid #c7efd9; }

    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
        padding: 5px 8px; border-radius: 4px; border: 1px solid #ccc;
        font-size: 13px; margin-left: 6px;
    }
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_paginate {
        font-size: 12.5px; color: #666;
    }
    .dataTables_wrapper .dataTables_filter { margin-bottom: 8px; }
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        padding: 4px 10px !important;
        border-radius: 4px !important;
        font-size: 12.5px !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: #0069d9 !important;
        color: #fff !important;
        border-color: #0053b3 !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: #eaf3fb !important;
        color: #0069d9 !important;
        border-color: #cfe2ff !important;
    }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Candidatos e Campanhas</h1>

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

    <a class="link-debug" href="?debug_sessao=1<?= $temFiltro ? '&' . http_build_query($_GET) : '' ?>">
        🔍 ver sessão
    </a>
</div>

<?php if ($debugSessao): ?>
    <div class="debug-box">
        <h3>🔍 Diagnóstico do usuário logado</h3>
<pre style="margin:0; white-space:pre-wrap;">
<span class="destaque">Login:</span>              <?= $__loginLower !== '' ? htmlspecialchars($__loginLower) : '<span class="aviso">(vazio)</span>' ?>

<span class="destaque">Nome:</span>               <?= $__nomeLower !== '' ? htmlspecialchars($__nomeLower) : '<span class="aviso">(vazio)</span>' ?>

<span class="destaque">Perfil:</span>             <?= htmlspecialchars($__perfil) ?>

<span class="destaque">É dono?</span>            <?= $__ehDono ? '<span class="ok">SIM ✔</span>' : '<span class="aviso">NÃO ✘</span>' ?>

<span class="destaque">Perfil admin/super?</span> <?= $__ehAdmin ? '<span class="ok">SIM ✔</span>' : '<span class="aviso">NÃO ✘</span>' ?>

<span class="destaque">Pode apagar?</span>       <?= $isAdmin ? '<span class="ok">SIM ✔</span>' : '<span class="aviso">NÃO ✘</span>' ?>

<span class="destaque">Coluna 'numero' existe em votos?</span> <?= $temNumero ? '<span class="ok">SIM ✔</span>' : '<span class="aviso">NÃO ✘</span>' ?>

<span class="destaque">Coluna de seção detectada:</span> <?= $temSecao ? '<span class="ok">' . htmlspecialchars($colSecao) . '</span>' : '<span class="aviso">(NENHUMA)</span>' ?>

<span class="destaque">Array retornado por usuarioLogado():</span>
<?= htmlspecialchars(print_r($__u, true)) ?>
</pre>
    </div>
<?php endif; ?>

<?php if ($debugSecao): ?>
    <div class="debug-box">
        <h3>🔍 Diagnóstico da coluna de seção</h3>
<pre style="margin:0; white-space:pre-wrap;">
<span class="destaque">Colunas em `votos`:</span>
<?= htmlspecialchars(implode(', ', $colunasVotos)) ?>

<span class="destaque">Coluna de seção detectada:</span>
<?= $temSecao
    ? '<span class="ok">' . htmlspecialchars($colSecao) . '</span>'
    : '<span class="aviso">(NENHUMA — nenhuma coluna com "secao" no nome)</span>' ?>

<span class="destaque">Total de municípios carregados:</span> <?= count($municipiosDetalhado) ?>
</pre>
    </div>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="flash <?= htmlspecialchars($flash['tipo']) ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

<?php if (!$temAno): ?>
    <div class="aviso">
        <strong>Atenção:</strong> a coluna <code>ano</code> ainda não existe em <code>votos</code>.
        Para ativar: <code>ALTER TABLE votos ADD COLUMN ano INT NULL AFTER nomeMunicipio, ADD INDEX idx_ano (ano);</code>
    </div>
<?php endif; ?>

<?php if (!$temNumero): ?>
    <div class="aviso">
        <strong>Atenção:</strong> a coluna <code>numero</code> não existe em <code>votos</code>.
        A coluna "Nº" ficará vazia. Para ativar:
        <br>
        <code>ALTER TABLE votos ADD COLUMN numero INT NULL AFTER cargoPergunta, ADD INDEX idx_numero (numero);</code>
    </div>
<?php endif; ?>

<?php if (!$temSecao): ?>
    <div class="aviso">
        <strong>Atenção:</strong> nenhuma coluna de seção foi encontrada em <code>votos</code>.
        A coluna "Seções" ficará vazia. Colunas existentes:
        <code><?= htmlspecialchars(implode(', ', $colunasVotos)) ?></code>.
        Se a coluna existir com outro nome, acrescente em <code>$candidatasSecao</code>
        ou verifique com <a href="?debug_secao=1">?debug_secao=1</a>.
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- FORMULÁRIO DE FILTROS (não auto-submete)                     -->
<!-- ============================================================ -->
<form method="get" action="candidatos_campanhas.php" id="form-filtros">
    <div class="filtros">
        <?php if ($temAno && $anosDisponiveis): ?>
            <label for="ano">Ano:</label>
            <select name="ano" id="ano">
                <option value="">— selecione —</option>
                <?php foreach ($anosDisponiveis as $a): ?>
                    <option value="<?= (int)$a ?>"
                        <?= (string)$a === (string)$ano ? 'selected' : '' ?>><?= (int)$a ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <label for="cargo">Cargo:</label>
        <select name="cargo" id="cargo">
            <option value="">Todos</option>
            <?php foreach ($cargos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $c === $cargo ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="candidato">Candidato:</label>
        <select name="candidato" id="candidato">
            <option value="">— selecione —</option>
            <?php foreach ($candidatos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $c === $candidato ? 'selected' : '' ?>>
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

<?php if (!$mostrarDados): ?>

    <!-- ============================================================ -->
    <!-- TELA EM BRANCO (estado inicial)                               -->
    <!-- ============================================================ -->
    <div class="estado-inicial">
        <span class="icone">🔎</span>
        <h2>Selecione os filtros para começar</h2>
        <p>
            Para exibir os dados de campanhas, escolha
            <?php if ($temAno): ?>
                <b>o Ano</b> e <b>o Candidato</b>
            <?php else: ?>
                <b>o Candidato</b>
            <?php endif; ?>
            acima e clique em <b>Consultar</b>.
            Os demais filtros (Cargo, Região, Ordenação) são opcionais e refinam o resultado.
        </p>
        <div class="passos">
            <div class="passo"><span class="num-passo">1</span>
                <?= $temAno ? 'Escolha o Ano' : 'Escolha o Candidato' ?>
            </div>
            <?php if ($temAno): ?>
            <div class="passo"><span class="num-passo">2</span> Escolha o Candidato</div>
            <?php endif; ?>
            <div class="passo"><span class="num-passo"><?= $temAno ? '3' : '2' ?></span> Clique em Consultar</div>
        </div>
        <small>💡 Dica: use os filtros opcionais (Cargo, Região) para refinar ainda mais o resultado.</small>
    </div>

<?php else: ?>

<!-- KPIs -->
<div class="kpis">
    <div class="kpi ativo">
        <div class="rotulo">Candidatos (filtro)</div>
        <div class="valor"><?= number_format($totalCandidatos, 0, ',', '.') ?></div>
    </div>
    <div class="kpi roxo ativo">
        <div class="rotulo">Cargos (filtro)</div>
        <div class="valor"><?= number_format($totalCargos, 0, ',', '.') ?></div>
    </div>
    <div class="kpi laranja ativo">
        <div class="rotulo">Campanhas<?= $temAno ? ' (cand × cargo × ano)' : ' (cand × cargo)' ?> (filtro)</div>
        <div class="valor"><?= number_format($totalCampanhasFiltro, 0, ',', '.') ?></div>
    </div>
    <?php if ($temAno && $totalAnos > 0): ?>
        <div class="kpi teal ativo">
            <div class="rotulo">Anos distintos (filtro)</div>
            <div class="valor"><?= number_format($totalAnos, 0, ',', '.') ?></div>
        </div>
    <?php endif; ?>
    <div class="kpi verde ativo">
        <div class="rotulo">Total de votos (filtro)</div>
        <div class="valor"><?= number_format($totalGeralFiltro, 0, ',', '.') ?></div>
        <?php if ($totalGeralBanco > 0): ?>
            <div class="sub"><?= number_format($pctFiltroBanco, 2, ',', '.') ?>% do banco</div>
        <?php endif; ?>
    </div>
    <div class="kpi ativo">
        <div class="rotulo">Municípios (filtro)</div>
        <div class="valor"><?= number_format($totalMunicipios, 0, ',', '.') ?></div>
    </div>
    <div class="kpi cinza ativo">
        <div class="rotulo">Zonas (filtro)</div>
        <div class="valor"><?= number_format($totalZonas, 0, ',', '.') ?></div>
    </div>
</div>

<div class="kpi-banco">
    <span><span class="tag">Banco completo:</span>
        <strong><?= number_format($totalCandidatosBanco, 0, ',', '.') ?></strong> candidatos</span>
    <span><strong><?= number_format($totalCargosBanco, 0, ',', '.') ?></strong> cargos</span>
    <span><strong><?= number_format($totalCampanhasBanco, 0, ',', '.') ?></strong> campanhas</span>
    <?php if ($temAno): ?>
        <span><strong><?= number_format($totalAnosBanco, 0, ',', '.') ?></strong> anos</span>
    <?php endif; ?>
    <span><strong><?= number_format($totalGeralBanco, 0, ',', '.') ?></strong> votos</span>
</div>

<!-- ============================================================ -->
<!-- VOTOS POR REGIÃO                                             -->
<!-- ============================================================ -->
<?php if ($porRegiao): ?>
    <h2>Votos por Região (filtradas)</h2>

    <div class="regioes-grid">
        <?php
        $posR = 1;
        foreach ($porRegiao as $r):
            $totalR   = (int)$r['total'];
            $pctR     = $totalGeralFiltro > 0 ? ($totalR / $totalGeralFiltro) * 100 : 0;
            $larguraR = $maxVotosRegiao > 0 ? ($totalR / $maxVotosRegiao) * 100 : 0;
        ?>
            <div class="regiao-card">
                <div class="rg-nome">
                    <span><?= htmlspecialchars($r['regiao']) ?></span>
                    <span class="rg-pos">#<?= $posR++ ?></span>
                </div>
                <div class="rg-total">
                    <?= number_format($totalR, 0, ',', '.') ?>
                    <small>votos</small>
                </div>
                <div class="rg-sub">
                    <?= number_format((int)$r['qtd_municipios'], 0, ',', '.') ?> município(s) ·
                    <?= number_format((int)$r['qtd_zonas'], 0, ',', '.') ?> zona(s) ·
                    <?= number_format((int)$r['qtd_candidatos'], 0, ',', '.') ?> candidato(s)
                </div>
                <div class="rg-barra">
                    <span style="width: <?= number_format($larguraR, 2, '.', '') ?>%"></span>
                </div>
                <div class="rg-pct">
                    <?= number_format($pctR, 2, ',', '.') ?>% do total
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Região</th>
                <th class="num">Municípios</th>
                <th class="num">Zonas</th>
                <th class="num">Candidatos</th>
                <th class="num">Votos</th>
                <th class="num">% do filtro</th>
                <th>Proporção</th>
            </tr>
        </thead>
        <tbody>
            <?php $posR = 1; foreach ($porRegiao as $r):
                $totalR   = (int)$r['total'];
                $pctR     = $totalGeralFiltro > 0 ? ($totalR / $totalGeralFiltro) * 100 : 0;
                $larguraR = $maxVotosRegiao > 0 ? ($totalR / $maxVotosRegiao) * 100 : 0;
            ?>
                <tr>
                    <td><?= $posR++ ?></td>
                    <td><span class="badge badge-regiao"><?= htmlspecialchars($r['regiao']) ?></span></td>
                    <td class="num"><?= number_format((int)$r['qtd_municipios'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format((int)$r['qtd_zonas'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format((int)$r['qtd_candidatos'], 0, ',', '.') ?></td>
                    <td class="num"><strong><?= number_format($totalR, 0, ',', '.') ?></strong></td>
                    <td class="num"><?= number_format($pctR, 2, ',', '.') ?>%</td>
                    <td><span class="barra barra-teal"><span style="width: <?= number_format($larguraR, 2, '.', '') ?>%"></span></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">Total</td>
                <td class="num"><?= number_format($totalGeralFiltro, 0, ',', '.') ?></td>
                <td class="num">100,00%</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
<?php endif; ?>

<h2>Campanhas (filtradas)</h2>

<?php if (!$campanhas): ?>
    <div class="aviso">Nenhuma campanha encontrada para os filtros selecionados.</div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <?php if ($temAno): ?><th>Ano</th><?php endif; ?>
                <th class="col-numero">Nº</th>
                <th>Candidato</th>
                <th>Cargo</th>
                <th class="num">Municípios</th>
                <th class="num">Zonas</th>
                <th class="num">Votos</th>
                <th class="num">% do filtro</th>
                <th>Proporção</th>
                <?php if ($isAdmin): ?><th class="col-acoes">Ações</th><?php endif; ?>
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
                $numero  = $temNumero ? (int)($c['numero'] ?? 0) : 0;
            ?>
                <tr class="linha-campanha"
                    data-chave="<?= htmlspecialchars($chave) ?>"
                    data-candidato="<?= htmlspecialchars($c['candidato']) ?>"
                    data-cargo="<?= htmlspecialchars($c['cargo']) ?>"
                    data-ano="<?= !empty($c['ano']) ? (int)$c['ano'] : '' ?>">
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
                    <td class="col-numero">
                        <?= $numero > 0 ? (int)$numero : '<span class="numero-vazio">—</span>' ?>
                    </td>
                    <td><?= htmlspecialchars($c['candidato']) ?></td>
                    <td><span class="badge badge-cargo"><?= htmlspecialchars($c['cargo']) ?></span></td>
                    <td class="num"><?= number_format($c['qtd_municipios'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format($c['qtd_zonas'], 0, ',', '.') ?></td>
                    <td class="num"><strong><?= number_format((int)$c['total'], 0, ',', '.') ?></strong></td>
                    <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                    <td><span class="barra"><span style="width: <?= number_format($largura, 2, '.', '') ?>%"></span></span></td>
                    <?php if ($isAdmin): ?>
                        <td class="col-acoes">
                            <button type="button" class="btn-lixeira"
                                    title="Apagar todos os registros deste candidato/cargo/ano"
                                    data-candidato="<?= htmlspecialchars($c['candidato']) ?>"
                                    data-cargo="<?= htmlspecialchars($c['cargo']) ?>"
                                    data-ano="<?= !empty($c['ano']) ? (int)$c['ano'] : '' ?>"
                                    data-numero="<?= $numero > 0 ? (int)$numero : '' ?>"
                                    data-total="<?= (int)$c['total'] ?>">
                                🗑️
                            </button>
                        </td>
                    <?php endif; ?>
                </tr>
                <tr class="linha-detalhe" style="display:none;">
                    <td colspan="<?= $numColunas ?>"></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="<?= ($temAno ? 6 : 5) + 1 ?>">Total do filtro</td>
                <td class="num"><?= number_format($totalVotosFiltro, 0, ',', '.') ?></td>
                <td class="num">100,00%</td>
                <td></td>
                <?php if ($isAdmin): ?><td></td><?php endif; ?>
            </tr>
        </tfoot>
    </table>

    <p style="font-size:12px;color:#888;">
        Dica: clique em uma linha para expandir a distribuição por região e município
        (os dados são carregados sob demanda).
        <?php if ($isAdmin): ?>
            Como admin, você pode apagar todos os registros de um candidato pelo ícone 🗑️.
        <?php else: ?>
            O ícone 🗑️ só aparece para <code>barreto</code> / <code>vbarreto</code>.
            <a href="?debug_sessao=1">Ver sessão →</a>
        <?php endif; ?>
    </p>
<?php endif; ?>

<h2>Rankings</h2>

<div class="grid-2">
    <div class="card">
        <h3>Top 10 candidatos</h3>
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
                                    <?php else: ?>—<?php endif; ?>
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
                                    <?php else: ?>—<?php endif; ?>
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

<!-- ============================================================ -->
<!-- VOTAÇÃO POR MUNICÍPIO (DataTable)                            -->
<!-- ============================================================ -->
<h2>Votação por Município (filtrada)</h2>

<div class="dt-wrapper">
    <div class="dt-header">
        <h3>
            🏙️ Todos os municípios
            <span class="dt-badge"><?= count($municipiosDetalhado) ?> municípios</span>
        </h3>
        <div class="dt-total">
            Ordenado por <b>total de votos</b> (maior → menor)
        </div>
    </div>

    <div class="dt-filtros">
        <div class="filtro-item">
            <label for="filtroZona">🔷 Buscar zona:</label>
            <input type="text" id="filtroZona" placeholder="#Z123" autocomplete="off">
            <button type="button" class="filtro-clear" data-target="filtroZona" title="Limpar">×</button>
        </div>

        <?php if ($temSecao): ?>
        <div class="filtro-item">
            <label for="filtroSecao">🟢 Buscar seção:</label>
            <input type="text" id="filtroSecao" placeholder="#S187" autocomplete="off">
            <button type="button" class="filtro-clear" data-target="filtroSecao" title="Limpar">×</button>
        </div>
        <?php endif; ?>

        <button type="button" id="btnLimparTodosFiltros" class="filtro-limpar-todos">
            🧹 Limpar todos
        </button>

        <span class="filtro-info">
            Digite o número (ex.: <code>123</code>) ou com prefixo (ex.: <code>#Z123</code>).
            A busca global também aceita <code>#Z123</code> / <code>#S187</code>.
        </span>
    </div>

    <table id="tabela-municipios" class="display" style="width:100%">
        <thead>
            <tr>
                <th>#</th>
                <th>Município</th>
                <th>Região</th>
                <th class="num">Votos</th>
                <th class="num">% do filtro</th>
                <th class="num">Zonas</th>
                <th>Zonas (#Z)</th>
                <?php if ($temSecao): ?>
                    <th class="num">Seções</th>
                    <th>Seções (#S)</th>
                <?php endif; ?>
                <th class="num">Candidatos</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $posM = 1;
            foreach ($municipiosDetalhado as $m):
                $totalM = (int)$m['total'];
                $pctM   = $totalGeralFiltro > 0 ? ($totalM / $totalGeralFiltro) * 100 : 0;
                $qtdZ   = (int)($m['qtd_zonas']      ?? 0);
                $qtdS   = (int)($m['qtd_secoes']     ?? 0);
                $qtdC   = (int)($m['qtd_candidatos'] ?? 0);

                $tagsZ = renderTags($m['zonas_lista']  ?? '', '#Z', 'tag-zona');
                $tagsS = $temSecao
                       ? renderTags($m['secoes_lista'] ?? '', '#S', 'tag-secao')
                       : '';

                $searchZ = tagsSearchText($m['zonas_lista']  ?? '', '#Z');
                $searchS = $temSecao
                         ? tagsSearchText($m['secoes_lista'] ?? '', '#S')
                         : '';
            ?>
                <tr>
                    <td class="pos"><?= $posM++ ?></td>

                    <td data-search="<?= htmlspecialchars($m['nomeMunicipio']) ?>">
                        <strong><?= htmlspecialchars($m['nomeMunicipio']) ?></strong>
                    </td>

                    <td data-search="<?= htmlspecialchars($m['regiao']) ?>">
                        <span class="badge badge-regiao"><?= htmlspecialchars($m['regiao']) ?></span>
                    </td>

                    <td class="num" data-order="<?= $totalM ?>">
                        <strong><?= number_format($totalM, 0, ',', '.') ?></strong>
                    </td>

                    <td class="num" data-order="<?= $pctM ?>">
                        <?= number_format($pctM, 2, ',', '.') ?>%
                    </td>

                    <td class="num" data-order="<?= $qtdZ ?>"><?= $qtdZ ?></td>

                    <td data-search="<?= htmlspecialchars($searchZ) ?>">
                        <div class="tags-cell"><?= $tagsZ ?></div>
                    </td>

                    <?php if ($temSecao): ?>
                        <td class="num" data-order="<?= $qtdS ?>"><?= $qtdS ?></td>

                        <td data-search="<?= htmlspecialchars($searchS) ?>">
                            <div class="tags-cell"><?= $tagsS ?: '<span style="color:#bbb;">—</span>' ?></div>
                        </td>
                    <?php endif; ?>

                    <td class="num" data-order="<?= $qtdC ?>"><?= $qtdC ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" style="text-align:right;">Total</th>
                <th class="num"><?= number_format($totalGeralFiltro, 0, ',', '.') ?></th>
                <th class="num">100,00%</th>
                <th class="num"><?= number_format($totalZonas, 0, ',', '.') ?></th>
                <th></th>
                <?php if ($temSecao): ?>
                    <th></th>
                    <th></th>
                <?php endif; ?>
                <th></th>
            </tr>
        </tfoot>
    </table>
</div>

<?php endif; /* fim do bloco $mostrarDados */ ?>

<!-- MODAL — confirmação pelo NÚMERO (só admin + dados visíveis) -->
<?php if ($isAdmin && $mostrarDados): ?>
<div id="modal-apagar" aria-hidden="true">
    <div class="caixa">
        <div class="cabecalho">
            <div class="icone">⚠️</div>
            <h3>Confirmar exclusão permanente</h3>
        </div>
        <div class="corpo">
            Você está prestes a apagar <b>todos os registros</b> desta campanha na tabela
            <code>votos</code>. Esta ação <b>não pode ser desfeita</b>.

            <div class="destaque" id="modalDetalhe"></div>

            <div class="campo">
                <label for="confirmaNumero">
                    Para confirmar, digite o <b>número do candidato</b> abaixo:
                </label>
                <input type="text" id="confirmaNumero"
                       inputmode="numeric" pattern="[0-9]*"
                       maxlength="6" autocomplete="off" spellcheck="false"
                       placeholder="ex.: 13">
            </div>

            <div class="aviso-forte" id="avisoNumero">
                ⚠ O botão só será habilitado quando o número estiver correto.
            </div>
        </div>
        <div class="rodape">
            <button type="button" class="btn-cancelar" id="btnCancelar">Cancelar</button>
            <button type="button" class="btn-apagar" id="btnApagar" disabled>Apagar definitivamente</button>
        </div>
    </div>
</div>

<form method="post" id="formApagar" style="display:none;">
    <input type="hidden" name="acao" value="apagar_campanha">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="del_candidato" id="fDelCandidato">
    <input type="hidden" name="del_cargo"     id="fDelCargo">
    <input type="hidden" name="del_ano"       id="fDelAno">
</form>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>

<script>
    // ============================================================
    // Select2 (sem auto-submit)
    // ============================================================
    (function () {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;
        if (!jQuery('#candidato').length) return;

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

        // NENHUM auto-submit — o form só envia ao clicar em "Consultar".
    })();

    // ============================================================
    // DataTable — Votação por Município
    // ============================================================
    (function () {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.DataTable === 'undefined') return;
        if (!jQuery('#tabela-municipios').length) return;

        const dt = jQuery('#tabela-municipios').DataTable({
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/pt-BR.json'
            },
            order: [[3, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Todos']],
            autoWidth: false,
            stateSave: false,
            columnDefs: [
                { targets: 0, orderable: false, searchable: false }
            ]
        });

        const COL_ZONA  = 6;
        const COL_SECAO = <?= $temSecao ? 8 : -1 ?>;

        jQuery('#filtroZona').on('keyup change input', function () {
            const v = this.value.trim();
            if (v === '') { dt.column(COL_ZONA).search('').draw(); return; }
            const num = v.replace(/^#Z/i, '').trim();
            if (num === '') { dt.column(COL_ZONA).search('').draw(); return; }
            dt.column(COL_ZONA).search('#Z' + num, false, false).draw();
        });

        if (COL_SECAO >= 0) {
            jQuery('#filtroSecao').on('keyup change input', function () {
                const v = this.value.trim();
                if (v === '') { dt.column(COL_SECAO).search('').draw(); return; }
                const num = v.replace(/^#S/i, '').trim();
                if (num === '') { dt.column(COL_SECAO).search('').draw(); return; }
                dt.column(COL_SECAO).search('#S' + num, false, false).draw();
            });
        }

        jQuery('.filtro-clear').on('click', function () {
            const id = jQuery(this).data('target');
            jQuery('#' + id).val('').trigger('keyup');
            jQuery('#' + id).focus();
        });

        jQuery('#btnLimparTodosFiltros').on('click', function () {
            dt.search('');
            dt.columns().search('');
            jQuery('#filtroZona, #filtroSecao').val('');
            dt.draw();
        });
    })();

    // ============================================================
    // Detalhe sob demanda (lazy loading)
    // ============================================================
    (function () {
        const CACHE = {};
        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            })[c]);
        }
        function fmtNum(n) { return Number(n).toLocaleString('pt-BR'); }

        function renderDetalhe(dados) {
            let regioesHtml = '';
            (dados.por_regiao || []).forEach(function (r) {
                regioesHtml += '<tr><td>' + escapeHtml(r.regiao) + '</td>' +
                    '<td class="num">' + fmtNum(r.total) + '</td></tr>';
            });
            if (!regioesHtml) {
                regioesHtml = '<tr><td colspan="2" style="text-align:center;color:#999;">Sem dados</td></tr>';
            }

            let munHtml = '';
            (dados.por_municipio || []).forEach(function (m) {
                munHtml += '<tr><td>' + escapeHtml(m.nomeMunicipio) + '</td>' +
                    '<td>' + escapeHtml(m.regiao) + '</td>' +
                    '<td class="num">' + fmtNum(m.total) + '</td></tr>';
            });
            if (!munHtml) {
                munHtml = '<tr><td colspan="3" style="text-align:center;color:#999;">Sem dados</td></tr>';
            }

            return '<div class="grid-2">' +
                '<div class="card"><h3>Distribuição por região</h3>' +
                  '<table><thead><tr><th>Região</th>' +
                  '<th class="num">Votos</th></tr></thead>' +
                  '<tbody>' + regioesHtml + '</tbody></table></div>' +
                '<div class="card"><h3>Top 15 municípios</h3>' +
                  '<table><thead><tr><th>Município</th><th>Região</th>' +
                  '<th class="num">Votos</th></tr></thead>' +
                  '<tbody>' + munHtml + '</tbody></table></div>' +
                '</div>';
        }

        document.querySelectorAll('tr.linha-campanha').forEach(function (tr) {
            tr.style.cursor = 'pointer';
            tr.title = 'Clique para expandir';

            tr.addEventListener('click', function (e) {
                if (e.target.closest('a, button, input, select')) return;
                const detalhe = tr.nextElementSibling;
                if (!detalhe || !detalhe.classList.contains('linha-detalhe')) return;

                const visivel = detalhe.style.display !== 'none';
                if (visivel) { detalhe.style.display = 'none'; return; }

                detalhe.style.display = 'table-row';
                const celula = detalhe.querySelector('td[colspan]');
                const chave  = tr.dataset.chave || '';

                if (CACHE[chave]) { celula.innerHTML = CACHE[chave]; return; }

                celula.innerHTML =
                    '<div class="detalhe-loading"><span class="spinner"></span>Carregando detalhes…</div>';

                const qs = new URLSearchParams({
                    ajax: 'detalhe',
                    cand: tr.dataset.candidato || '',
                    cargo: tr.dataset.cargo || '',
                });
                if (tr.dataset.ano) qs.append('ano', tr.dataset.ano);

                fetch('candidatos_campanhas.php?' + qs.toString())
                    .then(r => r.json())
                    .then(j => {
                        if (!j.ok) {
                            celula.innerHTML =
                                '<div style="padding:20px;color:#b94a48;">Erro: ' +
                                escapeHtml(j.msg || 'desconhecido') + '</div>';
                            return;
                        }
                        const html = renderDetalhe(j);
                        CACHE[chave] = html;
                        celula.innerHTML = html;
                    })
                    .catch(() => {
                        celula.innerHTML =
                            '<div style="padding:20px;color:#b94a48;">Erro de rede.</div>';
                    });
            });
        });
    })();

    // ============================================================
    // Modal de exclusão (admin + dados visíveis)
    // ============================================================
    <?php if ($isAdmin && $mostrarDados): ?>
    (function () {
        const modal       = document.getElementById('modal-apagar');
        if (!modal) return;

        const elDet       = document.getElementById('modalDetalhe');
        const inputConf   = document.getElementById('confirmaNumero');
        const avisoNumero = document.getElementById('avisoNumero');
        const btnApagar   = document.getElementById('btnApagar');
        const btnCancelar = document.getElementById('btnCancelar');
        const formApagar  = document.getElementById('formApagar');
        const fDelCand    = document.getElementById('fDelCandidato');
        const fDelCargo   = document.getElementById('fDelCargo');
        const fDelAno     = document.getElementById('fDelAno');

        let alvoAtual = null;
        const fmtNum = n => Number(n).toLocaleString('pt-BR');
        const escHtml = s => String(s).replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        })[c]);

        function abrirModal(dados) {
            alvoAtual = dados;
            const temNum    = (dados.numero && String(dados.numero).trim() !== '');
            const numeroRef = temNum ? String(dados.numero) : '0';

            elDet.innerHTML =
                '<b>Candidato:</b> ' + escHtml(dados.candidato) + '<br>' +
                '<b>Cargo:</b> ' + escHtml(dados.cargo) + '<br>' +
                (dados.ano ? '<b>Ano:</b> ' + dados.ano + '<br>' : '') +
                '<b>Número:</b> ' +
                  (temNum ? '<span class="destaque-numero">' + escHtml(numeroRef) + '</span>'
                          : '<span style="color:#b94a48;font-weight:bold;">(sem número cadastrado)</span>') +
                '<br><b>Votos que serão apagados:</b> ' + fmtNum(dados.total);

            inputConf.value = '';
            inputConf.dataset.numeroRef = numeroRef;
            btnApagar.disabled = true;
            btnApagar.textContent = 'Apagar definitivamente';

            if (temNum) {
                avisoNumero.textContent = '⚠ O botão só será habilitado quando o número estiver correto.';
            } else {
                avisoNumero.innerHTML = '⚠ Este candidato não tem número. Digite <b>0</b>.';
            }

            modal.classList.add('ativo');
            modal.setAttribute('aria-hidden', 'false');
            setTimeout(() => inputConf.focus(), 80);
        }

        function fecharModal() {
            modal.classList.remove('ativo');
            modal.setAttribute('aria-hidden', 'true');
            alvoAtual = null;
            inputConf.value = '';
            btnApagar.disabled = true;
        }

        inputConf.addEventListener('input', () => {
            inputConf.value = inputConf.value.replace(/\D/g, '');
            if (!alvoAtual) return;
            const ref = inputConf.dataset.numeroRef || '0';
            btnApagar.disabled = (inputConf.value.trim() !== ref);
        });

        inputConf.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !btnApagar.disabled) { e.preventDefault(); btnApagar.click(); }
            if (e.key === 'Escape') fecharModal();
        });

        btnCancelar.addEventListener('click', fecharModal);
        modal.addEventListener('click', (e) => { if (e.target === modal) fecharModal(); });

        btnApagar.addEventListener('click', () => {
            if (!alvoAtual || btnApagar.disabled) return;
            fDelCand.value  = alvoAtual.candidato;
            fDelCargo.value = alvoAtual.cargo;
            fDelAno.value   = alvoAtual.ano || '';
            btnApagar.disabled = true;
            btnApagar.textContent = 'Apagando…';
            formApagar.submit();
        });

        document.querySelectorAll('.btn-lixeira').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const dados = {
                    candidato: btn.dataset.candidato,
                    cargo:     btn.dataset.cargo,
                    ano:       btn.dataset.ano,
                    numero:    btn.dataset.numero,
                    total:     parseInt(btn.dataset.total, 10) || 0,
                };
                const numExib = dados.numero ? dados.numero : '(sem número)';
                const msg1 =
                    'Deseja apagar TODOS os registros do candidato:\n\n' +
                    '  ' + dados.candidato + '\n' +
                    '  Número: ' + numExib + '\n' +
                    '  Cargo: ' + dados.cargo + (dados.ano ? ' | Ano: ' + dados.ano : '') + '\n\n' +
                    'Total: ' + fmtNum(dados.total) + ' votos.\n\n' +
                    'Esta ação NÃO pode ser desfeita. Continuar?';
                if (!confirm(msg1)) return;
                abrirModal(dados);
            });
        });
    })();
    <?php endif; ?>
</script>

</body>
</html>