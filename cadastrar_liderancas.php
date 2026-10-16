<?php
// ------------------------------------------------------------
// cadastrar_liderancas.php
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

$__rotuloPerfil = function_exists('rotuloPerfil') ? rotuloPerfil($__perfil) : strtoupper($__perfil);
$__classePerfil = function_exists('classePerfil') ? classePerfil($__perfil) : ('perfil-' . $__perfil);

// ============================================================
// DETECÇÃO DE COLUNAS / TABELAS
// ============================================================
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

$temTipoVotavel   = in_array('cdTipoVotavel',  $colunasVotos, true);
$temCargoPergunta = in_array('cargoPergunta', $colunasVotos, true);

$colunasLider = [];
foreach ($pdo->query("SHOW COLUMNS FROM liderancas") as $c) $colunasLider[] = $c['Field'];
$temExpectativaLider = in_array('expectativa_votos', $colunasLider, true);

$colunasLS = [];
foreach ($pdo->query("SHOW COLUMNS FROM lider_secao") as $c) $colunasLS[] = $c['Field'];
$temCoordEmLiderSecao = in_array('coordenador_id', $colunasLS, true);

$colunasCoord = [];
foreach ($pdo->query("SHOW COLUMNS FROM coordenadores") as $c) $colunasCoord[] = $c['Field'];
$temNumeroCandidatoCoord = in_array('numero_candidato',  $colunasCoord, true);
$temAnoCampanhaCoord     = in_array('ano_campanha',      $colunasCoord, true);
$temExpectativaCoord     = in_array('expectativa_votos', $colunasCoord, true);

$temTabMunicipio  = false;
$temTabCoordSecao = false;
try {
    $temTabMunicipio  = (bool)$pdo->query("SHOW TABLES LIKE 'coordenador_municipio'")->fetchColumn();
    $temTabCoordSecao = (bool)$pdo->query("SHOW TABLES LIKE 'coordenador_secao'")->fetchColumn();
} catch (Exception $e) { /* mantém false */ }

