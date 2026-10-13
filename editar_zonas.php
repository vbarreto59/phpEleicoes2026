<?php
// ------------------------------------------------------------
// Configuração do banco
// editar_zonas.php
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

// ------------------------------------------------------------
// Endpoint AJAX (salvar / geocodificar)
// ------------------------------------------------------------
if (($_GET['ajax'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        // Só admin pode gravar/geocodificar
        if (!$__ehAdmin) {
            throw new Exception('Sem permissão para executar esta ação.');
        }

        $acao = $_GET['ajax'];

        if ($acao === 'salvar') {
            $numeroZona = (int)($_POST['numeroZona'] ?? 0);
            if ($numeroZona <= 0) {
                throw new Exception('numeroZona inválido.');
            }

            $endereco = trim($_POST['endereco_tse'] ?? '');
            $bairro   = trim($_POST['bairro']       ?? '');
            $cep      = trim($_POST['cep']          ?? '');
            $latRaw   = trim($_POST['latitude']     ?? '');
            $lngRaw   = trim($_POST['longitude']    ?? '');

            $lat = $latRaw === '' ? null : (float)str_replace(',', '.', $latRaw);
            $lng = $lngRaw === '' ? null : (float)str_replace(',', '.', $lngRaw);

            if ($lat !== null && ($lat < -90  || $lat > 90))  throw new Exception('Latitude fora do intervalo (-90 a 90).');
            if ($lng !== null && ($lng < -180 || $lng > 180)) throw new Exception('Longitude fora do intervalo (-180 a 180).');

            // Garante que a linha existe
            $pdo->prepare("INSERT IGNORE INTO zonas_enderecos (numeroZona) VALUES (:z)")
                ->execute([':z' => $numeroZona]);

            $sql = "UPDATE zonas_enderecos
                    SET endereco_tse = :endereco,
                        bairro       = :bairro,
                        cep          = :cep,
                        latitude     = :lat,
                        longitude    = :lng";
            $params = [
                ':endereco' => $endereco !== '' ? $endereco : null,
                ':bairro'   => $bairro   !== '' ? $bairro   : null,
                ':cep'      => $cep      !== '' ? $cep      : null,
                ':lat'      => $lat,
                ':lng'      => $lng,
                ':z'        => $numeroZona,
            ];

            if ($lat !== null && $lng !== null) {
                $sql .= ", geocodificado_em = NOW()";
            }
            $sql .= " WHERE numeroZona = :z";

            $pdo->prepare($sql)->execute($params);

            echo json_encode(['ok' => true, 'msg' => 'Salvo com sucesso.']);
            exit;
        }

        if ($acao === 'geocodificar') {
            $numeroZona = (int)($_POST['numeroZona'] ?? 0);
            if ($numeroZona <= 0) throw new Exception('numeroZona inválido.');

            $endereco  = trim($_POST['endereco_tse'] ?? '');
            $bairro    = trim($_POST['bairro']       ?? '');
            $municipio = trim($_POST['municipio']    ?? '');
            $uf        = trim($_POST['uf']           ?? 'PE');
            $cep       = trim($_POST['cep']          ?? '');

            // Monta a query (mesma lógica do script Python)
            $partes = [];
            if ($endereco)  $partes[] = $endereco;
            if ($bairro)    $partes[] = $bairro;
            if ($municipio) $partes[] = $municipio;
            if ($uf)        $partes[] = $uf;
            if ($cep)       $partes[] = $cep;
            $partes[] = 'Brasil';
            $query = implode(', ', $partes);

            $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                'q' => $query,
                'format' => 'json',
                'limit' => 1,
                'countrycodes' => 'br',
            ]);

            $ctx = stream_context_create([
                'http' => [
                    'header'  => "User-Agent: eleicoes-pe-app/1.0 (contato@exemplo.com)\r\n",
                    'timeout' => 15,
                ],
            ]);

            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) {
                throw new Exception('Falha ao consultar o Nominatim.');
            }

            $dados = json_decode($resp, true);
            if (!is_array($dados) || !$dados) {
                echo json_encode(['ok' => false, 'msg' => 'Endereço não encontrado no OpenStreetMap.']);
                exit;
            }

            $lat = (float)$dados[0]['lat'];
            $lng = (float)$dados[0]['lon'];

            echo json_encode([
                'ok'    => true,
                'lat'   => $lat,
                'lng'   => $lng,
                'query' => $query,
                'msg'   => 'Encontrado.',
            ]);
            exit;
        }

        throw new Exception('Ação desconhecida.');

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        exit;
    }
}

