<?php
/**
 * Rotas de carro dentro do app (sem abrir Waze/Google Maps).
 *
 * Usa o Valhalla com dados do OpenStreetMap — gratuito, sem chave. Padrão: servidor público da
 * FOSSGIS (valhalla1.openstreetmap.de), de uso justo: cache, no máximo 1 consulta por segundo e
 * crédito ao OpenStreetMap. Para uso em larga escala, rode um Valhalla próprio e defina VAGAS_ROTAS_URL.
 * Não há informação de trânsito em tempo real (isso o Waze/Google têm e não oferecem de graça).
 */
declare(strict_types=1);

require_once __DIR__ . '/geocodificador.php';   // requisitar_com_limite() (limite por provedor)

define('ROTAS_URL', getenv('VAGAS_ROTAS_URL') ?: 'https://valhalla1.openstreetmap.de/route');
define('ROTAS_CACHE', APP_ROOT . '/storage/cache/rotas');
const ROTAS_CACHE_HORAS = 24;

/** Região aceita para a origem (Grande Rio e arredores): quem vem de fora de Niterói também pode traçar rota. */
const REGIAO_ORIGEM = ['lat' => [-23.20, -22.40], 'lng' => [-43.90, -42.40]];

/**
 * @return array{distancia_m: int, duracao_s: int, linha: array, passos: array}
 * @throws RuntimeException
 */
function calcular_rota(float $deLat, float $deLng, float $paraLat, float $paraLng): array
{
    // Arredonda a origem (~10 m) para aproveitar o cache entre pessoas no mesmo ponto.
    $chave = sprintf('%.4f,%.4f|%.5f,%.5f', $deLat, $deLng, $paraLat, $paraLng);
    $arquivo = ROTAS_CACHE . '/' . sha1($chave) . '.json';
    if (is_file($arquivo) && filemtime($arquivo) > time() - ROTAS_CACHE_HORAS * 3600) {
        $salvo = json_decode((string) file_get_contents($arquivo), true);
        if (is_array($salvo)) {
            return $salvo;
        }
    }

    $pedido = [
        'locations'         => [['lat' => round($deLat, 5), 'lon' => round($deLng, 5)], ['lat' => $paraLat, 'lon' => $paraLng]],
        'costing'           => 'auto',
        'units'             => 'kilometers',
        'language'          => 'pt-BR',
        'directions_options' => ['language' => 'pt-BR', 'units' => 'kilometers'],
    ];
    $dados = json_decode(requisitar_com_limite(ROTAS_URL . '?json=' . rawurlencode(json_encode($pedido)), 15), true);
    $trecho = $dados['trip']['legs'][0] ?? null;
    if (!$trecho) {
        throw new RuntimeException('Rota não encontrada.');
    }

    $linha = decodificar_polilinha((string) $trecho['shape'], 6);
    $passos = [];
    foreach ($trecho['maneuvers'] as $m) {
        $texto = instrucao_em_portugues($m);
        $passos[] = [
            'texto'      => $texto,
            'icone'      => icone_da_manobra((int) $m['type']),
            'distancia_m' => (int) round(((float) $m['length']) * 1000),
            'inicio'     => (int) $m['begin_shape_index'],
        ];
    }

    $rota = [
        'distancia_m' => (int) round(((float) $dados['trip']['summary']['length']) * 1000),
        'duracao_s'   => (int) round((float) $dados['trip']['summary']['time']),
        'linha'       => $linha,
        'passos'      => $passos,
    ];
    if (!is_dir(ROTAS_CACHE)) {
        @mkdir(ROTAS_CACHE, 0750, true);
    }
    @file_put_contents($arquivo, json_encode($rota, JSON_UNESCAPED_UNICODE));
    limpar_rotas_antigas();
    return $rota;
}

