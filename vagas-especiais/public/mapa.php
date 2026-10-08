<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/layout.php';

require APP_ROOT . '/includes/estatisticas.php';

$u = exigir_acesso_app();
registrar_uso((int) $u['id']);
$naoLidos = avisos_nao_lidos((int) $u['id']);

cabecalho('Mapa de vagas', [
    'pwa'         => true,
    'classe_body' => 'pagina-mapa',
    'css'         => array_merge([LEAFLET_CSS], CLUSTER_CSS),
]);
?>
<div class="mapa-topo">
  <header class="barra-mapa">
    <a class="marca" href="mapa.php" aria-label="Vagas Especiais Niterói">
      <img src="../assets/img/icon.svg" alt="" width="40" height="40">
    </a>
    <form class="busca" id="form-busca" role="search" autocomplete="off">
      <?= icone('busca') ?>
      <label class="sr-only" for="busca-campo">Buscar endereço, lugar ou bairro</label>
      <input id="busca-campo" type="search" placeholder="Para onde você vai?" enterkeyhint="search"
             role="combobox" aria-expanded="false" aria-controls="busca-sugestoes" aria-autocomplete="list" maxlength="120">
      <button type="button" class="busca-limpar" id="busca-limpar" aria-label="Limpar busca" hidden><?= icone('fechar') ?></button>
    </form>
    <div class="busca-sugestoes" id="busca-sugestoes" role="listbox" aria-label="Sugestões de busca" hidden></div>
    <div class="barra-acoes">
      <?php if (is_admin($u)): ?>
        <a class="botao-icone" href="../admin/index.php" aria-label="Painel administrativo" title="Painel administrativo"><?= icone('admin') ?></a>
      <?php endif; ?>
      <a class="botao-icone botao-avatar" href="perfil.php" title="Meu perfil"
         aria-label="Meu perfil<?= $naoLidos ? ' — ' . $naoLidos . ($naoLidos === 1 ? ' aviso novo' : ' avisos novos') : '' ?>">
        <?= icone('usuario') ?><?php if ($naoLidos): ?><span class="ponto-aviso" aria-hidden="true"><?= $naoLidos > 9 ? '9+' : $naoLidos ?></span><?php endif; ?>
      </a>
    </div>
  </header>

  <section class="filtros" aria-label="Filtros">
    <div class="chips" role="radiogroup" aria-label="Categoria">
      <button type="button" class="chip" role="radio" aria-checked="true" data-categoria="">Todas</button>
      <button type="button" class="chip chip-pcd" role="radio" aria-checked="false" data-categoria="PCD"><?= icone('pcd', 'icone-pcd') ?> PCD</button>
      <button type="button" class="chip chip-idoso" role="radio" aria-checked="false" data-categoria="IDOSO"><span class="chip-60">60+</span> Idoso</button>
      <button type="button" class="chip chip-rotativo" role="radio" aria-checked="false" data-categoria="ROTATIVO"><span class="chip-p">P</span> Rotativo</button>
    </div>
    <label class="sr-only" for="filtro-bairro">Bairro</label>
    <select id="filtro-bairro">
      <option value="">Todos os bairros</option>
    </select>
  </section>
</div>

