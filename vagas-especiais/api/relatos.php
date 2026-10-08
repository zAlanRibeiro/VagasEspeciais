<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';

$u = api_exigir_acesso_app();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = db()->prepare('SELECT r.id, r.tipo, r.descricao, r.status, r.resposta_admin, r.criado_em,
                                v.logradouro, v.numero, v.bairro
                         FROM relatos r JOIN vagas v ON v.id = r.vaga_id
                         WHERE r.usuario_id = ? ORDER BY r.id DESC');
    $st->execute([$u['id']]);
    json_resposta(['ok' => true, 'relatos' => $st->fetchAll()]);
}

api_exigir_post();

$vaga_id   = (int) ($_POST['vaga_id'] ?? 0);
$tipo      = texto_post('tipo', 40);
$descricao = texto_post('descricao', 1000);

if (!isset(TIPOS_RELATO[$tipo])) {
    json_erro('Selecione o tipo de problema.');
}
if ($tipo === 'outro' && $descricao === '') {
    json_erro('Descreva o problema.');
}

if (!vaga_visivel($vaga_id, $u)) {
    json_erro('Vaga não encontrada.', 404);
}

$st = db()->prepare("SELECT 1 FROM relatos WHERE vaga_id = ? AND usuario_id = ? AND tipo = ? AND status IN ('aberto','em_analise')");
$st->execute([$vaga_id, $u['id'], $tipo]);
if ($st->fetchColumn()) {
    json_erro('Você já relatou este problema para esta vaga. Ele está em análise.');
}

db()->prepare("INSERT INTO relatos (vaga_id, usuario_id, tipo, descricao, status, criado_em, atualizado_em)
               VALUES (?, ?, ?, ?, 'aberto', ?, ?)")
    ->execute([$vaga_id, $u['id'], $tipo, $descricao ?: null, agora(), agora()]);

registrar_log('relato_enviado', (int) $u['id'], "vaga $vaga_id");
json_resposta(['ok' => true, 'mensagem' => 'Obrigado! Seu relato foi enviado para análise.']);