/** As rotas contêm o ponto de partida da pessoa: passadas 24 h, são apagadas (não só ignoradas). */
function limpar_rotas_antigas(): void
{
    if (random_int(1, 20) !== 1) {   // de vez em quando basta
        return;
    }
    foreach (glob(ROTAS_CACHE . '/*.json') ?: [] as $arquivo) {
        if (filemtime($arquivo) < time() - ROTAS_CACHE_HORAS * 3600) {
            @unlink($arquivo);
        }
    }
}

/** Polilinha codificada (formato Google, precisão 6 no Valhalla) → [[lat, lng], ...] */
function decodificar_polilinha(string $codificada, int $precisao): array
{
    $pontos = [];
    $indice = 0;
    $lat = 0;
    $lng = 0;
    $fator = 10 ** $precisao;
    $tamanho = strlen($codificada);
    while ($indice < $tamanho) {
        foreach (['lat', 'lng'] as $eixo) {
            $resultado = 0;
            $deslocamento = 0;
            do {
                $b = ord($codificada[$indice++]) - 63;
                $resultado |= ($b & 0x1f) << $deslocamento;
                $deslocamento += 5;
            } while ($b >= 0x20 && $indice < $tamanho);
            $delta = ($resultado & 1) ? ~($resultado >> 1) : ($resultado >> 1);
            $$eixo += $delta;
        }
        $pontos[] = [round($lat / $fator, 6), round($lng / $fator, 6)];
    }
    return $pontos;
}

/**
 * O Valhalla traduz quase tudo para o português, mas algumas frases (ex.: rotatórias) ainda vêm em
 * inglês. Nesses casos a instrução é montada aqui, a partir do tipo da manobra.
 */
function instrucao_em_portugues(array $m): string
{
    $texto = trim((string) ($m['instruction'] ?? ''));
    if ($texto !== '' && !preg_match('/\b(the|onto|enter|exit|take|roundabout|toward|continue on|turn|keep)\b/i', $texto)) {
        return $texto;
    }
    $rua = $m['street_names'][0] ?? ($m['begin_street_names'][0] ?? '');
    $para = $rua !== '' ? ' para ' . $rua : '';
    $ordinais = [1 => 'primeira', 'segunda', 'terceira', 'quarta', 'quinta', 'sexta', 'sétima', 'oitava'];
    $saida = $ordinais[(int) ($m['roundabout_exit_count'] ?? 0)] ?? '';

    return match ((int) $m['type']) {
        26      => 'Na rotatória, pegue a ' . ($saida ?: 'próxima') . ' saída' . $para . '.',
        27      => 'Saia da rotatória' . $para . '.',
        1, 2, 3 => 'Siga' . ($rua !== '' ? ' pela ' . $rua : '') . '.',
        4, 5, 6 => 'Você chegou ao destino.',
        8, 7    => 'Continue em frente' . $para . '.',
        9       => 'Mantenha-se levemente à direita' . $para . '.',
        10      => 'Vire à direita' . $para . '.',
        11      => 'Vire acentuadamente à direita' . $para . '.',
        12, 13  => 'Faça o retorno' . $para . '.',
        14      => 'Vire acentuadamente à esquerda' . $para . '.',
        15      => 'Vire à esquerda' . $para . '.',
        16      => 'Mantenha-se levemente à esquerda' . $para . '.',
        17, 22  => 'Siga em frente' . $para . '.',
        18, 20, 23 => 'Mantenha-se à direita' . $para . '.',
        19, 21, 24 => 'Mantenha-se à esquerda' . $para . '.',
        25, 37, 38 => 'Entre' . ($rua !== '' ? ' na ' . $rua : ' na via') . '.',
        default => 'Siga' . $para . '.',
    };
}

/** Ícone da manobra (nomes do sprite: i-<nome>) */
function icone_da_manobra(int $tipo): string
{
    return match ($tipo) {
        4, 5, 6        => 'bandeira',
        9, 10, 11, 18, 20, 23, 37 => 'virar-direita',
        14, 15, 16, 19, 21, 24, 38 => 'virar-esquerda',
        12, 13         => 'retorno',
        26, 27         => 'rotatoria',
        default        => 'seguir',
    };
}
