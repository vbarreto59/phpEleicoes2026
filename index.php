<?php
// index.php — Menu principal. Requer login.
// ------------------------------------------------------------
// Conexão automática (localhost ou remoto)
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';

// Autenticação (usa $pdo carregado acima)
require_once __DIR__ . '/auth.php';
date_default_timezone_set('America/Sao_Paulo');

// ------------------------------------------------------------
// CONFIGURAÇÃO: quem pode ver o grupo "⚙ Admin" no menu
// (além de ter perfil admin/superadmin)
// ------------------------------------------------------------
$__loginsDono = ['barreto', 'vbarreto'];        // campo `usuario` (login)
$__nomesDono  = ['barreto', 'valter barreto'];  // campo `nome`  (nome completo)

// ------------------------------------------------------------
// DETECÇÃO DE AMBIENTE
//   1) Tenta usar os globais definidos pelo conn_eleicoes.php
//   2) Se não existirem, detecta pelo $DB_HOST
// ------------------------------------------------------------
$__amb       = $GLOBALS['APP_ENV_ATUAL']     ?? null;
$__banco     = $GLOBALS['APP_DB_ATUAL']      ?? ($DB_NAME ?? '—');
$__hostReal  = $GLOBALS['APP_DB_HOST_ATUAL'] ?? ($DB_HOST ?? '—');

// Normaliza: se o global não veio ou veio como 'desconhecido', detecta pelo host
if ($__amb === null || $__amb === '' || $__amb === 'desconhecido') {
    $h = strtolower(trim((string)$__hostReal));
    $__amb = in_array($h, ['localhost', '127.0.0.1', '::1', ''], true)
        ? 'local'
        : 'producao';
}

