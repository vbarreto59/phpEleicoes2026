<?php
// ------------------------------------------------------------
// Mapa Coroplético por Município — br_pe.json
// mapa_municipios.php
// Faixas:
//   < 50 votos          → goiaba claro
//   50 a 199 votos      → laranja claro
//   >= 200 votos        → escala azul
// Zoom: enquadra todo o estado (vertical + horizontal)
// Top N configurável no painel lateral
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ------------------------------------------------------------
// Fallback de usuário
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
    $__classePerfil = 'super';
    $__rotuloPerfil = 'SUPERADMIN';
} elseif ($__ehAdminNivel) {
    $__classePerfil = 'admin';
    $__rotuloPerfil = 'ADMIN';
} else {
    $__classePerfil = 'visitante';
    $__rotuloPerfil = 'VISITANTE';
}

// ------------------------------------------------------------
// Filtros
// ------------------------------------------------------------
$ano       = $_GET['ano']       ?? '';
$candidato = $_GET['candidato'] ?? '';

// ------------------------------------------------------------
// Top N configurável
// Valores válidos: 10, 15, 20, 30, 50, 'todos'
// Padrão: 15
// ------------------------------------------------------------
$topRaw    = $_GET['top'] ?? '15';
$opcoesTop = [10, 15, 20, 30, 50];

if ($topRaw === 'todos' || $topRaw === 'all') {
    $topN        = 'todos';
    $topNLabel   = 'Todos';
} else {
    $topN = (int)$topRaw;
    if (!in_array($topN, $opcoesTop, true)) {
        $topN = 15; // fallback
    }
    $topNLabel = 'Top ' . $topN;
}

$filtros = [];
$params  = [];

if ($ano !== '') {
    $filtros[]      = "v.ano = :ano";
    $params[':ano'] = $ano;
}
if ($candidato !== '') {
    $filtros[]            = "v.nomeCandidatoResp = :candidato";
    $params[':candidato'] = $candidato;
}
$where = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

// ------------------------------------------------------------
// Votos agregados por município
// ------------------------------------------------------------
$sql = "SELECT v.nomeMunicipio, SUM(v.qtdVotos) AS total
        FROM votos v
        $where
        GROUP BY v.nomeMunicipio
        ORDER BY total DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$dados      = [];
$totalGeral = 0;
$maxVotos   = 0;

foreach ($stmt->fetchAll() as $r) {
    $mun = trim((string)($r['nomeMunicipio'] ?? ''));
    if ($mun === '') continue;

    $total       = (int)$r['total'];
    $dados[$mun] = $total;
    $totalGeral += $total;
    if ($total > $maxVotos) $maxVotos = $total;
}

// Painel lateral — Top N (ou todos)
$topMunicipios = $dados;
arsort($topMunicipios);

if ($topN !== 'todos') {
    $topMunicipios = array_slice($topMunicipios, 0, $topN, true);
}

