<?php
// ============================================================
// Mapa Comparativo — Candidatos
// ============================================================
require_once __DIR__ . '/conn_eleicoes.php';
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function fmtInt($n): string {
    return number_format((float)$n, 0, ',', '.');
}
function fmtVantagem(float $pp): string {
    return number_format($pp, 1, ',', '.') . '% acima';
}
function clamp($v, $min, $max) {
    return max($min, min($max, $v));
}
/** json_encode seguro para embutir dentro de <script> */
function jsonJs($v): string {
    return json_encode($v,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE |
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}
function achaColuna(array $disponiveis, array $candidatos): ?string {
    foreach ($candidatos as $c) {
        if (in_array($c, $disponiveis, true)) return $c;
    }
    return null;
}
function normalizaMun(string $s): string {
    return strtr(mb_strtolower($s, 'UTF-8'), [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ]);
}
/** HTML da célula "Liderança" (usado nas linhas e no total) */
function htmlLideranca(int $c1, int $c2, string $nome1, string $nome2): string {
    $tot = $c1 + $c2;
    if ($tot === 0)  return '<span style="color:#aaa;">—</span>';
    if ($c1 === $c2) return '<span style="color:#999;">Empate</span>';
    $diff = $c1 - $c2;
    $quem = $diff > 0 ? $nome1 : $nome2;
    $cor  = $diff > 0 ? '#1b5e20' : '#0d47a1';
    $pp   = abs($diff / $tot) * 100;
    return '<span class="nome-lider" style="color:' . $cor . ';">● ' . h($quem) . '</span>'
         . ' <span class="pp">+' . fmtVantagem($pp) . '</span>';
}

// ------------------------------------------------------------
// Conexão
// ------------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]
    );
} catch (PDOException $e) {
    error_log('[mapa_calor] conexão: ' . $e->getMessage());
    http_response_code(500);
    exit('Não foi possível conectar ao banco de dados.');
}

// ------------------------------------------------------------
// Usuário / perfil
// ------------------------------------------------------------
if (!isset($__u)) {
    $__u = function_exists('usuarioLogado')
        ? usuarioLogado()
        : [
            'nome'   => $_SESSION['usuario_nome']   ?? '—',
            'login'  => $_SESSION['usuario_login']  ?? '',
            'perfil' => $_SESSION['usuario_perfil'] ?? '',
        ];
}
$usuario       = $__u;
$perfil        = strtolower(trim((string)($usuario['perfil'] ?? '')));
$ehSuper       = in_array($perfil, ['superadmin', 'super'], true);
$ehAdminNivel  = in_array($perfil, ['admin', 'administrador'], true);
$ehAdmin       = $ehSuper || $ehAdminNivel;

$loginLower = mb_strtolower(trim((string)($usuario['login'] ?? '')), 'UTF-8');
$nomeLower  = mb_strtolower(trim((string)($usuario['nome']  ?? '')), 'UTF-8');
$ehDono     = in_array($loginLower, ['barreto', 'vbarreto'], true)
           || in_array($nomeLower,  ['barreto', 'valter barreto'], true);
$podeExcluir = $ehAdmin && $ehDono;

if ($ehSuper)           { $classePerfil = 'super';     $rotuloPerfil = 'SUPERADMIN'; }
elseif ($ehAdminNivel)  { $classePerfil = 'admin';     $rotuloPerfil = 'ADMIN'; }
else                    { $classePerfil = 'visitante'; $rotuloPerfil = 'VISITANTE'; }

// ------------------------------------------------------------
// Filtros recebidos (e normalizados)
// ------------------------------------------------------------
$ano           = trim((string)($_GET['ano']    ?? ''));
$regiao        = trim((string)($_GET['regiao'] ?? ''));
$cargo         = trim((string)($_GET['cargo']  ?? ''));
$cand1         = trim((string)($_GET['cand1']  ?? ''));
$cand2         = trim((string)($_GET['cand2']  ?? ''));
$incluirVazias = (($_GET['vazias'] ?? '') === '1');

$opacidade = clamp((float)($_GET['opac']   ?? 0.90), 0.10, 1.00);
$escala    = clamp((float)($_GET['escala'] ?? 1.00), 0.50, 2.50);
$limiar    = (int)clamp((int)($_GET['limiar'] ?? 10), 0, 30);

// Se só o candidato 2 foi escolhido, ele passa a ser o "único" (cand1).
// (Antes, os votos caíam na coluna c1 do SQL e o mapa ficava vazio.)
if ($cand1 === '' && $cand2 !== '') { $cand1 = $cand2; $cand2 = ''; }
if ($cand2 === $cand1)              { $cand2 = ''; }

$temAlgum      = ($cand1 !== '');
$temComparacao = ($cand1 !== '' && $cand2 !== '');

// ------------------------------------------------------------
// Coleta de dados
// ------------------------------------------------------------
$erro            = null;
$temAno          = false;
$colCoord        = null;
$colLider        = null;
$pontos          = [];
$maxC1 = $maxC2 = $maxTot = 0;
$totC1 = $totC2 = $totalSecoes = 0;
$porMunicipio    = [];
$resumo          = ['total' => 0, 'lidera_c1' => 0, 'lidera_c2' => 0, 'empates' => 0, 'sem_votos' => 0];
$anosDisponiveis = $cargos = $candidatos = $regioes = [];

