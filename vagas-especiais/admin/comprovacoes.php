<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/admin_auth.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? '';
    $observacao = texto_post('observacao', 500);

    $st = $pdo->prepare("SELECT * FROM comprovacoes WHERE id = ? AND status = 'em_analise'");
    $st->execute([$id]);
    $c = $st->fetch();

    if (!$c || !isset(PERFIS[(string) $c['perfil']])) {
        flash('erro', 'Comprovação não encontrada ou já analisada.');
    } elseif ($acao === 'recusar' && $observacao === '') {
        flash('erro', 'Informe o motivo da recusa — ele será exibido ao usuário.');
        redirecionar('comprovacoes.php?id=' . $id);
    } elseif (in_array($acao, ['aprovar', 'recusar'], true)) {
        $novo = $acao === 'aprovar' ? 'aprovado' : 'recusado';
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE comprovacoes SET status = ?, observacao = ?, analisado_por = ?, analisado_em = ? WHERE id = ?')
            ->execute([$novo, $observacao ?: null, $admin['id'], agora(), $id]);
        // A decisão vale para o perfil que o documento comprova (os outros perfis da pessoa não mudam).
        $pdo->prepare('UPDATE perfis SET status = ?, motivo_recusa = ?, atualizado_em = ? WHERE usuario_id = ? AND tipo = ?')
            ->execute([$novo, $novo === 'recusado' ? $observacao : null, agora(), $c['usuario_id'], $c['perfil']]);
        $pdo->commit();
        registrar_log('comprovacao_' . $novo, (int) $admin['id'], "comprovacao $id, usuario {$c['usuario_id']}, perfil {$c['perfil']}");
        $perfil = PERFIS[$c['perfil']];
        if ($novo === 'aprovado') {
            avisar((int) $c['usuario_id'], 'Perfil aprovado: ' . $perfil['nome'],
                'Agora você vê as ' . $perfil['libera'] . ' no mapa. Bom estacionamento!', 'mapa');
        } else {
            avisar((int) $c['usuario_id'], 'Comprovação recusada: ' . $perfil['nome'],
                'Motivo: ' . $observacao . '. Envie um novo documento pelo seu perfil.');
        }
        flash('ok', $novo === 'aprovado' ? 'Perfil ' . $perfil['nome'] . ' aprovado. A pessoa já vê essas vagas no mapa.' : 'Comprovação recusada.');
    }
    redirecionar('comprovacoes.php');
}

/* ---------- Análise de uma comprovação ---------- */

