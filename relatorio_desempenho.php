<?php
// ------------------------------------------------------------
// relatorio_desempenho.php
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';
// Define o fuso horário para o horário de Brasília
date_default_timezone_set('America/Sao_Paulo');

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ------------------------------------------------------------
// Fallback: usuário logado
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
    $__classePerfil = 'super'; $__rotuloPerfil = 'SUPERADMIN';
} elseif ($__ehAdminNivel) {
    $__classePerfil = 'admin'; $__rotuloPerfil = 'ADMIN';
} else {
    $__classePerfil = 'visitante'; $__rotuloPerfil = 'VISITANTE';
}

// ============================================================
// DETECÇÃO DE ESTRUTURAS
// ============================================================

// ---------- colunas de votos ----------
$colunasVotos = [];
foreach ($pdo->query("SHOW COLUMNS FROM votos") as $c) $colunasVotos[] = $c['Field'];

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
        $field = $c['Field']; $type = strtolower($c['Type']);
        if (in_array($field, ['numeroZona','numeroSecao','id'], true)) continue;
        if (str_contains($type,'int')||str_contains($type,'decimal')
            ||str_contains($type,'float')||str_contains($type,'double')) {
            $COL_VOTOS = $field; break;
        }
    }
}
if ($COL_VOTOS === null) die("Não foi possível detectar a coluna de votos em `votos`.");

$temTipoVotavel   = in_array('cdTipoVotavel',  $colunasVotos, true);
$temCargoPergunta = in_array('cargoPergunta', $colunasVotos, true);

// ---------- colunas de coordenadores ----------
$colunasCoord = [];
foreach ($pdo->query("SHOW COLUMNS FROM coordenadores") as $c) $colunasCoord[] = $c['Field'];
$temNumeroCandCoord = in_array('numero_candidato',  $colunasCoord, true);
$temAnoCampanha     = in_array('ano_campanha',      $colunasCoord, true);
$temExpectativaCoord= in_array('expectativa_votos', $colunasCoord, true);

// ---------- colunas de liderancas ----------
$colunasLider = [];
foreach ($pdo->query("SHOW COLUMNS FROM liderancas") as $c) $colunasLider[] = $c['Field'];
$temExpectativaLider = in_array('expectativa_votos', $colunasLider, true);

// ---------- tabelas opcionais ----------
$temLiderSecao = false;
$temCoordSecao = false;
try { $pdo->query("SELECT 1 FROM lider_secao LIMIT 1");     $temLiderSecao = true; } catch (Exception $e) {}
try { $pdo->query("SELECT 1 FROM coordenador_secao LIMIT 1"); $temCoordSecao = true; } catch (Exception $e) {}

// ============================================================
// HELPERS
// ============================================================
function classificarSituacao(int $esperado, int $urnas): array {
    if ($esperado <= 0) return ['rotulo' => 'Sem meta', 'classe' => 'sit-sem', 'icone' => '—'];
    if ($urnas >= $esperado) {
        return $urnas > $esperado
            ? ['rotulo' => 'Acima',   'classe' => 'sit-acima',  'icone' => '▲']
            : ['rotulo' => 'Na meta', 'classe' => 'sit-meta',   'icone' => '✔'];
    }
    $pct = ($urnas / $esperado) * 100;
    return $pct >= 70
        ? ['rotulo' => 'Quase',  'classe' => 'sit-quase',  'icone' => '≈']
        : ['rotulo' => 'Abaixo', 'classe' => 'sit-abaixo', 'icone' => '▼'];
}
function fmtPct(?float $p): string {
    return $p === null ? '—' : number_format($p, 1, ',', '.') . '%';
}
function fmtNum(int|float|null $n): string {
    return $n === null ? '—' : number_format((float)$n, 0, ',', '.');
}
function fmtSecao(int $z, int $s): string {
    return '#Z' . $z . '-S' . $s;
}

// ============================================================
// FILTROS
// ============================================================
$filtroAno    = (int)($_GET['ano']    ?? 0);
$filtroCand   = (int)($_GET['cand']   ?? 0);   // agora é o número da urna
$filtroCoord  = (int)($_GET['coord']  ?? 0);
$filtroLider  = (int)($_GET['lider']  ?? 0);
$filtroStatus = $_GET['status'] ?? 'todas';
if (!in_array($filtroStatus, ['todas','ativas','inativas'], true)) $filtroStatus = 'todas';

$mostrarLista = ($filtroAno > 0 && $filtroCand > 0);

// lista de anos
$listaAnos = $pdo->query(
    "SELECT DISTINCT ano FROM votos WHERE ano > 0 ORDER BY ano DESC"
)->fetchAll(PDO::FETCH_COLUMN);

// lista de candidatos do ano selecionado
$listaCandidatos = [];
if ($filtroAno > 0) {
    $stmt = $pdo->prepare(
        "SELECT numero,
                MIN(nomeCandidatoResp) AS nome,
                MIN(nomeCargoPerg)     AS cargo
           FROM votos
          WHERE ano = :ano AND numero > 0 AND nomeCandidatoResp <> ''
          GROUP BY numero
          ORDER BY nome"
    );
    $stmt->execute([':ano' => $filtroAno]);
    $listaCandidatos = $stmt->fetchAll();
}

// lista de coordenadores do ano/candidato
$listaCoords = [];
if ($mostrarLista) {
    $sql = "SELECT c.id, c.nome, c.ativo, c.numero_candidato, c.ano_campanha
              FROM coordenadores c
             WHERE 1=1";
    $pars = [];
    if ($temAnoCampanha)         { $sql .= " AND c.ano_campanha = :ano";     $pars[':ano'] = $filtroAno; }
    if ($temNumeroCandCoord)     { $sql .= " AND c.numero_candidato = :num"; $pars[':num'] = $filtroCand; }
    $sql .= " ORDER BY c.ativo DESC, c.nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($pars);
    $listaCoords = $stmt->fetchAll();
}

// lista de lideranças
$listaLideres = [];
if ($mostrarLista) {
    $sql = "SELECT l.id, l.nome, l.coordenador_id, l.ativo
              FROM liderancas l
              INNER JOIN coordenadores c ON c.id = l.coordenador_id
             WHERE 1=1";
    $pars = [];
    if ($temAnoCampanha)     { $sql .= " AND c.ano_campanha = :ano";     $pars[':ano'] = $filtroAno; }
    if ($temNumeroCandCoord) { $sql .= " AND c.numero_candidato = :num"; $pars[':num'] = $filtroCand; }
    $sql .= " ORDER BY c.nome, l.nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($pars);
    $listaLideres = $stmt->fetchAll();
}

// se escolheu uma liderança, força o coordenador dela
if ($filtroLider > 0) {
    foreach ($listaLideres as $ll) {
        if ((int)$ll['id'] === $filtroLider) {
            $filtroCoord = (int)$ll['coordenador_id'];
            break;
        }
    }
}

// ============================================================
// FILTRO PADRONIZADO DE VOTOS DO CANDIDATO
// (numero + ano + nome preenchido + tipo + cargo principal)
// ============================================================
function filtroCandVotos(string $aliasCoord, string $colVotos, bool $temTipo, bool $temCargo): string {
    $f  = " AND v.numero = {$aliasCoord}.numero_candidato ";
    $f .= " AND v.ano    = {$aliasCoord}.ano_campanha ";
    $f .= " AND v.nomeCandidatoResp <> '' ";
    if ($temTipo)  $f .= " AND v.cdTipoVotavel = 1 ";
    if ($temCargo) {
        $f .= " AND v.cargoPergunta = (
                    SELECT v2.cargoPergunta FROM votos v2
                     WHERE v2.numero = {$aliasCoord}.numero_candidato
                       AND v2.ano    = {$aliasCoord}.ano_campanha
                       AND v2.nomeCandidatoResp <> ''
                     GROUP BY v2.cargoPergunta
                     ORDER BY SUM(v2.`$colVotos`) DESC
                     LIMIT 1
                 ) ";
    }
    return $f;
}

