<?php
// ------------------------------------------------------------
// cadastrar_liderancas.php
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ============================================================
// DETECÇÃO DE COLUNAS
// ============================================================

// ---------- coluna de votos em `votos` ----------
$colunasVotos = [];
foreach ($pdo->query("SHOW COLUMNS FROM votos") as $c) {
    $colunasVotos[] = $c['Field'];
}

$candidatosVotos = [
    'votos', 'quantidade', 'total', 'qtde', 'qtd',
    'totalVotos', 'total_votos', 'num_votos', 'votosValidos',
    'numVotos', 'votos_validos', 'comparecimento', 'votosCandidato',
    'qtdVotos',
];

$COL_VOTOS = null;
foreach ($candidatosVotos as $cand) {
    if (in_array($cand, $colunasVotos, true)) {
        $COL_VOTOS = $cand;
        break;
    }
}
if ($COL_VOTOS === null) {
    foreach ($pdo->query("SHOW COLUMNS FROM votos") as $c) {
        $field = $c['Field'];
        $type  = strtolower($c['Type']);
        if (in_array($field, ['numeroZona', 'numeroSecao', 'id'], true)) continue;
        if (str_contains($type, 'int')
            || str_contains($type, 'decimal')
            || str_contains($type, 'float')
            || str_contains($type, 'double')) {
            $COL_VOTOS = $field;
            break;
        }
    }
}
if ($COL_VOTOS === null) {
    die("Não foi possível detectar a coluna de votos na tabela `votos`.");
}

// ---------- coluna de expectativa em `liderancas` ----------
$colunasLider = [];
foreach ($pdo->query("SHOW COLUMNS FROM liderancas") as $c) {
    $colunasLider[] = $c['Field'];
}

$candidatosExpect = [
    'expectativa_votos', 'expectativa', 'votos_esperados',
    'qtd_esperada', 'qtde_esperada', 'previsao', 'meta',
    'previsao_votos', 'votos_previstos', 'expectativaVotos',
];
$COL_EXPECT = null;
foreach ($candidatosExpect as $cand) {
    if (in_array($cand, $colunasLider, true)) {
        $COL_EXPECT = $cand;
        break;
    }
}
$EXPECT_EXPR = $COL_EXPECT ? "l.`$COL_EXPECT`" : "0";
$EXPECT_COL  = $COL_EXPECT ?: null;

// ---------- tabela lider_secao existe? ----------
$temLiderSecao = false;
try {
    $pdo->query("SELECT 1 FROM lider_secao LIMIT 1");
    $temLiderSecao = true;
} catch (Exception $e) {
    $temLiderSecao = false;
}

