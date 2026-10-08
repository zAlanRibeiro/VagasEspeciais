<?php
/**
 * Carregado por todos os endpoints de api/: bootstrap + CORS para o app móvel.
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// JSON comprimido (gzip) para quem aceita: a lista de vagas cai ~80% (dados móveis). Independe do mod_deflate.
ini_set('zlib.output_compression', '1');

cors_api();
