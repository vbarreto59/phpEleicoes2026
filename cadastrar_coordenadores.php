<?php
// ------------------------------------------------------------
// cadastrar_coordenadores.php
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';

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

$__rotuloPerfil = function_exists('rotuloPerfil')
                    ? rotuloPerfil($__perfil)
                    : strtoupper($__perfil);
$__classePerfil = function_exists('classePerfil')
                    ? classePerfil($__perfil)
                    : ('perfil-' . $__perfil);

// ============================================================
// DETECÇÃO DE COLUNAS
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

// Coluna de candidato em coordenadores
$colunasCoord = [];
foreach ($pdo->query("SHOW COLUMNS FROM coordenadores") as $c) $colunasCoord[] = $c['Field'];
$temCandidatoCoord = in_array('nomeCandidatoResp', $colunasCoord, true);

// Coluna de expectativa em liderancas
$colunasLider = [];
foreach ($pdo->query("SHOW COLUMNS FROM liderancas") as $c) $colunasLider[] = $c['Field'];
$candidatosExpect = [
    'expectativa_votos','expectativa','votos_esperados','qtd_esperada',
    'qtde_esperada','previsao','meta','previsao_votos','votos_previstos',
];
$COL_EXPECT = null;
foreach ($candidatosExpect as $cand) {
    if (in_array($cand, $colunasLider, true)) { $COL_EXPECT = $cand; break; }
}
$EXPECT_EXPR = $COL_EXPECT ? "l.`$COL_EXPECT`" : "0";

