<?php
// ------------------------------------------------------------
// Configuração do banco
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';

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
// Total geral do banco (para calcular %)
// ------------------------------------------------------------
$totalGeral = (int)$pdo->query(
    "SELECT COALESCE(SUM(qtdVotos),0) FROM votos"
)->fetchColumn();

// ------------------------------------------------------------
// Total por região
// COLLATE explícito para evitar erro 1267 (collation mismatch)
// ------------------------------------------------------------
$sqlRegioes = "
    SELECT
        r.id,
        r.codigo,
        r.nome                AS regiao,
        COUNT(DISTINCT v.nomeMunicipio) AS qtd_municipios,
        SUM(v.qtdVotos)       AS total
    FROM votos v
    JOIN municipio_regiao mr
      ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
       = v.nomeMunicipio  COLLATE utf8mb4_general_ci
    JOIN regioes r
      ON r.id = mr.regiao_id
    GROUP BY r.id, r.codigo, r.nome
    ORDER BY total DESC
";
$regioes = $pdo->query($sqlRegioes)->fetchAll();

// ------------------------------------------------------------
// Total por município dentro de cada região (para detalhamento)
// ------------------------------------------------------------
$sqlMunicipios = "
    SELECT
        r.id AS regiao_id,
        v.nomeMunicipio,
        SUM(v.qtdVotos) AS total
    FROM votos v
    JOIN municipio_regiao mr
      ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
       = v.nomeMunicipio  COLLATE utf8mb4_general_ci
    JOIN regioes r
      ON r.id = mr.regiao_id
    GROUP BY r.id, v.nomeMunicipio
    ORDER BY r.id, total DESC
";
$porRegiao = [];
foreach ($pdo->query($sqlMunicipios)->fetchAll() as $row) {
    $porRegiao[$row['regiao_id']][] = $row;
}

// ------------------------------------------------------------
// Coordenadas por região (vindas de secoes_enderecos)
// Para desenhar os polígonos (convex hull).
// ------------------------------------------------------------
$pontosPorRegiao = [];
$sqlPontos = "
    SELECT
        r.id AS regiao_id,
        s.latitude,
        s.longitude
    FROM secoes_enderecos s
    JOIN municipio_regiao mr
      ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
       = s.nomeMunicipio  COLLATE utf8mb4_general_ci
    JOIN regioes r
      ON r.id = mr.regiao_id
    WHERE s.latitude  IS NOT NULL
      AND s.longitude IS NOT NULL
      AND NOT (s.latitude = 0 AND s.longitude = 0)
";
foreach ($pdo->query($sqlPontos)->fetchAll() as $row) {
    $pontosPorRegiao[$row['regiao_id']][] = [
        'lat' => (float)$row['latitude'],
        'lng' => (float)$row['longitude'],
    ];
}

// ------------------------------------------------------------
// Municípios sem região (para alerta)
// ------------------------------------------------------------
$semRegiao = $pdo->query("
    SELECT DISTINCT v.nomeMunicipio
    FROM votos v
    LEFT JOIN municipio_regiao mr
      ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
       = v.nomeMunicipio  COLLATE utf8mb4_general_ci
    WHERE mr.id IS NULL
    ORDER BY v.nomeMunicipio
")->fetchAll(PDO::FETCH_COLUMN);

// ------------------------------------------------------------
// Total dos municípios sem região (para contabilizar no rodapé)
// ------------------------------------------------------------
$totalSemRegiao = 0;
if ($semRegiao) {
    $in = implode(',', array_fill(0, count($semRegiao), '?'));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(qtdVotos),0) FROM votos WHERE nomeMunicipio IN ($in)");
    $stmt->execute($semRegiao);
    $totalSemRegiao = (int)$stmt->fetchColumn();
}