// ============================================================
// 1) LIDERANÇAS
// ============================================================
$liderancas = [];
if ($mostrarLista) {
    $filtroCandVotos = filtroCandVotos('c', $COL_VOTOS, $temTipoVotavel, $temCargoPergunta);

    $subSecoesUrnas = $temLiderSecao
        ? "(SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
              FROM votos v
             WHERE 1=1 $filtroCandVotos
               AND (v.numeroZona, v.numeroSecao) IN (
                    SELECT ls.numeroZona, ls.numeroSecao
                      FROM lider_secao ls
                     WHERE ls.lider_id = l.id
               ))"
        : "0";
    $subQtdSecoes = $temLiderSecao
        ? "(SELECT COUNT(*) FROM lider_secao ls WHERE ls.lider_id = l.id)"
        : "0";

    $where  = [];
    $params = [];
    if ($temAnoCampanha)     { $where[] = "c.ano_campanha = :ano";     $params[':ano'] = $filtroAno; }
    if ($temNumeroCandCoord) { $where[] = "c.numero_candidato = :num"; $params[':num'] = $filtroCand; }
    if ($filtroCoord > 0)    { $where[] = "c.id = :coord";             $params[':coord'] = $filtroCoord; }
    if ($filtroLider > 0)    { $where[] = "l.id = :lider";             $params[':lider'] = $filtroLider; }
    if ($filtroStatus === 'ativas')   $where[] = "l.ativo = 1";
    if ($filtroStatus === 'inativas') $where[] = "l.ativo = 0";
    $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $expL = $temExpectativaLider ? "l.expectativa_votos" : "0";

    $sqlLid = "
        SELECT l.id, l.nome, l.ativo,
               $expL AS expectativa_votos,
               c.id   AS coord_id,
               c.nome AS coord_nome,
               c.ativo AS coord_ativo,
               c.numero_candidato, c.ano_campanha,
               $subSecoesUrnas AS votos_urnas,
               $subQtdSecoes   AS qtd_secoes
        FROM liderancas l
        INNER JOIN coordenadores c ON c.id = l.coordenador_id
        $whereStr
        ORDER BY c.nome, l.nome
    ";
    $stmt = $pdo->prepare($sqlLid);
    $stmt->execute($params);
    $liderancas = $stmt->fetchAll();
}

foreach ($liderancas as &$l) {
    $esp = (int)($l['expectativa_votos'] ?? 0);
    $urn = (int)($l['votos_urnas'] ?? 0);
    $l['esperado']  = $esp;
    $l['urnas']     = $urn;
    $l['diferenca'] = $urn - $esp;
    $l['pct']       = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
    $l['situacao']  = classificarSituacao($esp, $urn);
}
unset($l);

// ============================================================
// 2) COORDENADORES
// ============================================================
$coordenadores = [];
if ($mostrarLista) {
    $filtroCandVotos = filtroCandVotos('c', $COL_VOTOS, $temTipoVotavel, $temCargoPergunta);

    // Votos esperados do coordenador = apenas a expectativa dele
    $cExpectExpr = $temExpectativaCoord ? "c.expectativa_votos" : "0";

    // Votos urnas do coordenador = soma das seções em coordenador_secao
    if ($temCoordSecao) {
        $subCoordUrnas = "(SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
                             FROM votos v
                            WHERE 1=1 $filtroCandVotos
                              AND (v.numeroZona, v.numeroSecao) IN (
                                   SELECT cs.numeroZona, cs.numeroSecao
                                     FROM coordenador_secao cs
                                    WHERE cs.coordenador_id = c.id
                              ))";
        $subCoordZonas = "(SELECT COUNT(DISTINCT cs.numeroZona)
                             FROM coordenador_secao cs
                            WHERE cs.coordenador_id = c.id)";
        $subCoordSecoes = "(SELECT COUNT(*) FROM coordenador_secao cs
                            WHERE cs.coordenador_id = c.id)";
    } else {
        // fallback: usar lider_secao se não existir coordenador_secao
        $subCoordUrnas = $temLiderSecao
            ? "(SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
                  FROM votos v
                 WHERE 1=1 $filtroCandVotos
                   AND (v.numeroZona, v.numeroSecao) IN (
                        SELECT DISTINCT ls.numeroZona, ls.numeroSecao
                          FROM lider_secao ls
                          INNER JOIN liderancas l2 ON l2.id = ls.lider_id
                         WHERE l2.coordenador_id = c.id AND l2.ativo = 1
                   ))"
            : "0";
        $subCoordZonas  = "0";
        $subCoordSecoes = "0";
    }

    $where  = [];
    $params = [];
    if ($temAnoCampanha)     { $where[] = "c.ano_campanha = :ano";     $params[':ano'] = $filtroAno; }
    if ($temNumeroCandCoord) { $where[] = "c.numero_candidato = :num"; $params[':num'] = $filtroCand; }
    if ($filtroCoord > 0)    { $where[] = "c.id = :coord";             $params[':coord'] = $filtroCoord; }
    $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sqlCoord = "
        SELECT c.id, c.nome, c.ativo, c.numero_candidato, c.ano_campanha,
               $cExpectExpr     AS esperado,
               $subCoordUrnas   AS urnas,
               $subCoordZonas   AS qtd_zonas,
               $subCoordSecoes  AS qtd_secoes,
               (SELECT COUNT(*) FROM liderancas l WHERE l.coordenador_id = c.id) AS qtd_lid,
               (SELECT COUNT(*) FROM liderancas l WHERE l.coordenador_id = c.id AND l.ativo = 1) AS qtd_lid_ativ,
               (SELECT MIN(nomeCandidatoResp) FROM votos v
                 WHERE v.numero = c.numero_candidato AND v.ano = c.ano_campanha) AS candidato,
               (SELECT MIN(nomeCargoPerg) FROM votos v
                 WHERE v.numero = c.numero_candidato AND v.ano = c.ano_campanha) AS candidato_cargo
        FROM coordenadores c
        $whereStr
        ORDER BY c.nome
    ";
    $stmt = $pdo->prepare($sqlCoord);
    $stmt->execute($params);
    $coordenadores = $stmt->fetchAll();
}

foreach ($coordenadores as &$c) {
    $esp = (int)$c['esperado'];
    $urn = (int)$c['urnas'];
    $c['diferenca'] = $urn - $esp;
    $c['pct']       = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
    $c['situacao']  = classificarSituacao($esp, $urn);

    $c['qtd_acima'] = 0; $c['qtd_meta'] = 0;
    $c['qtd_quase'] = 0; $c['qtd_abaixo'] = 0; $c['qtd_sem'] = 0;
    foreach ($liderancas as $l) {
        if ((int)$l['coord_id'] !== (int)$c['id']) continue;
        switch ($l['situacao']['classe']) {
            case 'sit-acima':  $c['qtd_acima']++;  break;
            case 'sit-meta':   $c['qtd_meta']++;   break;
            case 'sit-quase':  $c['qtd_quase']++;  break;
            case 'sit-abaixo': $c['qtd_abaixo']++; break;
            default:           $c['qtd_sem']++;    break;
        }
    }
}
unset($c);

