<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/admin_auth.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'alternar_status' && $id) {
        $pdo->prepare("UPDATE vagas SET status = CASE WHEN status = 'ativa' THEN 'inativa' ELSE 'ativa' END, atualizado_em = ? WHERE id = ?")
            ->execute([agora(), $id]);
        registrar_log('admin_vaga_status', (int) $admin['id'], "vaga $id");
        flash('ok', 'Status da vaga alterado.');
        $voltar = (string) ($_POST['voltar'] ?? '');
        redirecionar(str_starts_with($voltar, 'vagas.php') ? $voltar : 'vagas.php');
    }

    if ($acao === 'salvar') {
        [$vaga, $erros] = validar_vaga($_POST);
        if ($vaga['codigo'] !== null) {
            $st = $pdo->prepare('SELECT id FROM vagas WHERE codigo = ? AND id <> ?');
            $st->execute([$vaga['codigo'], $id]);
            if ($st->fetchColumn()) {
                $erros[] = 'já existe outra vaga com este ID da base';
            }
        }
        if ($erros) {
            $_SESSION['form_vaga'] = $_POST;
            flash('erro', 'Corrija: ' . implode('; ', $erros) . '.');
            redirecionar('vagas.php?' . ($id ? 'editar=' . $id : 'nova=1'));
        }

        $dados = [$vaga['codigo'], $vaga['logradouro'], $vaga['numero'], $vaga['bairro'], $vaga['categoria'],
                  $vaga['latitude'], $vaga['longitude'], $vaga['status'], agora()];
        if ($id) {
            $pdo->prepare('UPDATE vagas SET codigo = ?, logradouro = ?, numero = ?, bairro = ?, categoria = ?, latitude = ?,
                           longitude = ?, status = ?, atualizado_em = ? WHERE id = ?')
                ->execute(array_merge($dados, [$id]));
        } else {
            $pdo->prepare('INSERT INTO vagas (codigo, logradouro, numero, bairro, categoria, latitude, longitude, status, atualizado_em)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($dados);
            $id = (int) $pdo->lastInsertId();
        }
        registrar_log('admin_vaga_salva', (int) $admin['id'], "vaga $id");
        flash('ok', 'Vaga salva.');
        redirecionar('vagas.php?editar=' . $id);
    }
    redirecionar('vagas.php');
}

/* ---------- Formulário (nova / editar) ---------- */

if (isset($_GET['editar']) || isset($_GET['nova'])) {
    $id = (int) ($_GET['editar'] ?? 0);
    $v = ['id' => 0, 'codigo' => '', 'logradouro' => '', 'numero' => '', 'bairro' => '', 'categoria' => 'PCD',
          'latitude' => '', 'longitude' => '', 'status' => 'ativa', 'atualizado_em' => null];
    $relatos = [];
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM vagas WHERE id = ?');
        $st->execute([$id]);
        $v = $st->fetch() ?: redirecionar('vagas.php');
        $st = $pdo->prepare('SELECT * FROM relatos WHERE vaga_id = ? ORDER BY id DESC');
        $st->execute([$id]);
        $relatos = $st->fetchAll();
    }
    // Reapresenta o que foi digitado quando a validação falhou.
    if (!empty($_SESSION['form_vaga'])) {
        $v = array_merge($v, array_intersect_key($_SESSION['form_vaga'], $v));
        unset($_SESSION['form_vaga']);
    }

    admin_inicio($id ? 'Editar vaga' : 'Nova vaga', 'vagas', ['css' => [LEAFLET_CSS]]);
    ?>
    <p><a href="vagas.php">← Voltar à lista</a></p>
    <section class="bloco">
      <form method="post" class="form-admin">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
        <div class="form-grade">
          <div><label for="codigo">ID na base Nittrans</label><input id="codigo" name="codigo" value="<?= e((string) $v['codigo']) ?>" maxlength="50"></div>
          <div>
            <label for="categoria">Categoria</label>
            <select id="categoria" name="categoria">
              <?php foreach (CATEGORIAS as $c => $r): ?>
                <option value="<?= e($c) ?>"<?= $v['categoria'] === $c ? ' selected' : '' ?>><?= e($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label for="logradouro">Logradouro</label><input id="logradouro" name="logradouro" value="<?= e($v['logradouro']) ?>" required maxlength="200"></div>
          <div><label for="numero">Número / referência</label><input id="numero" name="numero" value="<?= e((string) $v['numero']) ?>" maxlength="30"></div>
          <div><label for="bairro">Bairro</label><input id="bairro" name="bairro" value="<?= e($v['bairro']) ?>" required maxlength="100"></div>
          <div>
            <label for="status">Status</label>
            <select id="status" name="status">
              <option value="ativa"<?= $v['status'] === 'ativa' ? ' selected' : '' ?>>Ativa</option>
              <option value="inativa"<?= $v['status'] === 'inativa' ? ' selected' : '' ?>>Inativa</option>
            </select>
          </div>
          <div><label for="latitude">Latitude</label><input id="latitude" name="latitude" value="<?= e((string) $v['latitude']) ?>" required inputmode="decimal"></div>
          <div><label for="longitude">Longitude</label><input id="longitude" name="longitude" value="<?= e((string) $v['longitude']) ?>" required inputmode="decimal"></div>
          <div class="inteiro">
            <p class="dica">Clique no mapa ou arraste o marcador para ajustar a posição.</p>
            <div id="mapa-edicao"></div>
          </div>
        </div>
        <p class="dica">Última atualização: <?= e(data_br($v['atualizado_em'])) ?></p>
        <button class="botao botao-primario" type="submit">Salvar vaga</button>
      </form>
    </section>

    <?php if ($relatos): ?>
    <section class="bloco">
      <h2>Relatos desta vaga</h2>
      <table>
        <thead><tr><th>Data</th><th>Tipo</th><th>Descrição</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($relatos as $r): ?>
          <tr>
            <td><?= e(data_br($r['criado_em'])) ?></td>
            <td><?= e(rotulo(TIPOS_RELATO, $r['tipo'])) ?></td>
            <td><?= e((string) $r['descricao']) ?></td>
            <td><a href="relatos.php?status=todos#relato-<?= (int) $r['id'] ?>"><span class="selo selo-<?= e($r['status']) ?>"><?= e(rotulo(STATUS_RELATO, $r['status'])) ?></span></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <?php endif;
    admin_fim([LEAFLET_JS, '../assets/js/admin.js']);
    exit;
}

/* ---------- Lista ---------- */

$f = [
    'q'         => trim((string) ($_GET['q'] ?? '')),
    'bairro'    => (string) ($_GET['bairro'] ?? ''),
    'categoria' => (string) ($_GET['categoria'] ?? ''),
    'status'    => (string) ($_GET['status'] ?? ''),
];
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 50;

$where = [];
$params = [];
if ($f['q'] !== '') {
    $where[] = '(logradouro LIKE ? OR codigo = ?)';
    $params[] = '%' . $f['q'] . '%';
    $params[] = $f['q'];
}
if ($f['bairro'] !== '') {
    $where[] = 'bairro = ?';
    $params[] = $f['bairro'];
}
if (isset(CATEGORIAS[$f['categoria']])) {
    $where[] = 'categoria = ?';
    $params[] = $f['categoria'];
}
if (in_array($f['status'], ['ativa', 'inativa'], true)) {
    $where[] = 'status = ?';
    $params[] = $f['status'];
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM vagas $sqlWhere");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("SELECT v.*, (SELECT COUNT(*) FROM relatos r WHERE r.vaga_id = v.id AND r.status IN ('aberto','em_analise')) AS relatos_abertos
                     FROM vagas v $sqlWhere ORDER BY bairro, logradouro, numero LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina));
