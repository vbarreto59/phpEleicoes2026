<?php
// baixar_malha_pe.php
// Executa 1 vez para gerar br_pe.json com municípios de PE
date_default_timezone_set('America/Sao_Paulo');

$uf = 'PE';
$saida = __DIR__ . '/br_pe.json';

echo "Baixando malha municipal de $uf...\n";

$urlMalha = "https://servicodados.ibge.gov.br/api/v3/malhas/estados/$uf"
          . "?formato=application/vnd.geo+json"
          . "&qualidade=intermediaria"
          . "&intrarregiao=municipio";

$malha = json_decode(file_get_contents($urlMalha), true);
if (!$malha || empty($malha['features'])) {
    die("Falha ao baixar malha.\n");
}
echo "  Municípios (features): " . count($malha['features']) . "\n";

echo "Baixando nomes via API de localidades...\n";
$urlNomes = "https://servicodados.ibge.gov.br/api/v1/localidades/estados/$uf/municipios";
$municipios = json_decode(file_get_contents($urlNomes), true);

$nomePorCodigo = [];
foreach ($municipios as $m) {
    $nomePorCodigo[(string)$m['id']] = $m['nome'];
}
echo "  Nomes obtidos: " . count($nomePorCodigo) . "\n";

echo "Mesclando nomes...\n";
foreach ($malha['features'] as &$f) {
    $cod = (string)($f['properties']['codarea'] ?? '');
    $f['properties']['name']      = $nomePorCodigo[$cod] ?? '';
    $f['properties']['codibge']   = $cod;
}
unset($f);

file_put_contents($saida, json_encode($malha, JSON_UNESCAPED_UNICODE));
echo "OK — salvo em $saida (" . round(filesize($saida)/1024) . " KB)\n";