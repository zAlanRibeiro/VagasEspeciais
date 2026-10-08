<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';
require APP_ROOT . '/includes/estatisticas.php';

$u = api_exigir_acesso_app();
registrar_uso((int) $u['id']);   // conta usuários ativos (só a data de uso)

// Só os tipos de vaga dos perfis aprovados da pessoa (PCD, idoso e/ou rotativo).
$categorias = categorias_visiveis($u);
$marcas = implode(',', array_fill(0, count($categorias), '?'));
$st = db()->prepare("SELECT id, logradouro, numero, bairro, categoria, latitude, longitude, atualizado_em
                     FROM vagas WHERE status = 'ativa' AND categoria IN ($marcas) ORDER BY bairro, logradouro");
$st->execute($categorias);
$linhas = $st->fetchAll();

$vagas = array_map(fn($v) => [
    'id'          => (int) $v['id'],
    'logradouro'  => $v['logradouro'],
    'numero'      => $v['numero'],
    'bairro'      => $v['bairro'],
    'categoria'   => $v['categoria'],
    'lat'         => (float) $v['latitude'],
    'lng'         => (float) $v['longitude'],
    'atualizado'  => data_br($v['atualizado_em'], false),
], $linhas);

$bairros = array_values(array_unique(array_column($vagas, 'bairro')));
sort($bairros, SORT_LOCALE_STRING);

json_resposta(['ok' => true, 'vagas' => $vagas, 'bairros' => $bairros, 'categorias' => $categorias]);
