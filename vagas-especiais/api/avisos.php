<?php
/**
 * Central de avisos do usuário.
 *   GET            → últimos avisos e quantos não foram lidos
 *   POST lidos=1   → marca todos como lidos
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';

$u = api_exigir_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    api_exigir_post();
    marcar_avisos_lidos((int) $u['id']);
    json_resposta(['ok' => true]);
}

json_resposta([
    'ok'        => true,
    'avisos'    => avisos_do_usuario((int) $u['id']),
    'nao_lidos' => avisos_nao_lidos((int) $u['id']),
]);