// ------------------------------------------------------------
// Filtro
// ------------------------------------------------------------
$municipio = $_GET['municipio'] ?? '';

// Lista de municípios (da tabela votos)
$municipios = $pdo->query(
    "SELECT DISTINCT nomeMunicipio FROM votos ORDER BY nomeMunicipio"
)->fetchAll(PDO::FETCH_COLUMN);

// Zonas do município: junta votos (para saber quais zonas aparecem)
// com a tabela de endereços (LEFT JOIN)
$zonas = [];
if ($municipio !== '') {
    $sql = "SELECT
                v.numeroZona,
                SUM(v.qtdVotos) AS total,
                z.endereco_tse,
                z.bairro,
                z.cep,
                z.nomeMunicipio AS zona_municipio,
                z.uf            AS zona_uf,
                z.latitude,
                z.longitude
            FROM votos v
            LEFT JOIN zonas_enderecos z ON z.numeroZona = v.numeroZona
            WHERE v.nomeMunicipio = :m
            GROUP BY v.numeroZona, z.endereco_tse, z.bairro, z.cep,
                     z.nomeMunicipio, z.uf, z.latitude, z.longitude
            ORDER BY v.numeroZona";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':m' => $municipio]);
    $zonas = $stmt->fetchAll();
}

// Descobre a UF mais frequente do município (para pré-preencher o form)
$ufPadrao = 'PE';
if ($zonas) {
    foreach ($zonas as $z) {
        if (!empty($z['zona_uf'])) { $ufPadrao = $z['zona_uf']; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Editar Zonas</title>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    h2 { margin-top:24px; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    form.filtro { background:#fff; padding:15px; border-radius:8px;
                  box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
                  display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
    label { font-weight:bold; }
    select, button, input { padding:6px 8px; border-radius:4px; border:1px solid #ccc; font-size:14px; }
    button { cursor:pointer; border:none; }
    .btn-consultar { background:#0069d9; color:#fff; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none;
                  padding:6px 12px; border-radius:4px; display:inline-block; }
    .btn-limpar:hover { background:#5a6268; }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107;
             padding:12px 16px; border-radius:6px; margin-bottom:20px; }

    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:20px; }
    th, td { border:1px solid #ddd; padding:6px 8px; text-align:left; vertical-align:top; }
    th { background:#0069d9; color:#fff; font-size:13px; }
    tr:nth-child(even) { background:#f9f9f9; }
    td input { width:100%; box-sizing:border-box; font-size:13px; padding:4px 6px; }
    td input[readonly] { background:#eee; color:#666; }
    .num { text-align:right; }
    .coord input { font-family: monospace; }

    .acoes { display:flex; gap:6px; white-space:nowrap; }
    .btn-salvar { background:#28a745; color:#fff; padding:5px 10px; border-radius:4px; border:none; cursor:pointer; font-size:13px; }
    .btn-salvar:hover { background:#1e7e34; }
    .btn-geo { background:#6f42c1; color:#fff; padding:5px 10px; border-radius:4px; border:none; cursor:pointer; font-size:13px; }
    .btn-geo:hover { background:#59339b; }
    .btn-mapa { background:#0069d9; color:#fff; padding:5px 10px; border-radius:4px; border:none; cursor:pointer; font-size:13px; }
    .btn-mapa:hover { background:#0053b3; }
    .btn-salvar:disabled, .btn-geo:disabled, .btn-mapa:disabled { opacity:.6; cursor:not-allowed; }

    .status { font-size:12px; margin-left:6px; }
    .status.ok  { color:#28a745; }
    .status.err { color:#b94a48; }
    .status.load{ color:#6c757d; }

    .kpis { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .kpi { background:#fff; border-radius:8px; padding:10px 18px;
           box-shadow:0 1px 3px rgba(0,0,0,.1);
           border-left:5px solid #0069d9; min-width:150px; }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:22px; font-weight:bold; color:#0069d9; }
    .kpi.roxo    { border-left-color:#6f42c1; }
    .kpi.roxo .valor { color:#6f42c1; }

    /* Mapa lateral */
    #painel-mapa {
        position: fixed; top: 20px; right: 20px; width: 380px;
        background:#fff; border-radius:8px; padding:10px;
        box-shadow:0 4px 16px rgba(0,0,0,.25);
        display:none; z-index: 1000;
    }
    #painel-mapa.aberto { display:block; }
    #painel-mapa h3 { margin:0 0 6px; font-size:15px; color:#0069d9; }
    #painel-mapa .fechar { float:right; background:none; border:none;
                           font-size:18px; cursor:pointer; color:#666; }
    #mapa { height: 320px; width: 100%; border-radius:6px; }
    #painel-mapa .info { font-size:12px; color:#555; margin-top:6px; line-height:1.4; }
    #painel-mapa .coords { font-family: monospace; font-size:12px; color:#333; }

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

<a href="zonas.php" class="link-voltar">&larr; Voltar para zonas</a>

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
        <span class="badge-visitante">👁️ admin (somente leitura)</span>
    <?php else: ?>
        <span class="badge-visitante">👁️ visitante (somente leitura)</span>
    <?php endif; ?>
</div>

<h1>Editar Zonas Eleitorais</h1>

<form class="filtro" method="get" action="editar_zonas.php">
    <label for="municipio">Município:</label>
    <select name="municipio" id="municipio" required>
        <option value="">-- Selecione --</option>
        <?php foreach ($municipios as $m): ?>
            <option value="<?= htmlspecialchars($m) ?>"
                <?= $m === $municipio ? 'selected' : '' ?>>
                <?= htmlspecialchars($m) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-consultar">Filtrar</button>
    <a href="editar_zonas.php" class="btn-limpar">Limpar</a>
</form>

<?php if ($municipio === ''): ?>

    <div class="aviso">
        Selecione um <strong>município</strong> para listar e editar as zonas.
    </div>

<?php elseif (!$zonas): ?>

    <div class="aviso">
        Nenhuma zona encontrada para o município
        <strong><?= htmlspecialchars($municipio) ?></strong>.
    </div>

<?php else: ?>

    <?php
        $comCoord = 0;
        $semCoord = 0;
        foreach ($zonas as $z) {
            if (!empty($z['latitude']) && !empty($z['longitude'])) $comCoord++;
            else $semCoord++;
        }
    ?>

    <div class="kpis">
        <div class="kpi">
            <div class="rotulo">Município</div>
            <div class="valor" style="font-size:16px;"><?= htmlspecialchars($municipio) ?></div>
        </div>
        <div class="kpi">
            <div class="rotulo">Zonas</div>
            <div class="valor"><?= count($zonas) ?></div>
        </div>
        <div class="kpi">
            <div class="rotulo">Com coordenada</div>
            <div class="valor" style="color:#28a745;"><?= $comCoord ?></div>
        </div>
        <div class="kpi roxo">
            <div class="rotulo">Sem coordenada</div>
            <div class="valor"><?= $semCoord ?></div>
        </div>
    </div>

    <?php if ($__ehAdmin): ?>
        <div class="aviso" style="background:#e7f3ff;border-left-color:#0069d9;">
            Edite os campos e clique em <strong>Salvar</strong>.
            Use <strong>Geocodificar</strong> para buscar as coordenadas no OpenStreetMap,
            ou <strong>Mapa</strong> para ajustar o ponto arrastando o marcador.
        </div>
    <?php else: ?>
        <div class="aviso">
            Você está no modo <strong>somente leitura</strong> — os campos abaixo
            estão desabilitados para edição.
        </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th style="width:60px;">Zona</th>
                <th style="width:60px;" class="num">Votos</th>
                <th>Endereço (endereco_tse)</th>
                <th style="width:140px;">Bairro</th>
                <th style="width:110px;">CEP</th>
                <th style="width:120px;">Latitude</th>
                <th style="width:120px;">Longitude</th>
                <th style="width:230px;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($zonas as $z): ?>
                <tr data-zona="<?= (int)$z['numeroZona'] ?>">
                    <td><strong><?= htmlspecialchars($z['numeroZona']) ?></strong></td>
                    <td class="num"><?= number_format((int)$z['total'], 0, ',', '.') ?></td>
                    <td>
                        <input type="text"
                               name="endereco_tse"
                               value="<?= htmlspecialchars($z['endereco_tse'] ?? '') ?>"
                               placeholder="Ex.: Rua X, 123"
                               <?= $__ehAdmin ? '' : 'readonly' ?>>
                    </td>
                    <td>
                        <input type="text"
                               name="bairro"
                               value="<?= htmlspecialchars($z['bairro'] ?? '') ?>"
                               <?= $__ehAdmin ? '' : 'readonly' ?>>
                    </td>
                    <td>
                        <input type="text"
                               name="cep"
                               value="<?= htmlspecialchars($z['cep'] ?? '') ?>"
                               <?= $__ehAdmin ? '' : 'readonly' ?>>
                    </td>
                    <td class="coord">
                        <input type="text"
                               name="latitude"
                               value="<?= $z['latitude'] !== null ? htmlspecialchars(number_format((float)$z['latitude'], 6, '.', '')) : '' ?>"
                               placeholder="-7.123456"
                               <?= $__ehAdmin ? '' : 'readonly' ?>>
                    </td>
                    <td class="coord">
                        <input type="text"
                               name="longitude"
                               value="<?= $z['longitude'] !== null ? htmlspecialchars(number_format((float)$z['longitude'], 6, '.', '')) : '' ?>"
                               placeholder="-40.123456"
                               <?= $__ehAdmin ? '' : 'readonly' ?>>
                    </td>
                    <td>
                        <?php if ($__ehAdmin): ?>
                            <div class="acoes">
                                <button type="button" class="btn-salvar">Salvar</button>
                                <button type="button" class="btn-geo">Geocodificar</button>
                                <button type="button" class="btn-mapa"
                                        data-municipio="<?= htmlspecialchars($municipio) ?>"
                                        data-uf="<?= htmlspecialchars($ufPadrao) ?>">Mapa</button>
                            </div>
                            <div class="status"></div>
                        <?php else: ?>
                            <span style="color:#999;font-style:italic;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

<?php if ($__ehAdmin): ?>
<!-- Painel do mapa (oculto até clicar em "Mapa") -->
<div id="painel-mapa">
    <button class="fechar" title="Fechar">&times;</button>
    <h3>Zona <span id="pm-zona"></span></h3>
    <div id="mapa"></div>
    <div class="info">
        Arraste o marcador para ajustar a posição.
        <br>Coordenadas: <span class="coords" id="pm-coords">—</span>
        <br><button type="button" class="btn-salvar" id="pm-salvar" style="margin-top:6px;">Salvar do mapa</button>
    </div>
</div>
<?php endif; ?>

<?php if ($__ehAdmin): ?>
<script>
(function () {
    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------
    function setStatus(tr, msg, tipo) {
        const el = tr.querySelector('.status');
        if (!el) return;
        el.textContent = msg;
        el.className = 'status ' + (tipo || '');
    }

    // ------------------------------------------------------------
    // Salvar
    // ------------------------------------------------------------
    async function salvar(tr) {
        const zona = tr.dataset.zona;
        const fd = new FormData();
        fd.append('numeroZona', zona);
        fd.append('endereco_tse', tr.querySelector('[name=endereco_tse]').value);
        fd.append('bairro',       tr.querySelector('[name=bairro]').value);
        fd.append('cep',          tr.querySelector('[name=cep]').value);
        fd.append('latitude',     tr.querySelector('[name=latitude]').value);
        fd.append('longitude',    tr.querySelector('[name=longitude]').value);

        setStatus(tr, 'Salvando...', 'load');
        try {
            const r = await fetch('editar_zonas.php?ajax=salvar', { method:'POST', body: fd });
            const j = await r.json();
            if (j.ok) {
                setStatus(tr, '✔ Salvo', 'ok');
            } else {
                setStatus(tr, '✘ ' + j.msg, 'err');
            }
        } catch (e) {
            setStatus(tr, '✘ Erro de rede', 'err');
        }
        setTimeout(() => {
            const el = tr.querySelector('.status');
            if (el && el.classList.contains('ok')) el.textContent = '';
        }, 2500);
    }

    // ------------------------------------------------------------
    // Geocodificar
    // ------------------------------------------------------------
    async function geocodificar(tr) {
        const zona = tr.dataset.zona;
        const municipioEl = tr.querySelector('.btn-mapa');
        const fd = new FormData();
        fd.append('numeroZona', zona);
        fd.append('endereco_tse', tr.querySelector('[name=endereco_tse]').value);
        fd.append('bairro',       tr.querySelector('[name=bairro]').value);
        fd.append('cep',          tr.querySelector('[name=cep]').value);
        fd.append('municipio',    municipioEl.dataset.municipio || '');
        fd.append('uf',           municipioEl.dataset.uf || 'PE');

        setStatus(tr, 'Consultando...', 'load');
        try {
            const r = await fetch('editar_zonas.php?ajax=geocodificar', { method:'POST', body: fd });
            const j = await r.json();
            if (j.ok) {
                tr.querySelector('[name=latitude]').value  = (+j.lat).toFixed(6);
                tr.querySelector('[name=longitude]').value = (+j.lng).toFixed(6);
                setStatus(tr, '✔ Encontrado', 'ok');
            } else {
                setStatus(tr, '✘ ' + j.msg, 'err');
            }
        } catch (e) {
            setStatus(tr, '✘ Erro de rede', 'err');
        }
        setTimeout(() => {
            const el = tr.querySelector('.status');
            if (el && el.classList.contains('ok')) el.textContent = '';
        }, 3000);
    }

    // ------------------------------------------------------------
    // Mapa
    // ------------------------------------------------------------
    let mapa = null;
    let marcador = null;
    let trAtual = null;

    function abrirMapa(tr, lat, lng, titulo) {
        trAtual = tr;
        const painel = document.getElementById('painel-mapa');
        painel.classList.add('aberto');
        document.getElementById('pm-zona').textContent = titulo;

        // cria o mapa uma única vez
        if (!mapa) {
            mapa = L.map('mapa').setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(mapa);
        } else {
            mapa.setView([lat, lng], 15);
        }

        if (marcador) {
            mapa.removeLayer(marcador);
        }
        marcador = L.marker([lat, lng], { draggable: true }).addTo(mapa);

        const atualizar = () => {
            const p = marcador.getLatLng();
            const latF = p.lat.toFixed(6);
            const lngF = p.lng.toFixed(6);
            trAtual.querySelector('[name=latitude]').value  = latF;
            trAtual.querySelector('[name=longitude]').value = lngF;
            document.getElementById('pm-coords').textContent = latF + ', ' + lngF;
        };
        marcador.on('dragend', atualizar);

        document.getElementById('pm-coords').textContent =
            lat.toFixed(6) + ', ' + lng.toFixed(6);

        // Leaflet precisa recalcular o tamanho depois que o painel aparece
        setTimeout(() => mapa.invalidateSize(), 80);
    }

    document.querySelector('.fechar') // botão do painel
        .addEventListener('click', () => {
            document.getElementById('painel-mapa').classList.remove('aberto');
        });

    document.getElementById('pm-salvar')
        .addEventListener('click', () => {
            if (trAtual) salvar(trAtual);
        });

    // ------------------------------------------------------------
    // Delegação de eventos da tabela
    // ------------------------------------------------------------
    document.querySelector('table')?.addEventListener('click', (ev) => {
        const btn = ev.target.closest('button');
        if (!btn) return;
        const tr = btn.closest('tr');
        if (!tr) return;

        if (btn.classList.contains('btn-salvar')) {
            salvar(tr);
        } else if (btn.classList.contains('btn-geo')) {
            geocodificar(tr);
        } else if (btn.classList.contains('btn-mapa')) {
            const lat = parseFloat(tr.querySelector('[name=latitude]').value.replace(',', '.'));
            const lng = parseFloat(tr.querySelector('[name=longitude]').value.replace(',', '.'));
            if (isNaN(lat) || isNaN(lng)) {
                setStatus(tr, 'Informe ou geocodifique antes de abrir o mapa.', 'err');
                return;
            }
            abrirMapa(tr, lat, lng, tr.dataset.zona);
        }
    });

    // Enter dentro de um input = salvar aquela linha
    document.querySelector('table')?.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && ev.target.matches('input')) {
            ev.preventDefault();
            const tr = ev.target.closest('tr');
            if (tr) salvar(tr);
        }
    });
})();
</script>
<?php endif; ?>

</body>
</html>