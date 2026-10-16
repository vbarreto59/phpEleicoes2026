<?php
// ------------------------------------------------------------
// Configuração do banco
// mapa_calor.php
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';
date_default_timezone_set('America/Sao_Paulo');

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
// Fallback: garante que as variáveis de usuário existam
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
$municipio = $_GET['municipio'] ?? '';   // '' = Todos os municípios
$ano       = $_GET['ano']       ?? '';   // '' = Todos os anos
$candidato = $_GET['candidato'] ?? '';
$raio      = isset($_GET['raio'])  ? (int)$_GET['raio']  : 25;
$opacidade = isset($_GET['opac'])  ? (float)$_GET['opac'] : 0.65;

if ($raio < 5)   $raio = 5;
if ($raio > 80)  $raio = 80;
if ($opacidade < 0.1) $opacidade = 0.1;
if ($opacidade > 1)   $opacidade = 1;

$filtros = [];
$params  = [];

// Só filtra por município se não for "todos"
if ($municipio !== '') {
    $filtros[] = "v.nomeMunicipio = :municipio";
    $params[':municipio'] = $municipio;
}
// Filtro por ano (ajuste o nome da coluna se no seu schema for diferente)
if ($ano !== '') {
    $filtros[] = "v.ano = :ano";
    $params[':ano'] = $ano;
}
if ($candidato !== '') {
    $filtros[] = "v.nomeCandidatoResp = :candidato";
    $params[':candidato'] = $candidato;
}

$where = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

// ------------------------------------------------------------
// Pontos do mapa de calor
// ------------------------------------------------------------
$pontos       = [];
$zonas        = [];
$totalVotos   = 0;
$totalComCoord = 0;
$maxVotos     = 0;
$qtdMunicipios = 0;

$sql = "SELECT
            v.numeroZona,
            v.nomeMunicipio,
            SUM(v.qtdVotos) AS total,
            z.endereco_tse,
            z.bairro,
            z.cep,
            z.latitude,
            z.longitude
        FROM votos v
        LEFT JOIN zonas_enderecos z ON z.numeroZona = v.numeroZona
        $where
        GROUP BY v.numeroZona, v.nomeMunicipio,
                 z.endereco_tse, z.bairro, z.cep,
                 z.latitude, z.longitude
        ORDER BY total DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$municipiosSet = [];

foreach ($stmt->fetchAll() as $row) {
    $total = (int)$row['total'];
    $totalVotos += $total;

    if (!empty($row['nomeMunicipio'])) {
        $municipiosSet[$row['nomeMunicipio']] = true;
    }

    $temCoord = !empty($row['latitude']) && !empty($row['longitude']);

    $zonaInfo = [
        'zona'      => $row['numeroZona'],
        'municipio' => $row['nomeMunicipio'] ?? '',
        'total'     => $total,
        'endereco'  => $row['endereco_tse'] ?? '',
        'bairro'    => $row['bairro'] ?? '',
        'cep'       => $row['cep'] ?? '',
        'lat'       => $temCoord ? (float)$row['latitude']  : null,
        'lng'       => $temCoord ? (float)$row['longitude'] : null,
    ];
    $zonas[] = $zonaInfo;

    if ($temCoord) {
        $pontos[] = [
            'lat'       => (float)$row['latitude'],
            'lng'       => (float)$row['longitude'],
            'total'     => $total,
            'zona'      => $row['numeroZona'],
            'municipio' => $row['nomeMunicipio'] ?? '',
            'endereco'  => $row['endereco_tse'] ?? '',
            'bairro'    => $row['bairro'] ?? '',
        ];
        $totalComCoord += $total;
        if ($total > $maxVotos) $maxVotos = $total;
    }
}

$qtdMunicipios = count($municipiosSet);

// ------------------------------------------------------------
// Listas para os selects
// ------------------------------------------------------------
$municipios = $pdo->query(
    "SELECT DISTINCT nomeMunicipio FROM votos ORDER BY nomeMunicipio"
)->fetchAll(PDO::FETCH_COLUMN);

$anos = $pdo->query(
    "SELECT DISTINCT ano FROM votos WHERE ano IS NOT NULL ORDER BY ano DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$candidatos = $pdo->query(
    "SELECT DISTINCT nomeCandidatoResp FROM votos ORDER BY nomeCandidatoResp"
)->fetchAll(PDO::FETCH_COLUMN);

