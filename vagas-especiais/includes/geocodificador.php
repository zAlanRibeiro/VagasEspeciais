<?php
/**
 * Busca de endereços (texto → coordenadas) para a pesquisa do mapa.
 *
 * Provedores gratuitos baseados no OpenStreetMap, consultados em cascata até achar algo:
 *   1. Nominatim com o texto como digitado (abreviações expandidas: "Av" → "Avenida");
 *   2. Nominatim só com as palavras que identificam o lugar — sem conectivos ("de" × "da" varia entre
 *      a placa e o cadastro) e sem o tipo da via (o Nominatim às vezes não acha "Avenida X", mas acha "X");
 *   3. Photon, que tolera erros de digitação ("Francsico") — aceito só se o nome for parecido.
 * Política de uso respeitada: no máximo 1 consulta por segundo, identificação da aplicação,
 * resultados em cache e nenhuma consulta "a cada letra" (só quando a pessoa confirma a busca).
 * Para trocar de provedor: VAGAS_GEOCODER_URL (Nominatim) e VAGAS_GEOCODER_FUZZY_URL (Photon; "off" desliga).
 */
declare(strict_types=1);

define('GEOCODER_URL', getenv('VAGAS_GEOCODER_URL') ?: 'https://nominatim.openstreetmap.org/search');
define('GEOCODER_FUZZY_URL', getenv('VAGAS_GEOCODER_FUZZY_URL') ?: 'https://photon.komoot.io/api/');
define('GEOCODER_CONTATO', getenv('VAGAS_GEOCODER_CONTATO') ?: '');
define('GEOCODER_CACHE', APP_ROOT . '/storage/cache/geocodificacao');
const GEOCODER_CACHE_DIAS = 30;
const GEOCODER_CACHE_VAZIO_DIAS = 1; // buscas sem resultado são refeitas logo (o mapa do OSM é atualizado sempre)

/** Abreviações comuns em endereços brasileiros. */
const ABREVIACOES = [
    'av' => 'Avenida', 'avda' => 'Avenida', 'r' => 'Rua', 'tv' => 'Travessa', 'trav' => 'Travessa',
    'est' => 'Estrada', 'estr' => 'Estrada', 'al' => 'Alameda', 'pc' => 'Praça', 'pca' => 'Praça',
    'pç' => 'Praça', 'pça' => 'Praça', 'rod' => 'Rodovia', 'lgo' => 'Largo', 'lg' => 'Largo',
    'dr' => 'Doutor', 'dra' => 'Doutora', 'prof' => 'Professor', 'profa' => 'Professora',
    'gal' => 'General', 'gen' => 'General', 'cel' => 'Coronel', 'alm' => 'Almirante',
    'pres' => 'Presidente', 'eng' => 'Engenheiro', 'cap' => 'Capitão', 'ten' => 'Tenente',
    'mal' => 'Marechal', 'min' => 'Ministro', 'des' => 'Desembargador', 'vsc' => 'Visconde',
    'sta' => 'Santa', 'sto' => 'Santo',
];

/** Palavras que não ajudam a distinguir um endereço. */
const PALAVRAS_GENERICAS = ['de', 'da', 'do', 'dos', 'das', 'e', 'rua', 'avenida', 'travessa', 'estrada',
    'alameda', 'praca', 'rodovia', 'largo', 'niteroi', 'rj'];

/**
 * @return array<int, array{titulo: string, detalhe: string, lat: float, lng: float}>
 * @throws RuntimeException quando os serviços não respondem
 */
function geocodificar(string $consulta): array
{
    $consulta = preg_replace('/\s+/u', ' ', trim($consulta));
    $arquivoCache = GEOCODER_CACHE . '/' . sha1(mb_strtolower($consulta)) . '.json';

    $salvo = is_file($arquivoCache) ? json_decode((string) file_get_contents($arquivoCache), true) : null;
    if (is_array($salvo) && isset($salvo['resultados'], $salvo['quando'])) {
        $dias = $salvo['resultados'] ? GEOCODER_CACHE_DIAS : GEOCODER_CACHE_VAZIO_DIAS;
        if ($salvo['quando'] > time() - $dias * 86400) {
            return $salvo['resultados'];
        }
    }

    $expandida = expandir_abreviacoes($consulta);
    $semConectivos = remover_conectivos($expandida);
    $nucleo = remover_conectivos($expandida, true);

    $resultados = buscar_nominatim($expandida);
    if (!$resultados && $nucleo !== $expandida) {
        $resultados = buscar_nominatim($nucleo);
    }
    $completo = true;
    if (!$resultados && GEOCODER_FUZZY_URL !== 'off') {
        try {
            $resultados = buscar_photon($semConectivos);
        } catch (RuntimeException $e) {
            // O provedor reserva falhou: responde sem ele e não guarda o "nada encontrado" no cache.
            error_log('Geocodificação (Photon): ' . $e->getMessage());
            $completo = false;
        }
    }

    if (!$completo) {
        return $resultados;
    }
    if (!is_dir(GEOCODER_CACHE)) {
        @mkdir(GEOCODER_CACHE, 0750, true);
    }
    @file_put_contents($arquivoCache, json_encode(['quando' => time(), 'resultados' => $resultados], JSON_UNESCAPED_UNICODE));
    return $resultados;
}