// ============================================================
// ENDPOINTS AJAX
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $acao = $_GET['ajax'];

        // ---------------- SALVAR ----------------
        if ($acao === 'salvar') {
            $id             = (int)($_POST['id'] ?? 0);
            $coordenadorId  = (int)($_POST['coordenador_id'] ?? 0);
            $nome           = trim($_POST['nome'] ?? '');
            $cpf            = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            $telefone       = trim($_POST['telefone'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $observacoes    = trim($_POST['observacoes'] ?? '');
            $expectativa    = (int)($_POST['expectativa_votos'] ?? 0);
            $ativo          = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;

            if ($nome === '')        throw new Exception('Informe o nome.');
            if ($coordenadorId <= 0) throw new Exception('Selecione o coordenador.');

            // Verifica se o coordenador existe
            $stmt = $pdo->prepare("SELECT id FROM coordenadores WHERE id = :id");
            $stmt->execute([':id' => $coordenadorId]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('Coordenador não encontrado.');
            }

            // Seções (opcional)
            $secoes = $_POST['secoes'] ?? [];
            if (!is_array($secoes)) $secoes = [];
            $secoesLimpas = [];
            foreach ($secoes as $s) {
                if (is_string($s) && strpos($s, '|') !== false) {
                    [$z, $sec] = explode('|', $s, 2);
                    $z   = (int)$z;
                    $sec = (int)$sec;
                    if ($z > 0 && $sec > 0) {
                        $secoesLimpas[] = ['z' => $z, 's' => $sec];
                    }
                }
            }
            // Remove duplicatas
            $tmp = [];
            foreach ($secoesLimpas as $s) {
                $tmp[$s['z'] . '|' . $s['s']] = $s;
            }
            $secoesLimpas = array_values($tmp);

            $pdo->beginTransaction();

            if ($id > 0) {
                // ---------- UPDATE ----------
                if ($EXPECT_COL !== null) {
                    $sql = "UPDATE liderancas
                               SET coordenador_id   = :cid,
                                   nome             = :n,
                                   cpf              = :c,
                                   telefone         = :t,
                                   email            = :e,
                                   observacoes      = :o,
                                   `$EXPECT_COL`    = :exp,
                                   ativo            = :a
                             WHERE id = :id";
                    $params = [
                        ':cid' => $coordenadorId,
                        ':n'   => $nome,
                        ':c'   => $cpf !== '' ? $cpf : null,
                        ':t'   => $telefone !== '' ? $telefone : null,
                        ':e'   => $email !== '' ? $email : null,
                        ':o'   => $observacoes !== '' ? $observacoes : null,
                        ':exp' => $expectativa,
                        ':a'   => $ativo,
                        ':id'  => $id,
                    ];
                } else {
                    $sql = "UPDATE liderancas
                               SET coordenador_id   = :cid,
                                   nome             = :n,
                                   cpf              = :c,
                                   telefone         = :t,
                                   email            = :e,
                                   observacoes      = :o,
                                   ativo            = :a
                             WHERE id = :id";
                    $params = [
                        ':cid' => $coordenadorId,
                        ':n'   => $nome,
                        ':c'   => $cpf !== '' ? $cpf : null,
                        ':t'   => $telefone !== '' ? $telefone : null,
                        ':e'   => $email !== '' ? $email : null,
                        ':o'   => $observacoes !== '' ? $observacoes : null,
                        ':a'   => $ativo,
                        ':id'  => $id,
                    ];
                }
                $pdo->prepare($sql)->execute($params);
            } else {
                // ---------- INSERT ----------
                if ($EXPECT_COL !== null) {
                    $sql = "INSERT INTO liderancas
                                (coordenador_id, nome, cpf, telefone,
                                 email, observacoes, `$EXPECT_COL`, ativo)
                            VALUES
                                (:cid, :n, :c, :t, :e, :o, :exp, :a)";
                    $params = [
                        ':cid' => $coordenadorId,
                        ':n'   => $nome,
                        ':c'   => $cpf !== '' ? $cpf : null,
                        ':t'   => $telefone !== '' ? $telefone : null,
                        ':e'   => $email !== '' ? $email : null,
                        ':o'   => $observacoes !== '' ? $observacoes : null,
                        ':exp' => $expectativa,
                        ':a'   => $ativo,
                    ];
                } else {
                    $sql = "INSERT INTO liderancas
                                (coordenador_id, nome, cpf, telefone,
                                 email, observacoes, ativo)
                            VALUES
                                (:cid, :n, :c, :t, :e, :o, :a)";
                    $params = [
                        ':cid' => $coordenadorId,
                        ':n'   => $nome,
                        ':c'   => $cpf !== '' ? $cpf : null,
                        ':t'   => $telefone !== '' ? $telefone : null,
                        ':e'   => $email !== '' ? $email : null,
                        ':o'   => $observacoes !== '' ? $observacoes : null,
                        ':a'   => $ativo,
                    ];
                }
                $pdo->prepare($sql)->execute($params);
                $id = (int)$pdo->lastInsertId();
            }

            // ---------- Atualiza seções ----------
            if ($temLiderSecao) {
                $pdo->prepare("DELETE FROM lider_secao WHERE lider_id = :id")
                    ->execute([':id' => $id]);

                if ($secoesLimpas) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO lider_secao (lider_id, numeroZona, numeroSecao)
                         VALUES (:id, :z, :s)"
                    );
                    foreach ($secoesLimpas as $s) {
                        $stmt->execute([
                            ':id' => $id,
                            ':z'  => $s['z'],
                            ':s'  => $s['s'],
                        ]);
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'id' => $id, 'msg' => 'Salvo com sucesso.']);
            exit;
        }

        // ---------------- EXCLUIR ----------------
        if ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido.');

            $pdo->beginTransaction();
            if ($temLiderSecao) {
                $pdo->prepare("DELETE FROM lider_secao WHERE lider_id = :id")
                    ->execute([':id' => $id]);
            }
            $pdo->prepare("DELETE FROM liderancas WHERE id = :id")
                ->execute([':id' => $id]);
            $pdo->commit();

            echo json_encode(['ok' => true, 'msg' => 'Excluído.']);
            exit;
        }

        // ---------------- SECOES DE UMA ZONA (AJAX cascata) ----------------
        if ($acao === 'secoes_zona') {
            $zona = (int)($_GET['zona'] ?? 0);
            if ($zona <= 0) {
                echo json_encode([]);
                exit;
            }
            $stmt = $pdo->prepare(
                "SELECT DISTINCT numeroSecao
                   FROM secoes_enderecos
                  WHERE numeroZona = :z
                  ORDER BY numeroSecao"
            );
            $stmt->execute([':z' => $zona]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
            exit;
        }

        // ---------------- SECOES DA LIDERANCA (AJAX edição) ----------------
        if ($acao === 'secoes_lider') {
            $liderId = (int)($_GET['lider_id'] ?? 0);
            if ($liderId <= 0 || !$temLiderSecao) {
                echo json_encode([]);
                exit;
            }
            $stmt = $pdo->prepare(
                "SELECT numeroZona, numeroSecao
                   FROM lider_secao
                  WHERE lider_id = :id
                  ORDER BY numeroZona, numeroSecao"
            );
            $stmt->execute([':id' => $liderId]);
            $rows = $stmt->fetchAll();

            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'zona'  => (int)$r['numeroZona'],
                    'secao' => (int)$r['numeroSecao'],
                ];
            }
            echo json_encode($out);
            exit;
        }

        throw new Exception('Ação desconhecida.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        exit;
    }
}

