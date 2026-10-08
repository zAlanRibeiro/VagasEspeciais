<?php
/**
 * Entrega um documento de comprovação. Os arquivos ficam fora da área pública
 * (storage/documentos) e só saem por aqui, para administradores, com registro.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/admin_auth.php';

$st = db()->prepare('SELECT id, usuario_id, arquivo, mime FROM comprovacoes WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$c = $st->fetch();

$caminho = $c ? STORAGE_DOCUMENTOS . '/' . basename($c['arquivo']) : '';
if (!$c || !is_file($caminho)) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

registrar_log('documento_visualizado', (int) $admin['id'], "comprovacao {$c['id']}, usuario {$c['usuario_id']}");

// Permite exibir dentro do próprio painel (iframe), mas nunca em outros sites.
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
header('Content-Type: ' . $c['mime']);
header('Content-Length: ' . filesize($caminho));
header('Content-Disposition: inline; filename="comprovacao-' . (int) $c['id'] . '.' . pathinfo($c['arquivo'], PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store');
readfile($caminho);