// ============================================================
// 3) TOTAIS
// ============================================================
$tot = [
    'coords' => count($coordenadores), 'coords_ativos' => 0,
    'liderancas' => count($liderancas), 'liderancas_ativas' => 0,
    'esperado' => 0, 'urnas' => 0,
    'acima' => 0, 'meta' => 0, 'quase' => 0, 'abaixo' => 0, 'sem' => 0,
];
foreach ($coordenadores as $c) if ((int)$c['ativo'] === 1) $tot['coords_ativos']++;

foreach ($liderancas as $l) {
    if ((int)$l['ativo'] === 1) {
        $tot['liderancas_ativas']++;
        $tot['esperado'] += (int)$l['esperado'];
        $tot['urnas']    += (int)$l['urnas'];
    }
    switch ($l['situacao']['classe']) {
        case 'sit-acima':  $tot['acima']++;  break;
        case 'sit-meta':   $tot['meta']++;   break;
        case 'sit-quase':  $tot['quase']++;  break;
        case 'sit-abaixo': $tot['abaixo']++; break;
        default:           $tot['sem']++;    break;
    }
}
$tot['diferenca'] = $tot['urnas'] - $tot['esperado'];
$tot['pct'] = $tot['esperado'] > 0 ? round(($tot['urnas'] / $tot['esperado']) * 100, 1) : null;

// ============================================================
// 4) RANKINGS
// ============================================================
$rankLid = array_values(array_filter($liderancas, fn($l) =>
    (int)$l['ativo'] === 1 && (int)$l['esperado'] > 0));
usort($rankLid, fn($a, $b) => ($b['pct'] ?? 0) <=> ($a['pct'] ?? 0));

$rankCoord = array_values(array_filter($coordenadores, fn($c) =>
    (int)$c['ativo'] === 1 && (int)$c['esperado'] > 0));
usort($rankCoord, fn($a, $b) => ($b['pct'] ?? 0) <=> ($a['pct'] ?? 0));

// ============================================================
// 5) TOP SEÇÕES
// ============================================================
$topSecoes = [];
if ($temCoordSecao && $mostrarLista) {
    $filtroCandVotos = filtroCandVotos('c', $COL_VOTOS, $temTipoVotavel, $temCargoPergunta);

    $where  = [];
    $params = [];
    if ($temAnoCampanha)     { $where[] = "c.ano_campanha = :ano";     $params[':ano'] = $filtroAno; }
    if ($temNumeroCandCoord) { $where[] = "c.numero_candidato = :num"; $params[':num'] = $filtroCand; }
    if ($filtroCoord > 0)    { $where[] = "c.id = :coord";             $params[':coord'] = $filtroCoord; }
    $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sqlSecoes = "
        SELECT cs.numeroZona, cs.numeroSecao,
               (SELECT COUNT(DISTINCT ls.lider_id)
                  FROM lider_secao ls
                 WHERE ls.coordenador_id = cs.coordenador_id
                   AND ls.numeroZona = cs.numeroZona
                   AND ls.numeroSecao = cs.numeroSecao) AS qtd_lideres,
               (SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
                  FROM votos v
                 WHERE v.numeroZona = cs.numeroZona
                   AND v.numeroSecao = cs.numeroSecao
                   $filtroCandVotos) AS votos
        FROM coordenador_secao cs
        INNER JOIN coordenadores c ON c.id = cs.coordenador_id
        $whereStr
        GROUP BY cs.coordenador_id, cs.numeroZona, cs.numeroSecao
        ORDER BY votos DESC
        LIMIT 15
    ";
    $stmt = $pdo->prepare($sqlSecoes);
    $stmt->execute($params);
    $topSecoes = $stmt->fetchAll();
}

// ============================================================
// 6) SEÇÕES DESCOBERTAS (dentro de coordenador_secao sem lider)
// ============================================================
$secoesDescobertas     = [];
$qtdDescobertasTotal   = 0;
$votosDescobertosTotal = 0;

if ($temCoordSecao && $temLiderSecao && $mostrarLista && $filtroLider === 0) {
    $filtroCandVotos = filtroCandVotos('c', $COL_VOTOS, $temTipoVotavel, $temCargoPergunta);

    $where  = [];
    $params = [];
    if ($temAnoCampanha)     { $where[] = "c.ano_campanha = :ano";     $params[':ano'] = $filtroAno; }
    if ($temNumeroCandCoord) { $where[] = "c.numero_candidato = :num"; $params[':num'] = $filtroCand; }
    if ($filtroCoord > 0)    { $where[] = "c.id = :coord";             $params[':coord'] = $filtroCoord; }
    $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sqlDescobertas = "
        SELECT cs.numeroZona, cs.numeroSecao,
               (SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
                  FROM votos v
                 WHERE v.numeroZona = cs.numeroZona
                   AND v.numeroSecao = cs.numeroSecao
                   $filtroCandVotos) AS votos
        FROM coordenador_secao cs
        INNER JOIN coordenadores c ON c.id = cs.coordenador_id
        $whereStr
          AND NOT EXISTS (
              SELECT 1 FROM lider_secao ls
               WHERE ls.coordenador_id = cs.coordenador_id
                 AND ls.numeroZona = cs.numeroZona
                 AND ls.numeroSecao = cs.numeroSecao
          )
        ORDER BY votos DESC
        LIMIT 20
    ";
    $stmt = $pdo->prepare($sqlDescobertas);
    $stmt->execute($params);
    $secoesDescobertas = $stmt->fetchAll();

    $sqlCountDesc = "
        SELECT COUNT(*) AS qtd,
               COALESCE(SUM((
                   SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
                     FROM votos v
                    WHERE v.numeroZona = cs.numeroZona
                      AND v.numeroSecao = cs.numeroSecao
                      $filtroCandVotos
               )), 0) AS votos
        FROM coordenador_secao cs
        INNER JOIN coordenadores c ON c.id = cs.coordenador_id
        $whereStr
          AND NOT EXISTS (
              SELECT 1 FROM lider_secao ls
               WHERE ls.coordenador_id = cs.coordenador_id
                 AND ls.numeroZona = cs.numeroZona
                 AND ls.numeroSecao = cs.numeroSecao
          )
    ";
    $stmt = $pdo->prepare($sqlCountDesc);
    $stmt->execute($params);
    $rowDesc = $stmt->fetch();
    $qtdDescobertasTotal   = (int)($rowDesc['qtd']   ?? 0);
    $votosDescobertosTotal = (int)($rowDesc['votos'] ?? 0);
}