if (isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT c.*, u.nome, u.email, u.criado_em AS usuario_desde, a.nome AS analista
                         FROM comprovacoes c
                         JOIN usuarios u ON u.id = c.usuario_id
                         LEFT JOIN usuarios a ON a.id = c.analisado_por
                         WHERE c.id = ?');
    $st->execute([(int) $_GET['id']]);
    $c = $st->fetch();
    if (!$c) {
        flash('erro', 'Comprovação não encontrada.');
        redirecionar('comprovacoes.php');
    }
    $url = 'documento.php?id=' . (int) $c['id'];
    $outros = perfis_do_usuario((int) $c['usuario_id']);

    admin_inicio('Analisar comprovação', 'comprovacoes');
    ?>
    <p><a href="comprovacoes.php">← Voltar à lista</a></p>
    <div class="comprovacao">
      <section class="bloco">
        <?php if ($c['mime'] === 'application/pdf'): ?>
          <iframe class="visualizador" src="<?= e($url) ?>" title="Documento enviado"></iframe>
        <?php else: ?>
          <img class="visualizador" src="<?= e($url) ?>" alt="Documento enviado por <?= e($c['nome']) ?>">
        <?php endif; ?>
        <p><a href="<?= e($url) ?>" target="_blank" rel="noopener">Abrir em nova aba</a> · <?= e($c['nome_original']) ?> (<?= number_format($c['tamanho'] / 1024, 0, ',', '.') ?> KB)</p>
      </section>

      <section class="bloco">
        <h2><?= e($c['nome']) ?></h2>
        <dl class="dados">
          <dt>E-mail</dt><dd><?= e($c['email']) ?></dd>
          <dt>Perfil a comprovar</dt><dd><span class="selo selo-<?= strtolower(e((string) $c['perfil'])) ?>"><?= e(PERFIS[$c['perfil']]['nome'] ?? '—') ?></span></dd>
          <dt>Perfis da pessoa</dt>
          <dd class="selos-perfis">
            <?php foreach ($outros as $tipo => $p): ?>
              <span class="selo selo-<?= e($p['status']) ?>"><?= e(PERFIS[$tipo]['nome']) ?>: <?= e(mb_strtolower(status_perfil_texto($p))) ?></span>
            <?php endforeach; ?>
          </dd>
          <dt>Enviado em</dt><dd><?= e(data_br($c['criado_em'])) ?></dd>
          <dt>Status</dt><dd><span class="selo selo-<?= e($c['status']) ?>"><?= e(rotulo(STATUS_PERFIL, $c['status'])) ?></span></dd>
          <?php if ($c['analisado_em']): ?>
            <dt>Analisado</dt><dd><?= e(data_br($c['analisado_em'])) ?> por <?= e($c['analista'] ?? '—') ?></dd>
          <?php endif; ?>
          <?php if ($c['observacao']): ?>
            <dt>Observação</dt><dd><?= e($c['observacao']) ?></dd>
          <?php endif; ?>
        </dl>

        <?php if ($c['status'] === 'em_analise'): ?>
          <form method="post" class="form-admin">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <label for="observacao">Observação <span class="dica">(obrigatória para recusar; visível ao usuário)</span></label>
            <textarea id="observacao" name="observacao" rows="3" maxlength="500"></textarea>
            <div class="acoes-linha acoes-form">
              <button class="botao botao-primario" name="acao" value="aprovar">Aprovar</button>
              <button class="botao botao-perigo" name="acao" value="recusar">Recusar</button>
            </div>
          </form>
        <?php endif; ?>
      </section>
    </div>
    <?php
    admin_fim();
    exit;
}

/* ---------- Lista ---------- */

$status = $_GET['status'] ?? 'em_analise';
$params = [];
$where = '';
if (in_array($status, ['em_analise', 'aprovado', 'recusado'], true)) {
    $where = 'WHERE c.status = ?';
    $params[] = $status;
}
$st = $pdo->prepare("SELECT c.id, c.perfil, c.status, c.criado_em, c.analisado_em, u.nome, u.email
                     FROM comprovacoes c JOIN usuarios u ON u.id = c.usuario_id
                     $where ORDER BY c.id " . ($status === 'em_analise' ? 'ASC' : 'DESC') . ' LIMIT 200');
$st->execute($params);
$lista = $st->fetchAll();

admin_inicio('Comprovações', 'comprovacoes');
?>
<form class="filtros-admin" method="get">
  <div>
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="em_analise"<?= $status === 'em_analise' ? ' selected' : '' ?>>Aguardando análise</option>
      <option value="aprovado"<?= $status === 'aprovado' ? ' selected' : '' ?>>Aprovadas</option>
      <option value="recusado"<?= $status === 'recusado' ? ' selected' : '' ?>>Recusadas</option>
      <option value="todas"<?= $status === 'todas' ? ' selected' : '' ?>>Todas</option>
    </select>
  </div>
  <button class="botao" type="submit">Filtrar</button>
</form>

<section class="bloco">
  <?php if (!$lista): ?>
    <p>Nenhuma comprovação <?= $status === 'em_analise' ? 'aguardando análise' : 'encontrada' ?>.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Enviada em</th><th>Usuário</th><th>Perfil</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $c): ?>
      <tr>
        <td><?= e(data_br($c['criado_em'])) ?></td>
        <td><?= e($c['nome']) ?><br><small><?= e($c['email']) ?></small></td>
        <td><span class="selo selo-<?= strtolower(e((string) $c['perfil'])) ?>"><?= e(PERFIS[$c['perfil']]['nome'] ?? '—') ?></span></td>
        <td><span class="selo selo-<?= e($c['status']) ?>"><?= e(rotulo(STATUS_PERFIL, $c['status'])) ?></span></td>
        <td><a class="botao" href="comprovacoes.php?id=<?= (int) $c['id'] ?>"><?= $c['status'] === 'em_analise' ? 'Analisar' : 'Ver' ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php
admin_fim();