$__ehLocal   = ($__amb === 'local');
$__labelAmb  = $__ehLocal ? 'LOCALHOST' : 'REMOTO';
$__iconeAmb  = $__ehLocal ? '🖥' : '🌐';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu — Eleições PE</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f5f7fa;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ============================================================
           BANNER DE AMBIENTE (topo da página)
           ============================================================ */
        .topo-banner {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
            padding: 9px 20px;
            font-family: Arial, sans-serif;
            font-size: 12.5px;
            font-weight: bold;
            letter-spacing: .3px;
            color: #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,.18);
            position: relative;
            z-index: 200;
        }
        .topo-banner.local {
            background: linear-gradient(90deg, #1e7e34, #28a745, #20c997);
        }
        .topo-banner.remoto {
            background: linear-gradient(90deg, #a71d2a, #dc3545, #e4606d);
        }
        .topo-banner .icone {
            font-size: 15px;
            line-height: 1;
        }
        .topo-banner .label {
            font-size: 12px;
        }
        .topo-banner .label strong,
        .topo-banner .info strong {
            background: rgba(255,255,255,.28);
            padding: 2px 10px;
            border-radius: 10px;
            font-weight: bold;
            letter-spacing: .4px;
            color: #fff;
        }
        .topo-banner .sep {
            opacity: .55;
            font-size: 14px;
        }
        .topo-banner .info {
            font-weight: normal;
            opacity: .95;
            font-size: 12px;
        }
        .topo-banner .pulsar {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 0 0 rgba(255,255,255,.7);
            animation: pulso-banner 1.8s infinite;
        }
        @keyframes pulso-banner {
            0%   { box-shadow: 0 0 0 0 rgba(255,255,255,.7); }
            70%  { box-shadow: 0 0 0 8px rgba(255,255,255,0); }
            100% { box-shadow: 0 0 0 0 rgba(255,255,255,0); }
        }

        @media (max-width: 700px) {
            .topo-banner { font-size: 11.5px; padding: 8px 12px; gap: 6px; }
            .topo-banner .info { font-size: 11px; }
            .topo-banner .label strong,
            .topo-banner .info strong { padding: 1px 7px; }
        }

        /* ------------------------------------------------------------
           Barra do menu
           ------------------------------------------------------------ */
        .top-menu {
            background: #ffffff;
            border-bottom: 1px solid #e3e3e3;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            padding: 0 20px;
            display: flex;
            align-items: center;
            gap: 2px;
            font-family: Arial, sans-serif;
        }
        .top-menu .marca {
            font-weight: bold;
            color: #0069d9;
            font-size: 15px;
            margin-right: 14px;
            padding: 14px 0;
            letter-spacing: .3px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .top-menu .marca::before {
            content: "🗳";
            margin-right: 6px;
        }
        .top-menu a,
        .top-menu .dropdown > .gatilho {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 14px 12px;
            color: #555;
            text-decoration: none;
            font-size: 13.5px;
            border-bottom: 3px solid transparent;
            transition: color .15s, border-color .15s, background .15s;
            white-space: nowrap;
            flex-shrink: 0;
            cursor: pointer;
            background: none;
            border-top: none;
            border-left: none;
            border-right: none;
            font-family: inherit;
        }
        .top-menu a:hover,
        .top-menu .dropdown:hover > .gatilho {
            color: #0069d9;
            background: #f4f8ff;
        }
        .top-menu a.ativo,
        .top-menu .dropdown.ativo > .gatilho {
            color: #0069d9;
            border-bottom-color: #0069d9;
            font-weight: bold;
        }
        .top-menu .espaco { flex: 1; min-width: 8px; }

        /* ------------------------------------------------------------
           Grupo Admin (destaque de cor no gatilho)
           ------------------------------------------------------------ */
        .top-menu .dropdown.grupo-admin > .gatilho {
            color: #856404;
        }
        .top-menu .dropdown.grupo-admin:hover > .gatilho {
            color: #6a4e00;
            background: #fff8e1;
        }
        .top-menu .dropdown.grupo-admin.grupo-super > .gatilho {
            color: #dc3545;
        }
        .top-menu .dropdown.grupo-admin.grupo-super:hover > .gatilho {
            color: #a71d2a;
            background: #fdecea;
        }
        .top-menu .dropdown.grupo-admin .menu-drop {
            border-top-color: #ffc107;
        }
        .top-menu .dropdown.grupo-admin.grupo-super .menu-drop {
            border-top-color: #dc3545;
        }

        /* ------------------------------------------------------------
           Bloco do usuário logado + botão Sair
           ------------------------------------------------------------ */
        .top-menu .usuario-box {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            flex-shrink: 0;
            white-space: nowrap;
        }
        .top-menu .usuario-box .nome {
            font-size: 13px;
            color: #333;
            font-weight: bold;
        }
        .top-menu .usuario-box .nome .saudacao {
            font-weight: normal;
            color: #888;
            margin-right: 3px;
        }
        .top-menu .usuario-box .perfil-tag {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 8px;
            font-weight: bold;
            letter-spacing: .5px;
            background: #e6f0fa;
            color: #0069d9;
        }
        .top-menu .usuario-box .perfil-tag.perfil-super {
            background: #dc3545;
            color: #fff;
        }
        .top-menu .usuario-box .perfil-tag.perfil-admin {
            background: #fff3cd;
            color: #856404;
        }
        .top-menu .usuario-box .perfil-tag.perfil-consulta {
            background: #e6f0fa;
            color: #0069d9;
        }
        .top-menu .usuario-box .btn-sair {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            background: #fff;
            color: #dc3545;
            border: 1.5px solid #dc3545;
            border-radius: 5px;
            font-size: 12.5px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            transition: background .15s, color .15s;
        }
        .top-menu .usuario-box .btn-sair:hover {
            background: #dc3545;
            color: #fff;
        }
        .top-menu .usuario-box .btn-sair::before {
            content: "⏻";
            font-size: 13px;
        }

        /* ------------------------------------------------------------
           Dropdown
           ------------------------------------------------------------ */
        .top-menu .dropdown {
            position: relative;
            display: inline-block;
        }
        .top-menu .dropdown > .gatilho .seta {
            font-size: 9px;
            margin-left: 3px;
            transition: transform .15s;
            display: inline-block;
        }
        .top-menu .dropdown:hover > .gatilho .seta {
            transform: rotate(180deg);
        }
        .top-menu .dropdown .menu-drop {
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 290px;
            background: #fff;
            border: 1px solid #e3e3e3;
            border-top: 3px solid #0069d9;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 14px rgba(0,0,0,.12);
            padding: 6px 0;
            display: none;
            z-index: 500;
            margin-top: 0;
        }
        .top-menu .dropdown:hover .menu-drop,
        .top-menu .dropdown:focus-within .menu-drop {
            display: block;
        }
        .top-menu .dropdown .menu-drop a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            font-size: 13.5px;
            color: #444;
            border-bottom: none;
            border-left: 3px solid transparent;
            text-decoration: none;
            transition: background .12s, color .12s, border-color .12s;
        }
        .top-menu .dropdown .menu-drop a:hover {
            background: #f4f8ff;
            color: #0069d9;
        }
        .top-menu .dropdown .menu-drop a.ativo {
            background: #e7f3ff;
            color: #0069d9;
            border-left-color: #0069d9;
            font-weight: bold;
        }
        .top-menu .dropdown .menu-drop a .icone {
            font-size: 16px;
            width: 22px;
            text-align: center;
            flex-shrink: 0;
            line-height: 1;
        }
        .top-menu .dropdown .menu-drop a .texto {
            flex: 1;
        }
        .top-menu .dropdown .menu-drop .divisor {
            height: 1px;
            background: #eee;
            margin: 6px 0;
        }

        .top-menu .dropdown .menu-drop a.admin-destaque {
            color: #a71d2a;
            font-weight: bold;
        }
        .top-menu .dropdown .menu-drop a.admin-destaque:hover {
            background: #fdecea;
            color: #dc3545;
        }

        /* ------------------------------------------------------------
           Responsivo
           ------------------------------------------------------------ */
        @media (max-width: 1100px) {
            .top-menu a,
            .top-menu .dropdown > .gatilho { padding: 12px 9px; font-size: 13px; }
        }
        @media (max-width: 900px) {
            .top-menu { flex-wrap: wrap; padding: 0 10px; gap: 0; }
            .top-menu .marca {
                width: 100%; padding: 10px 0 0; margin-right: 0;
            }
            .top-menu a,
            .top-menu .dropdown > .gatilho { padding: 10px 10px; font-size: 13px; }
            .top-menu .dropdown .menu-drop {
                min-width: 240px; border-radius: 0 0 6px 6px;
            }
            .top-menu .usuario-box {
                width: 100%;
                justify-content: flex-end;
                padding: 8px 0 12px;
                border-top: 1px solid #f0f0f0;
                margin-top: 4px;
            }
        }

        /* ------------------------------------------------------------
           Conteúdo da página
           ------------------------------------------------------------ */
        .conteudo {
            flex: 1;
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
            text-align: center;
            width: 100%;
        }
        .conteudo h1 {
            color: #0069d9;
            margin-bottom: 12px;
            font-size: 24px;
        }
        .conteudo p { color: #666; font-size: 15px; }

        /* ------------------------------------------------------------
           Imagem centralizada
           ------------------------------------------------------------ */
        .imagem-central {
            margin: 32px auto 0; max-width: 100%;
            display: flex; flex-direction: column;
            justify-content: center; align-items: center;
        }
        .imagem-central img {
            display: block; max-width: 100%; height: auto;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,.12);
            border: 1px solid #e3e3e3;
        }
        .imagem-central figcaption {
            margin-top: 10px; font-size: 13px; color: #888;
        }

        /* ------------------------------------------------------------
           Rodapé
           ------------------------------------------------------------ */
        .rodape {
            background: #ffffff;
            border-top: 1px solid #e3e3e3;
            padding: 18px 20px;
            text-align: center;
            font-size: 13px;
            color: #666;
            box-shadow: 0 -1px 3px rgba(0,0,0,.04);
        }
        .rodape .autor { font-weight: bold; color: #0069d9; }
        .rodape .ano { color: #444; }
        .rodape .direitos { color: #888; font-size: 12px; }
    </style>
</head>
<body>

<?php
// ------------------------------------------------------------
// Dados do usuário logado
// ------------------------------------------------------------
$__u = usuarioLogado();

// ------------------------------------------------------------
// Perfil do usuário (3 níveis)
// ------------------------------------------------------------
$__perfil       = $__u['perfil'];
$__ehSuper      = ($__perfil === 'superadmin');
$__ehAdminNivel = ($__perfil === 'admin');
$__ehAdmin      = ($__ehSuper || $__ehAdminNivel);
$__rotuloPerfil = function_exists('rotuloPerfil')
                    ? rotuloPerfil($__perfil)
                    : strtoupper($__perfil);
$__classePerfil = function_exists('classePerfil')
                    ? classePerfil($__perfil)
                    : ('perfil-' . $__perfil);

// ------------------------------------------------------------
// DONO DO SISTEMA — só estes podem ver o grupo "⚙ Admin"
// ------------------------------------------------------------
$__loginLower = mb_strtolower(trim((string)($__u['login'] ?? '')), 'UTF-8');
$__nomeLower  = mb_strtolower(trim((string)($__u['nome']  ?? '')), 'UTF-8');

$__ehDono = in_array($__loginLower, $__loginsDono, true)
         || in_array($__nomeLower,  $__nomesDono,  true);

$__mostrarAdmin = ($__ehAdmin && $__ehDono);
?>

<!-- ============================================================
     BANNER DE AMBIENTE — sempre no topo
     ============================================================ -->
<div class="topo-banner <?= $__ehLocal ? 'local' : 'remoto' ?>">
    <span class="pulsar"></span>
    <span class="icone"><?= $__iconeAmb ?></span>
    <span class="label">Ambiente: <strong><?= htmlspecialchars($__labelAmb) ?></strong></span>
    <span class="sep">·</span>
    <span class="info">Host: <strong><?= htmlspecialchars($__hostReal) ?></strong></span>
    <span class="sep">·</span>
    <span class="info">Banco: <strong><?= htmlspecialchars($__banco) ?></strong></span>
</div>

<nav class="top-menu">
    <span class="marca">Eleições PE 2026 - VERSÃO BETA 1.2</span>

    <!-- Grupo Geral -->
    <div class="dropdown">
        <button type="button" class="gatilho">
            📋 Geral <span class="seta">▼</span>
        </button>
        <div class="menu-drop">
            <a href="candidatos_campanhas.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">🧑</span>
                <span class="texto">G1-Candidatos e Campanhas</span>
            </a>
            <a href="mapa_municipios.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">📊</span>
                <span class="texto">G2-Mapa dos Municípios</span>
            </a>            
          
            <a href="comparar_colunas.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">📊</span>
                <span class="texto">G3-Comparar Candidatos 1</span>
            </a>

            <a href="comparar_candidatos.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">⚖️</span>
                <span class="texto">G4-Comparar Candidatos 2</span>
            </a>

            <a href="regioes.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">🌎</span>
                <span class="texto">G5-Cand. por Regiões 1</span>
            </a>              
            <a href="regioes_candidatos.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">🌎</span>
                <span class="texto">G6-Cand. por Regiões 2</span>
            </a>
          
        </div>
    </div>

    <!-- Grupo Análise -->
    <div class="dropdown">
        <button type="button" class="gatilho">
            📈 Análise <span class="seta">▼</span>
        </button>
        <div class="menu-drop">

            <a href="mapa_calor.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">🌡️</span>
                <span class="texto">A1-Mapa de Calor Zonas</span>
            </a>

            <a href="mapa_calor_dois_v2.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">🔥</span>
                <span class="texto">A2-Mapa de Calor — 2 Candidatos</span>
            </a>


        </div>
    </div>

    <!-- Grupo Equipe -->
    <div class="dropdown">
        <button type="button" class="gatilho">
            👥 Equipe <span class="seta">▼</span>
        </button>
        <div class="menu-drop">

            <a href="ranking_coordenadores.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">🏆</span>
                <span class="texto">E1-Relatório de Coordenadores</span>
            </a>
            <a href="ranking_liderancas.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">🥇</span>
                <span class="texto">E2-Relatório de Lideranças</span>
            </a>
            <a href="mapa_calor_equipe.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">🗺️</span>
                <span class="texto">E3-Mapa de Calor da Equipe</span>
            </a>
            <a href="cadastrar_coordenadores.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">👔</span>
                <span class="texto">E4-Cad. Coordenadores</span>
            </a>
            <a href="cadastrar_liderancas.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">🤝</span>
                <span class="texto">E5-Cad. Lideranças</span>
            </a>
            <a href="relatorio_desempenho.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">📊</span>
                <span class="texto">E6-Relatório de Desempenho</span>
            </a>
        </div>
    </div>

    <!-- Grupo Zonas -->
    <div class="dropdown">
        <button type="button" class="gatilho">
            🗳️ Zonas <span class="seta">▼</span>
        </button>
        <div class="menu-drop">
            <a href="zonas.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">👁️</span>
                <span class="texto">Z1-Visualizar Zonas/Mapa</span>
            </a>
            <a href="zonas_lista.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">👁️</span>
                <span class="texto">Z2-Visualizar Zonas/Listagem</span>
            </a>  
            <a href="zonas_bairros.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">👁️</span>
                <span class="texto">Z3-Visualizar Zonas/Bairros</span>
            </a> 
            <a href="bairro_regiao_editor.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">👁️</span>
                <span class="texto">Z4-Editar Bairros/Região</span>
            </a>             

            <a href="editar_zonas.php" target="_blank" rel="noopener noreferrer">
                <span class="icone">✏️</span>
                <span class="texto">Z5-Editar Zonas</span>
            </a>
        </div>
    </div>

    <!-- Grupo ⚙ Admin -->
    <?php if ($__mostrarAdmin): ?>
    <div class="dropdown grupo-admin <?= $__ehSuper ? 'grupo-super' : '' ?>">
        <button type="button" class="gatilho">
            ⚙ Admin <span class="seta">▼</span>
        </button>
        <div class="menu-drop">
            <a href="usuarios.php" target="_blank" rel="noopener noreferrer"
               class="<?= $__ehSuper ? 'admin-destaque' : '' ?>">
                <span class="icone">👥</span>
                <span class="texto">Usuários</span>
            </a>

            <?php if ($__ehSuper): ?>
                <div class="divisor"></div>

                <a href="auditoria.php" target="_blank" rel="noopener noreferrer">
                    <span class="icone">📝</span>
                    <span class="texto">Log de Auditoria</span>
                </a>
                <a href="backup.php" target="_blank" rel="noopener noreferrer">
                    <span class="icone">💾</span>
                    <span class="texto">Backup do Banco</span>
                </a>
                <a href="configuracoes.php" target="_blank" rel="noopener noreferrer">
                    <span class="icone">⚙️</span>
                    <span class="texto">Configurações do Sistema</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <span class="espaco"></span>

    <div class="usuario-box">
        <span class="nome">
            <span class="saudacao">👤 Olá,</span>
            <?= htmlspecialchars($__u['nome']) ?>
        </span>
        <span class="perfil-tag <?= htmlspecialchars($__classePerfil) ?>">
            <?= htmlspecialchars($__rotuloPerfil) ?>
        </span>
        <a href="logout.php" class="btn-sair">Sair</a>
    </div>
</nav>

<div class="conteudo">
    <h1>Menu Principal — Eleições PE</h1>
    <p>Todas as opções abrem em uma <strong>nova aba</strong>.</p>

    <figure class="imagem-central">
        <img src="mapa1.jpg" alt="Mapa das zonas eleitorais de Pernambuco">
        <figcaption>Mapa das zonas eleitorais</figcaption>
    </figure>
</div>

<footer class="rodape">
    <span class="autor">Valter Barreto</span>
    &middot;
    <span class="ano">2026</span>
    &middot;
    <span class="direitos">Todos os direitos reservados.</span>
</footer>

</body>
</html>