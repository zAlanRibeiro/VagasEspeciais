<?php
/**
 * Avisos ao usuário: central de avisos (no app/site) + notificação no celular (push).
 *
 * Canais de push (todos opcionais; sem configuração, o aviso fica só na central):
 *   web  — navegadores e PWA (padrão Web Push/VAPID). As chaves são geradas sozinhas em storage/push/.
 *          A notificação vai "vazia" e o celular busca o texto em api/avisos.php — assim não é preciso
 *          criptografar conteúdo e nenhum dado pessoal passa pelo serviço de push do navegador.
 *   fcm  — app Android (Firebase Cloud Messaging). Requer storage/push/firebase.json (conta de serviço).
 *   apns — app iPhone (Apple Push). Requer storage/push/apns.p8 + VAGAS_APNS_KEY_ID e VAGAS_APNS_TEAM_ID.
 * Passo a passo em app-mobile/PUBLICACAO_LOJAS.md.
 */
declare(strict_types=1);

define('PUSH_DIR', APP_ROOT . '/storage/push');
// Contato exigido pelos serviços de push (e-mail institucional em produção).
define('PUSH_CONTATO', 'mailto:' . (getenv('VAGAS_PUSH_CONTATO') ?: 'contato@exemplo.org'));
define('APNS_BUNDLE', getenv('VAGAS_APNS_BUNDLE') ?: 'br.gov.rj.niteroi.vagasespeciais');
define('APNS_PRODUCAO', getenv('VAGAS_APNS_PRODUCAO') === '1');

/* ---------- Central de avisos ---------- */

/** Registra o aviso e tenta mandar notificação para os aparelhos do usuário (sem nunca falhar a ação). */
function avisar(int $usuario_id, string $titulo, string $corpo, string $tela = 'perfil'): void
{
    db()->prepare('INSERT INTO avisos (usuario_id, titulo, corpo, tela, criado_em) VALUES (?, ?, ?, ?, ?)')
        ->execute([$usuario_id, mb_substr($titulo, 0, 120), mb_substr($corpo, 0, 500), $tela, agora()]);
    try {
        enviar_push($usuario_id, $titulo, $corpo, $tela);
    } catch (Throwable $e) {
        error_log('Push: ' . $e->getMessage());
    }
}

function avisos_do_usuario(int $usuario_id, int $limite = 15): array
{
    $st = db()->prepare("SELECT id, titulo, corpo, tela, lido_em, criado_em FROM avisos
                         WHERE usuario_id = ? ORDER BY id DESC LIMIT $limite");
    $st->execute([$usuario_id]);
    return array_map(fn($a) => [
        'id'     => (int) $a['id'],
        'titulo' => $a['titulo'],
        'corpo'  => $a['corpo'],
        'tela'   => $a['tela'],
        'lido'   => $a['lido_em'] !== null,
        'data'   => data_br($a['criado_em']),
    ], $st->fetchAll());
}

function avisos_nao_lidos(int $usuario_id): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM avisos WHERE usuario_id = ? AND lido_em IS NULL');
    $st->execute([$usuario_id]);
    return (int) $st->fetchColumn();
}

function marcar_avisos_lidos(int $usuario_id): void
{
    db()->prepare('UPDATE avisos SET lido_em = ? WHERE usuario_id = ? AND lido_em IS NULL')->execute([agora(), $usuario_id]);
}

/* ---------- Inscrições dos aparelhos ---------- */

