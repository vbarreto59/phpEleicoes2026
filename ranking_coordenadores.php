<?php
// ------------------------------------------------------------
// ranking_coordenadores.php
// Ranking de coordenadores — esperado x urnas
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';
date_default_timezone_set('America/Sao_Paulo');

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER, $DB_PASS,
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

// ============================================================
// DETECÇÃO DE COLUNAS / TABELAS
// ============================================================

// ---------- coluna de votos em `votos` ----------
$colunasVotos = [];
foreach ($pdo->query("SHOW COLUMNS FROM votos") as $c) $colunasVotos[] = $c['Field'];

$candidatosVotos = [
    'votos','quantidade','total','qtde','qtd','totalVotos','total_votos',
    'num_votos','votosValidos','numVotos','votos_validos','comparecimento',
    'votosCandidato','qtdVotos',
];
$COL_VOTOS = null;
foreach ($candidatosVotos as $cand) {
    if (in_array($cand, $colunasVotos, true)) { $COL_VOTOS = $cand; break; }
}
if ($COL_VOTOS === null) {
    foreach ($pdo->query("SHOW COLUMNS FROM votos") as $c) {
        $field = $c['Field'];
        $type  = strtolower($c['Type']);
        if (in_array($field, ['numeroZona','numeroSecao','id'], true)) continue;
        if (str_contains($type,'int')||str_contains($type,'decimal')
            ||str_contains($type,'float')||str_contains($type,'double')) {
            $COL_VOTOS = $field; break;
        }
    }
}
if ($COL_VOTOS === null) die("Coluna de votos não detectada.");

$temTipoVotavel   = in_array('cdTipoVotavel',  $colunasVotos, true);
$temCargoPergunta = in_array('cargoPergunta', $colunasVotos, true);

// ---------- colunas de coordenadores ----------
$colunasCoord = [];
foreach ($pdo->query("SHOW COLUMNS FROM coordenadores") as $c) $colunasCoord[] = $c['Field'];
$temNumeroCandCoord = in_array('numero_candidato',  $colunasCoord, true);
$temAnoCampanha     = in_array('ano_campanha',      $colunasCoord, true);
$temExpectativaCoord= in_array('expectativa_votos', $colunasCoord, true);

// ---------- colunas de liderancas ----------
$colunasLider = [];
foreach ($pdo->query("SHOW COLUMNS FROM liderancas") as $c) $colunasLider[] = $c['Field'];
$temExpectativaLider = in_array('expectativa_votos', $colunasLider, true);

// ---------- tabelas opcionais ----------
$temLiderSecao = false;
$temCoordSecao = false;
try { $pdo->query("SELECT 1 FROM lider_secao LIMIT 1");       $temLiderSecao = true; } catch (Exception $e) {}
try { $pdo->query("SELECT 1 FROM coordenador_secao LIMIT 1"); $temCoordSecao = true; } catch (Exception $e) {}

// ============================================================
// FILTROS
// ============================================================
$filtroAno       = (int)($_GET['ano']       ?? 0);
$filtroCandidato = (int)($_GET['candidato'] ?? 0);
$filtroCargo     = trim($_GET['cargo'] ?? '');

$mostrarLista = ($filtroAno > 0 && $filtroCandidato > 0);

