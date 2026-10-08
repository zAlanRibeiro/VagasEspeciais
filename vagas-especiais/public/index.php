<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/layout.php';

$u = usuario_atual();
if ($u !== null) {
    redirecionar(pode_usar_app($u) ? 'mapa.php' : 'perfil.php');
}

cabecalho('Início', ['pwa' => true, 'classe_body' => 'pagina-centro']);
?>
<main id="conteudo" class="cartao boas-vindas">
  <img class="ilustracao" src="../assets/img/ilustracao-boas-vindas.svg" alt="" width="360" height="220">
  <h1>Estacione com tranquilidade</h1>
  <p class="lead">Vagas para <strong>pessoas com deficiência</strong>, <strong>pessoas idosas</strong> e do
     <strong>estacionamento rotativo</strong> nas ruas de Niterói — e o caminho até elas com um toque.</p>
  <ul class="beneficios">
    <li><span class="bolha"><?= icone('mapa') ?></span> Todas as vagas da base oficial da Nittrans</li>
    <li><span class="bolha bolha-verde"><?= icone('localizar') ?></span> As mais próximas de você primeiro</li>
    <li><span class="bolha bolha-ambar"><?= icone('navegar') ?></span> Rota até a vaga com voz, no próprio app</li>
  </ul>
  <div class="acoes">
    <a class="botao botao-primario" href="login.php">Entrar</a>
    <a class="botao" href="cadastro.php">Criar conta</a>
  </div>
  <?= botao_modo_acessivel() ?>
  <p class="rodape-tela"><a href="privacidade.php"><?= icone('escudo', 'icone icone-p') ?> Privacidade e termos de uso</a></p>
</main>
<?php
rodape(['../assets/js/app.js']);
