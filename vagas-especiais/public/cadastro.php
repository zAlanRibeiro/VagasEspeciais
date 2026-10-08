<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/layout.php';

if (usuario_atual() !== null) {
    redirecionar('index.php');
}

cabecalho('Criar conta', ['pwa' => true, 'classe_body' => 'pagina-centro']);
?>
<main id="conteudo" class="cartao">
  <div class="topo-tela">
    <a class="botao-icone" href="index.php" aria-label="Voltar ao início"><?= icone('voltar') ?></a>
    <span class="marca-pequena"><img src="../assets/img/icon.svg" alt="" width="26" height="26"> Vagas Especiais</span>
  </div>
  <h1>Criar conta</h1>
  <p class="subtitulo">Todos começam com o rotativo. Se você é PCD ou idoso, marque abaixo: esses perfis são liberados depois que a equipe conferir a comprovação.</p>

  <form data-api="../api/cadastro.php" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <label for="nome">Nome completo</label>
    <div class="campo">
      <?= icone('usuario') ?>
      <input id="nome" name="nome" autocomplete="name" maxlength="150" required>
    </div>

    <label for="email">E-mail</label>
    <div class="campo">
      <?= icone('email') ?>
      <input id="email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="190" placeholder="seu@email.com" required>
    </div>

    <label for="senha">Senha <span class="dica">(mínimo 8 caracteres)</span></label>
    <div class="campo campo-senha">
      <?= icone('cadeado') ?>
      <input id="senha" name="senha" type="password" autocomplete="new-password" minlength="8" required>
      <button type="button" class="mostrar-senha" aria-label="Mostrar senha" aria-pressed="false"><?= icone('olho') ?></button>
    </div>

    <label for="confirmar">Confirme a senha</label>
    <div class="campo campo-senha">
      <?= icone('cadeado') ?>
      <input id="confirmar" name="confirmar" type="password" autocomplete="new-password" minlength="8" required>
      <button type="button" class="mostrar-senha" aria-label="Mostrar senha" aria-pressed="false"><?= icone('olho') ?></button>
    </div>

    <fieldset>
      <legend>Seus perfis <span class="dica">(PCD e idoso são opcionais)</span></legend>
      <div class="opcoes-cartao">
        <?php foreach (PERFIS as $tipo => $perfil): ?>
          <label class="opcao-cartao">
            <input type="checkbox" name="perfis[]" value="<?= e($tipo) ?>"<?= $perfil['documento'] ? ' data-documento="doc-' . strtolower($tipo) . '"' : ' checked disabled' ?>>
            <span class="opcao-icone opcao-icone-<?= strtolower($tipo) ?>"><?= simbolo_perfil($tipo) ?></span>
            <span class="opcao-texto">
              <strong><?= e($perfil['nome']) ?></strong>
              <small><?= $perfil['documento'] ? 'Precisa de comprovação e análise da equipe' : 'Estacionamento rotativo · incluído para todos, sem comprovação' ?></small>
            </span>
            <span class="opcao-marca"><?= icone('check') ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <?php foreach (PERFIS_COM_COMPROVACAO as $tipo): $id = 'doc-' . strtolower($tipo); ?>
      <div class="documento-perfil" id="<?= $id ?>" hidden>
        <label for="<?= $id ?>-arquivo">Comprovação: <?= e(mb_strtolower(PERFIS[$tipo]['nome'])) ?></label>
        <label class="arquivo" for="<?= $id ?>-arquivo">
          <input id="<?= $id ?>-arquivo" name="documento_<?= $tipo ?>" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
          <span class="arquivo-icone"><?= icone('camera', 'icone icone-g') ?></span>
          <span class="arquivo-texto"><strong data-arquivo-nome>Tirar foto ou escolher arquivo</strong><small><?= e(PERFIS[$tipo]['documento']) ?> · PDF, JPG ou PNG · até 5 MB</small></span>
        </label>
      </div>
    <?php endforeach; ?>

    <label class="opcao">
      <input type="checkbox" name="aceite" value="1" required>
      <span>Li e aceito a <a href="privacidade.php" target="_blank" rel="noopener">política de privacidade e os termos de uso</a>.</span>
    </label>

    <p class="erro" data-erro role="alert" hidden></p>
    <button class="botao botao-primario" type="submit">Enviar cadastro</button>
  </form>
  <p class="nota">Já tem conta? <a href="login.php">Entrar</a></p>
</main>
<?php
rodape(['../assets/js/app.js']);