$st->execute($params);
$vagas = $st->fetchAll();

$bairros = $pdo->query('SELECT DISTINCT bairro FROM vagas ORDER BY bairro')->fetchAll(PDO::FETCH_COLUMN);
$voltar = 'vagas.php' . qs([]);

admin_inicio('Vagas', 'vagas');
?>
<form class="filtros-admin" method="get">
  <div><label for="q">Logradouro ou ID</label><input id="q" name="q" value="<?= e($f['q']) ?>"></div>
  <div>
    <label for="bairro">Bairro</label>
    <select id="bairro" name="bairro">
      <option value="">Todos</option>
      <?php foreach ($bairros as $b): ?>
        <option value="<?= e($b) ?>"<?= $f['bairro'] === $b ? ' selected' : '' ?>><?= e($b) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="categoria">Categoria</label>
    <select id="categoria" name="categoria">
      <option value="">Todas</option>
      <?php foreach (CATEGORIAS as $c => $r): ?>
        <option value="<?= e($c) ?>"<?= $f['categoria'] === $c ? ' selected' : '' ?>><?= e($c) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">Todos</option>
      <option value="ativa"<?= $f['status'] === 'ativa' ? ' selected' : '' ?>>Ativas</option>
      <option value="inativa"<?= $f['status'] === 'inativa' ? ' selected' : '' ?>>Inativas</option>
    </select>
  </div>
  <button class="botao" type="submit">Filtrar</button>
  <a class="botao botao-primario" href="vagas.php?nova=1">+ Nova vaga</a>
</form>

<section class="bloco">
  <p><?= $total ?> vaga(s)</p>
  <table>
    <thead><tr><th>ID base</th><th>Endereço</th><th>Bairro</th><th>Categoria</th><th>Coordenadas</th><th>Status</th><th>Atualização</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($vagas as $v): ?>
      <tr>
        <td><?= e((string) $v['codigo']) ?></td>
        <td>
          <?= e($v['logradouro'] . ($v['numero'] ? ', ' . $v['numero'] : '')) ?>
          <?php if ($v['relatos_abertos']): ?><br><a class="selo selo-aberto" href="relatos.php"><?= (int) $v['relatos_abertos'] ?> relato(s)</a><?php endif; ?>
        </td>
        <td><?= e($v['bairro']) ?></td>
        <td><span class="selo selo-<?= strtolower(e($v['categoria'])) ?>"><?= e($v['categoria']) ?></span></td>
        <td><small><?= e(number_format((float) $v['latitude'], 6, '.', '')) ?>, <?= e(number_format((float) $v['longitude'], 6, '.', '')) ?></small></td>
        <td><span class="selo selo-<?= e($v['status']) ?>"><?= e($v['status']) ?></span></td>
        <td><?= e(data_br($v['atualizado_em'], false)) ?></td>
        <td>
          <div class="acoes-linha">
            <a class="botao" href="vagas.php?editar=<?= (int) $v['id'] ?>">Editar</a>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="acao" value="alternar_status">
              <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
              <input type="hidden" name="voltar" value="<?= e($voltar) ?>">
              <button class="botao" type="submit"><?= $v['status'] === 'ativa' ? 'Inativar' : 'Ativar' ?></button>
            </form>
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