// ============================================================
// LISTAS PARA OS SELECTS
// ============================================================
$listaAnos = $pdo->query(
    "SELECT DISTINCT ano FROM votos WHERE ano > 0 ORDER BY ano DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$listaCandidatos = [];
if ($filtroAno > 0) {
    $stmt = $pdo->prepare(
        "SELECT numero, MIN(nomeCandidatoResp) AS nome, MIN(nomeCargoPerg) AS cargo
           FROM votos
          WHERE ano = :ano AND numero > 0 AND nomeCandidatoResp <> ''
          GROUP BY numero
          ORDER BY nome"
    );
    $stmt->execute([':ano' => $filtroAno]);
    $listaCandidatos = $stmt->fetchAll();
}

$listaCargos = $pdo->query(
    "SELECT DISTINCT nomeCargoPerg
       FROM votos
      WHERE nomeCargoPerg IS NOT NULL AND nomeCargoPerg <> ''
      ORDER BY nomeCargoPerg"
)->fetchAll(PDO::FETCH_COLUMN);

// ============================================================
// HELPERS
// ============================================================
function fmtPct(?float $p): string {
    return $p === null ? '—' : number_format($p, 1, ',', '.') . '%';
}
function fmtNum(int|float|null $n): string {
    return $n === null ? '—' : number_format((float)$n, 0, ',', '.');
}

// ============================================================
// QUERY PRINCIPAL
// ============================================================
$coordenadores = [];

if ($mostrarLista) {

    // ---------- filtro de votos do candidato ----------
    $filtroCandVotos = "
        AND v.numero = c.numero_candidato
        AND v.ano = c.ano_campanha
        AND v.nomeCandidatoResp <> ''
    ";
    if ($temTipoVotavel) {
        $filtroCandVotos .= " AND v.cdTipoVotavel = 1 ";
    }
    if ($temCargoPergunta) {
        $filtroCandVotos .= "
            AND v.cargoPergunta = (
                SELECT v2.cargoPergunta FROM votos v2
                 WHERE v2.numero = c.numero_candidato
                   AND v2.ano = c.ano_campanha
                   AND v2.nomeCandidatoResp <> ''
                 GROUP BY v2.cargoPergunta
                 ORDER BY SUM(v2.`$COL_VOTOS`) DESC
                 LIMIT 1
            )
        ";
    }
    // filtro de cargo extra (select)
    $condCargoExtra = '';
    $paramsExtra    = [];
    if ($filtroCargo !== '') {
        $condCargoExtra = " AND v.nomeCargoPerg = :cargoExtra ";
        $paramsExtra[':cargoExtra'] = $filtroCargo;
    }

    // ---------- esperado = expectativa do próprio coordenador ----------
    $cExpectExpr = $temExpectativaCoord ? "c.expectativa_votos" : "0";

    // ---------- urnas = soma das seções em coordenador_secao ----------
    if ($temCoordSecao) {
        $subUrnas = "(
            SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
              FROM votos v
             WHERE 1=1
               $filtroCandVotos
               $condCargoExtra
               AND (v.numeroZona, v.numeroSecao) IN (
                   SELECT cs.numeroZona, cs.numeroSecao
                     FROM coordenador_secao cs
                    WHERE cs.coordenador_id = c.id
               )
        ) AS urnas";
        $subSecoes = "(
            SELECT COUNT(*) FROM coordenador_secao cs
             WHERE cs.coordenador_id = c.id
        ) AS secoes_unicas";
        $subZonas = "(
            SELECT COUNT(DISTINCT cs.numeroZona)
              FROM coordenador_secao cs
             WHERE cs.coordenador_id = c.id
        ) AS zonas";
    } else {
        // fallback: usa lider_secao se coordenador_secao não existir
        $subUrnas = $temLiderSecao
            ? "(
                SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
                  FROM votos v
                 WHERE 1=1
                   $filtroCandVotos
                   $condCargoExtra
                   AND (v.numeroZona, v.numeroSecao) IN (
                       SELECT DISTINCT ls.numeroZona, ls.numeroSecao
                         FROM lider_secao ls
                         INNER JOIN liderancas l2 ON l2.id = ls.lider_id
                        WHERE l2.coordenador_id = c.id AND l2.ativo = 1
                   )
              ) AS urnas"
            : "0 AS urnas";
        $subSecoes = $temLiderSecao
            ? "(
                SELECT COUNT(DISTINCT CONCAT(ls.numeroZona,'|',ls.numeroSecao))
                  FROM lider_secao ls
                  INNER JOIN liderancas l2 ON l2.id = ls.lider_id
                 WHERE l2.coordenador_id = c.id AND l2.ativo = 1
              ) AS secoes_unicas"
            : "0 AS secoes_unicas";
        $subZonas = "0 AS zonas";
    }

    // ---------- candidato textual (a partir de votos) ----------
    $colCandSelect = "
        (SELECT MIN(v.nomeCandidatoResp) FROM votos v
          WHERE v.numero = c.numero_candidato AND v.ano = c.ano_campanha) AS candidato,
        (SELECT MIN(v.nomeCargoPerg) FROM votos v
          WHERE v.numero = c.numero_candidato AND v.ano = c.ano_campanha) AS candidato_cargo
    ";

    // ---------- where principal ----------
    $where  = ["c.ano_campanha = :ano", "c.numero_candidato = :num"];
    $params = [
        ':ano' => $filtroAno,
        ':num' => $filtroCandidato,
    ];
    $whereStr = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT
            c.id,
            c.nome AS coordenador,
            c.ativo,
            c.numero_candidato,
            c.ano_campanha,
            $cExpectExpr AS esperado,
            $subUrnas,
            $subSecoes,
            $subZonas,
            (SELECT COUNT(*) FROM liderancas l
              WHERE l.coordenador_id = c.id) AS liderancas_total,
            (SELECT COUNT(*) FROM liderancas l
              WHERE l.coordenador_id = c.id AND l.ativo = 1) AS liderancas_ativas,
            $colCandSelect
        FROM coordenadores c
        $whereStr
        ORDER BY urnas DESC, esperado DESC, c.nome
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $paramsExtra));
    $coordenadores = $stmt->fetchAll();
}

