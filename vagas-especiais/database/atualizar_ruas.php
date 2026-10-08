<?php
/**
 * Gera o índice local de ruas e lugares de Niterói usado nas sugestões da busca do mapa
 * (assets/data/ruas-niteroi.json). Os dados vêm do OpenStreetMap pela API Overpass (gratuita).
 *
 *   php database/atualizar_ruas.php
 *
 * Rode de tempos em tempos (ex.: a cada 3 meses) para incorporar ruas novas ou renomeadas.
 * Como funciona (só consultas por retângulo, que os servidores públicos aceitam mesmo quando cheios):
 *   1. baixa o contorno do município e os contornos dos bairros da região;
 *   2. fica com os bairros cujo centro cai dentro de Niterói;
 *   3. baixa ruas e lugares em quadrados de ~4 km, com pausas entre as consultas;
 *   4. descobre o bairro de cada rua/lugar pelo contorno em que o ponto cai.
 * O progresso fica em storage/cache/osm/ (7 dias): se for interrompido, rodar de novo continua.
 * Servidores: VAGAS_OVERPASS (separados por vírgula). Padrão: overpass-api.de.
 *
 * Licença dos dados: © colaboradores do OpenStreetMap, ODbL 1.0 (https://www.openstreetmap.org/copyright).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
const NITEROI_RELACAO = 2220789;   // limite do município no OpenStreetMap (admin_level 8)
const TAMANHO_QUADRADO = 0.035;    // graus (~4 km)
const PAUSA = 6;                   // segundos entre consultas (uso educado do serviço público)

$servidores = array_filter(array_map('trim', explode(',', getenv('VAGAS_OVERPASS') ?: 'https://overpass-api.de/api/interpreter')));
$cacheDir = APP_ROOT . '/storage/cache/osm';
$saida = APP_ROOT . '/assets/data/ruas-niteroi.json';
@mkdir($cacheDir, 0750, true);
@mkdir(dirname($saida), 0755, true);

/** Vias onde faz sentido procurar vaga (exclui calçadas, trilhas, escadas, ciclovias…). */
$vias = 'motorway|trunk|primary|secondary|tertiary|unclassified|residential|living_street|service|pedestrian|road|track'
      . '|motorway_link|trunk_link|primary_link|secondary_link|tertiary_link';

/** Lugares de referência que as pessoas costumam procurar. */
$lugares = [
    'amenity' => 'hospital|clinic|doctors|school|university|college|bank|townhall|courthouse|police|fire_station|library|theatre|cinema|arts_centre|community_centre|social_facility|bus_station|ferry_terminal|marketplace|place_of_worship',
    'shop'    => 'mall|supermarket|department_store',
    'leisure' => 'park|stadium|sports_centre',
    'tourism' => 'attraction|museum|viewpoint',
];

/* ---------- Overpass ---------- */

function consultar(string $consulta, string $arquivoCache): array
{
    global $servidores;
    if (is_file($arquivoCache) && filemtime($arquivoCache) > time() - 7 * 86400) {
        $d = json_decode((string) file_get_contents($arquivoCache), true);
        if (is_array($d)) {
            return $d;
        }
    }

    for ($tentativa = 0; $tentativa < 12; $tentativa++) {
        $servidor = $servidores[$tentativa % count($servidores)];
        $c = curl_init($servidor);
        curl_setopt_array($c, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['data' => $consulta]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'VagasEspeciaisNiteroi/1.0 (indice de ruas)',
        ]);
        $corpo = curl_exec($c);
        $status = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);

        $dados = is_string($corpo) ? json_decode($corpo, true) : null;
        // O Overpass responde 200 com "remark" quando estoura tempo/memória: também é falha.
        if ($status === 200 && is_array($dados) && isset($dados['elements']) && empty($dados['remark'])) {
            file_put_contents($arquivoCache, json_encode($dados['elements'], JSON_UNESCAPED_UNICODE));
            sleep(PAUSA);
            return $dados['elements'];
        }

        // 429 = "espere sua vez"; 504 = servidor cheio. Espera cada vez mais.
        $espera = min(120, 20 + 15 * $tentativa);
        fwrite(STDERR, "    servidor ocupado (HTTP $status), nova tentativa em {$espera}s…\n");
        sleep($espera);
    }
    throw new RuntimeException('O Overpass não respondeu depois de várias tentativas. Tente mais tarde.');
}