/* ---------- Preparação do texto ---------- */

function expandir_abreviacoes(string $texto): string
{
    return preg_replace_callback('/(?<![\p{L}\d])(\p{L}+)\.?(?![\p{L}\d])/u', function ($m) {
        $chave = mb_strtolower($m[1]);
        return ABREVIACOES[$chave] ?? $m[0];
    }, $texto);
}

/** Tira "de", "da"… e, se pedido, o tipo da via ("Rua", "Avenida"…). */
function remover_conectivos(string $texto, bool $tambemTipoDaVia = false): string
{
    $palavras = 'de|da|do|dos|das|e' . ($tambemTipoDaVia ? '|rua|avenida|travessa|estrada|alameda|praça|praca|rodovia|largo|ladeira|beco' : '');
    $sem = preg_replace('/(?<![\p{L}\d])(' . $palavras . ')(?![\p{L}\d])/iu', ' ', $texto);
    $sem = trim(preg_replace(['/\s+/u', '/\s*,\s*/u'], [' ', ', '], $sem), ' ,');
    return $sem !== '' ? $sem : $texto;
}

/** Palavras significativas, sem acento e em minúsculas. */
function palavras_chave(string $texto): array
{
    $palavras = preg_split('/[^a-z0-9]+/', sem_acentos($texto), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($palavras, fn($p) => strlen($p) >= 3 && !ctype_digit($p) && !in_array($p, PALAVRAS_GENERICAS, true)));
}

/** O resultado tem nome parecido com o que foi digitado? (tolera letras trocadas) */
function nome_parecido(string $consulta, string $nome): bool
{
    $procuradas = palavras_chave($consulta);
    if (!$procuradas) {
        return true;
    }
    $doNome = palavras_chave($nome);
    $achadas = 0;
    foreach ($procuradas as $p) {
        $limite = strlen($p) >= 6 ? 2 : 1;
        foreach ($doNome as $n) {
            if (str_starts_with($n, $p) || levenshtein($p, $n) <= $limite) {
                $achadas++;
                break;
            }
        }
    }
    return $achadas / count($procuradas) >= 0.6;
}

function eh_niteroi(string $texto): bool
{
    return (bool) preg_match('/niter[oó]i/iu', $texto);
}

/* ---------- Provedores ---------- */

function buscar_nominatim(string $texto): array
{
    $url = GEOCODER_URL . '?' . http_build_query([
        'format'          => 'jsonv2',
        'q'               => eh_niteroi($texto) ? $texto : $texto . ', Niterói, RJ',
        'countrycodes'    => 'br',
        'viewbox'         => NITEROI_LNG[0] . ',' . NITEROI_LAT[1] . ',' . NITEROI_LNG[1] . ',' . NITEROI_LAT[0],
        'bounded'         => 1,
        'addressdetails'  => 1,
        'limit'           => 6,
        'accept-language' => 'pt-BR',
    ]);
    $brutos = json_decode(requisitar_com_limite($url), true);
    if (!is_array($brutos)) {
        throw new RuntimeException('Resposta inválida do Nominatim.');
    }

    $resultados = [];
    foreach ($brutos as $r) {
        $lat = (float) ($r['lat'] ?? 0);
        $lng = (float) ($r['lon'] ?? 0);
        if (!dentro_de_niteroi($lat, $lng) || !eh_niteroi((string) ($r['display_name'] ?? ''))) {
            continue;
        }
        $a = $r['address'] ?? [];
        [$titulo, $detalhe] = nomes_do_endereco(
            trim((string) ($r['name'] ?? '')),
            (string) ($a['road'] ?? $a['pedestrian'] ?? $a['footway'] ?? ''),
            (string) ($a['house_number'] ?? ''),
            (string) ($a['suburb'] ?? $a['neighbourhood'] ?? $a['quarter'] ?? $a['city_district'] ?? ''),
            (string) strtok((string) ($r['display_name'] ?? ''), ',')
        );
        $resultados[] = ['titulo' => $titulo, 'detalhe' => $detalhe, 'lat' => round($lat, 6), 'lng' => round($lng, 6)];
    }
    return sem_repetidos($resultados);
}