// ============================================================
// PÓS-PROCESSAMENTO
// ============================================================
foreach ($coordenadores as &$c) {
    $esp = (int)$c['esperado'];
    $urn = (int)$c['urnas'];
    $c['diferenca'] = $urn - $esp;
    $c['pct'] = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
}
unset($c);

// ============================================================
// TOTAIS / KPIs
// ============================================================
$totalCoords         = count($coordenadores);
$totalAtivos         = 0;
$totalLiderancas     = 0;
$totalLiderancasAtiv = 0;
$totalEsperado       = 0;
$totalUrnas          = 0;
$totalSecoes         = 0;
$qtdAcima100         = 0;
$melhorCoord         = null;

foreach ($coordenadores as $c) {
    if ((int)$c['ativo'] === 1) $totalAtivos++;
    $totalLiderancas     += (int)$c['liderancas_total'];
    $totalLiderancasAtiv += (int)$c['liderancas_ativas'];
    $totalEsperado       += (int)$c['esperado'];
    $totalUrnas          += (int)$c['urnas'];
    $totalSecoes         += (int)$c['secoes_unicas'];

    if ($c['pct'] !== null && $c['pct'] >= 100) $qtdAcima100++;

    if ($melhorCoord === null) $melhorCoord = $c;
}

$totalDiferenca = $totalUrnas - $totalEsperado;
$totalPct = $totalEsperado > 0
    ? round(($totalUrnas / $totalEsperado) * 100, 1)
    : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ranking de Coordenadores</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom:4px; }
    .subtitulo { color:#666; font-size:13px; margin-bottom:18px; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9;
                   text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

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

    /* Filtros */
    .filtros {
        background:#fff; padding:14px 18px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
        display:flex; flex-wrap:wrap; align-items:center; gap:12px;
        border-left:5px solid #0069d9;
    }
    .filtros label { font-weight:bold; font-size:13px; }
    .filtros select, .filtros button {
        padding:8px 10px; border-radius:4px; border:1px solid #ccc;
        font-size:14px; font-family:inherit;
    }
    .filtros select:focus { outline:none; border-color:#0069d9;
        box-shadow:0 0 0 2px rgba(0,105,217,.15); }
    .btn-consultar { background:#0069d9; color:#fff; border:none; cursor:pointer;
                     font-weight:bold; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none;
                  padding:8px 14px; border-radius:4px; font-size:14px; }
    .btn-limpar:hover { background:#5a6268; }

    /* KPIs */
    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:14px 22px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:170px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666;
                   letter-spacing:1px; }
    .kpi .valor  { font-size:26px; font-weight:bold; color:#0069d9; margin-top:2px; }
    .kpi .sub    { font-size:11px; color:#888; margin-top:2px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.teal    { border-left-color:#17a2b8; } .kpi.teal .valor  { color:#17a2b8; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi .valor.sm { font-size:16px; line-height:1.3; word-break:break-word; }

    /* Tabela */
    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left;
             vertical-align:middle; font-size:13px; }
    th { background:#0069d9; color:#fff; font-size:12px;
         text-transform:uppercase; letter-spacing:.5px; }
    th.num, td.num { text-align:right; }
    th.centro, td.centro { text-align:center; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#eef6ff; }
    tr.linha-total { background:#eef6ff; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #0069d9; }
    .posicao { font-weight:bold; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-zona    { background:#e6f0fa; color:#0069d9; }
    .badge-lider   { background:#f0e6ff; color:#6f42c1; }
    .badge-ativo   { background:#d4f5dd; color:#1c7a34; }
    .badge-inativo { background:#f0f0f0; color:#888; }
    .badge-ano     { background:#ede7f6; color:#4527a0; }
    .badge-cand    { background:#e7d9ff; color:#4a1d96; font-weight:bold; }
    .badge-votos   { background:#fff0e0; color:#c25b00; }
    .badge-urnas   { background:#d9edf7; color:#0c5460; }
    .badge-pos     { background:#d4f5dd; color:#1c7a34; }
    .badge-neg     { background:#fdecea; color:#a71d2a; }
    .badge-zero    { background:#f0f0f0; color:#666; }
    .badge-pct-ok  { background:#d4f5dd; color:#1c7a34; }
    .badge-pct-med { background:#fff3cd; color:#856404; }
    .badge-pct-ruim{ background:#fdecea; color:#a71d2a; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    .barra {
        background:#e6f0fa; border-radius:4px; height:10px; overflow:hidden;
        display:inline-block; width:110px; vertical-align:middle; margin-left:6px;
    }
    .barra > span { display:block; height:100%; }
    .barra > span.ok   { background:#28a745; }
    .barra > span.med  { background:#ffc107; }
    .barra > span.ruim { background:#dc3545; }

    .info-col {
        font-size:12px; color:#666; margin-bottom:14px;
        background:#fff; padding:8px 14px; border-radius:6px;
        border-left:4px solid #17a2b8;
        box-shadow: 0 1px 2px rgba(0,0,0,.05);
    }
    .info-col code { background:#f4f4f4; padding:1px 5px; border-radius:3px; }
    .info-col .aviso { color:#856404; background:#fff3cd;
        padding:2px 8px; border-radius:6px; font-weight:bold; margin-left:6px; }

    .msg-vazia {
        background:#fff; padding:30px; border-radius:8px;
        text-align:center; color:#666;
        box-shadow:0 1px 3px rgba(0,0,0,.08);
    }
    .msg-aviso {
        background:#fff3cd; border-left:5px solid #ffc107;
        padding:14px 18px; border-radius:6px; margin-bottom:20px;
        color:#856404; font-size:13px;
    }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

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
        <span class="badge-visitante">👁️ admin (não autorizado a excluir)</span>
    <?php else: ?>
        <span class="badge-visitante">👁️ visitante</span>
    <?php endif; ?>
</div>

<h1>Ranking de Coordenadores</h1>
<div class="subtitulo">
    Compara a <strong>expectativa de votos do coordenador</strong>
    com os <strong>votos reais nas urnas</strong> das seções sob sua coordenação
    (<code>coordenador_secao</code>).
</div>

<p class="info-col">
    Coluna de votos: <code><?= htmlspecialchars($COL_VOTOS) ?></code>
    &nbsp;·&nbsp;
    <code>coordenador_secao</code>:
    <?php if ($temCoordSecao): ?>
        <strong>existe</strong>
    <?php else: ?>
        <span class="aviso">não existe — usando lider_secao</span>
    <?php endif; ?>
    &nbsp;·&nbsp;
    <code>expectativa_votos</code> em <code>coordenadores</code>:
    <?php if ($temExpectativaCoord): ?>
        <strong>existe</strong>
    <?php else: ?>
        <span class="aviso">NÃO EXISTE — esperado = 0</span>
    <?php endif; ?>
    &nbsp;·&nbsp;
    Formato de seções: <strong>#Zzona-Ssecao</strong>
</p>

<!-- ============================================================
     FILTROS
     ============================================================ -->
<form method="get" action="ranking_coordenadores.php" class="filtros" id="form-filtros">

    <label for="ano">Ano:</label>
    <select name="ano" id="ano" style="min-width:120px;"
            onchange="mudarAno(this.form);">
        <option value="0">— Selecione —</option>
        <?php foreach ($listaAnos as $a): ?>
            <option value="<?= (int)$a ?>" <?= ((int)$a === $filtroAno) ? 'selected' : '' ?>>
                <?= (int)$a ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="candidato">Candidato:</label>
    <select name="candidato" id="candidato" style="min-width:280px;"
            onchange="mudarCandidato(this.form);">
        <option value="0">— Selecione —</option>
        <?php foreach ($listaCandidatos as $c): ?>
            <option value="<?= (int)$c['numero'] ?>"
                <?= ((int)$c['numero'] === $filtroCandidato) ? 'selected' : '' ?>>
                Nº <?= (int)$c['numero'] ?> — <?= htmlspecialchars($c['nome']) ?>
                <?= $c['cargo'] ? ' (' . htmlspecialchars($c['cargo']) . ')' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="cargo">Cargo (opcional):</label>
    <select name="cargo" id="cargo" style="min-width:180px;">
        <option value="">— Todos —</option>
        <?php foreach ($listaCargos as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"
                <?= $c === $filtroCargo ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="btn-consultar">🔎 Aplicar</button>
    <a href="ranking_coordenadores.php" class="btn-limpar">✖ Limpar</a>
</form>

<?php if (!$mostrarLista): ?>

    <div class="msg-aviso">
        ⚠️ Selecione o <strong>ano da campanha</strong> e o <strong>candidato</strong>
        para visualizar o ranking de coordenadores.
    </div>

<?php elseif (!$coordenadores): ?>

    <div class="msg-vazia">
        <p>Nenhum coordenador encontrado para este ano/candidato.</p>
    </div>

<?php else: ?>

    <!-- ============================================================
         KPIs
         ============================================================ -->
    <div class="kpis">
        <div class="kpi">
            <div class="rotulo">Coordenadores</div>
            <div class="valor"><?= number_format($totalCoords, 0, ',', '.') ?></div>
            <div class="sub"><?= number_format($totalAtivos, 0, ',', '.') ?> ativos</div>
        </div>

        <div class="kpi laranja">
            <div class="rotulo">Votos esperados</div>
            <div class="valor"><?= number_format($totalEsperado, 0, ',', '.') ?></div>
            <div class="sub">
                soma da expectativa dos coordenadores
            </div>
        </div>

        <div class="kpi teal">
            <div class="rotulo">Votos nas urnas</div>
            <div class="valor"><?= number_format($totalUrnas, 0, ',', '.') ?></div>
            <div class="sub">
                <?= number_format($totalSecoes, 0, ',', '.') ?> seções sob coordenação
            </div>
        </div>

        <div class="kpi <?= $totalDiferenca >= 0 ? 'verde' : 'vermelho' ?>">
            <div class="rotulo">Diferença</div>
            <div class="valor">
                <?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?>
            </div>
            <div class="sub">
                <?= $totalPct !== null
                    ? number_format($totalPct, 1, ',', '.') . '% do esperado'
                    : 'sem base' ?>
            </div>
        </div>

        <div class="kpi roxo">
            <div class="rotulo">Acima de 100%</div>
            <div class="valor"><?= number_format($qtdAcima100, 0, ',', '.') ?></div>
            <div class="sub">de <?= number_format($totalCoords, 0, ',', '.') ?> coordenadores</div>
        </div>

        <?php if ($melhorCoord): ?>
            <div class="kpi verde">
                <div class="rotulo">🏆 Maior volume</div>
                <div class="valor sm"><?= htmlspecialchars($melhorCoord['coordenador']) ?></div>
                <div class="sub">
                    <?= number_format((int)$melhorCoord['urnas'], 0, ',', '.') ?> votos
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================
         TABELA
         ============================================================ -->
    <table>
        <thead>
            <tr>
                <th class="centro" style="width:50px;">#</th>
                <th>Coordenador</th>
                <th>Candidato</th>
                <th class="centro" style="width:80px;">Zonas</th>
                <th class="centro" style="width:80px;">Seções</th>
                <th class="centro" style="width:80px;">Líderes</th>
                <th class="num" style="width:110px;">Esperado</th>
                <th class="num" style="width:110px;">Urnas</th>
                <th class="num" style="width:100px;">Diferença</th>
                <th class="centro" style="width:130px;">%</th>
                <th style="width:70px;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $i = 1;
            foreach ($coordenadores as $c):
                $esp = (int)$c['esperado'];
                $urn = (int)$c['urnas'];
                $dif = (int)$c['diferenca'];
                $pct = $c['pct'];

                if ($pct === null)   { $pctCls = 'badge-zero';     $barCls = '';     $largura = 0; }
                elseif ($pct >= 100) { $pctCls = 'badge-pct-ok';   $barCls = 'ok';   $largura = 100; }
                elseif ($pct >= 70)  { $pctCls = 'badge-pct-med';  $barCls = 'med';  $largura = $pct; }
                else                 { $pctCls = 'badge-pct-ruim'; $barCls = 'ruim'; $largura = $pct; }

                if ($dif > 0)     $difCls = 'badge-pos';
                elseif ($dif < 0) $difCls = 'badge-neg';
                else              $difCls = 'badge-zero';

                $posTxt = $i;
                if ($i === 1) $posTxt = '🥇';
                elseif ($i === 2) $posTxt = '🥈';
                elseif ($i === 3) $posTxt = '🥉';
            ?>
                <tr>
                    <td class="centro posicao"><?= $posTxt ?></td>

                    <td>
                        <strong><?= htmlspecialchars($c['coordenador']) ?></strong>
                        <?php if ((int)$c['ativo'] === 0): ?>
                            <span class="badge badge-inativo">inativo</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="badge badge-ano"><?= (int)$c['ano_campanha'] ?></span>
                        <span class="badge badge-cand">Nº <?= (int)$c['numero_candidato'] ?></span>
                        <?php if (!empty($c['candidato'])): ?>
                            <div style="font-size:11px;color:#666;margin-top:3px;">
                                <?= htmlspecialchars($c['candidato']) ?>
                                <?= $c['candidato_cargo'] ? ' · ' . htmlspecialchars($c['candidato_cargo']) : '' ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td class="centro">
                        <?php if ((int)$c['zonas'] > 0): ?>
                            <span class="badge badge-zona"><?= (int)$c['zonas'] ?></span>
                        <?php else: ?>
                            <span class="vazio">0</span>
                        <?php endif; ?>
                    </td>

                    <td class="centro">
                        <?php if ((int)$c['secoes_unicas'] > 0): ?>
                            <span class="badge badge-zona"><?= number_format((int)$c['secoes_unicas'], 0, ',', '.') ?></span>
                        <?php else: ?>
                            <span class="vazio">0</span>
                        <?php endif; ?>
                    </td>

                    <td class="centro">
                        <?php if ((int)$c['liderancas_total'] > 0): ?>
                            <span class="badge badge-lider">
                                <?= (int)$c['liderancas_ativas'] ?>/<?= (int)$c['liderancas_total'] ?>
                            </span>
                        <?php else: ?>
                            <span class="vazio">0</span>
                        <?php endif; ?>
                    </td>

                    <td class="num">
                        <?php if ($esp > 0): ?>
                            <span class="badge badge-votos"><?= number_format($esp, 0, ',', '.') ?></span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="num">
                        <?php if ($urn > 0): ?>
                            <span class="badge badge-urnas"><?= number_format($urn, 0, ',', '.') ?></span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="num">
                        <?php if ($esp > 0 || $urn > 0): ?>
                            <span class="badge <?= $difCls ?>">
                                <?= ($dif > 0 ? '+' : '') . number_format($dif, 0, ',', '.') ?>
                            </span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="centro">
                        <?php if ($pct !== null): ?>
                            <span class="badge <?= $pctCls ?>">
                                <?= number_format($pct, 1, ',', '.') ?>%
                            </span>
                            <br>
                            <span class="barra" title="<?= number_format($pct, 1, ',', '.') ?>%">
                                <span class="<?= $barCls ?>" style="width: <?= number_format($largura, 1, '.', '') ?>%"></span>
                            </span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?= (int)$c['ativo'] === 1
                            ? '<span class="badge badge-ativo">Ativo</span>'
                            : '<span class="badge badge-inativo">Inativo</span>' ?>
                    </td>
                </tr>
                <?php $i++; ?>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="linha-total">
                <td colspan="5" style="text-align:right;">Total geral</td>
                <td class="centro"><?= number_format($totalLiderancasAtiv, 0, ',', '.') ?>/<?= number_format($totalLiderancas, 0, ',', '.') ?></td>
                <td class="num"><?= number_format($totalEsperado, 0, ',', '.') ?></td>
                <td class="num"><?= number_format($totalUrnas, 0, ',', '.') ?></td>
                <td class="num">
                    <?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?>
                </td>
                <td class="centro">
                    <?= $totalPct !== null
                        ? number_format($totalPct, 1, ',', '.') . '%'
                        : '—' ?>
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>

<?php endif; ?>

<script>
    /* ============================================================
       FILTROS (Ano / Candidato)
       ============================================================ */
    function mudarAno(form) {
        if (form.candidato) form.candidato.value = '0';
        form.submit();
    }
    function mudarCandidato(form) {
        form.submit();
    }
</script>

</body>
</html>