// ============================================================
// ENDPOINTS AJAX
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $acao = $_GET['ajax'];

        if ($acao === 'salvar') {
            if (!$__ehAdmin) {
                throw new Exception('Sem permissão para salvar.');
            }

            $id            = (int)($_POST['id'] ?? 0);
            $candidatoNome = trim($_POST['nomeCandidatoResp'] ?? '');
            $nome          = trim($_POST['nome'] ?? '');
            $cpf           = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            $telefone      = trim($_POST['telefone'] ?? '');
            $email         = trim($_POST['email'] ?? '');
            $observacoes   = trim($_POST['observacoes'] ?? '');
            $ativo         = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;

            $zonas = $_POST['zonas'] ?? [];
            if (!is_array($zonas)) $zonas = [$zonas];
            $zonas = array_values(array_unique(array_map('intval', $zonas)));
            $zonas = array_filter($zonas, fn($z) => $z > 0);

            if ($nome === '')      throw new Exception('Informe o nome.');
            if ($candidatoNome === '' && $temCandidatoCoord) {
                throw new Exception('Selecione o candidato.');
            }

            $pdo->beginTransaction();

            if ($id > 0) {
                if ($temCandidatoCoord) {
                    $pdo->prepare(
                        "UPDATE coordenadores
                            SET nomeCandidatoResp = :cand,
                                nome = :n, cpf = :c, telefone = :t,
                                email = :e, observacoes = :o, ativo = :a
                          WHERE id = :id"
                    )->execute([
                        ':cand' => $candidatoNome !== '' ? $candidatoNome : null,
                        ':n'    => $nome,
                        ':c'    => $cpf !== '' ? $cpf : null,
                        ':t'    => $telefone !== '' ? $telefone : null,
                        ':e'    => $email !== '' ? $email : null,
                        ':o'    => $observacoes !== '' ? $observacoes : null,
                        ':a'    => $ativo,
                        ':id'   => $id,
                    ]);
                } else {
                    $pdo->prepare(
                        "UPDATE coordenadores
                            SET nome = :n, cpf = :c, telefone = :t,
                                email = :e, observacoes = :o, ativo = :a
                          WHERE id = :id"
                    )->execute([
                        ':n'  => $nome,
                        ':c'  => $cpf !== '' ? $cpf : null,
                        ':t'  => $telefone !== '' ? $telefone : null,
                        ':e'  => $email !== '' ? $email : null,
                        ':o'  => $observacoes !== '' ? $observacoes : null,
                        ':a'  => $ativo,
                        ':id' => $id,
                    ]);
                }
            } else {
                if ($temCandidatoCoord) {
                    $pdo->prepare(
                        "INSERT INTO coordenadores
                            (nomeCandidatoResp, nome, cpf, telefone, email, observacoes, ativo)
                         VALUES (:cand, :n, :c, :t, :e, :o, :a)"
                    )->execute([
                        ':cand' => $candidatoNome !== '' ? $candidatoNome : null,
                        ':n'    => $nome,
                        ':c'    => $cpf !== '' ? $cpf : null,
                        ':t'    => $telefone !== '' ? $telefone : null,
                        ':e'    => $email !== '' ? $email : null,
                        ':o'    => $observacoes !== '' ? $observacoes : null,
                        ':a'    => $ativo,
                    ]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO coordenadores
                            (nome, cpf, telefone, email, observacoes, ativo)
                         VALUES (:n, :c, :t, :e, :o, :a)"
                    )->execute([
                        ':n' => $nome,
                        ':c' => $cpf !== '' ? $cpf : null,
                        ':t' => $telefone !== '' ? $telefone : null,
                        ':e' => $email !== '' ? $email : null,
                        ':o' => $observacoes !== '' ? $observacoes : null,
                        ':a' => $ativo,
                    ]);
                }
                $id = (int)$pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM coordenador_zona WHERE coordenador_id = :id")
                ->execute([':id' => $id]);

            if ($zonas) {
                $stmt = $pdo->prepare(
                    "INSERT INTO coordenador_zona (coordenador_id, numeroZona)
                     VALUES (:id, :z)"
                );
                foreach ($zonas as $z) $stmt->execute([':id' => $id, ':z' => $z]);
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'id' => $id, 'msg' => 'Salvo com sucesso.']);
            exit;
        }

        if ($acao === 'excluir') {
            if (!$isAdmin) {
                throw new Exception('Sem permissão para excluir.');
            }

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido.');

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM liderancas WHERE coordenador_id = :id");
            $stmt->execute([':id' => $id]);
            $qtd = (int)$stmt->fetchColumn();
            if ($qtd > 0) {
                throw new Exception(
                    "Não é possível excluir: há {$qtd} liderança(s) vinculada(s)."
                );
            }

            $pdo->prepare("DELETE FROM coordenadores WHERE id = :id")->execute([':id' => $id]);
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

// Filtro por candidato (via GET)
$filtroCandidato = trim($_GET['candidato'] ?? '');

// Lista de candidatos (para select do filtro e do modal)
$listaCandidatos = $pdo->query(
    "SELECT DISTINCT nomeCandidatoResp
       FROM votos
      WHERE nomeCandidatoResp IS NOT NULL AND nomeCandidatoResp <> ''
      ORDER BY nomeCandidatoResp"
)->fetchAll(PDO::FETCH_COLUMN);

// Zonas
$zonas = $pdo->query(
    "SELECT DISTINCT numeroZona FROM secoes_enderecos ORDER BY numeroZona"
)->fetchAll(PDO::FETCH_COLUMN);
if (!$zonas) {
    $zonas = $pdo->query(
        "SELECT DISTINCT numeroZona FROM votos ORDER BY numeroZona"
    )->fetchAll(PDO::FETCH_COLUMN);
}

// Query principal
$where  = '';
$params = [];
if ($filtroCandidato !== '' && $temCandidatoCoord) {
    $where  = "WHERE c.nomeCandidatoResp = :cand";
    $params[':cand'] = $filtroCandidato;
}

$colCandSelect = $temCandidatoCoord ? "c.nomeCandidatoResp AS candidato," : "NULL AS candidato,";

$sql = "
    SELECT c.*,
        $colCandSelect

        (SELECT GROUP_CONCAT(DISTINCT cz.numeroZona ORDER BY cz.numeroZona SEPARATOR ',')
           FROM coordenador_zona cz
          WHERE cz.coordenador_id = c.id) AS zonas_str,

        (SELECT COUNT(*) FROM liderancas l
          WHERE l.coordenador_id = c.id) AS qtd_liderancas,

        (SELECT COUNT(*) FROM liderancas l
          WHERE l.coordenador_id = c.id AND l.ativo = 1) AS qtd_ativas,

        (SELECT COALESCE(SUM($EXPECT_EXPR), 0)
           FROM liderancas l
          WHERE l.coordenador_id = c.id AND l.ativo = 1) AS votos_esperados,

        (SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
           FROM votos v
          WHERE (v.numeroZona, v.numeroSecao) IN (
                SELECT DISTINCT ls.numeroZona, ls.numeroSecao
                  FROM lider_secao ls
                  INNER JOIN liderancas l2 ON l2.id = ls.lider_id
                 WHERE l2.coordenador_id = c.id AND l2.ativo = 1
          )) AS votos_urnas

    FROM coordenadores c
    $where
    ORDER BY votos_esperados DESC, c.nome
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$coordenadores = $stmt->fetchAll();

foreach ($coordenadores as &$c) {
    $c['zonas'] = !empty($c['zonas_str'])
        ? array_map('intval', explode(',', $c['zonas_str'])) : [];
    $esp = (int)$c['votos_esperados'];
    $urn = (int)$c['votos_urnas'];
    $c['diferenca'] = $urn - $esp;
    $c['pct'] = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
}
unset($c);

// Totais
$totalCoords = count($coordenadores);
$totalAtivos = 0;
$totalLiderancas = 0;
$totalLiderancasAtiv = 0;
$totalVotosEsperados = 0;
$totalVotosUrnas = 0;
foreach ($coordenadores as $c) {
    if ((int)$c['ativo'] === 1) $totalAtivos++;
    $totalLiderancas     += (int)$c['qtd_liderancas'];
    $totalLiderancasAtiv += (int)$c['qtd_ativas'];
    $totalVotosEsperados += (int)$c['votos_esperados'];
    $totalVotosUrnas     += (int)$c['votos_urnas'];
}
$totalDiferenca = $totalVotosUrnas - $totalVotosEsperados;
$totalPct = $totalVotosEsperados > 0
    ? round(($totalVotosUrnas / $totalVotosEsperados) * 100, 1) : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Coordenadores</title>
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
    .btn-novo { background:#28a745; color:#fff; }
    .btn-novo:hover { background:#1e7e34; }
    .btn-editar{ background:#0069d9; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-editar:hover { background:#0053b3; }
    .btn-excluir{ background:#dc3545; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-excluir:hover { background:#b02a37; }
    .btn-salvar { background:#28a745; color:#fff; padding:10px 18px; font-size:14px; }
    .btn-salvar:hover { background:#1e7e34; }
    .btn-cancelar { background:#6c757d; color:#fff; padding:10px 18px; font-size:14px; }
    .btn-cancelar:hover { background:#5a6268; }

    .form-filtros {
        background:#fff; padding:14px 20px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
        display:flex; flex-wrap:wrap; align-items:center; gap:12px;
    }
    .form-filtros label { font-weight:bold; font-size:14px; }
    .form-filtros button { padding:8px 16px; border-radius:4px; border:none;
        cursor:pointer; font-size:14px; font-weight:bold; }
    .btn-consultar { background:#0069d9; color:#fff; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar-form { background:#6c757d; color:#fff; text-decoration:none;
        padding:8px 16px; border-radius:4px; font-size:14px; font-weight:bold;
        display:inline-block; }
    .btn-limpar-form:hover { background:#5a6268; }

    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; font-size:13px; }
    th { background:#0069d9; color:#fff; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#eef6ff; }
    tr.linha-total { background:#eef6ff; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #0069d9; }
    .num { text-align:right; }
    .centro { text-align:center; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-zona  { background:#e6f0fa; color:#0069d9; }
    .badge-lider { background:#f0e6ff; color:#6f42c1; }
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
    .badge-cand  { background:#e7d9ff; color:#4a1d96; font-weight:bold; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    .info-col {
        font-size:12px; color:#666; margin-bottom:12px;
        background:#fff; padding:8px 14px; border-radius:6px;
        border-left:4px solid #17a2b8;
        box-shadow: 0 1px 2px rgba(0,0,0,.05);
    }
    .info-col code { background:#f4f4f4; padding:1px 5px; border-radius:3px; }
    .info-col .aviso { color:#856404; background:#fff3cd;
        padding:2px 8px; border-radius:6px; font-weight:bold; margin-left:6px; }

    .modal-overlay { display:none; position: fixed; inset: 0;
        background: rgba(0,0,0,.5); z-index: 1000;
        align-items: center; justify-content: center; padding: 20px; }
    .modal-overlay.aberto { display: flex; }
    .modal { background:#fff; border-radius: 10px; max-width: 640px; width: 100%;
        max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,.3); }
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
    .campo input[type=text], .campo input[type=email],
    .campo textarea, .campo select {
        width: 100%; padding: 8px 10px; border: 1px solid #ccc;
        border-radius: 4px; font-size: 14px; font-family: inherit; box-sizing: border-box;
    }
    .campo textarea { resize: vertical; min-height: 60px; }
    .linha-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .toggle { display:flex; align-items:center; gap:8px; }
    .toggle input { width:auto; }

    .select2-container { width: 100% !important; }
    .select2-container--default .select2-selection--single,
    .select2-container--default .select2-selection--multiple {
        min-height: 40px; padding: 4px 6px; border: 1px solid #ccc; border-radius: 4px;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background: #0069d9; color: #fff; border: none; padding: 2px 8px; font-size: 12px;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        color: #fff; margin-right: 4px;
    }
    .select2-search--dropdown .select2-search__field { padding: 6px; font-size: 14px; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

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

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

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

<h1>Coordenadores</h1>

<p class="info-col">
    Coluna de votos em <code>votos</code>: <strong><?= htmlspecialchars($COL_VOTOS) ?></strong>
    &nbsp;·&nbsp;
    Coluna de expectativa em <code>liderancas</code>:
    <?php if ($COL_EXPECT): ?><strong><?= htmlspecialchars($COL_EXPECT) ?></strong>
    <?php else: ?><span class="aviso">não existe — usando 0</span><?php endif; ?>
    &nbsp;·&nbsp;
    Coluna <code>nomeCandidatoResp</code> em <code>coordenadores</code>:
    <?php if ($temCandidatoCoord): ?><strong>existe</strong>
    <?php else: ?><span class="aviso">NÃO EXISTE — rode o SQL</span><?php endif; ?>
</p>

<!-- ============================================================
     FILTRO POR CANDIDATO
     ============================================================ -->
<form method="get" action="cadastrar_coordenadores.php" class="form-filtros">
    <label for="candidato">Candidato:</label>
    <select name="candidato" id="candidato" style="min-width:320px;">
        <option value="">— Todos os candidatos —</option>
        <?php foreach ($listaCandidatos as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"
                <?= $c === $filtroCandidato ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="btn-consultar">🔎 Consultar</button>
    <a href="cadastrar_coordenadores.php" class="btn-limpar-form">✖ Limpar</a>
</form>

<div class="kpis">
    <div class="kpi">
        <div class="rotulo">Coordenadores<?= $filtroCandidato ? ' (filtrado)' : '' ?></div>
        <div class="valor"><?= number_format($totalCoords, 0, ',', '.') ?></div>
    </div>
    <div class="kpi verde">
        <div class="rotulo">Ativos</div>
        <div class="valor"><?= number_format($totalAtivos, 0, ',', '.') ?></div>
    </div>
    <div class="kpi roxo">
        <div class="rotulo">Lideranças subordinadas</div>
        <div class="valor"><?= number_format($totalLiderancas, 0, ',', '.') ?></div>
        <div class="mini"><?= number_format($totalLiderancasAtiv, 0, ',', '.') ?> ativas</div>
    </div>
    <div class="kpi laranja">
        <div class="rotulo">Votos esperados</div>
        <div class="valor"><?= number_format($totalVotosEsperados, 0, ',', '.') ?></div>
    </div>
    <div class="kpi teal">
        <div class="rotulo">Votos das urnas</div>
        <div class="valor"><?= number_format($totalVotosUrnas, 0, ',', '.') ?></div>
    </div>
    <div class="kpi <?= $totalDiferenca >= 0 ? 'verde' : 'vermelho' ?>">
        <div class="rotulo">Diferença</div>
        <div class="valor">
            <?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?>
        </div>
        <div class="mini">
            <?= $totalPct !== null
                ? number_format($totalPct, 1, ',', '.') . '% do esperado'
                : 'sem base' ?>
        </div>
    </div>
</div>

<?php if ($__ehAdmin): ?>
<div style="margin-bottom:15px;">
    <button type="button" class="btn btn-novo" id="btn-novo">+ Novo Coordenador</button>
</div>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th style="width:40px;">#</th>
            <th>Candidato</th>
            <th>Nome</th>
            <th style="width:100px;">Zonas</th>
            <th class="centro" style="width:70px;">Líderes</th>
            <th class="centro" style="width:70px;">Ativas</th>
            <th class="num" style="width:110px;">Esperados</th>
            <th class="num" style="width:110px;">Urnas</th>
            <th class="num" style="width:100px;">Diferença</th>
            <th class="centro" style="width:80px;">%</th>
            <th style="width:70px;">Status</th>
            <th style="width:150px;">Ações</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$coordenadores): ?>
            <tr><td colspan="12" style="text-align:center;color:#999;padding:20px;">
                Nenhum coordenador<?= $filtroCandidato ? ' para este candidato' : '' ?>.
            </td></tr>
        <?php else: ?>
            <?php $i = 1; foreach ($coordenadores as $c): ?>
                <?php
                $esp = (int)$c['votos_esperados'];
                $urn = (int)$c['votos_urnas'];
                $dif = (int)$c['diferenca'];
                $pct = $c['pct'];
                if ($pct === null)   $pctCls = 'badge-zero';
                elseif ($pct >= 100) $pctCls = 'badge-pct-ok';
                elseif ($pct >= 70)  $pctCls = 'badge-pct-med';
                else                 $pctCls = 'badge-pct-ruim';
                if ($dif > 0)     $difCls = 'badge-pos';
                elseif ($dif < 0) $difCls = 'badge-neg';
                else              $difCls = 'badge-zero';
                ?>
                <tr data-id="<?= (int)$c['id'] ?>">
                    <td><?= $i++ ?></td>
                    <td>
                        <?php if (!empty($c['candidato'])): ?>
                            <span class="badge badge-cand"><?= htmlspecialchars($c['candidato']) ?></span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= htmlspecialchars($c['nome']) ?></strong></td>
                    <td>
                        <?php if ($c['zonas']): foreach ($c['zonas'] as $z): ?>
                            <span class="badge badge-zona"><?= (int)$z ?></span>
                        <?php endforeach; else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="centro">
                        <?= (int)$c['qtd_liderancas'] > 0
                            ? '<span class="badge badge-lider">' . (int)$c['qtd_liderancas'] . '</span>'
                            : '<span class="vazio">0</span>' ?>
                    </td>
                    <td class="centro"><?= (int)$c['qtd_ativas'] > 0 ? (int)$c['qtd_ativas'] : '<span class="vazio">0</span>' ?></td>
                    <td class="num"><?= $esp > 0 ? '<span class="badge badge-votos">' . number_format($esp,0,',','.') . '</span>' : '<span class="vazio">—</span>' ?></td>
                    <td class="num"><?= $urn > 0 ? '<span class="badge badge-urnas">' . number_format($urn,0,',','.') . '</span>' : '<span class="vazio">—</span>' ?></td>
                    <td class="num">
                        <?php if ($esp > 0 || $urn > 0): ?>
                            <span class="badge <?= $difCls ?>">
                                <?= ($dif > 0 ? '+' : '') . number_format($dif, 0, ',', '.') ?>
                            </span>
                        <?php else: ?><span class="vazio">—</span><?php endif; ?>
                    </td>
                    <td class="centro">
                        <?= $pct !== null
                            ? '<span class="badge ' . $pctCls . '">' . number_format($pct,1,',','.') . '%</span>'
                            : '<span class="vazio">—</span>' ?>
                    </td>
                    <td>
                        <?= (int)$c['ativo'] === 1
                            ? '<span class="badge badge-ativo">Ativo</span>'
                            : '<span class="badge badge-inativo">Inativo</span>' ?>
                    </td>
                    <td>
                        <?php if ($__ehAdmin): ?>
                            <button type="button" class="btn btn-editar" data-id="<?= (int)$c['id'] ?>">Editar</button>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <button type="button" class="btn btn-excluir"
                                    data-id="<?= (int)$c['id'] ?>"
                                    data-nome="<?= htmlspecialchars($c['nome']) ?>"
                                    data-liderancas="<?= (int)$c['qtd_liderancas'] ?>">Excluir</button>
                        <?php endif; ?>
                        <?php if (!$__ehAdmin && !$isAdmin): ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr class="linha-total">
                <td colspan="4" style="text-align:right;"><strong>Total</strong></td>
                <td class="centro"><?= number_format($totalLiderancas, 0, ',', '.') ?></td>
                <td class="centro"><?= number_format($totalLiderancasAtiv, 0, ',', '.') ?></td>
                <td class="num"><?= number_format($totalVotosEsperados, 0, ',', '.') ?></td>
                <td class="num"><?= number_format($totalVotosUrnas, 0, ',', '.') ?></td>
                <td class="num"><?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?></td>
                <td class="centro"><?= $totalPct !== null ? number_format($totalPct, 1, ',', '.') . '%' : '—' ?></td>
                <td colspan="2"></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Modal -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modal-titulo">Novo Coordenador</h2>
            <button type="button" class="fechar" id="btn-fechar">&times;</button>
        </div>
        <form id="form-coord">
            <input type="hidden" name="id" id="f-id" value="0">
            <div class="modal-body">

                <?php if ($temCandidatoCoord): ?>
                <div class="campo">
                    <label for="f-candidato">Candidato *</label>
                    <select name="nomeCandidatoResp" id="f-candidato" required>
                        <option value="">— Selecione —</option>
                        <?php foreach ($listaCandidatos as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"
                                <?= $c === $filtroCandidato ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="campo">
                    <label for="f-nome">Nome completo *</label>
                    <input type="text" name="nome" id="f-nome" required maxlength="200">
                </div>

                <div class="linha-2">
                    <div class="campo">
                        <label for="f-cpf">CPF</label>
                        <input type="text" name="cpf" id="f-cpf" maxlength="14" placeholder="000.000.000-00">
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

                <div class="campo">
                    <label for="f-zonas">Zonas sob responsabilidade</label>
                    <select name="zonas[]" id="f-zonas" multiple>
                        <?php foreach ($zonas as $z): ?>
                            <option value="<?= (int)$z ?>">Zona <?= (int)$z ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label for="f-observacoes">Observações</label>
                    <textarea name="observacoes" id="f-observacoes"></textarea>
                </div>

                <div class="campo toggle">
                    <input type="checkbox" name="ativo" id="f-ativo" value="1" checked>
                    <label for="f-ativo" style="margin:0;font-weight:normal;">Ativo</label>
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
    const COORDENADORES = <?= json_encode($coordenadores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const TEM_CAND      = <?= $temCandidatoCoord ? 'true' : 'false' ?>;
    const FILTRO_CAND   = <?= json_encode($filtroCandidato, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const PODE_EDITAR   = <?= $__ehAdmin ? 'true' : 'false' ?>;
    const PODE_EXCLUIR  = <?= $isAdmin   ? 'true' : 'false' ?>;

    (function () {
        const $modal  = jQuery('#modal');
        const $form   = jQuery('#form-coord');
        const $titulo = jQuery('#modal-titulo');
        const $msg    = jQuery('#form-msg');
        const $zonas  = jQuery('#f-zonas');
        const $cand   = jQuery('#f-candidato');

        $zonas.select2({
            language: 'pt-BR', placeholder: 'Selecione as zonas...',
            allowClear: true, closeOnSelect: false, width: '100%'
        });

        if ($cand.length) {
            $cand.select2({
                language: 'pt-BR', placeholder: '— Selecione —',
                allowClear: true, width: '100%'
            });
        }

        function limparForm() {
            $form[0].reset();
            jQuery('#f-id').val('0');
            $zonas.val(null).trigger('change');
            if ($cand.length) $cand.val(null).trigger('change');
            if (TEM_CAND && FILTRO_CAND) {
                $cand.val(FILTRO_CAND).trigger('change');
            }
            $msg.text('').css('color', '');
        }

        function abrirModal(id) {
            limparForm();
            if (id > 0) {
                const c = COORDENADORES.find(x => parseInt(x.id) === id);
                if (!c) return;
                $titulo.text('Editar Coordenador');
                jQuery('#f-id').val(c.id);
                jQuery('#f-nome').val(c.nome);
                jQuery('#f-cpf').val(c.cpf || '');
                jQuery('#f-telefone').val(c.telefone || '');
                jQuery('#f-email').val(c.email || '');
                jQuery('#f-observacoes').val(c.observacoes || '');
                jQuery('#f-ativo').prop('checked', parseInt(c.ativo) === 1);
                if (c.zonas && c.zonas.length) $zonas.val(c.zonas.map(String)).trigger('change');
                if (TEM_CAND && c.candidato) {
                    $cand.val(c.candidato).trigger('change');
                }
            } else {
                $titulo.text('Novo Coordenador');
            }
            $modal.addClass('aberto');
            setTimeout(() => jQuery('#f-nome').focus(), 100);
        }

        function fecharModal() { $modal.removeClass('aberto'); }

        jQuery('#btn-novo').on('click', () => abrirModal(0));

        jQuery(document).on('click', '.btn-editar', function () {
            if (!PODE_EDITAR) return;
            abrirModal(parseInt(jQuery(this).data('id')));
        });

        jQuery(document).on('click', '.btn-excluir', function () {
            if (!PODE_EXCLUIR) return;
            const id   = parseInt(jQuery(this).data('id'));
            const nome = jQuery(this).data('nome');
            const qtd  = parseInt(jQuery(this).data('liderancas')) || 0;
            if (qtd > 0) {
                alert('Não é possível excluir "' + nome + '".\n\n' +
                      'Há ' + qtd + ' liderança(s) vinculada(s).');
                return;
            }
            if (!confirm('Excluir o coordenador "' + nome + '"?')) return;

            const fd = new FormData();
            fd.append('id', id);
            fetch('cadastrar_coordenadores.php?ajax=excluir', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => j.ok ? location.reload() : alert('Erro: ' + j.msg))
                .catch(() => alert('Erro de rede.'));
        });

        jQuery('#btn-fechar, #btn-cancelar').on('click', fecharModal);
        $modal.on('click', function (e) { if (e.target === this) fecharModal(); });

        $form.on('submit', function (e) {
            e.preventDefault();
            if (!PODE_EDITAR) {
                $msg.text('✘ Sem permissão.').css('color', '#dc3545');
                return;
            }
            $msg.text('Salvando...').css('color', '#6c757d');
            const fd = new FormData(this);
            fd.delete('zonas[]');
            $zonas.find('option:selected').each(function () {
                fd.append('zonas[]', jQuery(this).val());
            });
            fetch('cadastrar_coordenadores.php?ajax=salvar', { method: 'POST', body: fd })
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
    })();
</script>

</body>
</html>