/** @return string|null erro */
function inscrever_push(int $usuario_id, string $tipo, string $endereco): ?string
{
    if ($tipo === 'web') {
        if (!filter_var($endereco, FILTER_VALIDATE_URL) || parse_url($endereco, PHP_URL_SCHEME) !== 'https') {
            return 'Endereço de notificação inválido.';
        }
    } elseif (in_array($tipo, ['fcm', 'apns'], true)) {
        if (!preg_match('/^[A-Za-z0-9:_\-]{20,512}$/', $endereco)) {
            return 'Código do aparelho inválido.';
        }
    } else {
        return 'Tipo de notificação inválido.';
    }
    // O mesmo aparelho pode trocar de conta: o endereço passa a ser do usuário atual.
    db()->prepare('DELETE FROM inscricoes_push WHERE endereco = ?')->execute([$endereco]);
    db()->prepare('INSERT INTO inscricoes_push (usuario_id, tipo, endereco, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([$usuario_id, $tipo, $endereco, agora()]);
    return null;
}

function remover_push(int $usuario_id, string $endereco): void
{
    db()->prepare('DELETE FROM inscricoes_push WHERE usuario_id = ? AND endereco = ?')->execute([$usuario_id, $endereco]);
}

/** Canais de push configurados no servidor. */
function push_disponivel(): array
{
    return [
        'web'  => true,
        'fcm'  => is_file(PUSH_DIR . '/firebase.json'),
        'apns' => is_file(PUSH_DIR . '/apns.p8') && getenv('VAGAS_APNS_KEY_ID') && getenv('VAGAS_APNS_TEAM_ID'),
    ];
}

function enviar_push(int $usuario_id, string $titulo, string $corpo, string $tela): void
{
    $st = db()->prepare('SELECT id, tipo, endereco FROM inscricoes_push WHERE usuario_id = ?');
    $st->execute([$usuario_id]);
    $canais = push_disponivel();
    foreach ($st->fetchAll() as $i) {
        if (empty($canais[$i['tipo']])) {
            continue;
        }
        try {
            $valido = match ($i['tipo']) {
                'web'  => enviar_web_push($i['endereco']),
                'fcm'  => enviar_fcm($i['endereco'], $titulo, $corpo, $tela),
                'apns' => enviar_apns($i['endereco'], $titulo, $corpo, $tela),
            };
            if (!$valido) {
                // O aparelho desinstalou o app ou revogou a permissão: não adianta tentar de novo.
                db()->prepare('DELETE FROM inscricoes_push WHERE id = ?')->execute([$i['id']]);
            }
        } catch (Throwable $e) {
            error_log('Push ' . $i['tipo'] . ': ' . $e->getMessage());
        }
    }
}

/* ---------- Utilidades de criptografia ---------- */

function b64url(string $dados): string
{
    return rtrim(strtr(base64_encode($dados), '+/', '-_'), '=');
}

/** No Windows o OpenSSL do PHP precisa saber onde está o openssl.cnf para gerar chaves. */
function opcoes_openssl(): array
{
    $candidatos = [
        getenv('OPENSSL_CONF') ?: '',
        dirname((string) ini_get('extension_dir')) . '/extras/ssl/openssl.cnf',   // XAMPP / PHP no Windows
    ];
    foreach ($candidatos as $c) {
        if ($c !== '' && is_file($c)) {
            return ['config' => $c];
        }
    }
    return [];
}

/** Assinatura ES256: o OpenSSL devolve DER; JWT usa os 64 bytes "crus" (R || S). */
function assinar_es256(string $entrada, string $pemPrivada): string
{
    if (!openssl_sign($entrada, $der, $pemPrivada, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Falha ao assinar (ES256).');
    }
    $pos = 2 + ((ord($der[1]) & 0x80) ? (ord($der[1]) & 0x7f) : 0);
    $bruta = '';
    for ($n = 0; $n < 2; $n++) {
        $tam = ord($der[$pos + 1]);
        $inteiro = ltrim(substr($der, $pos + 2, $tam), "\0");
        $bruta .= str_pad($inteiro, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $tam;
    }
    return $bruta;
}

function jwt(array $cabecalho, array $dados, string $chave, string $alg): string
{
    $entrada = b64url(json_encode($cabecalho)) . '.' . b64url(json_encode($dados));
    if ($alg === 'ES256') {
        $assinatura = assinar_es256($entrada, $chave);
    } elseif (!openssl_sign($entrada, $assinatura, $chave, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Falha ao assinar (RS256).');
    }
    return $entrada . '.' . b64url($assinatura);
}

function requisicao_push(string $url, array $cabecalhos, string $corpo, bool $http2 = false): array
{
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $corpo,
        CURLOPT_HTTPHEADER     => $cabecalhos,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    if ($http2) {
        curl_setopt($c, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    }
    $resposta = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    $erro = curl_error($c);
    curl_close($c);
    if ($status === 0) {
        throw new RuntimeException('Sem resposta do serviço de push: ' . $erro);
    }
    return [$status, $resposta];
}

/* ---------- Web Push (navegador / PWA) ---------- */

/** Chaves VAPID do servidor, criadas na primeira vez. @return array{privada: string, publica: string} */
function vapid_chaves(): array
{
    $arquivo = PUSH_DIR . '/vapid.json';
    if (is_file($arquivo)) {
        $c = json_decode((string) file_get_contents($arquivo), true);
        if (!empty($c['privada']) && !empty($c['publica'])) {
            return $c;
        }
    }
    $opcoes = opcoes_openssl();
    $chave = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC] + $opcoes);
    if (!$chave || !openssl_pkey_export($chave, $pem, null, $opcoes)) {
        throw new RuntimeException('Não foi possível gerar as chaves de notificação: ' . openssl_error_string());
    }
    $d = openssl_pkey_get_details($chave);
    $publica = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $c = ['privada' => $pem, 'publica' => b64url($publica)];
    if (!is_dir(PUSH_DIR)) {
        mkdir(PUSH_DIR, 0750, true);
    }
    file_put_contents($arquivo, json_encode($c));
    return $c;
}

/** @return bool false se a inscrição não vale mais */
function enviar_web_push(string $endpoint): bool
{
    $v = vapid_chaves();
    $p = parse_url($endpoint);
    $publico = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $token = jwt(['typ' => 'JWT', 'alg' => 'ES256'], ['aud' => $publico, 'exp' => time() + 12 * 3600, 'sub' => PUSH_CONTATO],
        $v['privada'], 'ES256');
    [$status] = requisicao_push($endpoint, [
        'TTL: 86400',
        'Urgency: high',
        'Authorization: vapid t=' . $token . ', k=' . $v['publica'],
        'Content-Length: 0',
    ], '');
    if ($status === 404 || $status === 410) {
        return false;
    }
    if ($status >= 400) {
        throw new RuntimeException("Web Push respondeu HTTP $status");
    }
    return true;
}

/* ---------- Firebase (Android) ---------- */

function fcm_token_acesso(array $conta): string
{
    $cache = PUSH_DIR . '/.fcm-acesso.json';
    $salvo = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    if (!empty($salvo['token']) && $salvo['expira'] > time() + 60) {
        return $salvo['token'];
    }
    $agora = time();
    $assercao = jwt(['alg' => 'RS256', 'typ' => 'JWT'], [
        'iss'   => $conta['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => $conta['token_uri'],
        'iat'   => $agora,
        'exp'   => $agora + 3600,
    ], $conta['private_key'], 'RS256');
    [$status, $resposta] = requisicao_push($conta['token_uri'], ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assercao]));
    $d = json_decode($resposta, true);
    if ($status !== 200 || empty($d['access_token'])) {
        throw new RuntimeException("Firebase recusou a autenticação (HTTP $status)");
    }
    file_put_contents($cache, json_encode(['token' => $d['access_token'], 'expira' => $agora + (int) $d['expires_in']]));
    return $d['access_token'];
}

function enviar_fcm(string $token, string $titulo, string $corpo, string $tela): bool
{
    $conta = json_decode((string) file_get_contents(PUSH_DIR . '/firebase.json'), true);
    $mensagem = ['message' => [
        'token'        => $token,
        'notification' => ['title' => $titulo, 'body' => $corpo],
        'data'         => ['tela' => $tela],
        'android'      => ['priority' => 'HIGH', 'notification' => ['color' => '#1F5AAE', 'icon' => 'ic_stat_aviso']],
    ]];
    [$status, $resposta] = requisicao_push(
        'https://fcm.googleapis.com/v1/projects/' . rawurlencode($conta['project_id']) . '/messages:send',
        ['Authorization: Bearer ' . fcm_token_acesso($conta), 'Content-Type: application/json'],
        json_encode($mensagem, JSON_UNESCAPED_UNICODE)
    );
    if ($status === 404 || ($status === 400 && str_contains($resposta, 'registration token'))) {
        return false;
    }
    if ($status >= 400) {
        throw new RuntimeException("Firebase respondeu HTTP $status: " . mb_substr($resposta, 0, 200));
    }
    return true;
}

/* ---------- Apple Push (iPhone) ---------- */

function enviar_apns(string $token, string $titulo, string $corpo, string $tela): bool
{
    $cache = PUSH_DIR . '/.apns-jwt.json';
    $salvo = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    if (empty($salvo['jwt']) || $salvo['criado'] < time() - 50 * 60) {   // a Apple aceita o mesmo JWT por até 1 h
        $salvo = ['jwt' => jwt(['alg' => 'ES256', 'kid' => getenv('VAGAS_APNS_KEY_ID')],
            ['iss' => getenv('VAGAS_APNS_TEAM_ID'), 'iat' => time()],
            (string) file_get_contents(PUSH_DIR . '/apns.p8'), 'ES256'), 'criado' => time()];
        file_put_contents($cache, json_encode($salvo));
    }
    $host = APNS_PRODUCAO ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
    [$status, $resposta] = requisicao_push($host . '/3/device/' . $token, [
        'authorization: bearer ' . $salvo['jwt'],
        'apns-topic: ' . APNS_BUNDLE,
        'apns-push-type: alert',
        'apns-priority: 10',
        'content-type: application/json',
    ], json_encode(['aps' => ['alert' => ['title' => $titulo, 'body' => $corpo], 'sound' => 'default'], 'tela' => $tela],
        JSON_UNESCAPED_UNICODE), true);
    if ($status === 410 || ($status === 400 && str_contains($resposta, 'BadDeviceToken'))) {
        return false;
    }
    if ($status >= 400) {
        throw new RuntimeException("APNs respondeu HTTP $status: " . mb_substr($resposta, 0, 200));
    }
    return true;
}