/* ---------- Geometria ---------- */

/** Segmentos [lat1, lng1, lat2, lng2] de todas as linhas de um limite (relação com "out geom"). */
function segmentos(array $relacao): array
{
    $seg = [];
    foreach ($relacao['members'] ?? [] as $m) {
        $g = $m['geometry'] ?? [];
        for ($i = 1, $n = count($g); $i < $n; $i++) {
            $seg[] = [$g[$i - 1]['lat'], $g[$i - 1]['lon'], $g[$i]['lat'], $g[$i]['lon']];
        }
    }
    return $seg;
}

/** Ponto dentro do polígono (regra par-ímpar: funciona sem precisar montar os anéis na ordem). */
function dentro(float $lat, float $lng, array $segmentos): bool
{
    $dentro = false;
    foreach ($segmentos as [$y1, $x1, $y2, $x2]) {
        if (($y1 > $lat) !== ($y2 > $lat) && $lng < ($x2 - $x1) * ($lat - $y1) / ($y2 - $y1) + $x1) {
            $dentro = !$dentro;
        }
    }
    return $dentro;
}

function caixa(array $segmentos): array
{
    $lats = array_merge(array_column($segmentos, 0), array_column($segmentos, 2));
    $lngs = array_merge(array_column($segmentos, 1), array_column($segmentos, 3));
    return [min($lats), min($lngs), max($lats), max($lngs)];
}

/* ---------- 1. Município ---------- */

echo "Contorno de Niterói…\n";
$niteroi = consultar('[out:json][timeout:90];rel(' . NITEROI_RELACAO . ');out geom;', "$cacheDir/niteroi.json")[0] ?? null;
if (!$niteroi) {
    fwrite(STDERR, "✘ Limite do município não encontrado no OpenStreetMap.\n");
    exit(1);
}
$segNiteroi = segmentos($niteroi);
[$latMin, $lngMin, $latMax, $lngMax] = caixa($segNiteroi);
$retangulo = sprintf('%.4f,%.4f,%.4f,%.4f', $latMin, $lngMin, $latMax, $lngMax);

/* ---------- 2. Bairros ---------- */

echo "Bairros…\n";
$relacoes = consultar('[out:json][timeout:90];rel["boundary"="administrative"]["admin_level"="10"](' . $retangulo . ');out geom;',
    "$cacheDir/bairros.json");
$bairros = [];
foreach ($relacoes as $r) {
    $nome = $r['tags']['name'] ?? '';
    $seg = segmentos($r);
    if ($nome === '' || !$seg) {
        continue;
    }
    // É de Niterói se a maior parte da área do bairro cai dentro do município (medido numa grade de
    // pontos). Usar só o "centro" falha em bairros recortados pelo mar, como São Francisco e Itaipu.
    $cx = caixa($seg);
    if ($cx[2] < $latMin || $cx[0] > $latMax || $cx[3] < $lngMin || $cx[1] > $lngMax) {
        continue;
    }
    $noBairro = 0;
    $emNiteroi = 0;
    for ($i = 0; $i <= 14; $i++) {
        for ($j = 0; $j <= 14; $j++) {
            $la = $cx[0] + ($cx[2] - $cx[0]) * ($i + 0.5) / 15;
            $lo = $cx[1] + ($cx[3] - $cx[1]) * ($j + 0.5) / 15;
            if (dentro($la, $lo, $seg)) {
                $noBairro++;
                $emNiteroi += dentro($la, $lo, $segNiteroi) ? 1 : 0;
            }
        }
    }
    if ($noBairro > 0 && $emNiteroi / $noBairro >= 0.5) {
        $bairros[] = ['nome' => $nome, 'seg' => $seg, 'caixa' => $cx];
    }
}
usort($bairros, fn($a, $b) => strcmp($a['nome'], $b['nome']));
echo '  ' . count($bairros) . " bairros de Niterói\n";
if (count($bairros) < 40) {
    fwrite(STDERR, "✘ Poucos bairros encontrados — o mapeamento no OpenStreetMap pode ter mudado.\n");
    exit(1);
}

