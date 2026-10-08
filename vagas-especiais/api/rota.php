<?php
/**
 * Rota de carro até uma vaga: POST de=lat,lng & para=lat,lng
 * (POST para a posição da pessoa não ficar gravada no registro de acessos do servidor, como ficaria na URL).
 * Só para quem pode usar o mapa (limita o uso do serviço público de rotas).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';
require APP_ROOT . '/includes/rotas.php';

api_exigir_acesso_app();
api_exigir_post();

function ler_ponto(string $campo): ?array
{
    $partes = explode(',', (string) ($_POST[$campo] ?? ''));
    if (count($partes) !== 2 || !is_numeric($partes[0]) || !is_numeric($partes[1])) {
        return null;
    }
    return [(float) $partes[0], (float) $partes[1]];
}

$de = ler_ponto('de');
$para = ler_ponto('para');
if (!$de || !$para) {
    json_erro('Informe a origem e o destino.');
}
if ($de[0] < REGIAO_ORIGEM['lat'][0] || $de[0] > REGIAO_ORIGEM['lat'][1] || $de[1] < REGIAO_ORIGEM['lng'][0] || $de[1] > REGIAO_ORIGEM['lng'][1]) {
    json_erro('Você parece estar longe de Niterói. A rota dentro do app funciona na região metropolitana do Rio.', 422);
}
if (!dentro_de_niteroi($para[0], $para[1])) {
    json_erro('O destino precisa estar em Niterói.', 422);
}

try {
    json_resposta(['ok' => true, 'rota' => calcular_rota($de[0], $de[1], $para[0], $para[1])]);
} catch (RuntimeException $e) {
    error_log('Rotas: ' . $e->getMessage());
    json_erro('Não foi possível calcular a rota agora. Tente de novo ou use o Waze/Google Maps.', 503);
}