// ------------------------------------------------------------
// Monta os dados que vão para o JS (mapa)
// ------------------------------------------------------------
$dadosMapa = [];
foreach ($regioes as $r) {
    $pts = $pontosPorRegiao[$r['id']] ?? [];
    if (count($pts) < 3) continue; // não dá para montar polígono
    $dadosMapa[] = [
        'id'         => (int)$r['id'],
        'nome'       => $r['regiao'],
        'codigo'     => $r['codigo'],
        'total'      => (int)$r['total'],
        'municipios' => (int)$r['qtd_municipios'],
        'pontos'     => $pts,
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Total de Votos por Região</title>

<!-- Leaflet -->
<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    h2 { margin-top:30px; }

    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:200px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#0069d9; margin-top:4px; }
    .kpi.verde   { border-left-color:#28a745; }
    .kpi.verde .valor { color:#28a745; }
    .kpi.cinza   { border-left-color:#6c757d; }
    .kpi.cinza .valor { color:#6c757d; }
    .kpi.laranja { border-left-color:#fd7e14; }
    .kpi.laranja .valor { color:#fd7e14; }

    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; }
    th { background:#0069d9; color:#fff; }
    tr:nth-child(even) { background:#f2f2f2; }
    tfoot td { font-weight:bold; background:#e6f0fa; }
    .num { text-align:right; }
    .barra {
        background:#e6f0fa; border-radius:4px; height:12px; overflow:hidden;
        display:inline-block; width:120px; vertical-align:middle; margin-left:8px;
    }
    .barra > span { display:block; height:100%; background:#0069d9; }
    details { background:#fff; border-radius:6px; padding:10px 15px; margin-bottom:10px;
              box-shadow:0 1px 2px rgba(0,0,0,.06); }
    summary { cursor:pointer; font-weight:bold; color:#0069d9; padding:4px 0; }
    summary:hover { text-decoration:underline; }
    .alerta {
        background:#fff3cd; border-left:5px solid #ffc107;
        padding:12px 16px; border-radius:6px; margin-bottom:20px;
    }
    .alerta strong { color:#856404; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    /* Mapa */
    #mapa { height: 620px; width: 100%; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.15); background:#e5e5e5;
            margin-bottom: 8px; }
    .mapa-info { font-size:13px; color:#555; margin: 6px 0 14px; }

    /* Legenda do mapa */
    .legenda-mapa {
        background:#fff; padding:10px 14px; border-radius:6px;
        box-shadow:0 1px 2px rgba(0,0,0,.08); font-size:13px;
        display:flex; flex-wrap:wrap; gap:14px; margin-bottom: 20px;
    }
    .legenda-mapa .item { display:flex; align-items:center; gap:6px; }
    .legenda-mapa .cor  { width:14px; height:14px; border-radius:3px;
                          display:inline-block; border:1px solid rgba(0,0,0,.15); }

    /* Label permanente no polígono */
    .regiao-label {
        background: transparent !important;
        border: none !important;
        pointer-events: none;
    }
    .regiao-label .caixa {
        background: rgba(255,255,255,.94);
        border: 2px solid #333;
        border-radius: 6px;
        padding: 4px 8px;
        font-family: Arial, sans-serif;
        font-size: 12px;
        font-weight: bold;
        color: #222;
        text-align: center;
        box-shadow: 0 2px 6px rgba(0,0,0,.25);
        white-space: nowrap;
        line-height: 1.25;
    }
    .regiao-label .caixa .votos {
        display:block; font-weight: normal; font-size: 11px; color:#444;
    }

    .leaflet-popup-content { font-family: Arial, sans-serif; font-size: 13px; }
    .leaflet-popup-content b { color:#0069d9; }

    /* Gráfico de pizza */
    .grafico-wrap {
        background:#fff; border-radius:8px; padding:20px;
        box-shadow: 0 1px 3px rgba(0,0,0,.1); margin-bottom: 20px;
    }
    .grafico-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        align-items: start;
    }
    @media (max-width: 900px) {
        .grafico-grid { grid-template-columns: 1fr; }
    }
    .grafico-box {
        position: relative;
        height: 460px;
    }
    .grafico-titulo {
        font-size: 14px; font-weight: bold; color:#0069d9;
        margin-bottom: 8px; text-align:center;
    }
    .grafico-tabela table { margin: 0; }
    .grafico-tabela th { background:#0069d9; color:#fff; }
    .grafico-tabela .cor-cel {
        display:inline-block; width:12px; height:12px; border-radius:3px;
        margin-right:6px; vertical-align:middle;
        border: 1px solid rgba(0,0,0,.15);
    }
</style>
</head>
<body>

<a href="consultar.php" class="link-voltar">&larr; Voltar para a consulta</a>

<h1>Total de Votos por Região</h1>

<!-- KPIs -->
<div class="kpis">
    <div class="kpi">
        <div class="rotulo">Total geral</div>
        <div class="valor"><?= number_format($totalGeral, 0, ',', '.') ?></div>
    </div>
    <div class="kpi verde">
        <div class="rotulo">Regiões com dados</div>
        <div class="valor"><?= number_format(count($regioes), 0, ',', '.') ?></div>
    </div>
    <?php if ($totalSemRegiao > 0): ?>
    <div class="kpi laranja">
        <div class="rotulo">Sem região</div>
        <div class="valor"><?= number_format($totalSemRegiao, 0, ',', '.') ?></div>
    </div>
    <?php endif; ?>
</div>

<?php if ($semRegiao): ?>
    <div class="alerta">
        <strong>Atenção:</strong> os municípios abaixo estão na tabela <code>votos</code>
        mas não estão associados a nenhuma região em <code>municipio_regiao</code>:
        <ul>
            <?php foreach ($semRegiao as $m): ?>
                <li><?= htmlspecialchars($m) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- MAPA DE POLÍGONOS POR REGIÃO                                  -->
<!-- ============================================================ -->
<h2>Mapa das regiões</h2>

<?php if (!$dadosMapa): ?>
    <div class="alerta">
        Nenhuma região tem coordenadas suficientes (≥ 3 pontos) em
        <code>secoes_enderecos</code> para desenhar um polígono.
        Confirme se a tabela <code>secoes_enderecos</code> está preenchida
        com <code>latitude</code> e <code>longitude</code>.
    </div>
<?php else: ?>
    <div class="mapa-info">
        Cada polígono é o <strong>fecho convexo</strong> das seções eleitorais
        da região. Passe o mouse ou clique para ver detalhes.
    </div>

    <div class="legenda-mapa" id="legenda-mapa"></div>

    <div id="mapa"></div>

    <script>
        const REGIOES = <?= json_encode($dadosMapa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <script>
        (function () {
            const CORES = [
                '#e6194B', '#3cb44b', '#4363d8', '#f58231', '#911eb4',
                '#42d4f4', '#f032e6', '#bfef45', '#fabed4', '#469990',
                '#dcbeff', '#9A6324', '#800000', '#aaffc3', '#808000',
                '#ffd8b1', '#000075', '#00aaff', '#a9a9a9', '#ff7f00',
            ];

            function formatNum(n) { return Number(n).toLocaleString('pt-BR'); }
            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, c => ({
                    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
                })[c]);
            }
            function cross(O, A, B) {
                return (A.lat - O.lat) * (B.lng - O.lng) - (A.lng - O.lng) * (B.lat - O.lat);
            }
            function convexHull(points) {
                if (points.length < 3) return points.slice();
                const pts = points.slice().sort((a, b) => a.lat - b.lat || a.lng - b.lng);
                const lower = [];
                for (const p of pts) {
                    while (lower.length >= 2 &&
                           cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) {
                        lower.pop();
                    }
                    lower.push(p);
                }
                const upper = [];
                for (let i = pts.length - 1; i >= 0; i--) {
                    const p = pts[i];
                    while (upper.length >= 2 &&
                           cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) {
                        upper.pop();
                    }
                    upper.push(p);
                }
                lower.pop(); upper.pop();
                return lower.concat(upper);
            }
            function centroide(points) {
                let lat = 0, lng = 0;
                for (const p of points) { lat += p.lat; lng += p.lng; }
                return { lat: lat / points.length, lng: lng / points.length };
            }

            const mapa = L.map('mapa', { scrollWheelZoom: true })
                           .setView([-8.0, -38.0], 7);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(mapa);

            const bounds = L.latLngBounds([]);
            const legenda = document.getElementById('legenda-mapa');

            REGIOES.forEach((reg, idx) => {
                const cor = CORES[idx % CORES.length];

                const hull = convexHull(reg.pontos);
                const latlngs = hull.map(p => [p.lat, p.lng]);

                const poligono = L.polygon(latlngs, {
                    color: cor, weight: 2, opacity: 0.9,
                    fillColor: cor, fillOpacity: 0.25,
                }).addTo(mapa);

                poligono.bindPopup(`
                    <div style="min-width:200px;">
                        <div style="font-weight:bold;color:${cor};font-size:14px;">
                            ${escapeHtml(reg.nome)}
                        </div>
                        ${reg.codigo ? '<div style="font-size:12px;color:#888;">Código: ' +
                                        escapeHtml(reg.codigo) + '</div>' : ''}
                        <hr style="border:none;border-top:1px solid #eee;margin:6px 0;">
                        <div><b>${formatNum(reg.total)}</b> votos</div>
                        <div style="font-size:12px;color:#555;">
                            ${formatNum(reg.municipios)} município(s)
                        </div>
                        <div style="font-size:12px;color:#888;margin-top:4px;">
                            ${reg.pontos.length} seção(ões) usadas no polígono
                        </div>
                    </div>
                `);
                poligono.bindTooltip(
                    reg.nome + ' — ' + formatNum(reg.total) + ' votos',
                    { sticky: true }
                );

                const c = centroide(hull);
                const labelIcon = L.divIcon({
                    className: 'regiao-label',
                    html: `<div class="caixa" style="border-color:${cor};">
                              ${escapeHtml(reg.nome)}
                              <span class="votos">${formatNum(reg.total)} votos</span>
                           </div>`,
                    iconSize: [140, 40],
                    iconAnchor: [70, 20],
                });
                L.marker([c.lat, c.lng], {
                    icon: labelIcon, interactive: false, keyboard: false,
                }).addTo(mapa);

                hull.forEach(p => bounds.extend([p.lat, p.lng]));

                const item = document.createElement('span');
                item.className = 'item';
                item.innerHTML = `<span class="cor" style="background:${cor};"></span>
                                  ${escapeHtml(reg.nome)} — ${formatNum(reg.total)}`;
                legenda.appendChild(item);
            });

            if (bounds.isValid()) {
                mapa.fitBounds(bounds, { padding: [30, 30] });
            }
        })();
    </script>
<?php endif; ?>

<!-- ============================================================ -->
<!-- GRÁFICO DE PIZZA POR REGIÃO                                   -->
<!-- ============================================================ -->
<h2>Distribuição dos votos por região</h2>

<?php if (!$regioes): ?>
    <div class="alerta">
        Nenhuma região com votos encontrada para gerar o gráfico.
    </div>
<?php else: ?>
    <div class="grafico-wrap">
        <div class="grafico-grid">
            <!-- Pizza -->
            <div>
                <div class="grafico-titulo">Votos por região (%)</div>
                <div class="grafico-box">
                    <canvas id="grafico-pizza"></canvas>
                </div>
            </div>

            <!-- Tabela lateral com % para leitura fina -->
            <div class="grafico-tabela">
                <div class="grafico-titulo">Detalhamento</div>
                <table>
                    <thead>
                        <tr>
                            <th>Região</th>
                            <th class="num">Votos</th>
                            <th class="num">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regioes as $r):
                            $pct = $totalGeral > 0 ? ($r['total'] / $totalGeral) * 100 : 0;
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($r['regiao']) ?></td>
                                <td class="num"><?= number_format((int)$r['total'], 0, ',', '.') ?></td>
                                <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="num"><?= number_format($totalGeral, 0, ',', '.') ?></td>
                            <td class="num">100,00%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <script>
        const DADOS_PIZZA = <?= json_encode(array_map(function ($r) {
            return [
                'nome'  => $r['regiao'],
                'total' => (int)$r['total'],
            ];
        }, $regioes), JSON_UNESCAPED_UNICODE) ?>;

        (function () {
            const CORES = [
                '#e6194B', '#3cb44b', '#4363d8', '#f58231', '#911eb4',
                '#42d4f4', '#f032e6', '#bfef45', '#fabed4', '#469990',
                '#dcbeff', '#9A6324', '#800000', '#aaffc3', '#808000',
                '#ffd8b1', '#000075', '#00aaff', '#a9a9a9', '#ff7f00',
            ];

            if (typeof Chart === 'undefined') return;
            if (!DADOS_PIZZA.length) return;

            const ctx = document.getElementById('grafico-pizza').getContext('2d');

            const labels = DADOS_PIZZA.map(d => d.nome);
            const valores = DADOS_PIZZA.map(d => d.total);
            const cores = DADOS_PIZZA.map((_, i) => CORES[i % CORES.length]);
            const total = valores.reduce((a, b) => a + b, 0);

            function formatNum(n) { return Number(n).toLocaleString('pt-BR'); }

            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: valores,
                        backgroundColor: cores,
                        borderColor: '#fff',
                        borderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 14,
                                boxHeight: 14,
                                padding: 10,
                                font: { size: 12 },
                                generateLabels: function (chart) {
                                    const ds = chart.data.datasets[0];
                                    return chart.data.labels.map((label, i) => {
                                        const v = ds.data[i];
                                        const pct = total > 0 ? (v / total) * 100 : 0;
                                        return {
                                            text: label + ' (' + pct.toFixed(1) + '%)',
                                            fillStyle: ds.backgroundColor[i],
                                            strokeStyle: ds.backgroundColor[i],
                                            lineWidth: 0,
                                            hidden: false,
                                            index: i,
                                        };
                                    });
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    const v = ctx.parsed;
                                    const pct = total > 0 ? (v / total) * 100 : 0;
                                    return ' ' + ctx.label + ': ' +
                                           formatNum(v) + ' votos (' +
                                           pct.toFixed(2) + '%)';
                                }
                            }
                        }
                    }
                }
            });
        })();
    </script>
<?php endif; ?>

<!-- Tabela principal: total por região -->
<h2>Total por região</h2>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Região</th>
            <th class="num">Municípios</th>
            <th class="num">Votos</th>
            <th class="num">% do total</th>
            <th>Proporção</th>
        </tr>
    </thead>
    <tbody>
        <?php $pos = 1; foreach ($regioes as $r):
            $pct = $totalGeral > 0 ? ($r['total'] / $totalGeral) * 100 : 0;
            $largura = $totalGeral > 0 ? ($r['total'] / $regioes[0]['total']) * 100 : 0;
        ?>
            <tr>
                <td><?= $pos++ ?></td>
                <td><?= htmlspecialchars($r['regiao']) ?></td>
                <td class="num"><?= number_format($r['qtd_municipios'], 0, ',', '.') ?></td>
                <td class="num"><?= number_format((int)$r['total'], 0, ',', '.') ?></td>
                <td class="num"><?= number_format($pct, 2, ',', '.') ?>%</td>
                <td>
                    <span class="barra"><span style="width: <?= number_format($largura, 2, '.', '') ?>%"></span></span>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="2">Total</td>
            <td class="num">
                <?php
                    $somaMunicipios = 0;
                    foreach ($regioes as $r) $somaMunicipios += $r['qtd_municipios'];
                    echo number_format($somaMunicipios, 0, ',', '.');
                ?>
            </td>
            <td class="num"><?= number_format($totalGeral, 0, ',', '.') ?></td>
            <td class="num">100,00%</td>
            <td></td>
        </tr>
    </tfoot>
</table>

<!-- Detalhamento por município -->
<h2>Detalhamento por município</h2>

<?php foreach ($regioes as $r): ?>
    <details>
        <summary>
            <?= htmlspecialchars($r['regiao']) ?>
            — <?= number_format((int)$r['total'], 0, ',', '.') ?> votos
            em <?= number_format($r['qtd_municipios'], 0, ',', '.') ?> municípios
        </summary>
        <table>
            <thead>
                <tr>
                    <th>Município</th>
                    <th class="num">Votos</th>
                    <th class="num">% da região</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($porRegiao[$r['id']] ?? [] as $m):
                    $pctReg = $r['total'] > 0 ? ($m['total'] / $r['total']) * 100 : 0;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($m['nomeMunicipio']) ?></td>
                        <td class="num"><?= number_format((int)$m['total'], 0, ',', '.') ?></td>
                        <td class="num"><?= number_format($pctReg, 2, ',', '.') ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
<?php endforeach; ?>

</body>
</html>