function bairro_do_ponto(float $lat, float $lng, array $bairros): ?int
{
    foreach ($bairros as $i => $b) {
        [$a, $o, $A, $O] = $b['caixa'];
        if ($lat >= $a && $lat <= $A && $lng >= $o && $lng <= $O && dentro($lat, $lng, $b['seg'])) {
            return $i;
        }
    }
    return null;
}

/* ---------- 3. Ruas e lugares, por quadrados ---------- */

$filtrosLugares = '';
foreach ($lugares as $chave => $valores) {
    $filtrosLugares .= 'nwr["name"]["' . $chave . '"~"^(' . $valores . ')$"]({{q}});';
}

$quadrados = [];
for ($lat = $latMin; $lat < $latMax; $lat += TAMANHO_QUADRADO) {
    for ($lng = $lngMin; $lng < $lngMax; $lng += TAMANHO_QUADRADO) {
        $quadrados[] = [$lat, $lng, min($lat + TAMANHO_QUADRADO, $latMax), min($lng + TAMANHO_QUADRADO, $lngMax)];
    }
}

$agrupados = [];   // "tipo|nome|bairro" => [soma lat, soma lng, n]
$vistos = [];      // cada elemento uma vez só (pode aparecer em dois quadrados)
foreach ($quadrados as $k => [$a, $o, $A, $O]) {
    $q = sprintf('%.4f,%.4f,%.4f,%.4f', $a, $o, $A, $O);
    printf("  quadrado %2d/%d\n", $k + 1, count($quadrados));
    $elementos = consultar('[out:json][timeout:90];(way["highway"~"^(' . $vias . ')$"]["name"](' . $q . ');'
        . str_replace('{{q}}', $q, $filtrosLugares) . ');out tags center;', "$cacheDir/quadrado-" . md5($q) . '.json');

    foreach ($elementos as $e) {
        $id = $e['type'] . $e['id'];
        $nome = trim((string) ($e['tags']['name'] ?? ''));
        $lat = $e['lat'] ?? $e['center']['lat'] ?? null;
        $lng = $e['lon'] ?? $e['center']['lon'] ?? null;
        if (isset($vistos[$id]) || $nome === '' || $lat === null) {
            continue;
        }
        $vistos[$id] = true;
        $b = bairro_do_ponto((float) $lat, (float) $lng, $bairros);
        if ($b === null) {
            continue;   // fora de Niterói (São Gonçalo, Rio, Maricá…)
        }
        $tipo = isset($e['tags']['highway']) ? 0 : 1;   // 0 = rua, 1 = lugar
        $chave = $tipo . '|' . $nome . '|' . $b;
        $agrupados[$chave] ??= [0.0, 0.0, 0];
        $agrupados[$chave][0] += (float) $lat;
        $agrupados[$chave][1] += (float) $lng;
        $agrupados[$chave][2]++;
    }
}

/* ---------- 4. Arquivo compacto: [nome, índice do bairro, lat, lng, tipo] ---------- */

$itens = [];
foreach ($agrupados as $chave => [$somaLat, $somaLng, $n]) {
    [$tipo, $nome, $b] = explode('|', $chave, 3);
    $itens[] = [$nome, (int) $b, round($somaLat / $n, 5), round($somaLng / $n, 5), (int) $tipo];
}
usort($itens, fn($x, $y) => [$x[4], $x[0], $x[1]] <=> [$y[4], $y[0], $y[1]]);

$json = json_encode([
    'fonte'   => '© colaboradores do OpenStreetMap — dados sob licença ODbL 1.0 (openstreetmap.org/copyright)',
    'gerado'  => date('Y-m-d'),
    'bairros' => array_column($bairros, 'nome'),
    'itens'   => $itens,
], JSON_UNESCAPED_UNICODE);
file_put_contents($saida, $json);

$ruas = count(array_filter($itens, fn($i) => $i[4] === 0));
printf("✔ %d ruas e %d lugares em %d bairros → assets/data/ruas-niteroi.json (%d KB)\n",
    $ruas, count($itens) - $ruas, count($bairros), (int) round(strlen($json) / 1024));