// ============================================================
// ENDPOINTS AJAX
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $acao = $_GET['ajax'];

        if ($acao === 'coordenadores_listar') {
            $ano = (int)($_GET['ano'] ?? 0);
            $num = (int)($_GET['candidato'] ?? 0);
            $sql = "SELECT c.id, c.nome, c.numero_candidato, c.ano_campanha
                      FROM coordenadores c WHERE c.ativo = 1";
            $pars = [];
            if ($ano > 0 && $temAnoCampanhaCoord)     { $sql .= " AND c.ano_campanha = :ano";     $pars[':ano'] = $ano; }
            if ($num > 0 && $temNumeroCandidatoCoord) { $sql .= " AND c.numero_candidato = :num"; $pars[':num'] = $num; }
            $sql .= " ORDER BY c.nome";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($pars);
            echo json_encode(['ok' => true, 'coordenadores' => $stmt->fetchAll()]);
            exit;
        }

        if ($acao === 'secoes_disponiveis') {
            $coordId = (int)($_GET['coordenador_id'] ?? 0);
            $liderId = (int)($_GET['lider_id'] ?? 0);
            $zona    = (int)($_GET['zona'] ?? 0);
            if ($coordId <= 0) throw new Exception('Coordenador inválido.');

            $sql = "
                SELECT cs.numeroZona, cs.numeroSecao,
                       se.nomeMunicipio, se.bairro, se.nomeLocal
                  FROM coordenador_secao cs
                  LEFT JOIN secoes_enderecos se
                         ON se.numeroZona = cs.numeroZona
                        AND se.numeroSecao = cs.numeroSecao
                 WHERE cs.coordenador_id = :cid
                   AND NOT EXISTS (
                       SELECT 1 FROM lider_secao ls
                        WHERE ls.coordenador_id = :cid2
                          AND ls.numeroZona = cs.numeroZona
                          AND ls.numeroSecao = cs.numeroSecao
                   )
            ";
            $pars = [':cid' => $coordId, ':cid2' => $coordId];
            if ($zona > 0) { $sql .= " AND cs.numeroZona = :z"; $pars[':z'] = $zona; }
            $sql .= " ORDER BY se.nomeMunicipio, cs.numeroZona, cs.numeroSecao";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($pars);
            echo json_encode(['ok' => true, 'secoes' => $stmt->fetchAll()]);
            exit;
        }

        if ($acao === 'secoes_atribuidas') {
            $liderId = (int)($_GET['lider_id'] ?? 0);
            if ($liderId <= 0) throw new Exception('Liderança inválida.');

            $sql = "
                SELECT ls.numeroZona, ls.numeroSecao,
                       se.nomeMunicipio, se.bairro, se.nomeLocal
                  FROM lider_secao ls
                  LEFT JOIN secoes_enderecos se
                         ON se.numeroZona = ls.numeroZona
                        AND se.numeroSecao = ls.numeroSecao
                 WHERE ls.lider_id = :lid
                 ORDER BY se.nomeMunicipio, ls.numeroZona, ls.numeroSecao
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':lid' => $liderId]);
            echo json_encode(['ok' => true, 'secoes' => $stmt->fetchAll()]);
            exit;
        }

        if ($acao === 'stats_distribuicao') {
            $coordId = (int)($_GET['coordenador_id'] ?? 0);
            $liderId = (int)($_GET['lider_id'] ?? 0);
            if ($coordId <= 0) throw new Exception('Coordenador inválido.');

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM coordenador_secao WHERE coordenador_id = :c");
            $stmt->execute([':c' => $coordId]);
            $universo = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lider_secao WHERE coordenador_id = :c");
            $stmt->execute([':c' => $coordId]);
            $atribuidasTotal = (int)$stmt->fetchColumn();

            $minhas = 0;
            if ($liderId > 0) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM lider_secao WHERE lider_id = :l");
                $stmt->execute([':l' => $liderId]);
                $minhas = (int)$stmt->fetchColumn();
            }

            echo json_encode([
                'ok' => true,
                'universo'         => $universo,
                'atribuidas_total' => $atribuidasTotal,
                'disponiveis'      => max(0, $universo - $atribuidasTotal),
                'minhas'           => $minhas,
            ]);
            exit;
        }

        if ($acao === 'secao_atribuir' || $acao === 'secao_remover'
         || $acao === 'atribuir_lote' || $acao === 'remover_lote') {

            if (!$__ehAdmin) throw new Exception('Sem permissão.');

            $liderId = (int)($_POST['lider_id'] ?? 0);
            if ($liderId <= 0) throw new Exception('Liderança inválida.');

            $stmt = $pdo->prepare("SELECT coordenador_id FROM liderancas WHERE id = :id");
            $stmt->execute([':id' => $liderId]);
            $coordId = (int)$stmt->fetchColumn();
            if ($coordId <= 0) throw new Exception('Coordenador da liderança não encontrado.');

            if (!$temCoordEmLiderSecao) {
                throw new Exception('Coluna coordenador_id não existe em lider_secao. Rode o SQL.');
            }

            $lista = [];
            if ($acao === 'secao_atribuir' || $acao === 'secao_remover') {
                $z = (int)($_POST['numeroZona'] ?? 0);
                $s = (int)($_POST['numeroSecao'] ?? 0);
                if ($z > 0 && $s > 0) $lista[] = ['z' => $z, 's' => $s];
            } else {
                $raw = $_POST['secoes'] ?? '[]';
                $arr = json_decode($raw, true);
                if (is_array($arr)) {
                    foreach ($arr as $item) {
                        $z = (int)($item['z'] ?? 0);
                        $s = (int)($item['s'] ?? 0);
                        if ($z > 0 && $s > 0) $lista[] = ['z' => $z, 's' => $s];
                    }
                }
            }
            if (!$lista) throw new Exception('Nenhuma seção informada.');

            $pdo->beginTransaction();
            $add = 0; $del = 0;

            if ($acao === 'secao_atribuir' || $acao === 'atribuir_lote') {
                $stmtValida = $pdo->prepare(
                    "SELECT 1 FROM coordenador_secao
                      WHERE coordenador_id = :c AND numeroZona = :z AND numeroSecao = :s LIMIT 1"
                );
                $stmtIns = $pdo->prepare(
                    "INSERT IGNORE INTO lider_secao (lider_id, coordenador_id, numeroZona, numeroSecao)
                     VALUES (:l, :c, :z, :s)"
                );
                foreach ($lista as $it) {
                    $stmtValida->execute([':c' => $coordId, ':z' => $it['z'], ':s' => $it['s']]);
                    if (!$stmtValida->fetchColumn()) continue;
                    $stmtIns->execute([':l' => $liderId, ':c' => $coordId, ':z' => $it['z'], ':s' => $it['s']]);
                    if ($stmtIns->rowCount() > 0) $add++;
                }
            } else {
                $stmtDel = $pdo->prepare(
                    "DELETE FROM lider_secao
                      WHERE lider_id = :l AND numeroZona = :z AND numeroSecao = :s"
                );
                foreach ($lista as $it) {
                    $stmtDel->execute([':l' => $liderId, ':z' => $it['z'], ':s' => $it['s']]);
                    if ($stmtDel->rowCount() > 0) $del++;
                }
            }

            $pdo->commit();
            echo json_encode([
                'ok'  => true,
                'add' => $add,
                'del' => $del,
                'msg' => ($add > 0 ? "$add seção(ões) adicionada(s). " : '')
                       . ($del > 0 ? "$del seção(ões) removida(s)."  : ''),
            ]);
            exit;
        }

        if ($acao === 'salvar') {
            if (!$__ehAdmin) throw new Exception('Sem permissão para salvar.');

            $id            = (int)($_POST['id'] ?? 0);
            $coordenadorId = (int)($_POST['coordenador_id'] ?? 0);
            $nome          = trim($_POST['nome'] ?? '');
            $cpf           = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            $telefone      = trim($_POST['telefone'] ?? '');
            $email         = trim($_POST['email'] ?? '');
            $observacoes   = trim($_POST['observacoes'] ?? '');
            $expectativa   = (int)($_POST['expectativa_votos'] ?? 0);
            if ($expectativa < 0) $expectativa = 0;
            $ativo         = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;

            if ($nome === '')        throw new Exception('Informe o nome.');
            if ($coordenadorId <= 0) throw new Exception('Selecione o coordenador.');

            $stmt = $pdo->prepare("SELECT 1 FROM coordenadores WHERE id = :id");
            $stmt->execute([':id' => $coordenadorId]);
            if (!$stmt->fetchColumn()) throw new Exception('Coordenador não encontrado.');

            $pdo->beginTransaction();

            if ($id > 0) {
                $stmtOld = $pdo->prepare("SELECT coordenador_id FROM liderancas WHERE id = :id");
                $stmtOld->execute([':id' => $id]);
                $coordAntigo = (int)$stmtOld->fetchColumn();

                $sql = "UPDATE liderancas
                           SET coordenador_id = :cid,
                               nome = :n, cpf = :c, telefone = :t, email = :e,
                               observacoes = :o, ativo = :a";
                $pars = [
                    ':cid' => $coordenadorId,
                    ':n'   => $nome,
                    ':c'   => $cpf !== '' ? $cpf : null,
                    ':t'   => $telefone !== '' ? $telefone : null,
                    ':e'   => $email !== '' ? $email : null,
                    ':o'   => $observacoes !== '' ? $observacoes : null,
                    ':a'   => $ativo,
                    ':id'  => $id,
                ];
                if ($temExpectativaLider) {
                    $sql .= ", expectativa_votos = :exp";
                    $pars[':exp'] = $expectativa;
                }
                $sql .= " WHERE id = :id";
                $pdo->prepare($sql)->execute($pars);

                if ($coordAntigo > 0 && $coordAntigo !== $coordenadorId && $temCoordEmLiderSecao) {
                    $pdo->prepare("DELETE FROM lider_secao WHERE lider_id = :id")->execute([':id' => $id]);
                }
            } else {
                $cols = ['coordenador_id','nome','cpf','telefone','email','observacoes','ativo'];
                $vals = [':cid',':n',':c',':t',':e',':o',':a'];
                $pars = [
                    ':cid' => $coordenadorId,
                    ':n'   => $nome,
                    ':c'   => $cpf !== '' ? $cpf : null,
                    ':t'   => $telefone !== '' ? $telefone : null,
                    ':e'   => $email !== '' ? $email : null,
                    ':o'   => $observacoes !== '' ? $observacoes : null,
                    ':a'   => $ativo,
                ];
                if ($temExpectativaLider) {
                    array_splice($cols, 5, 0, 'expectativa_votos');
                    array_splice($vals, 5, 0, ':exp');
                    $pars[':exp'] = $expectativa;
                }
                $pdo->prepare(
                    "INSERT INTO liderancas (" . implode(',', $cols) . ")
                     VALUES (" . implode(',', $vals) . ")"
                )->execute($pars);
                $id = (int)$pdo->lastInsertId();
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'id' => $id, 'msg' => 'Salvo com sucesso.']);
            exit;
        }

        if ($acao === 'excluir') {
            if (!$isAdmin) throw new Exception('Sem permissão para excluir.');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido.');

            $pdo->prepare("DELETE FROM liderancas WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'msg' => 'Excluído.']);
            exit;
        }

        throw new Exception('Ação desconhecida.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        exit;
    }
}

// ============================================================
// DADOS PARA A PÁGINA
// ============================================================
$filtroAno       = (int)($_GET['ano'] ?? 0);
$filtroCandidato = (int)($_GET['candidato'] ?? 0);
$filtroCoord     = (int)($_GET['coordenador'] ?? 0);

$mostrarLista = ($filtroAno > 0 && $filtroCandidato > 0);