try {
    // Colunas opcionais
    $colunasVotos  = $pdo->query("SHOW COLUMNS FROM votos")->fetchAll(PDO::FETCH_COLUMN);
    $colunasSecoes = $pdo->query("SHOW COLUMNS FROM secoes_enderecos")->fetchAll(PDO::FETCH_COLUMN);
    $temAno   = in_array('ano', $colunasVotos, true);
    $colCoord = achaColuna($colunasSecoes, [
        'coordenador', 'nomeCoordenador', 'nome_coordenador',
        'coord', 'nome_coord', 'nm_coordenador',
    ]);
    $colLider = achaColuna($colunasSecoes, [
        'lideranca', 'nomeLideranca', 'nome_lideranca',
        'lider', 'nome_lider', 'nm_lideranca',
    ]);

    if ($temAlgum) {
        $q = fn(string $col) => '`' . str_replace('`', '``', $col) . '`';
        $coordExpr = $colCoord ? 's.' . $q($colCoord) : 'NULL';
        $liderExpr = $colLider ? 's.' . $q($colLider) : 'NULL';

        // --- filtros em votos (v) ---
        $vConds = [];
        $params = [];

        if ($temComparacao) {
            $vConds[]      = 'v.nomeCandidatoResp IN (:c1, :c2)';
            $params[':c1'] = $cand1;
            $params[':c2'] = $cand2;
            $params[':s1'] = $cand1;
            $params[':s2'] = $cand2;
            $selVotos = "
                COALESCE(SUM(CASE WHEN v.nomeCandidatoResp = :s1 THEN v.qtdVotos ELSE 0 END), 0) AS votos_c1,
                COALESCE(SUM(CASE WHEN v.nomeCandidatoResp = :s2 THEN v.qtdVotos ELSE 0 END), 0) AS votos_c2";
        } else {
            $vConds[]      = 'v.nomeCandidatoResp = :c1';
            $params[':c1'] = $cand1;
            $selVotos = "COALESCE(SUM(v.qtdVotos), 0) AS votos_c1, 0 AS votos_c2";
        }
        if ($temAno && $ano !== '') {
            $vConds[]       = 'v.ano = :ano';
            $params[':ano'] = (int)$ano;
        }
        if ($cargo !== '') {
            $vConds[]         = 'v.nomeCargoPerg = :cargo';
            $params[':cargo'] = $cargo;
        }

        // --- filtro de região (s) ---
        $condRegiao = null;
        if ($regiao !== '') {
            $condRegiao = "EXISTS (
                SELECT 1 FROM municipio_regiao mr
                WHERE mr.nomeMunicipio COLLATE utf8mb4_general_ci
                    = s.nomeMunicipio  COLLATE utf8mb4_general_ci
                  AND mr.regiao_id = :regiao
            )";
            $params[':regiao'] = (int)$regiao;
        }

        // --- FROM / WHERE ---
        if ($incluirVazias) {
            $from = "FROM secoes_enderecos s
                     LEFT JOIN votos v
                            ON v.numeroZona  = s.numeroZona
                           AND v.numeroSecao = s.numeroSecao
                           AND " . implode(' AND ', $vConds);
            $where = $condRegiao ? "WHERE $condRegiao" : '';
        } else {
            $from = "FROM votos v
                     INNER JOIN secoes_enderecos s
                             ON s.numeroZona  = v.numeroZona
                            AND s.numeroSecao = v.numeroSecao";
            $where = 'WHERE ' . implode(' AND ', $condRegiao ? array_merge($vConds, [$condRegiao]) : $vConds);
        }

        $sql = "
            SELECT s.numeroZona, s.numeroSecao, s.nomeLocal, s.nomeMunicipio,
                   s.latitude, s.longitude,
                   $coordExpr AS coordenador,
                   $liderExpr AS lideranca,
                   $selVotos
            $from
            $where
            GROUP BY s.numeroZona, s.numeroSecao, s.nomeLocal, s.nomeMunicipio,
                     s.latitude, s.longitude
                     " . ($colCoord ? ', s.' . $q($colCoord) : '')
                       . ($colLider ? ', s.' . $q($colLider) : '') . "
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        foreach ($stmt as $row) {
            $lat = (float)$row['latitude'];
            $lng = (float)$row['longitude'];
            if ($lat === 0.0 && $lng === 0.0) continue;

            $c1  = (int)$row['votos_c1'];
            $c2  = (int)$row['votos_c2'];
            $tot = $c1 + $c2;
            if (!$incluirVazias && $tot === 0) continue;

            $pontos[] = [
                'lat'   => $lat,
                'lng'   => $lng,
                'zona'  => (int)$row['numeroZona'],
                'secao' => (int)$row['numeroSecao'],
                'local' => $row['nomeLocal']     ?? '',
                'mun'   => $row['nomeMunicipio'] ?? '',
                'coord' => $row['coordenador']   ?? '',
                'lider' => $row['lideranca']     ?? '',
                'c1'    => $c1,
                'c2'    => $c2,
                'tot'   => $tot,
            ];

            $totC1 += $c1;
            $totC2 += $c2;
            $totalSecoes++;
            $maxC1  = max($maxC1,  $c1);
            $maxC2  = max($maxC2,  $c2);
            $maxTot = max($maxTot, $tot);

            // agregação por município
            $mun = $row['nomeMunicipio'] !== null && $row['nomeMunicipio'] !== ''
                 ? $row['nomeMunicipio'] : '(sem município)';
            if (!isset($porMunicipio[$mun])) {
                $porMunicipio[$mun] = [
                    'nome' => $mun, 'norm' => normalizaMun($mun),
                    'c1' => 0, 'c2' => 0, 'tot' => 0, 'secoes' => 0,
                ];
            }
            $porMunicipio[$mun]['c1']     += $c1;
            $porMunicipio[$mun]['c2']     += $c2;
            $porMunicipio[$mun]['tot']    += $tot;
            $porMunicipio[$mun]['secoes'] += 1;
        }

        uasort($porMunicipio, fn($a, $b) =>
            $b['tot'] <=> $a['tot'] ?: strcmp($a['nome'], $b['nome'])
        );

        $resumo['total'] = count($porMunicipio);
        foreach ($porMunicipio as $m) {
            if     ($m['tot'] === 0)       $resumo['sem_votos']++;
            elseif ($m['c1'] > $m['c2'])   $resumo['lidera_c1']++;
            elseif ($m['c2'] > $m['c1'])   $resumo['lidera_c2']++;
            else                           $resumo['empates']++;
        }
    }

    // Listas dos selects
    if ($temAno) {
        $anosDisponiveis = $pdo->query(
            "SELECT DISTINCT ano FROM votos WHERE ano IS NOT NULL ORDER BY ano DESC"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
    $cargos     = $pdo->query("SELECT DISTINCT nomeCargoPerg FROM votos ORDER BY nomeCargoPerg")->fetchAll(PDO::FETCH_COLUMN);
    $candidatos = $pdo->query("SELECT DISTINCT nomeCandidatoResp FROM votos ORDER BY nomeCandidatoResp")->fetchAll(PDO::FETCH_COLUMN);
    $regioes    = $pdo->query("SELECT id, nome FROM regioes ORDER BY nome")->fetchAll();

} catch (PDOException $e) {
    error_log('[mapa_calor] consulta: ' . $e->getMessage());
    $erro = 'Erro ao consultar o banco de dados. Tente novamente ou avise o administrador.';
    $pontos = [];
}

// Linhas da tabela-resumo (evita 4 blocos HTML quase idênticos)
$linhasResumo = [];
if ($temComparacao && $resumo['total'] > 0) {
    $linhasResumo = [
        ['linha-c1',  'c1',  'cel-c1',    'b-c1',  $cand1,                $resumo['lidera_c1'], true],
        ['linha-c2',  'c2',  'cel-c2',    'b-c2',  $cand2,                $resumo['lidera_c2'], true],
        ['linha-emp', 'emp', 'cel-cinza', 'b-emp', 'Empates',             $resumo['empates'],   false],
        ['linha-sv',  'sv',  'cel-cinza', 'b-sv',  'Sem votos dos dois',  $resumo['sem_votos'], false],
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mapa Comparativo — Candidatos</title>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    :root {
        --c1: #28a745;  --c1-txt: #1b5e20;
        --c2: #0d6efd;  --c2-txt: #0d47a1;
        --azul: #0069d9; --cinza: #6c757d; --roxo: #6f42c1;
        --sombra: 0 1px 3px rgba(0,0,0,.10);
    }
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    h2 { margin-top:26px; }

    /* ===== Barra de usuário ===== */
    .barra-usuario {
        display:flex; align-items:center; gap:12px; flex-wrap:wrap;
        background:#fff; padding:10px 16px; border-radius:8px;
        box-shadow:var(--sombra); margin-bottom:18px; font-size:13px;
    }
    .barra-usuario.super { border-left:5px solid var(--roxo); }
    .barra-usuario.admin { border-left:5px solid #0d6efd; }
    .nome-user { font-weight:600; }
    .perfil-tag, .badge {
        padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600;
        background:#e9ecef; color:#495057;
    }
    .perfil-tag { text-transform:uppercase; }
    .perfil-tag.admin, .badge-admin { background:#0d6efd; color:#fff; }
    .perfil-tag.super, .badge-super { background:var(--roxo); color:#fff; }
    .link-debug { margin-left:auto; color:var(--cinza); text-decoration:none; font-size:12px; }
    .link-debug:hover { color:#0d6efd; text-decoration:underline; }

    /* ===== KPIs ===== */
    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi {
        background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:var(--sombra); min-width:190px; border-left:5px solid var(--azul);
    }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:var(--azul); margin-top:4px; }
    .kpi .sub    { font-size:12px; color:#888; margin-top:2px; min-height:14px; }
    .kpi.c1    { border-left-color:var(--c1);    } .kpi.c1 .valor    { color:var(--c1); }
    .kpi.c2    { border-left-color:var(--c2);    } .kpi.c2 .valor    { color:var(--c2); }
    .kpi.cinza { border-left-color:var(--cinza); } .kpi.cinza .valor { color:var(--cinza); }
    .kpi.roxo  { border-left-color:var(--roxo);  } .kpi.roxo .valor  { color:var(--roxo); }
    .kpi.oculto { display:none !important; }

    /* ===== Formulário ===== */
    form { background:#fff; padding:15px; border-radius:8px; box-shadow:var(--sombra); margin-bottom:20px; }
    label { display:inline-block; margin-right:10px; font-weight:bold; }
    select, button { padding:6px 8px; margin:4px 10px 4px 0; border-radius:4px; border:1px solid #ccc; font-size:14px; }
    button { cursor:pointer; border:none; }
    .btn-consultar { background:var(--azul); color:#fff; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar { background:var(--cinza); color:#fff; text-decoration:none; padding:6px 12px;
                  border-radius:4px; display:inline-block; margin:4px 0; }
    .btn-limpar:hover { background:#5a6268; }
    .filtros { display:flex; flex-wrap:wrap; align-items:center; }

    .chk-vazias {
        display:inline-flex; align-items:center; gap:6px;
        padding:5px 12px; border-radius:6px; border:2px solid #e3e3e3; background:#fff;
        font-size:12.5px; font-weight:bold; color:#555;
        cursor:pointer; user-select:none; margin:4px 10px 4px 0;
    }
    .chk-vazias:hover { border-color:var(--azul); color:var(--azul); }
    .chk-vazias input { margin:0; cursor:pointer; }
    .chk-vazias.ativo { border-color:var(--cinza); background:#f0f0f0; color:#333; }

    .select2-container { margin:4px 10px 4px 0; }
    .select2-container--default .select2-selection--single {
        height:34px; padding:3px 6px; border:1px solid #ccc; border-radius:4px; background:#fff;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height:26px; color:#333; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height:32px; }
    .select2-search--dropdown .select2-search__field { padding:6px; font-size:14px; border-radius:4px; }
    .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color:var(--azul); }
    #cand1 + .select2-container, #cand2 + .select2-container { min-width:320px; max-width:440px; }

    .cand-tag { display:inline-flex; align-items:center; gap:6px; font-weight:bold; font-size:13px; }
    .cand-tag .dot { display:inline-block; width:12px; height:12px; border-radius:3px; }
    .cand-tag.c1 .dot { background:var(--c1); }
    .cand-tag.c2 .dot { background:var(--c2); }

    /* ===== Modos e controles ===== */
    .modo-btns { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
    .modo-btns button {
        background:#fff; border:2px solid #e3e3e3; padding:8px 14px; border-radius:6px;
        font-size:13px; color:#555; transition:all .12s ease; margin:0;
    }
    .modo-btns button:hover:not(:disabled) { border-color:var(--azul); color:var(--azul); }
    .modo-btns button.ativo { border-color:var(--azul); color:var(--azul); font-weight:bold; background:#e7f3ff; }
    .modo-btns button:disabled { opacity:.35; cursor:not-allowed; text-decoration:line-through; }

    .controles {
        background:#fff; padding:10px 14px; border-radius:6px; box-shadow:0 1px 2px rgba(0,0,0,.08);
        font-size:13px; display:flex; flex-wrap:wrap; align-items:center; gap:14px; margin-bottom:10px;
    }
    .controles label { font-weight:bold; font-size:12px; margin-right:6px; }
    .controles input[type=range] { width:130px; vertical-align:middle; }
    .controles .valor { font-family:monospace; color:var(--azul); font-weight:bold; min-width:40px; display:inline-block; }
    .controles .sep { border-left:1px solid #eee; padding-left:14px; }
    .controles .grupo-limiar { display:none; }
    .controles.modo-disputa .grupo-limiar { display:inline-flex; align-items:center; gap:14px; }

    .cand-toggle {
        display:inline-flex; align-items:center; gap:6px; cursor:pointer;
        padding:5px 12px; border-radius:6px; border:2px solid #e3e3e3; background:#fff;
        font-size:12.5px; font-weight:bold; transition:all .15s; user-select:none; margin:0;
    }
    .cand-toggle:hover { border-color:var(--azul); }
    .cand-toggle input { margin:0; cursor:pointer; }
    .cand-toggle .cor { width:14px; height:14px; border-radius:3px; display:inline-block; border:1px solid rgba(0,0,0,.15); }
    .cand-toggle.c1 .cor { background:var(--c1); }
    .cand-toggle.c2 .cor { background:var(--c2); }
    .cand-toggle.c1.ativo { border-color:var(--c1); background:#f0fff4; }
    .cand-toggle.c2.ativo { border-color:var(--c2); background:#f0f6ff; }
    .cand-toggle.c1.ativo .nome { color:var(--c1-txt); }
    .cand-toggle.c2.ativo .nome { color:var(--c2); }
    .cand-toggle.oculto { border-style:dashed; border-color:#ccc; background:#f4f4f4; }
    .cand-toggle.oculto .nome { color:#999; text-decoration:line-through; }
    .cand-toggle.oculto .cor { opacity:.25; }
    .cand-toggle .nome { color:#555; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

    #mapa { height:640px; width:100%; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.15); background:#e5e5e5; margin-top:10px; }

    .legenda-cores {
        background:#fff; padding:10px 16px; border-radius:6px; box-shadow:0 1px 2px rgba(0,0,0,.08);
        display:flex; flex-wrap:wrap; gap:22px; align-items:center; font-size:13px;
        margin-bottom:10px; min-height:42px;
    }
    .legenda-cores .item { display:flex; align-items:center; gap:8px; }
    .legenda-cores .grad { width:140px; height:12px; border-radius:3px; border:1px solid #ddd; }
    .grad-margem { background:linear-gradient(to right, #0d47a1, #1976d2, #90caf9, #f5f5f5, #a5d6a7, #43a047, #1b5e20); }
    .grad-c1 { background:linear-gradient(to right, #e8f5e9, #81c784, #43a047, #1b5e20); }
    .grad-c2 { background:linear-gradient(to right, #e3f2fd, #64b5f6, #1976d2, #0d47a1); }
    .legenda-vazia { color:#856404; font-weight:bold; font-size:13px; }

    .modo-unico-badge {
        display:inline-flex; align-items:center; gap:6px; background:#e7f3ff; color:#0053b3;
        border-left:4px solid var(--azul); padding:8px 14px; border-radius:6px; font-size:13px; margin-bottom:14px;
    }

    .aviso { background:#fff3cd; border-left:5px solid #ffc107; padding:12px 16px; border-radius:6px; margin-bottom:20px; }
    .aviso.erro { background:#f8d7da; border-left-color:#dc3545; color:#721c24; }
    .aviso code { background:#fff; padding:1px 5px; border-radius:3px; font-size:12px; border:1px solid #eee; }
    .link-voltar { display:inline-block; margin-bottom:15px; color:var(--azul); text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

    /* ===== Popup / tooltip ===== */
    .leaflet-popup-content { font-family:Arial, sans-serif; font-size:13px; margin:10px 12px; }
    .leaflet-tooltip {
        background:rgba(255,255,255,.98); border:1px solid #ccc; border-radius:6px;
        box-shadow:0 2px 8px rgba(0,0,0,.15); padding:8px 10px; color:#333; max-width:320px;
    }
    .leaflet-tooltip-top:before, .leaflet-tooltip-bottom:before,
    .leaflet-tooltip-left:before, .leaflet-tooltip-right:before { display:none; }
    .tt-sec   { font-weight:bold; }
    .tt-mun   { color:#333; font-size:12px; margin-top:2px; }
    .tt-local { color:#666; font-size:11px; }
    .tt-geo   { color:#999; font-size:10.5px; margin-top:1px; }
    .tt-resp  { color:#444; font-size:11px; }
    .tt-sep   { border:none; border-top:1px solid #eee; margin:5px 0; }
    .tt-linha { display:flex; justify-content:space-between; gap:12px; }
    .tt-centro{ text-align:center; font-size:12px; margin-top:6px; }
    .tt-sub   { color:#999; font-size:10.5px; text-align:center; margin-top:2px; }
    .tt-nenhum {
        margin-top:6px; padding:5px 8px; background:#f0f0f0; border-radius:4px;
        color:#555; font-weight:bold; text-align:center; letter-spacing:.5px;
    }
    .tt-barra { margin-top:8px; display:flex; height:14px; border-radius:7px; overflow:hidden; border:1px solid #eee; }
    .tt-pct   { display:flex; justify-content:space-between; font-size:11px; color:#666; margin-top:2px; }
    .donut-ico { background:transparent; border:none; }

    /* ===== Tabelas ===== */
    .tabela-wrap, .resumo-wrap {
        background:#fff; border-radius:8px; box-shadow:var(--sombra); margin-top:22px; overflow:hidden;
    }
    .resumo-wrap { max-width:720px; }
    .tabela-header {
        display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
        padding:12px 16px; border-bottom:1px solid #eee; background:#fbfbfb;
    }
    .tabela-header h2 { margin:0; font-size:15px; color:#333; letter-spacing:.3px; }
    .tabela-header .contagem { color:#888; font-size:12px; font-weight:normal; margin-left:6px; }
    .tabela-filtro { padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; min-width:220px; outline:none; }
    .tabela-filtro:focus { border-color:var(--azul); }
    .tabela-scroll { max-height:520px; overflow:auto; }

    table.tabela-municipios, table.tabela-resumo { width:100%; border-collapse:collapse; font-size:13px; }
    table.tabela-municipios thead th, table.tabela-resumo thead th {
        background:#f7f7f7; text-align:left; border-bottom:2px solid #ddd;
        font-size:11px; text-transform:uppercase; color:#666; letter-spacing:.6px; white-space:nowrap;
    }
    table.tabela-municipios thead th { position:sticky; top:0; padding:10px 14px; z-index:1; }
    table.tabela-resumo thead th { padding:12px 16px; }
    th.num { text-align:right !important; }

    table.tabela-municipios tbody td { padding:8px 14px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
    table.tabela-municipios tbody tr:hover { background:#fafafa; }
    table.tabela-municipios tbody tr:nth-child(even) { background:#fcfcfc; }
    table.tabela-municipios tbody tr:nth-child(even):hover { background:#f5f5f5; }
    td.num {
        text-align:right; font-variant-numeric:tabular-nums;
        font-family:'Courier New', monospace; font-weight:bold;
    }
    .col-c1 { color:var(--c1-txt); }
    .col-c2 { color:var(--c2-txt); }
    .col-vant { font-size:12px; }
    .col-vant .nome-lider { font-weight:bold; }
    .col-vant .pp { color:#999; font-size:11px; font-family:'Courier New', monospace; }

    tfoot.total-geral td { padding:12px 14px; background:#f7f7f7; border-top:2px solid #ddd; font-weight:bold; font-size:13px; }

    table.tabela-municipios.ocultar-c1 .col-c1, table.tabela-municipios.ocultar-c1 .col-vant,
    table.tabela-municipios.ocultar-c2 .col-c2, table.tabela-municipios.ocultar-c2 .col-vant { display:none; }

    .sem-resultado { text-align:center; padding:24px; color:#999; font-style:italic; display:none; }

    table.tabela-resumo tbody td { padding:14px 16px; border-bottom:1px solid #f0f0f0; vertical-align:middle; font-size:14px; }
    table.tabela-resumo td.num { font-size:16px; }
    table.tabela-resumo tbody tr:hover { background:#fafafa; }
    .cel-c1 { color:var(--c1-txt); } .cel-c2 { color:var(--c2-txt); } .cel-cinza { color:var(--cinza); }

    .resumo-dot { display:inline-block; width:12px; height:12px; border-radius:3px; margin-right:6px; vertical-align:middle; }
    .resumo-dot.c1 { background:var(--c1); } .resumo-dot.c2 { background:var(--c2); }
    .resumo-dot.emp { background:#adb5bd; }  .resumo-dot.sv { background:#ced4da; }
    .resumo-linha-total td { background:#f7f7f7; border-top:2px solid #ddd; font-weight:bold; padding:14px 16px; }
    .resumo-barra { display:flex; height:8px; border-radius:4px; overflow:hidden; background:#eee; margin:4px 0 0 auto; max-width:240px; }
    .resumo-barra > div { height:100%; }
    .b-c1 { background:var(--c1); } .b-c2 { background:var(--c2); }
    .b-emp { background:#adb5bd; }  .b-sv { background:#ced4da; }
    .resumo-wrap.ocultar-c1 .linha-c1 { display:none; }
    .resumo-wrap.ocultar-c2 .linha-c2 { display:none; }

    /* ============================================================
       v3 — layout refinado
       ============================================================ */
    body { max-width:1480px; margin:0 auto; padding:20px 24px 60px; background:#f1f3f6; }
    h1 { font-size:22px; margin:6px 0 14px; }
    h2 { font-size:16px; margin:22px 0 10px; }
    form { box-shadow:none; border:1px solid #e3e7ec; }
    .barra-usuario { box-shadow:none; border:1px solid #e3e7ec; }
    .kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:14px; }
    .kpi { box-shadow:none; border:1px solid #e3e7ec; border-left-width:5px; min-width:0; }
    .controles, .legenda-cores { box-shadow:none; border:1px solid #e3e7ec; }
    #mapa { height:clamp(460px, 72vh, 820px); border:1px solid #d9dee4; box-shadow:none; }
    #mapa.mapa-full { position:fixed; inset:0; z-index:9999; height:100vh !important;
                      border-radius:0; margin:0; border:none; }
    .tabela-wrap, .resumo-wrap { box-shadow:none; border:1px solid #e3e7ec; margin-top:0; max-width:none; }
    .tabela-scroll { max-height:640px; }

    .acoes { display:flex; gap:8px; flex-wrap:wrap; margin:10px 0 0; }
    .btn-acao {
        background:#fff; border:1px solid #d5d9df !important; color:#333;
        padding:7px 12px; border-radius:6px; font-size:13px; margin:0;
    }
    .btn-acao:hover { border-color:var(--azul) !important; color:var(--azul); }

    .paineis { display:grid; grid-template-columns:minmax(0, 2fr) minmax(300px, 1fr);
               gap:20px; align-items:start; margin-top:22px; }
    .paineis > :only-child { grid-column:1 / -1; }

    th.ord { cursor:pointer; user-select:none; }
    th.ord:hover { color:var(--azul); }
    th.ord::after { content:' ↕'; opacity:.35; }
    th.ord.asc::after  { content:' ▲'; opacity:1; color:var(--azul); }
    th.ord.desc::after { content:' ▼'; opacity:1; color:var(--azul); }
    table.tabela-municipios tbody tr { cursor:pointer; }
    table.tabela-municipios tbody tr.sel { background:#e7f3ff !important; }

    .leaflet-control-custom a { font-size:16px; line-height:30px; text-decoration:none; cursor:pointer; }

    .toast {
        position:fixed; left:50%; bottom:28px; transform:translate(-50%, 20px);
        background:#222; color:#fff; padding:10px 18px; border-radius:8px; font-size:13px;
        opacity:0; pointer-events:none; transition:all .25s; z-index:10000;
    }
    .toast.on { opacity:.95; transform:translate(-50%, 0); }

    @media (max-width: 1000px) { .paineis { grid-template-columns:1fr; } }
    @media (max-width: 720px) {
        body { padding:12px; }
        #cand1 + .select2-container, #cand2 + .select2-container { min-width:0; width:100% !important; max-width:100%; }
        .controles { gap:10px; }
        .controles .sep { border-left:none; padding-left:0; }
        #mapa { height:62vh; }
    }
    @media print {
        .acoes, form, .controles, .modo-btns, .link-voltar, .barra-usuario { display:none !important; }
        body { background:#fff; }
    }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

<h1>Mapa Comparativo — Candidatos</h1>

<div class="barra-usuario <?= $ehAdmin ? ($ehSuper ? 'super' : 'admin') : '' ?>">
    <span>👤 <span class="nome-user"><?= h($usuario['nome'] ?? '—') ?></span></span>
    <span class="perfil-tag <?= h($classePerfil) ?>"><?= h($rotuloPerfil) ?></span>

    <?php if ($podeExcluir): ?>
        <span class="badge <?= $ehSuper ? 'badge-super' : 'badge-admin' ?>">
            <?= $ehSuper ? '🔓 superadmin' : '🔓 administrador' ?>
        </span>
    <?php elseif ($ehAdmin): ?>
        <span class="badge badge-admin">🔓 admin (não autorizado a excluir)</span>
    <?php else: ?>
        <span class="badge">👁️ visitante</span>
    <?php endif; ?>

    <?php if ($ehAdmin): ?>
        <a class="link-debug" href="?debug_sessao=1">🔍 ver sessão</a>
    <?php endif; ?>
</div>

<?php if ($erro): ?>
    <div class="aviso erro"><strong>Ops:</strong> <?= h($erro) ?></div>
<?php endif; ?>

<?php if (!$erro && !$temAno): ?>
    <div class="aviso">
        <strong>Dica:</strong> a coluna <code>ano</code> ainda não existe em <code>votos</code>.
        O filtro de ano está desabilitado.
    </div>
<?php endif; ?>

<?php if (!$erro && !$colCoord && !$colLider): ?>
    <div class="aviso">
        <strong>Aviso:</strong> não encontrei as colunas <code>coordenador</code> nem
        <code>lideranca</code> em <code>secoes_enderecos</code>.
        O tooltip mostrará apenas município e coordenadas.
        <br><small style="color:#666;">
            Se as colunas tiverem outro nome, ajuste a lista em <code>achaColuna()</code> no topo do arquivo.
        </small>
    </div>
<?php endif; ?>

<form method="get" action="<?= h(basename($_SERVER['PHP_SELF'])) ?>" id="form-filtros">
    <input type="hidden" name="opac"   id="hOpac"   value="<?= h(number_format($opacidade, 2, '.', '')) ?>">
    <input type="hidden" name="escala" id="hEscala" value="<?= h(number_format($escala, 1, '.', '')) ?>">
    <input type="hidden" name="limiar" id="hLimiar" value="<?= (int)$limiar ?>">

    <div class="filtros">
        <?php if ($temAno && $anosDisponiveis): ?>
            <label for="ano">Ano:</label>
            <select name="ano" id="ano" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($anosDisponiveis as $a): ?>
                    <option value="<?= (int)$a ?>" <?= (string)$a === $ano ? 'selected' : '' ?>><?= (int)$a ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <label for="regiao">Região:</label>
        <select name="regiao" id="regiao" onchange="this.form.submit()">
            <option value="">Todas</option>
            <?php foreach ($regioes as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= (string)$r['id'] === $regiao ? 'selected' : '' ?>>
                    <?= h($r['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="cargo">Cargo:</label>
        <select name="cargo" id="cargo" onchange="this.form.submit()">
            <option value="">Todos</option>
            <?php foreach ($cargos as $c): ?>
                <option value="<?= h($c) ?>" <?= $c === $cargo ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="chk-vazias <?= $incluirVazias ? 'ativo' : '' ?>">
            <input type="checkbox" name="vazias" value="1" <?= $incluirVazias ? 'checked' : '' ?>
                   onchange="this.form.submit()">
            Incluir seções sem voto (NENHUM)
        </label>
    </div>

    <div class="filtros" style="margin-top:8px;">
        <label for="cand1"><span class="cand-tag c1"><span class="dot"></span>Candidato 1:</span></label>
        <select name="cand1" id="cand1">
            <option value="">-- Selecione --</option>
            <?php foreach ($candidatos as $c): ?>
                <option value="<?= h($c) ?>" <?= $c === $cand1 ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="cand2"><span class="cand-tag c2"><span class="dot"></span>Candidato 2 (opcional):</span></label>
        <select name="cand2" id="cand2">
            <option value="">-- Nenhum --</option>
            <?php foreach ($candidatos as $c): ?>
                <option value="<?= h($c) ?>" <?= $c === $cand2 ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn-consultar">Gerar Mapa</button>
        <a href="<?= h(basename($_SERVER['PHP_SELF'])) ?>" class="btn-limpar">Limpar</a>
    </div>
</form>

<?php if ($erro): ?>
    <?php /* erro já exibido acima */ ?>

<?php elseif (!$temAlgum): ?>

    <div class="aviso">
        Selecione <strong>um ou dois candidatos</strong> para gerar o mapa.
        <br><small style="color:#666;">
            Com 1 candidato → mapa de intensidade.
            Com 2 candidatos → modos <b>margem relativa</b>, <b>donut</b> e <b>zonas de disputa</b>.
        </small>
    </div>

<?php elseif (!$pontos): ?>

    <div class="aviso">
        Nenhuma seção com voto e coordenada foi encontrada para os filtros.
        Confirme se <code>secoes_enderecos</code> tem as coordenadas das seções.
    </div>

<?php else: ?>

    <!-- KPIs -->
    <div class="kpis">
        <div class="kpi c1" id="kpiC1">
            <div class="rotulo"><?= h($cand1) ?></div>
            <div class="valor" id="kpiC1Valor"><?= fmtInt($totC1) ?></div>
            <div class="sub" id="kpiC1Sub"></div>
        </div>

        <?php if ($temComparacao): ?>
            <div class="kpi c2" id="kpiC2">
                <div class="rotulo"><?= h($cand2) ?></div>
                <div class="valor" id="kpiC2Valor"><?= fmtInt($totC2) ?></div>
                <div class="sub" id="kpiC2Sub"></div>
            </div>
            <div class="kpi roxo" id="kpiDif">
                <div class="rotulo">Diferença</div>
                <div class="valor" id="kpiDifValor">0</div>
                <div class="sub" id="kpiDifSub"></div>
            </div>
        <?php endif; ?>

        <div class="kpi cinza">
            <div class="rotulo">Seções no mapa</div>
            <div class="valor" id="kpiSecoesValor"><?= fmtInt($totalSecoes) ?></div>
        </div>
    </div>

    <?php if (!$temComparacao): ?>
        <div class="modo-unico-badge">
            ℹ️ Exibindo <b><?= h($cand1) ?></b>. Selecione um segundo candidato para habilitar a comparação.
        </div>
    <?php else: ?>
        <h2>Visualização</h2>
        <div class="modo-btns" id="modo-btns">
            <button type="button" data-modo="margem" class="ativo">🎨 Margem relativa</button>
            <button type="button" data-modo="donut">🍩 Proporção (donut)</button>
            <button type="button" data-modo="disputa">⚔️ Zonas de disputa</button>
            <button type="button" data-modo="c1">🟢 Apenas <?= h($cand1) ?></button>
            <button type="button" data-modo="c2">🔵 Apenas <?= h($cand2) ?></button>
        </div>
    <?php endif; ?>

    <div class="controles" id="controles">
        <label for="ctrlEscala">Escala:</label>
        <input type="range" id="ctrlEscala" min="0.5" max="2.5" step="0.1" value="<?= h($escala) ?>">
        <span class="valor" id="ctrlEscalaVal"><?= h(number_format($escala, 1)) ?>×</span>

        <span class="sep">
            <label for="ctrlOpac">Opacidade:</label>
            <input type="range" id="ctrlOpac" min="0.1" max="1" step="0.05" value="<?= h($opacidade) ?>">
            <span class="valor" id="ctrlOpacVal"><?= h(number_format($opacidade, 2)) ?></span>
        </span>

        <span class="grupo-limiar">
            <label for="ctrlLimiar">Limiar de disputa:</label>
            <input type="range" id="ctrlLimiar" min="0" max="30" step="1" value="<?= (int)$limiar ?>">
            <span class="valor" id="ctrlLimiarVal"><?= (int)$limiar ?>% acima</span>
        </span>

        <?php if ($temComparacao): ?>
            <span class="sep">
                <label class="cand-toggle c1 ativo" id="toggleC1">
                    <input type="checkbox" id="mostrarC1" checked>
                    <span class="cor"></span>
                    <span class="nome" title="<?= h($cand1) ?>"><?= h($cand1) ?></span>
                </label>
                <label class="cand-toggle c2 ativo" id="toggleC2" style="margin-left:8px;">
                    <input type="checkbox" id="mostrarC2" checked>
                    <span class="cor"></span>
                    <span class="nome" title="<?= h($cand2) ?>"><?= h($cand2) ?></span>
                </label>
            </span>
        <?php endif; ?>
    </div>

    <div class="legenda-cores" id="legenda-cores"></div>

    <div class="acoes">
        <button type="button" class="btn-acao" id="btnCopiar">🔗 Copiar link desta visão</button>
        <button type="button" class="btn-acao" id="btnCsvSecoes">⬇ Exportar seções (CSV)</button>
        <button type="button" class="btn-acao" onclick="window.print()">🖨️ Imprimir</button>
    </div>

    <div id="mapa"></div>

    <div class="paineis">
    <!-- Tabela comparativa por município -->
    <?php if ($porMunicipio): ?>
    <div class="tabela-wrap">
        <div class="tabela-header">
            <h2>📊 Comparativo por município
                <span class="contagem">(<?= fmtInt(count($porMunicipio)) ?> municípios)</span>
            </h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <button type="button" class="btn-acao" id="btnCsvMun">⬇ CSV</button>
                <input type="text" id="buscaMunicipio" class="tabela-filtro" placeholder="🔎 Filtrar município...">
            </div>
        </div>
        <div class="tabela-scroll">
            <table class="tabela-municipios" id="tabelaMun">
                <thead>
                    <tr>
                        <th class="ord" data-k="nome">Município</th>
                        <th class="ord num" data-k="secoes">Seções</th>
                        <th class="ord num col-c1" data-k="c1"><?= h($cand1) ?></th>
                        <th class="ord num col-c2" data-k="c2"><?= h($cand2 ?: 'Candidato 2') ?></th>
                        <th class="ord col-vant" data-k="lider">Liderança</th>
                    </tr>
                </thead>
                <tbody id="tabelaMunBody">
                    <?php foreach ($porMunicipio as $m): ?>
                        <tr data-mun="<?= h($m['norm']) ?>" data-nome="<?= h($m['nome']) ?>"
                            data-secoes="<?= (int)$m['secoes'] ?>" data-c1="<?= (int)$m['c1'] ?>" data-c2="<?= (int)$m['c2'] ?>"
                            data-lider="<?= $m['tot'] > 0 ? round(($m['c1'] - $m['c2']) / $m['tot'], 4) : 0 ?>"
                            title="Clique para ampliar este município no mapa">
                            <td><?= h($m['nome']) ?></td>
                            <td class="num"><?= fmtInt($m['secoes']) ?></td>
                            <td class="num col-c1"><?= fmtInt($m['c1']) ?></td>
                            <td class="num col-c2"><?= fmtInt($m['c2']) ?></td>
                            <td class="col-vant"><?= htmlLideranca($m['c1'], $m['c2'], $cand1, $cand2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="total-geral">
                    <tr>
                        <td>TOTAL GERAL</td>
                        <td class="num"><?= fmtInt($totalSecoes) ?></td>
                        <td class="num col-c1"><?= fmtInt($totC1) ?></td>
                        <td class="num col-c2"><?= fmtInt($totC2) ?></td>
                        <td class="col-vant"><?= htmlLideranca($totC1, $totC2, $cand1, $cand2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="sem-resultado" id="semResultadoMun">Nenhum município encontrado com o filtro atual.</div>
    </div>
    <?php endif; ?>

    <!-- Resumo: quantos municípios cada candidato lidera -->
    <?php if ($linhasResumo): ?>
        <div class="resumo-wrap" id="resumoWrap">
            <div class="tabela-header"><h2>🏆 Municípios em que cada candidato lidera</h2></div>
            <table class="tabela-resumo">
                <thead>
                    <tr>
                        <th>Candidato</th>
                        <th class="num">Municípios liderando</th>
                        <th class="num">% dos municípios</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linhasResumo as [$trCls, $dot, $celCls, $barCls, $rotulo, $qtd, $sempre]): ?>
                        <?php if (!$sempre && $qtd === 0) continue; ?>
                        <?php $pct = $resumo['total'] > 0 ? ($qtd / $resumo['total']) * 100 : 0; ?>
                        <tr class="<?= $trCls ?>">
                            <td class="<?= $celCls ?>"><span class="resumo-dot <?= $dot ?>"></span><b><?= h($rotulo) ?></b></td>
                            <td class="num <?= $celCls ?>"><?= fmtInt($qtd) ?></td>
                            <td class="num <?= $celCls ?>">
                                <?= number_format($pct, 1, ',', '.') ?>%
                                <div class="resumo-barra"><div class="<?= $barCls ?>" style="width:<?= round($pct, 2) ?>%;"></div></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="resumo-linha-total">
                        <td>Total de municípios</td>
                        <td class="num"><?= fmtInt($resumo['total']) ?></td>
                        <td class="num">100,0%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>

    </div><!-- /paineis -->

    <script>
        const PONTOS         = <?= jsonJs($pontos) ?>;
        const CAND1          = <?= jsonJs($cand1) ?>;
        const CAND2          = <?= jsonJs($cand2) ?>;
        const TEM_COMP       = <?= $temComparacao ? 'true' : 'false' ?>;
        const INCLUIR_VAZIAS = <?= $incluirVazias ? 'true' : 'false' ?>;
        const MAX_C1         = <?= (int)$maxC1 ?>;
        const MAX_C2         = <?= (int)$maxC2 ?>;
        const MAX_TOT        = <?= (int)$maxTot ?>;
        let ESCALA_ATUAL     = <?= (float)$escala ?>;
        let OPAC_ATUAL       = <?= (float)$opacidade ?>;
        let LIMIAR_ATUAL     = <?= (int)$limiar ?>;
    </script>
    <script>
(function () {
    'use strict';

    // ---------- utilitários ----------
    const $ = id => document.getElementById(id);
    const formatNum = n => Number(n).toLocaleString('pt-BR');
    const fmtVantagem = pp => pp.toLocaleString('pt-BR', {
        minimumFractionDigits: 1, maximumFractionDigits: 1
    }) + '% acima';
    const escapeHtml = s => String(s).replace(/[&<>"']/g, c => (
        { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]
    ));
    const normaliza = s => String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const temTexto = v => v != null && String(v).trim() !== '';

    const COR_C1 = '#28a745', COR_C1_TXT = '#1b5e20';
    const COR_C2 = '#0d6efd', COR_C2_TXT = '#0d47a1';

    // ---------- mapa (canvas = bem mais leve com milhares de seções) ----------
    const mapa = L.map('mapa', { scrollWheelZoom: true, preferCanvas: true })
                  .setView([-8.05, -34.9], 10);

    const baseOSM = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    });
    const baseClaro = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        maxZoom: 19, subdomains: 'abcd',
        attribution: '&copy; OpenStreetMap &copy; <a href="https://carto.com/attributions">CARTO</a>'
    });
    const baseSat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19, attribution: 'Tiles &copy; Esri'
    });
    baseClaro.addTo(mapa);   // fundo claro destaca melhor as cores dos círculos
    L.control.layers({ 'Claro': baseClaro, 'Ruas (OSM)': baseOSM, 'Satélite': baseSat },
                     null, { position: 'topright' }).addTo(mapa);

    if (!PONTOS.length) return;

    // Espalha seções que compartilham exatamente a mesma coordenada
    (function espalhar() {
        const grupos = {};
        PONTOS.forEach(p => {
            const key = p.lat.toFixed(5) + ',' + p.lng.toFixed(5);
            (grupos[key] = grupos[key] || []).push(p);
        });
        Object.values(grupos).forEach(g => {
            if (g.length < 2) return;
            const raio = 0.0005 + 0.0002 * Math.min(g.length, 10);
            g.forEach((p, i) => {
                const ang = (2 * Math.PI * i) / g.length;
                p.lat += raio * Math.cos(ang);
                p.lng += raio * Math.sin(ang);
            });
        });
    })();

    const bounds = L.latLngBounds(PONTOS.map(p => [p.lat, p.lng]));
    if (bounds.isValid()) mapa.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 });

    // ---------- estado ----------
    let visC1 = true, visC2 = true;     // checkboxes
    let efC1  = true, efC2  = false;    // candidatos efetivamente exibidos
    let nDesenhadas = 0;
    let modoAtual = TEM_COMP ? 'margem' : 'c1';

    // ---------- cores ----------
    const ESCALA_CORES = [
        [-1.00, [ 13,  71, 161]], [-0.50, [ 25, 118, 210]],
        [-0.20, [100, 181, 246]], [-0.05, [187, 222, 251]],
        [ 0.00, [245, 245, 245]], [ 0.05, [200, 230, 201]],
        [ 0.20, [165, 214, 167]], [ 0.50, [ 67, 160,  71]],
        [ 1.00, [ 27,  94,  32]]
    ];
    function corMargem(t) {
        t = Math.max(-1, Math.min(1, t));
        for (let i = 0; i < ESCALA_CORES.length - 1; i++) {
            const [p0, a] = ESCALA_CORES[i];
            const [p1, b] = ESCALA_CORES[i + 1];
            if (t >= p0 && t <= p1) {
                const k = (t - p0) / (p1 - p0);
                return `rgb(${Math.round(a[0] + k * (b[0] - a[0]))},` +
                       `${Math.round(a[1] + k * (b[1] - a[1]))},` +
                       `${Math.round(a[2] + k * (b[2] - a[2]))})`;
            }
        }
        return 'rgb(245,245,245)';
    }
    const corC1 = v => {
        const t = MAX_C1 > 0 ? v / MAX_C1 : 0;
        return `rgb(${Math.round(232 - 205 * t)},${Math.round(245 - 151 * t)},${Math.round(233 - 201 * t)})`;
    };
    const corC2 = v => {
        const t = MAX_C2 > 0 ? v / MAX_C2 : 0;
        return `rgb(${Math.round(227 - 214 * t)},${Math.round(242 - 171 * t)},${Math.round(253 - 92 * t)})`;
    };
    const margem = p => (p.c1 + p.c2) > 0 ? (p.c1 - p.c2) / (p.c1 + p.c2) : 0;
    const raio   = (v, max) => Math.max(3, (4 + 14 * Math.sqrt(v / Math.max(max, 1))) * ESCALA_ATUAL);

    // ---------- tooltip / popup (corpo compartilhado) ----------
    function cabecalhoHTML(p) {
        let h = `<div class="tt-sec">Seção ${p.secao} — Zona ${p.zona}</div>`;
        if (p.mun)   h += `<div class="tt-mun">🏙️ ${escapeHtml(p.mun)}</div>`;
        if (p.local) h += `<div class="tt-local">📍 ${escapeHtml(p.local)}</div>`;
        h += `<div class="tt-geo">🌐 ${p.lat.toFixed(5)}, ${p.lng.toFixed(5)}</div>`;
        if (temTexto(p.coord)) h += `<div class="tt-resp" style="margin-top:3px;">👤 Coordenador: <b>${escapeHtml(p.coord)}</b></div>`;
        if (temTexto(p.lider)) h += `<div class="tt-resp">👥 Liderança: <b>${escapeHtml(p.lider)}</b></div>`;
        return h;
    }

    function corpoHTML(p, detalhado) {
        const tot = p.c1 + p.c2;
        if (tot === 0 || (!efC1 && !efC2)) {
            return `<hr class="tt-sep"><div class="tt-nenhum">NENHUM</div>
                    <div class="tt-sub">sem votos dos candidatos selecionados</div>`;
        }

        let h = '<hr class="tt-sep">';
        if (efC1) h += `<div class="tt-linha"><span style="color:${COR_C1};font-weight:bold;">● ${escapeHtml(CAND1)}</span><b>${formatNum(p.c1)}</b></div>`;
        if (efC2) h += `<div class="tt-linha"><span style="color:${COR_C2};font-weight:bold;">● ${escapeHtml(CAND2)}</span><b>${formatNum(p.c2)}</b></div>`;

        if (efC1 && efC2) {
            const diff = p.c1 - p.c2;

            if (detalhado) {
                const p1 = (p.c1 / tot) * 100, p2 = 100 - p1;
                h += `<div class="tt-barra">
                        <div style="width:${p1}%;background:${COR_C1};"></div>
                        <div style="width:${p2}%;background:${COR_C2};"></div>
                      </div>
                      <div class="tt-pct"><span>${p1.toFixed(1)}%</span><span>${p2.toFixed(1)}%</span></div>`;
            } else {
                h += '<hr class="tt-sep">';
            }

            if (diff === 0) {
                h += `<div class="tt-centro" style="color:#999;">${detalhado ? 'Empate exato' : 'Empate'}</div>`;
            } else {
                const quem = diff > 0 ? CAND1 : CAND2;
                const cor  = diff > 0 ? COR_C1 : COR_C2;
                const pp   = Math.abs(diff / tot * 100);
                h += `<div class="tt-centro"><b style="color:${cor};">${escapeHtml(quem)}</b> ` +
                     (detalhado ? `lidera por ${fmtVantagem(pp)}`
                                : `<span style="color:#666;">lidera (${fmtVantagem(pp)})</span>`) + '</div>';
            }
        }
        return h;
    }

    const tooltipHTML = p => `<div style="min-width:220px;font-size:12px;line-height:1.55;">${cabecalhoHTML(p)}${corpoHTML(p, false)}</div>`;
    const popupHTML   = p => `<div style="min-width:250px;">${cabecalhoHTML(p)}${corpoHTML(p, true)}</div>`;

    // ---------- desenho ----------
    const camada = L.layerGroup().addTo(mapa);
    const limpar = () => { camada.clearLayers(); nDesenhadas = 0; };

    function adicionar(layer, p) {
        layer.bindPopup(() => popupHTML(p))
             .bindTooltip(() => tooltipHTML(p), { sticky: true, direction: 'top', opacity: 1 })
             .addTo(camada);
        nDesenhadas++;
    }
    const adicionarVazia = p => adicionar(L.circleMarker([p.lat, p.lng], {
        radius: 3, fillColor: '#c8c8c8', fillOpacity: 0.35, color: '#999', weight: 0.4
    }), p);

    const circulo = (p, r, cor, extra) => adicionar(L.circleMarker([p.lat, p.lng], Object.assign({
        radius: r, fillColor: cor, fillOpacity: OPAC_ATUAL, color: 'rgba(0,0,0,.30)', weight: 0.6
    }, extra || {})), p);

    function desenharMargem() {
        limpar();
        PONTOS.forEach(p => {
            if (p.tot === 0) return adicionarVazia(p);
            circulo(p, raio(p.tot, MAX_TOT), corMargem(margem(p)));
        });
    }

    function desenharDonut() {
        limpar();
        PONTOS.forEach(p => {
            if (p.tot === 0) return adicionarVazia(p);
            const r    = raio(p.tot, MAX_TOT) + 1;
            const c    = 2 * Math.PI * r;
            const size = 2 * r + 2;
            const cx   = r + 1;
            const sw   = Math.max(2, r * 0.85);
            const ri   = r - sw / 2;
            const svg = `
              <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
                <circle cx="${cx}" cy="${cx}" r="${ri}" fill="none" stroke="${COR_C2}" stroke-width="${sw}"/>
                <circle cx="${cx}" cy="${cx}" r="${ri}" fill="none" stroke="${COR_C1}" stroke-width="${sw}"
                        stroke-dasharray="${(c * p.c1 / p.tot).toFixed(2)} ${c.toFixed(2)}"
                        transform="rotate(-90 ${cx} ${cx})"/>
                <circle cx="${cx}" cy="${cx}" r="${Math.max(1, ri - sw / 2)}" fill="#fff"/>
              </svg>`;
            adicionar(L.marker([p.lat, p.lng], {
                icon: L.divIcon({ html: svg, className: 'donut-ico', iconSize: [size, size], iconAnchor: [cx, cx] })
            }), p);
        });
    }

    function desenharDisputa() {
        limpar();
        const lim = LIMIAR_ATUAL / 100;
        PONTOS.forEach(p => {
            if (p.tot === 0) return;
            const m = margem(p);
            if (Math.abs(m) > lim) return;
            circulo(p, raio(p.tot, MAX_TOT) + 1.5, corMargem(m), { color: '#111', weight: 1.4 });
        });
    }

    function desenharUnico(qual) {
        limpar();
        const usarC1 = (qual === 'c1');
        if (!usarC1 && !TEM_COMP) return;
        const max = usarC1 ? MAX_C1 : MAX_C2;
        PONTOS.forEach(p => {
            const v = usarC1 ? p.c1 : p.c2;
            if (v <= 0) { if (INCLUIR_VAZIAS) adicionarVazia(p); return; }
            circulo(p, raio(v, max), usarC1 ? corC1(v) : corC2(v));
        });
    }

    // ---------- legenda ----------
    const elLegenda = $('legenda-cores');
    function atualizarLegenda(modo) {
        const vazias = INCLUIR_VAZIAS ? ' · círculos cinza = sem voto (NENHUM)' : '';
        const nota = t => `<span style="font-size:11px;color:#666;">${t}</span>`;

        if (modo === 'vazio') {
            elLegenda.innerHTML = '<span class="legenda-vazia">⚠ Nenhum candidato visível — ative ao menos um.</span>';
        } else if (modo === 'margem' || modo === 'disputa') {
            const extra = modo === 'disputa' ? ` · mostrando só seções com diferença ≤ ${LIMIAR_ATUAL}% acima` : '';
            elLegenda.innerHTML = `
              <div class="item">
                <span style="color:${COR_C2_TXT};font-weight:bold;">${escapeHtml(CAND2)} forte</span>
                <span class="grad grad-margem"></span>
                <span style="color:${COR_C1_TXT};font-weight:bold;">${escapeHtml(CAND1)} forte</span>
              </div>
              ${nota('branco ≈ empate · tamanho do círculo = total de votos entre os dois' + extra + vazias)}`;
        } else if (modo === 'donut') {
            elLegenda.innerHTML = `
              <div class="item"><span style="color:${COR_C1};font-weight:bold;">● ${escapeHtml(CAND1)}</span></div>
              <div class="item"><span style="color:${COR_C2};font-weight:bold;">● ${escapeHtml(CAND2)}</span></div>
              ${nota('fatia interna = proporção em cada seção · tamanho = total' + vazias)}`;
        } else if (modo === 'c1' || modo === 'c2') {
            const c1 = modo === 'c1';
            const oculto = !TEM_COMP || modoAtual === modo ? ''
                : ` <span style="font-size:11px;color:#888;">(${c1 ? 'C2' : 'C1'} oculto)</span>`;
            elLegenda.innerHTML = `
              <div class="item">
                <span style="color:${c1 ? COR_C1 : COR_C2};font-weight:bold;">${escapeHtml(c1 ? CAND1 : CAND2)}</span>
                <span class="grad ${c1 ? 'grad-c1' : 'grad-c2'}"></span>
                <span style="font-size:11px;color:#888;">menos → mais</span>
              </div>${oculto}`;
        } else {
            elLegenda.innerHTML = '';
        }
    }

    // ---------- elementos da UI ----------
    const modosBtns = document.querySelectorAll('#modo-btns button');
    const ckC1 = $('mostrarC1'), ckC2 = $('mostrarC2');
    const togC1 = $('toggleC1'), togC2 = $('toggleC2');
    const kpiC1 = $('kpiC1'), kpiC2 = $('kpiC2'), kpiDif = $('kpiDif');
    const cont = $('controles');
    const tabelaMun = $('tabelaMun'), resumoWrap = $('resumoWrap');

    function modoEfetivo() {
        if (!TEM_COMP) return 'c1';
        if (!visC1 && !visC2) return 'vazio';
        if (visC1 && !visC2)  return 'c1';
        if (!visC1 && visC2)  return 'c2';
        return modoAtual;
    }
    function definirVisibilidadeEfetiva(m) {
        efC1 = (m === 'c1' || m === 'margem' || m === 'donut' || m === 'disputa');
        efC2 = (m === 'c2' || m === 'margem' || m === 'donut' || m === 'disputa') && TEM_COMP;
    }

    function atualizarBotoes(m) {
        modosBtns.forEach(btn => {
            const modo = btn.dataset.modo;
            const off  = (!visC1 && !visC2) ||
                         (visC1 && !visC2 && modo !== 'c1') ||
                         (!visC1 && visC2 && modo !== 'c2');
            btn.disabled = off;
            btn.classList.toggle('ativo', modo === m && !off);
        });
    }
    function estiloToggle(tog, ativo) {
        if (!tog) return;
        tog.classList.toggle('ativo', ativo);
        tog.classList.toggle('oculto', !ativo);
    }

    function atualizarKPI(card, valorId, subId, mostrar, total, secoes) {
        if (!card) return;
        card.classList.toggle('oculto', !mostrar);
        $(valorId).textContent = mostrar ? formatNum(total) : '';
        $(subId).textContent   = mostrar ? 'votos em ' + formatNum(secoes) + ' seções' : '';
    }
    function atualizarKPIs() {
        let t1 = 0, t2 = 0, s1 = 0, s2 = 0;
        PONTOS.forEach(p => {
            t1 += p.c1; t2 += p.c2;
            if (p.c1 > 0) s1++;
            if (p.c2 > 0) s2++;
        });
        atualizarKPI(kpiC1, 'kpiC1Valor', 'kpiC1Sub', efC1, t1, s1);
        atualizarKPI(kpiC2, 'kpiC2Valor', 'kpiC2Sub', efC2, t2, s2);

        if (kpiDif) {
            const comparando = efC1 && efC2;
            kpiDif.classList.toggle('oculto', !comparando);
            if (comparando) {
                const dif = Math.abs(t1 - t2), tot = t1 + t2;
                $('kpiDifValor').textContent = formatNum(dif);
                $('kpiDifSub').textContent = t1 === t2
                    ? 'Empate técnico'
                    : (t1 > t2 ? CAND1 : CAND2) + ' lidera por ' + fmtVantagem(tot > 0 ? dif / tot * 100 : 0);
            }
        }
        $('kpiSecoesValor').textContent = formatNum(nDesenhadas);
    }

    function atualizarTabelas() {
        [tabelaMun, resumoWrap].forEach(el => {
            if (!el) return;
            el.classList.toggle('ocultar-c1', !efC1);
            el.classList.toggle('ocultar-c2', !efC2);
        });
    }

    function redesenhar() {
        const m = modoEfetivo();
        definirVisibilidadeEfetiva(m);

        if      (m === 'vazio')   limpar();
        else if (m === 'c1')      desenharUnico('c1');
        else if (m === 'c2')      desenharUnico('c2');
        else if (m === 'margem')  desenharMargem();
        else if (m === 'donut')   desenharDonut();
        else if (m === 'disputa') desenharDisputa();

        atualizarBotoes(m);
        estiloToggle(togC1, visC1);
        estiloToggle(togC2, visC2);
        atualizarKPIs();
        atualizarTabelas();
        atualizarLegenda(m);
        cont.classList.toggle('modo-disputa', m === 'disputa');
    }

    // ---------- eventos ----------
    modosBtns.forEach(btn => btn.addEventListener('click', () => {
        if (btn.disabled) return;
        modoAtual = btn.dataset.modo;
        redesenhar();
    }));

    function aoAlternar() {
        mapa.closePopup();
        if (visC1 && visC2 && (modoAtual === 'c1' || modoAtual === 'c2')) modoAtual = 'margem';
        redesenhar();
    }
    if (ckC1) ckC1.addEventListener('change', () => { visC1 = ckC1.checked; aoAlternar(); });
    if (ckC2) ckC2.addEventListener('change', () => { visC2 = ckC2.checked; aoAlternar(); });

    // filtro da tabela de municípios
    const inputBusca = $('buscaMunicipio'), semRes = $('semResultadoMun');
    if (inputBusca) inputBusca.addEventListener('input', () => {
        const termo = normaliza(inputBusca.value.trim());
        let visiveis = 0;
        document.querySelectorAll('#tabelaMunBody tr').forEach(tr => {
            const ok = !termo || (tr.dataset.mun || '').includes(termo);
            tr.style.display = ok ? '' : 'none';
            if (ok) visiveis++;
        });
        if (semRes) semRes.style.display = visiveis === 0 ? 'block' : 'none';
    });

    // sliders (também gravam nos campos ocultos do formulário, para não perder ao filtrar)
    function ligarSlider(id, valId, hiddenId, fmt, onChange) {
        const el = $(id);
        if (!el) return;
        el.addEventListener('input', () => {
            const v = parseFloat(el.value);
            $(valId).textContent = fmt(v);
            $(hiddenId).value = el.value;
            onChange(v);
        });
    }
    ligarSlider('ctrlEscala', 'ctrlEscalaVal', 'hEscala', v => v.toFixed(1) + '×',
        v => { ESCALA_ATUAL = v; redesenhar(); });
    ligarSlider('ctrlOpac', 'ctrlOpacVal', 'hOpac', v => v.toFixed(2),
        v => { OPAC_ATUAL = v; redesenhar(); });
    ligarSlider('ctrlLimiar', 'ctrlLimiarVal', 'hLimiar', v => Math.round(v) + '% acima',
        v => { LIMIAR_ATUAL = Math.round(v); if (modoEfetivo() === 'disputa') redesenhar(); });

    // =====================================================
    // v3 — novas funcionalidades
    // =====================================================
    function toast(msg) {
        let t = $('toast');
        if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; document.body.appendChild(t); }
        t.textContent = msg;
        t.classList.add('on');
        clearTimeout(t._h);
        t._h = setTimeout(() => t.classList.remove('on'), 2200);
    }

    // --- botões no mapa: enquadrar + tela cheia ---
    const elMapa = $('mapa');
    let btnFull = null;
    const enquadrar = () => mapa.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 });
    function telaCheia(ligar) {
        const on = (ligar === undefined) ? !elMapa.classList.contains('mapa-full') : ligar;
        elMapa.classList.toggle('mapa-full', on);
        document.body.style.overflow = on ? 'hidden' : '';
        if (btnFull) { btnFull.textContent = on ? '✕' : '⤢'; btnFull.title = on ? 'Sair da tela cheia (Esc)' : 'Tela cheia'; }
        setTimeout(() => mapa.invalidateSize(), 50);
    }
    document.addEventListener('keydown', e => { if (e.key === 'Escape') telaCheia(false); });

    const Acoes = L.Control.extend({
        options: { position: 'topleft' },
        onAdd() {
            const box = L.DomUtil.create('div', 'leaflet-bar leaflet-control-custom');
            const mk = (txt, title, fn) => {
                const a = L.DomUtil.create('a', '', box);
                a.href = '#'; a.title = title; a.textContent = txt;
                L.DomEvent.on(a, 'click', e => { L.DomEvent.preventDefault(e); L.DomEvent.stopPropagation(e); fn(); });
                return a;
            };
            mk('🎯', 'Enquadrar todas as seções', enquadrar);
            btnFull = mk('⤢', 'Tela cheia', () => telaCheia());
            return box;
        }
    });
    mapa.addControl(new Acoes());

    // --- link compartilhável (filtros + sliders + modo) ---
    $('btnCopiar').addEventListener('click', async () => {
        const u = new URL(location.href);
        u.searchParams.set('opac',   OPAC_ATUAL.toFixed(2));
        u.searchParams.set('escala', ESCALA_ATUAL.toFixed(1));
        u.searchParams.set('limiar', LIMIAR_ATUAL);
        u.hash = 'modo=' + modoEfetivo();
        try { await navigator.clipboard.writeText(u.toString()); toast('Link copiado!'); }
        catch (e) { window.prompt('Copie o link:', u.toString()); }
    });
    // modo na URL (#modo=disputa)
    const modoHash = (location.hash.match(/modo=(\w+)/) || [])[1];
    if (TEM_COMP && ['margem', 'donut', 'disputa', 'c1', 'c2'].includes(modoHash)) modoAtual = modoHash;
    modosBtns.forEach(b => b.addEventListener('click', () => {
        if (!b.disabled) history.replaceState(null, '', '#modo=' + b.dataset.modo);
    }));

    // --- CSV ---
    const csvCel = v => {
        const s = String(v == null ? '' : v);
        return /[;"\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    };
    function baixarCSV(nome, linhas) {
        const txt  = '\uFEFF' + linhas.map(l => l.map(csvCel).join(';')).join('\r\n');   // BOM + ";" = abre certo no Excel pt-BR
        const url  = URL.createObjectURL(new Blob([txt], { type: 'text/csv;charset=utf-8' }));
        const a    = document.createElement('a');
        a.href = url; a.download = nome;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    const hoje = () => new Date().toISOString().slice(0, 10);

    $('btnCsvSecoes').addEventListener('click', () => {
        const cab = ['Zona', 'Seção', 'Município', 'Local', 'Coordenador', 'Liderança', CAND1];
        if (TEM_COMP) cab.push(CAND2);
        cab.push('Total');
        const linhas = [cab];
        PONTOS.forEach(p => {
            const l = [p.zona, p.secao, p.mun, p.local, p.coord, p.lider, p.c1];
            if (TEM_COMP) l.push(p.c2);
            l.push(p.tot);
            linhas.push(l);
        });
        baixarCSV('secoes_' + hoje() + '.csv', linhas);
        toast(formatNum(PONTOS.length) + ' seções exportadas');
    });

    const btnCsvMun = $('btnCsvMun');
    if (btnCsvMun) btnCsvMun.addEventListener('click', () => {
        const cab = ['Município', 'Seções', CAND1];
        if (TEM_COMP) cab.push(CAND2, 'Liderança');
        const linhas = [cab];
        document.querySelectorAll('#tabelaMunBody tr').forEach(tr => {
            if (tr.style.display === 'none') return;          // respeita o filtro de busca
            const d = tr.dataset;
            const l = [d.nome, d.secoes, d.c1];
            if (TEM_COMP) l.push(d.c2, tr.lastElementChild.textContent.replace(/\s+/g, ' ').trim());
            linhas.push(l);
        });
        baixarCSV('municipios_' + hoje() + '.csv', linhas);
        toast((linhas.length - 1) + ' municípios exportados');
    });

    // --- ordenar tabela de municípios ---
    const thsOrd = document.querySelectorAll('#tabelaMun th.ord');
    let ordCol = null, ordDir = 1;
    thsOrd.forEach(th => th.addEventListener('click', () => {
        const k = th.dataset.k;
        ordDir = (ordCol === k) ? -ordDir : (k === 'nome' ? 1 : -1);
        ordCol = k;
        thsOrd.forEach(t => t.classList.remove('asc', 'desc'));
        th.classList.add(ordDir === 1 ? 'asc' : 'desc');
        const corpo = $('tabelaMunBody');
        [...corpo.rows].sort((a, b) => k === 'nome'
            ? ordDir * a.dataset.nome.localeCompare(b.dataset.nome, 'pt-BR')
            : ordDir * (parseFloat(a.dataset[k]) - parseFloat(b.dataset[k]))
        ).forEach(r => corpo.appendChild(r));
    }));

    // --- clicar no município = ampliar no mapa ---
    const corpoMun = $('tabelaMunBody');
    if (corpoMun) corpoMun.addEventListener('click', e => {
        const tr = e.target.closest('tr');
        if (!tr) return;
        const nome = tr.dataset.nome === '(sem município)' ? '' : tr.dataset.nome;
        const pts  = PONTOS.filter(p => (p.mun || '') === nome);
        if (!pts.length) return;
        mapa.closePopup();
        mapa.fitBounds(L.latLngBounds(pts.map(p => [p.lat, p.lng])), { padding: [50, 50], maxZoom: 15 });
        corpoMun.querySelectorAll('tr.sel').forEach(r => r.classList.remove('sel'));
        tr.classList.add('sel');
        elMapa.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    redesenhar();
})();
    </script>

<?php endif; ?>

<!-- jQuery + Select2 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>
<script>
    (function () {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;

        const semAcento = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        function matcherSemAcento(params, data) {
            const termo = (params.term || '').trim();
            if (termo === '') return data;
            return semAcento((data.text || '').toLowerCase()).indexOf(semAcento(termo.toLowerCase())) > -1
                ? data : null;
        }

        ['#cand1', '#cand2'].forEach(sel => jQuery(sel).select2({
            language: 'pt-BR',
            placeholder: 'Buscar candidato...',
            allowClear: true,
            width: 'resolve',
            matcher: matcherSemAcento
        }));
    })();
</script>

</body>
</html>