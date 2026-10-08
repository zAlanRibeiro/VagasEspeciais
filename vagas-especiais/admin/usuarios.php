<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/admin_auth.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? '';
    $tipo = (string) ($_POST['perfil'] ?? '');

    if ($id === (int) $admin['id']) {
        flash('erro', 'Você não pode alterar a própria conta por aqui.');
        redirecionar('usuarios.php' . qs([]));
    }

    if ($acao === 'excluir') {
        excluir_conta($id);
        registrar_log('admin_usuario_excluir', (int) $admin['id'], "usuario $id");
        flash('ok', 'Conta excluída, com documentos e dados pessoais.');
        redirecionar('usuarios.php' . qs([]));
    }

    // Revogar/liberar vale para um perfil PCD ou idoso; suspender vale para a conta inteira (inclusive o rotativo).
    if (in_array($acao, ['revogar_perfil', 'liberar_perfil'], true) && in_array($tipo, PERFIS_COM_COMPROVACAO, true)) {
        $revogar = $acao === 'revogar_perfil';
        $st = $pdo->prepare('UPDATE perfis SET status = ?, motivo_recusa = ?, atualizado_em = ? WHERE usuario_id = ? AND tipo = ?');
        $st->execute([$revogar ? 'recusado' : 'aprovado', $revogar ? 'Perfil revogado pela administração.' : null, agora(), $id, $tipo]);
        if ($st->rowCount()) {
            registrar_log('admin_' . $acao, (int) $admin['id'], "usuario $id, perfil $tipo");
            $perfil = PERFIS[$tipo];
            if ($revogar) {
                avisar($id, 'Perfil revogado: ' . $perfil['nome'], 'Você deixou de ver as ' . $perfil['libera'] . '. Em caso de dúvida, procure a Nittrans.');
            } else {
                avisar($id, 'Perfil liberado: ' . $perfil['nome'], 'Agora você vê as ' . $perfil['libera'] . ' no mapa.', 'mapa');
            }
            flash('ok', 'Perfil ' . $perfil['nome'] . ($revogar ? ' revogado.' : ' liberado.'));
        }
        redirecionar('usuarios.php' . qs([]));
    }

    $sql = match ($acao) {
        'tornar_admin'  => "UPDATE usuarios SET papel = 'admin', atualizado_em = ? WHERE id = ?",
        'remover_admin' => "UPDATE usuarios SET papel = 'usuario', atualizado_em = ? WHERE id = ?",
        'suspender'     => 'UPDATE usuarios SET suspenso = 1, atualizado_em = ? WHERE id = ?',
        'reativar'      => 'UPDATE usuarios SET suspenso = 0, atualizado_em = ? WHERE id = ?',
        default         => null,
    };
    if ($sql !== null) {
        $pdo->prepare($sql)->execute([agora(), $id]);
        registrar_log('admin_usuario_' . $acao, (int) $admin['id'], "usuario $id");
        if ($acao === 'suspender') {
            avisar($id, 'Acesso suspenso', 'Seu acesso ao mapa foi suspenso pela administração. Em caso de dúvida, procure a Nittrans.');
        } elseif ($acao === 'reativar') {
            avisar($id, 'Acesso liberado', 'Seu acesso ao mapa de vagas foi liberado novamente.', 'mapa');
        }
        flash('ok', 'Usuário atualizado.');
    }
    redirecionar('usuarios.php' . qs([]));
}

$SITUACOES = [
    'em_analise' => 'Com perfil em análise',
    'pendente'   => 'Com perfil sem comprovação',
    'recusado'   => 'Com perfil recusado',
    'suspenso'   => 'Conta suspensa',
    'admin'      => 'Administradores',
];
$situacao = (string) ($_GET['status'] ?? '');
$tipoFiltro = (string) ($_GET['perfil'] ?? '');
$busca = trim((string) ($_GET['q'] ?? ''));
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 30;

$where = [];
$params = [];
if (isset(STATUS_PERFIL[$situacao])) {
    $where[] = 'EXISTS (SELECT 1 FROM perfis p WHERE p.usuario_id = u.id AND p.status = ?)';
    $params[] = $situacao;
} elseif ($situacao === 'suspenso') {
    $where[] = 'u.suspenso = 1';
} elseif ($situacao === 'admin') {
    $where[] = "u.papel = 'admin'";
}
if (isset(PERFIS[$tipoFiltro])) {
    $where[] = 'EXISTS (SELECT 1 FROM perfis p WHERE p.usuario_id = u.id AND p.tipo = ?)';
    $params[] = $tipoFiltro;
}
if ($busca !== '') {
    $where[] = '(u.nome LIKE ? OR u.email LIKE ?)';
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM usuarios u $sqlWhere");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("SELECT u.*, (SELECT MAX(c.id) FROM comprovacoes c WHERE c.usuario_id = u.id AND c.status = 'em_analise') AS comp_pendente
                     FROM usuarios u $sqlWhere ORDER BY u.id DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina));
$st->execute($params);
$usuarios = $st->fetchAll();

