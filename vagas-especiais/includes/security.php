<?php
/**
 * Funções de segurança: escape de saída, CSRF, cabeçalhos HTTP, sessão e logs.
 */
declare(strict_types=1);

function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function agora(): string
{
    return date('Y-m-d H:i:s');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('VAGASSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function enviar_cabecalhos_seguranca(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' https://unpkg.com; "
        . "style-src 'self' 'unsafe-inline' https://unpkg.com; "
        . "img-src 'self' data: blob: https://*.tile.openstreetmap.org https://unpkg.com; "
        . "connect-src 'self'; font-src 'self'; object-src 'none'; frame-ancestors 'none'; "
        . "base-uri 'self'; form-action 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valido(): bool
{
    // Requisições do app usam token no cabeçalho, que o navegador nunca envia sozinho: não há risco de CSRF.
    if (autenticado_por_token()) {
        return true;
    }
    $enviado = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($enviado) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $enviado);
}

/** Para páginas: encerra com 400 se o token for inválido. */
function exigir_csrf(): void
{
    if (!csrf_valido()) {
        http_response_code(400);
        exit('Sessão expirada ou requisição inválida. Volte e tente novamente.');
    }
}

/* ---------- Respostas JSON (API) ---------- */

function json_resposta(array $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_erro(string $mensagem, int $status = 400): never
{
    json_resposta(['ok' => false, 'erro' => $mensagem], $status);
}

/**
 * @param bool $exigir_csrf false só em login/cadastro do app, que não usam sessão nem cookie.
 */
function api_exigir_post(bool $exigir_csrf = true): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_erro('Método não permitido.', 405);
    }
    if ($exigir_csrf && !csrf_valido()) {
        json_erro('Sessão expirada. Recarregue a página.', 419);
    }
}

/* ---------- CORS (app móvel) ---------- */

/** Origens das telas do app (Capacitor) e outras definidas em VAGAS_CORS_ORIGENS (separadas por vírgula). */
function origens_permitidas(): array
{
    $extras = array_filter(array_map('trim', explode(',', (string) getenv('VAGAS_CORS_ORIGENS'))));
    return array_merge(['capacitor://localhost', 'https://localhost', 'http://localhost', 'ionic://localhost'], $extras);
}

function cors_api(): void
{
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    // localhost em qualquer porta: telas do app servidas localmente durante o desenvolvimento.
    // A API usa token (não cookie), então liberar uma origem não expõe sessões de outros usuários.
    $permitida = in_array($origem, origens_permitidas(), true) || preg_match('#^https?://localhost(:\d+)?$#', $origem);
    if ($origem !== '' && $permitida) {
        header('Access-Control-Allow-Origin: ' . $origem);
        header('Access-Control-Allow-Headers: Authorization, X-Auth-Token, Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Max-Age: 86400');
    }
    header('Vary: Origin');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/* ---------- Mensagens flash (painel) ---------- */

function flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function flashes(): array
{
    $itens = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $itens;
}

function redirecionar(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/* ---------- Registro de acessos ---------- */

function ip_cliente(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function registrar_log(string $acao, ?int $usuario_id = null, ?string $detalhe = null): void
{
    try {
        $st = db()->prepare('INSERT INTO logs_acesso (usuario_id, acao, detalhe, ip, user_agent, criado_em)
                             VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([
            $usuario_id,
            $acao,
            $detalhe !== null ? mb_substr($detalhe, 0, 500) : null,
            ip_cliente(),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            agora(),
        ]);
    } catch (Throwable $e) {
        error_log('Falha ao registrar log: ' . $e->getMessage());
    }
}

/* ---------- Validação ---------- */

function texto_post(string $campo, int $max = 255): string
{
    $v = $_POST[$campo] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}
