<?php
/**
 * Exclusão da própria conta (exigida pela Play Store e pela App Store).
 * Pede a senha para confirmar. Remove dados pessoais e documentos; os relatos ficam anônimos.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';

$u = api_exigir_login();
api_exigir_post();

if (($_POST['acao'] ?? '') !== 'excluir') {
    json_erro('Ação inválida.');
}

$st = db()->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
$st->execute([$u['id']]);
if (!password_verify(is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '', (string) $st->fetchColumn())) {
    json_erro('Senha incorreta.', 422);
}

if (is_admin($u)) {
    json_erro('Contas de administrador devem ser removidas por outro administrador no painel.', 403);
}

excluir_conta((int) $u['id']);
if (token_da_requisicao() === null) {
    encerrar_sessao();
}
json_resposta(['ok' => true, 'mensagem' => 'Sua conta e seus dados foram excluídos.', 'redirect' => 'login.php?conta_excluida=1']);
