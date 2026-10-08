<?php
/**
 * Autenticação de usuários (sessão).
 */
declare(strict_types=1);

const LOGIN_MAX_FALHAS = 5;
const LOGIN_JANELA_MINUTOS = 15;

const TOKEN_VALIDADE_DIAS = 90;

/**
 * Usuário logado: pelo token "Authorization: Bearer" (app móvel) ou pela sessão (site).
 * Se a requisição traz um token, a sessão é ignorada.
 */
function usuario_atual(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;

    $token = token_da_requisicao();
    if ($token !== null) {
        $id = usuario_do_token($token);
    } else {
        $id = !empty($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    if ($id !== null) {
        $st = db()->prepare('SELECT id, nome, email, papel, suspenso, criado_em FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $cache = $st->fetch() ?: null;
        if ($cache === null && $token === null) {
            unset($_SESSION['uid']);
        }
        if ($cache !== null) {
            $cache['perfis'] = perfis_do_usuario((int) $cache['id']);
        }
    }
    return $cache;
}

/* ---------- Tokens do app móvel ---------- */

function token_da_requisicao(): ?string
{
    $cabecalho = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($cabecalho), $m)) {
        return strtolower($m[1]);
    }
    // Alternativa para hospedagens que removem o cabeçalho Authorization.
    $alt = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    return preg_match('/^[a-f0-9]{64}$/i', $alt) ? strtolower($alt) : null;
}

function autenticado_por_token(): bool
{
    return token_da_requisicao() !== null && usuario_atual() !== null;
}

function usuario_do_token(string $token): ?int
{
    $st = db()->prepare('SELECT id, usuario_id, usado_em FROM api_tokens WHERE token_hash = ? AND expira_em > ?');
    $st->execute([hash('sha256', $token), agora()]);
    $t = $st->fetch();
    if (!$t) {
        return null;
    }
    // Renova a validade a cada uso (no máximo uma escrita por hora).
    if (strtotime($t['usado_em']) < time() - 3600) {
        db()->prepare('UPDATE api_tokens SET usado_em = ?, expira_em = ? WHERE id = ?')
            ->execute([agora(), date('Y-m-d H:i:s', strtotime('+' . TOKEN_VALIDADE_DIAS . ' days')), $t['id']]);
    }
    return (int) $t['usuario_id'];
}

/** Cria um token para o app. O valor só é devolvido aqui; no banco fica apenas o hash. */
function emitir_token(int $usuario_id, string $dispositivo = ''): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO api_tokens (usuario_id, token_hash, dispositivo, criado_em, usado_em, expira_em)
                   VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$usuario_id, hash('sha256', $token), mb_substr($dispositivo, 0, 100) ?: null, agora(), agora(),
                   date('Y-m-d H:i:s', strtotime('+' . TOKEN_VALIDADE_DIAS . ' days'))]);
    return $token;
}

function revogar_token_atual(): void
{
    $token = token_da_requisicao();
    if ($token !== null) {
        db()->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
}

/** Dados do usuário devolvidos ao app. */
function usuario_publico(array $u): array
{
    $perfis = $u['perfis'] ?? perfis_do_usuario((int) $u['id']);
    $publico = fn(string $tipo) => ['tipo' => $tipo] + PERFIS[$tipo]
        + ['com_comprovacao' => in_array($tipo, PERFIS_COM_COMPROVACAO, true)];
    return [
        'nome'          => $u['nome'],
        'email'         => $u['email'],
        'papel'         => $u['papel'],
        'suspenso'      => !empty($u['suspenso']),
        'perfis'        => array_values(array_map(fn($p) => $publico($p['tipo']) + [
            'status'           => $p['status'],
            'status_texto'     => status_perfil_texto($p),
            'descricao'        => descricao_perfil($p),
            'motivo_recusa'    => $p['motivo_recusa'],
            'aceita_documento' => in_array($p['tipo'], PERFIS_COM_COMPROVACAO, true) && perfil_aceita_documento($p),
        ], $perfis)),
        // Perfis que a pessoa ainda pode acrescentar à conta (o rotativo todos já têm)
        'perfis_novos'  => array_map($publico, array_values(array_diff(PERFIS_COM_COMPROVACAO, array_keys($perfis)))),
        'categorias'    => categorias_visiveis($u + ['perfis' => $perfis]),
        'pode_usar_app' => pode_usar_app($u + ['perfis' => $perfis]),
    ];
}

function is_admin(?array $u): bool
{
    return $u !== null && $u['papel'] === 'admin';
}

/** Pode usar o mapa: tem ao menos um perfil aprovado (o rotativo já nasce aprovado) e a conta não está suspensa. */
function pode_usar_app(?array $u): bool
{
    return categorias_visiveis($u) !== [];
}

function autenticar(array $usuario): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $usuario['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function encerrar_sessao(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function login_bloqueado(): bool
{
    $limite = date('Y-m-d H:i:s', time() - LOGIN_JANELA_MINUTOS * 60);
    $st = db()->prepare("SELECT COUNT(*) FROM logs_acesso WHERE acao = 'login_falha' AND ip = ? AND criado_em > ?");
    $st->execute([ip_cliente(), $limite]);
    return (int) $st->fetchColumn() >= LOGIN_MAX_FALHAS;
}

/* ---------- Guardas para páginas (public/) ---------- */

function exigir_login(): array
{
    $u = usuario_atual();
    if ($u === null) {
        redirecionar('login.php');
    }
    return $u;
}

function exigir_acesso_app(): array
{
    $u = exigir_login();
    if (!pode_usar_app($u)) {
        redirecionar('perfil.php');
    }
    return $u;
}

/* ---------- Guardas para a API (api/) ---------- */

function api_exigir_login(): array
{
    $u = usuario_atual();
    if ($u === null) {
        json_erro('Faça login para continuar.', 401);
    }
    return $u;
}

function api_exigir_acesso_app(): array
{
    $u = api_exigir_login();
    if (!pode_usar_app($u)) {
        json_erro(!empty($u['suspenso']) ? 'Seu acesso foi suspenso pela administração.' : 'Nenhum perfil seu foi aprovado ainda.', 403);
    }
    return $u;
}
