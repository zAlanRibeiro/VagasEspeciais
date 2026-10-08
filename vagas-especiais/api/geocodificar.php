<?php
/**
 * Busca de endereço para o mapa: GET ?q=texto → lugares em Niterói com coordenadas.
 * Só para quem pode usar o mapa (limita o uso do serviço externo).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';
require APP_ROOT . '/includes/geocodificador.php';

api_exigir_acesso_app();

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 3) {
    json_erro('Digite pelo menos 3 letras.');
}
if (mb_strlen($q) > 120) {
    json_erro('Endereço muito longo.');
}

try {
    json_resposta(['ok' => true, 'resultados' => geocodificar($q)]);
} catch (RuntimeException $e) {
    error_log('Geocodificação: ' . $e->getMessage());
    json_erro('A busca de endereços está indisponível agora. Tente de novo em instantes ou busque pelo bairro.', 503);
}
