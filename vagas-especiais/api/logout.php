<?php
/**
 * Encerra o acesso: revoga o token do app ou a sessão do site.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';

api_exigir_post();

$u = usuario_atual();
if ($u !== null) {
    registrar_log('logout', (int) $u['id']);
}
if (token_da_requisicao() !== null) {
    revogar_token_atual();
} else {
    encerrar_sessao();
}
json_resposta(['ok' => true]);
