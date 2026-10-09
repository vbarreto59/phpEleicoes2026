<?php
// auth.php — incluir no topo (primeira linha) de qualquer página protegida.
// Uso:  require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ------------------------------------------------------------
// Configuração do banco (se a página ainda não criou o $pdo)
// ------------------------------------------------------------
if (!isset($pdo)) {
    $DB_HOST = 'localhost';
    $DB_NAME = 'eleicoes';
    $DB_USER = 'root';
    $DB_PASS = 'caio';

    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

// ------------------------------------------------------------
// Não logado → manda pro login
// ------------------------------------------------------------
if (empty($_SESSION['usuario_id'])) {
    $destino = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header("Location: login.php?redirect={$destino}");
    exit;
}

// ------------------------------------------------------------
// Usuário logado, mas recarrega do banco para checar se ainda está ativo
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT id, usuario, nome, perfil, ativo
     FROM usuarios
     WHERE id = :id"
);
$stmt->execute([':id' => $_SESSION['usuario_id']]);
$__u = $stmt->fetch();

if (!$__u || (int)$__u['ativo'] !== 1) {
    // Usuário foi desativado ou excluído
    session_unset();
    session_destroy();
    header('Location: login.php?motivo=inativo');
    exit;
}

// Atualiza dados na sessão (caso admin tenha mudado nome/perfil)
$_SESSION['usuario_nome']   = $__u['nome'];
$_SESSION['usuario_login']  = $__u['usuario'];
$_SESSION['usuario_perfil'] = $__u['perfil'];

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function usuarioLogado(): array {
    return [
        'id'     => $_SESSION['usuario_id']     ?? null,
        'nome'   => $_SESSION['usuario_nome']   ?? '',
        'login'  => $_SESSION['usuario_login']  ?? '',
        'perfil' => $_SESSION['usuario_perfil'] ?? '',
    ];
}

function ehAdmin(): bool {
    return (($_SESSION['usuario_perfil'] ?? '') === 'admin');
}

function exigirAdmin(): void {
    if (!ehAdmin()) {
        http_response_code(403);
        echo "<h1 style='font-family:Arial;color:#b94a48;'>Acesso negado</h1>";
        echo "<p style='font-family:Arial;'>Esta página é restrita ao administrador.</p>";
        echo "<p><a href='index.php' style='font-family:Arial;'>Voltar</a></p>";
        exit;
    }
}