// ============================================================
// DADOS PARA A PÁGINA
// ============================================================

// Coordenadores (para o select do modal)
$coordenadores = $pdo->query(
    "SELECT id, nome, ativo FROM coordenadores ORDER BY nome"
)->fetchAll();

// Zonas disponíveis
$zonas = $pdo->query(
    "SELECT DISTINCT numeroZona FROM secoes_enderecos ORDER BY numeroZona"
)->fetchAll(PDO::FETCH_COLUMN);

if (!$zonas) {
    $zonas = $pdo->query(
        "SELECT DISTINCT numeroZona FROM votos ORDER BY numeroZona"
    )->fetchAll(PDO::FETCH_COLUMN);
}

// ------------------------------------------------------------
// Query principal — lista de lideranças
// (bloco separado para não quebrar a string com ternário inline)
// ------------------------------------------------------------
if ($temLiderSecao) {
    $sqlPartesSecoes = "
        (SELECT GROUP_CONCAT(DISTINCT ls.numeroZona
                             ORDER BY ls.numeroZona SEPARATOR ',')
           FROM lider_secao ls
          WHERE ls.lider_id = l.id) AS zonas_str,
        (SELECT COUNT(DISTINCT ls.numeroSecao)
           FROM lider_secao ls
          WHERE ls.lider_id = l.id) AS qtd_secoes,
        (SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
           FROM votos v
          WHERE (v.numeroZona, v.numeroSecao) IN (
                SELECT ls2.numeroZona, ls2.numeroSecao
                  FROM lider_secao ls2
                 WHERE ls2.lider_id = l.id
          )) AS votos_urnas
    ";
} else {
    $sqlPartesSecoes = "
        NULL AS zonas_str,
        0    AS qtd_secoes,
        0    AS votos_urnas
    ";
}

$sql = "
    SELECT l.*,
        c.nome AS coordenador_nome,
        $sqlPartesSecoes
    FROM liderancas l
    LEFT JOIN coordenadores c ON c.id = l.coordenador_id
    ORDER BY c.nome, l.nome
";
$liderancas = $pdo->query($sql)->fetchAll();

// ------------------------------------------------------------
// Totais
// ------------------------------------------------------------
$totalLiderancas     = count($liderancas);
$totalLiderancasAtiv = 0;
$totalVotosEsperados = 0;
$totalVotosUrnas     = 0;

foreach ($liderancas as &$l) {
    $l['zonas'] = !empty($l['zonas_str'])
        ? array_map('intval', explode(',', $l['zonas_str']))
        : [];

    $esp = (int)($l[$COL_EXPECT] ?? 0);
    $urn = (int)$l['votos_urnas'];

    $l['diferenca'] = $urn - $esp;
    $l['pct']       = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;

    if ((int)$l['ativo'] === 1) $totalLiderancasAtiv++;
    $totalVotosEsperados += $esp;
    $totalVotosUrnas     += $urn;
}
unset($l);