// Título / rótulo conforme seleção
$rotuloMunicipio = $municipio !== ''
    ? $municipio
    : 'Todos os municípios (' . $qtdMunicipios . ')';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Mapa de Calor — Votos</title>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom: 6px; }
    .subtitulo { color:#666; font-size:13px; margin-bottom: 18px; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    /* ===== Barra de usuário (mesmo modelo do consultar.php) ===== */
    .barra-usuario {
        display: flex;
        align-items: center;
        gap: 12px;
        background: #fff;
        padding: 10px 16px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.08);
        margin-bottom: 18px;
        font-size: 13px;
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
    .link-debug {
        margin-left: auto; color: #6c757d;
        text-decoration: none; font-size: 12px;
    }
    .link-debug:hover { color: #0d6efd; text-decoration: underline; }

    form.filtro {
        background:#fff; padding:15px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
    }
    .linha { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin-bottom:8px; }
    .linha:last-child { margin-bottom:0; }
    label { font-weight:bold; font-size:13px; }
    select, input[type=range], button {
        padding:6px 8px; border-radius:4px; border:1px solid #ccc; font-size:14px;
    }
    input[type=range] { padding:0; width: 180px; }
    button { cursor:pointer; border:none; }
    .btn-consultar { background:#0069d9; color:#fff; padding:8px 16px; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none;
                  padding:8px 14px; border-radius:4px; display:inline-block; font-size:14px; }
    .btn-limpar:hover { background:#5a6268; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso.azul { background:#e7f3ff; border-left-color:#0069d9; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:12px 20px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:170px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:22px; font-weight:bold; color:#0069d9; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }

    .grid { display:grid; grid-template-columns: 1fr 340px; gap: 20px; }
    @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }

    #mapa {
        height: 620px; width: 100%; border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,.15); background:#e5e5e5;
    }

    .painel-lateral {
        background:#fff; border-radius:8px; padding:14px;
        box-shadow: 0 1px 3px rgba(0,0,0,.1);
        max-height: 620px; overflow-y: auto;
    }
    .painel-lateral h3 {
        margin: 0 0 10px; font-size: 15px; color:#0069d9;
        border-bottom: 1px solid #eee; padding-bottom: 8px;
    }
    .linha-zona {
        display: flex; align-items: center; gap: 8px;
        padding: 6px 4px; border-bottom: 1px solid #f0f0f0;
        font-size: 13px; cursor: pointer; border-radius: 4px;
        transition: background .12s;
    }
    .linha-zona:hover { background:#f4f8ff; }
    .linha-zona .zona { font-weight:bold; color:#0069d9; width: 46px; }
    .linha-zona .nome { flex: 1; color:#555; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .linha-zona .votos { font-weight:bold; color:#333; }
    .linha-zona .sem-coord { color:#b94a48; font-style:italic; font-size:12px; }
    .linha-zona .mun { font-size:11px; color:#888; display:block; }

    .controle-heat {
        background:#fff; padding: 8px 12px; border-radius:6px;
        box-shadow: 0 1px 3px rgba(0,0,0,.2); font-size: 13px;
        display: flex; align-items: center; gap: 8px;
    }
    .controle-heat input { width: 100px; }

    .legenda-heat {
        background: rgba(255,255,255,.9); padding: 6px 10px; border-radius:6px;
        box-shadow: 0 1px 3px rgba(0,0,0,.2); font-size: 12px;
    }
    .legenda-heat .gradiente {
        width: 140px; height: 10px; border-radius: 4px;
        background: linear-gradient(to right,
            #0000ff, #00ffff, #00ff00, #ffff00, #ff0000);
        margin: 4px 0;
    }
    .legenda-heat .extremos { display:flex; justify-content:space-between; color:#555; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Mapa de Calor — Votos</h1>

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
    Cada ponto é uma zona eleitoral. A intensidade da cor é proporcional
    ao total de votos da zona (respeitando os filtros de ano/candidato).
</div>

<form class="filtro" method="get" action="mapa_calor.php">
    <div class="linha">
        <label for="municipio">Município:</label>
        <select name="municipio" id="municipio">
            <option value="">Todos os municípios</option>
            <?php foreach ($municipios as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>"
                    <?= $m === $municipio ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m) ?>
                </option>
            <?php endforeach; ?>
        </select>

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
    </div>

    <div class="linha">
        <label for="raio">Intensidade:</label>
        <input type="range" name="raio" id="raio" min="5" max="80" step="1"
               value="<?= (int)$raio ?>"
               oninput="document.getElementById('raioVal').textContent = this.value;">
        <span id="raioVal" style="font-family:monospace;"><?= (int)$raio ?></span>

        <label for="opac" style="margin-left:14px;">Opacidade:</label>
        <input type="range" name="opac" id="opac" min="0.1" max="1" step="0.05"
               value="<?= htmlspecialchars($opacidade) ?>"
               oninput="document.getElementById('opacVal').textContent =
                        parseFloat(this.value).toFixed(2);">
        <span id="opacVal" style="font-family:monospace;"><?= htmlspecialchars(number_format($opacidade, 2)) ?></span>

        <button type="submit" class="btn-consultar" style="margin-left:14px;">Aplicar</button>
        <a href="mapa_calor.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<?php if (!$pontos): ?>

    <div class="aviso">
        Nenhuma zona com coordenada encontrada para os filtros selecionados.
        Rode o script de geocodificação ou preencha as coordenadas em
        <a href="editar_zonas.php">editar_zonas.php</a>.
    </div>

<?php else: ?>

    <div class="kpis">
        <div class="kpi <?= $municipio === '' ? 'vermelho' : '' ?>">
            <div class="rotulo"><?= $municipio === '' ? 'Abrangência' : 'Município' ?></div>
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
            <div class="valor"><?= number_format($totalVotos, 0, ',', '.') ?></div>
        </div>
        <div class="kpi roxo">
            <div class="rotulo">Zonas no mapa</div>
            <div class="valor"><?= number_format(count($pontos), 0, ',', '.') ?></div>
        </div>
        <div class="kpi laranja">
            <div class="rotulo">Maior zona</div>
            <div class="valor"><?= number_format($maxVotos, 0, ',', '.') ?></div>
        </div>
        <?php if ($municipio === ''): ?>
            <div class="kpi">
                <div class="rotulo">Municípios</div>
                <div class="valor"><?= number_format($qtdMunicipios, 0, ',', '.') ?></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($totalComCoord < $totalVotos): ?>
        <div class="aviso">
            <?= number_format($totalVotos - $totalComCoord, 0, ',', '.') ?>
            voto(s) estão em zonas sem coordenada e não aparecem no mapa.
        </div>
    <?php endif; ?>

    <div class="grid">
        <div id="mapa"></div>

        <div class="painel-lateral">
            <h3>Zonas (<?= count($zonas) ?>)</h3>
            <?php
            // Ordena por votos desc para a lista
            $zonasOrdenadas = $zonas;
            usort($zonasOrdenadas, fn($a, $b) => $b['total'] <=> $a['total']);
            foreach ($zonasOrdenadas as $z):
                $temCoord = $z['lat'] !== null && $z['lng'] !== null;
            ?>
                <div class="linha-zona"
                     data-zona="<?= (int)$z['zona'] ?>"
                     <?= $temCoord ? 'data-lat="' . $z['lat'] . '" data-lng="' . $z['lng'] . '"' : '' ?>>
                    <span class="zona"><?= htmlspecialchars($z['zona']) ?></span>
                    <span class="nome">
                        <?= htmlspecialchars($z['endereco'] ?: ($z['bairro'] ?: '—')) ?>
                        <?php if ($municipio === '' && !empty($z['municipio'])): ?>
                            <span class="mun"><?= htmlspecialchars($z['municipio']) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if ($temCoord): ?>
                        <span class="votos"><?= number_format($z['total'], 0, ',', '.') ?></span>
                    <?php else: ?>
                        <span class="sem-coord">sem coord.</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        // ------------------------------------------------------------
        // Dados
        // ------------------------------------------------------------
        const PONTOS = <?= json_encode($pontos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const MAX_VOTOS = <?= (int)$maxVotos ?>;
        const RAIO = <?= (int)$raio ?>;
        const OPACIDADE = <?= json_encode((float)$opacidade) ?>;
        const MUNICIPIO = <?= json_encode($municipio, JSON_UNESCAPED_UNICODE) ?>;
        const MOSTRAR_MUNICIPIO = <?= $municipio === '' ? 'true' : 'false' ?>;

        // ------------------------------------------------------------
        // Mapa base
        // ------------------------------------------------------------
        const mapa = L.map('mapa', { scrollWheelZoom: true })
                       .setView([-8.0, -38.0], 7);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(mapa);

        // ------------------------------------------------------------
        // Heatmap
        // ------------------------------------------------------------
        const heatData = PONTOS.map(p => {
            const peso = MAX_VOTOS > 0 ? (p.total / MAX_VOTOS) : 0;
            return [p.lat, p.lng, peso];
        });

        const heatLayer = L.heatLayer(heatData, {
            radius: RAIO,
            blur: Math.round(RAIO * 0.8),
            maxZoom: 17,
            minOpacity: 0.25,
            opacity: OPACIDADE,
            gradient: {
                0.0: '#0000ff',
                0.25: '#00ffff',
                0.5: '#00ff00',
                0.75: '#ffff00',
                1.0: '#ff0000'
            }
        }).addTo(mapa);

        // ------------------------------------------------------------
        // Marcadores clicáveis
        // ------------------------------------------------------------
        const bounds = L.latLngBounds([]);

        PONTOS.forEach(p => {
            bounds.extend([p.lat, p.lng]);

            const raioPx = 6 + 14 * Math.sqrt(MAX_VOTOS > 0 ? p.total / MAX_VOTOS : 0);

            const partes = [];
            partes.push('<div><b>Zona ' + p.zona + '</b></div>');
            if (MOSTRAR_MUNICIPIO && p.municipio) {
                partes.push('<div style="font-size:12px;color:#0069d9;font-weight:bold;">'
                            + escapeHtml(p.municipio) + '</div>');
            }
            if (p.endereco) {
                partes.push('<div style="font-size:12px;color:#555;margin-top:3px;">'
                            + escapeHtml(p.endereco) + '</div>');
            }
            if (p.bairro) {
                partes.push('<div style="font-size:12px;color:#888;">'
                            + escapeHtml(p.bairro) + '</div>');
            }
            partes.push(
                '<div style="margin-top:6px;">' +
                '<b>' + formatNum(p.total) + '</b> votos ' +
                '<span style="color:#888;">(' +
                ((p.total / (MAX_VOTOS || 1)) * 100).toFixed(1) +
                '% do maior)</span></div>'
            );

            L.circleMarker([p.lat, p.lng], {
                radius: raioPx,
                color: '#003a7a',
                weight: 1,
                fillColor: '#0069d9',
                fillOpacity: 0.15
            })
            .bindPopup(partes.join(''))
            .bindTooltip('Zona ' + p.zona + ' — ' + formatNum(p.total) + ' votos', { sticky: true })
            .addTo(mapa);
        });

        if (bounds.isValid()) {
            mapa.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 });
        }

        // ------------------------------------------------------------
        // Legenda
        // ------------------------------------------------------------
        const legenda = L.control({ position: 'bottomright' });
        legenda.onAdd = function () {
            const div = L.DomUtil.create('div', 'legenda-heat');
            div.innerHTML = `
                <div style="font-weight:bold;color:#0069d9;">Intensidade</div>
                <div class="gradiente"></div>
                <div class="extremos">
                    <span>menos votos</span>
                    <span>mais votos</span>
                </div>`;
            return div;
        };
        legenda.addTo(mapa);

        // ------------------------------------------------------------
        // Controle ao vivo
        // ------------------------------------------------------------
        const controle = L.control({ position: 'topright' });
        controle.onAdd = function () {
            const div = L.DomUtil.create('div', 'controle-heat');
            div.innerHTML = `
                <label style="font-weight:bold;">Raio</label>
                <input type="range" id="liveRaio" min="5" max="80" value="${RAIO}">
                <span id="liveRaioVal" style="font-family:monospace;">${RAIO}</span>
                <label style="font-weight:bold;margin-left:6px;">Opac.</label>
                <input type="range" id="liveOpac" min="0.1" max="1" step="0.05" value="${OPACIDADE}">
                <span id="liveOpacVal" style="font-family:monospace;">${OPACIDADE.toFixed(2)}</span>`;
            L.DomEvent.disableClickPropagation(div);
            setTimeout(() => {
                div.querySelector('#liveRaio').addEventListener('input', e => {
                    const r = parseInt(e.target.value, 10);
                    div.querySelector('#liveRaioVal').textContent = r;
                    heatLayer.setOptions({ radius: r, blur: Math.round(r * 0.8) });
                });
                div.querySelector('#liveOpac').addEventListener('input', e => {
                    const o = parseFloat(e.target.value);
                    div.querySelector('#liveOpacVal').textContent = o.toFixed(2);
                    heatLayer.setOptions({ opacity: o });
                });
            }, 0);
            return div;
        };
        controle.addTo(mapa);

        // ------------------------------------------------------------
        // Clique na lista lateral
        // ------------------------------------------------------------
        document.querySelectorAll('.linha-zona[data-lat]').forEach(el => {
            el.addEventListener('click', () => {
                const lat = parseFloat(el.dataset.lat);
                const lng = parseFloat(el.dataset.lng);
                mapa.setView([lat, lng], 15, { animate: true });
                mapa.eachLayer(layer => {
                    if (layer.getLatLng) {
                        const ll = layer.getLatLng();
                        if (Math.abs(ll.lat - lat) < 1e-6 && Math.abs(ll.lng - lng) < 1e-6) {
                            layer.openPopup();
                        }
                    }
                });
                document.getElementById('mapa')
                    .scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        });

        // ------------------------------------------------------------
        // Helpers
        // ------------------------------------------------------------
        function formatNum(n) { return Number(n).toLocaleString('pt-BR'); }
        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            })[c]);
        }
    </script>

<?php endif; ?>

</body>
</html>