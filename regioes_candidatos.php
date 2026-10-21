<?php
// regioes_candidatos.php
// ------------------------------------------------------------
// Página dedicada à comparação de até 2 candidatos por região
// ------------------------------------------------------------
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';
date_default_timezone_set('America/Sao_Paulo');

// Nome deste arquivo — usado nas chamadas AJAX e no link "Limpar"
$nomeArquivo = basename(__FILE__);

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
$isAdmin        = ($__ehAdmin && $__ehDono);

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

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ------------------------------------------------------------
// Detectar colunas: ano, numero, e seção
// ------------------------------------------------------------
$colunasVotos = $pdo->query("SHOW COLUMNS FROM votos")->fetchAll(PDO::FETCH_COLUMN);
$temAno    = in_array('ano', $colunasVotos, true);
$temNumero = in_array('numero', $colunasVotos, true);

$candidatasSecao = [
    'numeroSecao','numero_secao','secao','nrSecao','NR_SECAO',
    'numSecao','num_secao','secaoNumero','secao_numero',
    'cdSecao','cd_secao','codSecao','cod_secao',
    'nr_secao','secao_num'
];
$colSecao = '';
foreach ($candidatasSecao as $cand) {
    if (in_array($cand, $colunasVotos, true)) { $colSecao = $cand; break; }
}
if ($colSecao === '') {
    foreach ($colunasVotos as $col) {
        $norm = strtolower($col);
        $norm = str_replace(
            ['ç','ã','á','à','â','é','ê','í','ó','õ','ú'],
            ['c','a','a','a','a','e','e','i','o','o','u'],
            $norm
        );
        if (strpos($norm, 'secao') !== false) {
            $colSecao = $col;
            break;
        }
    }
}
$temSecao = ($colSecao !== '');

