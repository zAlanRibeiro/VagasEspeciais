<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/admin_auth.php';
require APP_ROOT . '/includes/estatisticas.php';
require APP_ROOT . '/includes/graficos.php';

const PERIODOS = [7 => '7 dias', 30 => '30 dias', 90 => '90 dias'];
$dias = (int) ($_GET['periodo'] ?? 30);
if (!isset(PERIODOS[$dias])) {
    $dias = 30;
}

$pdo = db();
$contar = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
$num = fn($n) => number_format((int) $n, 0, ',', '.');

/* ---------- Uso do aplicativo (período escolhido) ---------- */
$uso = totais_eventos($dias);
$ativos = usuarios_ativos($dias);
$conclusao = $uso['navegacao'] ? min(100, (int) round(100 * $uso['chegada'] / $uso['navegacao'])) : null;
$serie = serie_diaria($dias, ['vaga_vista', 'rota', 'chegada']);
$topVagas = vagas_mais_procuradas($dias);
$procuraBairro = procura_por_bairro($dias);
$maxBairro = max(1, ...array_map(fn($b) => (int) $b['rotas'] + (int) $b['vistas'], $procuraBairro ?: [['rotas' => 0, 'vistas' => 0]]));
$demanda = demanda_sem_vaga($dias);

/* ---------- Cadastros e base (situação atual) ---------- */
$tempoAnalise = tempo_medio_analise($dias);
$novosCadastros = cadastros_no_periodo($dias);
$ind = [
    'vagas_ativas'    => $contar("SELECT COUNT(*) FROM vagas WHERE status = 'ativa'"),
    'vagas_pcd'       => $contar("SELECT COUNT(*) FROM vagas WHERE status = 'ativa' AND categoria = 'PCD'"),
    'vagas_idoso'     => $contar("SELECT COUNT(*) FROM vagas WHERE status = 'ativa' AND categoria = 'IDOSO'"),
    'vagas_rotativo'  => $contar("SELECT COUNT(*) FROM vagas WHERE status = 'ativa' AND categoria = 'ROTATIVO'"),
    'vagas_inativas'  => $contar("SELECT COUNT(*) FROM vagas WHERE status = 'inativa'"),
    'usuarios_aprov'  => $contar("SELECT COUNT(*) FROM usuarios u WHERE u.papel = 'usuario' AND u.suspenso = 0
                                  AND EXISTS (SELECT 1 FROM perfis p WHERE p.usuario_id = u.id AND p.status = 'aprovado')"),
    'comp_pendentes'  => $contar("SELECT COUNT(*) FROM comprovacoes WHERE status = 'em_analise'"),
    'relatos_abertos' => $contar("SELECT COUNT(*) FROM relatos WHERE status IN ('aberto','em_analise')"),
];
$perfisAprovados = array_column($pdo->query("SELECT tipo, COUNT(*) AS total FROM perfis p JOIN usuarios u ON u.id = p.usuario_id
                                             WHERE p.status = 'aprovado' AND u.suspenso = 0 GROUP BY tipo")->fetchAll(), 'total', 'tipo');
$porBairro = $pdo->query("SELECT bairro,
                                 SUM(CASE WHEN categoria = 'PCD' THEN 1 ELSE 0 END) AS pcd,
                                 SUM(CASE WHEN categoria = 'IDOSO' THEN 1 ELSE 0 END) AS idoso,
                                 SUM(CASE WHEN categoria = 'ROTATIVO' THEN 1 ELSE 0 END) AS rotativo,
                                 COUNT(*) AS total
                          FROM vagas WHERE status = 'ativa'
                          GROUP BY bairro ORDER BY total DESC, bairro LIMIT 15")->fetchAll();
$porTipoRelato = $pdo->query("SELECT tipo, COUNT(*) AS total FROM relatos GROUP BY tipo ORDER BY total DESC")->fetchAll();
$vagasMapa = array_map(fn($v) => [(float) $v['latitude'], (float) $v['longitude']],
    $pdo->query("SELECT latitude, longitude FROM vagas WHERE status = 'ativa'")->fetchAll());
$logs = $pdo->query("SELECT l.acao, l.detalhe, l.ip, l.criado_em, u.nome
                     FROM logs_acesso l LEFT JOIN usuarios u ON u.id = l.usuario_id
                     ORDER BY l.id DESC LIMIT 20")->fetchAll();

admin_inicio('Dashboard', 'index', ['css' => [LEAFLET_CSS]]);
?>
<nav class="periodo" aria-label="Período das estatísticas">
  <span>Período:</span>
  <?php foreach (PERIODOS as $d => $rotulo): ?>
    <a class="chip-periodo" href="index.php?periodo=<?= $d ?>"<?= $d === $dias ? ' aria-current="true"' : '' ?>>Últimos <?= $rotulo ?></a>
  <?php endforeach; ?>
</nav>

<h2 class="secao">Uso do aplicativo</h2>
<div class="indicadores">
  <div class="indicador"><strong><?= $num($ativos) ?></strong><span>Usuários ativos</span><small>usaram o mapa no período</small></div>
  <div class="indicador"><strong><?= $num($uso['vaga_vista']) ?></strong><span>Vagas vistas</span><small>detalhes abertos</small></div>
  <div class="indicador"><strong><?= $num($uso['rota']) ?></strong><span>Rotas traçadas</span><small>dentro do app</small></div>
  <div class="indicador"><strong><?= $num($uso['chegada']) ?></strong><span>Chegadas às vagas</span>
    <small><?= $conclusao === null ? 'nenhuma navegação iniciada' : $conclusao . '% das navegações iniciadas' ?></small></div>
  <div class="indicador"><strong><?= $num($uso['waze'] + $uso['gmaps']) ?></strong><span>Abertas no Waze/Maps</span>
    <small>Waze <?= $num($uso['waze']) ?> · Maps <?= $num($uso['gmaps']) ?></small></div>
  <div class="indicador<?= $uso['busca_sem_vaga'] ? ' indicador-alerta' : '' ?>"><strong><?= $num($uso['busca_sem_vaga']) ?></strong>
    <span>Destinos sem vaga por perto</span><small>de <?= $num($uso['busca']) ?> buscas de destino</small></div>
</div>

<section class="bloco" aria-labelledby="t-uso-dia">
  <h2 id="t-uso-dia">Uso por dia</h2>
  <?= grafico_linhas($serie, [
      ['chave' => 'vaga_vista', 'nome' => 'Vagas vistas'],
      ['chave' => 'rota', 'nome' => 'Rotas traçadas'],
      ['chave' => 'chegada', 'nome' => 'Chegadas'],
  ], 'Vagas vistas, rotas traçadas e chegadas por dia, últimos ' . PERIODOS[$dias]) ?>
</section>

<div class="grade-2">
  <section class="bloco" aria-labelledby="t-top">
    <h2 id="t-top">Vagas mais procuradas</h2>
    <?php if (!$topVagas): ?>
      <p class="vazio-grafico">Nenhuma vaga foi vista no período.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Vaga</th><th class="num">Vistas</th><th class="num">Rotas</th><th class="num">Chegadas</th><th class="num">Waze/Maps</th></tr></thead>
      <tbody>
      <?php foreach ($topVagas as $v): ?>
        <tr>
          <td>
            <a href="vagas.php?editar=<?= (int) $v['id'] ?>"><?= e($v['logradouro'] . ($v['numero'] ? ', ' . $v['numero'] : '')) ?></a>
            <br><small><?= e($v['bairro']) ?> · <?= e($v['categoria']) ?></small>
          </td>
          <td class="num"><?= $num($v['vistas']) ?></td>
          <td class="num"><?= $num($v['rotas']) ?></td>
          <td class="num"><?= $num($v['chegadas']) ?></td>
          <td class="num"><?= $num($v['externos']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="bloco" aria-labelledby="t-bairros-procura">
    <h2 id="t-bairros-procura">Procura por bairro</h2>
    <?php if (!$procuraBairro): ?>
      <p class="vazio-grafico">Sem procura registrada no período.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Bairro</th><th>Procura</th><th class="num">Vistas</th><th class="num">Rotas</th></tr></thead>
      <tbody>
      <?php foreach ($procuraBairro as $b): $total = (int) $b['vistas'] + (int) $b['rotas']; ?>
        <tr>
          <td><?= e($b['bairro']) ?></td>
          <td class="celula-barra"><span class="barra-inline" aria-hidden="true"><span style="width: <?= round(100 * $total / $maxBairro) ?>%"></span></span></td>
          <td class="num"><?= $num($b['vistas']) ?></td>
          <td class="num"><?= $num($b['rotas']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="dica">“Rotas” inclui as aberturas no Waze/Google Maps.</p>
    <?php endif; ?>
  </section>
</div>

<section class="bloco" aria-labelledby="t-demanda">
  <h2 id="t-demanda">Onde falta vaga</h2>
  <p class="dica">Destinos que as pessoas buscaram e que não têm vaga especial a menos de 1 km — candidatos a receber vagas novas.
     A posição é arredondada (~100 m) e não identifica quem buscou.</p>
  <?php if (!$demanda): ?>
    <p class="vazio-grafico">Nenhuma busca sem vaga por perto no período.</p>
  <?php else: ?>
  <div class="demanda">
    <div>
      <div id="mapa-demanda" data-pontos="<?= e(json_encode($demanda, JSON_UNESCAPED_UNICODE)) ?>" data-vagas="<?= e(json_encode($vagasMapa)) ?>"
           role="img" aria-label="Mapa dos destinos buscados sem vaga por perto"></div>
      <ul class="legenda">
        <li><span class="amostra amostra-demanda"></span>Destino sem vaga a menos de 1 km (maior = mais buscas)</li>
        <li><span class="amostra amostra-vaga"></span>Vagas existentes</li>
      </ul>
    </div>
    <table>
      <thead><tr><th>Destino buscado</th><th class="num">Buscas</th></tr></thead>
      <tbody>
      <?php foreach ($demanda as $d): ?>
        <tr><td><?= e($d['nome']) ?></td><td class="num"><?= $num($d['buscas']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<h2 class="secao">Cadastros e base de vagas</h2>
<div class="indicadores">
  <div class="indicador"><strong><?= $num($novosCadastros) ?></strong><span>Novos cadastros</span><small>no período</small></div>
  <div class="indicador"><strong><?= $tempoAnalise === null ? '—' : ($tempoAnalise < 1 ? max(1, (int) round($tempoAnalise * 60)) . ' min' : ($tempoAnalise < 48 ? number_format($tempoAnalise, 1, ',', '.') . ' h' : number_format($tempoAnalise / 24, 1, ',', '.') . ' dias')) ?></strong>
    <span>Tempo médio de análise</span><small>do envio à aprovação/recusa</small></div>
  <a class="indicador<?= $ind['comp_pendentes'] ? ' indicador-alerta' : '' ?>" href="comprovacoes.php"><strong><?= $ind['comp_pendentes'] ?></strong><span>Comprovações aguardando análise</span></a>
  <a class="indicador<?= $ind['relatos_abertos'] ? ' indicador-alerta' : '' ?>" href="relatos.php"><strong><?= $ind['relatos_abertos'] ?></strong><span>Relatos em aberto</span></a>
  <a class="indicador" href="usuarios.php?status=aprovado"><strong><?= $ind['usuarios_aprov'] ?></strong><span>Pessoas com acesso ao mapa</span>
    <small>perfis aprovados: PCD <?= (int) ($perfisAprovados['PCD'] ?? 0) ?> · Idoso <?= (int) ($perfisAprovados['IDOSO'] ?? 0) ?> · Rotativo <?= (int) ($perfisAprovados['ROTATIVO'] ?? 0) ?></small></a>
  <a class="indicador" href="vagas.php?status=ativa"><strong><?= $ind['vagas_ativas'] ?></strong><span>Vagas ativas</span>
    <small>PCD <?= $ind['vagas_pcd'] ?> · Idoso <?= $ind['vagas_idoso'] ?> · Rotativo <?= $ind['vagas_rotativo'] ?> · inativas <?= $ind['vagas_inativas'] ?></small></a>
</div>

<div class="grade-2">
  <section class="bloco">
    <h2>Vagas ativas por bairro</h2>
    <?php if (!$porBairro): ?>
      <p>Nenhuma vaga cadastrada. <a href="importar.php">Importe a base</a>.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Bairro</th><th class="num">PCD</th><th class="num">Idoso</th><th class="num">Rotativo</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($porBairro as $b): ?>
        <tr>
          <td><a href="vagas.php?bairro=<?= urlencode($b['bairro']) ?>"><?= e($b['bairro']) ?></a></td>
          <td class="num"><?= (int) $b['pcd'] ?></td><td class="num"><?= (int) $b['idoso'] ?></td><td class="num"><?= (int) $b['rotativo'] ?></td><td class="num"><?= (int) $b['total'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="bloco">
    <h2>Relatos por tipo</h2>
    <?php if (!$porTipoRelato): ?>
      <p>Nenhum relato recebido.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Tipo</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($porTipoRelato as $t): ?>
        <tr><td><?= e(rotulo(TIPOS_RELATO, $t['tipo'])) ?></td><td class="num"><?= (int) $t['total'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
</div>

<section class="bloco">
  <h2>Registro de acessos recentes</h2>
  <table>
    <thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Detalhe</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td><?= e(data_br($l['criado_em'])) ?></td>
        <td><?= e($l['nome'] ?? '—') ?></td>
        <td><?= e($l['acao']) ?></td>
        <td><?= e($l['detalhe'] ?? '') ?></td>
        <td><?= e($l['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
admin_fim([LEAFLET_JS, '../assets/js/admin.js']);
