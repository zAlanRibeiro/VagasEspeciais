<?php
/**
 * Incluído por todas as páginas de admin/. Bloqueia quem não é administrador.
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/layout.php';

$admin = usuario_atual();
if ($admin === null) {
    redirecionar('../public/login.php');
}
if (!is_admin($admin)) {
    http_response_code(403);
    registrar_log('admin_negado', (int) $admin['id'], $_SERVER['REQUEST_URI'] ?? null);
    exit('Acesso restrito à administração.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
}