$totalDiferenca = $totalVotosUrnas - $totalVotosEsperados;
$totalPct = $totalVotosEsperados > 0
    ? round(($totalVotosUrnas / $totalVotosEsperados) * 100, 1)
    : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lideranças</title>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:180px;
        border-left:5px solid #6f42c1;
    }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#6f42c1; margin-top:4px; }
    .kpi.verde   { border-left-color:#28a745; } .kpi.verde .valor { color:#28a745; }
    .kpi.roxo    { border-left-color:#6f42c1; } .kpi.roxo .valor  { color:#6f42c1; }
    .kpi.laranja { border-left-color:#fd7e14; } .kpi.laranja .valor { color:#fd7e14; }
    .kpi.teal    { border-left-color:#17a2b8; } .kpi.teal .valor  { color:#17a2b8; }
    .kpi.vermelho{ border-left-color:#dc3545; } .kpi.vermelho .valor { color:#dc3545; }
    .kpi .mini   { font-size:11px; color:#999; margin-top:2px; }

    .btn { display:inline-block; padding:8px 14px; border-radius:4px; border:none;
           cursor:pointer; text-decoration:none; font-size:14px; font-family:inherit; }
    .btn-novo { background:#28a745; color:#fff; }
    .btn-novo:hover { background:#1e7e34; }
    .btn-editar{ background:#0069d9; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-editar:hover { background:#0053b3; }
    .btn-excluir{ background:#dc3545; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-excluir:hover { background:#b02a37; }
    .btn-salvar { background:#28a745; color:#fff; padding:10px 18px; font-size:14px; }
    .btn-salvar:hover { background:#1e7e34; }
    .btn-cancelar { background:#6c757d; color:#fff; padding:10px 18px; font-size:14px; }
    .btn-cancelar:hover { background:#5a6268; }

    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; font-size:13px; }
    th { background:#6f42c1; color:#fff; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#f4eefc; }
    tr.linha-total { background:#f4eefc; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #6f42c1; }
    .num { text-align:right; }
    .centro { text-align:center; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-zona  { background:#e6f0fa; color:#0069d9; }
    .badge-coord { background:#f0e6ff; color:#6f42c1; }
    .badge-ativo { background:#d4f5dd; color:#1c7a34; }
    .badge-inativo{ background:#f0f0f0; color:#888; }
    .badge-votos { background:#fff0e0; color:#c25b00; }
    .badge-urnas { background:#d9edf7; color:#0c5460; }
    .badge-pos   { background:#d4f5dd; color:#1c7a34; }
    .badge-neg   { background:#fdecea; color:#a71d2a; }
    .badge-zero  { background:#f0f0f0; color:#666; }
    .badge-pct-ok  { background:#d4f5dd; color:#1c7a34; }
    .badge-pct-med { background:#fff3cd; color:#856404; }
    .badge-pct-ruim{ background:#fdecea; color:#a71d2a; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    .info-col {
        font-size:12px; color:#666; margin-bottom:12px;
        background:#fff; padding:8px 14px; border-radius:6px;
        border-left:4px solid #17a2b8;
        box-shadow: 0 1px 2px rgba(0,0,0,.05);
    }
    .info-col code { background:#f4f4f4; padding:1px 5px; border-radius:3px; font-size:12px; }
    .info-col .aviso {
        color:#856404; background:#fff3cd; padding:2px 8px;
        border-radius:6px; font-weight:bold; margin-left:6px;
    }

    .modal-overlay {
        display:none; position: fixed; inset: 0;
        background: rgba(0,0,0,.5); z-index: 1000;
        align-items: center; justify-content: center;
        padding: 20px;
    }
    .modal-overlay.aberto { display: flex; }
    .modal {
        background:#fff; border-radius: 10px;
        max-width: 680px; width: 100%; max-height: 90vh;
        overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,.3);
    }
    .modal-header {
        padding: 16px 22px; border-bottom: 1px solid #eee;
        display: flex; justify-content: space-between; align-items: center;
    }
    .modal-header h2 { margin: 0; font-size: 18px; color:#6f42c1; }
    .modal-header .fechar {
        background: none; border: none; font-size: 22px;
        cursor: pointer; color: #888;
    }
    .modal-body { padding: 20px 22px; }
    .modal-footer {
        padding: 14px 22px; border-top: 1px solid #eee;
        text-align: right; background:#fafafa;
    }
    .campo { margin-bottom: 14px; }
    .campo label { display: block; font-weight: bold; margin-bottom: 4px; font-size: 13px; }
    .campo input[type=text],
    .campo input[type=email],
    .campo input[type=number],
    .campo textarea,
    .campo select {
        width: 100%; padding: 8px 10px; border: 1px solid #ccc;
        border-radius: 4px; font-size: 14px; font-family: inherit;
        box-sizing: border-box;
    }
    .campo textarea { resize: vertical; min-height: 60px; }
    .linha-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .linha-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    .toggle { display:flex; align-items:center; gap:8px; }
    .toggle input { width:auto; }

    .select2-container { width: 100% !important; }
    .select2-container--default .select2-selection--single,
    .select2-container--default .select2-selection--multiple {
        min-height: 40px; padding: 4px 6px; border: 1px solid #ccc; border-radius: 4px;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background: #6f42c1; color: #fff; border: none;
        padding: 2px 8px; font-size: 12px;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        color: #fff; margin-right: 4px;
    }
    .select2-search--dropdown .select2-search__field {
        padding: 6px; font-size: 14px;
    }

    .btn-add-secao {
        display:inline-flex; align-items:center; gap:6px;
        background:#17a2b8; color:#fff; border:none;
        padding:6px 12px; border-radius:4px; font-size:13px;
        cursor:pointer; margin-top:6px;
    }
    .btn-add-secao:hover { background:#117a8b; }

    .secoes-lista {
        display:flex; flex-wrap:wrap; gap:6px;
        margin-top:8px; min-height: 30px;
        padding:6px; border:1px dashed #ccc; border-radius:4px;
        background:#fafafa;
    }
    .secao-chip {
        display:inline-flex; align-items:center; gap:6px;
        background:#6f42c1; color:#fff;
        padding:3px 10px; border-radius:12px; font-size:12px;
    }
    .secao-chip .x {
        cursor:pointer; font-weight:bold; opacity:.85;
        margin-left:2px;
    }
    .secao-chip .x:hover { opacity:1; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    .form-msg { font-size:13px; float:left; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Lideranças</h1>

<p class="info-col">
    Coluna de votos em <code>votos</code>:
    <strong><?= htmlspecialchars($COL_VOTOS) ?></strong>
    &nbsp;·&nbsp;
    Coluna de expectativa em <code>liderancas</code>:
    <?php if ($COL_EXPECT): ?>
        <strong><?= htmlspecialchars($COL_EXPECT) ?></strong>
    <?php else: ?>
        <span class="aviso">não existe — usando 0</span>
    <?php endif; ?>
    &nbsp;·&nbsp;
    Tabela <code>lider_secao</code>:
    <?php if ($temLiderSecao): ?>
        <strong>existe</strong>
    <?php else: ?>
        <span class="aviso">não existe — seções não serão salvas</span>
    <?php endif; ?>
</p>

<div class="kpis">
    <div class="kpi">
        <div class="rotulo">Lideranças</div>
        <div class="valor"><?= number_format($totalLiderancas, 0, ',', '.') ?></div>
    </div>
    <div class="kpi verde">
        <div class="rotulo">Ativas</div>
        <div class="valor"><?= number_format($totalLiderancasAtiv, 0, ',', '.') ?></div>
    </div>
    <div class="kpi laranja">
        <div class="rotulo">Votos esperados</div>
        <div class="valor"><?= number_format($totalVotosEsperados, 0, ',', '.') ?></div>
    </div>
    <div class="kpi teal">
        <div class="rotulo">Votos das urnas</div>
        <div class="valor"><?= number_format($totalVotosUrnas, 0, ',', '.') ?></div>
    </div>
    <div class="kpi <?= $totalDiferenca >= 0 ? 'verde' : 'vermelho' ?>">
        <div class="rotulo">Diferença</div>
        <div class="valor">
            <?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?>
        </div>
        <div class="mini">
            <?= $totalPct !== null
                ? number_format($totalPct, 1, ',', '.') . '% do esperado'
                : 'sem base de comparação' ?>
        </div>
    </div>
</div>

<div style="margin-bottom:15px;">
    <button type="button" class="btn btn-novo" id="btn-novo">+ Nova Liderança</button>
</div>

<table>
    <thead>
        <tr>
            <th style="width:40px;">#</th>
            <th>Nome</th>
            <th>Coordenador</th>
            <th style="width:100px;">Zonas</th>
            <th class="centro" style="width:70px;">Seções</th>
            <th class="num" style="width:110px;">Esperados</th>
            <th class="num" style="width:110px;">Urnas</th>
            <th class="num" style="width:100px;">Diferença</th>
            <th class="centro" style="width:80px;">%</th>
            <th style="width:70px;">Status</th>
            <th style="width:150px;">Ações</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$liderancas): ?>
            <tr>
                <td colspan="11" style="text-align:center;color:#999;padding:20px;">
                    Nenhuma liderança cadastrada ainda.
                </td>
            </tr>
        <?php else: ?>
            <?php $i = 1; foreach ($liderancas as $l): ?>
                <?php
                $esp = (int)($l[$COL_EXPECT] ?? 0);
                $urn = (int)$l['votos_urnas'];
                $dif = (int)$l['diferenca'];
                $pct = $l['pct'];

                if ($pct === null)   $pctCls = 'badge-zero';
                elseif ($pct >= 100) $pctCls = 'badge-pct-ok';
                elseif ($pct >= 70)  $pctCls = 'badge-pct-med';
                else                 $pctCls = 'badge-pct-ruim';

                if ($dif > 0)     $difCls = 'badge-pos';
                elseif ($dif < 0) $difCls = 'badge-neg';
                else              $difCls = 'badge-zero';
                ?>
                <tr data-id="<?= (int)$l['id'] ?>">
                    <td><?= $i++ ?></td>
                    <td><strong><?= htmlspecialchars($l['nome']) ?></strong></td>
                    <td>
                        <?php if (!empty($l['coordenador_nome'])): ?>
                            <span class="badge badge-coord">
                                <?= htmlspecialchars($l['coordenador_nome']) ?>
                            </span>
                        <?php else: ?>
                            <span class="vazio">— sem coordenador —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($l['zonas']): ?>
                            <?php foreach ($l['zonas'] as $z): ?>
                                <span class="badge badge-zona"><?= (int)$z ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="centro">
                        <?= (int)$l['qtd_secoes'] > 0 ? (int)$l['qtd_secoes'] : '<span class="vazio">0</span>' ?>
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
                            <span class="badge <?= $pctCls ?>"><?= number_format($pct, 1, ',', '.') ?>%</span>
                        <?php else: ?>
                            <span class="vazio">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$l['ativo'] === 1): ?>
                            <span class="badge badge-ativo">Ativo</span>
                        <?php else: ?>
                            <span class="badge badge-inativo">Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn-editar"
                                data-id="<?= (int)$l['id'] ?>">Editar</button>
                        <button type="button" class="btn btn-excluir"
                                data-id="<?= (int)$l['id'] ?>"
                                data-nome="<?= htmlspecialchars($l['nome']) ?>">Excluir</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr class="linha-total">
                <td colspan="5" style="text-align:right;"><strong>Total geral</strong></td>
                <td class="num"><?= number_format($totalVotosEsperados, 0, ',', '.') ?></td>
                <td class="num"><?= number_format($totalVotosUrnas, 0, ',', '.') ?></td>
                <td class="num">
                    <?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?>
                </td>
                <td class="centro">
                    <?= $totalPct !== null ? number_format($totalPct, 1, ',', '.') . '%' : '—' ?>
                </td>
                <td colspan="2"></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Modal -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modal-titulo">Nova Liderança</h2>
            <button type="button" class="fechar" id="btn-fechar">&times;</button>
        </div>
        <form id="form-lider">
            <input type="hidden" name="id" id="f-id" value="0">

            <div class="modal-body">
                <div class="campo">
                    <label for="f-coordenador">Coordenador responsável *</label>
                    <select name="coordenador_id" id="f-coordenador" required>
                        <option value="">— Selecione —</option>
                        <?php foreach ($coordenadores as $c): ?>
                            <option value="<?= (int)$c['id'] ?>">
                                <?= htmlspecialchars($c['nome']) ?>
                                <?= (int)$c['ativo'] === 0 ? ' (inativo)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label for="f-nome">Nome completo *</label>
                    <input type="text" name="nome" id="f-nome" required maxlength="200">
                </div>

                <div class="linha-2">
                    <div class="campo">
                        <label for="f-cpf">CPF</label>
                        <input type="text" name="cpf" id="f-cpf" maxlength="14" placeholder="000.000.000-00">
                    </div>
                    <div class="campo">
                        <label for="f-telefone">Telefone</label>
                        <input type="text" name="telefone" id="f-telefone" maxlength="20">
                    </div>
                </div>

                <div class="linha-2">
                    <div class="campo">
                        <label for="f-email">E-mail</label>
                        <input type="email" name="email" id="f-email" maxlength="120">
                    </div>
                    <div class="campo">
                        <label for="f-expectativa">Expectativa de votos</label>
                        <input type="number" name="expectativa_votos" id="f-expectativa"
                               min="0" step="1" value="0">
                    </div>
                </div>

                <?php if ($temLiderSecao): ?>
                <div class="campo">
                    <label>Seções sob responsabilidade</label>
                    <div class="linha-2">
                        <select id="f-zona-tmp">
                            <option value="">— Zona —</option>
                            <?php foreach ($zonas as $z): ?>
                                <option value="<?= (int)$z ?>">Zona <?= (int)$z ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="f-secao-tmp" disabled>
                            <option value="">— Seção —</option>
                        </select>
                    </div>
                    <button type="button" class="btn-add-secao" id="btn-add-secao">
                        + Adicionar seção
                    </button>
                    <div class="secoes-lista" id="secoes-lista">
                        <span class="vazio" id="secoes-vazio">Nenhuma seção adicionada</span>
                    </div>
                    <div id="secoes-inputs"></div>
                </div>
                <?php endif; ?>

                <div class="campo">
                    <label for="f-observacoes">Observações</label>
                    <textarea name="observacoes" id="f-observacoes"></textarea>
                </div>

                <div class="campo toggle">
                    <input type="checkbox" name="ativo" id="f-ativo" value="1" checked>
                    <label for="f-ativo" style="margin:0;font-weight:normal;">Ativo</label>
                </div>
            </div>

            <div class="modal-footer">
                <span class="form-msg" id="form-msg"></span>
                <button type="button" class="btn btn-cancelar" id="btn-cancelar">Cancelar</button>
                <button type="submit" class="btn btn-salvar" id="btn-salvar">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
    const LIDERANCAS = <?= json_encode($liderancas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const TEM_LIDER_SECAO = <?= $temLiderSecao ? 'true' : 'false' ?>;
    const COL_EXPECT = <?= json_encode($COL_EXPECT) ?>;

    (function () {
        const $modal  = jQuery('#modal');
        const $form   = jQuery('#form-lider');
        const $titulo = jQuery('#modal-titulo');
        const $msg    = jQuery('#form-msg');
        const $coord  = jQuery('#f-coordenador');

        $coord.select2({
            language: 'pt-BR',
            placeholder: '— Selecione —',
            allowClear: true,
            width: '100%'
        });

        // ---------- Seções ----------
        let secoesSelecionadas = [];

        function renderSecoes() {
            if (!TEM_LIDER_SECAO) return;
            const $lista  = jQuery('#secoes-lista');
            const $inputs = jQuery('#secoes-inputs');
            $lista.empty();
            $inputs.empty();

            if (!secoesSelecionadas.length) {
                $lista.html('<span class="vazio">Nenhuma seção adicionada</span>');
                return;
            }

            secoesSelecionadas.forEach(function (s, idx) {
                const $chip = jQuery('<span class="secao-chip"></span>');
                $chip.append(document.createTextNode(
                    'Zona ' + s.zona + ' · Seção ' + s.secao + ' '
                ));
                const $x = jQuery('<span class="x" title="Remover">×</span>');
                $x.on('click', function () {
                    secoesSelecionadas.splice(idx, 1);
                    renderSecoes();
                });
                $chip.append($x);
                $lista.append($chip);

                const $inp = jQuery('<input type="hidden" name="secoes[]">');
                $inp.val(s.zona + '|' + s.secao);
                $inputs.append($inp);
            });
        }

        jQuery('#f-zona-tmp').on('change', function () {
            const zona = jQuery(this).val();
            const $sec = jQuery('#f-secao-tmp');
            $sec.prop('disabled', true).html('<option value="">— Seção —</option>');
            if (!zona) return;

            fetch('cadastrar_liderancas.php?ajax=secoes_zona&zona=' + encodeURIComponent(zona))
                .then(r => r.json())
                .then(lista => {
                    lista.forEach(function (s) {
                        $sec.append('<option value="' + s + '">Seção ' + s + '</option>');
                    });
                    $sec.prop('disabled', false);
                });
        });

        jQuery('#btn-add-secao').on('click', function () {
            const zona  = parseInt(jQuery('#f-zona-tmp').val()) || 0;
            const secao = parseInt(jQuery('#f-secao-tmp').val()) || 0;
            if (zona <= 0 || secao <= 0) {
                alert('Escolha a zona e a seção.');
                return;
            }
            const existe = secoesSelecionadas.some(s => s.zona === zona && s.secao === secao);
            if (existe) {
                alert('Essa seção já foi adicionada.');
                return;
            }
            secoesSelecionadas.push({ zona: zona, secao: secao });
            renderSecoes();
        });

        // ---------- Modal ----------
        function limparForm() {
            $form[0].reset();
            jQuery('#f-id').val('0');
            jQuery('#f-expectativa').val('0');
            $coord.val(null).trigger('change');
            secoesSelecionadas = [];
            renderSecoes();
            jQuery('#f-zona-tmp').val('');
            jQuery('#f-secao-tmp').prop('disabled', true)
                                .html('<option value="">— Seção —</option>');
            $msg.text('').css('color', '');
        }

        function abrirModal(id) {
            limparForm();
            if (id > 0) {
                const l = LIDERANCAS.find(x => parseInt(x.id) === id);
                if (!l) return;
                $titulo.text('Editar Liderança');
                jQuery('#f-id').val(l.id);
                jQuery('#f-nome').val(l.nome);
                jQuery('#f-cpf').val(l.cpf || '');
                jQuery('#f-telefone').val(l.telefone || '');
                jQuery('#f-email').val(l.email || '');
                jQuery('#f-observacoes').val(l.observacoes || '');
                jQuery('#f-ativo').prop('checked', parseInt(l.ativo) === 1);

                if (COL_EXPECT && l[COL_EXPECT] !== undefined) {
                    jQuery('#f-expectativa').val(parseInt(l[COL_EXPECT] || 0));
                }

                if (l.coordenador_id) {
                    $coord.val(String(l.coordenador_id)).trigger('change');
                }

                // Carrega seções existentes
                if (TEM_LIDER_SECAO) {
                    fetch('cadastrar_liderancas.php?ajax=secoes_lider&lider_id=' + l.id)
                        .then(r => r.json())
                        .then(arr => {
                            secoesSelecionadas = Array.isArray(arr) ? arr : [];
                            renderSecoes();
                        })
                        .catch(() => {
                            secoesSelecionadas = [];
                            renderSecoes();
                        });
                }
            } else {
                $titulo.text('Nova Liderança');
            }
            $modal.addClass('aberto');
            setTimeout(() => jQuery('#f-nome').focus(), 100);
        }

        function fecharModal() { $modal.removeClass('aberto'); }

        jQuery('#btn-novo').on('click', () => abrirModal(0));
        jQuery(document).on('click', '.btn-editar', function () {
            abrirModal(parseInt(jQuery(this).data('id')));
        });

        jQuery(document).on('click', '.btn-excluir', function () {
            const id   = parseInt(jQuery(this).data('id'));
            const nome = jQuery(this).data('nome');
            if (!confirm('Excluir a liderança "' + nome + '"?')) return;

            const fd = new FormData();
            fd.append('id', id);
            fetch('cadastrar_liderancas.php?ajax=excluir', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => j.ok ? location.reload() : alert('Erro: ' + j.msg))
                .catch(() => alert('Erro de rede.'));
        });

        jQuery('#btn-fechar, #btn-cancelar').on('click', fecharModal);
        $modal.on('click', function (e) { if (e.target === this) fecharModal(); });

        $form.on('submit', function (e) {
            e.preventDefault();
            $msg.text('Salvando...').css('color', '#6c757d');

            const fd = new FormData(this);
            fetch('cadastrar_liderancas.php?ajax=salvar', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => {
                    if (j.ok) {
                        $msg.text('✔ ' + j.msg).css('color', '#28a745');
                        setTimeout(() => location.reload(), 600);
                    } else {
                        $msg.text('✘ ' + j.msg).css('color', '#dc3545');
                    }
                })
                .catch(() => $msg.text('✘ Erro de rede.').css('color', '#dc3545'));
        });
    })();
</script>

</body>
</html>