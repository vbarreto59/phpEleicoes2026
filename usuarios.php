<?php
// ------------------------------------------------------------
// usuarios.php — Gerenciamento de usuários
// Acesso restrito a administradores
// Senhas em TEXTO PURO (sem hash)
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';

// ------------------------------------------------------------
// Proteção: só admin / superadmin
// ------------------------------------------------------------
if (!ehAdmin()) {
    http_response_code(403);
    die('
        <div style="font-family:Arial;padding:40px;max-width:600px;margin:60px auto;
                    background:#fdecea;border-left:5px solid #dc3545;border-radius:6px;">
            <h2 style="margin:0 0 10px;color:#a71d2a;">🚫 Acesso restrito</h2>
            <p style="margin:0 0 12px;">Esta página é exclusiva para administradores.</p>
            <a href="index.php" style="color:#0069d9;">&larr; Voltar ao menu</a>
        </div>
    ');
}

$__u = usuarioLogado();

// ------------------------------------------------------------
// Detecta se a coluna `senha_visivel` existe
// ------------------------------------------------------------
$TEM_SENHA_VISIVEL = false;
try {
    foreach ($pdo->query("SHOW COLUMNS FROM usuarios") as $col) {
        if ($col['Field'] === 'senha_visivel') { $TEM_SENHA_VISIVEL = true; break; }
    }
} catch (Throwable $t) {
    $TEM_SENHA_VISIVEL = false;
}

// ------------------------------------------------------------
// Endpoints AJAX
// ------------------------------------------------------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $acao = $_GET['ajax'];

        // ====================================================
        // SALVAR (criar / editar)
        // ====================================================
        if ($acao === 'salvar') {
            $id       = (int)($_POST['id'] ?? 0);
            $usuario  = strtolower(trim($_POST['usuario'] ?? ''));
            $nome     = trim($_POST['nome'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $perfil   = $_POST['perfil'] ?? 'consulta';
            $ativo    = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            $senha    = (string)($_POST['senha'] ?? '');
            $confirma = (string)($_POST['senha_confirma'] ?? '');

            // ---------- Validações ----------
            if ($nome === '')    throw new Exception('Informe o nome completo.');
            if ($usuario === '') throw new Exception('Informe o usuário (login).');

            if (!preg_match('/^[a-z0-9._-]{3,60}$/', $usuario)) {
                throw new Exception(
                    'Usuário inválido. Use 3 a 60 caracteres: ' .
                    'letras (sem acento), números, ponto, hífen ou underline.'
                );
            }

            if (!in_array($perfil, ['superadmin', 'admin', 'consulta'], true)) {
                throw new Exception('Perfil inválido.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('E-mail inválido.');
            }

            // ---------- Unicidade de usuário ----------
            $sqlCheck = "SELECT id FROM usuarios WHERE usuario = :u";
            $parCheck = [':u' => $usuario];
            if ($id > 0) { $sqlCheck .= " AND id <> :id"; $parCheck[':id'] = $id; }
            $st = $pdo->prepare($sqlCheck);
            $st->execute($parCheck);
            if ($st->fetchColumn()) {
                throw new Exception("Já existe um usuário cadastrado com o login \"{$usuario}\".");
            }

            // ---------- Unicidade de e-mail ----------
            if ($email !== '') {
                $sqlEm = "SELECT id FROM usuarios WHERE email = :e";
                $parEm = [':e' => $email];
                if ($id > 0) { $sqlEm .= " AND id <> :id"; $parEm[':id'] = $id; }
                $st = $pdo->prepare($sqlEm);
                $st->execute($parEm);
                if ($st->fetchColumn()) {
                    throw new Exception("O e-mail \"{$email}\" já está em uso.");
                }
            }

            // ---------- Senha ----------
            $trocarSenha = false;
            if ($id === 0) {
                if ($senha === '') throw new Exception('Informe a senha para o novo usuário.');
                $trocarSenha = true;
            } elseif ($senha !== '') {
                $trocarSenha = true;
            }

            if ($trocarSenha) {
                if (strlen($senha) < 6) throw new Exception('A senha deve ter no mínimo 6 caracteres.');
                if ($senha !== $confirma) throw new Exception('As senhas não coincidem.');
            }

            // ---------- Regras de segurança ----------
            if ($id > 0 && $id === (int)$__u['id']) {
                if ($perfil !== $__u['perfil']) {
                    throw new Exception('Você não pode alterar seu próprio perfil.');
                }
                if ($ativo !== 1) {
                    throw new Exception('Você não pode desativar sua própria conta.');
                }
            }

            if ($id > 0) {
                $st = $pdo->prepare("SELECT perfil, ativo FROM usuarios WHERE id = :id");
                $st->execute([':id' => $id]);
                $atual = $st->fetch();

                $eraAdminAtivo  = $atual && in_array($atual['perfil'], ['admin','superadmin'], true)
                                  && (int)$atual['ativo'] === 1;
                $ficaAdminAtivo = in_array($perfil, ['admin','superadmin'], true) && $ativo === 1;

                if ($eraAdminAtivo && !$ficaAdminAtivo) {
                    $qtd = (int)$pdo->query(
                        "SELECT COUNT(*) FROM usuarios
                          WHERE perfil IN ('admin','superadmin') AND ativo = 1"
                    )->fetchColumn();
                    if ($qtd <= 1) {
                        throw new Exception(
                            'Este é o único administrador ativo. ' .
                            'Cadastre outro antes de rebaixar ou desativar este.'
                        );
                    }
                }
            }

            // ---------- Persistência ----------
            $pdo->beginTransaction();

            if ($id > 0) {
                // -------- UPDATE --------
                if ($trocarSenha) {
                    // Senha em TEXTO PURO (sem hash)
                    if ($TEM_SENHA_VISIVEL) {
                        $pdo->prepare(
                            "UPDATE usuarios
                                SET usuario = :u, nome = :n, email = :e,
                                    perfil = :p, ativo = :a,
                                    senha_hash = :h, senha_visivel = :sv
                              WHERE id = :id"
                        )->execute([
                            ':u'  => $usuario,
                            ':n'  => $nome,
                            ':e'  => $email !== '' ? $email : null,
                            ':p'  => $perfil,
                            ':a'  => $ativo,
                            ':h'  => $senha,      // texto puro
                            ':sv' => $senha,      // mesmo valor
                            ':id' => $id,
                        ]);
                    } else {
                        $pdo->prepare(
                            "UPDATE usuarios
                                SET usuario = :u, nome = :n, email = :e,
                                    perfil = :p, ativo = :a, senha_hash = :h
                              WHERE id = :id"
                        )->execute([
                            ':u'  => $usuario,
                            ':n'  => $nome,
                            ':e'  => $email !== '' ? $email : null,
                            ':p'  => $perfil,
                            ':a'  => $ativo,
                            ':h'  => $senha,      // texto puro
                            ':id' => $id,
                        ]);
                    }
                } else {
                    $pdo->prepare(
                        "UPDATE usuarios
                            SET usuario = :u, nome = :n, email = :e,
                                perfil = :p, ativo = :a
                          WHERE id = :id"
                    )->execute([
                        ':u'  => $usuario,
                        ':n'  => $nome,
                        ':e'  => $email !== '' ? $email : null,
                        ':p'  => $perfil,
                        ':a'  => $ativo,
                        ':id' => $id,
                    ]);
                }

                // Se alterei meu próprio cadastro, atualiza a sessão
                if ($id === (int)$__u['id']) {
                    $_SESSION['usuario_nome']   = $nome;
                    $_SESSION['usuario_login']  = $usuario;
                    $_SESSION['usuario_perfil'] = $perfil;
                    $_SESSION['usuario_ativo']  = $ativo;
                }

            } else {
                // -------- INSERT --------
                if ($TEM_SENHA_VISIVEL) {
                    $pdo->prepare(
                        "INSERT INTO usuarios
                            (usuario, senha_hash, senha_visivel, nome, email, perfil, ativo)
                         VALUES (:u, :h, :sv, :n, :e, :p, :a)"
                    )->execute([
                        ':u'  => $usuario,
                        ':h'  => $senha,      // texto puro
                        ':sv' => $senha,
                        ':n'  => $nome,
                        ':e'  => $email !== '' ? $email : null,
                        ':p'  => $perfil,
                        ':a'  => $ativo,
                    ]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO usuarios
                            (usuario, senha_hash, nome, email, perfil, ativo)
                         VALUES (:u, :h, :n, :e, :p, :a)"
                    )->execute([
                        ':u' => $usuario,
                        ':h' => $senha,       // texto puro
                        ':n' => $nome,
                        ':e' => $email !== '' ? $email : null,
                        ':p' => $perfil,
                        ':a' => $ativo,
                    ]);
                }

                $id = (int)$pdo->lastInsertId();
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'id' => $id, 'msg' => 'Usuário salvo com sucesso.']);
            exit;
        }

        // ====================================================
        // TOGGLE ativo/inativo
        // ====================================================
        if ($acao === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido.');
            if ($id === (int)$__u['id']) {
                throw new Exception('Você não pode ativar/desativar sua própria conta.');
            }

            $st = $pdo->prepare("SELECT perfil, ativo FROM usuarios WHERE id = :id");
            $st->execute([':id' => $id]);
            $u = $st->fetch();
            if (!$u) throw new Exception('Usuário não encontrado.');

            $novoStatus = (int)$u['ativo'] === 1 ? 0 : 1;

            if ((int)$u['ativo'] === 1 && in_array($u['perfil'], ['admin','superadmin'], true)) {
                $qtd = (int)$pdo->query(
                    "SELECT COUNT(*) FROM usuarios
                      WHERE perfil IN ('admin','superadmin') AND ativo = 1"
                )->fetchColumn();
                if ($qtd <= 1) {
                    throw new Exception(
                        'Este é o único administrador ativo. ' .
                        'Cadastre outro antes de desativá-lo.'
                    );
                }
            }

            $pdo->prepare("UPDATE usuarios SET ativo = :a WHERE id = :id")
                ->execute([':a' => $novoStatus, ':id' => $id]);

            echo json_encode([
                'ok'  => true,
                'msg' => $novoStatus === 1 ? 'Usuário ativado.' : 'Usuário desativado.',
            ]);
            exit;
        }

        // ====================================================
        // RESET de senha
        // ====================================================
        if ($acao === 'reset_senha') {
            $id    = (int)($_POST['id'] ?? 0);
            $senha = (string)($_POST['senha'] ?? '');
            $conf  = (string)($_POST['senha_confirma'] ?? '');

            if ($id <= 0) throw new Exception('ID inválido.');
            if (strlen($senha) < 6) throw new Exception('A senha deve ter no mínimo 6 caracteres.');
            if ($senha !== $conf)   throw new Exception('As senhas não coincidem.');

            $st = $pdo->prepare("SELECT id FROM usuarios WHERE id = :id");
            $st->execute([':id' => $id]);
            if (!$st->fetchColumn()) throw new Exception('Usuário não encontrado.');

            // Senha em TEXTO PURO
            if ($TEM_SENHA_VISIVEL) {
                $pdo->prepare(
                    "UPDATE usuarios
                        SET senha_hash = :h, senha_visivel = :sv
                      WHERE id = :id"
                )->execute([':h' => $senha, ':sv' => $senha, ':id' => $id]);
            } else {
                $pdo->prepare("UPDATE usuarios SET senha_hash = :h WHERE id = :id")
                    ->execute([':h' => $senha, ':id' => $id]);
            }

            echo json_encode(['ok' => true, 'msg' => 'Senha redefinida.']);
            exit;
        }

        // ====================================================
        // EXCLUIR
        // ====================================================
        if ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido.');
            if ($id === (int)$__u['id']) {
                throw new Exception('Você não pode excluir sua própria conta.');
            }

            $st = $pdo->prepare("SELECT perfil, ativo FROM usuarios WHERE id = :id");
            $st->execute([':id' => $id]);
            $u = $st->fetch();
            if (!$u) throw new Exception('Usuário não encontrado.');

            if (in_array($u['perfil'], ['admin','superadmin'], true) && (int)$u['ativo'] === 1) {
                $qtd = (int)$pdo->query(
                    "SELECT COUNT(*) FROM usuarios
                      WHERE perfil IN ('admin','superadmin') AND ativo = 1"
                )->fetchColumn();
                if ($qtd <= 1) {
                    throw new Exception(
                        'Este é o único administrador ativo. ' .
                        'Cadastre outro antes de excluí-lo.'
                    );
                }
            }

            $pdo->prepare("DELETE FROM usuarios WHERE id = :id")
                ->execute([':id' => $id]);

            echo json_encode(['ok' => true, 'msg' => 'Usuário excluído.']);
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

// ------------------------------------------------------------
// Dados para a página
// ------------------------------------------------------------
$colSenhaVis = $TEM_SENHA_VISIVEL ? 'senha_visivel,' : '';

$usuarios = $pdo->query(
    "SELECT id, usuario, senha_hash, {$colSenhaVis} nome, email, perfil, ativo, criado_em, atualizado_em
       FROM usuarios
      ORDER BY ativo DESC, perfil ASC, nome"
)->fetchAll();

// Garante que a chave sempre exista (evita warning no PHP)
foreach ($usuarios as &$uu) {
    if (!array_key_exists('senha_visivel', $uu)) {
        $uu['senha_visivel'] = null;
    }
}
unset($uu);

$totalUsuarios = count($usuarios);
$totalAtivos   = 0;
$totalAdmins   = 0;
foreach ($usuarios as $u) {
    if ((int)$u['ativo'] === 1) $totalAtivos++;
    if (in_array($u['perfil'], ['admin','superadmin'], true) && (int)$u['ativo'] === 1) {
        $totalAdmins++;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Usuários — Eleições PE</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#0069d9; margin: 0 0 4px; }
    .subtitulo { color:#888; font-size:13px; margin-bottom:20px; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:180px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#0069d9; margin-top:4px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.amarelo { border-left-color:#ffc107; } .kpi.amarelo .valor { color:#b8860b; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }

    .btn { display:inline-block; padding:8px 14px; border-radius:4px; border:none;
           cursor:pointer; text-decoration:none; font-size:14px; font-family:inherit; }
    .btn-novo { background:#28a745; color:#fff; }
    .btn-novo:hover { background:#1e7e34; }
    .btn-editar{ background:#0069d9; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-editar:hover { background:#0053b3; }
    .btn-excluir{ background:#dc3545; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-excluir:hover { background:#b02a37; }
    .btn-senha  { background:#fd7e14; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-senha:hover { background:#e8660f; }
    .btn-toggle { background:#6c757d; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-toggle:hover { background:#5a6268; }
    .btn-toggle.ativar { background:#28a745; }
    .btn-toggle.ativar:hover { background:#1e7e34; }
    .btn-salvar { background:#28a745; color:#fff; padding:10px 18px; font-size:14px; }
    .btn-salvar:hover { background:#1e7e34; }
    .btn-cancelar { background:#6c757d; color:#fff; padding:10px 18px; font-size:14px; }
    .btn-cancelar:hover { background:#5a6268; }

    .btn-ver-senha {
        background:transparent; border:1px solid #ccc; padding:2px 8px;
        border-radius:4px; cursor:pointer; font-size:14px;
        line-height:1; color:#666;
    }
    .btn-ver-senha:hover { background:#f0f0f0; color:#0069d9; border-color:#0069d9; }
    .btn-copiar {
        background:transparent; border:1px solid #ccc; padding:2px 8px;
        border-radius:4px; cursor:pointer; font-size:14px;
        line-height:1; color:#666; margin-left:4px;
    }
    .btn-copiar:hover { background:#f0f0f0; color:#28a745; border-color:#28a745; }

    table { border-collapse:collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; font-size:13px; }
    th { background:#0069d9; color:#fff; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#eef6ff; }
    tr.eu { background:#fffbe6 !important; }
    tr.eu:hover { background:#fff8d6 !important; }
    .num { text-align:right; }
    .centro { text-align:center; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-super { background:#dc3545; color:#fff; }
    .badge-admin { background:#fff3cd; color:#856404; }
    .badge-cons  { background:#e6f0fa; color:#0069d9; }
    .badge-ativo { background:#d4f5dd; color:#1c7a34; }
    .badge-inativo{ background:#f0f0f0; color:#888; }
    .badge-voce  { background:#6f42c1; color:#fff; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    /* Célula da senha */
    .senha-cell { display:flex; align-items:center; gap:4px; white-space:nowrap; }
    .senha-valor {
        font-family: 'Courier New', monospace;
        font-size:12px;
        background:#f4f4f4;
        padding:2px 6px;
        border-radius:3px;
        color:#333;
        min-width:80px;
        display:inline-block;
        text-align:left;
    }
    .senha-valor.oculta {
        letter-spacing:2px;
        color:#999;
        user-select:none;
    }

    .modal-overlay {
        display:none; position:fixed; inset:0;
        background: rgba(0,0,0,.5); z-index:1000;
        align-items:center; justify-content:center; padding:20px;
    }
    .modal-overlay.aberto { display:flex; }
    .modal { background:#fff; border-radius:10px; max-width: 620px;
             width:100%; max-height: 94vh; overflow-y:auto;
             box-shadow: 0 10px 30px rgba(0,0,0,.3); }
    .modal-header { padding:16px 22px; border-bottom:1px solid #eee;
                    display:flex; justify-content:space-between; align-items:center; }
    .modal-header h2 { margin:0; font-size:18px; color:#0069d9; }
    .modal-header .fechar { background:none; border:none; font-size:22px;
                            cursor:pointer; color:#888; }
    .modal-body { padding:20px 22px; }
    .modal-footer { padding:14px 22px; border-top:1px solid #eee;
                    text-align:right; background:#fafafa; }
    .campo { margin-bottom:14px; }
    .campo label { display:block; font-weight:bold; margin-bottom:4px; font-size:13px; }
    .campo input[type=text],
    .campo input[type=email],
    .campo input[type=password],
    .campo select {
        width:100%; padding:8px 10px; border:1px solid #ccc;
        border-radius:4px; font-size:14px; font-family:inherit;
        box-sizing:border-box;
    }
    .campo input:focus, .campo select:focus {
        outline:none; border-color:#0069d9;
        box-shadow:0 0 0 3px rgba(0,105,217,.15);
    }
    .campo .hint { font-size:11px; color:#888; margin-top:4px; }
    .linha-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .toggle { display:flex; align-items:center; gap:8px; }
    .toggle input { width:auto; }

    /* Campo de senha com botão olho */
    .campo-senha { position: relative; }
    .campo-senha input { padding-right: 40px !important; }
    .campo-senha .btn-olho {
        position: absolute;
        right: 8px;
        top: 28px;
        background: transparent;
        border: none;
        cursor: pointer;
        font-size: 16px;
        line-height: 1;
        padding: 4px 6px;
        color: #666;
        border-radius: 4px;
    }
    .campo-senha .btn-olho:hover {
        background: #f0f0f0;
        color: #0069d9;
    }

    .aviso-edit {
        background:#fff3cd; border-left:3px solid #ffc107;
        padding:8px 12px; border-radius:4px; font-size:12px;
        color:#856404; margin-bottom:12px;
    }
    .aviso-seg {
        background:#fdecea; border-left:3px solid #dc3545;
        padding:8px 12px; border-radius:4px; font-size:12px;
        color:#a71d2a; margin-bottom:16px;
    }
    .aviso-info {
        background:#e6f0fa; border-left:3px solid #0069d9;
        padding:8px 12px; border-radius:4px; font-size:12px;
        color:#0053b3; margin-bottom:16px;
    }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    .msg-box {
        background:#e6f0fa; border-left:3px solid #0069d9;
        padding:8px 12px; border-radius:4px; font-size:12px;
        color:#0053b3; margin-bottom:12px;
    }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Gerenciar Usuários</h1>
<p class="subtitulo">
    Cadastro, edição, exclusão e controle de acesso.
    Somente administradores têm acesso a esta página.
</p>

<div class="aviso-seg">
    🔓 <strong>Atenção:</strong> as senhas são armazenadas em <strong>texto puro</strong>
    (sem criptografia). Qualquer pessoa com acesso ao banco de dados consegue lê-las.
    Use apenas em ambiente controlado.
</div>

<?php if (!$TEM_SENHA_VISIVEL): ?>
    <div class="aviso-info">
        ℹ A coluna <code>senha_visivel</code> ainda não existe. As senhas estão sendo
        gravadas em <code>senha_hash</code> em texto puro. Se quiser uma coluna espelho
        dedicada, rode:
        <br><br>
        <code style="display:block;background:#fff;padding:8px;border-radius:4px;font-size:12px;">
            ALTER TABLE usuarios ADD COLUMN senha_visivel VARCHAR(100) NULL AFTER senha_hash;
        </code>
    </div>
<?php endif; ?>

<div class="kpis">
    <div class="kpi">
        <div class="rotulo">Usuários</div>
        <div class="valor"><?= number_format($totalUsuarios, 0, ',', '.') ?></div>
    </div>
    <div class="kpi verde">
        <div class="rotulo">Ativos</div>
        <div class="valor"><?= number_format($totalAtivos, 0, ',', '.') ?></div>
    </div>
    <div class="kpi amarelo">
        <div class="rotulo">Administradores ativos</div>
        <div class="valor"><?= number_format($totalAdmins, 0, ',', '.') ?></div>
    </div>
</div>

<div style="margin-bottom:15px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <button type="button" class="btn btn-novo" id="btn-novo">+ Novo Usuário</button>

    <button type="button" class="btn btn-toggle" id="btn-toggle-todas"
            style="background:#6f42c1;">
        👁 Mostrar todas as senhas
    </button>
</div>

<table>
    <thead>
        <tr>
            <th style="width:50px;">#</th>
            <th style="width:130px;">Usuário</th>
            <th>Nome</th>
            <th>E-mail</th>
            <th style="width:200px;">Senha</th>
            <th style="width:110px;">Perfil</th>
            <th style="width:90px;">Status</th>
            <th style="width:280px;">Ações</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$usuarios): ?>
            <tr>
                <td colspan="8" style="text-align:center;color:#999;padding:20px;">
                    Nenhum usuário cadastrado ainda.
                </td>
            </tr>
        <?php else: ?>
            <?php $i = 1; foreach ($usuarios as $u): ?>
                <?php
                $souEu = ((int)$u['id'] === (int)$__u['id']);

                // Senha "mostrável": se senha_visivel existir, usa ela;
                // senão, usa a própria senha_hash (agora em texto puro)
                if ($TEM_SENHA_VISIVEL && !empty($u['senha_visivel'])) {
                    $senhaMostrada = $u['senha_visivel'];
                } elseif (!empty($u['senha_hash'])) {
                    $senhaMostrada = $u['senha_hash'];
                } else {
                    $senhaMostrada = '';
                }
                $temSenhaVisivel = ($senhaMostrada !== '');
                ?>
                <tr class="<?= $souEu ? 'eu' : '' ?>" data-id="<?= (int)$u['id'] ?>">
                    <td><?= $i++ ?></td>
                    <td>
                        <strong><?= htmlspecialchars($u['usuario']) ?></strong>
                        <?php if ($souEu): ?>
                            <span class="badge badge-voce">você</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($u['nome']) ?></td>
                    <td>
                        <?= $u['email']
                            ? htmlspecialchars($u['email'])
                            : '<span class="vazio">—</span>' ?>
                    </td>
                    <td>
                        <?php if (!$temSenhaVisivel): ?>
                            <span class="vazio">não registrada</span>
                        <?php else: ?>
                            <div class="senha-cell">
                                <span class="senha-valor oculta"
                                      data-senha="<?= htmlspecialchars($senhaMostrada, ENT_QUOTES) ?>">
                                    ●●●●●●●●
                                </span>
                                <button type="button"
                                        class="btn-ver-senha"
                                        title="Mostrar senha">👁</button>
                                <button type="button"
                                        class="btn-copiar"
                                        data-senha="<?= htmlspecialchars($senhaMostrada, ENT_QUOTES) ?>"
                                        title="Copiar senha">📋</button>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['perfil'] === 'superadmin'): ?>
                            <span class="badge badge-super">SUPER</span>
                        <?php elseif ($u['perfil'] === 'admin'): ?>
                            <span class="badge badge-admin">ADMIN</span>
                        <?php else: ?>
                            <span class="badge badge-cons">CONSULTA</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$u['ativo'] === 1): ?>
                            <span class="badge badge-ativo">Ativo</span>
                        <?php else: ?>
                            <span class="badge badge-inativo">Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn-editar"
                                data-id="<?= (int)$u['id'] ?>">Editar</button>

                        <button type="button" class="btn btn-senha"
                                data-id="<?= (int)$u['id'] ?>"
                                data-usuario="<?= htmlspecialchars($u['usuario']) ?>">
                            🔑 Senha
                        </button>

                        <?php if (!$souEu): ?>
                            <button type="button"
                                    class="btn btn-toggle <?= (int)$u['ativo'] === 1 ? '' : 'ativar' ?>"
                                    data-id="<?= (int)$u['id'] ?>"
                                    data-ativo="<?= (int)$u['ativo'] ?>"
                                    data-usuario="<?= htmlspecialchars($u['usuario']) ?>">
                                <?= (int)$u['ativo'] === 1 ? 'Desativar' : 'Ativar' ?>
                            </button>

                            <button type="button" class="btn btn-excluir"
                                    data-id="<?= (int)$u['id'] ?>"
                                    data-usuario="<?= htmlspecialchars($u['usuario']) ?>"
                                    data-nome="<?= htmlspecialchars($u['nome']) ?>">
                                Excluir
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-toggle"
                                    disabled title="Você não pode alterar sua própria conta"
                                    style="opacity:.4;cursor:not-allowed;">
                                Desativar
                            </button>
                            <button type="button" class="btn btn-excluir"
                                    disabled title="Você não pode excluir sua própria conta"
                                    style="opacity:.4;cursor:not-allowed;">
                                Excluir
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- ============================================================
     MODAL: CADASTRO / EDIÇÃO
     ============================================================ -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modal-titulo">Novo Usuário</h2>
            <button type="button" class="fechar" id="btn-fechar">&times;</button>
        </div>
        <form id="form-user" autocomplete="off">
            <input type="hidden" name="id" id="f-id" value="0">

            <div class="modal-body">
                <div id="aviso-edit" class="aviso-edit" style="display:none;">
                    Deixe os campos de senha em branco para <strong>manter a senha atual</strong>.
                </div>

                <div class="linha-2">
                    <div class="campo">
                        <label for="f-usuario">Usuário (login) *</label>
                        <input type="text" name="usuario" id="f-usuario"
                               required maxlength="60"
                               pattern="[a-zA-Z0-9._\-]{3,60}"
                               placeholder="ex.: joao.silva">
                        <div class="hint">
                            Letras, números, ponto, hífen ou underline (3 a 60 caracteres).
                        </div>
                    </div>

                    <div class="campo">
                        <label for="f-perfil">Perfil *</label>
                        <select name="perfil" id="f-perfil" required>
                            <option value="consulta">Consulta</option>
                            <option value="admin">Administrador</option>
                            <option value="superadmin">Super Administrador</option>
                        </select>
                    </div>
                </div>

                <div class="campo">
                    <label for="f-nome">Nome completo *</label>
                    <input type="text" name="nome" id="f-nome"
                           required maxlength="120">
                </div>

                <div class="campo">
                    <label for="f-email">E-mail</label>
                    <input type="email" name="email" id="f-email"
                           maxlength="120" placeholder="opcional">
                </div>

                <div class="linha-2">
                    <div class="campo campo-senha">
                        <label for="f-senha">Senha <span id="lbl-senha-obrig">*</span></label>
                        <input type="text" name="senha" id="f-senha"
                               minlength="6" maxlength="100"
                               autocomplete="off"
                               placeholder="mínimo 6 caracteres">
                        <button type="button" class="btn-olho" data-alvo="f-senha"
                                title="Mostrar/ocultar">👁</button>
                    </div>

                    <div class="campo campo-senha">
                        <label for="f-senha-confirma">Confirmar senha</label>
                        <input type="text" name="senha_confirma" id="f-senha-confirma"
                               minlength="6" maxlength="100"
                               autocomplete="off">
                        <button type="button" class="btn-olho" data-alvo="f-senha-confirma"
                                title="Mostrar/ocultar">👁</button>
                    </div>
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

<!-- ============================================================
     MODAL: RESET DE SENHA
     ============================================================ -->
<div class="modal-overlay" id="modal-senha">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h2>Redefinir senha</h2>
            <button type="button" class="fechar" id="btn-fechar-senha">&times;</button>
        </div>
        <form id="form-senha" autocomplete="off">
            <input type="hidden" name="id" id="s-id" value="0">

            <div class="modal-body">
                <div class="msg-box">
                    Você está redefinindo a senha do usuário
                    <strong id="s-usuario-label">—</strong>.
                    Esta ação não pode ser desfeita.
                </div>

                <div class="campo campo-senha">
                    <label for="s-senha">Nova senha *</label>
                    <input type="text" name="senha" id="s-senha"
                           required minlength="6" maxlength="100"
                           autocomplete="off"
                           placeholder="mínimo 6 caracteres">
                    <button type="button" class="btn-olho" data-alvo="s-senha"
                            title="Mostrar/ocultar">👁</button>
                </div>

                <div class="campo campo-senha">
                    <label for="s-senha-confirma">Confirmar nova senha *</label>
                    <input type="text" name="senha_confirma" id="s-senha-confirma"
                           required minlength="6" maxlength="100"
                           autocomplete="off">
                    <button type="button" class="btn-olho" data-alvo="s-senha-confirma"
                            title="Mostrar/ocultar">👁</button>
                </div>
            </div>

            <div class="modal-footer">
                <span id="senha-msg" style="float:left;font-size:13px;"></span>
                <button type="button" class="btn btn-cancelar" id="btn-cancelar-senha">Cancelar</button>
                <button type="submit" class="btn btn-salvar">Redefinir</button>
            </div>
        </form>
    </div>
</div>

<script>
    const USUARIOS            = <?= json_encode($usuarios, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const EU_ID               = <?= (int)$__u['id'] ?>;
    const TEM_SENHA_VISIVEL   = <?= $TEM_SENHA_VISIVEL ? 'true' : 'false' ?>;

    (function () {
        // ============ Modal principal ============
        const $modal    = document.getElementById('modal');
        const $form     = document.getElementById('form-user');
        const $titulo   = document.getElementById('modal-titulo');
        const $msg      = document.getElementById('form-msg');
        const $aviso    = document.getElementById('aviso-edit');
        const $lblSenha = document.getElementById('lbl-senha-obrig');
        const $inpSenha = document.getElementById('f-senha');
        const $inpConf  = document.getElementById('f-senha-confirma');

        function abrirModal(id) {
            $form.reset();
            document.getElementById('f-id').value = '0';
            $msg.textContent = '';
            $aviso.style.display = 'none';
            $lblSenha.textContent = '*';

            if (id > 0) {
                const u = USUARIOS.find(x => parseInt(x.id) === id);
                if (!u) return;

                $titulo.textContent = 'Editar Usuário';
                document.getElementById('f-id').value       = u.id;
                document.getElementById('f-usuario').value  = u.usuario;
                document.getElementById('f-nome').value     = u.nome;
                document.getElementById('f-email').value    = u.email || '';
                document.getElementById('f-perfil').value   = u.perfil;
                document.getElementById('f-ativo').checked  = parseInt(u.ativo) === 1;

                $aviso.style.display = 'block';
                $lblSenha.textContent = '';
                $inpSenha.required = false;
                $inpConf.required  = false;
            } else {
                $titulo.textContent = 'Novo Usuário';
                document.getElementById('f-perfil').value = 'consulta';
                document.getElementById('f-ativo').checked = true;
                $inpSenha.required = true;
                $inpConf.required  = true;
            }

            $modal.classList.add('aberto');
            setTimeout(() => document.getElementById('f-usuario').focus(), 100);
        }

        function fecharModal() { $modal.classList.remove('aberto'); }

        // ============ Modal de reset de senha ============
        const $modalSenha = document.getElementById('modal-senha');
        const $formSenha  = document.getElementById('form-senha');
        const $senhaMsg   = document.getElementById('senha-msg');

        function abrirModalSenha(id, usuario) {
            $formSenha.reset();
            document.getElementById('s-id').value = id;
            document.getElementById('s-usuario-label').textContent = usuario;
            $senhaMsg.textContent = '';
            $modalSenha.classList.add('aberto');
            setTimeout(() => document.getElementById('s-senha').focus(), 100);
        }

        function fecharModalSenha() { $modalSenha.classList.remove('aberto'); }

        // ============ Olhos (mostrar/ocultar) ============
        // Como os inputs são type="text", o olho só alterna o valor do botão
        document.addEventListener('click', (e) => {
            if (!e.target.matches('.btn-olho')) return;
            const alvo = document.getElementById(e.target.dataset.alvo);
            if (!alvo) return;
            // Como já é texto, o olho apenas serve como feedback visual
            // Se você preferir esconder, descomente abaixo
            // alvo.type = alvo.type === 'text' ? 'password' : 'text';
            alvo.focus();
        });

        // ============ Olhos (senha da tabela) ============
        document.addEventListener('click', (e) => {
            if (!e.target.matches('.btn-ver-senha')) return;
            const celula = e.target.closest('.senha-cell');
            if (!celula) return;
            const span  = celula.querySelector('.senha-valor');
            const senha = span.dataset.senha || '';

            if (span.classList.contains('oculta')) {
                span.textContent = senha;
                span.classList.remove('oculta');
                e.target.textContent = '🙈';
            } else {
                span.textContent = '●●●●●●●●';
                span.classList.add('oculta');
                e.target.textContent = '👁';
            }
        });

        // ============ Copiar senha ============
        document.addEventListener('click', async (e) => {
            if (!e.target.matches('.btn-copiar')) return;
            const senha = e.target.dataset.senha || '';
            try {
                await navigator.clipboard.writeText(senha);
                const original = e.target.textContent;
                e.target.textContent = '✔';
                e.target.style.color = '#28a745';
                setTimeout(() => {
                    e.target.textContent = original;
                    e.target.style.color = '';
                }, 1200);
            } catch (err) {
                alert('Não foi possível copiar. Senha: ' + senha);
            }
        });

        // ============ Botão "Mostrar todas" ============
        const $btnTodas = document.getElementById('btn-toggle-todas');
        if ($btnTodas) {
            let todasVisiveis = false;
            $btnTodas.addEventListener('click', function () {
                todasVisiveis = !todasVisiveis;
                document.querySelectorAll('.senha-cell').forEach(celula => {
                    const span  = celula.querySelector('.senha-valor');
                    const botao = celula.querySelector('.btn-ver-senha');
                    const senha = span.dataset.senha || '';

                    if (todasVisiveis) {
                        span.textContent = senha;
                        span.classList.remove('oculta');
                        if (botao) botao.textContent = '🙈';
                    } else {
                        span.textContent = '●●●●●●●●';
                        span.classList.add('oculta');
                        if (botao) botao.textContent = '👁';
                    }
                });
                this.textContent = todasVisiveis
                    ? '🙈 Ocultar todas as senhas'
                    : '👁 Mostrar todas as senhas';
            });
        }

        // ============ Eventos gerais ============
        document.getElementById('btn-novo').addEventListener('click', () => abrirModal(0));

        document.addEventListener('click', (e) => {
            if (e.target.matches('.btn-editar')) {
                abrirModal(parseInt(e.target.dataset.id));
            }
        });

        document.getElementById('btn-fechar').addEventListener('click', fecharModal);
        document.getElementById('btn-cancelar').addEventListener('click', fecharModal);
        $modal.addEventListener('click', (e) => { if (e.target === $modal) fecharModal(); });

        document.getElementById('btn-fechar-senha').addEventListener('click', fecharModalSenha);
        document.getElementById('btn-cancelar-senha').addEventListener('click', fecharModalSenha);
        $modalSenha.addEventListener('click', (e) => { if (e.target === $modalSenha) fecharModalSenha(); });

        // ---- Botão Senha ----
        document.addEventListener('click', (e) => {
            if (e.target.matches('.btn-senha')) {
                abrirModalSenha(parseInt(e.target.dataset.id), e.target.dataset.usuario);
            }
        });

        // ---- Botão Toggle ativo/inativo ----
        document.addEventListener('click', async (e) => {
            if (!e.target.matches('.btn-toggle')) return;
            if (e.target.id === 'btn-toggle-todas') return;
            const btn   = e.target;
            const id    = parseInt(btn.dataset.id);
            const usr   = btn.dataset.usuario;
            const ativo = parseInt(btn.dataset.ativo);
            const acao  = ativo === 1 ? 'desativar' : 'ativar';

            if (!confirm(`Deseja ${acao} o usuário "${usr}"?`)) return;

            const fd = new FormData();
            fd.append('id', id);
            try {
                const r = await fetch('usuarios.php?ajax=toggle', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.ok) location.reload();
                else alert('Erro: ' + j.msg);
            } catch (err) { alert('Erro de rede.'); }
        });

        // ---- Botão Excluir ----
        document.addEventListener('click', async (e) => {
            if (!e.target.matches('.btn-excluir')) return;
            if (e.target.disabled) return;

            const id   = parseInt(e.target.dataset.id);
            const usr  = e.target.dataset.usuario;
            const nome = e.target.dataset.nome;

            if (!confirm(`Excluir o usuário "${usr}" (${nome})?\n\nEsta ação não pode ser desfeita.`)) return;

            const fd = new FormData();
            fd.append('id', id);
            try {
                const r = await fetch('usuarios.php?ajax=excluir', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.ok) location.reload();
                else alert('Erro: ' + j.msg);
            } catch (err) { alert('Erro de rede.'); }
        });

        // ---- Submit cadastro/edição ----
        $form.addEventListener('submit', async (e) => {
            e.preventDefault();
            $msg.textContent = 'Salvando...';
            $msg.style.color = '#6c757d';
            const fd = new FormData($form);
            try {
                const r = await fetch('usuarios.php?ajax=salvar', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.ok) {
                    $msg.textContent = '✔ ' + j.msg;
                    $msg.style.color = '#28a745';
                    setTimeout(() => location.reload(), 500);
                } else {
                    $msg.textContent = '✘ ' + j.msg;
                    $msg.style.color = '#dc3545';
                }
            } catch (err) {
                $msg.textContent = '✘ Erro de rede.';
                $msg.style.color = '#dc3545';
            }
        });

        // ---- Submit reset de senha ----
        $formSenha.addEventListener('submit', async (e) => {
            e.preventDefault();
            $senhaMsg.textContent = 'Redefinindo...';
            $senhaMsg.style.color = '#6c757d';
            const fd = new FormData($formSenha);
            try {
                const r = await fetch('usuarios.php?ajax=reset_senha', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.ok) {
                    $senhaMsg.textContent = '✔ ' + j.msg;
                    $senhaMsg.style.color = '#28a745';
                    setTimeout(() => location.reload(), 700);
                } else {
                    $senhaMsg.textContent = '✘ ' + j.msg;
                    $senhaMsg.style.color = '#dc3545';
                }
            } catch (err) {
                $senhaMsg.textContent = '✘ Erro de rede.';
                $senhaMsg.style.color = '#dc3545';
            }
        });
    })();
</script>

</body>
</html>