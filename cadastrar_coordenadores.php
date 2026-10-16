<?php
// ------------------------------------------------------------
// cadastrar_coordenadores.php
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

$colunasCoord = [];
foreach ($pdo->query("SHOW COLUMNS FROM coordenadores") as $c) $colunasCoord[] = $c['Field'];

$temNumeroCandidatoCoord = in_array('numero_candidato',  $colunasCoord, true);
$temExpectativaCoord     = in_array('expectativa_votos', $colunasCoord, true);
$temAnoCampanhaCoord     = in_array('ano_campanha',      $colunasCoord, true);

$temTabMunicipio  = false;
$temTabCoordSecao = false;
try {
    $temTabMunicipio  = (bool)$pdo->query("SHOW TABLES LIKE 'coordenador_municipio'")->fetchColumn();
    $temTabCoordSecao = (bool)$pdo->query("SHOW TABLES LIKE 'coordenador_secao'")->fetchColumn();
} catch (Exception $e) { /* mantém false */ }

// ============================================================
// ENDPOINTS AJAX
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $acao = $_GET['ajax'];

        // ---------- LISTAR SEÇÕES DE UM COORDENADOR ----------
        if ($acao === 'secoes_listar') {
            if (!$temTabCoordSecao) {
                echo json_encode(['ok' => true, 'secoes' => []]);
                exit;
            }
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido.');

            $stmt = $pdo->prepare("
                SELECT cs.numeroZona, cs.numeroSecao,
                       se.nomeMunicipio, se.bairro, se.nomeLocal
                  FROM coordenador_secao cs
                  LEFT JOIN secoes_enderecos se
                         ON se.numeroZona = cs.numeroZona
                        AND se.numeroSecao = cs.numeroSecao
                 WHERE cs.coordenador_id = :id
                 ORDER BY se.nomeMunicipio, cs.numeroZona, cs.numeroSecao
            ");
            $stmt->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'secoes' => $stmt->fetchAll()]);
            exit;
        }

        // ---------- LISTAR SEÇÕES DE UMA ZONA (para o select) ----------
        if ($acao === 'secoes_por_zona') {
            $zona = (int)($_GET['zona'] ?? 0);
            $mun  = trim($_GET['municipio'] ?? '');
            if ($zona <= 0) throw new Exception('Zona inválida.');

            $sql = "SELECT numeroSecao, nomeMunicipio, bairro, nomeLocal
                      FROM secoes_enderecos
                     WHERE numeroZona = :z";
            $pars = [':z' => $zona];
            if ($mun !== '') { $sql .= " AND nomeMunicipio = :m"; $pars[':m'] = $mun; }
            $sql .= " ORDER BY numeroSecao";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($pars);
            echo json_encode(['ok' => true, 'secoes' => $stmt->fetchAll()]);
            exit;
        }

        // ---------- LISTAR ZONAS DE UM MUNICÍPIO (para o select) ----------
        if ($acao === 'zonas_por_municipio') {
            $mun = trim($_GET['municipio'] ?? '');
            if ($mun === '') {
                $rows = $pdo->query(
                    "SELECT DISTINCT numeroZona FROM secoes_enderecos ORDER BY numeroZona"
                )->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT DISTINCT numeroZona FROM secoes_enderecos
                      WHERE nomeMunicipio = :m ORDER BY numeroZona"
                );
                $stmt->execute([':m' => $mun]);
                $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            echo json_encode(['ok' => true, 'zonas' => $rows]);
            exit;
        }

        // ---------- ADICIONAR SEÇÃO ----------
        if ($acao === 'secao_add') {
            if (!$__ehAdmin) throw new Exception('Sem permissão.');
            if (!$temTabCoordSecao) throw new Exception('Tabela coordenador_secao não existe.');

            $id    = (int)($_POST['id']           ?? 0);
            $zona  = (int)($_POST['numeroZona']   ?? 0);
            $secao = (int)($_POST['numeroSecao']  ?? 0);
            if ($id <= 0 || $zona <= 0 || $secao <= 0) {
                throw new Exception('Dados incompletos.');
            }

            // Confere se a seção existe em secoes_enderecos
            $stmt = $pdo->prepare(
                "SELECT 1 FROM secoes_enderecos
                  WHERE numeroZona = :z AND numeroSecao = :s LIMIT 1"
            );
            $stmt->execute([':z' => $zona, ':s' => $secao]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('Seção não encontrada em secoes_enderecos.');
            }

            $pdo->prepare(
                "INSERT IGNORE INTO coordenador_secao (coordenador_id, numeroZona, numeroSecao)
                 VALUES (:id, :z, :s)"
            )->execute([':id' => $id, ':z' => $zona, ':s' => $secao]);

            echo json_encode(['ok' => true, 'msg' => 'Seção adicionada.']);
            exit;
        }

        // ---------- REMOVER SEÇÃO ----------
        if ($acao === 'secao_del') {
            if (!$__ehAdmin) throw new Exception('Sem permissão.');
            if (!$temTabCoordSecao) throw new Exception('Tabela coordenador_secao não existe.');

            $id    = (int)($_POST['id']          ?? 0);
            $zona  = (int)($_POST['numeroZona']  ?? 0);
            $secao = (int)($_POST['numeroSecao'] ?? 0);
            if ($id <= 0 || $zona <= 0 || $secao <= 0) {
                throw new Exception('Dados incompletos.');
            }

            $pdo->prepare(
                "DELETE FROM coordenador_secao
                  WHERE coordenador_id = :id AND numeroZona = :z AND numeroSecao = :s"
            )->execute([':id' => $id, ':z' => $zona, ':s' => $secao]);

            echo json_encode(['ok' => true, 'msg' => 'Seção removida.']);
            exit;
        }

        // ---------- SALVAR COORDENADOR ----------
        if ($acao === 'salvar') {
            if (!$__ehAdmin) throw new Exception('Sem permissão para salvar.');

            $id               = (int)($_POST['id'] ?? 0);
            $numeroCandidato  = (int)($_POST['numero_candidato'] ?? 0);
            $anoCampanha      = (int)($_POST['ano_campanha'] ?? 0);
            $expectativaVotos = (int)($_POST['expectativa_votos'] ?? 0);
            if ($expectativaVotos < 0) $expectativaVotos = 0;

            $nome        = trim($_POST['nome'] ?? '');
            $cpf         = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            $telefone    = trim($_POST['telefone'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $observacoes = trim($_POST['observacoes'] ?? '');
            $ativo       = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;

            $zonas = $_POST['zonas'] ?? [];
            if (!is_array($zonas)) $zonas = [$zonas];
            $zonas = array_values(array_unique(array_map('intval', $zonas)));
            $zonas = array_filter($zonas, fn($z) => $z > 0);

            $municipios = $_POST['municipios'] ?? [];
            if (!is_array($municipios)) $municipios = [$municipios];
            $municipios = array_values(array_unique(array_filter(
                array_map('trim', $municipios), fn($m) => $m !== ''
            )));

            if ($nome === '') throw new Exception('Informe o nome.');
            if ($temAnoCampanhaCoord && $anoCampanha <= 0) {
                throw new Exception('Selecione o ano da campanha.');
            }
            if ($temNumeroCandidatoCoord && $numeroCandidato <= 0) {
                throw new Exception('Selecione o candidato (número da urna).');
            }
            if ($temTabMunicipio && !$municipios && !$zonas) {
                throw new Exception('Selecione pelo menos um município ou uma zona.');
            }

            $pdo->beginTransaction();

            if ($id > 0) {
                $sets = [];
                $pars = [':n' => $nome, ':id' => $id];

                if ($temAnoCampanhaCoord) {
                    $sets[] = "ano_campanha = :ano";
                    $pars[':ano'] = $anoCampanha > 0 ? $anoCampanha : null;
                }
                if ($temNumeroCandidatoCoord) {
                    $sets[] = "numero_candidato = :num";
                    $pars[':num'] = $numeroCandidato > 0 ? $numeroCandidato : null;
                }
                if ($temExpectativaCoord) {
                    $sets[] = "expectativa_votos = :exp";
                    $pars[':exp'] = $expectativaVotos;
                }

                $sets[] = "nome = :n";
                $sets[] = "cpf = :c";         $pars[':c'] = $cpf         !== '' ? $cpf         : null;
                $sets[] = "telefone = :t";    $pars[':t'] = $telefone    !== '' ? $telefone    : null;
                $sets[] = "email = :e";       $pars[':e'] = $email       !== '' ? $email       : null;
                $sets[] = "observacoes = :o"; $pars[':o'] = $observacoes !== '' ? $observacoes : null;
                $sets[] = "ativo = :a";       $pars[':a'] = $ativo;

                $pdo->prepare("UPDATE coordenadores SET " . implode(', ', $sets) . " WHERE id = :id")
                    ->execute($pars);
            } else {
                $cols = ['nome','cpf','telefone','email','observacoes','ativo'];
                $vals = [':n',':c',':t',':e',':o',':a'];
                $pars = [
                    ':n' => $nome,
                    ':c' => $cpf         !== '' ? $cpf         : null,
                    ':t' => $telefone    !== '' ? $telefone    : null,
                    ':e' => $email       !== '' ? $email       : null,
                    ':o' => $observacoes !== '' ? $observacoes : null,
                    ':a' => $ativo,
                ];
                $pos = 0;
                if ($temAnoCampanhaCoord) {
                    array_splice($cols, $pos, 0, 'ano_campanha');
                    array_splice($vals, $pos, 0, ':ano');
                    $pars[':ano'] = $anoCampanha > 0 ? $anoCampanha : null;
                    $pos++;
                }
                if ($temNumeroCandidatoCoord) {
                    array_splice($cols, $pos, 0, 'numero_candidato');
                    array_splice($vals, $pos, 0, ':num');
                    $pars[':num'] = $numeroCandidato > 0 ? $numeroCandidato : null;
                    $pos++;
                }
                if ($temExpectativaCoord) {
                    array_splice($cols, $pos, 0, 'expectativa_votos');
                    array_splice($vals, $pos, 0, ':exp');
                    $pars[':exp'] = $expectativaVotos;
                }
                $pdo->prepare(
                    "INSERT INTO coordenadores (" . implode(',', $cols) . ")
                     VALUES (" . implode(',', $vals) . ")"
                )->execute($pars);
                $id = (int)$pdo->lastInsertId();
            }

            // Zonas
            $pdo->prepare("DELETE FROM coordenador_zona WHERE coordenador_id = :id")->execute([':id' => $id]);
            if ($zonas) {
                $stmt = $pdo->prepare("INSERT INTO coordenador_zona (coordenador_id, numeroZona) VALUES (:id, :z)");
                foreach ($zonas as $z) $stmt->execute([':id' => $id, ':z' => $z]);
            }

            // Municípios
            if ($temTabMunicipio) {
                $pdo->prepare("DELETE FROM coordenador_municipio WHERE coordenador_id = :id")->execute([':id' => $id]);
                if ($municipios) {
                    $stmtM = $pdo->prepare("INSERT INTO coordenador_municipio (coordenador_id, nomeMunicipio) VALUES (:id, :m)");
                    foreach ($municipios as $m) $stmtM->execute([':id' => $id, ':m' => $m]);
                }
            }

            // Seções — regra: município principal; se tiver zonas, aplica AND.
            if ($temTabCoordSecao) {
                $pdo->prepare("DELETE FROM coordenador_secao WHERE coordenador_id = :id")->execute([':id' => $id]);

                $conds = [];
                $pars  = [':id' => $id];

                if ($municipios) {
                    $ph = [];
                    foreach ($municipios as $i => $m) { $k = ":m$i"; $ph[] = $k; $pars[$k] = $m; }
                    $conds[] = "se.nomeMunicipio IN (" . implode(',', $ph) . ")";
                }
                if ($zonas) {
                    $ph = [];
                    foreach ($zonas as $i => $z) { $k = ":z$i"; $ph[] = $k; $pars[$k] = $z; }
                    $conds[] = "se.numeroZona IN (" . implode(',', $ph) . ")";
                }

                if ($conds) {
                    $sqlIns = "INSERT IGNORE INTO coordenador_secao (coordenador_id, numeroZona, numeroSecao)
                               SELECT DISTINCT :id, se.numeroZona, se.numeroSecao
                                 FROM secoes_enderecos se
                                WHERE " . implode(' AND ', $conds);
                    $pdo->prepare($sqlIns)->execute($pars);
                }
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'id' => $id, 'msg' => 'Salvo com sucesso.']);
            exit;
        }

        if ($acao === 'excluir') {
            if (!$isAdmin) throw new Exception('Sem permissão para excluir.');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido.');

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM liderancas WHERE coordenador_id = :id");
            $stmt->execute([':id' => $id]);
            $qtd = (int)$stmt->fetchColumn();
            if ($qtd > 0) throw new Exception("Não é possível excluir: há {$qtd} liderança(s) vinculada(s).");

            $pdo->prepare("DELETE FROM coordenadores WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'msg' => 'Excluído.']);
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
$filtroAno       = (int)($_GET['ano']       ?? 0);
$filtroCandidato = (int)($_GET['candidato'] ?? 0);

$mostrarLista = ($filtroAno > 0 && $filtroCandidato > 0);

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

$candsPorAno = [];
$rows = $pdo->query(
    "SELECT ano, numero, MIN(nomeCandidatoResp) AS nome, MIN(nomeCargoPerg) AS cargo
       FROM votos
      WHERE numero > 0 AND nomeCandidatoResp <> ''
      GROUP BY ano, numero
      ORDER BY ano DESC, nome"
)->fetchAll();
foreach ($rows as $r) {
    $candsPorAno[(int)$r['ano']][] = [
        'numero' => (int)$r['numero'],
        'nome'   => $r['nome'],
        'cargo'  => $r['cargo'],
    ];
}

$listaMunicipios = [];
if ($temTabMunicipio) {
    try {
        $listaMunicipios = $pdo->query(
            "SELECT DISTINCT nomeMunicipio FROM municipio_regiao
              WHERE nomeMunicipio IS NOT NULL AND nomeMunicipio <> ''
              ORDER BY nomeMunicipio"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $listaMunicipios = []; }
    if (!$listaMunicipios) {
        $listaMunicipios = $pdo->query(
            "SELECT DISTINCT nomeMunicipio FROM secoes_enderecos
              WHERE nomeMunicipio IS NOT NULL AND nomeMunicipio <> ''
              ORDER BY nomeMunicipio"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
}

$zonas = $pdo->query(
    "SELECT DISTINCT numeroZona FROM secoes_enderecos ORDER BY numeroZona"
)->fetchAll(PDO::FETCH_COLUMN);
if (!$zonas) {
    $zonas = $pdo->query(
        "SELECT DISTINCT numeroZona FROM votos ORDER BY numeroZona"
    )->fetchAll(PDO::FETCH_COLUMN);
}

$coordenadores = [];
if ($mostrarLista && $temNumeroCandidatoCoord && $temAnoCampanhaCoord) {
    $where  = "WHERE c.numero_candidato = :num AND c.ano_campanha = :ano";
    $params = [':num' => $filtroCandidato, ':ano' => $filtroAno];

    $colCandSelect = "c.numero_candidato AS numero_candidato, c.ano_campanha AS ano_campanha,";

    $esperadosExpr = $temExpectativaCoord ? "c.expectativa_votos" : "0";

    $secCountExpr = $temTabCoordSecao
        ? "(SELECT COUNT(*) FROM coordenador_secao cs WHERE cs.coordenador_id = c.id)"
        : "0";

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

    if ($temTabCoordSecao) {
        $urnasExpr = "(
            SELECT COALESCE(SUM(v.`$COL_VOTOS`), 0)
              FROM votos v
             WHERE 1=1 $filtroCandVotos
               AND (v.numeroZona, v.numeroSecao) IN (
                   SELECT cs.numeroZona, cs.numeroSecao
                     FROM coordenador_secao cs
                    WHERE cs.coordenador_id = c.id
               )
        )";
    } else {
        $urnasExpr = "0";
    }

    $sql = "
        SELECT c.*, $colCandSelect
            (SELECT MIN(v.nomeCandidatoResp) FROM votos v
              WHERE v.numero = c.numero_candidato AND v.ano = c.ano_campanha) AS candidato,
            (SELECT MIN(v.nomeCargoPerg) FROM votos v
              WHERE v.numero = c.numero_candidato AND v.ano = c.ano_campanha) AS candidato_cargo,
            (SELECT COUNT(*) FROM liderancas l WHERE l.coordenador_id = c.id) AS qtd_liderancas,
            (SELECT COUNT(*) FROM liderancas l WHERE l.coordenador_id = c.id AND l.ativo = 1) AS qtd_ativas,
            $esperadosExpr AS votos_esperados,
            $urnasExpr     AS votos_urnas,
            $secCountExpr  AS qtd_secoes
        FROM coordenadores c
        $where
        ORDER BY votos_esperados DESC, c.nome
    ";

    if (isset($_GET['debug']) && $_GET['debug'] == '1') {
        $interpolar = function ($sql, $pars) {
            $keys = array_keys($pars);
            usort($keys, fn($a, $b) => strlen($b) - strlen($a));
            foreach ($keys as $k) {
                $v = $pars[$k];
                if ($v === null)          $rep = 'NULL';
                elseif (is_int($v))       $rep = (string)$v;
                elseif (is_numeric($v))   $rep = (string)$v;
                else                      $rep = "'" . str_replace("'", "''", (string)$v) . "'";
                $sql = str_replace($k, $rep, $sql);
            }
            return $sql;
        };

        $stmtIds = $pdo->prepare(
            "SELECT id, nome, numero_candidato, ano_campanha
               FROM coordenadores
              WHERE numero_candidato = :num AND ano_campanha = :ano"
        );
        $stmtIds->execute($params);
        $listaDebug = $stmtIds->fetchAll();

        echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Debug SQL</title>";
        echo "<style>body{font-family:monospace;background:#1e1e1e;color:#ddd;padding:20px;}h1,h2,h3{color:#4ec9b0;}.bloco{background:#252526;border-left:4px solid #569cd6;padding:12px;margin:12px 0;border-radius:4px;white-space:pre-wrap;word-break:break-all;font-size:12px;line-height:1.5;}textarea{width:100%;background:#1e1e1e;color:#ddd;border:1px solid #333;padding:8px;font-family:monospace;font-size:12px;}button{background:#0e639c;color:#fff;border:none;padding:6px 12px;border-radius:3px;cursor:pointer;margin:4px 0;}</style></head><body>";

        echo "<h1>DEBUG SQL — Coordenadores</h1>";
        echo "<p>Ano: <b>" . (int)$filtroAno . "</b> · Candidato Nº: <b>" . (int)$filtroCandidato . "</b></p>";
        echo "<p>Coordenadores: <b>" . count($listaDebug) . "</b></p>";

        echo "<h2>SQL principal</h2>";
        echo "<div class='bloco'>" . htmlspecialchars($interpolar($sql, $params)) . "</div>";

        foreach ($listaDebug as $d) {
            $cid = (int)$d['id'];
            echo "<h2>Coordenador ID {$cid} — " . htmlspecialchars($d['nome']) . "</h2>";

            $rowsMun = $pdo->query("
                SELECT se.nomeMunicipio,
                       GROUP_CONCAT(DISTINCT cs.numeroZona ORDER BY cs.numeroZona SEPARATOR ',') AS zonas,
                       COUNT(*) AS qtd_secoes
                  FROM coordenador_secao cs
                  LEFT JOIN secoes_enderecos se
                         ON se.numeroZona = cs.numeroZona
                        AND se.numeroSecao = cs.numeroSecao
                 WHERE cs.coordenador_id = $cid
                 GROUP BY se.nomeMunicipio
                 ORDER BY se.nomeMunicipio
            ")->fetchAll();

            echo "<div class='bloco'><b>Seções por município:</b>\n";
            foreach ($rowsMun as $m) {
                echo "  " . htmlspecialchars((string)$m['nomeMunicipio'])
                   . " · zonas: " . htmlspecialchars((string)$m['zonas'])
                   . " · seções: " . (int)$m['qtd_secoes'] . "\n";
            }
            echo "</div>";
        }

        echo "</body></html>";
        exit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $coordenadores = $stmt->fetchAll();
}

// Complementos: agrupamento por município
if ($coordenadores) {
    $ids = array_filter(array_map('intval', array_column($coordenadores, 'id')), fn($x) => $x > 0);
    if ($ids) {
        $in = implode(',', $ids);

        $porMunPorCoord = [];
        if ($temTabCoordSecao) {
            $rows = $pdo->query("
                SELECT cs.coordenador_id,
                       se.nomeMunicipio,
                       GROUP_CONCAT(DISTINCT cs.numeroZona ORDER BY cs.numeroZona SEPARATOR ',') AS zonas,
                       COUNT(*) AS qtd_secoes
                  FROM coordenador_secao cs
                  LEFT JOIN secoes_enderecos se
                         ON se.numeroZona = cs.numeroZona
                        AND se.numeroSecao = cs.numeroSecao
                 WHERE cs.coordenador_id IN ($in)
                 GROUP BY cs.coordenador_id, se.nomeMunicipio
                 ORDER BY se.nomeMunicipio
            ")->fetchAll();
            foreach ($rows as $r) {
                $porMunPorCoord[(int)$r['coordenador_id']][] = [
                    'municipio'   => $r['nomeMunicipio'] ?? '—',
                    'zonas'       => $r['zonas'] !== null && $r['zonas'] !== ''
                                       ? array_map('intval', explode(',', $r['zonas'])) : [],
                    'qtd_secoes'  => (int)$r['qtd_secoes'],
                ];
            }
        }

        foreach ($coordenadores as &$c) {
            $cid = (int)$c['id'];
            $c['por_municipio'] = $porMunPorCoord[$cid] ?? [];
            $c['municipios'] = array_map(fn($x) => $x['municipio'], $c['por_municipio']);

            $zs = [];
            foreach ($c['por_municipio'] as $pm) {
                foreach ($pm['zonas'] as $z) $zs[$z] = true;
            }
            $zs = array_keys($zs);
            sort($zs, SORT_NUMERIC);
            $c['zonas']     = $zs;
            $c['zonas_str'] = implode(',', $zs);
        }
        unset($c);
    }
}

foreach ($coordenadores as &$c) {
    $esp = (int)$c['votos_esperados'];
    $urn = (int)$c['votos_urnas'];
    $c['diferenca'] = $urn - $esp;
    $c['pct'] = $esp > 0 ? round(($urn / $esp) * 100, 1) : null;
}
unset($c);

$totalCoords = count($coordenadores);
$totalAtivos = 0;
$totalLiderancas = 0;
$totalLiderancasAtiv = 0;
$totalVotosEsperados = 0;
$totalVotosUrnas = 0;
$totalSecoes = 0;
foreach ($coordenadores as $c) {
    if ((int)$c['ativo'] === 1) $totalAtivos++;
    $totalLiderancas     += (int)$c['qtd_liderancas'];
    $totalLiderancasAtiv += (int)$c['qtd_ativas'];
    $totalVotosEsperados += (int)$c['votos_esperados'];
    $totalVotosUrnas     += (int)$c['votos_urnas'];
    $totalSecoes         += (int)$c['qtd_secoes'];
}
$totalDiferenca = $totalVotosUrnas - $totalVotosEsperados;
$totalPct = $totalVotosEsperados > 0
    ? round(($totalVotosUrnas / $totalVotosEsperados) * 100, 1) : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Coordenadores</title>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background:#f7f7f7; color:#333; }
    h1 { color:#333; }
    .kpis { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px; }
    .kpi { background:#fff; border-radius:8px; padding:15px 25px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); min-width:180px;
        border-left:5px solid #0069d9; }
    .kpi .rotulo { font-size:12px; text-transform:uppercase; color:#666; letter-spacing:1px; }
    .kpi .valor  { font-size:28px; font-weight:bold; color:#0069d9; margin-top:4px; }
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
    .btn-sec   { background:#fd7e14; color:#fff; font-size:12px; padding:5px 10px; }
    .btn-sec:hover { background:#d96610; }
    .btn-salvar { background:#28a745; color:#fff; padding:10px 18px; font-size:14px; }
    .btn-salvar:hover { background:#1e7e34; }
    .btn-cancelar { background:#6c757d; color:#fff; padding:10px 18px; font-size:14px; }
    .btn-cancelar:hover { background:#5a6268; }
    .btn-debug { background:#6f42c1; color:#fff; text-decoration:none;
                 padding:8px 14px; border-radius:4px; font-size:13px; display:inline-block; }
    .btn-debug:hover { background:#5a32a3; }
    .btn-mini-del { background:#dc3545; color:#fff; font-size:11px; padding:3px 7px; border:none;
                    border-radius:3px; cursor:pointer; }

    .form-filtros {
        background:#fff; padding:14px 20px; border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px;
        display:flex; flex-wrap:wrap; align-items:center; gap:12px;
    }
    .form-filtros label { font-weight:bold; font-size:14px; }
    .form-filtros button { padding:8px 16px; border-radius:4px; border:none;
        cursor:pointer; font-size:14px; font-weight:bold; }
    .btn-consultar { background:#0069d9; color:#fff; }
    .btn-consultar:hover { background:#0053b3; }
    .btn-limpar-form { background:#6c757d; color:#fff; text-decoration:none;
        padding:8px 16px; border-radius:4px; font-size:14px; font-weight:bold;
        display:inline-block; }
    .btn-limpar-form:hover { background:#5a6268; }

    .aviso-selecione {
        background:#fff3cd; color:#856404; padding:20px;
        border-radius:8px; text-align:center;
        border-left:5px solid #ffc107;
        font-size:14px; margin-bottom:20px;
    }

    table { border-collapse: collapse; width:100%; background:#fff; margin-bottom:15px; }
    th, td { border:1px solid #ddd; padding:8px 10px; text-align:left; vertical-align:top; font-size:13px; }
    th { background:#0069d9; color:#fff; }
    tr:nth-child(even) { background:#f9f9f9; }
    tr:hover { background:#eef6ff; }
    tr.linha-total { background:#eef6ff; font-weight:bold; }
    tr.linha-total td { border-top:2px solid #0069d9; }
    .num { text-align:right; }
    .centro { text-align:center; }

    .badge { display:inline-block; padding:2px 8px; border-radius:12px;
             font-size:11px; font-weight:bold; margin:1px 2px; }
    .badge-zona  { background:#e6f0fa; color:#0069d9; }
    .badge-mun   { background:#e8f5e9; color:#1c7a34; }
    .badge-sec   { background:#fff8e1; color:#8a6d00; }
    .badge-ano   { background:#ede7f6; color:#4527a0; }
    .badge-lider { background:#f0e6ff; color:#6f42c1; }
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
    .badge-cand  { background:#e7d9ff; color:#4a1d96; font-weight:bold; }
    .badge-num   { background:#0069d9; color:#fff; font-weight:bold; }
    .vazio { color:#999; font-style:italic; font-size:12px; }

    .mun-bloco { display:flex; flex-direction:column; gap:5px; }
    .mun-item {
        background:#f4faf5; border-left:3px solid #28a745;
        padding:5px 8px; border-radius:4px; font-size:12px;
    }
    .mun-item .mun-nome { font-weight:bold; color:#1c7a34; display:block; margin-bottom:2px; }
    .mun-item .mun-zonas { color:#0069d9; font-weight:bold; }
    .mun-item .mun-sec   { color:#8a6d00; font-weight:bold; }

    .info-col {
        font-size:12px; color:#666; margin-bottom:12px;
        background:#fff; padding:8px 14px; border-radius:6px;
        border-left:4px solid #17a2b8;
        box-shadow: 0 1px 2px rgba(0,0,0,.05);
    }
    .info-col code { background:#f4f4f4; padding:1px 5px; border-radius:3px; }
    .info-col .aviso { color:#856404; background:#fff3cd;
        padding:2px 8px; border-radius:6px; font-weight:bold; margin-left:6px; }

    .modal-overlay { display:none; position: fixed; inset: 0;
        background: rgba(0,0,0,.5); z-index: 1000;
        align-items: center; justify-content: center; padding: 20px; }
    .modal-overlay.aberto { display: flex; }
    .modal { background:#fff; border-radius: 10px; max-width: 720px; width: 100%;
        max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,.3); }
    .modal.modal-secao { max-width: 860px; }
    .modal-header { padding: 16px 22px; border-bottom: 1px solid #eee;
        display: flex; justify-content: space-between; align-items: center; }
    .modal-header h2 { margin: 0; font-size: 18px; color:#0069d9; }
    .modal-header .fechar { background: none; border: none; font-size: 22px;
        cursor: pointer; color: #888; }
    .modal-body { padding: 20px 22px; }
    .modal-footer { padding: 14px 22px; border-top: 1px solid #eee;
        text-align: right; background:#fafafa; }
    .campo { margin-bottom: 14px; }
    .campo label { display: block; font-weight: bold; margin-bottom: 4px; font-size: 13px; }
    .campo input[type=text], .campo input[type=email], .campo input[type=number],
    .campo textarea, .campo select {
        width: 100%; padding: 8px 10px; border: 1px solid #ccc;
        border-radius: 4px; font-size: 14px; font-family: inherit; box-sizing: border-box;
    }
    .campo textarea { resize: vertical; min-height: 60px; }
    .linha-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .linha-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    .toggle { display:flex; align-items:center; gap:8px; }
    .toggle input { width:auto; }
    .dica { font-size:11px; color:#888; margin-top:3px; }
    .destaque-exp { background:#fff8e1; border-left:3px solid #ffc107;
        padding:8px 12px; border-radius:4px; }
    .destaque-sec { background:#fff4e6; border-left:3px solid #fd7e14;
        padding:12px; border-radius:4px; margin-bottom:15px; }

    .select2-container { width: 100% !important; }
    .select2-container--default .select2-selection--single,
    .select2-container--default .select2-selection--multiple {
        min-height: 40px; padding: 4px 6px; border: 1px solid #ccc; border-radius: 4px;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background: #0069d9; color: #fff; border: none; padding: 2px 8px; font-size: 12px;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        color: #fff; margin-right: 4px;
    }
    .select2-search--dropdown .select2-search__field { padding: 6px; font-size: 14px; }

    .link-voltar { display:inline-block; margin-bottom:15px; color:#0069d9; text-decoration:none; }
    .link-voltar:hover { text-decoration:underline; }

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

    .secoes-wrap { max-height:320px; overflow:auto; border:1px solid #ddd; border-radius:4px; }
    .secoes-wrap table { margin:0; font-size:12px; }
    .secoes-wrap th { position:sticky; top:0; z-index:1; }
</style>
</head>
<body>

<a href="index.php" class="link-voltar">&larr; Voltar ao menu</a>

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

<h1>Coordenadores</h1>

<p class="info-col">
    <code>ano_campanha</code>:
    <?php if ($temAnoCampanhaCoord): ?><strong>ok</strong>
    <?php else: ?><span class="aviso">NÃO EXISTE</span><?php endif; ?>
    &nbsp;·&nbsp;
    <code>numero_candidato</code>:
    <?php if ($temNumeroCandidatoCoord): ?><strong>ok</strong>
    <?php else: ?><span class="aviso">NÃO EXISTE</span><?php endif; ?>
    &nbsp;·&nbsp;
    <code>expectativa_votos</code>:
    <?php if ($temExpectativaCoord): ?><strong>ok</strong>
    <?php else: ?><span class="aviso">NÃO EXISTE</span><?php endif; ?>
    &nbsp;·&nbsp;
    <code>coordenador_municipio</code>:
    <?php if ($temTabMunicipio): ?><strong>ok</strong>
    <?php else: ?><span class="aviso">NÃO EXISTE</span><?php endif; ?>
    &nbsp;·&nbsp;
    <code>coordenador_secao</code>:
    <?php if ($temTabCoordSecao): ?><strong>ok</strong>
    <?php else: ?><span class="aviso">NÃO EXISTE</span><?php endif; ?>
</p>

<form method="get" action="cadastrar_coordenadores.php" class="form-filtros">
    <label for="ano">Ano:</label>
    <select name="ano" id="ano" style="min-width:140px;"
            onchange="this.form.candidato.value='0'; this.form.submit();">
        <option value="0">— Selecione —</option>
        <?php foreach ($listaAnos as $a): ?>
            <option value="<?= (int)$a ?>" <?= ((int)$a === $filtroAno) ? 'selected' : '' ?>>
                <?= (int)$a ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="candidato">Candidato:</label>
    <select name="candidato" id="candidato" style="min-width:360px;"
            <?= $filtroAno <= 0 ? 'disabled' : '' ?>>
        <option value="0">— Selecione —</option>
        <?php foreach ($listaCandidatos as $c): ?>
            <option value="<?= (int)$c['numero'] ?>"
                <?= ((int)$c['numero'] === $filtroCandidato) ? 'selected' : '' ?>>
                Nº <?= (int)$c['numero'] ?> — <?= htmlspecialchars($c['nome']) ?>
                <?= $c['cargo'] ? ' (' . htmlspecialchars($c['cargo']) . ')' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="btn-consultar"
            <?= $filtroAno <= 0 ? 'disabled' : '' ?>>🔎 Consultar</button>
    <a href="cadastrar_coordenadores.php" class="btn-limpar-form">✖ Limpar</a>

    <?php if ($mostrarLista): ?>
        <a href="?ano=<?= (int)$filtroAno ?>&candidato=<?= (int)$filtroCandidato ?>&debug=1"
           class="btn-debug" target="_blank">🐞 Debug SQL</a>
    <?php endif; ?>
</form>

<?php if (!$mostrarLista): ?>

    <div class="aviso-selecione">
        ⚠️ Selecione o <strong>ano da campanha</strong> e o <strong>candidato</strong>.
    </div>

<?php else: ?>

    <div class="kpis">
        <div class="kpi">
            <div class="rotulo">Coordenadores</div>
            <div class="valor"><?= number_format($totalCoords, 0, ',', '.') ?></div>
        </div>
        <div class="kpi verde">
            <div class="rotulo">Ativos</div>
            <div class="valor"><?= number_format($totalAtivos, 0, ',', '.') ?></div>
        </div>
        <div class="kpi roxo">
            <div class="rotulo">Lideranças</div>
            <div class="valor"><?= number_format($totalLiderancas, 0, ',', '.') ?></div>
            <div class="mini"><?= number_format($totalLiderancasAtiv, 0, ',', '.') ?> ativas</div>
        </div>
        <div class="kpi laranja">
            <div class="rotulo">Seções</div>
            <div class="valor"><?= number_format($totalSecoes, 0, ',', '.') ?></div>
        </div>
        <div class="kpi teal">
            <div class="rotulo">Votos esperados</div>
            <div class="valor"><?= number_format($totalVotosEsperados, 0, ',', '.') ?></div>
        </div>
        <div class="kpi <?= $totalDiferenca >= 0 ? 'verde' : 'vermelho' ?>">
            <div class="rotulo">Votos × Diferença</div>
            <div class="valor"><?= number_format($totalVotosUrnas, 0, ',', '.') ?></div>
            <div class="mini">
                <?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?>
                <?= $totalPct !== null
                    ? ' (' . number_format($totalPct, 1, ',', '.') . '%)'
                    : '' ?>
            </div>
        </div>
    </div>

    <?php if ($__ehAdmin): ?>
    <div style="margin-bottom:15px;">
        <button type="button" class="btn btn-novo" id="btn-novo">+ Novo Coordenador</button>
    </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th>Ano</th>
                <th>Candidato</th>
                <th>Nome</th>
                <th>Municípios / Zonas / Seções</th>
                <th class="centro" style="width:60px;">Líderes</th>
                <th class="num" style="width:100px;">Esperados</th>
                <th class="num" style="width:100px;">Votos</th>
                <th class="num" style="width:90px;">Diferença</th>
                <th class="centro" style="width:70px;">%</th>
                <th style="width:70px;">Status</th>
                <th style="width:230px;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$coordenadores): ?>
                <tr><td colspan="12" style="text-align:center;color:#999;padding:20px;">
                    Nenhum coordenador cadastrado.
                </td></tr>
            <?php else: ?>
                <?php $i = 1; foreach ($coordenadores as $c): ?>
                    <?php
                    $esp = (int)$c['votos_esperados'];
                    $urn = (int)$c['votos_urnas'];
                    $dif = (int)$c['diferenca'];
                    $pct = $c['pct'];
                    if ($pct === null)   $pctCls = 'badge-zero';
                    elseif ($pct >= 100) $pctCls = 'badge-pct-ok';
                    elseif ($pct >= 70)  $pctCls = 'badge-pct-med';
                    else                 $pctCls = 'badge-pct-ruim';
                    if ($dif > 0)     $difCls = 'badge-pos';
                    elseif ($dif < 0) $difCls = 'badge-neg';
                    else              $difCls = 'badge-zero';
                    ?>
                    <tr data-id="<?= (int)$c['id'] ?>">
                        <td><?= $i++ ?></td>
                        <td><span class="badge badge-ano"><?= (int)$c['ano_campanha'] ?></span></td>
                        <td>
                            <?php if (!empty($c['numero_candidato'])): ?>
                                <span class="badge badge-num">Nº <?= (int)$c['numero_candidato'] ?></span>
                                <?php if (!empty($c['candidato'])): ?>
                                    <span class="badge badge-cand"><?= htmlspecialchars($c['candidato']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="vazio">—</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($c['nome']) ?></strong></td>
                        <td>
                            <?php if (!empty($c['por_municipio'])): ?>
                                <div class="mun-bloco">
                                    <?php foreach ($c['por_municipio'] as $pm): ?>
                                        <div class="mun-item">
                                            <span class="mun-nome">📍 <?= htmlspecialchars($pm['municipio']) ?></span>
                                            <span class="mun-zonas">Zonas:
                                                <?= htmlspecialchars(implode(', ', $pm['zonas'])) ?></span>
                                            &nbsp;·&nbsp;
                                            <span class="mun-sec">Seções:
                                                <?= number_format($pm['qtd_secoes'], 0, ',', '.') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="vazio">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="centro">
                            <?= (int)$c['qtd_ativas'] > 0
                                ? '<span class="badge badge-lider">' . (int)$c['qtd_ativas'] . '</span>'
                                : '<span class="vazio">0</span>' ?>
                        </td>
                        <td class="num"><?= $esp > 0 ? '<span class="badge badge-votos">' . number_format($esp,0,',','.') . '</span>' : '<span class="vazio">—</span>' ?></td>
                        <td class="num"><?= $urn > 0 ? '<span class="badge badge-urnas">' . number_format($urn,0,',','.') . '</span>' : '<span class="vazio">—</span>' ?></td>
                        <td class="num">
                            <?php if ($esp > 0 || $urn > 0): ?>
                                <span class="badge <?= $difCls ?>">
                                    <?= ($dif > 0 ? '+' : '') . number_format($dif, 0, ',', '.') ?>
                                </span>
                            <?php else: ?><span class="vazio">—</span><?php endif; ?>
                        </td>
                        <td class="centro">
                            <?= $pct !== null
                                ? '<span class="badge ' . $pctCls . '">' . number_format($pct,1,',','.') . '%</span>'
                                : '<span class="vazio">—</span>' ?>
                        </td>
                        <td>
                            <?= (int)$c['ativo'] === 1
                                ? '<span class="badge badge-ativo">Ativo</span>'
                                : '<span class="badge badge-inativo">Inativo</span>' ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sec"
                                    data-id="<?= (int)$c['id'] ?>"
                                    data-nome="<?= htmlspecialchars($c['nome']) ?>">
                                📋 Seções
                            </button>
                            <?php if ($__ehAdmin): ?>
                                <button type="button" class="btn btn-editar" data-id="<?= (int)$c['id'] ?>">Editar</button>
                            <?php endif; ?>
                            <?php if ($isAdmin): ?>
                                <button type="button" class="btn btn-excluir"
                                        data-id="<?= (int)$c['id'] ?>"
                                        data-nome="<?= htmlspecialchars($c['nome']) ?>"
                                        data-liderancas="<?= (int)$c['qtd_liderancas'] ?>">Excluir</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="linha-total">
                    <td colspan="5" style="text-align:right;"><strong>Total</strong></td>
                    <td class="centro"><?= number_format($totalLiderancasAtiv, 0, ',', '.') ?></td>
                    <td class="num"><?= number_format($totalVotosEsperados, 0, ',', '.') ?></td>
                    <td class="num"><?= number_format($totalVotosUrnas, 0, ',', '.') ?></td>
                    <td class="num"><?= ($totalDiferenca > 0 ? '+' : '') . number_format($totalDiferenca, 0, ',', '.') ?></td>
                    <td class="centro"><?= $totalPct !== null ? number_format($totalPct, 1, ',', '.') . '%' : '—' ?></td>
                    <td colspan="2"></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

<?php endif; ?>

<!-- =================== MODAL COORDENADOR =================== -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modal-titulo">Novo Coordenador</h2>
            <button type="button" class="fechar" id="btn-fechar">&times;</button>
        </div>
        <form id="form-coord">
            <input type="hidden" name="id" id="f-id" value="0">
            <div class="modal-body">

                <?php if ($temAnoCampanhaCoord): ?>
                <div class="campo">
                    <label for="f-ano">Ano da campanha *</label>
                    <select name="ano_campanha" id="f-ano" required>
                        <option value="">— Selecione —</option>
                        <?php foreach ($listaAnos as $a): ?>
                            <option value="<?= (int)$a ?>"><?= (int)$a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($temNumeroCandidatoCoord): ?>
                <div class="campo">
                    <label for="f-numero-candidato">Candidato (número da urna) *</label>
                    <select name="numero_candidato" id="f-numero-candidato" required>
                        <option value="">— Selecione o ano primeiro —</option>
                    </select>
                </div>
                <?php endif; ?>

                <div class="campo">
                    <label for="f-nome">Nome completo *</label>
                    <input type="text" name="nome" id="f-nome" required maxlength="200">
                </div>

                <div class="linha-2">
                    <div class="campo">
                        <label for="f-cpf">CPF</label>
                        <input type="text" name="cpf" id="f-cpf" maxlength="14">
                    </div>
                    <div class="campo">
                        <label for="f-telefone">Telefone</label>
                        <input type="text" name="telefone" id="f-telefone" maxlength="20">
                    </div>
                </div>

                <div class="campo">
                    <label for="f-email">E-mail</label>
                    <input type="email" name="email" id="f-email" maxlength="120">
                </div>

                <?php if ($temExpectativaCoord): ?>
                <div class="campo destaque-exp">
                    <label for="f-expectativa">Expectativa de votos do coordenador</label>
                    <input type="number" name="expectativa_votos" id="f-expectativa" min="0" step="1" value="0">
                </div>
                <?php endif; ?>

                <?php if ($temTabMunicipio): ?>
                <div class="campo">
                    <label for="f-municipios">Municípios sob responsabilidade</label>
                    <select name="municipios[]" id="f-municipios" multiple>
                        <?php foreach ($listaMunicipios as $m): ?>
                            <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="dica">Seções são filtradas pelos municípios escolhidos.</div>
                </div>
                <?php endif; ?>

                <div class="campo">
                    <label for="f-zonas">Zonas adicionais (opcional)</label>
                    <select name="zonas[]" id="f-zonas" multiple>
                        <?php foreach ($zonas as $z): ?>
                            <option value="<?= (int)$z ?>">Zona <?= (int)$z ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="dica">
                        Se escolher município + zona, só entram seções que estejam no município E na zona.
                    </div>
                </div>

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
                <span id="form-msg" style="float:left;font-size:13px;"></span>
                <button type="button" class="btn btn-cancelar" id="btn-cancelar">Cancelar</button>
                <button type="submit" class="btn btn-salvar" id="btn-salvar">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- =================== MODAL SEÇÕES =================== -->
<div class="modal-overlay" id="modal-secao">
    <div class="modal modal-secao">
        <div class="modal-header">
            <h2 id="secao-titulo">Gerenciar Seções</h2>
            <button type="button" class="fechar" id="btn-fechar-secao">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="s-coord-id">
            <p style="margin-top:0;">
                <b>Coordenador:</b> <span id="s-coord-nome">—</span>
            </p>

            <fieldset class="destaque-sec" style="border:none;">
                <legend style="font-weight:bold;color:#c25b00;padding:0 6px;">➕ Adicionar seção</legend>

                <div class="linha-3">
                    <div class="campo">
                        <label for="s-municipio">Município (opcional)</label>
                        <select id="s-municipio">
                            <option value="">— Todos —</option>
                            <?php foreach ($listaMunicipios as $m): ?>
                                <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label for="s-zona">Zona *</label>
                        <select id="s-zona">
                            <option value="">— Selecione —</option>
                        </select>
                    </div>
                    <div class="campo">
                        <label for="s-secao">Seção *</label>
                        <select id="s-secao">
                            <option value="">— Selecione a zona primeiro —</option>
                        </select>
                    </div>
                </div>

                <button type="button" class="btn btn-salvar" id="btn-add-secao"
                        style="padding:8px 16px;font-size:13px;">+ Adicionar</button>
                <span id="s-msg" style="margin-left:12px;font-size:13px;"></span>
            </fieldset>

            <div style="display:flex;justify-content:space-between;align-items:center;margin:15px 0 8px;gap:10px;">
                <b>Seções atuais: <span id="s-total">0</span></b>
                <input type="text" id="s-filtro" placeholder="Filtrar..."
                       style="width:220px;padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;">
            </div>

            <div class="secoes-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Município</th>
                            <th style="width:80px;">Zona</th>
                            <th style="width:80px;">Seção</th>
                            <th>Local / Bairro</th>
                            <th style="width:70px;" class="centro">Ação</th>
                        </tr>
                    </thead>
                    <tbody id="s-tbody">
                        <tr><td colspan="5" style="text-align:center;color:#999;padding:12px;">
                            Carregando...
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-cancelar" id="btn-fechar-secao-2">Fechar</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>

<script>
    const COORDENADORES = <?= json_encode($coordenadores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CAND_POR_ANO = <?= json_encode($candsPorAno, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const TEM_NUM      = <?= $temNumeroCandidatoCoord ? 'true' : 'false' ?>;
    const TEM_MUN      = <?= $temTabMunicipio ? 'true' : 'false' ?>;
    const TEM_EXP      = <?= $temExpectativaCoord ? 'true' : 'false' ?>;
    const TEM_ANO      = <?= $temAnoCampanhaCoord ? 'true' : 'false' ?>;
    const TEM_SEC      = <?= $temTabCoordSecao ? 'true' : 'false' ?>;
    const FILTRO_NUM   = <?= (int)$filtroCandidato ?>;
    const FILTRO_ANO   = <?= (int)$filtroAno ?>;
    const PODE_EDITAR  = <?= $__ehAdmin ? 'true' : 'false' ?>;
    const PODE_EXCLUIR = <?= $isAdmin   ? 'true' : 'false' ?>;

    (function () {
        /* ==========================================================
           MODAL COORDENADOR
           ========================================================== */
        const $modal   = jQuery('#modal');
        const $form    = jQuery('#form-coord');
        const $titulo  = jQuery('#modal-titulo');
        const $msg     = jQuery('#form-msg');
        const $zonas   = jQuery('#f-zonas');
        const $mun     = jQuery('#f-municipios');
        const $numCand = jQuery('#f-numero-candidato');
        const $ano     = jQuery('#f-ano');
        const $exp     = jQuery('#f-expectativa');

        if ($zonas.length) $zonas.select2({ language:'pt-BR', placeholder:'Selecione zonas...', allowClear:true, closeOnSelect:false, width:'100%' });
        if ($mun.length)   $mun.select2({ language:'pt-BR', placeholder:'Selecione os municípios...', allowClear:true, closeOnSelect:false, width:'100%' });
        if ($numCand.length) $numCand.select2({ language:'pt-BR', placeholder:'— Selecione —', allowClear:true, width:'100%' });
        if ($ano.length)   $ano.select2({ language:'pt-BR', placeholder:'— Selecione —', allowClear:true, width:'100%' });

        function popularCandidatos(ano, valorSel) {
            if (!$numCand.length) return;
            $numCand.empty().append(jQuery('<option>').val('').text('— Selecione —'));
            var lista = (ano && CAND_POR_ANO[ano]) ? CAND_POR_ANO[ano] : [];
            lista.forEach(function (c) {
                var txt = 'Nº ' + c.numero + ' — ' + c.nome + (c.cargo ? ' (' + c.cargo + ')' : '');
                var $o = jQuery('<option>').val(c.numero).text(txt);
                if (valorSel && String(c.numero) === String(valorSel)) $o.prop('selected', true);
                $numCand.append($o);
            });
            $numCand.trigger('change');
        }

        if ($ano.length) {
            $ano.on('change', function () { popularCandidatos(this.value, null); });
        }

        function limparForm() {
            $form[0].reset();
            jQuery('#f-id').val('0');
            if ($zonas.length) $zonas.val(null).trigger('change');
            if ($mun.length)   $mun.val(null).trigger('change');
            if ($ano.length)   $ano.val(null).trigger('change');
            if ($numCand.length) {
                $numCand.empty().append(jQuery('<option>').val('').text('— Selecione o ano primeiro —'));
                $numCand.trigger('change');
            }
            if ($exp.length) $exp.val('0');
            $msg.text('').css('color', '');
        }

        function abrirModal(id) {
            limparForm();
            if (id > 0) {
                const c = COORDENADORES.find(x => parseInt(x.id) === id);
                if (!c) return;
                $titulo.text('Editar Coordenador');
                jQuery('#f-id').val(c.id);
                jQuery('#f-nome').val(c.nome);
                jQuery('#f-cpf').val(c.cpf || '');
                jQuery('#f-telefone').val(c.telefone || '');
                jQuery('#f-email').val(c.email || '');
                jQuery('#f-observacoes').val(c.observacoes || '');
                jQuery('#f-ativo').prop('checked', parseInt(c.ativo) === 1);
                if (TEM_EXP && $exp.length) $exp.val(c.expectativa_votos != null ? c.expectativa_votos : 0);
                if (TEM_ANO && $ano.length && c.ano_campanha) {
                    $ano.val(String(c.ano_campanha)).trigger('change');
                    popularCandidatos(String(c.ano_campanha), c.numero_candidato);
                } else if (TEM_NUM && $numCand.length && c.numero_candidato) {
                    popularCandidatos(null, c.numero_candidato);
                }
                if (c.zonas && c.zonas.length && $zonas.length) $zonas.val(c.zonas.map(String)).trigger('change');
                if (TEM_MUN && $mun.length && c.municipios && c.municipios.length) $mun.val(c.municipios).trigger('change');
            } else {
                $titulo.text('Novo Coordenador');
                if (TEM_ANO && $ano.length && FILTRO_ANO > 0) {
                    $ano.val(String(FILTRO_ANO)).trigger('change');
                    if (TEM_NUM && FILTRO_NUM > 0) popularCandidatos(String(FILTRO_ANO), FILTRO_NUM);
                }
            }
            $modal.addClass('aberto');
            setTimeout(() => jQuery('#f-nome').focus(), 100);
        }

        jQuery('#btn-novo').on('click', () => abrirModal(0));
        jQuery(document).on('click', '.btn-editar', function () {
            if (!PODE_EDITAR) return;
            abrirModal(parseInt(jQuery(this).data('id')));
        });
        jQuery('#btn-fechar, #btn-cancelar').on('click', () => $modal.removeClass('aberto'));
        $modal.on('click', function (e) { if (e.target === this) $modal.removeClass('aberto'); });

        $form.on('submit', function (e) {
            e.preventDefault();
            if (!PODE_EDITAR) { $msg.text('✘ Sem permissão.').css('color', '#dc3545'); return; }
            $msg.text('Salvando...').css('color', '#6c757d');
            const fd = new FormData(this);
            fd.delete('zonas[]');
            $zonas.find('option:selected').each(function () { fd.append('zonas[]', jQuery(this).val()); });
            if (TEM_MUN && $mun.length) {
                fd.delete('municipios[]');
                $mun.find('option:selected').each(function () { fd.append('municipios[]', jQuery(this).val()); });
            }
            fetch('cadastrar_coordenadores.php?ajax=salvar', { method: 'POST', body: fd })
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

        jQuery(document).on('click', '.btn-excluir', function () {
            if (!PODE_EXCLUIR) return;
            const id   = parseInt(jQuery(this).data('id'));
            const nome = jQuery(this).data('nome');
            const qtd  = parseInt(jQuery(this).data('liderancas')) || 0;
            if (qtd > 0) { alert('Não é possível excluir "' + nome + '". Há ' + qtd + ' liderança(s).'); return; }
            if (!confirm('Excluir "' + nome + '"?')) return;
            const fd = new FormData();
            fd.append('id', id);
            fetch('cadastrar_coordenadores.php?ajax=excluir', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => j.ok ? location.reload() : alert('Erro: ' + j.msg))
                .catch(() => alert('Erro de rede.'));
        });

        /* ==========================================================
           MODAL SEÇÕES
           ========================================================== */
        const $modalSec   = jQuery('#modal-secao');
        const $sMun       = jQuery('#s-municipio');
        const $sZona      = jQuery('#s-zona');
        const $sSec       = jQuery('#s-secao');
        const $sMsg       = jQuery('#s-msg');
        const $sTbody     = jQuery('#s-tbody');
        const $sTotal     = jQuery('#s-total');
        const $sFiltro    = jQuery('#s-filtro');
        let sSecoes       = [];

        if ($sMun.length) $sMun.select2({ language:'pt-BR', placeholder:'— Todos —', allowClear:true, width:'100%', dropdownParent: $modalSec });
        if ($sZona.length) $sZona.select2({ language:'pt-BR', placeholder:'— Selecione —', allowClear:true, width:'100%', dropdownParent: $modalSec });
        if ($sSec.length)  $sSec.select2({ language:'pt-BR', placeholder:'— Selecione —', allowClear:true, width:'100%', dropdownParent: $modalSec });

        function limparAdd() {
            $sMun.val('').trigger('change');
            $sZona.empty().append(jQuery('<option>').val('').text('— Selecione —')).trigger('change');
            $sSec.empty().append(jQuery('<option>').val('').text('— Selecione a zona primeiro —')).trigger('change');
            $sMsg.text('').css('color', '');
        }

        function carregarZonas(municipio, valorSel) {
            const url = 'cadastrar_coordenadores.php?ajax=zonas_por_municipio&municipio=' + encodeURIComponent(municipio || '');
            fetch(url).then(r => r.json()).then(j => {
                $sZona.empty().append(jQuery('<option>').val('').text('— Selecione —'));
                (j.zonas || []).forEach(function (z) {
                    var $o = jQuery('<option>').val(z).text('Zona ' + z);
                    if (valorSel && String(z) === String(valorSel)) $o.prop('selected', true);
                    $sZona.append($o);
                });
                $sZona.trigger('change');
            }).catch(() => {});
        }

        function carregarSecoes(zona, municipio, valorSel) {
            if (!zona) {
                $sSec.empty().append(jQuery('<option>').val('').text('— Selecione a zona primeiro —')).trigger('change');
                return;
            }
            const url = 'cadastrar_coordenadores.php?ajax=secoes_por_zona&zona=' + parseInt(zona)
                      + '&municipio=' + encodeURIComponent(municipio || '');
            fetch(url).then(r => r.json()).then(j => {
                $sSec.empty().append(jQuery('<option>').val('').text('— Selecione —'));
                (j.secoes || []).forEach(function (s) {
                    var extra = [];
                    if (s.nomeMunicipio) extra.push(s.nomeMunicipio);
                    if (s.bairro) extra.push(s.bairro);
                    if (s.nomeLocal) extra.push(s.nomeLocal);
                    var txt = 'Seção ' + s.numeroSecao + (extra.length ? ' — ' + extra.join(' · ') : '');
                    var $o = jQuery('<option>').val(s.numeroSecao).text(txt);
                    if (valorSel && String(s.numeroSecao) === String(valorSel)) $o.prop('selected', true);
                    $sSec.append($o);
                });
                $sSec.trigger('change');
            }).catch(() => {});
        }

        $sMun.on('change', function () {
            carregarZonas(this.value, null);
            $sSec.empty().append(jQuery('<option>').val('').text('— Selecione a zona primeiro —')).trigger('change');
        });

        $sZona.on('change', function () {
            carregarSecoes(this.value, $sMun.val() || '', null);
        });

        function renderSecoes() {
            const filtro = ($sFiltro.val() || '').toString().toLowerCase().trim();
            let list = sSecoes.slice();
            if (filtro) {
                list = list.filter(s => {
                    return (String(s.numeroZona) + ' ' +
                            String(s.numeroSecao) + ' ' +
                            (s.nomeMunicipio || '') + ' ' +
                            (s.bairro || '') + ' ' +
                            (s.nomeLocal || '')
                           ).toLowerCase().includes(filtro);
                });
            }
            $sTotal.text(sSecoes.length);
            if (!list.length) {
                $sTbody.html('<tr><td colspan="5" style="text-align:center;color:#999;padding:12px;">Nenhuma seção.</td></tr>');
                return;
            }
            let html = '';
            list.forEach(function (s) {
                const local = [s.nomeLocal, s.bairro].filter(Boolean).join(' / ') || '—';
                html += '<tr>'
                     +    '<td>' + (s.nomeMunicipio ? escapeHtml(s.nomeMunicipio) : '<span class="vazio">—</span>') + '</td>'
                     +    '<td>' + parseInt(s.numeroZona) + '</td>'
                     +    '<td>' + parseInt(s.numeroSecao) + '</td>'
                     +    '<td>' + escapeHtml(local) + '</td>'
                     +    '<td class="centro"><button class="btn-mini-del" data-zona="' + parseInt(s.numeroZona) + '" data-secao="' + parseInt(s.numeroSecao) + '">remover</button></td>'
                     + '</tr>';
            });
            $sTbody.html(html);
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        function carregarSecoesAtuais() {
            const id = parseInt(jQuery('#s-coord-id').val());
            if (!id) return;
            $sTbody.html('<tr><td colspan="5" style="text-align:center;color:#999;padding:12px;">Carregando...</td></tr>');
            const fd = new FormData();
            fd.append('id', id);
            fetch('cadastrar_coordenadores.php?ajax=secoes_listar', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => {
                    sSecoes = j.secoes || [];
                    renderSecoes();
                })
                .catch(() => { $sTbody.html('<tr><td colspan="5" style="color:#dc3545;padding:12px;">Erro ao carregar.</td></tr>'); });
        }

        jQuery(document).on('click', '.btn-sec', function () {
            if (!TEM_SEC) { alert('Tabela coordenador_secao não existe.'); return; }
            const id   = parseInt(jQuery(this).data('id'));
            const nome = jQuery(this).data('nome') || '';
            jQuery('#s-coord-id').val(id);
            jQuery('#s-coord-nome').text(nome);
            jQuery('#secao-titulo').text('Gerenciar Seções');
            limparAdd();
            sSecoes = [];
            renderSecoes();
            $modalSec.addClass('aberto');
            // pré-preenche município com o primeiro do coordenador (se tiver)
            const c = COORDENADORES.find(x => parseInt(x.id) === id);
            if (c && c.municipios && c.municipios.length) {
                $sMun.val(c.municipios[0]).trigger('change');
            } else {
                carregarZonas('', null);
            }
            carregarSecoesAtuais();
        });

        $sFiltro.on('input', renderSecoes);

        jQuery('#btn-add-secao').on('click', function () {
            const id    = parseInt(jQuery('#s-coord-id').val());
            const zona  = parseInt($sZona.val());
            const secao = parseInt($sSec.val());
            if (!id || !zona || !secao) {
                $sMsg.text('✘ Selecione zona e seção.').css('color', '#dc3545');
                return;
            }
            $sMsg.text('Adicionando...').css('color', '#6c757d');
            const fd = new FormData();
            fd.append('id', id);
            fd.append('numeroZona', zona);
            fd.append('numeroSecao', secao);
            fetch('cadastrar_coordenadores.php?ajax=secao_add', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => {
                    if (j.ok) {
                        $sMsg.text('✔ ' + j.msg).css('color', '#28a745');
                        carregarSecoesAtuais();
                        $sSec.val('').trigger('change');
                    } else {
                        $sMsg.text('✘ ' + j.msg).css('color', '#dc3545');
                    }
                })
                .catch(() => $sMsg.text('✘ Erro de rede.').css('color', '#dc3545'));
        });

        jQuery(document).on('click', '.btn-mini-del', function () {
            const id    = parseInt(jQuery('#s-coord-id').val());
            const zona  = parseInt(jQuery(this).data('zona'));
            const secao = parseInt(jQuery(this).data('secao'));
            if (!id || !zona || !secao) return;
            if (!confirm('Remover a seção ' + secao + ' da zona ' + zona + '?')) return;
            const fd = new FormData();
            fd.append('id', id);
            fd.append('numeroZona', zona);
            fd.append('numeroSecao', secao);
            fetch('cadastrar_coordenadores.php?ajax=secao_del', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => {
                    if (j.ok) carregarSecoesAtuais();
                    else alert('Erro: ' + j.msg);
                })
                .catch(() => alert('Erro de rede.'));
        });

        jQuery('#btn-fechar-secao, #btn-fechar-secao-2').on('click', () => $modalSec.removeClass('aberto'));
        $modalSec.on('click', function (e) {
            if (e.target === this) $modalSec.removeClass('aberto');
        });
    })();
</script>

</body>
</html>
<!-- antes lideranca -->