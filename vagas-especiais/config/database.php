<?php
/**
 * Configuração do banco de dados.
 *
 * Produção: MySQL/MariaDB (padrão). Ajuste os valores abaixo ou defina as
 * variáveis de ambiente VAGAS_DB_*.
 * Desenvolvimento local sem MySQL: defina VAGAS_DB_DRIVER=sqlite.
 */
declare(strict_types=1);

define('DB_DRIVER', getenv('VAGAS_DB_DRIVER') ?: 'mysql');          // mysql | sqlite
define('DB_HOST', getenv('VAGAS_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('VAGAS_DB_PORT') ?: 3306));
define('DB_NAME', getenv('VAGAS_DB_NAME') ?: 'vagas_especiais');
define('DB_USER', getenv('VAGAS_DB_USER') ?: 'root');
define('DB_PASS', getenv('VAGAS_DB_PASS') ?: '');
define('DB_SQLITE_PATH', getenv('VAGAS_DB_SQLITE') ?: dirname(__DIR__) . '/storage/database.sqlite');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $opcoes = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if (DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_SQLITE_PATH, null, null, $opcoes);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opcoes);
    }

    return $pdo;
}
