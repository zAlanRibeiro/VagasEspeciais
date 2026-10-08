<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$u = usuario_atual();
if ($u !== null) {
    registrar_log('logout', (int) $u['id']);
}
encerrar_sessao();
// Limpa dados em cache do navegador (vagas guardadas pelo service worker).
header('Clear-Site-Data: "cache", "storage"');
redirecionar('login.php');
