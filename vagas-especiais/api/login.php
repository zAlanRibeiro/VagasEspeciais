<?php
/**
 * Login. Site: cria sessão (exige CSRF). App (modo=app): devolve um token, sem sessão.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';

$modo_app = ($_POST['modo'] ?? '') === 'app';
api_exigir_post(!$modo_app);

if (login_bloqueado()) {
    json_erro('Muitas tentativas de login. Aguarde ' . LOGIN_JANELA_MINUTOS . ' minutos e tente novamente.', 429);
}

$email = mb_strtolower(texto_post('email', 190));
$senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';

$st = db()->prepare('SELECT * FROM usuarios WHERE email = ?');
$st->execute([$email]);
$u = $st->fetch();

// Verifica um hash mesmo quando o e-mail não existe, para não revelar contas pelo tempo de resposta.
$hash = $u['senha_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
if (!password_verify($senha, $hash) || !$u) {
    registrar_log('login_falha', $u ? (int) $u['id'] : null, $email);
    json_erro('E-mail ou senha incorretos.', 401);
}

if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
    db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
        ->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
}

if ($modo_app) {
    $token = emitir_token((int) $u['id'], texto_post('dispositivo', 100));
    registrar_log('login_app', (int) $u['id']);
    json_resposta(['ok' => true, 'token' => $token, 'usuario' => usuario_publico($u)]);
}

autenticar($u);
registrar_log('login', (int) $u['id']);

$destino = match (true) {
    $u['papel'] === 'admin'     => '../admin/index.php',
    pode_usar_app($u)           => 'mapa.php',
    default                     => 'perfil.php',
};
json_resposta(['ok' => true, 'redirect' => $destino]);