$listaAnos = $pdo->query(
    "SELECT DISTINCT ano FROM votos WHERE ano > 0 ORDER BY ano DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$listaCandidatos = [];
if ($filtroAno > 0) {
    $stmt = $pdo->prepare(
        "SELECT numero, MIN(nomeCandidatoResp) AS nome, MIN(nomeCargoPerg) AS cargo
           FROM votos
          WHERE ano = :ano AND numero > 0 AND nomeCandidatoResp <> ''
          GROUP BY numero ORDER BY nome"
    );
    $stmt->execute([':ano' => $filtroAno]);
    $listaCandidatos = $stmt->fetchAll();
}

$listaCoordenadores = [];
if ($mostrarLista) {
    $sql = "SELECT c.id, c.nome FROM coordenadores c WHERE c.ativo = 1";
    $pars = [];
    if ($temAnoCampanhaCoord)     { $sql .= " AND c.ano_campanha = :ano";     $pars[':ano'] = $filtroAno; }
    if ($temNumeroCandidatoCoord) { $sql .= " AND c.numero_candidato = :num"; $pars[':num'] = $filtroCandidato; }
    $sql .= " ORDER BY c.nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($pars);
    $listaCoordenadores = $stmt->fetchAll();
}

$liderancas = [];
if ($mostrarLista) {
    $where  = [];
    $params = [];

    if ($temAnoCampanhaCoord)     { $where[] = "c.ano_campanha = :ano";     $params[':ano'] = $filtroAno; }
    if ($temNumeroCandidatoCoord) { $where[] = "c.numero_candidato = :num"; $params[':num'] = $filtroCandidato; }
    if ($filtroCoord > 0)         { $where[] = "c.id = :cid";               $params[':cid'] = $filtroCoord; }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $expL = $temExpectativaLider ? "l.expectativa_votos" : "0";

    $filtroCandVotos = "
        AND v.numero = c.numero_candidato
        AND v.ano = c.ano_campanha
        AND v.nomeCandidatoResp <> ''
    ";
    if ($temTipoVotavel)  $filtroCandVotos .= " AND v.cdTipoVotavel = 1 ";
    if ($temCargoPergunta) {
        $filtroCandVotos .= "
            AND v.cargoPergunta = (
                SELECT v2.cargoPergunta FROM votos v2
                 WHERE v2.numero = c.numero_candidato
                   AND v2.ano = c.ano_campanha
                   AND v2.nomeCandidatoResp <> ''
                 GROUP BY v2.cargoPergunta
                 ORDER BY SUM(v2.`$COL_VOTOS`) DESC
                 LIMIT 1
            )
        ";
    }

    // LISTA DE SEÇÕES: formato #Zzona-Ssecao, separado por vírgula
    $sql = "
        SELECT l.*,
            c.nome  AS coordenador_nome,
            c.numero_candidato,
            c.ano_campanha,

            (SELECT COUNT(*) FROM coordenador_secao cs
              WHERE cs.coordenador_id = c.id) AS universo_secoes,

            (SELECT COUNT(*) FROM lider_secao ls
              WHERE ls.lider_id = l.id) AS qtd_secoes,

            $expL AS votos_esperados,

            (SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
               FROM votos v
              WHERE 1=1 $filtroCandVotos
                AND (v.numeroZona, v.numeroSecao) IN (
                    SELECT ls.numeroZona, ls.numeroSecao
                      FROM lider_secao ls
                     WHERE ls.lider_id = l.id
                )) AS votos_urnas,

            (SELECT GROUP_CONCAT(
                        CONCAT('#Z', ls.numeroZona, '-S', ls.numeroSecao)
                        ORDER BY ls.numeroZona, ls.numeroSecao
                        SEPARATOR ', ')
               FROM lider_secao ls
              WHERE ls.lider_id = l.id) AS secoes_str

        FROM liderancas l
        INNER JOIN coordenadores c ON c.id = l.coordenador_id
        $whereSql
        ORDER BY c.nome, l.nome
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $liderancas = $stmt->fetchAll();
}

foreach ($liderancas as &$l) {
    $esp = (int)$l['votos_esperados'];
    $urn = (int)$l['votos_urnas'];
    $l['diferenca'] = $urn - $esp;
    $l['pct']       = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
    $univ           = (int)$l['universo_secoes'];
    $l['cobertura'] = $univ > 0 ? round(((int)$l['qtd_secoes'] / $univ) * 100, 1) : 0;
    $l['secoes_str']= $l['secoes_str'] ?? '';
}
unset($l);

// Totais
$totalLiderancas = count($liderancas);
$totalAtivos     = 0;
$totalEsperados  = 0;
$totalUrnas      = 0;
$totalSecoesAtrib= 0;
foreach ($liderancas as $l) {
    if ((int)$l['ativo'] === 1) $totalAtivos++;
    $totalEsperados  += (int)$l['votos_esperados'];
    $totalUrnas      += (int)$l['votos_urnas'];
    $totalSecoesAtrib+= (int)$l['qtd_secoes'];
}
$totalDiferenca = $totalUrnas - $totalEsperados;
$totalPct = $totalEsperados > 0 ? round(($totalUrnas / $totalEsperados) * 100, 1) : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lideranças</title>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi { background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:180px;
        border-left:5px solid #0069d9; }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#0069d9; margin-top:4px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.teal    { border-left-color:#17a2b8; } .kpi.teal .valor  { color:#17a2b8; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi .mini   { font-size:11px; color:#999; margin-top:2px; }

    .btn { display:inline-block; padding:8px 14px; border-radius:4px; border:none;
           cursor:pointer; text-decoration:none; font-size:14px; font-family:inherit; }
    .btn-novo { background:#28a745; color:#fff; } .btn-novo:hover { background:#1e7e34; }
    .btn-editar{ background:#0069d9; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-editar:hover { background:#0053b3; }
    .btn-excluir{ background:#dc3545; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-excluir:hover { background:#b02a37; }
    .btn-sec   { background:#fd7e14; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-sec:hover { background:#d96610; }
    .btn-salvar { background:#28a745; color:#fff; padding:10px 18px; font-size:14px; }
    .btn-salvar:hover { background:#1e7e34; }
    .btn-cancelar { background:#6c757d; color:#fff; padding:10px 18px; font-size:14px; }
    .btn-cancelar:hover { background:#5a6268; }
    .btn-primary { background:#0069d9; color:#fff; padding:8px 14px; font-size:13px; border:none;
                   border-radius:4px; cursor:pointer; font-family:inherit; }
    .btn-primary:hover { background:#0053b3; }
    .btn-danger  { background:#dc3545; color:#fff; padding:8px 14px; font-size:13px; border:none;
                   border-radius:4px; cursor:pointer; font-family:inherit; }
    .btn-danger:hover  { background:#b02a37; }

    .form-filtros {
        background:#fff; padding:14px 20px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
        display:flex; flex-wrap:wrap; align-items:center; gap:12px;
    }
    .form-filtros label { font-weight:bold; font-size:14px; }
    .form-filtros button { padding:8px 16px; border-radius:4px; border:none;
        cursor:pointer; font-size:14px; font-weight:bold; }
    .btn-consultar { background:#0069d9; color:#fff; } .btn-consultar:hover { background:#0053b3; }
    .btn-limpar-form { background:#6c757d; color:#fff; text-decoration:none;
        padding:8px 16px; border-radius:4px; font-size:14px; font-weight:bold; display:inline-block; }
    .btn-limpar-form:hover { background:#5a6268; }

    .aviso-selecione {
        background:#fff3cd; color:#856404; padding:20px; border-radius:8px;
        text-align:center; border-left:5px solid #ffc107; font-size:14px; margin-bottom:20px;
    }

    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; font-size:13px; }
    th { background:#0069d9; color:#fff; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#eef6ff; }
    tr.linha-total { background:#eef6ff; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #0069d9; }
    .num { text-align:right; } .centro { text-align:center; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-zona  { background:#e6f0fa; color:#0069d9; }
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
    .badge-coord { background:#e7f3ff; color:#0a3d62; }
    .badge-sec   { background:#fff8e1; color:#8a6d00; }
    .badge-mun   { background:#e8f5e9; color:#1c7a34; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    /* lista de seções #Z-S */
    .lista-secoes {
        font-family: 'Courier New', monospace;
        font-size:11.5px;
        color:#0a3d62;
        background:#f4f9ff;
        border-left:3px solid #0069d9;
        padding:6px 8px;
        border-radius:4px;
        max-height:90px;
        overflow:auto;
        line-height:1.6;
        word-break:break-all;
    }
    .lista-secoes .tag {
        display:inline-block;
        background:#e6f0fa;
        color:#0069d9;
        padding:1px 5px;
        border-radius:3px;
        margin:1px 2px 1px 0;
        font-size:11px;
        font-weight:bold;
    }

    .info-col {
        font-size:12px; color:#666; margin-bottom:12px;
        background:#fff; padding:8px 14px; border-radius:6px;
        border-left:4px solid #17a2b8;
        box-shadow: 0 1px 2px rgba(0,0,0,.05);
    }
    .info-col code { background:#f4f4f4; padding:1px 5px; border-radius:3px; }
    .info-col .aviso { color:#856404; background:#fff3cd;
        padding:2px 8px; border-radius:6px; font-weight:bold; margin-left:6px; }

    .progresso {
        background:#eee; height:8px; border-radius:4px; overflow:hidden; margin-top:4px;
    }
    .progresso > div { background:#28a745; height:100%; }

    .modal-overlay { display:none; position: fixed; inset: 0;
        background: rgba(0,0,0,.5); z-index: 1000;
        align-items: center; justify-content: center; padding: 20px; }
    .modal-overlay.aberto { display: flex; }
    .modal { background:#fff; border-radius: 10px; max-width: 720px; width: 100%;
        max-height: 92vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,.3); }
    .modal.modal-sec { max-width: 1100px; }
    .modal-header { padding: 16px 22px; border-bottom: 1px solid #eee;
        display: flex; justify-content: space-between; align-items: center; }
    .modal-header h2 { margin: 0; font-size: 18px; color:#0069d9; }
    .modal-header .fechar { background: none; border: none; font-size: 22px;
        cursor: pointer; color: #888; }
    .modal-body { padding: 20px 22px; }
    .modal-footer { padding: 14px 22px; border-top: 1px solid #eee;
        text-align: right; background:#fafafa; }
    .campo { margin-bottom: 14px; }
    .campo label { display: block; font-weight: bold; margin-bottom: 4px; font-size: 13px; }
    .campo input[type=text], .campo input[type=email], .campo input[type=number],
    .campo textarea, .campo select {
        width: 100%; padding: 8px 10px; border: 1px solid #ccc;
        border-radius: 4px; font-size: 14px; font-family: inherit; box-sizing: border-box;
    }
    .campo textarea { resize: vertical; min-height: 60px; }
    .linha-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .toggle { display:flex; align-items:center; gap:8px; }
    .toggle input { width:auto; }
    .dica { font-size:11px; color:#888; margin-top:3px; }
    .destaque-exp { background:#fff8e1; border-left:3px solid #ffc107;
        padding:8px 12px; border-radius:4px; }

    .select2-container { width: 100% !important; }
    .select2-container--default .select2-selection--single,
    .select2-container--default .select2-selection--multiple {
        min-height: 40px; padding: 4px 6px; border: 1px solid #ccc; border-radius: 4px;
    }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

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

    .perfil-tag { font-size:10px; padding:2px 8px; border-radius:8px;
        font-weight:bold; letter-spacing:.5px; background:#e6f0fa; color:#0069d9; }
    .perfil-tag.perfil-super    { background:#dc3545; color:#fff; }
    .perfil-tag.perfil-admin    { background:#fff3cd; color:#856404; }
    .perfil-tag.perfil-consulta { background:#e6f0fa; color:#0069d9; }

    .badge-admin { display:inline-flex; align-items:center; gap:5px;
        background:#fff3cd; color:#856404; padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; }
    .badge-super { display:inline-flex; align-items:center; gap:5px;
        background:#dc3545; color:#fff; padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; }
    .badge-visitante { display:inline-flex; align-items:center; gap:5px;
        background:#e9ecef; color:#495057; padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; }

    /* modal distribuir */
    .split {
        display:grid; grid-template-columns:1fr 1fr; gap:14px;
        min-height:400px;
    }
    .split .col {
        background:#fafafa; border:1px solid #e5e5e5; border-radius:6px;
        display:flex; flex-direction:column;
    }
    .split .col h3 {
        margin:0; padding:10px 12px; font-size:13px; color:#fff;
        border-radius:5px 5px 0 0; display:flex; align-items:center; gap:8px;
    }
    .split .col h3 .cont {
        background:rgba(255,255,255,.3); border-radius:10px;
        padding:1px 8px; font-size:11px;
    }
    .split .col.disp h3 { background:#0069d9; }
    .split .col.atrib h3 { background:#28a745; }
    .split .filtros-col {
        display:flex; gap:8px; padding:10px; background:#fff;
        border-bottom:1px solid #e5e5e5; flex-wrap:wrap;
    }
    .split .filtros-col input[type=text] {
        padding:6px 8px; border:1px solid #ccc; border-radius:4px;
        font-size:12px; font-family:inherit;
    }
    .split .filtros-col input[type=text] { flex:1; min-width:100px; }
    .split .filtros-col .input-zona { flex:0 0 90px; min-width:90px; }
    .split .lista {
        flex:1; overflow:auto; padding:8px; max-height:380px;
        background:#fff; margin:0;
    }
    .split .lista label {
        display:flex; align-items:center; gap:8px; padding:5px 6px;
        border-radius:4px; font-size:12px; cursor:pointer;
    }
    .split .lista label:hover { background:#eef6ff; }
    .split .lista input[type=checkbox] { transform:scale(1.15); }
    .split .lista .mun { color:#666; font-size:11px; margin-left:auto; }
    .split .acao {
        padding:10px; border-top:1px solid #e5e5e5; background:#fff;
        display:flex; gap:8px; align-items:center; flex-wrap:wrap;
        border-radius:0 0 5px 5px;
    }
    .split .acao .info-mini { font-size:11px; color:#666; margin-left:auto; }
    .stats-row {
        display:flex; gap:14px; margin-bottom:14px; flex-wrap:wrap;
    }
    .stats-row .stat {
        background:#fff; border-radius:6px; padding:8px 14px;
        border-left:3px solid #0069d9; font-size:12px; min-width:130px;
    }
    .stats-row .stat .n { font-size:20px; font-weight:bold; color:#0069d9; display:block; }
    .stats-row .stat.verde { border-left-color:#28a745; } .stats-row .stat.verde .n { color:#28a745; }
    .stats-row .stat.roxo  { border-left-color:#6f42c1; } .stats-row .stat.roxo  .n { color:#6f42c1; }
    .stats-row .stat.laranja { border-left-color:#fd7e14; } .stats-row .stat.laranja .n { color:#fd7e14; }

    .sec-info {
        background:#eef6ff; border-left:4px solid #0069d9;
        padding:10px 14px; border-radius:6px; font-size:12.5px;
        margin-bottom:14px; color:#333;
    }
    .secoes-preview {
        background:#fff;
        border:1px solid #e5e5e5;
        border-radius:6px;
        padding:10px 12px;
        margin-bottom:14px;
        font-family:'Courier New', monospace;
        font-size:11.5px;
        color:#0a3d62;
        max-height:90px;
        overflow:auto;
        word-break:break-all;
        line-height:1.7;
    }
    .secoes-preview .tag {
        display:inline-block;
        background:#e6f0fa;
        color:#0069d9;
        padding:1px 5px;
        border-radius:3px;
        margin:1px 3px 1px 0;
        font-size:11px;
        font-weight:bold;
    }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

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

<h1>Lideranças</h1>

<p class="info-col">
    <code>ano_campanha</code>:
    <?php if ($temAnoCampanhaCoord): ?><strong>ok</strong>
    <?php else: ?><span class="aviso">NÃO EXISTE</span><?php endif; ?>
    &nbsp;·&nbsp;
    <code>coordenador_secao</code>:
    <?php if ($temTabCoordSecao): ?><strong>ok</strong>
    <?php else: ?><span class="aviso">NÃO EXISTE</span><?php endif; ?>
    &nbsp;·&nbsp;
    <code>lider_secao.coordenador_id</code>:
    <?php if ($temCoordEmLiderSecao): ?><strong>ok</strong>
    <?php else: ?><span class="aviso">NÃO EXISTE — rode o SQL</span><?php endif; ?>
    &nbsp;·&nbsp;
    <code>expectativa_votos</code> em <code>liderancas</code>:
    <?php if ($temExpectativaLider): ?><strong>ok</strong>
    <?php else: ?><span class="aviso">NÃO EXISTE</span><?php endif; ?>
    &nbsp;·&nbsp;
    Formato das seções: <strong>#Zzona-Ssecao</strong>
</p>

<form method="get" action="cadastrar_liderancas.php" class="form-filtros" id="form-filtros">
    <label for="ano">Ano:</label>
    <select name="ano" id="ano" style="min-width:120px;"
            onchange="mudarAno(this.form);">
        <option value="0">— Selecione —</option>
        <?php foreach ($listaAnos as $a): ?>
            <option value="<?= (int)$a ?>" <?= ((int)$a === $filtroAno) ? 'selected' : '' ?>>
                <?= (int)$a ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="candidato">Candidato:</label>
    <select name="candidato" id="candidato" style="min-width:300px;"
            onchange="mudarCandidato(this.form);">
        <option value="0">— Selecione —</option>
        <?php foreach ($listaCandidatos as $c): ?>
            <option value="<?= (int)$c['numero'] ?>" <?= ((int)$c['numero'] === $filtroCandidato) ? 'selected' : '' ?>>
                Nº <?= (int)$c['numero'] ?> — <?= htmlspecialchars($c['nome']) ?>
                <?= $c['cargo'] ? ' (' . htmlspecialchars($c['cargo']) . ')' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if ($mostrarLista): ?>
    <label for="coordenador">Coordenador:</label>
    <select name="coordenador" id="coordenador" style="min-width:220px;"
            onchange="document.getElementById('form-filtros').submit();">
        <option value="0">— Todos —</option>
        <?php foreach ($listaCoordenadores as $co): ?>
            <option value="<?= (int)$co['id'] ?>" <?= ((int)$co['id'] === $filtroCoord) ? 'selected' : '' ?>>
                <?= htmlspecialchars($co['nome']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <button type="submit" class="btn-consultar">🔎 Consultar</button>
    <a href="cadastrar_liderancas.php" class="btn-limpar-form">✖ Limpar</a>
</form>

<?php if (!$mostrarLista): ?>

    <div class="aviso-selecione">
        ⚠️ Selecione o <strong>ano da campanha</strong> e o <strong>candidato</strong>
        para visualizar as lideranças.
    </div>

<?php else: ?>

    <div class="kpis">
        <div class="kpi">
            <div class="rotulo">Lideranças</div>
            <div class="valor"><?= number_format($totalLiderancas, 0, ',', '.') ?></div>
            <div class="mini">Ano <?= (int)$filtroAno ?> · Nº <?= (int)$filtroCandidato ?></div>
        </div>
        <div class="kpi verde">
            <div class="rotulo">Ativas</div>
            <div class="valor"><?= number_format($totalAtivos, 0, ',', '.') ?></div>
        </div>
        <div class="kpi laranja">
            <div class="rotulo">Seções atribuídas</div>
            <div class="valor"><?= number_format($totalSecoesAtrib, 0, ',', '.') ?></div>
        </div>
        <div class="kpi teal">
            <div class="rotulo">Votos esperados</div>
            <div class="valor"><?= number_format($totalEsperados, 0, ',', '.') ?></div>
        </div>
        <div class="kpi <?= $totalDiferenca >= 0 ? 'verde' : 'vermelho' ?>">
            <div class="rotulo">Votos × Diferença</div>
            <div class="valor"><?= number_format($totalUrnas, 0, ',', '.') ?></div>
            <div class="mini">
                <?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?>
                <?= $totalPct !== null ? ' (' . number_format($totalPct, 1, ',', '.') . '%)' : '' ?>
            </div>
        </div>
    </div>

    <?php if ($__ehAdmin): ?>
    <div style="margin-bottom:15px;">
        <button type="button" class="btn btn-novo" id="btn-novo">+ Nova Liderança</button>
    </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th>Liderança</th>
                <th>Coordenador</th>
                <th style="width:170px;">Cobertura</th>
                <th>Seções atribuídas</th>
                <th class="centro" style="width:70px;">Ativa</th>
                <th class="num" style="width:100px;">Esperados</th>
                <th class="num" style="width:100px;">Votos</th>
                <th class="num" style="width:80px;">Dif.</th>
                <th class="centro" style="width:60px;">%</th>
                <th style="width:210px;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$liderancas): ?>
                <tr><td colspan="11" style="text-align:center;color:#999;padding:20px;">
                    Nenhuma liderança cadastrada.
                </td></tr>
            <?php else: ?>
                <?php $i = 1; foreach ($liderancas as $l): ?>
                    <?php
                    $esp = (int)$l['votos_esperados'];
                    $urn = (int)$l['votos_urnas'];
                    $dif = (int)$l['diferenca'];
                    $pct = $l['pct'];
                    if ($pct === null)   $pctCls = 'badge-zero';
                    elseif ($pct >= 100) $pctCls = 'badge-pct-ok';
                    elseif ($pct >= 70)  $pctCls = 'badge-pct-med';
                    else                 $pctCls = 'badge-pct-ruim';
                    if ($dif > 0)     $difCls = 'badge-pos';
                    elseif ($dif < 0) $difCls = 'badge-neg';
                    else              $difCls = 'badge-zero';
                    $univ = (int)$l['universo_secoes'];
                    $minhas = (int)$l['qtd_secoes'];
                    $cob = $l['cobertura'];
                    ?>
                    <tr data-id="<?= (int)$l['id'] ?>">
                        <td><?= $i++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($l['nome']) ?></strong>
                            <?php if (!empty($l['telefone'])): ?>
                                <div style="font-size:11px;color:#666;">📞 <?= htmlspecialchars($l['telefone']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-coord"><?= htmlspecialchars($l['coordenador_nome']) ?></span>
                            <div style="font-size:11px;color:#666;margin-top:3px;">
                                Ano <?= (int)$l['ano_campanha'] ?> · Nº <?= (int)$l['numero_candidato'] ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-sec"><?= number_format($minhas,0,',','.') ?> de <?= number_format($univ,0,',','.') ?></span>
                            <div class="progresso"><div style="width:<?= min(100,$cob) ?>%"></div></div>
                            <div style="font-size:11px;color:#666;margin-top:3px;">
                                <?= number_format($cob,1,',','.') ?>% do coordenador
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($l['secoes_str'])): ?>
                                <div class="lista-secoes" title="<?= htmlspecialchars($l['secoes_str']) ?>">
                                    <?php
                                    $itens = array_map('trim', explode(',', $l['secoes_str']));
                                    foreach ($itens as $it):
                                        if ($it === '') continue;
                                    ?>
                                        <span class="tag"><?= htmlspecialchars($it) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="vazio">Nenhuma seção atribuída.</span>
                            <?php endif; ?>
                        </td>
                        <td class="centro">
                            <?= (int)$l['ativo'] === 1
                                ? '<span class="badge badge-ativo">Sim</span>'
                                : '<span class="badge badge-inativo">Não</span>' ?>
                        </td>
                        <td class="num"><?= $esp > 0 ? '<span class="badge badge-votos">'.number_format($esp,0,',','.').'</span>' : '<span class="vazio">—</span>' ?></td>
                        <td class="num"><?= $urn > 0 ? '<span class="badge badge-urnas">'.number_format($urn,0,',','.').'</span>' : '<span class="vazio">—</span>' ?></td>
                        <td class="num">
                            <?php if ($esp > 0 || $urn > 0): ?>
                                <span class="badge <?= $difCls ?>"><?= ($dif > 0 ? '+' : '') . number_format($dif, 0, ',', '.') ?></span>
                            <?php else: ?><span class="vazio">—</span><?php endif; ?>
                        </td>
                        <td class="centro">
                            <?= $pct !== null ? '<span class="badge '.$pctCls.'">'.number_format($pct,1,',','.').'%</span>' : '<span class="vazio">—</span>' ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sec"
                                    data-id="<?= (int)$l['id'] ?>"
                                    data-nome="<?= htmlspecialchars($l['nome']) ?>"
                                    data-coordenador-id="<?= (int)$l['coordenador_id'] ?>"
                                    data-coordenador-nome="<?= htmlspecialchars($l['coordenador_nome']) ?>">
                                📋 Distribuir seções
                            </button>
                            <?php if ($__ehAdmin): ?>
                                <button type="button" class="btn btn-editar" data-id="<?= (int)$l['id'] ?>">Editar</button>
                            <?php endif; ?>
                            <?php if ($isAdmin): ?>
                                <button type="button" class="btn btn-excluir"
                                        data-id="<?= (int)$l['id'] ?>"
                                        data-nome="<?= htmlspecialchars($l['nome']) ?>">Excluir</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="linha-total">
                    <td colspan="6" style="text-align:right;"><strong>Total</strong></td>
                    <td class="num"><?= number_format($totalEsperados, 0, ',', '.') ?></td>
                    <td class="num"><?= number_format($totalUrnas, 0, ',', '.') ?></td>
                    <td class="num"><?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?></td>
                    <td class="centro"><?= $totalPct !== null ? number_format($totalPct, 1, ',', '.') . '%' : '—' ?></td>
                    <td></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

<?php endif; ?>

<!-- =================== MODAL LIDERANÇA =================== -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modal-titulo">Nova Liderança</h2>
            <button type="button" class="fechar" id="btn-fechar">&times;</button>
        </div>
        <form id="form-lider">
            <input type="hidden" name="id" id="f-id" value="0">
            <div class="modal-body">

                <div class="campo">
                    <label for="f-coordenador">Coordenador *</label>
                    <select name="coordenador_id" id="f-coordenador" required>
                        <option value="">— Selecione —</option>
                        <?php foreach ($listaCoordenadores as $co): ?>
                            <option value="<?= (int)$co['id'] ?>"><?= htmlspecialchars($co['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label for="f-nome">Nome completo *</label>
                    <input type="text" name="nome" id="f-nome" required maxlength="200">
                </div>

                <div class="linha-2">
                    <div class="campo">
                        <label for="f-cpf">CPF</label>
                        <input type="text" name="cpf" id="f-cpf" maxlength="14">
                    </div>
                    <div class="campo">
                        <label for="f-telefone">Telefone</label>
                        <input type="text" name="telefone" id="f-telefone" maxlength="20">
                    </div>
                </div>

                <div class="campo">
                    <label for="f-email">E-mail</label>
                    <input type="email" name="email" id="f-email" maxlength="120">
                </div>

                <?php if ($temExpectativaLider): ?>
                <div class="campo destaque-exp">
                    <label for="f-expectativa">Expectativa de votos</label>
                    <input type="number" name="expectativa_votos" id="f-expectativa" min="0" step="1" value="0">
                </div>
                <?php endif; ?>

                <div class="campo">
                    <label for="f-observacoes">Observações</label>
                    <textarea name="observacoes" id="f-observacoes"></textarea>
                </div>

                <div class="campo toggle">
                    <input type="checkbox" name="ativo" id="f-ativo" value="1" checked>
                    <label for="f-ativo" style="margin:0;font-weight:normal;">Ativa</label>
                </div>
            </div>

            <div class="modal-footer">
                <span id="form-msg" style="float:left;font-size:13px;"></span>
                <button type="button" class="btn btn-cancelar" id="btn-cancelar">Cancelar</button>
                <button type="submit" class="btn btn-salvar" id="btn-salvar">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- =================== MODAL DISTRIBUIR SEÇÕES =================== -->
<div class="modal-overlay" id="modal-sec">
    <div class="modal modal-sec">
        <div class="modal-header">
            <h2 id="sec-titulo">Distribuir Seções</h2>
            <button type="button" class="fechar" id="btn-fechar-sec">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="s-lider-id">
            <input type="hidden" id="s-coord-id">

            <div class="sec-info">
                <b>Liderança:</b> <span id="s-lider-nome">—</span>
                &nbsp;·&nbsp;
                <b>Coordenador:</b> <span id="s-coord-nome">—</span>
                <br>
                <span style="font-size:11.5px;color:#666;">
                    As seções abaixo já estão no escopo do coordenador.
                    Selecione as que ficarão sob responsabilidade desta liderança.
                    Formato: <b>#Zzona-Ssecao</b>
                </span>
            </div>

            <!-- PREVIEW DAS SEÇÕES ATRIBUÍDAS -->
            <div id="secoes-preview-wrap" style="display:none;">
                <div style="font-size:11.5px;color:#666;margin-bottom:4px;">
                    <b>Seções atribuídas a esta liderança:</b>
                </div>
                <div class="secoes-preview" id="secoes-preview"></div>
            </div>

            <div class="stats-row" id="stats-row">
                <div class="stat">
                    <span class="n" id="st-universo">0</span>
                    Universo do coordenador
                </div>
                <div class="stat verde">
                    <span class="n" id="st-atribuidas">0</span>
                    Já atribuídas
                </div>
                <div class="stat roxo">
                    <span class="n" id="st-minhas">0</span>
                    Desta liderança
                </div>
                <div class="stat laranja">
                    <span class="n" id="st-disponiveis">0</span>
                    Disponíveis
                </div>
            </div>

            <div class="split">
                <div class="col disp">
                    <h3>⚪ Disponíveis (não atribuídas)
                        <span class="cont" id="disp-total">0</span>
                    </h3>
                    <div class="filtros-col">
                        <input type="text" id="disp-zona" class="input-zona" placeholder="Zona">
                        <input type="text" id="disp-filtro" placeholder="Filtrar seção/local/município...">
                    </div>
                    <div class="lista" id="lista-disp">
                        <div style="padding:20px;text-align:center;color:#999;">Carregando...</div>
                    </div>
                    <div class="acao">
                        <button type="button" class="btn-primary" id="btn-add-sel">→ Adicionar selecionadas</button>
                        <button type="button" class="btn-primary" id="btn-add-all">→ Adicionar todas</button>
                        <span class="info-mini"><span id="disp-count">0</span> marcadas</span>
                    </div>
                </div>

                <div class="col atrib">
                    <h3>🟢 Atribuídas a esta liderança
                        <span class="cont" id="atr-total">0</span>
                    </h3>
                    <div class="filtros-col">
                        <input type="text" id="atr-filtro" placeholder="Filtrar seção/local/município...">
                    </div>
                    <div class="lista" id="lista-atr">
                        <div style="padding:20px;text-align:center;color:#999;">Carregando...</div>
                    </div>
                    <div class="acao">
                        <button type="button" class="btn-danger" id="btn-rem-sel">← Remover selecionadas</button>
                        <span class="info-mini"><span id="atr-count">0</span> marcadas</span>
                    </div>
                </div>
            </div>

            <div id="sec-msg" style="margin-top:12px;font-size:13px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-cancelar" id="btn-fechar-sec-2">Fechar</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
    /* ============================================================
       FILTROS (Ano / Candidato)
       ============================================================ */
    function mudarAno(form) {
        if (form.candidato)   form.candidato.value   = '0';
        if (form.coordenador) form.coordenador.value = '0';
        form.submit();
    }
    function mudarCandidato(form) {
        if (form.coordenador) form.coordenador.value = '0';
        form.submit();
    }

    /* ============================================================
       DADOS
       ============================================================ */
    const LIDERANCAS  = <?= json_encode($liderancas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const TEM_EXP     = <?= $temExpectativaLider ? 'true' : 'false' ?>;
    const TEM_SEC     = <?= $temCoordEmLiderSecao ? 'true' : 'false' ?>;
    const PODE_EDITAR = <?= $__ehAdmin ? 'true' : 'false' ?>;
    const PODE_EXCLUIR= <?= $isAdmin   ? 'true' : 'false' ?>;

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g,
            m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    /* Monta a string #Zzona-Ssecao, separada por vírgulas */
    function montarListaSecoes(lista) {
        if (!lista || !lista.length) return '';
        return lista.map(s => '#Z' + s.numeroZona + '-S' + s.numeroSecao).join(', ');
    }

    /* Formata um único item */
    function fmtSecao(s) {
        return '#Z' + s.numeroZona + '-S' + s.numeroSecao;
    }

    (function () {
        /* ================== MODAL LIDERANÇA ================== */
        const $modal   = jQuery('#modal');
        const $form    = jQuery('#form-lider');
        const $titulo  = jQuery('#modal-titulo');
        const $msg     = jQuery('#form-msg');
        const $coord   = jQuery('#f-coordenador');
        const $exp     = jQuery('#f-expectativa');

        if ($coord.length) $coord.select2({
            language: 'pt-BR', placeholder: '— Selecione —', allowClear: true, width: '100%'
        });

        function limparForm() {
            $form[0].reset();
            jQuery('#f-id').val('0');
            if ($coord.length) $coord.val(null).trigger('change');
            if ($exp.length)   $exp.val('0');
            $msg.text('').css('color', '');
        }

        function abrirModal(id) {
            limparForm();
            if (id > 0) {
                const l = LIDERANCAS.find(x => parseInt(x.id) === id);
                if (!l) return;
                $titulo.text('Editar Liderança');
                jQuery('#f-id').val(l.id);
                jQuery('#f-nome').val(l.nome);
                jQuery('#f-cpf').val(l.cpf || '');
                jQuery('#f-telefone').val(l.telefone || '');
                jQuery('#f-email').val(l.email || '');
                jQuery('#f-observacoes').val(l.observacoes || '');
                jQuery('#f-ativo').prop('checked', parseInt(l.ativo) === 1);
                if (TEM_EXP && $exp.length) $exp.val(l.expectativa_votos != null ? l.expectativa_votos : 0);
                if ($coord.length) $coord.val(String(l.coordenador_id)).trigger('change');
            } else {
                $titulo.text('Nova Liderança');
            }
            $modal.addClass('aberto');
            setTimeout(() => jQuery('#f-nome').focus(), 100);
        }

        jQuery('#btn-novo').on('click', () => abrirModal(0));
        jQuery(document).on('click', '.btn-editar', function () {
            if (!PODE_EDITAR) return;
            abrirModal(parseInt(jQuery(this).data('id')));
        });
        jQuery('#btn-fechar, #btn-cancelar').on('click', () => $modal.removeClass('aberto'));
        $modal.on('click', function (e) { if (e.target === this) $modal.removeClass('aberto'); });

        $form.on('submit', function (e) {
            e.preventDefault();
            if (!PODE_EDITAR) { $msg.text('✘ Sem permissão.').css('color', '#dc3545'); return; }
            $msg.text('Salvando...').css('color', '#6c757d');
            const fd = new FormData(this);
            fetch('cadastrar_liderancas.php?ajax=salvar', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => {
                    if (j.ok) {
                        $msg.text('✔ ' + j.msg).css('color', '#28a745');
                        setTimeout(() => location.reload(), 600);
                    } else {
                        $msg.text('✘ ' + j.msg).css('color', '#dc3545');
                    }
                })
                .catch(() => $msg.text('✘ Erro de rede.').css('color', '#dc3545'));
        });

        jQuery(document).on('click', '.btn-excluir', function () {
            if (!PODE_EXCLUIR) return;
            const id = parseInt(jQuery(this).data('id'));
            const nome = jQuery(this).data('nome');
            if (!confirm('Excluir a liderança "' + nome + '"?\n\nAs seções atribuídas a ela serão removidas.')) return;
            const fd = new FormData();
            fd.append('id', id);
            fetch('cadastrar_liderancas.php?ajax=excluir', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => j.ok ? location.reload() : alert('Erro: ' + j.msg))
                .catch(() => alert('Erro de rede.'));
        });

        /* ================== MODAL DISTRIBUIR ================== */
        const $modalSec = jQuery('#modal-sec');
        const $sMsg     = jQuery('#sec-msg');
        const $preview  = jQuery('#secoes-preview');
        const $previewWrap = jQuery('#secoes-preview-wrap');
        let disponiveis = [];
        let atribuidas  = [];

        function atualizarPreview() {
            if (!atribuidas.length) {
                $previewWrap.hide();
                $preview.html('');
                return;
            }
            const texto = montarListaSecoes(atribuidas);
            const tags = texto.split(', ').map(t => '<span class="tag">' + escapeHtml(t) + '</span>').join('');
            $preview.html(tags);
            $previewWrap.show();
        }

        function atualizarStats() {
            const id  = parseInt(jQuery('#s-coord-id').val());
            const lid = parseInt(jQuery('#s-lider-id').val());
            if (!id) return;
            fetch('cadastrar_liderancas.php?ajax=stats_distribuicao&coordenador_id=' + id + '&lider_id=' + lid)
                .then(r => r.json())
                .then(j => {
                    if (!j.ok) return;
                    jQuery('#st-universo').text(j.universo);
                    jQuery('#st-atribuidas').text(j.atribuidas_total);
                    jQuery('#st-minhas').text(j.minhas);
                    jQuery('#st-disponiveis').text(j.disponiveis);
                });
        }

        function renderDisponiveis() {
            const filtro = (jQuery('#disp-filtro').val() || '').toString().toLowerCase().trim();
            const zona   = (jQuery('#disp-zona').val() || '').toString().trim();
            let list = disponiveis.slice();
            if (zona) list = list.filter(s => String(s.numeroZona) === zona);
            if (filtro) {
                list = list.filter(s =>
                    (String(s.numeroZona) + ' ' + String(s.numeroSecao) + ' ' +
                     (s.nomeMunicipio||'') + ' ' + (s.bairro||'') + ' ' + (s.nomeLocal||'')
                    ).toLowerCase().includes(filtro)
                );
            }
            jQuery('#disp-total').text(list.length);
            if (!list.length) {
                jQuery('#lista-disp').html('<div style="padding:20px;text-align:center;color:#999;">Nenhuma seção disponível.</div>');
            } else {
                let html = '';
                list.forEach(function (s) {
                    const local = [s.nomeLocal, s.bairro].filter(Boolean).join(' / ');
                    html += '<label>'
                         +    '<input type="checkbox" class="cb-disp" data-z="' + s.numeroZona + '" data-s="' + s.numeroSecao + '">'
                         +    '<span><b>' + fmtSecao(s) + '</b>'
                         +    (local ? ' · ' + escapeHtml(local) : '') + '</span>'
                         +    '<span class="mun">' + escapeHtml(s.nomeMunicipio || '') + '</span>'
                         + '</label>';
                });
                jQuery('#lista-disp').html(html);
            }
            jQuery('#disp-count').text(0);
        }

        function renderAtribuidas() {
            const filtro = (jQuery('#atr-filtro').val() || '').toString().toLowerCase().trim();
            let list = atribuidas.slice();
            if (filtro) {
                list = list.filter(s =>
                    (String(s.numeroZona) + ' ' + String(s.numeroSecao) + ' ' +
                     (s.nomeMunicipio||'') + ' ' + (s.bairro||'') + ' ' + (s.nomeLocal||'')
                    ).toLowerCase().includes(filtro)
                );
            }
            jQuery('#atr-total').text(list.length);
            if (!list.length) {
                jQuery('#lista-atr').html('<div style="padding:20px;text-align:center;color:#999;">Nenhuma seção atribuída.</div>');
            } else {
                let html = '';
                list.forEach(function (s) {
                    const local = [s.nomeLocal, s.bairro].filter(Boolean).join(' / ');
                    html += '<label>'
                         +    '<input type="checkbox" class="cb-atr" data-z="' + s.numeroZona + '" data-s="' + s.numeroSecao + '">'
                         +    '<span><b>' + fmtSecao(s) + '</b>'
                         +    (local ? ' · ' + escapeHtml(local) : '') + '</span>'
                         +    '<span class="mun">' + escapeHtml(s.nomeMunicipio || '') + '</span>'
                         + '</label>';
                });
                jQuery('#lista-atr').html(html);
            }
            jQuery('#atr-count').text(0);
            atualizarPreview();
        }

        function carregarDisponiveis() {
            const id  = parseInt(jQuery('#s-coord-id').val());
            const lid = parseInt(jQuery('#s-lider-id').val());
            if (!id) return;
            jQuery('#lista-disp').html('<div style="padding:20px;text-align:center;color:#999;">Carregando...</div>');
            const zon = jQuery('#disp-zona').val() || '';
            let url = 'cadastrar_liderancas.php?ajax=secoes_disponiveis&coordenador_id=' + id + '&lider_id=' + lid;
            if (zon) url += '&zona=' + encodeURIComponent(zon);
            fetch(url).then(r => r.json()).then(j => {
                disponiveis = j.secoes || [];
                renderDisponiveis();
                atualizarStats();
            }).catch(() => jQuery('#lista-disp').html('<div style="padding:20px;color:#dc3545;">Erro.</div>'));
        }

        function carregarAtribuidas() {
            const lid = parseInt(jQuery('#s-lider-id').val());
            if (!lid) return;
            jQuery('#lista-atr').html('<div style="padding:20px;text-align:center;color:#999;">Carregando...</div>');
            fetch('cadastrar_liderancas.php?ajax=secoes_atribuidas&lider_id=' + lid)
                .then(r => r.json())
                .then(j => {
                    atribuidas = j.secoes || [];
                    renderAtribuidas();
                    atualizarStats();
                })
                .catch(() => jQuery('#lista-atr').html('<div style="padding:20px;color:#dc3545;">Erro.</div>'));
        }

        jQuery(document).on('click', '.btn-sec', function () {
            if (!TEM_SEC) { alert('Coluna lider_secao.coordenador_id não existe. Rode o SQL.'); return; }
            const lid   = parseInt(jQuery(this).data('id'));
            const coord = parseInt(jQuery(this).data('coordenador-id'));
            const nome  = jQuery(this).data('nome');
            const cnome = jQuery(this).data('coordenador-nome');

            jQuery('#s-lider-id').val(lid);
            jQuery('#s-coord-id').val(coord);
            jQuery('#s-lider-nome').text(nome);
            jQuery('#s-coord-nome').text(cnome);
            jQuery('#sec-titulo').text('Distribuir Seções — ' + nome);

            jQuery('#disp-zona').val('');
            jQuery('#disp-filtro').val('');
            jQuery('#atr-filtro').val('');
            $sMsg.text('');
            $previewWrap.hide();
            $preview.html('');

            $modalSec.addClass('aberto');
            carregarDisponiveis();
            carregarAtribuidas();
        });

        jQuery('#btn-fechar-sec, #btn-fechar-sec-2').on('click', function () {
            $modalSec.removeClass('aberto');
            location.reload();
        });
        $modalSec.on('click', function (e) {
            if (e.target === this) { $modalSec.removeClass('aberto'); location.reload(); }
        });

        jQuery('#disp-zona').on('input', function () {
            clearTimeout(this._t);
            this._t = setTimeout(carregarDisponiveis, 400);
        });
        jQuery('#disp-filtro').on('input', renderDisponiveis);
        jQuery('#atr-filtro').on('input', renderAtribuidas);

        jQuery('#lista-disp').on('change', '.cb-disp', function () {
            jQuery('#disp-count').text(jQuery('.cb-disp:checked').length);
        });
        jQuery('#lista-atr').on('change', '.cb-atr', function () {
            jQuery('#atr-count').text(jQuery('.cb-atr:checked').length);
        });

        function enviarLote(acao, itens) {
            const lid = parseInt(jQuery('#s-lider-id').val());
            if (!lid) return Promise.resolve();
            const fd = new FormData();
            fd.append('lider_id', lid);
            fd.append('secoes', JSON.stringify(itens));
            return fetch('cadastrar_liderancas.php?ajax=' + acao, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => {
                    if (j.ok) {
                        $sMsg.text('✔ ' + (j.msg || 'OK')).css('color', '#28a745');
                    } else {
                        $sMsg.text('✘ ' + j.msg).css('color', '#dc3545');
                    }
                });
        }

        jQuery('#btn-add-sel').on('click', function () {
            const itens = [];
            jQuery('.cb-disp:checked').each(function () {
                itens.push({ z: parseInt(jQuery(this).data('z')), s: parseInt(jQuery(this).data('s')) });
            });
            if (!itens.length) { $sMsg.text('Nenhuma marcada.').css('color', '#856404'); return; }
            $sMsg.text('Adicionando ' + itens.length + '...').css('color', '#6c757d');
            enviarLote('atribuir_lote', itens).then(() => {
                carregarDisponiveis();
                carregarAtribuidas();
            });
        });

        jQuery('#btn-add-all').on('click', function () {
            const filtro = (jQuery('#disp-filtro').val() || '').toString().toLowerCase().trim();
            const zona   = (jQuery('#disp-zona').val() || '').toString().trim();
            let list = disponiveis.slice();
            if (zona) list = list.filter(s => String(s.numeroZona) === zona);
            if (filtro) {
                list = list.filter(s =>
                    (String(s.numeroZona) + ' ' + String(s.numeroSecao) + ' ' +
                     (s.nomeMunicipio||'') + ' ' + (s.bairro||'') + ' ' + (s.nomeLocal||'')
                    ).toLowerCase().includes(filtro)
                );
            }
            if (!list.length) { $sMsg.text('Nada para adicionar.').css('color', '#856404'); return; }
            if (!confirm('Adicionar TODAS as ' + list.length + ' seções visíveis?')) return;
            const itens = list.map(s => ({ z: parseInt(s.numeroZona), s: parseInt(s.numeroSecao) }));
            $sMsg.text('Adicionando ' + itens.length + '...').css('color', '#6c757d');
            enviarLote('atribuir_lote', itens).then(() => {
                carregarDisponiveis();
                carregarAtribuidas();
            });
        });

        jQuery('#btn-rem-sel').on('click', function () {
            const itens = [];
            jQuery('.cb-atr:checked').each(function () {
                itens.push({ z: parseInt(jQuery(this).data('z')), s: parseInt(jQuery(this).data('s')) });
            });
            if (!itens.length) { $sMsg.text('Nenhuma marcada.').css('color', '#856404'); return; }
            if (!confirm('Remover ' + itens.length + ' seção(ões) desta liderança?')) return;
            $sMsg.text('Removendo...').css('color', '#6c757d');
            enviarLote('remover_lote', itens).then(() => {
                carregarDisponiveis();
                carregarAtribuidas();
            });
        });

    })();
</script>

</body>
</html>