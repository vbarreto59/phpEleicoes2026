<?php
// ------------------------------------------------------------
// bairro_regiao_editor.php
// Editor de região e tipo_voto dos bairros (tabela `bairros`).
// Permite:
//  - Filtrar por município / região / tipo_voto / busca por nome
//  - Alterar a região e o tipo_voto de vários bairros de uma vez
//  - Adicionar novos bairros
//  - Excluir bairros
//  - Ver bairros de secoes_enderecos que ainda não estão cadastrados
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

// ============================================================
// HELPERS
// ============================================================
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function corRegiao($nome) {
    $map = [
        'Zona Norte' => '#4363d8',
        'Zona Sul'   => '#f58231',
        'Centro'     => '#800000',
        'Zona Oeste' => '#3cb44b',
    ];
    return $map[$nome] ?? '#6c757d';
}

function corTipoVoto($tipo) {
    $map = [
        'Opinião'   => '#6f42c1', // roxo
        'Estrutura' => '#20c997', // teal
    ];
    return $map[$tipo] ?? '#6c757d';
}

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
// PERMISSÃO
// ============================================================
$podeEditar = $isAdmin;

// ============================================================
// DETECÇÃO DE ESTRUTURAS
// ============================================================
$temBairros = false;
$temRegioes = false;
$temSecEnd  = false;
$COL_BAIRRO_SEC = null;
$temTipoVoto = false;

try { $pdo->query("SELECT 1 FROM bairros LIMIT 1");         $temBairros = true; } catch (Exception $e) {}
try { $pdo->query("SELECT 1 FROM regioes_bairro LIMIT 1");  $temRegioes = true; } catch (Exception $e) {}

// Detecta se a coluna `tipo_voto` existe em `bairros`
if ($temBairros) {
    try {
        $colsB = [];
        foreach ($pdo->query("SHOW COLUMNS FROM bairros") as $c) $colsB[] = $c['Field'];
        $temTipoVoto = in_array('tipo_voto', $colsB, true);
    } catch (Exception $e) { $temTipoVoto = false; }
}

try {
    $colsSec = [];
    foreach ($pdo->query("SHOW COLUMNS FROM secoes_enderecos") as $c) $colsSec[] = $c['Field'];
    $temSecEnd = true;
    foreach (['bairro', 'nomeBairro', 'bairroSecao', 'nmBairro'] as $cand) {
        if (in_array($cand, $colsSec, true)) { $COL_BAIRRO_SEC = $cand; break; }
    }
} catch (Exception $e) { $temSecEnd = false; }

if (!$temBairros || !$temRegioes) {
    die("As tabelas <code>bairros</code> e <code>regioes_bairro</code> são necessárias.");
}

// Lista de tipos de voto aceitos (para validação no PHP)
$TIPOS_VOTO_VALIDOS = ['Opinião', 'Estrutura'];