function buscar_photon(string $texto): array
{
    $url = GEOCODER_FUZZY_URL . '?' . http_build_query([
        'q'     => eh_niteroi($texto) ? $texto : $texto . ' Niterói',
        'limit' => 8,
        'bbox'  => NITEROI_LNG[0] . ',' . NITEROI_LAT[0] . ',' . NITEROI_LNG[1] . ',' . NITEROI_LAT[1],
    ]);
    $dados = json_decode(requisitar_com_limite($url, 15), true); // o Photon público às vezes demora
    if (!is_array($dados) || !isset($dados['features'])) {
        throw new RuntimeException('Resposta inválida do Photon.');
    }

    $resultados = [];
    foreach ($dados['features'] as $f) {
        $p = $f['properties'] ?? [];
        $lng = (float) ($f['geometry']['coordinates'][0] ?? 0);
        $lat = (float) ($f['geometry']['coordinates'][1] ?? 0);
        if (!dentro_de_niteroi($lat, $lng) || !eh_niteroi((string) ($p['city'] ?? ''))) {
            continue;
        }
        $nome = trim((string) ($p['name'] ?? ''));
        $rua = (string) ($p['street'] ?? '');
        // O Photon aceita palavras aproximadas e às vezes erra feio: confere se o nome se parece com o digitado.
        if (!nome_parecido($texto, $nome . ' ' . $rua)) {
            continue;
        }
        [$titulo, $detalhe] = nomes_do_endereco($nome, $rua, (string) ($p['housenumber'] ?? ''),
            (string) ($p['district'] ?? $p['locality'] ?? ''), $nome);
        $resultados[] = ['titulo' => $titulo, 'detalhe' => $detalhe, 'lat' => round($lat, 6), 'lng' => round($lng, 6)];
    }
    return array_slice(sem_repetidos($resultados), 0, 6);
}

/** Título curto ("Plaza Shopping") e detalhe ("Rua Quinze de Novembro, 8 · Centro"). */
function nomes_do_endereco(string $nome, string $rua, string $numero, string $bairro, string $reserva): array
{
    $ruaNumero = trim($rua . ($numero !== '' ? ', ' . $numero : ''));
    if ($nome !== '' && $nome !== $rua && $nome !== $bairro) {
        $titulo = $nome;
        $detalhe = implode(' · ', array_filter([$ruaNumero, $bairro]));
    } elseif ($ruaNumero !== '') {
        $titulo = $ruaNumero;
        $detalhe = $bairro;
    } else {
        $titulo = $nome !== '' ? $nome : $reserva;
        $detalhe = $titulo === $bairro ? 'Bairro' : $bairro;
    }
    return [$titulo, $detalhe ?: 'Niterói'];
}

function sem_repetidos(array $resultados): array
{
    $vistos = [];
    return array_values(array_filter($resultados, function ($r) use (&$vistos) {
        $id = mb_strtolower($r['titulo'] . '|' . $r['detalhe']);
        if ($r['titulo'] === '' || isset($vistos[$id])) {
            return false;
        }
        return $vistos[$id] = true;
    }));
}

/** Faz a requisição garantindo intervalo mínimo de 1 s entre consultas ao mesmo provedor. */
function requisitar_com_limite(string $url, int $tempoLimite = 8): string
{
    if (!is_dir(GEOCODER_CACHE)) {
        @mkdir(GEOCODER_CACHE, 0750, true);
    }
    $provedor = preg_replace('/[^a-z0-9.-]/', '', (string) parse_url($url, PHP_URL_HOST));
    $trava = fopen(GEOCODER_CACHE . '/.ultima-consulta-' . $provedor, 'c+');
    if ($trava === false || !flock($trava, LOCK_EX)) {
        throw new RuntimeException('Não foi possível consultar o serviço de endereços.');
    }
    try {
        $ultima = (float) stream_get_contents($trava);
        $espera = $ultima + 1.0 - microtime(true);
        if ($espera > 0) {
            usleep((int) ($espera * 1_000_000));
        }

        $agente = 'VagasEspeciaisNiteroi/1.0' . (GEOCODER_CONTATO !== '' ? ' (' . GEOCODER_CONTATO . ')' : '');
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $tempoLimite,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => $agente,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $corpo = curl_exec($c);
        $status = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        $falha = curl_error($c);
        curl_close($c);

        ftruncate($trava, 0);
        rewind($trava);
        fwrite($trava, (string) microtime(true));

        if (!is_string($corpo) || $status !== 200) {
            throw new RuntimeException('Serviço de endereços indisponível (HTTP ' . $status . ($falha !== '' ? ', ' . $falha : '') . ').');
        }
        return $corpo;
    } finally {
        flock($trava, LOCK_UN);
        fclose($trava);
    }
}
