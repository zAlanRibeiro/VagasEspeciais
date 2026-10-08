<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/admin_auth.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $resposta = texto_post('resposta', 1000);

    $st = $pdo->prepare('SELECT r.vaga_id, r.usuario_id, r.status, r.resposta_admin, v.logradouro, v.numero
                         FROM relatos r JOIN vagas v ON v.id = r.vaga_id WHERE r.id = ?');
    $st->execute([$id]);
    $relato = $st->fetch();
    $vaga_id = $relato['vaga_id'] ?? null;

    if ($vaga_id && isset(STATUS_RELATO[$status])) {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE relatos SET status = ?, resposta_admin = ?, atualizado_em = ? WHERE id = ?')
            ->execute([$status, $resposta ?: null, agora(), $id]);
        if (!empty($_POST['inativar_vaga'])) {
            $pdo->prepare("UPDATE vagas SET status = 'inativa', atualizado_em = ? WHERE id = ?")->execute([agora(), $vaga_id]);
        }
        $pdo->commit();
        registrar_log('admin_relato', (int) $admin['id'], "relato $id → $status" . (!empty($_POST['inativar_vaga']) ? ', vaga inativada' : ''));
        // Avisa quem relatou quando há novidade (mudou o status ou chegou resposta).
        $mudou = $status !== $relato['status'] || ($resposta !== '' && $resposta !== (string) $relato['resposta_admin']);
        if ($relato['usuario_id'] && $mudou) {
            $local = $relato['logradouro'] . ($relato['numero'] ? ', ' . $relato['numero'] : '');
            avisar((int) $relato['usuario_id'], 'Seu relato foi atualizado',
                $local . ': ' . rotulo(STATUS_RELATO, $status) . ($resposta !== '' ? '. ' . $resposta : '.'));
        }
        flash('ok', 'Relato atualizado.');
    } else {
        flash('erro', 'Relato não encontrado.');
    }
    redirecionar('relatos.php' . qs([]));
}

$filtro = (string) ($_GET['status'] ?? 'abertos');
$where = match (true) {
    $filtro === 'abertos'          => "WHERE r.status IN ('aberto','em_analise')",
    isset(STATUS_RELATO[$filtro])  => 'WHERE r.status = ' . $pdo->quote($filtro),
    default                        => '',
};

$relatos = $pdo->query("SELECT r.*, v.logradouro, v.numero, v.bairro, v.categoria, v.status AS vaga_status, u.nome AS usuario
                        FROM relatos r
                        JOIN vagas v ON v.id = r.vaga_id
                        LEFT JOIN usuarios u ON u.id = r.usuario_id
                        $where ORDER BY r.id DESC LIMIT 200")->fetchAll();

admin_inicio('Relatos de problemas', 'relatos');
?>
<form class="filtros-admin" method="get">
  <div>
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="abertos"<?= $filtro === 'abertos' ? ' selected' : '' ?>>Em aberto</option>
      <?php foreach (STATUS_RELATO as $v => $r): ?>
        <option value="<?= e($v) ?>"<?= $filtro === $v ? ' selected' : '' ?>><?= e($r) ?></option>
      <?php endforeach; ?>
      <option value="todos"<?= $filtro === 'todos' ? ' selected' : '' ?>>Todos</option>
    </select>
  </div>
  <button class="botao" type="submit">Filtrar</button>
</form>

<section class="bloco">
  <?php if (!$relatos): ?>
    <p>Nenhum relato neste filtro.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Data</th><th>Vaga</th><th>Problema</th><th>Status</th><th>Tratamento</th></tr></thead>
    <tbody>
    <?php foreach ($relatos as $r): ?>
      <tr id="relato-<?= (int) $r['id'] ?>">
        <td><?= e(data_br($r['criado_em'])) ?><br><small><?= e($r['usuario'] ?? '—') ?></small></td>
        <td>
          <a href="vagas.php?editar=<?= (int) $r['vaga_id'] ?>"><?= e($r['logradouro'] . ($r['numero'] ? ', ' . $r['numero'] : '')) ?></a>
          <br><small><?= e($r['bairro']) ?> · <?= e($r['categoria']) ?></small>
          <?php if ($r['vaga_status'] === 'inativa'): ?><br><span class="selo selo-inativa">vaga inativa</span><?php endif; ?>
        </td>
        <td><strong><?= e(rotulo(TIPOS_RELATO, $r['tipo'])) ?></strong><?php if ($r['descricao']): ?><br><?= e($r['descricao']) ?><?php endif; ?></td>
        <td><span class="selo selo-<?= e($r['status']) ?>"><?= e(rotulo(STATUS_RELATO, $r['status'])) ?></span></td>
        <td>
          <form method="post" action="relatos.php<?= e(qs([])) ?>" class="form-relato-admin">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <label class="sr-only" for="st-<?= (int) $r['id'] ?>">Novo status</label>
            <select id="st-<?= (int) $r['id'] ?>" name="status">
              <?php foreach (STATUS_RELATO as $v => $rot): ?>
                <option value="<?= e($v) ?>"<?= $r['status'] === $v ? ' selected' : '' ?>><?= e($rot) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="sr-only" for="resp-<?= (int) $r['id'] ?>">Resposta ao usuário</label>
            <textarea id="resp-<?= (int) $r['id'] ?>" name="resposta" rows="2" maxlength="1000" placeholder="Resposta ao usuário (opcional)"><?= e((string) $r['resposta_admin']) ?></textarea>
            <?php if ($r['vaga_status'] === 'ativa'): ?>
              <label class="opcao"><input type="checkbox" name="inativar_vaga" value="1"> Inativar a vaga</label>
            <?php endif; ?>
            <button class="botao" type="submit">Salvar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php
admin_fim();
