<?php
// instalar.php — Cria a tabela usuarios e os usuários iniciais.
// Rode UMA vez e depois APAGUE este arquivo.

$DB_HOST = 'localhost';
$DB_NAME = 'eleicoes';
$DB_USER = 'root';
$DB_PASS = 'caio';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 1) Cria a tabela
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            usuario        VARCHAR(60)  NOT NULL,
            senha_hash     VARCHAR(255) NOT NULL,
            nome           VARCHAR(120) NOT NULL,
            email          VARCHAR(120) NULL,
            perfil         ENUM('admin','consulta') NOT NULL DEFAULT 'consulta',
            ativo          TINYINT(1)   NOT NULL DEFAULT 1,
            criado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_usuario (usuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 2) Cria/atualiza os usuários iniciais
    $usuarios = [
        [
            'usuario' => 'barreto',
            'senha'   => 'caio',
            'nome'    => 'Valter Barreto',
            'email'   => null,
            'perfil'  => 'admin',
        ],
        [
            'usuario' => 'consulta',
            'senha'   => 'consulta123',
            'nome'    => 'Usuário de Consulta',
            'email'   => null,
            'perfil'  => 'consulta',
        ],
    ];

    foreach ($usuarios as $u) {
        $hash = password_hash($u['senha'], PASSWORD_DEFAULT);

        // Se já existe, só atualiza nome/perfil/senha_hash (mantém ativo)
        $stmt = $pdo->prepare(
            "INSERT INTO usuarios (usuario, senha_hash, nome, email, perfil, ativo)
             VALUES (:u, :s, :n, :e, :p, 1)
             ON DUPLICATE KEY UPDATE
                senha_hash = VALUES(senha_hash),
                nome       = VALUES(nome),
                email      = VALUES(email),
                perfil     = VALUES(perfil)"
        );
        $stmt->execute([
            ':u' => $u['usuario'],
            ':s' => $hash,
            ':n' => $u['nome'],
            ':e' => $u['email'],
            ':p' => $u['perfil'],
        ]);
    }

    echo "<h1>Instalação concluída</h1>";
    echo "<p>Usuários criados:</p>";
    echo "<ul>";
    echo "<li><b>barreto</b> / <code>caio</code> — perfil <b>admin</b></li>";
    echo "<li><b>consulta</b> / <code>consulta123</code> — perfil <b>consulta</b></li>";
    echo "</ul>";
    echo "<p style='color:#b94a48;'><b>Atenção:</b> apague este arquivo depois de rodar.</p>";
    echo "<p><a href='login.php'>Ir para o login</a></p>";

} catch (Exception $e) {
    die('Erro: ' . $e->getMessage());
}