// ============================================================
// AÇÕES (POST)
// ============================================================
$msg      = '';
$msgTipo  = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $podeEditar) {
    try {
        $acao = $_POST['acao'] ?? '';

        // ---------- SALVAR ALTERAÇÕES EM MASSA ----------
        if ($acao === 'salvar') {
            $regioesPorId = [];
            foreach ($pdo->query("SELECT id, nome FROM regioes_bairro") as $r) {
                $regioesPorId[(int)$r['id']] = $r['nome'];
            }

            $alterados = 0;
            $ids = $_POST['bairro_id']  ?? [];
            $reg = $_POST['regiao_id']  ?? [];
            $tip = $_POST['tipo_voto']  ?? [];

            if (is_array($ids) && is_array($reg)) {
                if ($temTipoVoto) {
                    $stmtUpd = $pdo->prepare(
                        "UPDATE bairros
                            SET regiao_bairro_id = :rid,
                                tipo_voto        = :tp
                          WHERE id = :id"
                    );
                    $stmtChk = $pdo->prepare(
                        "SELECT regiao_bairro_id, tipo_voto FROM bairros WHERE id = :id"
                    );
                } else {
                    $stmtUpd = $pdo->prepare(
                        "UPDATE bairros SET regiao_bairro_id = :rid WHERE id = :id"
                    );
                    $stmtChk = $pdo->prepare(
                        "SELECT regiao_bairro_id FROM bairros WHERE id = :id"
                    );
                }

                foreach ($ids as $idB) {
                    $idB = (int)$idB;
                    if ($idB <= 0) continue;

                    $idR = (int)($reg[$idB] ?? 0);
                    if ($idR <= 0 || !isset($regioesPorId[$idR])) continue;

                    $tp = null;
                    if ($temTipoVoto) {
                        $tp = (string)($tip[$idB] ?? '');
                        if (!in_array($tp, $TIPOS_VOTO_VALIDOS, true)) {
                            $tp = null;
                        }
                    }

                    $stmtChk->execute([':id' => $idB]);
                    $atualRow = $stmtChk->fetch();
                    if (!$atualRow) continue;

                    $mudouRegiao = ((int)$atualRow['regiao_bairro_id'] !== $idR);
                    $mudouTipo   = false;
                    if ($temTipoVoto && $tp !== null) {
                        $mudouTipo = ((string)$atualRow['tipo_voto'] !== $tp);
                    }

                    if (!$mudouRegiao && !$mudouTipo) continue;

                    if ($temTipoVoto) {
                        $tpFinal = ($tp !== null) ? $tp : (string)$atualRow['tipo_voto'];
                        $stmtUpd->execute([':rid' => $idR, ':tp' => $tpFinal, ':id' => $idB]);
                    } else {
                        $stmtUpd->execute([':rid' => $idR, ':id' => $idB]);
                    }
                    $alterados++;
                }
            }

            $msg = "✅ <strong>{$alterados}</strong> bairro(s) atualizado(s).";
            $msgTipo = 'ok';
        }

        // ---------- ADICIONAR NOVO BAIRRO ----------
        elseif ($acao === 'adicionar') {
            $novoNome = trim((string)($_POST['novo_nome'] ?? ''));
            $novoMun  = trim((string)($_POST['novo_municipio'] ?? ''));
            $novoReg  = (int)($_POST['novo_regiao_id'] ?? 0);
            $novoTipo = trim((string)($_POST['novo_tipo_voto'] ?? ''));

            if ($novoNome === '' || $novoMun === '' || $novoReg <= 0) {
                throw new Exception("Preencha nome, município e região.");
            }
            if ($temTipoVoto) {
                if ($novoTipo === '') $novoTipo = 'Estrutura';
                if (!in_array($novoTipo, $TIPOS_VOTO_VALIDOS, true)) {
                    throw new Exception("Tipo de voto inválido.");
                }
            }

            $stmt = $pdo->prepare(
                "SELECT id FROM bairros
                  WHERE UPPER(TRIM(nome))      = UPPER(TRIM(:n))
                    AND UPPER(TRIM(municipio)) = UPPER(TRIM(:m))"
            );
            $stmt->execute([':n' => $novoNome, ':m' => $novoMun]);
            if ($stmt->fetchColumn()) {
                throw new Exception("Este bairro já está cadastrado em {$novoMun}.");
            }

            $stmt = $pdo->prepare("SELECT id FROM regioes_bairro WHERE id = :id");
            $stmt->execute([':id' => $novoReg]);
            if (!$stmt->fetchColumn()) {
                throw new Exception("Região inválida.");
            }

            if ($temTipoVoto) {
                $stmt = $pdo->prepare(
                    "INSERT INTO bairros (nome, municipio, regiao_bairro_id, tipo_voto)
                     VALUES (:n, :m, :r, :tp)"
                );
                $stmt->execute([
                    ':n'  => $novoNome,
                    ':m'  => $novoMun,
                    ':r'  => $novoReg,
                    ':tp' => $novoTipo,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO bairros (nome, municipio, regiao_bairro_id)
                     VALUES (:n, :m, :r)"
                );
                $stmt->execute([':n' => $novoNome, ':m' => $novoMun, ':r' => $novoReg]);
            }

            $msg = "✅ Bairro <strong>" . h($novoNome) . "</strong> adicionado em <strong>" . h($novoMun) . "</strong>.";
            $msgTipo = 'ok';
        }

        // ---------- EXCLUIR BAIRRO ----------
        elseif ($acao === 'excluir') {
            $idDel = (int)($_POST['excluir_id'] ?? 0);
            if ($idDel <= 0) throw new Exception("ID inválido.");

            $stmt = $pdo->prepare("SELECT nome, municipio FROM bairros WHERE id = :id");
            $stmt->execute([':id' => $idDel]);
            $b = $stmt->fetch();
            if (!$b) throw new Exception("Bairro não encontrado.");

            $stmt = $pdo->prepare("DELETE FROM bairros WHERE id = :id");
            $stmt->execute([':id' => $idDel]);

            $msg = "🗑️ Bairro <strong>" . h($b['nome']) . "</strong> (" . h($b['municipio']) . ") excluído.";
            $msgTipo = 'ok';
        }

        // ---------- ADICIONAR REGIÃO ----------
        elseif ($acao === 'add_regiao') {
            $nomeR = trim((string)($_POST['nome_regiao'] ?? ''));
            if ($nomeR === '') throw new Exception("Informe o nome da região.");

            $stmt = $pdo->prepare(
                "SELECT id FROM regioes_bairro WHERE UPPER(nome) = UPPER(:n)"
            );
            $stmt->execute([':n' => $nomeR]);
            if ($stmt->fetchColumn()) {
                throw new Exception("Essa região já existe.");
            }

            $stmt = $pdo->prepare("INSERT INTO regioes_bairro (nome) VALUES (:n)");
            $stmt->execute([':n' => $nomeR]);

            $msg = "✅ Região <strong>" . h($nomeR) . "</strong> criada.";
            $msgTipo = 'ok';
        }

    } catch (Exception $e) {
        $msg = "❌ Erro: " . h($e->getMessage());
        $msgTipo = 'erro';
    }
}

// ============================================================
// FILTROS DE VISUALIZAÇÃO
// ============================================================
$fMun     = trim((string)($_GET['municipio'] ?? ''));
$fReg     = trim((string)($_GET['regiao']    ?? ''));
$fTipo    = trim((string)($_GET['tipo_voto'] ?? ''));
$fBusca   = trim((string)($_GET['busca']     ?? ''));
$fOrdem   = $_GET['ordem'] ?? 'bairro';
if (!in_array($fOrdem, ['bairro','municipio','regiao','tipo_voto'], true)) $fOrdem = 'bairro';
if ($fTipo !== '' && !in_array($fTipo, $TIPOS_VOTO_VALIDOS, true)) $fTipo = '';

// ============================================================
// LISTAS AUXILIARES
// ============================================================
$regioes = $pdo->query(
    "SELECT id, nome FROM regioes_bairro ORDER BY id"
)->fetchAll();

$regioesMap = [];
foreach ($regioes as $r) $regioesMap[(int)$r['id']] = $r['nome'];

$municipiosLista = $pdo->query(
    "SELECT nomeMunicipio FROM (
        SELECT DISTINCT nomeMunicipio FROM votos
         WHERE nomeMunicipio IS NOT NULL AND nomeMunicipio <> ''
        UNION
        SELECT DISTINCT municipio AS nomeMunicipio FROM bairros
         WHERE municipio IS NOT NULL AND municipio <> ''
     ) t ORDER BY nomeMunicipio"
)->fetchAll(PDO::FETCH_COLUMN);

// ============================================================
// QUERY DOS BAIRROS
// ============================================================
$where  = [];
$params = [];

