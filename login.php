<?php
// login.php — Tela de login. Senhas em TEXTO PURO (sem hash).
session_start();

// Se já está logado, vai pro index
if (!empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// conexão com o banco de dados
require_once __DIR__ . '/conn_eleicoes.php';


$erro = '';
$motivo = $_GET['motivo'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha   = $_POST['senha'] ?? '';

    if ($usuario === '' || $senha === '') {
        $erro = 'Informe usuário e senha.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
                $DB_USER, $DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            $stmt = $pdo->prepare(
                "SELECT id, usuario, senha_hash, nome, perfil, ativo
                 FROM usuarios WHERE usuario = :u"
            );
            $stmt->execute([':u' => $usuario]);
            $u = $stmt->fetch();

            // ---------- Comparação DIRETA (texto puro) ----------
            if (!$u || $senha !== $u['senha_hash']) {
                $erro = 'Usuário ou senha incorretos.';
            } elseif ((int)$u['ativo'] !== 1) {
                $erro = 'Usuário desativado. Contate o administrador.';
            } else {
                session_regenerate_id(true);
                $_SESSION['usuario_id']     = (int)$u['id'];
                $_SESSION['usuario_nome']   = $u['nome'];
                $_SESSION['usuario_login']  = $u['usuario'];
                $_SESSION['usuario_perfil'] = $u['perfil'];

                // Redireciona
                $redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
                if ($redirect !== '' && strpos($redirect, '://') === false) {
                    header("Location: $redirect");
                } else {
                    header('Location: index.php');
                }
                exit;
            }
        } catch (Exception $e) {
            $erro = 'Erro no servidor: ' . $e->getMessage();
        }
    }
}

$redirectAtual = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar — Eleições PE</title>
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh;
        font-family: Arial, Helvetica, sans-serif;
        background: linear-gradient(135deg, #eef4ff 0%, #f7f7f7 60%, #eef4ff 100%);
        display: flex; align-items: center; justify-content: center;
        padding: 20px;
    }
    .cartao {
        background: #fff; border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0,0,0,.15);
        max-width: 400px; width: 100%;
        padding: 36px 32px;
    }
    .cartao .icone { font-size: 42px; text-align: center; }
    .cartao h1 {
        margin: 8px 0 4px; font-size: 22px;
        color: #0069d9; text-align: center;
    }
    .cartao .sub {
        text-align: center; color: #888; font-size: 13px;
        margin-bottom: 26px;
    }
    .campo { margin-bottom: 16px; }
    .campo label {
        display: block; font-weight: bold;
        margin-bottom: 6px; font-size: 13px;
    }
    .campo input {
        width: 100%; padding: 11px 12px;
        border: 1px solid #ccc; border-radius: 6px;
        font-size: 15px; font-family: inherit;
    }
    .campo input:focus {
        border-color: #0069d9; outline: none;
        box-shadow: 0 0 0 3px rgba(0,105,217,.15);
    }
    button {
        width: 100%; padding: 12px;
        background: #0069d9; color: #fff;
        border: none; border-radius: 6px;
        font-size: 15px; font-weight: bold;
        cursor: pointer; margin-top: 8px;
        transition: background .15s;
    }
    button:hover { background: #0053b3; }
    .erro {
        background: #fdecea; border-left: 4px solid #dc3545;
        color: #721c24; padding: 10px 14px;
        border-radius: 4px; font-size: 13px;
        margin-bottom: 18px;
    }
    .aviso-inativo {
        background: #fff3cd; border-left: 4px solid #ffc107;
        color: #856404; padding: 10px 14px;
        border-radius: 4px; font-size: 13px;
        margin-bottom: 18px;
    }
    .rodape {
        text-align: center; font-size: 11px;
        color: #aaa; margin-top: 22px;
    }
</style>
</head>
<body>

<div class="cartao">
    <div class="icone">🗳</div>
    <h1>Eleições PE</h1>
    <div class="sub">Entre para acessar o sistema</div>

    <?php if ($motivo === 'inativo'): ?>
        <div class="aviso-inativo">
            Sua sessão foi encerrada porque o usuário foi desativado.
        </div>
    <?php endif; ?>

    <?php if ($erro !== ''): ?>
        <div class="erro"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php<?= $redirectAtual !== '' ? '?redirect=' . urlencode($redirectAtual) : '' ?>">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectAtual) ?>">

        <div class="campo">
            <label for="usuario">Usuário</label>
            <input type="text" name="usuario" id="usuario" autofocus
                   value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>"
                   autocomplete="username">
        </div>

        <div class="campo">
            <label for="senha">Senha</label>
            <input type="password" name="senha" id="senha"
                   autocomplete="current-password">
        </div>

        <button type="submit">Entrar</button>
    </form>

    <div class="rodape">
        Valter Barreto · 2026 · Todos os direitos reservados.
    </div>
</div>

</body>
</html>