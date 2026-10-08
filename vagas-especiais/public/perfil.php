<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/layout.php';

$u = exigir_login();

$st = db()->prepare('SELECT perfil, nome_original, status, observacao, criado_em, analisado_em
                     FROM comprovacoes WHERE usuario_id = ? ORDER BY id DESC');
$st->execute([$u['id']]);
$comprovacoes = $st->fetchAll();

$st = db()->prepare('SELECT r.tipo, r.status, r.resposta_admin, r.criado_em, v.logradouro, v.numero, v.bairro
                     FROM relatos r JOIN vagas v ON v.id = r.vaga_id
                     WHERE r.usuario_id = ? ORDER BY r.id DESC LIMIT 20');
$st->execute([$u['id']]);
$relatos = $st->fetchAll();

$perfis = $u['perfis'];
$novos = array_diff(PERFIS_COM_COMPROVACAO, array_keys($perfis));   // o rotativo todos já têm
$aguardando = array_filter($perfis, fn($p) => in_array($p['status'], ['pendente', 'em_analise'], true));

// Avisos: mostra (com os novos destacados) e já marca como lidos.
$avisos = avisos_do_usuario((int) $u['id']);
marcar_avisos_lidos((int) $u['id']);
$vapid = null;
try {
    $vapid = vapid_chaves()['publica'];
} catch (RuntimeException $e) {
    error_log('Push: ' . $e->getMessage());
}

/** Formulário de envio da comprovação de um perfil PCD/idoso. */
function form_documento(string $tipo, string $botao): string
{
    $id = 'doc-' . strtolower($tipo);
    return '<form data-api="../api/perfil.php" enctype="multipart/form-data" novalidate class="form-perfil">'
        . csrf_field() . '<input type="hidden" name="perfil" value="' . e($tipo) . '">'
        . '<label class="arquivo" for="' . $id . '">'
        . '<input id="' . $id . '" name="documento" type="file" accept=".pdf,.jpg,.jpeg,.png" required>'
        . '<span class="arquivo-icone">' . icone('camera', 'icone icone-g') . '</span>'
        . '<span class="arquivo-texto"><strong data-arquivo-nome>Tirar foto ou escolher arquivo</strong><small>'
        . e(PERFIS[$tipo]['documento']) . ' · PDF, JPG ou PNG · até 5 MB</small></span></label>'
        . '<p class="erro" data-erro role="alert" hidden></p>'
        . '<button class="botao botao-primario" type="submit">' . e($botao) . '</button></form>';
}

$partes = preg_split('/\s+/', trim($u['nome']));
$iniciais = mb_strtoupper(mb_substr($partes[0], 0, 1) . (count($partes) > 1 ? mb_substr(end($partes), 0, 1) : ''));

cabecalho('Meu perfil', ['pwa' => true, 'classe_body' => 'pagina-centro']);
?>
<main id="conteudo" class="cartao cartao-largo">
  <div class="topo-tela">
    <?php if (pode_usar_app($u)): ?>
      <a class="botao-icone" href="mapa.php" aria-label="Voltar ao mapa"><?= icone('voltar') ?></a>
    <?php else: ?>
      <span class="marca-pequena"><img src="../assets/img/icon.svg" alt="" width="26" height="26"> Vagas Especiais</span>
    <?php endif; ?>
    <a class="botao botao-pequeno" href="logout.php"><?= icone('sair') ?> Sair</a>
  </div>

  <div class="cartao-identidade">
    <span class="avatar" aria-hidden="true"><?= e($iniciais) ?></span>
    <div class="identidade-texto">
      <h1>Olá, <?= e($partes[0]) ?></h1>
      <span class="email"><?= e($u['email']) ?></span>
      <?php if (categorias_visiveis($u) && !is_admin($u)): ?>
        <span class="selos-perfis">
          <?php foreach (categorias_visiveis($u) as $tipo): ?>
            <span class="selo selo-<?= strtolower($tipo) ?>"><?= simbolo_perfil($tipo) ?> <?= e(PERFIS[$tipo]['nome']) ?></span>
          <?php endforeach; ?>
        </span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($avisos): ?>
  <section class="painel-conteudo" aria-labelledby="t-avisos">
    <h2 id="t-avisos">Avisos</h2>
    <ul class="lista-cartoes">
      <?php foreach ($avisos as $a): ?>
        <li<?= $a['lido'] ? '' : ' class="nao-lido"' ?>>
          <span class="bolha bolha-ambar"><?= icone('sino') ?></span>
          <span class="item-corpo">
            <strong><?= e($a['titulo']) ?></strong>
            <span class="resposta"><?= e($a['corpo']) ?></span>
            <small><?= e($a['data']) ?></small>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="painel-conteudo" aria-labelledby="t-perfis">
    <h2 id="t-perfis">Seus perfis</h2>
    <?php if (!empty($u['suspenso'])): ?>
      <p class="alerta alerta-erro">Seu acesso foi suspenso pela administração. Em caso de dúvida, procure a Nittrans.</p>
    <?php elseif (is_admin($u)): ?>
      <p class="dica">Como administrador, você vê todos os tipos de vaga no mapa.</p>
    <?php endif; ?>

    <?php if ($perfis): ?>
    <ul class="perfis">
      <?php foreach ($perfis as $tipo => $p): ?>
        <li class="perfil perfil-<?= e($p['status']) ?>">
          <div class="perfil-linha">
            <span class="opcao-icone opcao-icone-<?= strtolower($tipo) ?>" aria-hidden="true"><?= simbolo_perfil($tipo) ?></span>
            <span class="perfil-texto">
              <span class="perfil-titulo"><strong><?= e(PERFIS[$tipo]['nome']) ?></strong>
                <span class="selo selo-<?= e($p['status']) ?>"><?= e(status_perfil_texto($p)) ?></span></span>
              <small><?= e(descricao_perfil($p)) ?></small>
            </span>
          </div>
          <?php if (in_array($tipo, PERFIS_COM_COMPROVACAO, true) && perfil_aceita_documento($p) && empty($u['suspenso'])): ?>
            <?= form_documento($tipo, 'Enviar comprovação') ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if (pode_usar_app($u)): ?>
      <div class="acoes-status">
        <a class="botao botao-primario" href="mapa.php"><?= icone('mapa') ?> Abrir mapa de vagas</a>
      </div>
    <?php elseif (array_filter($perfis, fn($p) => $p['status'] === 'em_analise')): ?>
      <div class="acoes-status">
        <a class="botao" href="perfil.php"><?= icone('atualizar') ?> Atualizar status</a>
      </div>
    <?php endif; ?>

    <?php if ($aguardando): ?>
      <div class="convite" data-push-convite hidden>
        <span class="bolha bolha-ambar"><?= icone('sino') ?></span>
        <span class="config-texto"><strong>Quer ser avisado da aprovação?</strong><small>Mandamos uma notificação assim que a equipe analisar seu documento.</small></span>
        <button type="button" class="botao botao-pequeno" data-push-ativar>Ativar</button>
      </div>
    <?php endif; ?>

    <?php if ($novos && !is_admin($u) && empty($u['suspenso'])): ?>
      <h3 class="titulo-novos">Adicionar perfil</h3>
      <ul class="perfis perfis-novos">
        <?php foreach ($novos as $tipo): ?>
          <li class="perfil">
              <details class="perfil-adicionar">
                <summary class="perfil-linha">
                  <span class="opcao-icone opcao-icone-<?= strtolower($tipo) ?>" aria-hidden="true"><?= simbolo_perfil($tipo) ?></span>
                  <span class="perfil-texto"><strong><?= e(PERFIS[$tipo]['nome']) ?></strong><small>Precisa de comprovação (<?= e(PERFIS[$tipo]['documento']) ?>). A equipe analisa antes de liberar.</small></span>
                  <span class="botao botao-pequeno" aria-hidden="true">Adicionar</span>
                </summary>
                <?= form_documento($tipo, 'Enviar e pedir análise') ?>
              </details>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($comprovacoes): ?>
  <section class="painel-conteudo" aria-labelledby="t-comp">
    <h2 id="t-comp">Comprovações enviadas</h2>
    <ul class="lista-cartoes">
      <?php foreach ($comprovacoes as $c): ?>
        <li>
          <span class="bolha"><?= icone('documento') ?></span>
          <span class="item-corpo">
            <strong><?= e($c['nome_original']) ?></strong>
            <small><?= e(PERFIS[$c['perfil']]['nome'] ?? '') ?> · enviada em <?= e(data_br($c['criado_em'])) ?></small>
            <?php if ($c['observacao']): ?><span class="resposta"><?= e($c['observacao']) ?></span><?php endif; ?>
          </span>
          <span class="selo selo-<?= e($c['status']) ?>"><?= e(rotulo(STATUS_PERFIL, $c['status'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($relatos): ?>
  <section class="painel-conteudo" aria-labelledby="t-rel">
    <h2 id="t-rel">Meus relatos</h2>
    <ul class="lista-cartoes">
      <?php foreach ($relatos as $r): ?>
        <li>
          <span class="bolha bolha-ambar"><?= icone('bandeira') ?></span>
          <span class="item-corpo">
            <strong><?= e(rotulo(TIPOS_RELATO, $r['tipo'])) ?></strong>
            <small><?= e($r['logradouro'] . ($r['numero'] ? ', ' . $r['numero'] : '') . ' · ' . $r['bairro']) ?></small>
            <?php if ($r['resposta_admin']): ?><span class="resposta">Resposta: <?= e($r['resposta_admin']) ?></span><?php endif; ?>
          </span>
          <span class="selo selo-<?= e($r['status']) ?>"><?= e(rotulo(STATUS_RELATO, $r['status'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="painel-conteudo" aria-labelledby="t-config">
    <h2 id="t-config">Configurações</h2>
    <div class="config-item">
      <span class="bolha"><?= icone('letra') ?></span>
      <span class="config-texto">
        <strong id="cfg-acessivel">Letra maior e alto contraste</strong>
        <small>Textos maiores, cores mais fortes e bordas bem marcadas.</small>
      </span>
      <button type="button" class="interruptor" role="switch" aria-labelledby="cfg-acessivel"
              aria-checked="<?= modo_acessivel() ? 'true' : 'false' ?>" data-modo-acessivel></button>
    </div>
    <div class="config-item">
      <span class="bolha bolha-ambar"><?= icone('sino') ?></span>
      <span class="config-texto">
        <strong id="cfg-push">Avisos no celular</strong>
        <small data-push-estado>Saiba na hora quando seu cadastro for analisado ou seu relato for respondido.</small>
      </span>
      <button type="button" class="interruptor" role="switch" aria-labelledby="cfg-push" aria-checked="false"
              data-push data-vapid="<?= e((string) $vapid) ?>" disabled></button>
    </div>
    <?php if (pode_usar_app($u)): ?>
    <div class="config-item">
      <span class="bolha bolha-verde"><?= icone('info') ?></span>
      <span class="config-texto">
        <strong id="cfg-tutorial">Como usar o aplicativo</strong>
        <small>Reveja o passo a passo e como liberar a localização.</small>
      </span>
      <a class="botao botao-pequeno" href="mapa.php?tutorial=1" aria-label="Ver o tutorial de como usar o aplicativo">Ver</a>
    </div>
    <?php endif; ?>
  </section>

  <?php if (!is_admin($u)): ?>
  <details class="zona-perigo">
    <summary><?= icone('lixeira') ?> Excluir minha conta</summary>
    <div class="conteudo">
      <p>Seus dados pessoais e documentos serão apagados definitivamente. Os relatos enviados permanecem, sem identificação.</p>
      <form data-api="../api/conta.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="excluir">
        <label for="senha-excluir">Confirme sua senha</label>
        <div class="campo campo-senha">
          <?= icone('cadeado') ?>
          <input id="senha-excluir" name="senha" type="password" autocomplete="current-password" required>
          <button type="button" class="mostrar-senha" aria-label="Mostrar senha" aria-pressed="false"><?= icone('olho') ?></button>
        </div>
        <p class="erro" data-erro role="alert" hidden></p>
        <button class="botao botao-perigo botao-bloco acoes-form" type="submit"><?= icone('lixeira') ?> Excluir conta definitivamente</button>
      </form>
    </div>
  </details>
  <?php endif; ?>

  <p class="rodape-tela"><a href="privacidade.php"><?= icone('escudo', 'icone icone-p') ?> Privacidade e termos de uso</a></p>
</main>
<?php
rodape(['../assets/js/app.js']);