if ($fMun !== '') {
    $where[] = "UPPER(TRIM(b.municipio)) = UPPER(TRIM(:mun))";
    $params[':mun'] = $fMun;
}
if ($fReg !== '') {
    if ($fReg === '__sem__') {
        $where[] = "1=0";
    } else {
        $where[] = "rb.nome = :reg";
        $params[':reg'] = $fReg;
    }
}
if ($temTipoVoto && $fTipo !== '') {
    $where[] = "b.tipo_voto = :tipo";
    $params[':tipo'] = $fTipo;
}
if ($fBusca !== '') {
    $where[] = "b.nome LIKE :busca";
    $params[':busca'] = '%' . $fBusca . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

switch ($fOrdem) {
    case 'municipio': $orderBy = "ORDER BY b.municipio, b.nome"; break;
    case 'regiao':    $orderBy = "ORDER BY rb.id, b.nome"; break;
    case 'tipo_voto':
        $orderBy = $temTipoVoto
            ? "ORDER BY b.tipo_voto, b.nome"
            : "ORDER BY b.nome";
        break;
    case 'bairro':
    default:          $orderBy = "ORDER BY b.nome, b.municipio"; break;
}

$selTipo = $temTipoVoto ? ", b.tipo_voto" : ", NULL AS tipo_voto";

$sql = "
    SELECT b.id, b.nome, b.municipio, b.regiao_bairro_id,
           rb.nome AS regiao_nome
           $selTipo
      FROM bairros b
      LEFT JOIN regioes_bairro rb ON rb.id = b.regiao_bairro_id
      $whereSql
      $orderBy
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listaBairros = $stmt->fetchAll();

// ============================================================
// BAIRROS DE secoes_enderecos NÃO CADASTRADOS em `bairros`
// ============================================================
$bairrosSemCadastro = [];
if ($temSecEnd && $COL_BAIRRO_SEC !== null) {
    $sqlFalta = "
        SELECT DISTINCT
            UPPER(TRIM(s.`$COL_BAIRRO_SEC`)) COLLATE utf8mb4_general_ci AS bairro_secao,
            UPPER(TRIM(s.nomeMunicipio))     COLLATE utf8mb4_general_ci AS municipio_secao
          FROM secoes_enderecos s
         WHERE s.`$COL_BAIRRO_SEC` IS NOT NULL
           AND s.`$COL_BAIRRO_SEC` <> ''
    ";
    $pFalta = [];
    if ($fMun !== '') {
        $sqlFalta .= " AND UPPER(TRIM(s.nomeMunicipio)) = UPPER(TRIM(:mun))";
        $pFalta[':mun'] = $fMun;
    }
    $sqlFalta .= " ORDER BY municipio_secao, bairro_secao";

    try {
        $stmt = $pdo->prepare($sqlFalta);
        $stmt->execute($pFalta);
        $todosSec = $stmt->fetchAll();

        $cadastrados = [];
        foreach ($pdo->query("SELECT nome, municipio FROM bairros") as $b) {
            $k = mb_strtoupper(trim($b['nome']), 'UTF-8') . '|' .
                 mb_strtoupper(trim($b['municipio']), 'UTF-8');
            $cadastrados[$k] = true;
        }

        foreach ($todosSec as $r) {
            $bs = (string)$r['bairro_secao'];
            $ms = (string)$r['municipio_secao'];
            $k  = mb_strtoupper(trim($bs), 'UTF-8') . '|' .
                  mb_strtoupper(trim($ms), 'UTF-8');
            if (!isset($cadastrados[$k])) {
                $bairrosSemCadastro[] = [
                    'bairro'    => $bs,
                    'municipio' => $ms,
                ];
            }
        }
    } catch (Exception $e) {
        $bairrosSemCadastro = [];
    }
}

// ============================================================
// ESTATÍSTICAS
// ============================================================
$qtdTotal     = count($listaBairros);
$qtdPorRegiao = [];
$qtdPorTipo   = [];
foreach ($listaBairros as $b) {
    $rn = $b['regiao_nome'] ?: '— sem —';
    $qtdPorRegiao[$rn] = ($qtdPorRegiao[$rn] ?? 0) + 1;

    if ($temTipoVoto) {
        $tp = $b['tipo_voto'] ?: '—';
        $qtdPorTipo[$tp] = ($qtdPorTipo[$tp] ?? 0) + 1;
    }
}
$qtdSemCadastro = count($bairrosSemCadastro);

// ============================================================
// KPI ESPECIAL: DISTRIBUIÇÃO POR TIPO DE VOTO
// ============================================================
$kpiTipo = [
    'Opinião'   => 0,
    'Estrutura' => 0,
];
$totalTipo = 0;
$pctTipo   = [];

if ($temTipoVoto) {
    foreach ($listaBairros as $b) {
        $tp = (string)($b['tipo_voto'] ?? '');
        if (isset($kpiTipo[$tp])) {
            $kpiTipo[$tp]++;
            $totalTipo++;
        }
    }
    foreach ($kpiTipo as $tp => $qtd) {
        $pctTipo[$tp] = $totalTipo > 0 ? ($qtd / $totalTipo) * 100 : 0;
    }
}

// ============================================================
// URL para preservar filtros
// ============================================================
$queryAtual = $_GET;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Editor de Regiões dos Bairros</title>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom:6px; }
    h2 { margin-top:30px; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    /* Barra de usuário */
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

    .badge-admin { display:inline-flex; align-items:center; gap:5px;
        background:#fff3cd; color:#856404; padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; letter-spacing:.3px; }
    .badge-super { display:inline-flex; align-items:center; gap:5px;
        background:#dc3545; color:#fff; padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; letter-spacing:.3px; }
    .badge-visitante { display:inline-flex; align-items:center; gap:5px;
        background:#e9ecef; color:#495057; padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; letter-spacing:.3px; }
    .badge-leitura { display:inline-flex; align-items:center; gap:5px;
        background:#f8d7da; color:#721c24; padding:3px 10px; border-radius:12px;
        font-size:11px; font-weight:bold; letter-spacing:.3px; }

    /* Mensagens */
    .msg { padding:12px 16px; border-radius:6px; margin-bottom:18px; font-size:14px; }
    .msg.ok   { background:#d4edda; color:#155724; border-left:5px solid #28a745; }
    .msg.erro { background:#f8d7da; color:#721c24; border-left:5px solid #dc3545; }

    /* Cards de estatística */
    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; align-items: stretch; }
    .kpi {
        background:#fff; border-radius:8px; padding:14px 22px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:170px;
        border-left:5px solid #0069d9;
    }
    .kpi .rotulo { font-size:11px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:24px; font-weight:bold; color:#0069d9; margin-top:2px; }
    .kpi .sub    { font-size:11px; color:#888; margin-top:2px; }

    /* ============================================================
       KPI especial para tipo_voto
       ============================================================ */
    .kpi.kpi-tipo-voto {
        min-width: 340px;
        max-width: 440px;
        flex: 1 1 340px;
        padding: 14px 20px 12px;
        border-left: 5px solid #6c757d;
        background: linear-gradient(180deg, #ffffff 0%, #fafbff 100%);
    }

    .kpi-tipo-voto .barra-tipos {
        display: flex;
        height: 22px;
        border-radius: 6px;
        overflow: hidden;
        margin: 10px 0 12px;
        box-shadow: inset 0 1px 2px rgba(0,0,0,.08);
        background: #f0f2f5;
    }
    .kpi-tipo-voto .barra-tipos .seg {
        display: block;
        height: 100%;
        transition: width .4s ease;
        position: relative;
    }
    .kpi-tipo-voto .barra-tipos .seg + .seg {
        border-left: 2px solid #fff;
    }

    .kpi-tipo-voto .legenda-tipos {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .kpi-tipo-voto .legenda-tipos .item-tipo {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        line-height: 1.4;
    }
    .kpi-tipo-voto .legenda-tipos .item-tipo.total {
        border-top: 1px dashed #e3e6ec;
        padding-top: 6px;
        margin-top: 4px;
        font-weight: bold;
    }
    .kpi-tipo-voto .legenda-tipos .bolinha {
        width: 10px; height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .kpi-tipo-voto .legenda-tipos .nome-tipo {
        color: #333;
        min-width: 72px;
    }
    .kpi-tipo-voto .legenda-tipos .qtd-tipo {
        font-weight: bold;
        margin-left: auto;
    }
    .kpi-tipo-voto .legenda-tipos .pct-tipo {
        color: #888;
        font-size: 11.5px;
        min-width: 56px;
        text-align: right;
    }
    .kpi.kpi-tipo-voto .rotulo {
        color: #555;
        font-size: 11px;
    }

    /* Filtros */
    form.filtros-form { background:#fff; padding:15px; border-radius:8px;
           box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px; }
    .filtros { display:flex; flex-wrap:wrap; align-items:center; gap:10px; }
    .filtros label { font-weight:bold; font-size:13px; }
    .filtros select, .filtros input[type=text], .filtros button {
        padding:6px 8px; border-radius:4px; border:1px solid #ccc; font-size:14px;
        font-family: inherit;
    }
    .filtros input[type=text] { width: 220px; }
    .btn-consultar { background:#0069d9; color:#fff; border:none; cursor:pointer;
                     font-weight:bold; padding:7px 14px; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:#6c757d; color:#fff; text-decoration:none;
                  padding:7px 14px; border-radius:4px; font-size:14px; }
    .btn-limpar:hover { background:#5a6268; }

    /* Select2 */
    .select2-container { margin: 0; }
    .select2-container--default .select2-selection--single {
        height: 36px; padding: 3px 6px;
        border: 1px solid #ccc; border-radius: 4px; background:#fff;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 28px; color:#333;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 34px; }
    .select2-search--dropdown .select2-search__field { padding: 6px; font-size:14px; }
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #0069d9;
    }

    /* Tabela */
    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:middle; }
    th { background:#0069d9; color:#fff; font-size:13px; position: sticky; top: 0; z-index: 2; }
    tr:nth-child(even) { background:#f8f9fb; }
    tr:hover { background:#eef4fc; }
    .num { text-align:right; }
    .col-check { width: 40px; text-align:center; }
    .col-id    { width: 60px; color:#888; font-family: monospace; }

    /* Selects inline */
    select.sel-regiao,
    select.sel-tipo {
        padding: 4px 6px; border-radius:4px; border:1px solid #ccc;
        font-size: 13px; background:#fff; min-width: 130px;
    }
    select.sel-regiao.alterado,
    select.sel-tipo.alterado {
        background:#fff8e1; border-color:#f0c36d; font-weight:bold;
    }

    /* Tags coloridas */
    .regiao-tag {
        display:inline-block; color:#fff;
        padding:3px 10px; border-radius:12px;
        font-weight:bold; font-size:11px;
        letter-spacing:.3px; text-transform:uppercase;
    }
    .tipo-tag {
        display:inline-block; color:#fff;
        padding:3px 10px; border-radius:12px;
        font-weight:bold; font-size:11px;
        letter-spacing:.3px;
    }
    .tipo-tag.tipo-vazio {
        background:#e9ecef !important; color:#6c757d;
        font-style: italic; text-transform: none;
    }

    /* Barra de ações flutuante */
    .barra-acoes {
        position: sticky; bottom: 10px; z-index: 5;
        background: #fff;
        padding: 10px 16px; border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,.15);
        display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        margin-top: 18px; border-left: 5px solid #28a745;
    }
    .barra-acoes .info-edit {
        font-size: 13px; color: #856404;
        background: #fff3cd; padding: 4px 12px; border-radius: 8px;
        font-weight: bold;
    }
    .btn-salvar {
        background:#28a745; color:#fff; border:none; cursor:pointer;
        font-weight:bold; padding: 8px 18px; border-radius:4px;
        font-size: 14px;
    }
    .btn-salvar:hover { background:#218838; }

    /* Aviso de bairros não cadastrados */
    .painel-pendentes {
        background:#fff8e1; border-left:5px solid #ffc107;
        padding: 14px 18px; border-radius:8px; margin-bottom: 20px;
    }
    .painel-pendentes h3 {
        margin: 0 0 8px 0; color:#856404; font-size:16px;
    }
    .painel-pendentes p { margin: 0 0 10px 0; font-size:13px; color:#666; }
    .tags-pendentes {
        display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px;
        max-height: 180px; overflow-y:auto;
    }
    .tag-pendente {
        display:inline-block; background:#fff;
        border:1px solid #f0c36d; padding:3px 10px; border-radius:12px;
        font-size:12px; color:#856404;
    }
    .tag-pendente .mun {
        color:#999; font-weight:normal; font-size:11px;
    }

    /* Formulário de adicionar */
    .card-add {
        background:#fff; border-radius:8px; padding: 16px 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,.1);
        margin-bottom: 20px; border-left: 5px solid #17a2b8;
    }
    .card-add h3 { margin: 0 0 12px 0; font-size: 16px; color: #17a2b8; }
    .card-add .linha {
        display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;
    }
    .card-add .campo label {
        display: block; font-size: 12px; font-weight: bold;
        color: #666; margin-bottom: 4px;
    }
    .card-add input[type=text] {
        padding: 7px 10px; border-radius:4px; border:1px solid #ccc;
        font-size: 14px; min-width: 220px;
    }
    .card-add select {
        padding: 7px 10px; border-radius:4px; border:1px solid #ccc;
        font-size: 14px; min-width: 180px;
    }
    .btn-add {
        background:#17a2b8; color:#fff; border:none; cursor:pointer;
        font-weight:bold; padding: 8px 18px; border-radius:4px;
        font-size: 14px;
    }
    .btn-add:hover { background:#117a8b; }

    .aviso-info {
        background:#e7f3ff; border-left:5px solid #0069d9; color:#004085;
        padding:12px 16px; border-radius:6px; margin-bottom:20px; font-size:13.5px;
    }
    .aviso-warn {
        background:#fff3cd; border-left:5px solid #ffc107; color:#856404;
        padding:12px 16px; border-radius:6px; margin-bottom:20px; font-size:13.5px;
    }

    .btn-excluir-mini {
        background: transparent; color: #dc3545; border: 1px solid #f5c6cb;
        cursor:pointer; padding: 3px 8px; border-radius: 4px;
        font-size: 12px; font-weight: bold;
    }
    .btn-excluir-mini:hover { background: #f8d7da; color:#721c24; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<div class="barra-usuario <?= $isAdmin ? ($__ehSuper ? 'super' : 'admin') : '' ?>">
    <span>
        👤 <span class="saudacao">Olá,</span>
        <span class="nome-user"><?= h($__u['nome'] ?? '—') ?></span>
    </span>
    <span class="perfil-tag <?= h($__classePerfil) ?>">
        <?= h($__rotuloPerfil) ?>
    </span>
    <?php if ($isAdmin): ?>
        <span class="<?= $__ehSuper ? 'badge-super' : 'badge-admin' ?>">
            <?= $__ehSuper ? '🔓 superadmin' : '🔓 administrador' ?>
        </span>
    <?php elseif ($__ehAdmin && !$__ehDono): ?>
        <span class="badge-visitante">👁️ admin (não autorizado a editar)</span>
    <?php else: ?>
        <span class="badge-visitante">👁️ visitante</span>
    <?php endif; ?>
</div>

<h1>🗂️ Editor de Regiões dos Bairros</h1>
<div style="color:#666;font-size:13px;margin-bottom:18px;">
    Altere a <strong>região</strong> e o <strong>tipo de voto</strong> de cada bairro,
    adicione novos bairros e veja quais bairros das seções ainda não estão cadastrados.
    As alterações são aplicadas ao clicar em <strong>Salvar alterações</strong>.
</div>

<?php if (!$podeEditar): ?>
    <div class="aviso-info">
        ⚠️ Você está no modo <strong>somente leitura</strong>. Apenas administradores
        autorizados podem editar as regiões.
    </div>
<?php endif; ?>

<?php if (!$temTipoVoto): ?>
    <div class="aviso-warn">
        ⚠️ A coluna <code>tipo_voto</code> não existe na tabela <code>bairros</code>.
        Os campos de tipo de voto serão ocultados.
        <br>Para habilitar, rode no banco:
        <code>ALTER TABLE bairros ADD COLUMN tipo_voto ENUM('Opinião','Estrutura') NOT NULL DEFAULT 'Estrutura' AFTER regiao_bairro_id;</code>
    </div>
<?php endif; ?>

<?php if ($msg !== ''): ?>
    <div class="msg <?= $msgTipo ?>"><?= $msg ?></div>
<?php endif; ?>

<!-- ============================================================
     ESTATÍSTICAS
     ============================================================ -->
<div class="kpis">
    <div class="kpi">
        <div class="rotulo">Bairros listados</div>
        <div class="valor"><?= number_format($qtdTotal, 0, ',', '.') ?></div>
    </div>

    <?php foreach ($regioes as $r):
        $nm = $r['nome'];
        $qtd = $qtdPorRegiao[$nm] ?? 0;
        if ($qtd === 0 && $fMun !== '') continue;
    ?>
        <div class="kpi" style="border-left-color: <?= corRegiao($nm) ?>;">
            <div class="rotulo"><?= h($nm) ?></div>
            <div class="valor" style="color: <?= corRegiao($nm) ?>;">
                <?= number_format($qtd, 0, ',', '.') ?>
            </div>
            <div class="sub">bairro(s)</div>
        </div>
    <?php endforeach; ?>

    <!-- ============================================================
         KPI ESPECIAL: DISTRIBUIÇÃO POR TIPO DE VOTO
         ============================================================ -->
    <?php if ($temTipoVoto && $totalTipo > 0): ?>
        <div class="kpi kpi-tipo-voto">
            <div class="rotulo">Distribuição por tipo de voto</div>

            <div class="barra-tipos">
                <?php foreach ($kpiTipo as $tp => $qtd):
                    $pct = $pctTipo[$tp];
                    if ($pct <= 0) continue;
                    $cor = corTipoVoto($tp);
                ?>
                    <span class="seg"
                          style="width: <?= number_format($pct, 3, '.', '') ?>%;
                                 background: <?= $cor ?>;"
                          title="<?= h($tp) ?>: <?= number_format($qtd, 0, ',', '.') ?> bairro(s) (<?= number_format($pct, 1, ',', '.') ?>%)">
                    </span>
                <?php endforeach; ?>
            </div>

            <div class="legenda-tipos">
                <?php foreach ($kpiTipo as $tp => $qtd):
                    $pct = $pctTipo[$tp];
                    $cor = corTipoVoto($tp);
                ?>
                    <div class="item-tipo">
                        <span class="bolinha" style="background: <?= $cor ?>;"></span>
                        <span class="nome-tipo"><?= h($tp) ?></span>
                        <span class="qtd-tipo" style="color: <?= $cor ?>;">
                            <?= number_format($qtd, 0, ',', '.') ?>
                        </span>
                        <span class="pct-tipo">
                            (<?= number_format($pct, 1, ',', '.') ?>%)
                        </span>
                    </div>
                <?php endforeach; ?>

                <div class="item-tipo total">
                    <span class="nome-tipo" style="color:#666;">Total</span>
                    <span class="qtd-tipo" style="color:#333;">
                        <?= number_format($totalTipo, 0, ',', '.') ?>
                    </span>
                    <span class="pct-tipo">bairro(s)</span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($qtdSemCadastro > 0): ?>
        <div class="kpi" style="border-left-color:#ffc107;">
            <div class="rotulo">⚠️ Sem cadastro</div>
            <div class="valor" style="color:#856404;"><?= number_format($qtdSemCadastro, 0, ',', '.') ?></div>
            <div class="sub">bairros nas seções</div>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     FORM DE FILTRO
     ============================================================ -->
<form method="get" action="bairro_regiao_editor.php" class="filtros-form">
    <div class="filtros">
        <label for="municipio">Município:</label>
        <select name="municipio" id="municipio" style="min-width:200px;">
            <option value="">Todos</option>
            <?php foreach ($municipiosLista as $m): ?>
                <option value="<?= h($m) ?>" <?= $m === $fMun ? 'selected' : '' ?>>
                    <?= h($m) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="regiao">Região:</label>
        <select name="regiao" id="regiao" style="min-width:170px;">
            <option value="">Todas</option>
            <?php foreach ($regioes as $r): ?>
                <option value="<?= h($r['nome']) ?>"
                    <?= $r['nome'] === $fReg ? 'selected' : '' ?>>
                    <?= h($r['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($temTipoVoto): ?>
        <label for="tipo_voto">Tipo:</label>
        <select name="tipo_voto" id="tipo_voto" style="min-width:150px;">
            <option value="">Todos</option>
            <?php foreach ($TIPOS_VOTO_VALIDOS as $tp): ?>
                <option value="<?= h($tp) ?>"
                    <?= $tp === $fTipo ? 'selected' : '' ?>>
                    <?= h($tp) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <label for="busca">Buscar bairro:</label>
        <input type="text" name="busca" id="busca"
               placeholder="ex: BOA"
               value="<?= h($fBusca) ?>">

        <label for="ordem">Ordenar por:</label>
        <select name="ordem" id="ordem">
            <option value="bairro"    <?= $fOrdem === 'bairro'    ? 'selected' : '' ?>>Bairro (A-Z)</option>
            <option value="municipio" <?= $fOrdem === 'municipio' ? 'selected' : '' ?>>Município</option>
            <option value="regiao"    <?= $fOrdem === 'regiao'    ? 'selected' : '' ?>>Região</option>
            <?php if ($temTipoVoto): ?>
            <option value="tipo_voto" <?= $fOrdem === 'tipo_voto' ? 'selected' : '' ?>>Tipo de voto</option>
            <?php endif; ?>
        </select>

        <button type="submit" class="btn-consultar">Filtrar</button>
        <a href="bairro_regiao_editor.php" class="btn-limpar">Limpar</a>
    </div>
</form>

<!-- ============================================================
     PAINEL DE BAIRROS NÃO CADASTRADOS
     ============================================================ -->
<?php if ($qtdSemCadastro > 0): ?>
    <div class="painel-pendentes">
        <h3>⚠️ <?= number_format($qtdSemCadastro, 0, ',', '.') ?> bairro(s) nas seções sem cadastro em <code>bairros</code></h3>
        <p>
            Estes bairros existem em <code>secoes_enderecos</code>, mas não estão
            cadastrados na tabela <code>bairros</code>. Cadastre-os para que
            apareçam com região no <a href="zonas_bairros.php">relatório de bairros</a>.
        </p>

        <?php if ($podeEditar): ?>
            <?php
            $limite = 60;
            $mostrar = array_slice($bairrosSemCadastro, 0, $limite);
            ?>
            <div class="tags-pendentes">
                <?php foreach ($mostrar as $bp): ?>
                    <span class="tag-pendente"
                          data-bairro="<?= h($bp['bairro']) ?>"
                          data-municipio="<?= h($bp['municipio']) ?>"
                          style="cursor:pointer;"
                          title="Clique para adicionar como novo bairro">
                        <?= h($bp['bairro']) ?>
                        <span class="mun">— <?= h($bp['municipio']) ?></span>
                    </span>
                <?php endforeach; ?>
                <?php if (count($bairrosSemCadastro) > $limite): ?>
                    <span class="tag-pendente" style="background:#f5f5f5; color:#666;">
                        + <?= (count($bairrosSemCadastro) - $limite) ?> outro(s)...
                    </span>
                <?php endif; ?>
            </div>
            <p style="font-size:12px; color:#888; margin:0;">
                💡 Dica: clique em uma tag para preencher o formulário abaixo com o nome e o município.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ============================================================
     FORM DE ADICIONAR NOVO BAIRRO
     ============================================================ -->
<?php if ($podeEditar): ?>
<div class="card-add">
    <h3>➕ Adicionar novo bairro</h3>
    <form method="post" id="form-add">
        <input type="hidden" name="acao" value="adicionar">
        <div class="linha">
            <div class="campo">
                <label for="novo_nome">Nome do bairro</label>
                <input type="text" name="novo_nome" id="novo_nome"
                       placeholder="ex: BOA VIAGEM" required maxlength="100">
            </div>
            <div class="campo">
                <label for="novo_municipio">Município</label>
                <input type="text" name="novo_municipio" id="novo_municipio"
                       placeholder="ex: RECIFE" required maxlength="50"
                       list="lista-municipios-add">
                <datalist id="lista-municipios-add">
                    <?php foreach ($municipiosLista as $m): ?>
                        <option value="<?= h($m) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="campo">
                <label for="novo_regiao_id">Região</label>
                <select name="novo_regiao_id" id="novo_regiao_id" required>
                    <option value="">— Selecione —</option>
                    <?php foreach ($regioes as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"><?= h($r['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($temTipoVoto): ?>
            <div class="campo">
                <label for="novo_tipo_voto">Tipo de voto</label>
                <select name="novo_tipo_voto" id="novo_tipo_voto" required>
                    <?php foreach ($TIPOS_VOTO_VALIDOS as $tp): ?>
                        <option value="<?= h($tp) ?>"
                            <?= $tp === 'Estrutura' ? 'selected' : '' ?>>
                            <?= h($tp) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="campo">
                <button type="submit" class="btn-add">Adicionar</button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ============================================================
     LISTA DE BAIRROS EDITÁVEIS
     ============================================================ -->
<?php if (!$listaBairros): ?>

    <div class="msg erro">
        Nenhum bairro encontrado para os filtros.
        <?php if ($fMun === '' && $fReg === '' && $fTipo === '' && $fBusca === ''): ?>
            <br>Talvez a tabela <code>bairros</code> esteja vazia. Use o formulário acima
            para adicionar bairros.
        <?php endif; ?>
    </div>

<?php else: ?>

    <h2>📋 Bairros cadastrados (<?= number_format($qtdTotal, 0, ',', '.') ?>)</h2>

    <form method="post" id="form-edit" action="bairro_regiao_editor.php?<?= h(http_build_query($queryAtual)) ?>">
        <input type="hidden" name="acao" value="salvar">

        <table>
            <thead>
                <tr>
                    <th class="col-check">
                        <input type="checkbox" id="check-all" title="Marcar todos">
                    </th>
                    <th class="col-id">ID</th>
                    <th>Bairro</th>
                    <th style="width:160px;">Município</th>
                    <th style="width:220px;">Região atual → Nova região</th>
                    <?php if ($temTipoVoto): ?>
                    <th style="width:220px;">Tipo atual → Novo tipo</th>
                    <?php endif; ?>
                    <th style="width:60px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($listaBairros as $b):
                    $id      = (int)$b['id'];
                    $nome    = $b['nome'];
                    $mun     = $b['municipio'];
                    $regId   = (int)$b['regiao_bairro_id'];
                    $regNome = $b['regiao_nome'] ?: '—';
                    $tpAtual = $temTipoVoto ? (string)($b['tipo_voto'] ?? '') : '';
                ?>
                    <tr data-id="<?= $id ?>">
                        <td class="col-check">
                            <input type="checkbox" class="chk-linha"
                                   name="bairro_id[]" value="<?= $id ?>" checked>
                        </td>
                        <td class="col-id"><?= $id ?></td>
                        <td><strong><?= h($nome) ?></strong></td>
                        <td><?= h($mun) ?></td>
                        <td>
                            <span class="regiao-tag"
                                  style="background: <?= corRegiao($regNome) ?>;">
                                <?= h($regNome) ?>
                            </span>
                            &nbsp;→&nbsp;
                            <select name="regiao_id[<?= $id ?>]" class="sel-regiao"
                                    data-id="<?= $id ?>"
                                    <?= !$podeEditar ? 'disabled' : '' ?>>
                                <?php foreach ($regioes as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>"
                                        <?= (int)$r['id'] === $regId ? 'selected' : '' ?>>
                                        <?= h($r['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php if ($temTipoVoto): ?>
                        <td>
                            <?php if ($tpAtual !== ''): ?>
                                <span class="tipo-tag"
                                      style="background: <?= corTipoVoto($tpAtual) ?>;">
                                    <?= h($tpAtual) ?>
                                </span>
                            <?php else: ?>
                                <span class="tipo-tag tipo-vazio">— sem —</span>
                            <?php endif; ?>
                            &nbsp;→&nbsp;
                            <select name="tipo_voto[<?= $id ?>]" class="sel-tipo"
                                    data-id="<?= $id ?>"
                                    <?= !$podeEditar ? 'disabled' : '' ?>>
                                <?php foreach ($TIPOS_VOTO_VALIDOS as $tp): ?>
                                    <option value="<?= h($tp) ?>"
                                        <?= $tp === $tpAtual ? 'selected' : '' ?>>
                                        <?= h($tp) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?php if ($podeEditar): ?>
                                <button type="button" class="btn-excluir-mini"
                                        data-id="<?= $id ?>"
                                        data-nome="<?= h($nome) ?>"
                                        data-mun="<?= h($mun) ?>"
                                        title="Excluir este bairro">
                                    ✕
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($podeEditar): ?>
        <div class="barra-acoes">
            <span class="info-edit" id="info-edit">0 alteração(ões) pendente(s)</span>
            <button type="submit" class="btn-salvar">💾 Salvar alterações</button>
            <button type="button" class="btn-limpar" id="btn-descartar"
                    style="border:none; cursor:pointer;">↺ Descartar</button>
            <span style="font-size:12px; color:#888; margin-left:auto;">
                Total: <?= number_format($qtdTotal, 0, ',', '.') ?> bairro(s)
            </span>
        </div>
        <?php endif; ?>
    </form>

<?php endif; ?>

<!-- ============================================================
     FORM INVISÍVEL PARA EXCLUIR
     ============================================================ -->
<?php if ($podeEditar): ?>
<form method="post" id="form-excluir" style="display:none;">
    <input type="hidden" name="acao" value="excluir">
    <input type="hidden" name="excluir_id" id="excluir_id" value="">
</form>
<?php endif; ?>

<!-- ============================================================
     FORM INVISÍVEL PARA CRIAR REGIÃO (opcional)
     ============================================================ -->
<?php if ($podeEditar): ?>
<details style="margin-top:24px; background:#fff; padding:10px 16px; border-radius:8px;
                box-shadow:0 1px 3px rgba(0,0,0,.08);">
    <summary style="cursor:pointer; font-weight:bold; color:#0069d9;">
        ➕ Criar nova região
    </summary>
    <form method="post" style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <input type="hidden" name="acao" value="add_regiao">
        <div class="campo">
            <label for="nome_regiao" style="display:block; font-size:12px; font-weight:bold; color:#666; margin-bottom:4px;">
                Nome da região
            </label>
            <input type="text" name="nome_regiao" id="nome_regiao"
                   placeholder="ex: Zona Leste" required
                   style="padding:7px 10px; border-radius:4px; border:1px solid #ccc; font-size:14px; min-width:220px;">
        </div>
        <button type="submit" class="btn-add">Criar região</button>
    </form>
</details>
<?php endif; ?>

<!-- jQuery + Select2 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
    (function () {
        if (typeof jQuery === 'undefined') return;

        // ==========================================================
        // Select2 nos filtros
        // ==========================================================
        if (jQuery.fn.select2) {
            function removerAcentos(s) {
                return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }
            function matcherSemAcento(params, data) {
                if (jQuery.trim(params.term) === '') return data;
                const termo = removerAcentos(params.term.toLowerCase());
                const texto = removerAcentos((data.text || '').toLowerCase());
                return texto.indexOf(termo) > -1 ? data : null;
            }
            function initSel($el, ph) {
                if (!$el || !$el.length) return;
                $el.select2({
                    language: 'pt-BR',
                    placeholder: ph,
                    allowClear: true,
                    width: 'resolve',
                    matcher: matcherSemAcento
                });
            }
            initSel(jQuery('#municipio'), 'Buscar município...');
            initSel(jQuery('#regiao'),    'Buscar região...');
            initSel(jQuery('#tipo_voto'), 'Buscar tipo...');
            initSel(jQuery('#ordem'),     'Ordenar por...');
            initSel(jQuery('#novo_regiao_id'), '— Selecione —');
            initSel(jQuery('#novo_tipo_voto'), '— Selecione —');
        }

        // ==========================================================
        // Marcar / desmarcar todos
        // ==========================================================
        const $checkAll = jQuery('#check-all');
        if ($checkAll.length) {
            $checkAll.on('change', function () {
                jQuery('.chk-linha').prop('checked', this.checked);
                atualizarPendentes();
            });

            jQuery(document).on('change', '.chk-linha', function () {
                const total    = jQuery('.chk-linha').length;
                const marcados = jQuery('.chk-linha:checked').length;
                $checkAll.prop('checked', total > 0 && total === marcados);
                atualizarPendentes();
            });
        }

        // ==========================================================
        // Detectar alterações (região OU tipo_voto)
        // ==========================================================
        function atualizarPendentes() {
            let qtd = 0;

            jQuery('tr[data-id]').each(function () {
                const $tr = jQuery(this);
                let alterada = false;

                $tr.find('.sel-regiao, .sel-tipo').each(function () {
                    const $s = jQuery(this);
                    const origem = $s.data('origem');
                    if (origem !== undefined && String($s.val()) !== String(origem)) {
                        $s.addClass('alterado');
                        alterada = true;
                    } else {
                        $s.removeClass('alterado');
                    }
                });

                if (alterada) qtd++;
            });

            const $info = jQuery('#info-edit');
            if ($info.length) {
                if (qtd === 0) {
                    $info.text('0 alteração(ões) pendente(s)');
                    $info.css({background:'#e7f3ff', color:'#004085'});
                } else {
                    $info.text(qtd + ' linha(s) com alteração pendente');
                    $info.css({background:'#fff3cd', color:'#856404'});
                }
            }
        }

        jQuery('.sel-regiao, .sel-tipo').each(function () {
            jQuery(this).data('origem', jQuery(this).val());
        });
        atualizarPendentes();

        jQuery(document).on('change', '.sel-regiao, .sel-tipo', atualizarPendentes);

        // ==========================================================
        // Descartar alterações
        // ==========================================================
        jQuery('#btn-descartar').on('click', function () {
            if (confirm('Descartar todas as alterações não salvas?')) {
                window.location.reload();
            }
        });

        // ==========================================================
        // Excluir bairro
        // ==========================================================
        jQuery(document).on('click', '.btn-excluir-mini', function () {
            const id   = jQuery(this).data('id');
            const nome = jQuery(this).data('nome');
            const mun  = jQuery(this).data('mun');
            if (confirm('Excluir o bairro "' + nome + '" (' + mun + ')?\n\nEsta ação não pode ser desfeita.')) {
                jQuery('#excluir_id').val(id);
                jQuery('#form-excluir').submit();
            }
        });

        // ==========================================================
        // Adicionar bairro — clicar em tag pendente preenche o form
        // ==========================================================
        jQuery(document).on('click', '.tag-pendente', function () {
            const bairro = jQuery(this).data('bairro');
            const mun    = jQuery(this).data('municipio');
            if (!bairro) return;

            jQuery('#novo_nome').val(bairro);
            jQuery('#novo_municipio').val(mun);
            jQuery('#novo_regiao_id').focus();

            jQuery('html, body').animate({
                scrollTop: jQuery('#form-add').offset().top - 60
            }, 300);
        });

        // ==========================================================
        // Confirmação ao sair com alterações pendentes
        // ==========================================================
        let temAlteracoes = false;
        setInterval(function () {
            temAlteracoes = jQuery('.sel-regiao.alterado, .sel-tipo.alterado').length > 0;
        }, 500);

        jQuery(window).on('beforeunload', function (e) {
            if (temAlteracoes) {
                e.preventDefault();
                return '';
            }
        });

        jQuery('#form-edit').on('submit', function () {
            temAlteracoes = false;
        });
    })();
</script>

</body>
</html>