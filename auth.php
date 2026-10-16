<?php
// ============================================================
// auth.php — proteção de páginas + dados do usuário logado
// Inclua SEMPRE na PRIMEIRA linha de qualquer página protegida:
//     require_once __DIR__ . '/auth.php';
// ============================================================
date_default_timezone_set('America/Sao_Paulo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ------------------------------------------------------------
// Conexão com o banco (se ainda não existir $pdo)
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
// Descobre o caminho base para o login.php
// Funciona tanto na raiz quanto em subpastas
// ------------------------------------------------------------
$__loginPath = '/phpEleicoes2026/login.php';

// ------------------------------------------------------------
// 1. Não tem sessão → manda pro login
// ------------------------------------------------------------
$__logado = !empty($_SESSION['usuario_id']);

if (!$__logado) {
    $destino = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header("Location: {$__loginPath}?redirect={$destino}");
    exit;
}

// ------------------------------------------------------------
// 2. Recarrega do banco e confirma que ainda está ativo
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare(
        "SELECT id, usuario, nome, perfil, ativo
           FROM usuarios
          WHERE id = :id
          LIMIT 1"
    );
    $stmt->execute([':id' => (int)$_SESSION['usuario_id']]);
    $__uDb = $stmt->fetch();
} catch (Exception $e) {
    // Erro no banco: derruba sessão por segurança
    session_unset();
    session_destroy();
    header("Location: {$__loginPath}?motivo=erro");
    exit;
}

if (!$__uDb || (int)$__uDb['ativo'] !== 1) {
    session_unset();
    session_destroy();
    header("Location: {$__loginPath}?motivo=inativo");
    exit;
}

// Renova a sessão com dados atualizados
$_SESSION['usuario_nome']   = $__uDb['nome'];
$_SESSION['usuario_login']  = $__uDb['usuario'];
$_SESSION['usuario_perfil'] = $__uDb['perfil'];

// ------------------------------------------------------------
// 3. Variáveis prontas para uso em qualquer página
// ------------------------------------------------------------
$__u = [
    'id'     => (int)($_SESSION['usuario_id'] ?? 0),
    'nome'   => $_SESSION['usuario_nome']   ?? '—',
    'login'  => $_SESSION['usuario_login']  ?? '',
    'perfil' => strtolower(trim((string)($_SESSION['usuario_perfil'] ?? ''))),
];

$__loginsDono = ['barreto', 'vbarreto'];
$__nomesDono  = ['barreto', 'valter barreto'];

$__loginLower = mb_strtolower(trim($__u['login']), 'UTF-8');
$__nomeLower  = mb_strtolower(trim($__u['nome']),  'UTF-8');

$__ehDono = in_array($__loginLower, $__loginsDono, true)
         || in_array($__nomeLower,  $__nomesDono,  true);

$__ehSuper      = in_array($__u['perfil'], ['super', 'superadmin', 'super_admin'], true);
$__ehAdminNivel = in_array($__u['perfil'], ['admin', 'administrador', 'adm'], true);
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

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ------------------------------------------------------------
// 4. Helpers (compatível com código antigo)
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
    global $__ehAdmin;
    return $__ehAdmin ?? false;
}

function exigirAdmin(): void {
    if (!ehAdmin()) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

function exigirAdminDono(): void {
    global $isAdmin;
    if (!($isAdmin ?? false)) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

// ------------------------------------------------------------
// 5. Barra de usuário padronizada
// ------------------------------------------------------------
function renderBarraUsuario(): string {
    global $__u, $__ehAdmin, $__ehSuper, $__ehDono,
           $__classePerfil, $__rotuloPerfil, $isAdmin;

    $classeBarra = $__ehAdmin ? ($__ehSuper ? 'super' : 'admin') : '';
    $nomeSeguro  = htmlspecialchars($__u['nome'] ?? '—');

    $html  = '<div class="barra-usuario ' . $classeBarra . '">';
    $html .= '<span>👤 <span class="nome-user">' . $nomeSeguro . '</span></span>';
    $html .= '<span class="perfil-tag ' . htmlspecialchars($__classePerfil) . '">'
           . htmlspecialchars($__rotuloPerfil) . '</span>';

    if ($isAdmin) {
        $badgeClasse = $__ehSuper ? 'badge-super' : 'badge-admin';
        $badgeTexto  = $__ehSuper ? '🔓 superadmin' : '🔓 administrador';
        $html .= '<span class="' . $badgeClasse . '">' . $badgeTexto . '</span>';
    } elseif ($__ehAdmin && !$__ehDono) {
        $html .= '<span class="badge-admin">🔓 admin (não autorizado a excluir)</span>';
    } else {
        $html .= '<span class="badge-visitante">👁️ visitante</span>';
    }

    $html .= '<a class="link-debug" href="?debug_sessao=1">🔍 ver sessão</a>';
    $html .= '</div>';

    return $html;
}