// ------------------------------------------------------------
// Endpoint JSON
// ------------------------------------------------------------
if (($_GET['formato'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ano'        => $ano,
        'candidato'  => $candidato,
        'top'        => $topN,
        'total'      => $totalGeral,
        'max'        => $maxVotos,
        'quantidade' => count($dados),
        'municipios' => $dados,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------
// Listas para selects
// ------------------------------------------------------------
$anos = $pdo->query(
    "SELECT DISTINCT ano FROM votos WHERE ano IS NOT NULL ORDER BY ano DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$candidatos = $pdo->query(
    "SELECT DISTINCT nomeCandidatoResp
     FROM votos
     WHERE nomeCandidatoResp IS NOT NULL AND nomeCandidatoResp <> ''
     ORDER BY nomeCandidatoResp"
)->fetchAll(PDO::FETCH_COLUMN);

// ------------------------------------------------------------
// GeoJSON
// ------------------------------------------------------------
$geojsonPath   = __DIR__ . '/br_pe.json';
$geojsonExiste = file_exists($geojsonPath);

$rotuloMunicipio = $candidato !== '' ? $candidato : 'Todos os candidatos';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Mapa por Município — Votos</title>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom: 6px; }
    .subtitulo { color:#666; font-size:13px; margin-bottom: 18px; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    .barra-usuario {
        display: flex; align-items: center; gap: 12px;
        background: #fff; padding: 10px 16px; border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 18px; font-size: 13px;
    }
    .barra-usuario.super { border-left: 5px solid #6f42c1; }
    .barra-usuario.admin { border-left: 5px solid #0d6efd; }
    .nome-user { font-weight: 600; }
    .perfil-tag { background:#e9ecef; color:#495057; padding:2px 10px;
                  border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; }
    .perfil-tag.admin { background:#0d6efd; color:#fff; }
    .perfil-tag.super { background:#6f42c1; color:#fff; }
    .badge-super     { background:#6f42c1; color:#fff; padding:2px 10px; border-radius:12px; font-size:11px; }
    .badge-admin     { background:#0d6efd; color:#fff; padding:2px 10px; border-radius:12px; font-size:11px; }
    .badge-visitante { background:#e9ecef; color:#495057; padding:2px 10px; border-radius:12px; font-size:11px; }
    .link-debug { margin-left:auto; color:#6c757d; text-decoration:none; font-size:12px; }
    .link-debug:hover { color:#0d6efd; text-decoration:underline; }

    form.filtro { background:#fff; padding:15px; border-radius:8px;
                  box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px; }
    .linha { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin-bottom:8px; }
    .linha:last-child { margin-bottom:0; }
    label { font-weight:bold; font-size:13px; }
    select, button { padding:6px 8px; border-radius:4px; border:1px solid #ccc; font-size:14px; }
    button { cursor:pointer; border:none; }
    .btn-consultar { background:#0069d9; color:#fff; padding:8px 16px; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none;
                  padding:8px 14px; border-radius:4px; display:inline-block; font-size:14px; }
    .btn-limpar:hover { background:#5a6268; }
    .btn-json { background:#198754; color:#fff; text-decoration:none;
                padding:8px 14px; border-radius:4px; display:inline-block; font-size:14px; }
    .btn-json:hover { background:#146c43; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso.azul { background:#e7f3ff; border-left-color:#0069d9; }
    .aviso.vermelho { background:#f8d7da; border-left-color:#dc3545; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi { background:#fff; border-radius:8px; padding:12px 20px;
           box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:170px;
           border-left:5px solid #0069d9; }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:22px; font-weight:bold; color:#0069d9; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi.goiaba  { border-left-color:#e07a8b; } .kpi.goiaba .valor  { color:#e07a8b; }

    .grid { display:grid; grid-template-columns: 1fr 340px; gap: 20px; }
    @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }

    #mapa { height: 660px; width: 100%; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.15); background:#eef3f8; }

    .painel-lateral { background:#fff; border-radius:8px; padding:14px;
                      box-shadow: 0 1px 3px rgba(0,0,0,.1);
                      max-height: 660px; overflow-y: auto; }
    .painel-lateral h3 { margin:0 0 10px; font-size:15px; color:#0069d9;
                         border-bottom:1px solid #eee; padding-bottom:8px;
                         display:flex; justify-content:space-between;
                         align-items:center; }
    .painel-lateral h3 .contador {
        font-size:11px; color:#888; font-weight:normal;
    }
    .linha-zona { display:flex; align-items:center; gap:8px;
                  padding:6px 4px; border-bottom:1px solid #f0f0f0;
                  font-size:13px; cursor:pointer; border-radius:4px; transition:background .12s; }
    .linha-zona:hover { background:#eaf3fb; }
    .linha-zona .pos  { font-weight:bold; color:#0069d9; width:26px; }
    .linha-zona .nome { flex:1; color:#555; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .linha-zona .votos{ font-weight:bold; color:#0b3d91; }

    .legenda-mapa {
        background: rgba(255,255,255,.96); padding: 8px 12px; border-radius:6px;
        box-shadow: 0 1px 4px rgba(0,0,0,.25); font-size: 12px; min-width: 220px;
    }
    .legenda-mapa .titulo { font-weight:bold; color:#0b3d91; margin-bottom:4px; }
    .legenda-mapa .gradiente {
        height: 14px; border-radius:4px; margin: 6px 0;
        background: linear-gradient(to right,
            #c6dbef, #9ecae1, #6baed6, #4292c6,
            #2171b5, #08519c, #08306b);
        border: 1px solid #d0d0d0;
    }
    .legenda-mapa .extremos { display:flex; justify-content:space-between; color:#555; }

    .leaflet-tooltip.municipio-tip {
        background:#0b3d91; color:#fff; border:none; border-radius:4px;
        font-size:12px; padding:5px 9px;
        box-shadow:0 2px 8px rgba(0,0,0,.4);
    }
    .leaflet-tooltip.municipio-tip::before { display:none; }

    .rotulo-municipio {
        background: transparent;
        border: none;
        box-shadow: none;
        color: #0b3d91;
        font-weight: 600;
        font-size: 11px;
        text-shadow: 0 0 3px #fff, 0 0 3px #fff, 0 0 3px #fff;
    }
    .leaflet-tooltip.rotulo-municipio::before { display:none; }

    /* Botão "Enquadrar PE" no canto superior esquerdo do mapa */
    .btn-fit-estado {
        background: #fff; color: #0b3d91; border: 1px solid #ccd;
        padding: 6px 10px; border-radius: 6px; font-size: 12px;
        font-weight: 600; cursor: pointer;
        box-shadow: 0 1px 4px rgba(0,0,0,.2);
    }
    .btn-fit-estado:hover { background: #eaf3fb; }



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

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Mapa por Município — PE</h1>

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

    <a class="link-debug" href="?debug_sessao=1">🔍 ver sessão</a>
</div>

<div class="subtitulo">
    Faixas de cor:
    <b style="color:#c96a78;">goiaba claro</b> &lt; 50 votos ·
    <b style="color:#b07a20;">laranja claro</b> 50–199 votos ·
    <b style="color:#0b3d91;">escala azul</b> ≥ 200 votos.
    Contornos tracejados delimitam cada município (<code>br_pe.json</code>).
</div>

<form class="filtro" method="get" action="mapa_municipios.php">
    <div class="linha">
        <label for="ano">Ano:</label>
        <select name="ano" id="ano">
            <option value="">Todos os anos</option>
            <?php foreach ($anos as $a): ?>
                <option value="<?= htmlspecialchars((string)$a) ?>"
                    <?= (string)$a === (string)$ano ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$a) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="candidato">Candidato:</label>
        <select name="candidato" id="candidato">
            <option value="">Todos</option>
            <?php foreach ($candidatos as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= $c === $candidato ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="top">Top municípios:</label>
        <select name="top" id="top">
            <?php foreach ($opcoesTop as $opt): ?>
                <option value="<?= $opt ?>" <?= ($topN === $opt) ? 'selected' : '' ?>>
                    Top <?= $opt ?>
                </option>
            <?php endforeach; ?>
            <option value="todos" <?= ($topN === 'todos') ? 'selected' : '' ?>>
                Todos
            </option>
        </select>

        <button type="submit" class="btn-consultar">Aplicar</button>
        <a href="mapa_municipios.php" class="btn-limpar">Limpar</a>
        
    </div>
</form>

<?php if (!$geojsonExiste): ?>
    <div class="aviso vermelho">
        Arquivo <b>br_pe.json</b> não encontrado em <code><?= htmlspecialchars($geojsonPath) ?></code>.
        Coloque o GeoJSON dos municípios de PE no mesmo diretório deste script.
    </div>
<?php endif; ?>

<?php if (!$dados): ?>
    <div class="aviso">
        Nenhum voto encontrado para os filtros selecionados.
    </div>
<?php else: ?>

    <?php
    // Contadores por faixa
    $qtdGoiaba = 0; $qtdLaranja = 0; $qtdAzul = 0;
    foreach ($dados as $q) {
        if     ($q < 50)  $qtdGoiaba++;
        elseif ($q < 200) $qtdLaranja++;
        else              $qtdAzul++;
    }
    ?>

    <div class="kpis">
        <div class="kpi">
            <div class="rotulo">Candidato</div>
            <div class="valor" style="font-size:16px;"><?= htmlspecialchars($rotuloMunicipio) ?></div>
        </div>
        <?php if ($ano !== ''): ?>
            <div class="kpi roxo">
                <div class="rotulo">Ano</div>
                <div class="valor"><?= htmlspecialchars((string)$ano) ?></div>
            </div>
        <?php endif; ?>
        <div class="kpi verde">
            <div class="rotulo">Total de votos</div>
            <div class="valor"><?= number_format($totalGeral, 0, ',', '.') ?></div>
        </div>
        <div class="kpi">
            <div class="rotulo">Municípios com votos</div>
            <div class="valor"><?= number_format(count($dados), 0, ',', '.') ?></div>
        </div>
        <div class="kpi laranja">
            <div class="rotulo">Maior município</div>
            <div class="valor"><?= number_format($maxVotos, 0, ',', '.') ?></div>
        </div>
        <div class="kpi goiaba">
            <div class="rotulo">&lt; 50 votos</div>
            <div class="valor"><?= number_format($qtdGoiaba, 0, ',', '.') ?></div>
        </div>
        <div class="kpi laranja">
            <div class="rotulo">50 – 199 votos</div>
            <div class="valor"><?= number_format($qtdLaranja, 0, ',', '.') ?></div>
        </div>
        <div class="kpi">
            <div class="rotulo">≥ 200 votos</div>
            <div class="valor"><?= number_format($qtdAzul, 0, ',', '.') ?></div>
        </div>
    </div>

    <div class="grid">
        <div id="mapa"></div>

        <div class="painel-lateral">
            <h3>
                <span><?= $topN === 'todos' ? 'Todos os municípios' : 'Top ' . (int)$topN . ' municípios' ?></span>
                <span class="contador"><?= count($topMunicipios) ?> itens</span>
            </h3>
            <?php $pos = 1; foreach ($topMunicipios as $nome => $qtd): ?>
                <div class="linha-zona" data-municipio="<?= htmlspecialchars($nome) ?>">
                    <span class="pos"><?= $pos++ ?></span>
                    <span class="nome"><?= htmlspecialchars($nome) ?></span>
                    <span class="votos"><?= number_format($qtd, 0, ',', '.') ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        // ------------------------------------------------------------
        // JSON gerado pelo PHP
        // ------------------------------------------------------------
        const DADOS = {
            ano:        <?= json_encode($ano,       JSON_UNESCAPED_UNICODE) ?>,
            candidato:  <?= json_encode($candidato, JSON_UNESCAPED_UNICODE) ?>,
            top:        <?= json_encode($topN,      JSON_UNESCAPED_UNICODE) ?>,
            total:      <?= (int)$totalGeral ?>,
            max:        <?= (int)$maxVotos ?>,
            municipios: <?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        };

        // ------------------------------------------------------------
        // Normalização
        // ------------------------------------------------------------
        function normalizar(s) {
            return String(s || '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/\s+/g, ' ')
                .trim();
        }

        const VOTOS_NORM = {};
        for (const k in DADOS.municipios) {
            VOTOS_NORM[normalizar(k)] = DADOS.municipios[k];
        }

        // ------------------------------------------------------------
        // Cores
        // ------------------------------------------------------------
        const LIMITE_GOIABA  = 50;
        const LIMITE_LARANJA = 200;

        const COR_GOIABA     = '#ffd6d6';
        const COR_LARANJA    = '#ffd9a0';
        const COR_SEM_DADO   = '#e8e8e8';
        const COR_SEM_MATCH  = '#ffd8d8';

        const ESCALA_AZUL = [
            '#c6dbef', '#9ecae1', '#6baed6', '#4292c6',
            '#2171b5', '#08519c', '#08306b'
        ];

        const COR_BORDA       = '#1a3a5c';
        const COR_BORDA_HOVER = '#001f3f';

        function corPorVotos(v, temMatch) {
            if (!temMatch) return COR_SEM_MATCH;
            if (!v || v <= 0) return COR_SEM_DADO;
            if (v < LIMITE_GOIABA)  return COR_GOIABA;
            if (v < LIMITE_LARANJA) return COR_LARANJA;

            const maxEfetivo = Math.max(DADOS.max - LIMITE_LARANJA, 1);
            const t = Math.min(1, (v - LIMITE_LARANJA) / maxEfetivo);
            const tSmooth = Math.sqrt(t);
            const idx = Math.min(
                ESCALA_AZUL.length - 1,
                Math.max(0, Math.round(tSmooth * (ESCALA_AZUL.length - 1)))
            );
            return ESCALA_AZUL[idx];
        }

        function rotuloFaixa(v, temMatch) {
            if (!temMatch) return { txt: 'sem dados no banco', cor: '#666' };
            if (!v || v <= 0) return { txt: 'sem votos', cor: '#666' };
            if (v < LIMITE_GOIABA)  return { txt: '< 50 votos — goiaba claro', cor: '#b0445a' };
            if (v < LIMITE_LARANJA) return { txt: '50–199 votos — laranja claro', cor: '#8a5a00' };
            return { txt: '≥ 200 votos — escala azul', cor: '#0b3d91' };
        }

        // ------------------------------------------------------------
        // Mapa base
        // ------------------------------------------------------------
        const mapa = L.map('mapa', {
            scrollWheelZoom: true,
            zoomControl: true,
            preferCanvas: false
        }).setView([-8.3, -37.9], 7);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(mapa);

        // ------------------------------------------------------------
        // Helpers de nome no GeoJSON
        // ------------------------------------------------------------
        function getNome(feature) {
            const p = feature.properties || {};
            return p.name       || p.nome      || p.NOME       ||
                   p.NM_MUNICIP || p.municipio || p.Municipio  ||
                   p.name_muni  || p.nome_municipio || '';
        }

        function formatNum(n) { return Number(n || 0).toLocaleString('pt-BR'); }
        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            })[c]);
        }

        // ------------------------------------------------------------
        // Enquadramento integral do estado
        // ------------------------------------------------------------
        let estadoBounds = null;

        function enquadrarEstado(animar = true) {
            if (!estadoBounds || !estadoBounds.isValid()) return;

            // fitBounds calcula automaticamente o zoom que caiba
            // o retângulo INTEIRO (vertical + horizontal) no viewport.
            // Sem maxZoom para não cortar o estado.
            mapa.fitBounds(estadoBounds, {
                padding: [10, 10],
                animate: animar
            });
        }

        // Botão "Enquadrar PE"
        const ctrlFit = L.control({ position: 'topleft' });
        ctrlFit.onAdd = function () {
            const div = L.DomUtil.create('div');
            const btn = L.DomUtil.create('button', 'btn-fit-estado', div);
            btn.type = 'button';
            btn.innerHTML = '🗺️ Enquadrar PE';
            L.DomEvent.disableClickPropagation(div);
            L.DomEvent.on(btn, 'click', e => {
                L.DomEvent.stop(e);
                enquadrarEstado(true);
            });
            return div;
        };
        ctrlFit.addTo(mapa);

        // ------------------------------------------------------------
        // Carrega o GeoJSON e monta o coroplético
        // ------------------------------------------------------------
        let geoLayer   = null;
        let labelLayer = null;
        const MOSTRAR_LABELS_A_PARTIR_DE = 9;

        fetch('br_pe.json', { cache: 'no-store' })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(geojson => {
                console.log('[GeoJSON] features:', geojson.features?.length);
                console.log('[GeoJSON] properties da 1ª:', geojson.features?.[0]?.properties);
                console.log('[Banco] municípios:', Object.keys(DADOS.municipios).length);

                geoLayer = L.geoJSON(geojson, {
                    style: feature => {
                        const nome     = getNome(feature);
                        const chave    = normalizar(nome);
                        const temMatch = Object.prototype.hasOwnProperty.call(VOTOS_NORM, chave);
                        const votos    = temMatch ? VOTOS_NORM[chave] : 0;

                        return {
                            fillColor:   corPorVotos(votos, temMatch),
                            fillOpacity: 0.88,
                            color:       COR_BORDA,
                            weight:      1.1,
                            opacity:     1,
                            dashArray:   '3,2'
                        };
                    },

                    onEachFeature: (feature, lyr) => {
                        const nome     = getNome(feature);
                        const chave    = normalizar(nome);
                        const temMatch = Object.prototype.hasOwnProperty.call(VOTOS_NORM, chave);
                        const votos    = temMatch ? VOTOS_NORM[chave] : 0;
                        const pct      = DADOS.total > 0
                                       ? ((votos / DADOS.total) * 100).toFixed(2) + '%'
                                       : '0%';
                        const faixa    = rotuloFaixa(votos, temMatch);

                        const avisoSemMatch = temMatch ? '' :
                            '<div style="color:#ffd0d0;font-size:11px;">⚠️ não encontrado no banco</div>';

                        lyr.bindTooltip(
                            `<b>${escapeHtml(nome)}</b><br>${formatNum(votos)} votos (${pct})` +
                            `<div style="font-size:11px;color:${faixa.cor};margin-top:2px;">${faixa.txt}</div>` +
                            avisoSemMatch,
                            { sticky: true, direction: 'top', className: 'municipio-tip' }
                        );

                        let badgeFaixa = '';
                        if (temMatch && votos > 0 && votos < LIMITE_GOIABA) {
                            badgeFaixa = '<div style="font-size:11px;color:#8a2a40;margin-top:4px;background:'+COR_GOIABA+';display:inline-block;padding:2px 8px;border-radius:10px;">Goiaba claro: menos de 50 votos</div>';
                        } else if (temMatch && votos >= LIMITE_GOIABA && votos < LIMITE_LARANJA) {
                            badgeFaixa = '<div style="font-size:11px;color:#8a5a00;margin-top:4px;background:'+COR_LARANJA+';display:inline-block;padding:2px 8px;border-radius:10px;">Laranja claro: 50 a 199 votos</div>';
                        }

                        lyr.bindPopup(
                            `<div style="font-size:14px;min-width:180px;">
                                <div style="font-weight:bold;color:#0b3d91;font-size:15px;">
                                    ${escapeHtml(nome)}
                                </div>
                                <hr style="border:none;border-top:1px solid #eee;margin:6px 0;">
                                <div style="margin-top:4px;">
                                    <b style="color:#08519c;font-size:16px;">
                                        ${formatNum(votos)}
                                    </b> votos
                                    <span style="color:#888;">(${pct})</span>
                                </div>
                                ${badgeFaixa}
                                <?php if ($candidato !== ''): ?>
                                <div style="font-size:12px;color:#555;margin-top:6px;">
                                    <b>Candidato:</b> <?= htmlspecialchars(addslashes($candidato)) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($ano !== ''): ?>
                                <div style="font-size:12px;color:#555;">
                                    <b>Ano:</b> <?= htmlspecialchars(addslashes((string)$ano)) ?>
                                </div>
                                <?php endif; ?>
                            </div>`
                        );

                        lyr.on({
                            mouseover: e => {
                                e.target.setStyle({
                                    weight:    3,
                                    color:     COR_BORDA_HOVER,
                                    dashArray: ''
                                });
                                e.target.bringToFront();
                            },
                            mouseout: e => geoLayer.resetStyle(e.target)
                        });
                    }
                }).addTo(mapa);

                // ---- Guarda os bounds para o enquadramento integral ----
                estadoBounds = geoLayer.getBounds();

                // Enquadra o estado inteiro já no carregamento
                if (estadoBounds.isValid()) {
                    // Dois passos: primeiro "fitBounds" sem animação,
                    // depois um invalidateSize + re-fit para garantir que
                    // o tamanho do container está correto (evita zoom
                    // aplicado antes do layout final).
                    mapa.invalidateSize();
                    enquadrarEstado(false);
                    setTimeout(() => {
                        mapa.invalidateSize();
                        enquadrarEstado(false);
                    }, 60);
                }

                // ---- Rótulos ----
                labelLayer = L.layerGroup();
                geoLayer.eachLayer(lyr => {
                    const centro = lyr.getBounds().getCenter();
                    const nome   = getNome(lyr.feature);
                    const rotulo = L.marker(centro, {
                        interactive: false,
                        icon: L.divIcon({
                            className: 'rotulo-municipio',
                            html: escapeHtml(nome),
                            iconSize: null
                        })
                    });
                    labelLayer.addLayer(rotulo);
                });

                function atualizarRotulos() {
                    if (mapa.getZoom() >= MOSTRAR_LABELS_A_PARTIR_DE) {
                        if (!mapa.hasLayer(labelLayer)) labelLayer.addTo(mapa);
                    } else {
                        if (mapa.hasLayer(labelLayer)) mapa.removeLayer(labelLayer);
                    }
                }
                mapa.on('zoomend', atualizarRotulos);
                atualizarRotulos();

                // ---- Clique na lista lateral ----
                document.querySelectorAll('.linha-zona[data-municipio]').forEach(el => {
                    el.addEventListener('click', () => {
                        const alvo = normalizar(el.dataset.municipio);
                        geoLayer.eachLayer(lyr => {
                            const nome = getNome(lyr.feature);
                            if (normalizar(nome) === alvo) {
                                mapa.fitBounds(lyr.getBounds(), { maxZoom: 11 });
                                lyr.openPopup();
                            }
                        });
                        document.getElementById('mapa')
                            .scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                });
            })
            .catch(err => {
                console.error('Erro ao carregar br_pe.json:', err);
                const aviso = document.createElement('div');
                aviso.className = 'aviso vermelho';
                aviso.style.marginTop = '10px';
                aviso.innerHTML = 'Falha ao carregar <b>br_pe.json</b>: ' +
                                  escapeHtml(err.message);
                document.getElementById('mapa').replaceWith(aviso);
            });

        // ------------------------------------------------------------
        // Re-enquadra ao redimensionar a janela (se o usuário não
        // mexeu no zoom manualmente, mantém o estado sempre visível)
        // ------------------------------------------------------------
        let usuarioMexeuNoZoom = false;
        mapa.on('zoomstart', e => {
            // Ignora zoomstart disparado pelo próprio fitBounds
            if (e.hard === true) return;
        });
        mapa.on('zoomend', () => {
            if (estadoBounds && estadoBounds.isValid()) {
                // Se o usuário deu zoom muito longe do estado, não força nada.
                // Só re-enquadra em resize se o viewport atual ainda inclui PE.
            }
        });

        window.addEventListener('resize', () => {
            if (!estadoBounds || !estadoBounds.isValid()) return;
            // Só re-enquadra se o usuário não estiver com zoom muito próximo
            // (evita perder a posição de quem está analisando um município).
            const z = mapa.getZoom();
            if (z <= 9) {
                mapa.invalidateSize();
                enquadrarEstado(false);
            } else {
                mapa.invalidateSize();
            }
        });

        // ------------------------------------------------------------
        // Legenda
        // ------------------------------------------------------------
        const legenda = L.control({ position: 'bottomright' });
        legenda.onAdd = function () {
            const div = L.DomUtil.create('div', 'legenda-mapa');
            div.innerHTML = `
                <div class="titulo">Votos por município</div>
                <div class="gradiente"></div>
                <div class="extremos">
                    <span>${formatNum(LIMITE_LARANJA)}</span>
                    <span>${formatNum(DADOS.max)}</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;margin-top:8px;
                            padding-top:6px;border-top:1px solid #eee;">
                    <span style="display:inline-block;width:14px;height:14px;
                                 background:${COR_GOIABA};border:1px solid #d9a6ad;
                                 border-radius:3px;"></span>
                    <span style="color:#b0445a;font-size:11px;">
                        &lt; ${formatNum(LIMITE_GOIABA)} votos (goiaba claro)
                    </span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;margin-top:4px;">
                    <span style="display:inline-block;width:14px;height:14px;
                                 background:${COR_LARANJA};border:1px solid #d9a86a;
                                 border-radius:3px;"></span>
                    <span style="color:#8a5a00;font-size:11px;">
                        ${formatNum(LIMITE_GOIABA)}–${formatNum(LIMITE_LARANJA-1)} votos (laranja claro)
                    </span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;margin-top:4px;">
                    <span style="display:inline-block;width:14px;height:14px;
                                 background:${COR_SEM_MATCH};border:1px solid #c0c0c0;
                                 border-radius:3px;"></span>
                    <span style="color:#666;font-size:11px;">sem dados no banco</span>
                </div>`;
            return div;
        };
        legenda.addTo(mapa);
    </script>

<?php endif; ?>

<footer class="rodape">
    <span class="autor">Valter Barreto</span>
    &middot;
    <span class="ano">2026</span>
    &middot;
    <span class="direitos">Todos os direitos reservados.</span>
</footer>

</body>
</html>