// ============================================================
// AJAX — dados do gráfico
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'chart_regioes') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $chC1    = trim($_GET['c1']     ?? '');
        $chC2    = trim($_GET['c2']     ?? '');
        $chAno   = $temAno ? trim($_GET['ano'] ?? '') : '';
        $chCargo = trim($_GET['cargo']  ?? '');
        $chReg   = trim($_GET['regiao'] ?? '');

        if ($chC1 === '' && $chC2 === '') {
            echo json_encode(['ok' => false, 'msg' => 'Selecione ao menos 1 candidato.']);
            exit;
        }

        // ----- WHERE com filtro de candidato (para o gráfico) -----
        $w = [];
        $p = [];

        if ($temAno && $chAno !== '') {
            $w[]       = "v.ano = :ano";
            $p[':ano'] = (int)$chAno;
        }
        if ($chCargo !== '') {
            $w[]         = "v.nomeCargoPerg = :cargo";
            $p[':cargo'] = $chCargo;
        }
        if ($chReg !== '') {
            $w[] = "EXISTS (
                SELECT 1 FROM municipio_regiao mr
                 WHERE mr.nomeMunicipio COLLATE utf8mb4_general_ci
                     = v.nomeMunicipio  COLLATE utf8mb4_general_ci
                   AND mr.regiao_id = :regiao
            )";
            $p[':regiao'] = (int)$chReg;
        }

        $ph = [];
        if ($chC1 !== '') { $ph[] = ':c1'; $p[':c1'] = $chC1; }
        if ($chC2 !== '' && $chC2 !== $chC1) {
            $ph[] = ':c2'; $p[':c2'] = $chC2;
        }
        if ($ph) {
            $w[] = "v.nomeCandidatoResp IN (" . implode(',', $ph) . ")";
        }

        $whereCh = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        // ----- WHERE sem filtro de candidato (para os totais de zonas/seções) -----
        $wTot = [];
        $pTot = [];
        if ($temAno && $chAno !== '') {
            $wTot[]       = "v.ano = :ano";
            $pTot[':ano'] = (int)$chAno;
        }
        if ($chCargo !== '') {
            $wTot[]         = "v.nomeCargoPerg = :cargo";
            $pTot[':cargo'] = $chCargo;
        }
        if ($chReg !== '') {
            $wTot[] = "EXISTS (
                SELECT 1 FROM municipio_regiao mr
                 WHERE mr.nomeMunicipio COLLATE utf8mb4_general_ci
                     = v.nomeMunicipio  COLLATE utf8mb4_general_ci
                   AND mr.regiao_id = :regiao
            )";
            $pTot[':regiao'] = (int)$chReg;
        }
        $whereTot = $wTot ? 'WHERE ' . implode(' AND ', $wTot) : '';

        // ----- Query principal: votos por região × candidato -----
        $sqlCh = "
            SELECT
                COALESCE(r.nome, 'Sem região') AS regiao,
                v.nomeCandidatoResp AS candidato,
                SUM(v.qtdVotos) AS total
            FROM votos v
            LEFT JOIN municipio_regiao mr
              ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
               = v.nomeMunicipio  COLLATE utf8mb4_general_ci
            LEFT JOIN regioes r ON r.id = mr.regiao_id
            $whereCh
            GROUP BY r.nome, v.nomeCandidatoResp
        ";
        $stmt = $pdo->prepare($sqlCh);
        $stmt->execute($p);
        $rows = $stmt->fetchAll();

        // ----- Municípios / zonas / seções por candidato -----
        $colSecaoSel = $temSecao
            ? ", COUNT(DISTINCT v.`$colSecao`) AS qtd_secoes"
            : ", 0 AS qtd_secoes";

        $sqlMunZonSec = "
            SELECT
                v.nomeCandidatoResp AS candidato,
                COUNT(DISTINCT v.nomeMunicipio) AS qtd_municipios,
                COUNT(DISTINCT v.numeroZona)    AS qtd_zonas
                $colSecaoSel
            FROM votos v
            $whereCh
            GROUP BY v.nomeCandidatoResp
        ";
        $stmtMun = $pdo->prepare($sqlMunZonSec);
        $stmtMun->execute($p);

        $munPorCand    = [];
        $zonasPorCand  = [];
        $secoesPorCand = [];
        foreach ($stmtMun->fetchAll() as $r) {
            $munPorCand[$r['candidato']]    = (int)$r['qtd_municipios'];
            $zonasPorCand[$r['candidato']]  = (int)$r['qtd_zonas'];
            $secoesPorCand[$r['candidato']] = (int)$r['qtd_secoes'];
        }

        // ----- Totais gerais (filtros aplicados, sem filtro de candidato) -----
        $colSecaoTot = $temSecao
            ? ", COUNT(DISTINCT v.`$colSecao`) AS qtd_secoes"
            : ", 0 AS qtd_secoes";

        $sqlTot = "
            SELECT
                COUNT(DISTINCT v.numeroZona) AS qtd_zonas
                $colSecaoTot
            FROM votos v
            $whereTot
        ";
        $stmtTot = $pdo->prepare($sqlTot);
        $stmtTot->execute($pTot);
        $rowTot = $stmtTot->fetch();

        $totalZonasGeral  = (int)($rowTot['qtd_zonas']  ?? 0);
        $totalSecoesGeral = (int)($rowTot['qtd_secoes'] ?? 0);

        // ----- Monta estrutura por região -----
        $regioesSet = [];
        $porRegiao  = [];
        foreach ($rows as $r) {
            $reg  = $r['regiao'] ?: 'Sem região';
            $cand = $r['candidato'];
            $tot  = (int)$r['total'];

            $regioesSet[$reg] = true;
            if (!isset($porRegiao[$reg])) $porRegiao[$reg] = [];
            $porRegiao[$reg][$cand] = $tot;
        }

        $regioes = array_keys($regioesSet);
        usort($regioes, function ($a, $b) use ($porRegiao) {
            return array_sum($porRegiao[$b] ?? []) <=> array_sum($porRegiao[$a] ?? []);
        });

        $ordemCands = [];
        if ($chC1 !== '') $ordemCands[] = $chC1;
        if ($chC2 !== '' && $chC2 !== $chC1) $ordemCands[] = $chC2;

        $datasets = [];
        foreach ($ordemCands as $cand) {
            $valores = [];
            foreach ($regioes as $reg) {
                $valores[] = $porRegiao[$reg][$cand] ?? 0;
            }
            $datasets[] = [
                'nome'                => $cand,
                'valores'             => $valores,
                'total'               => array_sum($valores),
                'municipios_com_voto' => $munPorCand[$cand]    ?? 0,
                'zonas_com_voto'      => $zonasPorCand[$cand]  ?? 0,
                'secoes_com_voto'     => $secoesPorCand[$cand] ?? 0,
            ];
        }

        echo json_encode([
            'ok'                 => true,
            'regioes'            => $regioes,
            'datasets'           => $datasets,
            'total_zonas_geral'  => $totalZonasGeral,
            'total_secoes_geral' => $totalSecoesGeral,
            'tem_secao'          => $temSecao,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// AJAX — tabela detalhada por região × candidato
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'tabela_regioes') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $chC1    = trim($_GET['c1']     ?? '');
        $chC2    = trim($_GET['c2']     ?? '');
        $chAno   = $temAno ? trim($_GET['ano'] ?? '') : '';
        $chCargo = trim($_GET['cargo']  ?? '');
        $chReg   = trim($_GET['regiao'] ?? '');

        if ($chC1 === '' && $chC2 === '') {
            echo json_encode(['ok' => false, 'msg' => 'Selecione ao menos 1 candidato.']);
            exit;
        }

        $w = [];
        $p = [];

        if ($temAno && $chAno !== '') {
            $w[]       = "v.ano = :ano";
            $p[':ano'] = (int)$chAno;
        }
        if ($chCargo !== '') {
            $w[]         = "v.nomeCargoPerg = :cargo";
            $p[':cargo'] = $chCargo;
        }
        if ($chReg !== '') {
            $w[] = "EXISTS (
                SELECT 1 FROM municipio_regiao mr
                 WHERE mr.nomeMunicipio COLLATE utf8mb4_general_ci
                     = v.nomeMunicipio  COLLATE utf8mb4_general_ci
                   AND mr.regiao_id = :regiao
            )";
            $p[':regiao'] = (int)$chReg;
        }

        $ph = [];
        if ($chC1 !== '') { $ph[] = ':c1'; $p[':c1'] = $chC1; }
        if ($chC2 !== '' && $chC2 !== $chC1) {
            $ph[] = ':c2'; $p[':c2'] = $chC2;
        }
        if ($ph) {
            $w[] = "v.nomeCandidatoResp IN (" . implode(',', $ph) . ")";
        }

        $whereCh = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        $sql = "
            SELECT
                COALESCE(r.nome, 'Sem região') AS regiao,
                v.nomeCandidatoResp AS candidato,
                COUNT(DISTINCT v.nomeMunicipio) AS qtd_municipios,
                COUNT(DISTINCT v.numeroZona)    AS qtd_zonas,
                SUM(v.qtdVotos)                 AS total
            FROM votos v
            LEFT JOIN municipio_regiao mr
              ON mr.nomeMunicipio COLLATE utf8mb4_general_ci
               = v.nomeMunicipio  COLLATE utf8mb4_general_ci
            LEFT JOIN regioes r ON r.id = mr.regiao_id
            $whereCh
            GROUP BY r.nome, v.nomeCandidatoResp
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p);
        $rows = $stmt->fetchAll();

        $regioesSet = [];
        foreach ($rows as $r) {
            $reg = $r['regiao'] ?: 'Sem região';
            $regioesSet[$reg] = true;
        }
        $regioes = array_keys($regioesSet);
        usort($regioes, function ($a, $b) use ($rows) {
            $ta = 0; $tb = 0;
            foreach ($rows as $r) {
                $rg = $r['regiao'] ?: 'Sem região';
                if ($rg === $a) $ta += (int)$r['total'];
                if ($rg === $b) $tb += (int)$r['total'];
            }
            return $tb <=> $ta;
        });

        $ordemCands = [];
        if ($chC1 !== '') $ordemCands[] = $chC1;
        if ($chC2 !== '' && $chC2 !== $chC1) $ordemCands[] = $chC2;

        $linhas = [];
        foreach ($regioes as $reg) {
            $linha = ['regiao' => $reg, 'dados' => []];
            $totReg = 0;
            foreach ($ordemCands as $cand) {
                $encontrado = null;
                foreach ($rows as $r) {
                    $rg = $r['regiao'] ?: 'Sem região';
                    if ($rg === $reg && $r['candidato'] === $cand) {
                        $encontrado = $r;
                        break;
                    }
                }
                if ($encontrado) {
                    $linha['dados'][$cand] = [
                        'total'      => (int)$encontrado['total'],
                        'municipios' => (int)$encontrado['qtd_municipios'],
                        'zonas'      => (int)$encontrado['qtd_zonas'],
                    ];
                    $totReg += (int)$encontrado['total'];
                } else {
                    $linha['dados'][$cand] = ['total' => 0, 'municipios' => 0, 'zonas' => 0];
                }
            }
            $linha['total_regiao'] = $totReg;
            $linhas[] = $linha;
        }

        $totalPorCand = [];
        foreach ($ordemCands as $cand) {
            $tot = 0;
            foreach ($rows as $r) {
                if ($r['candidato'] === $cand) $tot += (int)$r['total'];
            }
            $totalPorCand[$cand] = $tot;
        }

        echo json_encode([
            'ok'             => true,
            'candidatos'     => $ordemCands,
            'linhas'         => $linhas,
            'totais'         => $totalPorCand,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// Listas para os selects
// ============================================================
$anosDisponiveis = [];
if ($temAno) {
    $anosDisponiveis = $pdo->query(
        "SELECT DISTINCT ano FROM votos WHERE ano IS NOT NULL ORDER BY ano DESC"
    )->fetchAll(PDO::FETCH_COLUMN);
}

$cargos = $pdo->query(
    "SELECT DISTINCT nomeCargoPerg FROM votos ORDER BY nomeCargoPerg"
)->fetchAll(PDO::FETCH_COLUMN);

$candidatos = $pdo->query(
    "SELECT DISTINCT nomeCandidatoResp FROM votos ORDER BY nomeCandidatoResp"
)->fetchAll(PDO::FETCH_COLUMN);

$regioes = $pdo->query(
    "SELECT id, nome FROM regioes ORDER BY nome"
)->fetchAll();

$c1     = $_GET['c1']     ?? '';
$c2     = $_GET['c2']     ?? '';
$ano    = $_GET['ano']    ?? '';
$cargo  = $_GET['cargo']  ?? '';
$regiao = $_GET['regiao'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Comparar Candidatos por Região</title>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; margin-bottom: 4px; }
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

    .filtro-card { background: #fff; border-radius: 8px; padding: 16px 20px;
                   box-shadow: 0 1px 3px rgba(0,0,0,.1); margin-bottom: 22px; }
    .filtro-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 22px; margin-bottom: 14px; }
    @media (max-width: 720px) { .filtro-grid { grid-template-columns: 1fr; } }
    .filtro-item { display: flex; flex-direction: column; gap: 5px; }
    .filtro-item label { font-size: 12px; font-weight: bold; color: #0b3d91;
                         letter-spacing: .3px; text-transform: uppercase; }
    .filtro-item .hint { font-size: 11px; color: #888; font-weight: normal;
                         text-transform: none; letter-spacing: 0; margin-left: 4px; }
    .filtro-item select { padding: 9px 12px; border-radius: 6px;
                          border: 1.5px solid #ccd; font-size: 14px; background: #fff;
                          width: 100%; box-sizing: border-box;
                          transition: border-color .12s, box-shadow .12s; }
    .filtro-item select:focus { outline: none; border-color: #0069d9;
                                box-shadow: 0 0 0 3px rgba(0,105,217,.15); }
    .filtro-secundario { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                         gap: 12px; padding-top: 14px; border-top: 1px dashed #e0e0e0; }
    .filtro-secundario .filtro-item label { font-size: 11px; color: #666; }
    .filtro-secundario .filtro-item select { padding: 7px 10px; font-size: 13px; }

    .botoes-linha { display: flex; gap: 10px; margin-top: 14px;
                    justify-content: flex-end; flex-wrap: wrap; }
    .btn-aplicar { background: #0069d9; color: #fff; padding: 9px 22px;
                   border-radius: 6px; border: none; cursor: pointer;
                   font-size: 14px; font-weight: bold; transition: background .12s; }
    .btn-aplicar:hover { background: #0053b3; }
    .btn-limpar { background: #6c757d; color: #fff; padding: 9px 18px;
                  border-radius: 6px; text-decoration: none;
                  font-size: 14px; display: inline-block; }
    .btn-limpar:hover { background: #5a6268; }

    .kpis-comparacao { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 22px; }
    @media (max-width: 720px) { .kpis-comparacao { grid-template-columns: 1fr; } }
    .kpi-candidato { background: #fff; border-radius: 8px; padding: 14px 20px;
                     box-shadow: 0 1px 3px rgba(0,0,0,.1); border-left: 6px solid #ccc; }
    .kpi-candidato.cor-1 { border-left-color: #0069d9; }
    .kpi-candidato.cor-2 { border-left-color: #fd7e14; }
    .kpi-candidato .kc-cor { display: inline-block; width: 12px; height: 12px;
                             border-radius: 50%; margin-right: 8px; vertical-align: middle; }
    .kpi-candidato.cor-1 .kc-cor { background: #0069d9; }
    .kpi-candidato.cor-2 .kc-cor { background: #fd7e14; }
    .kpi-candidato .kc-nome { font-size: 15px; font-weight: bold; color: #333;
                              margin-bottom: 6px; display: flex; align-items: center; }
    .kpi-candidato .kc-total { font-size: 26px; font-weight: bold; color: #0069d9; margin: 4px 0; }
    .kpi-candidato.cor-2 .kc-total { color: #fd7e14; }
    .kpi-candidato .kc-sub { font-size: 12px; color: #666; margin-top: 4px; }
    .kpi-candidato .kc-vazio { color: #888; font-style: italic; font-size: 13px; padding: 10px 0; }

    /* Grid de cobertura (regiões, municípios, zonas, seções) */
    .kc-cobertura {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px 14px;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px dashed #e5e5e5;
    }
    .kc-cob-item {
        display: flex;
        align-items: baseline;
        gap: 6px;
        font-size: 12px;
        color: #555;
        line-height: 1.35;
    }
    .kc-cob-num {
        display: inline-block;
        min-width: 26px;
        padding: 1px 6px;
        border-radius: 6px;
        background: #eaf3fb;
        color: #0069d9;
        font-weight: bold;
        font-size: 12.5px;
        text-align: center;
        font-family: 'Courier New', monospace;
    }
    .kpi-candidato.cor-2 .kc-cob-num { background: #fdefe2; color: #c8641a; }
    .kc-cob-lbl { font-size: 11.5px; color: #555; }
    .kc-cob-lbl b { color: #333; }
    .kc-cob-pct {
        display: inline-block;
        margin-left: 4px;
        font-size: 10.5px;
        padding: 1px 5px;
        border-radius: 8px;
        background: #f3f3f3;
        color: #666;
        font-weight: 600;
    }
    .kc-cob-pct.alto { background: #d4f5dd; color: #1c7a34; }
    .kc-cob-pct.medio { background: #fff3cd; color: #856404; }
    .kc-cob-pct.baixo { background: #f8d7da; color: #721c24; }

    .kc-mini-titulo {
        grid-column: 1 / -1;
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #999;
        margin: 4px 0 -2px;
        font-weight: 600;
    }

    .chart-card { background:#fff; border-radius:8px; padding: 18px 22px;
                  box-shadow: 0 1px 3px rgba(0,0,0,.1); margin-bottom: 26px; }
    .chart-card h3 { margin: 0 0 14px; font-size: 16px; color: #0069d9;
                     display: flex; align-items: center; gap: 10px;
                     border-bottom: 1px solid #eee; padding-bottom: 10px; }
    .chart-card h3 .chart-badge { font-size: 11px; padding: 2px 10px; border-radius: 12px;
                                  background: #e7f3ff; color: #0069d9; font-weight: 600;
                                  text-transform: uppercase; letter-spacing: .5px; }
    .chart-canvas-wrap { position: relative; height: 480px; min-height: 480px; }
    .chart-aviso { padding: 60px 20px; text-align: center; color: #888;
                   background: #fafafa; border-radius: 8px; font-size: 14px;
                   border: 1px dashed #ddd; }
    .chart-aviso .emoji { font-size: 42px; display: block; margin-bottom: 10px; opacity: .55; }

    .tabela-card { background: #fff; border-radius: 8px; padding: 18px 22px;
                   box-shadow: 0 1px 3px rgba(0,0,0,.1); margin-bottom: 26px; }
    .tabela-card h3 { margin: 0 0 14px; font-size: 16px; color: #0069d9;
                      border-bottom: 1px solid #eee; padding-bottom: 10px; }
    .tabela-regioes { width: 100%; border-collapse: collapse; font-size: 13px; }
    .tabela-regioes th { background: #0069d9; color: #fff; padding: 9px 10px;
                         text-align: left; border: 1px solid #0053b3; font-size: 12px; }
    .tabela-regioes th.num { text-align: right; }
    .tabela-regioes th.cand-1 { background: #0069d9; }
    .tabela-regioes th.cand-2 { background: #c8641a; }
    .tabela-regioes td { padding: 8px 10px; border: 1px solid #e6e6e6; vertical-align: top; }
    .tabela-regioes td.num { text-align: right; font-variant-numeric: tabular-nums; }
    .tabela-regioes tr:nth-child(even) { background: #f9f9f9; }
    .tabela-regioes tr:hover { background: #f4f8ff; }
    .tabela-regioes tfoot td { background: #e6f0fa; font-weight: bold;
                               border-top: 2px solid #0069d9; }
    .tabela-aviso { padding: 30px; text-align: center; color: #999; font-size: 13px; }

    .loading-spinner { display: inline-block; width: 18px; height: 18px;
                       border: 3px solid #e0e0e0; border-top-color: #0069d9;
                       border-radius: 50%; animation: spin .8s linear infinite;
                       margin-right: 8px; vertical-align: middle; }
    @keyframes spin { to { transform: rotate(360deg); } }

    .select2-container { width: 100% !important; }
    .select2-container--default .select2-selection--single {
        height: 40px; padding: 5px 8px; border: 1.5px solid #ccd; border-radius: 6px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 28px; color: #333; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 38px; }
    .select2-container--default.select2-container--focus .select2-selection--single {
        border-color: #0069d9; box-shadow: 0 0 0 3px rgba(0,105,217,.15); }
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #0069d9; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Comparar Candidatos por Região</h1>
<div class="subtitulo">
    Selecione até <b>2 candidatos</b> para comparar a distribuição de votos em cada região.
</div>

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
</div>

<div class="filtro-card">
    <div class="filtro-grid">
        <div class="filtro-item">
            <label for="selC1">🔵 Candidato 1 <span class="hint">(obrigatório)</span></label>
            <select id="selC1">
                <option value="">— Selecione o 1º candidato —</option>
                <?php foreach ($candidatos as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"
                        <?= ($c === $c1) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filtro-item">
            <label for="selC2">🟠 Candidato 2 <span class="hint">(opcional)</span></label>
            <select id="selC2">
                <option value="">— Nenhum (comparação simples) —</option>
                <?php foreach ($candidatos as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"
                        <?= ($c === $c2) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="filtro-secundario">
        <?php if ($temAno && $anosDisponiveis): ?>
        <div class="filtro-item">
            <label for="selAno">Ano</label>
            <select id="selAno">
                <option value="">Todos</option>
                <?php foreach ($anosDisponiveis as $a): ?>
                    <option value="<?= (int)$a ?>"
                        <?= ((string)$a === (string)$ano) ? 'selected' : '' ?>>
                        <?= (int)$a ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="filtro-item">
            <label for="selCargo">Cargo</label>
            <select id="selCargo">
                <option value="">Todos</option>
                <?php foreach ($cargos as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"
                        <?= ($c === $cargo) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filtro-item">
            <label for="selRegiao">Região</label>
            <select id="selRegiao">
                <option value="">Todas</option>
                <?php foreach ($regioes as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"
                        <?= ((string)$r['id'] === (string)$regiao) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="botoes-linha">
        <a href="<?= htmlspecialchars($nomeArquivo) ?>" class="btn-limpar">🧹 Limpar</a>
        <button type="button" class="btn-aplicar" id="btnAplicar">🔍 Comparar</button>
    </div>
</div>

<div class="kpis-comparacao" id="kpisComparacao">
    <div class="kpi-candidato cor-1" id="kpiC1">
        <div class="kc-nome"><span class="kc-cor"></span><span id="nomeC1">Candidato 1</span></div>
        <div class="kc-vazio">Aguardando seleção…</div>
    </div>
    <div class="kpi-candidato cor-2" id="kpiC2">
        <div class="kc-nome"><span class="kc-cor"></span><span id="nomeC2">Candidato 2</span></div>
        <div class="kc-vazio">Nenhum candidato selecionado.</div>
    </div>
</div>

<div class="chart-card">
    <h3>📊 Votos por região <span class="chart-badge">barras horizontais</span></h3>
    <div class="chart-aviso" id="chartAviso">
        <span class="emoji">📊</span>
        Selecione pelo menos um candidato e clique em <b>Comparar</b>.
    </div>
    <div class="chart-canvas-wrap" id="chartWrap" style="display:none;">
        <canvas id="graficoRegioes"></canvas>
    </div>
</div>

<div class="tabela-card">
    <h3>Detalhamento por região</h3>
    <div id="tabelaContainer">
        <div class="tabela-aviso">Selecione pelo menos um candidato para visualizar o detalhamento.</div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// ============================================================
// Nome deste arquivo (dinâmico)
// ============================================================
const SELF = <?= json_encode($nomeArquivo) ?>;

(function () {
    'use strict';

    const $selC1   = document.getElementById('selC1');
    const $selC2   = document.getElementById('selC2');
    const $selAno  = document.getElementById('selAno');
    const $selCargo= document.getElementById('selCargo');
    const $selReg  = document.getElementById('selRegiao');
    const $btn     = document.getElementById('btnAplicar');
    const $aviso   = document.getElementById('chartAviso');
    const $wrap    = document.getElementById('chartWrap');
    const $canvas  = document.getElementById('graficoRegioes');
    const $tab     = document.getElementById('tabelaContainer');
    const $kpiC1   = document.getElementById('kpiC1');
    const $kpiC2   = document.getElementById('kpiC2');

    let chart = null;

    // ------------------------------------------------------------
    // Select2
    // ------------------------------------------------------------
    function removerAcentos(s) {
        return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    function matcherSemAcento(params, data) {
        if (jQuery.trim(params.term) === '') return data;
        const termo = removerAcentos(params.term.toLowerCase());
        const texto = removerAcentos((data.text || '').toLowerCase());
        return texto.indexOf(termo) > -1 ? data : null;
    }
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('#selC1, #selC2').select2({
            language: 'pt-BR',
            allowClear: true,
            width: '100%',
            matcher: matcherSemAcento
        });
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------
    function fmtNum(n) { return Number(n || 0).toLocaleString('pt-BR'); }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        })[c]);
    }
    function pct(parte, total) {
        if (!total || total <= 0) return '0';
        return ((parte / total) * 100).toFixed(1).replace('.', ',');
    }
    function classePct(p) {
        // p como string "12,3" (pt-BR) — normaliza para número
        const v = parseFloat(String(p).replace(',', '.'));
        if (isNaN(v)) return '';
        if (v >= 66) return 'alto';
        if (v >= 33) return 'medio';
        return 'baixo';
    }
    function paramsFiltros() {
        const p = {};
        if ($selAno   && $selAno.value)   p.ano    = $selAno.value;
        if ($selCargo && $selCargo.value) p.cargo  = $selCargo.value;
        if ($selReg   && $selReg.value)   p.regiao = $selReg.value;
        return p;
    }
    function atualizarURL() {
        const p = new URLSearchParams();
        if ($selC1.value) p.set('c1', $selC1.value);
        if ($selC2.value && $selC2.value !== $selC1.value) p.set('c2', $selC2.value);
        Object.entries(paramsFiltros()).forEach(([k, v]) => p.set(k, v));
        const qs = p.toString();
        history.replaceState(null, '', SELF + (qs ? '?' + qs : ''));
    }

    // ------------------------------------------------------------
    // KPIs
    // ------------------------------------------------------------
    function linhaCobertura(rotulo, qtd, total, totalConhecido) {
        // Se não sabemos o total (ex.: não há coluna de seção), mostra só a qtd.
        if (!totalConhecido) {
            return '<div class="kc-cob-item">' +
                '<span class="kc-cob-num">' + fmtNum(qtd) + '</span>' +
                '<span class="kc-cob-lbl">' + rotulo + '</span>' +
            '</div>';
        }
        const p = pct(qtd, total);
        const cls = classePct(p);
        return '<div class="kc-cob-item">' +
            '<span class="kc-cob-num">' + fmtNum(qtd) + '</span>' +
            '<span class="kc-cob-lbl">de <b>' + fmtNum(total) + '</b> ' + rotulo +
                '<span class="kc-cob-pct ' + cls + '">' + p + '%</span>' +
            '</span>' +
        '</div>';
    }

    function renderKPICandidato($el, ds, dados, corClasse) {
        if (!ds) {
            $el.innerHTML =
                '<div class="kc-nome"><span class="kc-cor"></span><span>' +
                    (corClasse === 'cor-1' ? 'Candidato 1' : 'Candidato 2') +
                '</span></div>' +
                '<div class="kc-vazio">' +
                    (corClasse === 'cor-1' ? 'Não selecionado.' : 'Nenhum candidato selecionado.') +
                '</div>';
            return;
        }

        const regsComVoto   = ds.valores.filter(v => v > 0).length;
        const max           = Math.max.apply(null, ds.valores.concat([0]));
        const regiaoTop     = dados.regioes[ds.valores.indexOf(max)] || '—';
        const munsComVoto   = Number(ds.municipios_com_voto || 0);
        const zonasComVoto  = Number(ds.zonas_com_voto || 0);
        const secoesComVoto = Number(ds.secoes_com_voto || 0);

        const totalZonas    = Number(dados.total_zonas_geral || 0);
        const totalSecoes   = Number(dados.total_secoes_geral || 0);
        const temSecao      = !!dados.tem_secao;

        $el.innerHTML =
            '<div class="kc-nome"><span class="kc-cor"></span><span>' +
                escapeHtml(ds.nome) +
            '</span></div>' +
            '<div class="kc-total">' + fmtNum(ds.total) +
                ' <span style="font-size:13px;color:#888;font-weight:normal;">votos</span></div>' +
            '<div class="kc-sub">Maior região: <b>' + escapeHtml(regiaoTop) +
                '</b> (' + fmtNum(max) + ')</div>' +
            '<div class="kc-cobertura">' +
                '<div class="kc-mini-titulo">Cobertura territorial</div>' +
                '<div class="kc-cob-item">' +
                    '<span class="kc-cob-num">' + regsComVoto + '</span>' +
                    '<span class="kc-cob-lbl">região(ões)</span>' +
                '</div>' +
                '<div class="kc-cob-item">' +
                    '<span class="kc-cob-num">' + fmtNum(munsComVoto) + '</span>' +
                    '<span class="kc-cob-lbl">município(s)</span>' +
                '</div>' +
                linhaCobertura('zona(s)', zonasComVoto, totalZonas, true) +
                (temSecao
                    ? linhaCobertura('seção(ões)', secoesComVoto, totalSecoes, true)
                    : '<div class="kc-cob-item" style="grid-column:1/-1;color:#999;font-style:italic;">' +
                      '<span class="kc-cob-lbl">coluna de seção não encontrada em <code>votos</code></span>' +
                      '</div>'
                ) +
            '</div>';
    }

    function renderKPIs(dados) {
        const ds = dados.datasets || [];
        renderKPICandidato($kpiC1, ds[0], dados, 'cor-1');
        renderKPICandidato($kpiC2, ds[1], dados, 'cor-2');
    }

    // ------------------------------------------------------------
    // Gráfico
    // ------------------------------------------------------------
    function esconderGrafico() {
        if (chart) { chart.destroy(); chart = null; }
        $wrap.style.display = 'none';
        $aviso.style.display = 'block';
    }
    function mostrarGrafico() {
        $wrap.style.display = 'block';
        $aviso.style.display = 'none';
    }

    function renderGrafico(dados) {
        if (chart) chart.destroy();
        mostrarGrafico();

        const cores = [
            { bg: 'rgba(0, 105, 217, 0.82)', border: 'rgba(0, 83, 179, 1)' },
            { bg: 'rgba(253, 126, 20, 0.82)', border: 'rgba(200, 100, 15, 1)' }
        ];

        const datasets = dados.datasets.map(function (d, i) {
            const cor = cores[i % cores.length];
            return {
                label: d.nome,
                data: d.valores,
                backgroundColor: cor.bg,
                borderColor: cor.border,
                borderWidth: 1.5,
                borderRadius: 4,
                borderSkipped: false,
                maxBarThickness: 32
            };
        });

        chart = new Chart($canvas, {
            type: 'bar',
            data: { labels: dados.regioes, datasets: datasets },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 500 },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 13, weight: 'bold' }, padding: 14 }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(20,20,20,.92)',
                        padding: 10,
                        titleFont: { size: 13 },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + fmtNum(ctx.parsed.x) + ' votos';
                            }
                        }
                    }
                },
                scales: {
                    x: { beginAtZero: true,
                         ticks: { callback: v => fmtNum(v), font: { size: 11 } },
                         grid: { color: '#eaeaea' } },
                    y: { grid: { display: false },
                         ticks: { font: { size: 12, weight: 'bold' }, color: '#333' } }
                }
            }
        });
    }

    // ------------------------------------------------------------
    // Tabela
    // ------------------------------------------------------------
    function renderTabela(dados) {
        const cands  = dados.candidatos || [];
        const linhas = dados.linhas || [];
        const totais = dados.totais || {};

        if (!cands.length || !linhas.length) {
            $tab.innerHTML = '<div class="tabela-aviso">Sem dados para exibir.</div>';
            return;
        }

        let thead = '<tr><th style="width:36px;">#</th><th>Região</th>';
        cands.forEach(function (cand, i) {
            const cls = (i === 0) ? 'cand-1' : 'cand-2';
            thead += '<th class="num ' + cls + '" colspan="3" style="text-align:center;">' +
                     escapeHtml(cand) + '</th>';
        });
        thead += '<th class="num" style="background:#0b3d91;">Total região</th></tr>';

        let subhead = '<tr><th></th><th></th>';
        cands.forEach(function (cand, i) {
            const bg = (i === 0) ? '#3a8ee0' : '#e08a3a';
            subhead += '<th class="num" style="background:' + bg + ';">Votos</th>' +
                       '<th class="num" style="background:' + bg + ';">Municípios</th>' +
                       '<th class="num" style="background:' + bg + ';">Zonas</th>';
        });
        subhead += '<th class="num" style="background:#3a5ea0;">Votos</th></tr>';

        let tbody = '';
        let totalGeralReg = 0;

        linhas.forEach(function (linha, idx) {
            tbody += '<tr><td>' + (idx + 1) + '</td>' +
                     '<td><b>' + escapeHtml(linha.regiao) + '</b></td>';

            cands.forEach(function (cand, i) {
                const d = linha.dados[cand] || { total: 0, municipios: 0, zonas: 0 };
                const peso = i === 0 ? 'color:#0069d9;font-weight:600;' : 'color:#c8641a;font-weight:600;';
                tbody += '<td class="num" style="' + peso + '">' + fmtNum(d.total) + '</td>' +
                         '<td class="num">' + fmtNum(d.municipios) + '</td>' +
                         '<td class="num">' + fmtNum(d.zonas) + '</td>';
            });

            tbody += '<td class="num"><b>' + fmtNum(linha.total_regiao) + '</b></td></tr>';
            totalGeralReg += linha.total_regiao;
        });

        let tfoot = '<tr><td colspan="2">Total geral</td>';
        cands.forEach(function (cand, i) {
            const totCand = totais[cand] || 0;
            tfoot += '<td class="num" style="color:' + (i === 0 ? '#0069d9' : '#c8641a') + ';">' +
                     fmtNum(totCand) + '</td>' +
                     '<td class="num">—</td><td class="num">—</td>';
        });
        tfoot += '<td class="num">' + fmtNum(totalGeralReg) + '</td></tr>';

        $tab.innerHTML =
            '<table class="tabela-regioes">' +
                '<thead>' + thead + subhead + '</thead>' +
                '<tbody>' + tbody + '</tbody>' +
                '<tfoot>' + tfoot + '</tfoot>' +
            '</table>';
    }

    // ------------------------------------------------------------
    // Carregar
    // ------------------------------------------------------------
    function carregar() {
        const c1 = $selC1.value;
        const c2 = $selC2.value;

        if (!c1 && !c2) {
            esconderGrafico();
            renderKPICandidato($kpiC1, null, {}, 'cor-1');
            renderKPICandidato($kpiC2, null, {}, 'cor-2');
            $tab.innerHTML = '<div class="tabela-aviso">Selecione pelo menos um candidato para visualizar o detalhamento.</div>';
            atualizarURL();
            return;
        }

        if (c1 && c2 && c1 === c2) {
            alert('Os dois candidatos são iguais. Escolha candidatos diferentes ou deixe o 2º em branco.');
            $selC2.value = '';
            if (window.jQuery) jQuery('#selC2').trigger('change');
            return carregar();
        }

        const base = { c1: c1, c2: c2 };
        Object.assign(base, paramsFiltros());

        atualizarURL();

        $wrap.style.display = 'block';
        $aviso.style.display = 'none';
        const ctx = $canvas.getContext('2d');
        ctx.clearRect(0, 0, $canvas.width, $canvas.height);
        ctx.font = '14px Arial';
        ctx.fillStyle = '#888';
        ctx.textAlign = 'center';
        ctx.fillText('Carregando dados...', $canvas.width / 2, $canvas.height / 2);

        // ---- Gráfico / KPIs ----
        const qsChart = new URLSearchParams(Object.assign({ ajax: 'chart_regioes' }, base));
        fetch(SELF + '?' + qsChart.toString(), { cache: 'no-store' })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(j => {
                if (!j.ok) {
                    esconderGrafico();
                    console.warn('Erro no gráfico:', j.msg);
                    return;
                }
                renderGrafico(j);
                renderKPIs(j);
            })
            .catch(err => {
                console.error('Falha ao carregar gráfico:', err);
                esconderGrafico();
            });

        // ---- Tabela ----
        const qsTab = new URLSearchParams(Object.assign({ ajax: 'tabela_regioes' }, base));
        $tab.innerHTML = '<div class="tabela-aviso"><span class="loading-spinner"></span>Carregando detalhamento…</div>';
        fetch(SELF + '?' + qsTab.toString(), { cache: 'no-store' })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(j => {
                if (!j.ok) {
                    $tab.innerHTML = '<div class="tabela-aviso">Erro: ' + escapeHtml(j.msg || '') + '</div>';
                    return;
                }
                renderTabela(j);
            })
            .catch(err => {
                console.error('Falha ao carregar tabela:', err);
                $tab.innerHTML = '<div class="tabela-aviso">Erro de rede.</div>';
            });
    }

    // ------------------------------------------------------------
    // Eventos
    // ------------------------------------------------------------
    $btn.addEventListener('click', carregar);

    [$selC1, $selC2, $selAno, $selCargo, $selReg].forEach(function (el) {
        if (el) el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); carregar(); }
        });
    });

    if ($selC1.value || $selC2.value) carregar();

})();
</script>

</body>
</html>