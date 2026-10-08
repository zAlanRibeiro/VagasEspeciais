<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/layout.php';

if (usuario_atual() !== null) {
    redirecionar('index.php');
}

cabecalho('Entrar', ['pwa' => true, 'classe_body' => 'pagina-centro']);
?>
<main id="conteudo" class="cartao">
  <div class="topo-tela">
    <a class="botao-icone" href="index.php" aria-label="Voltar ao início"><?= icone('voltar') ?></a>
    <span class="marca-pequena"><img src="../assets/img/icon.svg" alt="" width="26" height="26"> Vagas Especiais</span>
  </div>
  <h1>Entrar</h1>
  <p class="subtitulo">Que bom ter você de volta!</p>
  <?php if (isset($_GET['conta_excluida'])): ?>
    <p class="alerta alerta-ok" role="status"><?= icone('check') ?> Sua conta e seus dados foram excluídos.</p>
  <?php endif; ?>
  <form data-api="../api/login.php" novalidate>
    <?= csrf_field() ?>
    <label for="email">E-mail</label>
    <div class="campo">
      <?= icone('email') ?>
      <input id="email" name="email" type="email" autocomplete="email" inputmode="email" placeholder="seu@email.com" required>
    </div>

    <label for="senha">Senha</label>
    <div class="campo campo-senha">
      <?= icone('cadeado') ?>
      <input id="senha" name="senha" type="password" autocomplete="current-password" required>
      <button type="button" class="mostrar-senha" aria-label="Mostrar senha" aria-pressed="false"><?= icone('olho') ?></button>
    </div>

    <p class="erro" data-erro role="alert" hidden></p>
    <button class="botao botao-primario" type="submit">Entrar</button>
  </form>
  <p class="nota">Ainda não tem conta? <a href="cadastro.php">Cadastre-se</a></p>
  <div class="rodape-tela"><?= botao_modo_acessivel() ?></div>
</main>
<?php
rodape(['../assets/js/app.js']);
