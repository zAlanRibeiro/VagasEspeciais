<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/api.php';

// Site: cria sessão (exige CSRF). App (modo=app): devolve um token, sem sessão.
$modo_app = ($_POST['modo'] ?? '') === 'app';
api_exigir_post(!$modo_app);

$nome      = texto_post('nome', 150);
$email     = mb_strtolower(texto_post('email', 190));
$senha     = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
$confirmar = is_string($_POST['confirmar'] ?? null) ? $_POST['confirmar'] : '';
// Perfis escolhidos (um ou mais). Versões antigas do app mandam uma "categoria" e um "documento".
$perfis = array_values(array_intersect(array_keys(PERFIS), array_map('strval', (array) ($_POST['perfis'] ?? []))));
$documentos = [];
foreach (PERFIS_COM_COMPROVACAO as $tipo) {
    $documentos[$tipo] = $_FILES['documento_' . $tipo] ?? null;
}
$antiga = texto_post('categoria', 10);
if (!$perfis && in_array($antiga, PERFIS_COM_COMPROVACAO, true)) {
    $perfis = [$antiga];
    $documentos[$antiga] = $_FILES['documento'] ?? null;
}

if (mb_strlen($nome) < 3) {
    json_erro('Informe seu nome completo.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_erro('Informe um e-mail válido.');
}
if (mb_strlen($senha) < 8) {
    json_erro('A senha deve ter pelo menos 8 caracteres.');
}
if ($senha !== $confirmar) {
    json_erro('As senhas não conferem.');
}
// O rotativo é de todos: entra sempre, já aprovado. PCD e idoso são opcionais.
$perfis = array_values(array_unique([...$perfis, 'ROTATIVO']));
// PCD e idoso: cada perfil precisa da sua comprovação já no cadastro (tudo conferido antes de criar a conta).
foreach (array_intersect($perfis, PERFIS_COM_COMPROVACAO) as $tipo) {
    $erro = validar_documento($documentos[$tipo], $tipo);
    if ($erro !== null) {
        json_erro($erro);
    }
}
if (empty($_POST['aceite'])) {
    json_erro('É necessário aceitar a política de privacidade e os termos de uso.');
}

$st = db()->prepare('SELECT 1 FROM usuarios WHERE email = ?');
$st->execute([$email]);
if ($st->fetchColumn()) {
    json_erro('Já existe uma conta com este e-mail.');
}

$pdo = db();
$pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, papel, aceite_termos_em, criado_em, atualizado_em)
               VALUES (?, ?, ?, 'usuario', ?, ?, ?)")
    ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), agora(), agora(), agora()]);
$id = (int) $pdo->lastInsertId();

foreach ($perfis as $tipo) {
    if ($tipo === 'ROTATIVO') {
        adicionar_perfil_rotativo($id);
        continue;
    }
    $erro = salvar_comprovacao($id, $tipo, $documentos[$tipo]);
    if ($erro !== null) {
        // Sem todas as comprovações gravadas o cadastro não é concluído.
        excluir_conta($id);
        json_erro($erro);
    }
}

registrar_log('cadastro', $id, implode(',', $perfis));

if ($modo_app) {
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    json_resposta(['ok' => true, 'token' => emitir_token($id, texto_post('dispositivo', 100)), 'usuario' => usuario_publico($st->fetch())]);
}

autenticar(['id' => $id]);
// Só rotativo: já pode usar o mapa. Com PCD/idoso, o perfil mostra que a comprovação está em análise.
json_resposta(['ok' => true, 'redirect' => $perfis === ['ROTATIVO'] ? 'mapa.php' : 'perfil.php']);