// Perfis de todos os usuários da página numa consulta só
$perfisDe = [];
if ($usuarios) {
    $ids = array_column($usuarios, 'id');
    $st = $pdo->prepare('SELECT usuario_id, tipo, status, motivo_recusa FROM perfis WHERE usuario_id IN ('
                        . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    foreach ($st->fetchAll() as $p) {
        $perfisDe[(int) $p['usuario_id']][$p['tipo']] = $p;
    }
    // Mesma ordem em todas as linhas: PCD, idoso, rotativo
    foreach ($perfisDe as $uid => $lista) {
        $perfisDe[$uid] = array_merge(array_intersect_key(array_flip(array_keys(PERFIS)), $lista), $lista);
    }
}

admin_inicio('Usuários', 'usuarios');
?>
<form class="filtros-admin" method="get">
  <div>
    <label for="q">Buscar</label>
    <input id="q" name="q" value="<?= e($busca) ?>" placeholder="Nome ou e-mail">
  </div>
  <div>
    <label for="perfil">Perfil</label>
    <select id="perfil" name="perfil">
      <option value="">Todos</option>
      <?php foreach (PERFIS as $v => $p): ?>
        <option value="<?= e($v) ?>"<?= $tipoFiltro === $v ? ' selected' : '' ?>><?= e($p['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="status">Situação</label>
    <select id="status" name="status">
      <option value="">Todas</option>
      <?php foreach ($SITUACOES as $v => $r): ?>
        <option value="<?= e($v) ?>"<?= $situacao === $v ? ' selected' : '' ?>><?= e($r) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="botao" type="submit">Filtrar</button>
</form>

<section class="bloco">
  <p><?= $total ?> usuário(s)</p>
  <table>
    <thead><tr><th>Nome</th><th>E-mail</th><th>Perfis</th><th>Cadastro</th><th>Ações</th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $u): $proprio = (int) $u['id'] === (int) $admin['id']; ?>
      <tr>
        <td>
          <?= e($u['nome']) ?>
          <?php if ($u['papel'] === 'admin'): ?><span class="selo selo-admin">admin</span><?php endif; ?>
          <?php if ($u['suspenso']): ?><span class="selo selo-recusado">suspensa</span><?php endif; ?>
        </td>
        <td><?= e($u['email']) ?></td>
        <td>
          <ul class="perfis-admin">
            <?php foreach ($perfisDe[(int) $u['id']] ?? [] as $tipo => $p): ?>
              <li>
                <span class="selo selo-<?= e($p['status']) ?>" title="<?= e((string) $p['motivo_recusa']) ?>"><?= e(PERFIS[$tipo]['nome']) ?>: <?= e(mb_strtolower(status_perfil_texto($p))) ?></span>
                <?php if (!$proprio && in_array($tipo, PERFIS_COM_COMPROVACAO, true) && in_array($p['status'], ['aprovado', 'recusado'], true)): ?>
                  <form method="post" action="usuarios.php<?= e(qs([])) ?>">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="perfil" value="<?= e($tipo) ?>">
                    <?php if ($p['status'] === 'aprovado'): ?>
                      <button class="link-botao" name="acao" value="revogar_perfil" data-confirmar="Revogar o perfil <?= e(PERFIS[$tipo]['nome']) ?> de <?= e($u['nome']) ?>? A pessoa deixa de ver essas vagas.">revogar</button>
                    <?php else: ?>
                      <button class="link-botao" name="acao" value="liberar_perfil" data-confirmar="Liberar o perfil <?= e(PERFIS[$tipo]['nome']) ?> de <?= e($u['nome']) ?> sem nova análise?">liberar</button>
                    <?php endif; ?>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
            <?php if (empty($perfisDe[(int) $u['id']])): ?><li class="dica"><?= $u['papel'] === 'admin' ? 'vê todas as vagas' : '—' ?></li><?php endif; ?>
          </ul>
        </td>
        <td><?= e(data_br($u['criado_em'], false)) ?></td>
        <td>
          <div class="acoes-linha">
          <?php if ($u['comp_pendente']): ?>
            <a class="botao botao-primario" href="comprovacoes.php?id=<?= (int) $u['comp_pendente'] ?>">Analisar</a>
          <?php endif; ?>
          <?php if (!$proprio): ?>
            <form method="post" action="usuarios.php<?= e(qs([])) ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <?php if ($u['suspenso']): ?>
                <button class="botao" name="acao" value="reativar" data-confirmar="Reativar a conta de <?= e($u['nome']) ?>?">Reativar conta</button>
              <?php else: ?>
                <button class="botao" name="acao" value="suspender" data-confirmar="Suspender a conta de <?= e($u['nome']) ?>? A pessoa perde o acesso ao mapa, inclusive ao rotativo.">Suspender</button>
              <?php endif; ?>
              <?php if ($u['papel'] === 'admin'): ?>
                <button class="botao" name="acao" value="remover_admin" data-confirmar="Remover permissão de administrador?">Remover admin</button>
              <?php else: ?>
                <button class="botao" name="acao" value="tornar_admin" data-confirmar="Dar permissão de administrador a <?= e($u['nome']) ?>?">Tornar admin</button>
              <?php endif; ?>
              <button class="botao" name="acao" value="excluir" data-confirmar="Excluir DEFINITIVAMENTE a conta e os documentos de <?= e($u['nome']) ?>? Use para pedidos de exclusão (LGPD).">Excluir</button>
            </form>
          <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php paginacao($pagina, $total, $porPagina); ?>
</section>
<?php
admin_fim(['../assets/js/admin.js']);