<main id="conteudo" class="area-mapa">
  <div id="mapa" role="application" aria-label="Mapa das vagas especiais"></div>

  <div class="botoes-flutuantes">
    <button type="button" id="btn-localizar" class="botao-redondo" aria-label="Mostrar minha localização" title="Minha localização"><?= icone('localizar') ?></button>
    <button type="button" id="btn-lista" class="botao-redondo" aria-label="Ver lista de vagas" title="Lista de vagas" aria-expanded="false" aria-controls="painel-lista"><?= icone('lista') ?></button>
  </div>

  <p id="aviso-mapa" class="aviso-mapa" role="status" hidden></p>

  <!-- Lista de vagas (alternativa acessível ao mapa) -->
  <aside id="painel-lista" class="painel" aria-labelledby="t-lista" hidden>
    <div class="alca" aria-hidden="true"></div>
    <div class="painel-topo">
      <h2 id="t-lista"><span id="t-lista-texto">Vagas</span> <span id="contador" class="contador"></span></h2>
      <button type="button" class="fechar" data-fechar="painel-lista" aria-label="Fechar lista"><?= icone('fechar') ?></button>
    </div>
    <p class="dica" id="dica-lista">Toque no botão de localização para ver as mais próximas de você.</p>
    <ol id="lista-vagas" class="lista-vagas"></ol>
  </aside>

  <!-- Detalhes da vaga -->
  <aside id="painel-detalhe" class="painel" aria-labelledby="det-titulo" hidden>
    <div class="alca" aria-hidden="true"></div>
    <div class="painel-topo">
      <span id="det-categoria" class="selo"></span>
      <button type="button" class="fechar" data-fechar="painel-detalhe" aria-label="Fechar detalhes"><?= icone('fechar') ?></button>
    </div>
    <h2 id="det-titulo"></h2>
    <p class="det-local"><?= icone('pin') ?> <span id="det-bairro"></span></p>
    <div class="det-chips">
      <span class="chip-info chip-distancia" id="det-distancia" hidden><?= icone('localizar') ?> <span id="det-distancia-texto"></span></span>
      <span class="chip-info"><?= icone('relogio') ?> <span id="det-atualizado"></span></span>
    </div>
    <button type="button" id="btn-rota" class="botao botao-primario botao-bloco"><?= icone('rota') ?> Traçar rota até aqui</button>
    <p class="abrir-externo">ou abrir no
      <a id="det-waze" target="_blank" rel="noopener">Waze</a> ·
      <a id="det-gmaps" target="_blank" rel="noopener">Google Maps</a>
    </p>
    <div class="relatar">
      <button type="button" id="btn-relatar" class="botao-texto"><?= icone('bandeira') ?> Relatar um problema nesta vaga</button>
    </div>

    <form id="form-relato" hidden novalidate>
      <input type="hidden" name="vaga_id" id="relato-vaga">
      <label for="relato-tipo">Qual o problema?</label>
      <select id="relato-tipo" name="tipo" required>
        <option value="">Selecione…</option>
        <?php foreach (TIPOS_RELATO as $valor => $rotulo): ?>
          <option value="<?= e($valor) ?>"><?= e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="relato-descricao">Detalhes <span class="dica">(opcional)</span></label>
      <textarea id="relato-descricao" name="descricao" rows="3" maxlength="1000" placeholder="Conte o que você encontrou no local"></textarea>
      <p class="erro" data-erro role="alert" hidden></p>
      <button class="botao botao-primario" type="submit">Enviar relato</button>
    </form>
    <p id="relato-ok" class="sucesso" role="status" hidden></p>
  </aside>

  <!-- Rota até a vaga (calculada no servidor com dados do OpenStreetMap) -->
  <aside id="painel-rota" class="painel" aria-labelledby="rota-titulo" hidden>
    <div class="alca" aria-hidden="true"></div>
    <div class="painel-topo">
      <h2 id="rota-titulo">Rota</h2>
      <button type="button" class="fechar" id="rota-fechar" aria-label="Cancelar a rota"><?= icone('fechar') ?></button>
    </div>
    <p class="rota-resumo"><strong id="rota-tempo"></strong> <span id="rota-distancia"></span> <span id="rota-chegada"></span></p>
    <p class="dica rota-aviso">Rota sem informação de trânsito em tempo real.</p>
    <button type="button" id="btn-iniciar" class="botao botao-primario botao-bloco"><?= icone('navegar') ?> Iniciar</button>
    <ol id="rota-passos" class="rota-passos" aria-label="Passo a passo"></ol>
    <p class="rota-credito">Rotas: Valhalla (FOSSGIS) · dados © colaboradores do OpenStreetMap</p>
  </aside>

  <!-- Modo navegação -->
  <div id="navegacao" class="navegacao" hidden>
    <div class="nav-manobra" role="status" aria-live="polite">
      <span class="nav-icone" id="nav-icone" aria-hidden="true"></span>
      <span class="nav-texto"><strong id="nav-distancia"></strong><span id="nav-instrucao"></span></span>
    </div>
    <div class="nav-rodape">
      <span class="nav-restante"><strong id="nav-tempo"></strong><span id="nav-km"></span></span>
      <button type="button" class="botao-icone" id="nav-centralizar" aria-label="Centralizar no meu local" hidden><?= icone('localizar') ?></button>
      <button type="button" class="botao-icone" id="nav-som" aria-pressed="true" aria-label="Desligar a voz"><?= icone('som') ?></button>
      <button type="button" class="botao botao-perigo botao-pequeno" id="nav-encerrar">Encerrar</button>
    </div>
  </div>
</main>
<?php
rodape([LEAFLET_JS, CLUSTER_JS, '../assets/js/app.js', '../assets/js/mapa.js', '../assets/js/tutorial.js']);
