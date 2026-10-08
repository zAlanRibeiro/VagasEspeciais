<?php
/**
 * Carregado no início de toda página/endpoint.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_NOME', 'Vagas Especiais Niterói');
define('STORAGE_DOCUMENTOS', APP_ROOT . '/storage/documentos');

date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');

require APP_ROOT . '/config/database.php';
require __DIR__ . '/security.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/dominio.php';
require __DIR__ . '/avisos.php';

enviar_cabecalhos_seguranca();
// O app se identifica por token; só o site usa sessão (cookie).
if (token_da_requisicao() === null) {
    iniciar_sessao();
}
