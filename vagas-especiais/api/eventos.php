<?php
/**
 * Registro de uso (anônimo, agregado por dia) para as estatísticas do painel.
 *   POST tipo=vaga_vista|rota|navegacao|chegada|waze|gmaps|busca|busca_sem_vaga
 *        vaga_id (eventos de vaga) · lat, lng, nome (busca_sem_vaga)
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';
require APP_ROOT . '/includes/estatisticas.php';

$u = api_exigir_acesso_app();
api_exigir_post();

$tipo = texto_post('tipo', 20);
if (!isset(TIPOS_EVENTO[$tipo])) {
    json_erro('Evento inválido.');
}

$vaga_id = (int) ($_POST['vaga_id'] ?? 0);
if (in_array($tipo, ['vaga_vista', 'rota', 'navegacao', 'chegada', 'waze', 'gmaps'], true)) {
    if (!vaga_visivel($vaga_id, $u)) {
        json_erro('Vaga não encontrada.', 404);
    }
} else {
    $vaga_id = 0;
}

if ($tipo === 'busca_sem_vaga') {
    $lat = (float) ($_POST['lat'] ?? 0);
    $lng = (float) ($_POST['lng'] ?? 0);
    $nome = texto_post('nome', 120);
    if (!dentro_de_niteroi($lat, $lng) || $nome === '') {
        json_erro('Destino inválido.');
    }
    registrar_demanda($lat, $lng, $nome);
}

contar_evento($tipo, $vaga_id);
json_resposta(['ok' => true]);
