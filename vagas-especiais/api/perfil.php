<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';

$u = api_exigir_login();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = db()->prepare('SELECT id, perfil, nome_original, status, observacao, criado_em, analisado_em
                         FROM comprovacoes WHERE usuario_id = ? ORDER BY id DESC');
    $st->execute([$u['id']]);
    $comprovacoes = $st->fetchAll();

    $st = db()->prepare('SELECT r.tipo, r.status, r.resposta_admin, r.criado_em, v.logradouro, v.numero, v.bairro
                         FROM relatos r JOIN vagas v ON v.id = r.vaga_id
                         WHERE r.usuario_id = ? ORDER BY r.id DESC LIMIT 20');
    $st->execute([$u['id']]);
    $relatos = array_map(fn($r) => $r + [
        'tipo_texto'   => rotulo(TIPOS_RELATO, $r['tipo']),
        'status_texto' => rotulo(STATUS_RELATO, $r['status']),
        'data'         => data_br($r['criado_em']),
    ], $st->fetchAll());

    json_resposta([
        'ok'           => true,
        'usuario'      => usuario_publico($u),
        'comprovacoes' => array_map(fn($c) => $c + [
            'status_texto' => rotulo(STATUS_PERFIL, $c['status']),
            'perfil_texto' => PERFIS[$c['perfil']]['nome'] ?? '',
            'data'         => data_br($c['criado_em']),
        ], $comprovacoes),
        'relatos'      => $relatos,
    ]);
}

// POST: comprovação de um perfil PCD/idoso (novo, sem documento ou recusado). O rotativo todos já têm.
api_exigir_post();

if (!empty($u['suspenso'])) {
    json_erro('Seu acesso foi suspenso pela administração.');
}

$perfil = texto_post('perfil', 10);
if ($perfil === '') {
    // Versões antigas do app não dizem o perfil: vale o único que aguarda documento.
    $aguardando = array_keys(array_filter($u['perfis'], fn($p) => in_array($p['tipo'], PERFIS_COM_COMPROVACAO, true) && perfil_aceita_documento($p)));
    $perfil = count($aguardando) === 1 ? $aguardando[0] : '';
}
if (!in_array($perfil, PERFIS_COM_COMPROVACAO, true)) {
    json_erro('Escolha o perfil que o documento comprova.');
}
if (!perfil_aceita_documento($u['perfis'][$perfil] ?? null)) {
    json_erro('A comprovação deste perfil já está em análise ou aprovada.');
}

$erro = salvar_comprovacao((int) $u['id'], $perfil, $_FILES['documento'] ?? null);
if ($erro !== null) {
    json_erro($erro);
}
json_resposta(['ok' => true, 'redirect' => 'perfil.php', 'mensagem' => 'Comprovação enviada. Aguarde a análise.']);
