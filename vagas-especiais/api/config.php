<?php
/**
 * Configuração pública para o app: rótulos, links e versão mínima exigida.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';

$base = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
      . rtrim(str_replace(DIRECTORY_SEPARATOR, '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');

// Chave pública para o navegador assinar a inscrição de notificações (gerada na primeira vez).
try {
    $vapid = vapid_chaves()['publica'];
} catch (RuntimeException $e) {
    error_log('Push: ' . $e->getMessage());
    $vapid = null;
}

json_resposta([
    'ok'              => true,
    'categorias'      => CATEGORIAS,
    'tipos_relato'    => TIPOS_RELATO,
    'versao_minima'   => getenv('VAGAS_APP_VERSAO_MINIMA') ?: '1.0.0',
    'url_privacidade' => $base . '/public/privacidade.php',
    'doc_max_mb'      => DOC_MAX_BYTES / 1024 / 1024,
    'push'            => push_disponivel(),
    'vapid_publica'   => $vapid,
]);