// ============================================================
// RÓTULOS DOS FILTROS
// ============================================================
$nomeCandFiltro = '';
if ($filtroCand > 0) {
    foreach ($listaCandidatos as $cc) {
        if ((int)$cc['numero'] === $filtroCand) { $nomeCandFiltro = $cc['nome']; break; }
    }
}
$nomeCoordFiltro = '';
if ($filtroCoord > 0) {
    foreach ($listaCoords as $cc) {
        if ((int)$cc['id'] === $filtroCoord) { $nomeCoordFiltro = $cc['nome']; break; }
    }
}
$nomeLiderFiltro = '';
if ($filtroLider > 0) {
    foreach ($listaLideres as $ll) {
        if ((int)$ll['id'] === $filtroLider) { $nomeLiderFiltro = $ll['nome']; break; }
    }
}
$temFiltro = ($filtroAno > 0 || $filtroCand > 0 || $filtroCoord > 0 || $filtroLider > 0 || $filtroStatus !== 'todas');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatório de Desempenho</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom:4px; }
    h2.sec { font-size:16px; color:#6f42c1; margin:26px 0 10px; }
    .info-col { font-size:11px; color:#666; margin-bottom:16px; }
    .info-col code { background:#f4f4f4; padding:1px 5px; border-radius:3px; }
    .info-col .aviso { color:#856404; background:#fff3cd;
        padding:2px 8px; border-radius:6px; font-weight:bold; margin-left:6px; }

    .barra-usuario {
        display: flex; align-items: center; gap: 12px;
        background: #fff; padding: 10px 16px; border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.08);
        margin-bottom: 18px; font-size: 13px;
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
    .link-debug { margin-left: auto; color: #6c757d; text-decoration: none; font-size: 12px; }
    .link-debug:hover { color: #0d6efd; text-decoration: underline; }

    .filtros {
        background:#fff; border-radius:8px; padding:16px 20px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
        display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end;
        border-left:5px solid #0069d9;
    }
    .filtros .campo { display:flex; flex-direction:column; min-width:200px; flex:1; }
    .filtros .campo label {
        font-size:11px; font-weight:bold; color:#555;
        text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;
    }
    .filtros .campo select {
        padding:8px 10px; border:1px solid #ccc; border-radius:4px;
        font-size:13px; background:#fff; font-family:inherit; width:100%;
        cursor:pointer;
    }
    .filtros .campo select:focus {
        outline:none; border-color:#0069d9;
        box-shadow:0 0 0 2px rgba(0,105,217,.15);
    }
    .filtros .acoes { display:flex; gap:8px; align-items:flex-end; }
    .btn-filtrar {
        background:#0069d9; color:#fff; padding:8px 16px;
        border:none; border-radius:4px; cursor:pointer;
        font-size:13px; font-weight:bold; font-family:inherit;
    }
    .btn-filtrar:hover { background:#0053b3; }
    .btn-limpar {
        background:#fff; color:#666; border:1px solid #ccc;
        padding:8px 14px; border-radius:4px; cursor:pointer;
        font-size:13px; font-family:inherit; text-decoration:none;
        display:inline-flex; align-items:center;
    }
    .btn-limpar:hover { background:#f4f4f4; color:#333; }

    .badge-filtro {
        background:#e6f0fa; color:#0069d9; padding:4px 12px;
        border-radius:12px; font-size:12px; font-weight:bold;
        display:inline-flex; align-items:center; gap:6px;
        margin-right:6px; margin-bottom:6px;
    }
    .badge-filtro .x {
        cursor:pointer; color:#0069d9; text-decoration:none;
        font-size:14px; line-height:1; font-weight:bold;
    }
    .badge-filtro .x:hover { color:#dc3545; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:15px; }
    .kpi {
        background:#fff; border-radius:8px; padding:14px 22px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:170px;
        border-left:5px solid #6f42c1;
    }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:26px; font-weight:bold; color:#6f42c1; margin-top:4px; }
    .kpi .mini   { font-size:11px; color:#999; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.azul    { border-left-color:#0069d9; } .kpi.azul .valor  { color:#0069d9; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.teal    { border-left-color:#17a2b8; } .kpi.teal .valor  { color:#17a2b8; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi.amarelo { border-left-color:#ffc107; } .kpi.amarelo .valor { color:#b8860b; }

    table { border-collapse:collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; font-size:13px; }
    th { background:#6f42c1; color:#fff; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#f6f0ff; }
    tr.linha-total { background:#f6f0ff; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #6f42c1; }
    tr.destaque-ouro  { background:#fffbe6; }
    tr.destaque-prata { background:#f4f4f4; }
    tr.destaque-bronze{ background:#fdf2e3; }
    .num { text-align:right; } .centro { text-align:center; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-zona  { background:#f0e6ff; color:#6f42c1; }
    .badge-coord { background:#e6f0fa; color:#0069d9; }
    .badge-cand  { background:#e7d9ff; color:#4a1d96; font-weight:bold; }
    .badge-ano   { background:#ede7f6; color:#4527a0; }
    .badge-ativo { background:#d4f5dd; color:#1c7a34; }
    .badge-inativo{ background:#f0f0f0; color:#888; }
    .badge-votos { background:#fff0e0; color:#c25b00; }
    .badge-urnas { background:#d9edf7; color:#0c5460; }
    .badge-pos   { background:#d4f5dd; color:#1c7a34; }
    .badge-neg   { background:#fdecea; color:#a71d2a; }
    .badge-zero  { background:#f0f0f0; color:#666; }
    .badge-pct-ok  { background:#d4f5dd; color:#1c7a34; }
    .badge-pct-med { background:#fff3cd; color:#856404; }
    .badge-pct-ruim{ background:#fdecea; color:#a71d2a; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    .sit { display:inline-block; padding:3px 10px; border-radius:12px;
           font-size:11px; font-weight:bold; white-space:nowrap; }
    .sit-acima  { background:#d4f5dd; color:#0e5c22; border:1px solid #87c99a; }
    .sit-meta   { background:#28a745; color:#fff; }
    .sit-quase  { background:#fff3cd; color:#856404; border:1px solid #f0d175; }
    .sit-abaixo { background:#fdecea; color:#a71d2a; border:1px solid #e4a4ab; }
    .sit-sem    { background:#f0f0f0; color:#888; }
    .sit .ico   { font-size:12px; margin-right:3px; }

    .barra { position:relative; height:20px; background:#eee; border-radius:10px;
             overflow:hidden; min-width:120px; }
    .barra .fill { height:100%; transition:width .3s; }
    .barra .fill.ok   { background:linear-gradient(90deg,#28a745,#5ec27a); }
    .barra .fill.med  { background:linear-gradient(90deg,#ffc107,#ffdb70); }
    .barra .fill.ruim { background:linear-gradient(90deg,#dc3545,#e87682); }
    .barra .fill.zero { background:#ccc; }
    .barra .txt { position:absolute; inset:0; text-align:center;
                  font-size:11px; font-weight:bold; line-height:20px; color:#222; }

    .medalha { font-size:16px; }

    .lista-secoes {
        font-family:'Courier New', monospace; font-size:11px; color:#0a3d62;
        background:#f4f9ff; border-left:3px solid #0069d9; padding:5px 7px;
        border-radius:4px; max-height:70px; overflow:auto; line-height:1.6;
        word-break:break-all;
    }
    .lista-secoes .tag {
        display:inline-block; background:#e6f0fa; color:#0069d9;
        padding:1px 5px; border-radius:3px; margin:1px 3px 1px 0;
        font-size:10.5px; font-weight:bold;
    }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }
    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
    @media (max-width: 900px) { .grid-2 { grid-template-columns:1fr; } }

    .card { background:#fff; border-radius:8px; padding:16px 20px;
            box-shadow:0 1px 3px rgba(0,0,0,.1); }
    .card h3 { margin:0 0 12px; font-size:14px; color:#6f42c1;
               text-transform:uppercase; letter-spacing:1px; }

    .btn-imprimir { background:#0069d9; color:#fff; padding:8px 14px;
                    border-radius:4px; border:none; cursor:pointer;
                    font-size:14px; margin-right:8px; }
    .btn-imprimir:hover { background:#0053b3; }
    @media print {
        .no-print { display:none !important; }
        body { background:#fff; margin:0; }
        table { page-break-inside:auto; }
        tr    { page-break-inside:avoid; }
    }

    /* coluna de seções atribuídas */
    .col-secoes { max-width:280px; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar no-print">&larr; Voltar ao menu</a>

<h1>Relatório de Desempenho</h1>

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
</div>

<div style="display:flex; justify-content:space-between; align-items:flex-start;">
    <div>
        <p class="info-col" style="margin-top:0;">
            Coluna de votos: <strong><?= htmlspecialchars($COL_VOTOS) ?></strong> ·
            Expectativa em <code>coordenadores</code>/<code>liderancas</code>:
            <?php if ($temExpectativaCoord || $temExpectativaLider): ?><strong>ok</strong>
            <?php else: ?><span class="aviso">não existe — usando 0</span><?php endif; ?>
            ·
            <code>coordenador_secao</code>:
            <?php if ($temCoordSecao): ?><strong>existe</strong>
            <?php else: ?><span class="aviso">não existe</span><?php endif; ?>
            ·
            <code>lider_secao</code>:
            <?php if ($temLiderSecao): ?><strong>existe</strong>
            <?php else: ?><span class="aviso">não existe</span><?php endif; ?>
            · Gerado em <?= date('d/m/Y H:i') ?>
        </p>
    </div>
    <button class="btn-imprimir no-print" onclick="window.print()">🖨 Imprimir</button>
</div>

<!-- ============================================================
     PAINEL DE FILTROS
     ============================================================ -->
<form class="filtros no-print" method="get" action="relatorio_desempenho.php" id="form-filtros">

    <div class="campo">
        <label for="f-ano">Ano da campanha *</label>
        <select name="ano" id="f-ano" required>
            <option value="0">— Selecione —</option>
            <?php foreach ($listaAnos as $a): ?>
                <option value="<?= (int)$a ?>" <?= ((int)$a === $filtroAno) ? 'selected' : '' ?>>
                    <?= (int)$a ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo">
        <label for="f-cand">Candidato *</label>
        <select name="cand" id="f-cand">
            <option value="0">— Selecione o ano primeiro —</option>
            <?php foreach ($listaCandidatos as $c): ?>
                <option value="<?= (int)$c['numero'] ?>"
                    <?= ((int)$c['numero'] === $filtroCand) ? 'selected' : '' ?>>
                    Nº <?= (int)$c['numero'] ?> — <?= htmlspecialchars($c['nome']) ?>
                    <?= $c['cargo'] ? ' (' . htmlspecialchars($c['cargo']) . ')' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($mostrarLista): ?>
    <div class="campo">
        <label for="f-coord">Coordenador</label>
        <select name="coord" id="f-coord">
            <option value="0">— Todos os coordenadores —</option>
            <?php foreach ($listaCoords as $cc): ?>
                <option value="<?= (int)$cc['id'] ?>"
                    <?= $filtroCoord === (int)$cc['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cc['nome']) ?>
                    <?= (int)$cc['ativo'] === 0 ? ' (inativo)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo">
        <label for="f-lider">Liderança</label>
        <select name="lider" id="f-lider">
            <option value="0">— Todas as lideranças —</option>
            <?php foreach ($listaLideres as $ll): ?>
                <option value="<?= (int)$ll['id'] ?>"
                        data-coord="<?= (int)$ll['coordenador_id'] ?>"
                    <?= $filtroLider === (int)$ll['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ll['nome']) ?>
                    <?= (int)$ll['ativo'] === 0 ? ' (inativa)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo" style="max-width:180px;">
        <label for="f-status">Status</label>
        <select name="status" id="f-status">
            <option value="todas"    <?= $filtroStatus === 'todas'    ? 'selected' : '' ?>>Todas</option>
            <option value="ativas"   <?= $filtroStatus === 'ativas'   ? 'selected' : '' ?>>Só ativas</option>
            <option value="inativas" <?= $filtroStatus === 'inativas' ? 'selected' : '' ?>>Só inativas</option>
        </select>
    </div>
    <?php endif; ?>

    <div class="acoes">
        <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
        <a href="relatorio_desempenho.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<!-- Badges dos filtros ativos -->
<?php if ($temFiltro): ?>
<div class="no-print" style="margin-bottom:16px;">
    <span style="font-size:12px; color:#666; margin-right:8px;">Filtros ativos:</span>

    <?php if ($filtroAno > 0): ?>
        <span class="badge-filtro">
            📅 <?= (int)$filtroAno ?>
            <a class="x" href="?<?= http_build_query(['ano' => 0, 'cand' => 0, 'coord' => 0, 'lider' => 0, 'status' => 'todas']) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($filtroCand > 0): ?>
        <span class="badge-filtro">
            🗳 Nº <?= (int)$filtroCand ?> — <?= htmlspecialchars($nomeCandFiltro) ?>
            <a class="x" href="?<?= http_build_query(['ano' => $filtroAno, 'cand' => 0, 'coord' => 0, 'lider' => 0, 'status' => $filtroStatus]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($filtroCoord > 0): ?>
        <span class="badge-filtro">
            👔 <?= htmlspecialchars($nomeCoordFiltro) ?>
            <a class="x" href="?<?= http_build_query(['ano' => $filtroAno, 'cand' => $filtroCand, 'coord' => 0, 'lider' => 0, 'status' => $filtroStatus]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($filtroLider > 0): ?>
        <span class="badge-filtro">
            👤 <?= htmlspecialchars($nomeLiderFiltro) ?>
            <a class="x" href="?<?= http_build_query(['ano' => $filtroAno, 'cand' => $filtroCand, 'coord' => $filtroCoord, 'lider' => 0, 'status' => $filtroStatus]) ?>">×</a>
        </span>
    <?php endif; ?>

    <?php if ($filtroStatus !== 'todas'): ?>
        <span class="badge-filtro">
            <?= $filtroStatus === 'ativas' ? '🟢 Só ativas' : '⚪ Só inativas' ?>
            <a class="x" href="?<?= http_build_query(['ano' => $filtroAno, 'cand' => $filtroCand, 'coord' => $filtroCoord, 'lider' => $filtroLider, 'status' => 'todas']) ?>">×</a>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$mostrarLista): ?>
    <div class="aviso">
        ⚠️ Selecione o <strong>ano da campanha</strong> e o <strong>candidato</strong>
        para visualizar o relatório.
    </div>
<?php else: ?>

<!-- ============================================================
     KPIs GERAIS
     ============================================================ -->
<div class="kpis">
    <div class="kpi azul">
        <div class="rotulo">Coordenadores</div>
        <div class="valor"><?= fmtNum($tot['coords']) ?></div>
        <div class="mini"><?= fmtNum($tot['coords_ativos']) ?> ativos</div>
    </div>
    <div class="kpi">
        <div class="rotulo">Lideranças</div>
        <div class="valor"><?= fmtNum($tot['liderancas']) ?></div>
        <div class="mini"><?= fmtNum($tot['liderancas_ativas']) ?> ativas</div>
    </div>
    <div class="kpi laranja">
        <div class="rotulo">Votos esperados</div>
        <div class="valor"><?= fmtNum($tot['esperado']) ?></div>
        <div class="mini">soma das lideranças ativas</div>
    </div>
    <div class="kpi teal">
        <div class="rotulo">Votos das urnas</div>
        <div class="valor"><?= fmtNum($tot['urnas']) ?></div>
    </div>
    <div class="kpi <?= $tot['diferenca'] >= 0 ? 'verde' : 'vermelho' ?>">
        <div class="rotulo">Diferença</div>
        <div class="valor"><?= ($tot['diferenca'] > 0 ? '+' : '') . fmtNum($tot['diferenca']) ?></div>
        <div class="mini"><?= fmtPct($tot['pct']) ?> do esperado</div>
    </div>
</div>

<h2 class="sec">Distribuição das lideranças por situação</h2>
<div class="kpis">
    <div class="kpi verde">
        <div class="rotulo">▲ Acima da meta</div>
        <div class="valor"><?= fmtNum($tot['acima']) ?></div>
    </div>
    <div class="kpi verde">
        <div class="rotulo">✔ Na meta</div>
        <div class="valor"><?= fmtNum($tot['meta']) ?></div>
    </div>
    <div class="kpi amarelo">
        <div class="rotulo">≈ Quase (≥70%)</div>
        <div class="valor"><?= fmtNum($tot['quase']) ?></div>
    </div>
    <div class="kpi vermelho">
        <div class="rotulo">▼ Abaixo (&lt;70%)</div>
        <div class="valor"><?= fmtNum($tot['abaixo']) ?></div>
    </div>
    <div class="kpi">
        <div class="rotulo">— Sem meta</div>
        <div class="valor"><?= fmtNum($tot['sem']) ?></div>
    </div>
</div>

<!-- ============================================================
     RANKING DE COORDENADORES
     ============================================================ -->
<h2 class="sec">🏆 Ranking de coordenadores (por % de atingimento)</h2>

<?php if (!$rankCoord): ?>
    <div class="aviso">Nenhum coordenador ativo com meta definida para os filtros selecionados.</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th style="width:50px;" class="centro">#</th>
            <th>Candidato</th>
            <th>Coordenador</th>
            <th class="centro" style="width:70px;">Zonas</th>
            <th class="centro" style="width:70px;">Seções</th>
            <th class="centro" style="width:70px;">Líderes</th>
            <th class="num" style="width:100px;">Esperado</th>
            <th class="num" style="width:100px;">Urnas</th>
            <th class="num" style="width:90px;">Diferença</th>
            <th style="width:200px;">Desempenho</th>
            <th class="centro" style="width:110px;">Situação</th>
            <th class="centro" style="width:180px;">Distribuição</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rankCoord as $i => $c): ?>
            <?php
            $pos = $i + 1;
            $med = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : $pos));
            $pct = $c['pct'] ?? 0;
            $largura = min(100, max(0, $pct));
            $cls = $pct >= 100 ? 'ok' : ($pct >= 70 ? 'med' : 'ruim');
            $dif = (int)$c['diferenca'];
            $linhaCls = $pos === 1 ? 'destaque-ouro'
                      : ($pos === 2 ? 'destaque-prata'
                      : ($pos === 3 ? 'destaque-bronze' : ''));
            ?>
            <tr class="<?= $linhaCls ?>">
                <td class="centro"><span class="medalha"><?= $med ?></span></td>
                <td>
                    <span class="badge badge-ano"><?= (int)$c['ano_campanha'] ?></span>
                    <span class="badge badge-cand">Nº <?= (int)$c['numero_candidato'] ?></span>
                    <?php if (!empty($c['candidato'])): ?>
                        <div style="font-size:11px;color:#666;margin-top:3px;">
                            <?= htmlspecialchars($c['candidato']) ?>
                            <?= $c['candidato_cargo'] ? ' · ' . htmlspecialchars($c['candidato_cargo']) : '' ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td><strong><?= htmlspecialchars($c['nome']) ?></strong></td>
                <td class="centro"><?= fmtNum($c['qtd_zonas']) ?></td>
                <td class="centro"><?= fmtNum($c['qtd_secoes']) ?></td>
                <td class="centro"><?= fmtNum($c['qtd_lid_ativ']) ?></td>
                <td class="num"><span class="badge badge-votos"><?= fmtNum($c['esperado']) ?></span></td>
                <td class="num"><span class="badge badge-urnas"><?= fmtNum($c['urnas']) ?></span></td>
                <td class="num">
                    <span class="badge <?= $dif > 0 ? 'badge-pos' : ($dif < 0 ? 'badge-neg' : 'badge-zero') ?>">
                        <?= ($dif > 0 ? '+' : '') . fmtNum($dif) ?>
                    </span>
                </td>
                <td>
                    <div class="barra">
                        <div class="fill <?= $cls ?>" style="width:<?= $largura ?>%"></div>
                        <div class="txt"><?= fmtPct($c['pct']) ?></div>
                    </div>
                </td>
                <td class="centro">
                    <span class="sit <?= $c['situacao']['classe'] ?>">
                        <span class="ico"><?= $c['situacao']['icone'] ?></span><?= $c['situacao']['rotulo'] ?>
                    </span>
                </td>
                <td class="centro" style="font-size:11px;">
                    <?php if ((int)$c['qtd_acima'] > 0): ?><span class="badge badge-pos">▲<?= (int)$c['qtd_acima'] ?></span><?php endif; ?>
                    <?php if ((int)$c['qtd_meta'] > 0): ?><span class="badge badge-pos">✔<?= (int)$c['qtd_meta'] ?></span><?php endif; ?>
                    <?php if ((int)$c['qtd_quase'] > 0): ?><span class="badge badge-pct-med">≈<?= (int)$c['qtd_quase'] ?></span><?php endif; ?>
                    <?php if ((int)$c['qtd_abaixo'] > 0): ?><span class="badge badge-neg">▼<?= (int)$c['qtd_abaixo'] ?></span><?php endif; ?>
                    <?php if ((int)$c['qtd_sem'] > 0): ?><span class="badge badge-zero">—<?= (int)$c['qtd_sem'] ?></span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- ============================================================
     RANKING DE LIDERANÇAS
     ============================================================ -->
<h2 class="sec">🏆 Ranking de lideranças (por % de atingimento)</h2>

<?php if (!$rankLid): ?>
    <div class="aviso">Nenhuma liderança ativa com meta definida para os filtros selecionados.</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th style="width:50px;" class="centro">#</th>
            <th>Liderança</th>
            <th>Coordenador</th>
            <th class="num" style="width:100px;">Esperado</th>
            <th class="num" style="width:100px;">Urnas</th>
            <th class="num" style="width:90px;">Diferença</th>
            <th style="width:200px;">Desempenho</th>
            <th class="centro" style="width:110px;">Situação</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach (array_slice($rankLid, 0, 30) as $i => $l): ?>
            <?php
            $pos = $i + 1;
            $med = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : $pos));
            $pct = $l['pct'] ?? 0;
            $largura = min(100, max(0, $pct));
            $cls = $pct >= 100 ? 'ok' : ($pct >= 70 ? 'med' : 'ruim');
            $dif = (int)$l['diferenca'];
            $linhaCls = $pos === 1 ? 'destaque-ouro'
                      : ($pos === 2 ? 'destaque-prata'
                      : ($pos === 3 ? 'destaque-bronze' : ''));
            ?>
            <tr class="<?= $linhaCls ?>">
                <td class="centro"><span class="medalha"><?= $med ?></span></td>
                <td><strong><?= htmlspecialchars($l['nome']) ?></strong></td>
                <td><span class="badge badge-coord"><?= htmlspecialchars($l['coord_nome']) ?></span></td>
                <td class="num"><span class="badge badge-votos"><?= fmtNum($l['esperado']) ?></span></td>
                <td class="num"><span class="badge badge-urnas"><?= fmtNum($l['urnas']) ?></span></td>
                <td class="num">
                    <span class="badge <?= $dif > 0 ? 'badge-pos' : ($dif < 0 ? 'badge-neg' : 'badge-zero') ?>">
                        <?= ($dif > 0 ? '+' : '') . fmtNum($dif) ?>
                    </span>
                </td>
                <td>
                    <div class="barra">
                        <div class="fill <?= $cls ?>" style="width:<?= $largura ?>%"></div>
                        <div class="txt"><?= fmtPct($l['pct']) ?></div>
                    </div>
                </td>
                <td class="centro">
                    <span class="sit <?= $l['situacao']['classe'] ?>">
                        <span class="ico"><?= $l['situacao']['icone'] ?></span><?= $l['situacao']['rotulo'] ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($rankLid) > 30): ?>
            <tr>
                <td colspan="8" class="centro vazio">
                    ... <?= count($rankLid) - 30 ?> outras lideranças não exibidas
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- ============================================================
     TOP SEÇÕES vs. DESCOBERTAS
     ============================================================ -->
<div class="grid-2">

    <div class="card">
        <h3>🔥 Top 15 seções (mais votos)</h3>
        <?php if (!$temCoordSecao): ?>
            <p class="vazio">Tabela <code>coordenador_secao</code> não existe.</p>
        <?php elseif (!$topSecoes): ?>
            <p class="vazio">Nenhuma seção vinculada para os filtros selecionados.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width:50px;" class="centro">#</th>
                        <th>Seção</th>
                        <th class="centro" style="width:70px;">Líderes</th>
                        <th class="num" style="width:100px;">Votos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topSecoes as $i => $s): ?>
                        <tr>
                            <td class="centro"><?= $i + 1 ?></td>
                            <td><span class="badge badge-zona"><?= fmtSecao((int)$s['numeroZona'], (int)$s['numeroSecao']) ?></span></td>
                            <td class="centro"><?= (int)$s['qtd_lideres'] ?></td>
                            <td class="num"><span class="badge badge-urnas"><?= fmtNum($s['votos']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>⚠ Seções descobertas (sem liderança)</h3>
        <?php if (!$temCoordSecao || !$temLiderSecao): ?>
            <p class="vazio">Tabelas <code>coordenador_secao</code> / <code>lider_secao</code> não existem.</p>
        <?php elseif ($filtroLider > 0): ?>
            <p class="vazio">Esta análise não se aplica quando há um líder específico selecionado.</p>
        <?php else: ?>
            <p style="margin:0 0 10px; font-size:12px; color:#666;">
                <strong><?= fmtNum($qtdDescobertasTotal) ?></strong> seções ·
                <strong><?= fmtNum($votosDescobertosTotal) ?></strong> votos sem cobertura
                <?= $filtroCoord > 0 ? ' (coordenador filtrado)' : '' ?>
            </p>
            <?php if (!$secoesDescobertas): ?>
                <p class="vazio">Parabéns! Todas as seções do coordenador têm liderança cadastrada.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:50px;" class="centro">#</th>
                            <th>Seção</th>
                            <th class="num" style="width:100px;">Votos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($secoesDescobertas as $i => $s): ?>
                            <tr>
                                <td class="centro"><?= $i + 1 ?></td>
                                <td><span class="badge badge-zona"><?= fmtSecao((int)$s['numeroZona'], (int)$s['numeroSecao']) ?></span></td>
                                <td class="num"><span class="badge badge-urnas"><?= fmtNum($s['votos']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<!-- ============================================================
     ANÁLISE DETALHADA POR COORDENADOR
     ============================================================ -->
<h2 class="sec">📋 Análise detalhada por coordenador</h2>

<table>
    <thead>
        <tr>
            <th>Candidato</th>
            <th>Coordenador</th>
            <th class="centro" style="width:60px;">Status</th>
            <th class="centro" style="width:70px;">Seções</th>
            <th class="centro" style="width:70px;">Líderes</th>
            <th class="num" style="width:100px;">Esperado</th>
            <th class="num" style="width:100px;">Urnas</th>
            <th class="num" style="width:90px;">Diferença</th>
            <th class="centro" style="width:80px;">%</th>
            <th class="centro" style="width:70px;">▲ Acima</th>
            <th class="centro" style="width:70px;">✔ Meta</th>
            <th class="centro" style="width:70px;">≈ Quase</th>
            <th class="centro" style="width:70px;">▼ Abaixo</th>
            <th class="centro" style="width:70px;">— Sem</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$coordenadores): ?>
            <tr><td colspan="14" class="centro vazio" style="padding:20px;">
                Nenhum coordenador para os filtros selecionados.
            </td></tr>
        <?php else: ?>
            <?php foreach ($coordenadores as $c): ?>
                <?php $dif = (int)$c['diferenca']; ?>
                <tr>
                    <td>
                        <span class="badge badge-ano"><?= (int)$c['ano_campanha'] ?></span>
                        <span class="badge badge-cand">Nº <?= (int)$c['numero_candidato'] ?></span>
                        <?php if (!empty($c['candidato'])): ?>
                            <div style="font-size:11px;color:#666;margin-top:3px;">
                                <?= htmlspecialchars($c['candidato']) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= htmlspecialchars($c['nome']) ?></strong></td>
                    <td class="centro">
                        <?= (int)$c['ativo'] === 1
                            ? '<span class="badge badge-ativo">Ativo</span>'
                            : '<span class="badge badge-inativo">Inativo</span>' ?>
                    </td>
                    <td class="centro"><?= fmtNum($c['qtd_secoes']) ?></td>
                    <td class="centro"><?= fmtNum($c['qtd_lid_ativ']) ?></td>
                    <td class="num"><span class="badge badge-votos"><?= fmtNum($c['esperado']) ?></span></td>
                    <td class="num"><span class="badge badge-urnas"><?= fmtNum($c['urnas']) ?></span></td>
                    <td class="num">
                        <span class="badge <?= $dif > 0 ? 'badge-pos' : ($dif < 0 ? 'badge-neg' : 'badge-zero') ?>">
                            <?= ($dif > 0 ? '+' : '') . fmtNum($dif) ?>
                        </span>
                    </td>
                    <td class="centro">
                        <?php if ($c['pct'] !== null): ?>
                            <span class="badge <?= $c['pct'] >= 100 ? 'badge-pct-ok' : ($c['pct'] >= 70 ? 'badge-pct-med' : 'badge-pct-ruim') ?>">
                                <?= fmtPct($c['pct']) ?>
                            </span>
                        <?php else: ?><span class="vazio">—</span><?php endif; ?>
                    </td>
                    <td class="centro"><?= (int)$c['qtd_acima']  ?: '<span class="vazio">0</span>' ?></td>
                    <td class="centro"><?= (int)$c['qtd_meta']   ?: '<span class="vazio">0</span>' ?></td>
                    <td class="centro"><?= (int)$c['qtd_quase']  ?: '<span class="vazio">0</span>' ?></td>
                    <td class="centro"><?= (int)$c['qtd_abaixo'] ?: '<span class="vazio">0</span>' ?></td>
                    <td class="centro"><?= (int)$c['qtd_sem']    ?: '<span class="vazio">0</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="linha-total">
                <td colspan="5" style="text-align:right;">Total geral</td>
                <td class="num"><?= fmtNum($tot['esperado']) ?></td>
                <td class="num"><?= fmtNum($tot['urnas']) ?></td>
                <td class="num"><?= ($tot['diferenca'] > 0 ? '+' : '') . fmtNum($tot['diferenca']) ?></td>
                <td class="centro"><?= fmtPct($tot['pct']) ?></td>
                <td class="centro"><?= fmtNum($tot['acima']) ?></td>
                <td class="centro"><?= fmtNum($tot['meta']) ?></td>
                <td class="centro"><?= fmtNum($tot['quase']) ?></td>
                <td class="centro"><?= fmtNum($tot['abaixo']) ?></td>
                <td class="centro"><?= fmtNum($tot['sem']) ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- ============================================================
     DETALHAMENTO POR LIDERANÇA
     ============================================================ -->
<h2 class="sec">👥 Detalhamento por liderança</h2>

<table>
    <thead>
        <tr>
            <th>Liderança</th>
            <th>Coordenador</th>
            <th class="centro" style="width:70px;">Status</th>
            <th class="centro" style="width:80px;">Seções</th>
            <th class="num" style="width:100px;">Esperado</th>
            <th class="num" style="width:100px;">Urnas</th>
            <th class="num" style="width:90px;">Diferença</th>
            <th class="centro" style="width:80px;">%</th>
            <th class="centro" style="width:110px;">Situação</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$liderancas): ?>
            <tr><td colspan="9" class="centro vazio" style="padding:20px;">
                Nenhuma liderança para os filtros selecionados.
            </td></tr>
        <?php else: ?>
            <?php foreach ($liderancas as $l): ?>
                <?php $dif = (int)$l['diferenca']; ?>
                <tr>
                    <td><strong><?= htmlspecialchars($l['nome']) ?></strong></td>
                    <td><span class="badge badge-coord"><?= htmlspecialchars($l['coord_nome']) ?></span></td>
                    <td class="centro">
                        <?= (int)$l['ativo'] === 1
                            ? '<span class="badge badge-ativo">Ativa</span>'
                            : '<span class="badge badge-inativo">Inativa</span>' ?>
                    </td>
                    <td class="centro"><?= fmtNum($l['qtd_secoes']) ?></td>
                    <td class="num"><span class="badge badge-votos"><?= fmtNum($l['esperado']) ?></span></td>
                    <td class="num"><span class="badge badge-urnas"><?= fmtNum($l['urnas']) ?></span></td>
                    <td class="num">
                        <span class="badge <?= $dif > 0 ? 'badge-pos' : ($dif < 0 ? 'badge-neg' : 'badge-zero') ?>">
                            <?= ($dif > 0 ? '+' : '') . fmtNum($dif) ?>
                        </span>
                    </td>
                    <td class="centro">
                        <?php if ($l['pct'] !== null): ?>
                            <span class="badge <?= $l['pct'] >= 100 ? 'badge-pct-ok' : ($l['pct'] >= 70 ? 'badge-pct-med' : 'badge-pct-ruim') ?>">
                                <?= fmtPct($l['pct']) ?>
                            </span>
                        <?php else: ?><span class="vazio">—</span><?php endif; ?>
                    </td>
                    <td class="centro">
                        <span class="sit <?= $l['situacao']['classe'] ?>">
                            <span class="ico"><?= $l['situacao']['icone'] ?></span><?= $l['situacao']['rotulo'] ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- ============================================================
     LEGENDA
     ============================================================ -->
<h2 class="sec">Legenda das classificações</h2>
<table>
    <thead>
        <tr>
            <th style="width:130px;">Situação</th>
            <th style="width:230px;">Regra</th>
            <th>Significado</th>
        </tr>
    </thead>
    <tbody>
        <tr><td><span class="sit sit-acima"><span class="ico">▲</span>Acima</span></td>
            <td>Urnas <strong>maior</strong> que a meta</td>
            <td>A liderança/coordenador superou a expectativa de votos</td></tr>
        <tr><td><span class="sit sit-meta"><span class="ico">✔</span>Na meta</span></td>
            <td>Urnas <strong>igual</strong> à meta</td>
            <td>Atingiu exatamente o esperado</td></tr>
        <tr><td><span class="sit sit-quase"><span class="ico">≈</span>Quase</span></td>
            <td>Urnas entre <strong>70% e 99%</strong> da meta</td>
            <td>Atingiu boa parte, mas ainda abaixo</td></tr>
        <tr><td><span class="sit sit-abaixo"><span class="ico">▼</span>Abaixo</span></td>
            <td>Urnas <strong>menor</strong> que 70% da meta</td>
            <td>Desempenho muito abaixo do esperado</td></tr>
        <tr><td><span class="sit sit-sem">— Sem meta</span></td>
            <td>Não há expectativa cadastrada</td>
            <td>Sem base para avaliar desempenho</td></tr>
    </tbody>
</table>

<p style="font-size:11px; color:#888; margin-top:30px; text-align:center;">
    Relatório gerado automaticamente · <?= date('d/m/Y \à\s H:i') ?>
</p>

<?php endif; ?>

<script>
    // ============================================================
    // Cascata: Ano → Candidato → Coordenador → Liderança
    // ============================================================
    (function () {
        const selAno   = document.getElementById('f-ano');
        const selCand  = document.getElementById('f-cand');
        const selCoord = document.getElementById('f-coord');
        const selLider = document.getElementById('f-lider');

        // guarda opções originais de coordenadores e lideranças
        const opcoesCoord = selCoord ? Array.from(selCoord.querySelectorAll('option'))
            .filter(op => op.value !== '0')
            .map(op => ({ value: op.value, text: op.textContent })) : [];

        const opcoesLider = selLider ? Array.from(selLider.querySelectorAll('option'))
            .filter(op => op.value !== '0')
            .map(op => ({ value: op.value, text: op.textContent, coord: op.dataset.coord })) : [];

        // Ao trocar o ANO: recarrega a página (para carregar os candidatos corretos)
        if (selAno) {
            selAno.addEventListener('change', function () {
                const url = new URL(window.location.href);
                url.searchParams.set('ano', this.value);
                url.searchParams.set('cand', '0');
                url.searchParams.set('coord', '0');
                url.searchParams.set('lider', '0');
                url.searchParams.set('status', 'todas');
                window.location.href = url.toString();
            });
        }

        // Ao trocar o CANDIDATO: recarrega a página (para carregar coordenadores/lideranças)
        if (selCand) {
            selCand.addEventListener('change', function () {
                const url = new URL(window.location.href);
                url.searchParams.set('cand', this.value);
                url.searchParams.set('coord', '0');
                url.searchParams.set('lider', '0');
                window.location.href = url.toString();
            });
        }

        // Ao trocar o COORDENADOR: filtra lideranças (client-side)
        if (selCoord && selLider) {
            selCoord.addEventListener('change', function () {
                const coordSel = this.value;
                const liderAtual = selLider.value;

                selLider.innerHTML = '<option value="0">— Todas as lideranças —</option>';
                opcoesLider.forEach(o => {
                    if (coordSel !== '0' && o.coord !== coordSel) return;
                    const opt = document.createElement('option');
                    opt.value = o.value;
                    opt.textContent = o.text;
                    opt.dataset.coord = o.coord;
                    if (o.value === liderAtual) opt.selected = true;
                    selLider.appendChild(opt);
                });
                if (liderAtual !== '0'
                    && !selLider.querySelector(`option[value="${liderAtual}"]`)) {
                    selLider.value = '0';
                }
            });

            // Ao trocar a LIDERANÇA: seleciona o coordenador dela
            selLider.addEventListener('change', function () {
                const opt = this.options[this.selectedIndex];
                if (!opt) return;
                const coord = opt.dataset.coord;
                if (coord && coord !== '0') {
                    selCoord.value = coord;
                }
            });
        }
    })();
</script>

</body>
</html>