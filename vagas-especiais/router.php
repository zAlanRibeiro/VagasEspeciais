<?php
/**
 * Roteador para o servidor embutido do PHP (somente desenvolvimento):
 *   php -S localhost:8000 router.php
 * Reproduz o bloqueio das pastas internas feito pelo .htaccess no Apache.
 */
$caminho = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if (preg_match('#^/(config|includes|database|storage)(/|$)#', $caminho) || preg_match('#/\.#', $caminho)) {
    http_response_code(403);
    exit('Acesso negado.');
}
if (str_ends_with($caminho, '.webmanifest')) {
    header('Content-Type: application/manifest+json');
    readfile(__DIR__ . $caminho);
    return true;
}
return false;
