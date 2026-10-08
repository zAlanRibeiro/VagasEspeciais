<?php
/**
 * Inscrição do aparelho para receber notificações.
 *   POST acao=inscrever|remover, tipo=web|fcm|apns, endereco=endpoint ou token do aparelho
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';

$u = api_exigir_login();
api_exigir_post();

$tipo = texto_post('tipo', 10);
$endereco = texto_post('endereco', 512);

if (($_POST['acao'] ?? '') === 'remover') {
    remover_push((int) $u['id'], $endereco);
    json_resposta(['ok' => true]);
}

$erro = inscrever_push((int) $u['id'], $tipo, $endereco);
if ($erro !== null) {
    json_erro($erro);
}
registrar_log('push_inscrito', (int) $u['id'], $tipo);
json_resposta(['ok' => true]);
