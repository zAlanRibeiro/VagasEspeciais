/*
 * Tutorial de primeiro acesso: como o app funciona e como liberar a localização.
 * Compartilhado entre o site (public/mapa.php) e o app móvel (shared/tutorial.js).
 *
 * Usa do adaptador window.Vagas (assets/js/app.js no site, src/js/main.js no app):
 *   Vagas.permissaoLocalizacao() e Vagas.pedirLocalizacao(), que respondem
 *   'granted' | 'aproximada' | 'prompt' | 'denied' | 'desligado' | 'indisponivel'.
 *
 * O site abre o tutorial sozinho na primeira visita ao mapa (ou com ?tutorial=1);
 * o app chama VagasTutorial.configurar() e VagasTutorial.abrir() quando quer.
 */
(function () {
  'use strict';

  var CHAVE_VISTO = 'vagas_tutorial_visto';

  var config = {
    plataforma: 'web',             // 'android' | 'ios' | 'web' — muda os textos de permissão
    imagens: '../assets/img/',
    aoFechar: function () {
      try { localStorage.setItem(CHAVE_VISTO, '1'); } catch (e) { /* sem armazenamento: mostra de novo na próxima vez */ }
    }
  };

  function icone(nome, classe) {
    return '<svg class="' + (classe || 'icone') + '" aria-hidden="true" focusable="false"><use href="#i-' + nome + '"></use></svg>';
  }

  function pino(categoria, classe) {
    var simbolo = categoria === 'PCD' ? icone('pcd', 'icone-pcd') : (categoria === 'IDOSO' ? '60+' : 'P');
    return '<span class="marcador marcador-' + categoria.toLowerCase() + ' ' + (classe || '') + '" aria-hidden="true">'
         + '<span class="marcador-corpo"><span class="marcador-icone">' + simbolo + '</span></span></span>';
  }

  /** Fundo de mapa desenhado (ruas, praça e água). Estica junto com a cena para os pinos ficarem no lugar. */
  function fundoMapa(extra) {
    return '<svg class="tut-fundo" viewBox="0 0 320 180" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
      + '<rect width="320" height="180" fill="#ece6dc"/>'
      + '<path d="M0 138 Q58 128 92 180 L0 180Z" fill="#bcd8ee"/>'
      + '<rect x="250" y="66" width="70" height="38" rx="10" fill="#cfe5c3"/>'
      + '<g fill="none" stroke="#fff" stroke-linecap="round" vector-effect="non-scaling-stroke">'
      + '<path d="M-10 50 L330 46" stroke-width="13" vector-effect="non-scaling-stroke"/>'
      + '<path d="M-10 112 L330 116" stroke-width="10" vector-effect="non-scaling-stroke"/>'
      + '<path d="M84 -10 L90 190" stroke-width="10" vector-effect="non-scaling-stroke"/>'
      + '<path d="M176 -10 L172 190" stroke-width="13" vector-effect="non-scaling-stroke"/>'
      + '<path d="M252 -10 L258 190" stroke-width="8" vector-effect="non-scaling-stroke"/>'
      + '</g>' + (extra || '') + '</svg>';
  }

  function voce(classe) {
    return '<span class="voce-marcador tut-voce ' + classe + '" aria-hidden="true"><span class="voce-pulso"></span><span class="voce-ponto"></span></span>';
  }

  /* ---------- Textos de permissão de cada aparelho ---------- */

  var TEXTOS = {
    android: {
      pedido: 'Quando o celular perguntar, toque em <b>Durante o uso do app</b> e deixe marcada a opção <b>Precisa</b>.',
      bloqueada: [
        'Abra as <b>Configurações</b> do celular e toque em <b>Apps</b> (ou Aplicativos).',
        'Escolha <b>Vagas Especiais</b> → <b>Permissões</b> → <b>Localização</b>.',
        'Marque <b>Permitir durante o uso do app</b> e ligue <b>Usar local exato</b>.'
      ],
      desligada: 'Deslize o dedo do topo da tela para baixo e toque em <b>Localização</b> para ligar.',
      aproximada: 'Para corrigir, abra <b>Configurações</b> → <b>Apps</b> → <b>Vagas Especiais</b> → <b>Permissões</b> → <b>Localização</b> e ligue <b>Usar local exato</b>.'
    },
    ios: {
      pedido: 'Quando o iPhone perguntar, toque em <b>Permitir Durante o Uso do App</b>.',
      bloqueada: [
        'Abra os <b>Ajustes</b> do iPhone.',
        'Toque em <b>Vagas Especiais</b> → <b>Localização</b>.',
        'Escolha <b>Durante o Uso do App</b> e ligue <b>Localização Precisa</b>.'
      ],
      desligada: 'Abra <b>Ajustes</b> → <b>Privacidade e Segurança</b> → <b>Serviços de Localização</b> e ligue.',
      aproximada: 'Para corrigir, abra <b>Ajustes</b> → <b>Vagas Especiais</b> → <b>Localização</b> e ligue <b>Localização Precisa</b>.'
    },
    web: {
      pedido: 'Quando o navegador perguntar, toque em <b>Permitir</b>.',
      bloqueada: [
        'Toque no ícone ao lado do endereço do site (cadeado ou ajustes).',
        'Abra <b>Permissões</b> e mude <b>Localização</b> para <b>Permitir</b>.',
        'No iPhone: <b>Ajustes</b> → <b>Privacidade e Segurança</b> → <b>Serviços de Localização</b> → <b>Sites do Safari</b>.'
      ],
      desligada: 'Ligue a localização (GPS) do aparelho e tente de novo.',
      aproximada: ''
    }
  };

  function textos() { return TEXTOS[config.plataforma] || TEXTOS.web; }

  /** Imitação do pedido de permissão do sistema, para a pessoa reconhecer o que vai aparecer. */
  function pedidoDoSistema() {
    if (config.plataforma === 'android') {
      return '<div class="tut-sistema tut-sistema-android">'
        + '<span class="tut-sistema-icone">' + icone('localizar') + '</span>'
        + '<p>Permitir que o app <b>Vagas Especiais</b> acesse a localização deste dispositivo?</p>'
        + '<div class="tut-sistema-precisao"><span class="marcado">Precisa</span><span>Aproximada</span></div>'
        + '<span class="tut-sistema-opcao tut-destaque">Durante o uso do app<i class="tut-toque"></i></span>'
        + '<span class="tut-sistema-opcao">Apenas esta vez</span>'
        + '<span class="tut-sistema-opcao">Não permitir</span></div>';
    }
    if (config.plataforma === 'ios') {
      return '<div class="tut-sistema tut-sistema-ios">'
        + '<p><b>Permitir que “Vagas Especiais” use a sua localização?</b></p>'
        + '<span class="tut-sistema-opcao">Permitir Uma Vez</span>'
        + '<span class="tut-sistema-opcao tut-destaque">Permitir Durante o Uso do App<i class="tut-toque"></i></span>'
        + '<span class="tut-sistema-opcao">Não Permitir</span></div>';
    }
    return '<div class="tut-sistema tut-sistema-web">'
      + '<p>' + icone('localizar') + '<span>Este site quer <b>saber sua localização</b></span></p>'
      + '<div class="tut-sistema-botoes"><span class="tut-sistema-opcao">Bloquear</span>'
      + '<span class="tut-sistema-opcao tut-destaque">Permitir<i class="tut-toque"></i></span></div></div>';
  }

  /* ---------- Passos ---------- */

  var PASSOS = [
    {
      cena: function () {
        return '<div class="tut-cena tut-cena-inicio"><img src="' + config.imagens + 'ilustracao-boas-vindas.svg" alt="" width="360" height="220"></div>';
      },
      titulo: 'Boas-vindas ao Vagas Especiais!',
      corpo: function () {
        return '<p>Em poucos passos, veja como encontrar uma vaga especial perto de você e chegar até ela com tranquilidade.</p>'
          + '<p class="tut-nota">' + icone('relogio') + ' Leva menos de um minuto. Você pode pular quando quiser.</p>';
      },
      proximo: 'Começar'
    },
    {
      id: 'localizacao',
      cena: function () { return '<div class="tut-cena tut-cena-local">' + pedidoDoSistema() + '</div>'; },
      titulo: 'Permita o acesso à sua localização',
      corpo: function () {
        return '<p>Com ela, o app mostra as vagas mais perto de você e guia o caminho até lá.</p>'
          + '<p class="tut-nota">' + icone('escudo') + ' Sua localização fica no aparelho. Ela só é enviada, sem o seu nome, quando você pede uma rota.</p>'
          + '<div class="tut-local" data-local><div role="status" data-local-estado></div><div data-local-acao></div></div>';
      }
    },
    {
      cena: function () {
        return '<div class="tut-cena tut-cena-mapa">' + fundoMapa()
          + '<span class="tut-grupo tut-pos-grupo" aria-hidden="true">4</span>'
          + pino('PCD', 'tut-pino tut-pos-a') + pino('IDOSO', 'tut-pino tut-pos-b') + pino('PCD', 'tut-pino tut-pos-c')
          + pino('ROTATIVO', 'tut-pino tut-pos-r')
          + voce('tut-pos-voce')
          + '<span class="tut-flutuantes" aria-hidden="true"><span>' + icone('localizar') + '</span><span>' + icone('lista') + '</span></span>'
          + '</div>';
      },
      titulo: 'As vagas aparecem no mapa',
      corpo: function () {
        return '<p>Cada alfinete é uma vaga. Toque nele para ver o endereço e as opções. Aparecem só os tipos dos seus perfis aprovados.</p>'
          + '<ul class="tut-legenda">'
          + '<li><span class="tut-amostra">' + pino('PCD', 'tut-mini') + '</span><span><b>Azul:</b> vaga para pessoa com deficiência</span></li>'
          + '<li><span class="tut-amostra">' + pino('IDOSO', 'tut-mini') + '</span><span><b>Laranja:</b> vaga para pessoa idosa</span></li>'
          + '<li><span class="tut-amostra">' + pino('ROTATIVO', 'tut-mini') + '</span><span><b>Verde (P):</b> vaga do estacionamento rotativo</span></li>'
          + '<li><span class="tut-amostra"><span class="tut-grupo" aria-hidden="true">4</span></span><span><b>Número:</b> várias vagas juntas. Toque para aproximar.</span></li>'
          + '</ul>'
          + '<p class="tut-nota">' + icone('localizar') + ' Os botões redondos mostram onde você está e a lista das vagas mais próximas. No topo, filtre por PCD, Idoso ou bairro.</p>';
      }
    },
    {
      cena: function () {
        return '<div class="tut-cena tut-cena-busca">' + fundoMapa()
          + '<span class="tut-raio" aria-hidden="true"></span>'
          + pino('PCD', 'tut-pino tut-pos-d') + pino('IDOSO', 'tut-pino tut-pos-e')
          + '<span class="marcador marcador-busca tut-pino tut-pos-destino" aria-hidden="true"><span class="marcador-corpo"><span class="marcador-icone">' + icone('pin') + '</span></span></span>'
          + '<span class="tut-busca" aria-hidden="true">' + icone('busca') + '<span>Hospital Antônio Pedro</span><i class="tut-cursor"></i></span>'
          + '</div>';
      },
      titulo: 'Vai a outro lugar? Busque o destino',
      corpo: function () {
        return '<p>Digite um endereço, lugar ou bairro em <b>Para onde você vai?</b>. O app mostra as vagas a até 1 km do destino, das mais próximas para as mais distantes.</p>'
          + '<p class="tut-nota">' + icone('busca') + ' Não precisa escrever certinho: o app entende abreviações como “Av.” e pequenos erros de digitação.</p>';
      }
    },
    {
      cena: function () {
        var rota = '<path d="M88 168 L87 114 L254 116" fill="none" stroke="#fff" stroke-width="11" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>'
                 + '<path d="M88 168 L87 114 L254 116" fill="none" stroke="#1f5aae" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>';
        return '<div class="tut-cena tut-cena-rota">' + fundoMapa(rota)
          + voce('tut-pos-inicio') + pino('PCD', 'tut-pino tut-pos-fim')
          + '<span class="tut-nav" aria-hidden="true"><span class="tut-nav-icone">' + icone('virar-direita') + '</span>'
          + '<span class="tut-nav-texto"><b>200 m</b><span>Vire à direita</span></span><span class="tut-nav-som">' + icone('som') + '</span></span>'
          + '</div>';
      },
      titulo: 'Trace a rota e siga pela voz',
      corpo: function () {
        return '<ol class="tut-passos">'
          + '<li><span>Toque na vaga e depois em <b>Traçar rota até aqui</b>.</span></li>'
          + '<li><span>Confira o caminho e toque em <b>Iniciar</b>.</span></li>'
          + '<li><span>O app fala cada curva e avisa quando você chegar.</span></li>'
          + '</ol>'
          + '<p class="tut-nota">' + icone('navegar') + ' Prefere o Waze ou o Google Maps? Eles aparecem logo abaixo do botão de rota.</p>'
          + '<p class="tut-nota">' + icone('alerta') + ' Ao estacionar, deixe a credencial visível no painel do carro.</p>';
      }
    },
    {
      cena: function () {
        return '<div class="tut-cena tut-cena-pronto">' + pino('PCD', 'tut-pino tut-pos-f')
          + '<span class="tut-selo-pronto" aria-hidden="true">' + icone('check') + '</span>' + pino('IDOSO', 'tut-pino tut-pos-g') + '</div>';
      },
      titulo: 'Tudo pronto!',
      corpo: function () {
        var ligado = document.documentElement.classList.contains('modo-acessivel');
        return '<ul class="tut-lista">'
          + '<li><span class="bolha">' + icone('letra') + '</span>'
          + '<span class="config-texto"><strong id="tut-cfg-acessivel">Letra maior e alto contraste</strong><small>Textos maiores e cores mais fortes. Experimente agora.</small></span>'
          + '<button type="button" class="interruptor" role="switch" aria-labelledby="tut-cfg-acessivel" aria-checked="' + ligado + '" data-modo-acessivel></button></li>'
          + '<li><span class="bolha bolha-ambar">' + icone('bandeira') + '</span>'
          + '<span class="config-texto"><span>Achou algo errado numa vaga? Nos detalhes dela, toque em <b>Relatar um problema</b>.</span></span></li>'
          + '<li><span class="bolha bolha-verde">' + icone('usuario') + '</span>'
          + '<span class="config-texto"><span>No seu <b>perfil</b> ficam os avisos, as configurações e este tutorial, em <b>Como usar o aplicativo</b>.</span></span></li>'
          + '</ul>';
      },
      proximo: 'Começar a usar'
    }
  ];

  /* ---------- Janela ---------- */

  var atual = null;   // { raiz, janela, conteudo, indice, focoAnterior, inertes[], local }

  function $(sel) { return atual.janela.querySelector(sel); }

  function montar() {
    var raiz = document.createElement('div');
    raiz.className = 'tutorial';
    raiz.innerHTML =
        '<div class="tut-janela" role="dialog" aria-modal="true" aria-labelledby="tut-titulo">'
      + '<div class="tut-topo">'
      + '<ol class="tut-pontos" aria-hidden="true">' + PASSOS.map(function () { return '<li></li>'; }).join('') + '</ol>'
      + '<button type="button" class="tut-pular" data-tut="pular">Pular</button>'
      + '</div>'
      + '<div class="tut-conteudo"></div>'
      + '<div class="tut-rodape">'
      + '<div class="tut-botoes">'
      + '<button type="button" class="botao tut-esq"></button>'
      + '<button type="button" class="botao tut-dir"></button>'
      + '</div></div></div>';
    document.body.appendChild(raiz);
    return raiz;
  }

  function ir(indice, direcao) {
    var passo = PASSOS[indice];
    atual.indice = indice;
    var c = atual.conteudo;
    c.innerHTML = passo.cena()
      + '<div class="tut-texto"><h2 id="tut-titulo" tabindex="-1"><span class="sr-only">Passo ' + (indice + 1) + ' de ' + PASSOS.length + ': </span>'
      + passo.titulo + '</h2>' + passo.corpo() + '</div>';
    c.scrollTop = 0;
    c.classList.remove('tut-entrar-frente', 'tut-entrar-tras');
    if (direcao) {
      void c.offsetWidth;   // reinicia a animação
      c.classList.add(direcao > 0 ? 'tut-entrar-frente' : 'tut-entrar-tras');
    }

    atual.janela.querySelectorAll('.tut-pontos li').forEach(function (li, i) {
      li.className = i === indice ? 'atual' : (i < indice ? 'feito' : '');
    });
    $('.tut-pular').hidden = indice === PASSOS.length - 1;
    if (passo.id === 'localizacao') atual.local = null;
    atualizarRodape();
    if (passo.id === 'localizacao') verificarLocalizacao(false, false);
    $('#tut-titulo').focus({ preventScroll: true });
  }

  /**
   * Botões do rodapé. Na etapa da localização, enquanto ela não foi liberada, a ação principal (sempre no
   * mesmo lugar, à direita) é "Permitir localização" e a outra é "Agora não".
   */
  function atualizarRodape() {
    var passo = PASSOS[atual.indice];
    var esq = { texto: 'Voltar', acao: 'voltar', icone: 'voltar', oculto: atual.indice === 0 };
    var dir = { texto: passo.proximo || 'Próximo', acao: 'proximo', primario: true, seta: !passo.proximo };
    if (passo.id === 'localizacao') {
      var l = atual.local;
      if (!l || l === 'prompt' || l === 'aguardando') {
        esq = { texto: 'Agora não', acao: 'proximo' };
        dir = { texto: l === 'aguardando' ? 'Aguardando…' : 'Permitir localização', acao: 'pedir', primario: true, icone: 'localizar', esperando: l === 'aguardando' };
      } else if (l === 'denied' || l === 'desligado') {
        dir = { texto: 'Agora não', acao: 'proximo' };
      }
    }
    pintarBotao($('.tut-esq'), esq);
    pintarBotao($('.tut-dir'), dir);
  }

  function pintarBotao(b, c) {
    b.hidden = !!c.oculto;
    b.dataset.tut = c.acao;
    b.classList.toggle('botao-primario', !!c.primario);
    // aria-disabled (e não disabled) para o foco não sair do botão enquanto espera a resposta.
    if (c.esperando) b.setAttribute('aria-disabled', 'true'); else b.removeAttribute('aria-disabled');
    b.innerHTML = (c.icone ? icone(c.icone) + ' ' : '') + c.texto + (c.seta ? ' ' + icone('seta-dir') : '');
  }

  /* ---------- Etapa da localização ---------- */

  async function verificarLocalizacao(pedir, focar) {
    if (!$('[data-local]')) return;
    if (pedir) { atual.local = 'aguardando'; renderizarLocal(); atualizarRodape(); }
    var estado;
    try {
      var adaptador = window.Vagas || {};
      estado = pedir ? await adaptador.pedirLocalizacao() : await adaptador.permissaoLocalizacao();
    } catch (e) {
      estado = 'prompt';
    }
    if (!atual || PASSOS[atual.indice].id !== 'localizacao') return;   // a pessoa já saiu desta etapa
    atual.local = estado || 'prompt';
    renderizarLocal();
    atualizarRodape();
    if (focar) {
      // Bloqueada/desligada: o próximo passo é conferir de novo; nos outros casos, seguir em frente.
      var acao = atual.janela.querySelector('[data-local-acao] button');
      ((atual.local === 'denied' || atual.local === 'desligado') && acao ? acao : $('.tut-dir')).focus();
    }
  }

  function renderizarLocal() {
    var estado = atual.local;
    var t = textos();
    var caixa = $('[data-local]');
    var html = '';
    var botao = '';
    if (estado === 'granted') {
      html = '<div class="tut-estado tut-estado-ok">' + icone('check') + '<span><b>Localização permitida.</b> Tudo certo!</span></div>';
    } else if (estado === 'aproximada') {
      html = '<div class="tut-estado tut-estado-alerta">' + icone('alerta') + '<span><b>Você permitiu só a localização aproximada.</b> Ela basta para ver as vagas por perto, mas a navegação fica imprecisa. '
           + t.aproximada + '</span></div>';
      botao = 'Pedir localização precisa';
    } else if (estado === 'denied') {
      html = '<div class="tut-estado tut-estado-alerta">' + icone('alerta') + '<span><b>A localização está bloqueada.</b> Para liberar:'
           + '<ol>' + t.bloqueada.map(function (p) { return '<li>' + p + '</li>'; }).join('') + '</ol></span></div>';
      botao = 'Já liberei, verificar de novo';
    } else if (estado === 'desligado') {
      html = '<div class="tut-estado tut-estado-alerta">' + icone('alerta') + '<span><b>A localização do aparelho está desligada.</b> ' + t.desligada + '</span></div>';
      botao = 'Já liguei, verificar de novo';
    } else if (estado === 'indisponivel') {
      html = '<div class="tut-estado">' + icone('info') + '<span>Este aparelho não informa a localização. Você ainda pode buscar um endereço para ver as vagas por perto.</span></div>';
    } else {
      html = '<p class="tut-dica-pedido">' + t.pedido + '</p>';
    }
    // A região de status já existe na tela: leitores de tela anunciam a mudança.
    caixa.querySelector('[data-local-estado]').innerHTML = html;
    caixa.querySelector('[data-local-acao]').innerHTML = botao
      ? '<button type="button" class="botao botao-bloco" data-tut="pedir">' + icone('atualizar') + ' ' + botao + '</button>'
      : '';
  }

  /* ---------- Abrir, navegar e fechar ---------- */

  function abrir() {
    if (atual) return;
    var raiz = montar();
    atual = {
      raiz: raiz,
      janela: raiz.querySelector('.tut-janela'),
      conteudo: raiz.querySelector('.tut-conteudo'),
      indice: 0,
      focoAnterior: document.activeElement,
      inertes: [],
      local: null
    };
    // O resto da página fica inacessível enquanto o tutorial está aberto (leitores de tela e teclado).
    Array.prototype.forEach.call(document.body.children, function (el) {
      if (el !== raiz && !el.inert) { el.inert = true; atual.inertes.push(el); }
    });
    document.documentElement.classList.add('tutorial-aberto');
    raiz.addEventListener('click', aoClicar);
    raiz.addEventListener('keydown', aoTeclar);
    raiz.addEventListener('touchstart', aoTocar, { passive: true });
    raiz.addEventListener('touchend', aoSoltar, { passive: true });
    ir(0, 0);
  }

  async function fechar(concluido) {
    if (!atual) return;
    var a = atual;
    atual = null;
    a.inertes.forEach(function (el) { el.inert = false; });
    document.documentElement.classList.remove('tutorial-aberto');
    a.raiz.remove();
    if (a.focoAnterior && a.focoAnterior.focus && document.contains(a.focoAnterior)) a.focoAnterior.focus({ preventScroll: true });

    try { config.aoFechar({ concluido: concluido, localizacao: a.local }); } catch (e) { /* segue */ }

    // Localização liberada (agora ou antes): o mapa já mostra onde a pessoa está.
    var estado = a.local;
    if (!estado && window.Vagas && window.Vagas.permissaoLocalizacao) {
      try { estado = await window.Vagas.permissaoLocalizacao(); } catch (e) { estado = null; }
    }
    if ((estado === 'granted' || estado === 'aproximada') && window.VagasMapa && window.VagasMapa.localizar) {
      window.VagasMapa.localizar();
    }
  }

  function avancar() {
    if (atual.indice < PASSOS.length - 1) ir(atual.indice + 1, 1);
    else fechar(true);
  }

  /** Voltar um passo (ou fechar, no primeiro). Usado também pelo botão "voltar" do Android. */
  function voltar() {
    if (!atual) return;
    if (atual.indice > 0) ir(atual.indice - 1, -1);
    else fechar(false);
  }

  function aoClicar(ev) {
    var alvo = ev.target.closest('[data-tut]');
    if (!alvo || alvo.disabled || alvo.getAttribute('aria-disabled') === 'true') return;
    var acao = alvo.dataset.tut;
    if (acao === 'proximo') avancar();
    else if (acao === 'voltar') voltar();
    else if (acao === 'pular') fechar(false);
    else if (acao === 'pedir') {
      // Bloqueada ou desligada: só confere de novo (o sistema não pergunta outra vez).
      verificarLocalizacao(['denied', 'desligado'].indexOf(atual.local) === -1, true);
    }
  }

  function aoTeclar(ev) {
    if (ev.key === 'Escape') { ev.preventDefault(); fechar(false); return; }
    if (ev.key !== 'Tab') return;
    // Mantém o foco dentro da janela.
    var focaveis = Array.prototype.filter.call(
      atual.janela.querySelectorAll('button, [href], [tabindex]:not([tabindex="-1"])'),
      function (el) { return !el.disabled && !el.hidden && el.offsetParent !== null; }
    );
    if (!focaveis.length) return;
    var primeiro = focaveis[0];
    var ultimo = focaveis[focaveis.length - 1];
    if (ev.shiftKey && (document.activeElement === primeiro || !atual.janela.contains(document.activeElement))) {
      ev.preventDefault(); ultimo.focus();
    } else if (!ev.shiftKey && document.activeElement === ultimo) {
      ev.preventDefault(); primeiro.focus();
    }
  }

  // Arrastar para o lado troca de passo (como as telas de apresentação dos apps).
  var toque = null;
  function aoTocar(ev) {
    var t = ev.changedTouches[0];
    toque = { x: t.clientX, y: t.clientY };
  }
  function aoSoltar(ev) {
    if (!toque || !atual) return;
    var t = ev.changedTouches[0];
    var dx = t.clientX - toque.x;
    var dy = t.clientY - toque.y;
    toque = null;
    if (Math.abs(dx) < 60 || Math.abs(dy) > Math.abs(dx) * 0.7) return;
    if (dx < 0 && atual.indice < PASSOS.length - 1) avancar();
    else if (dx > 0 && atual.indice > 0) voltar();
  }

  window.VagasTutorial = {
    configurar: function (op) { Object.keys(op).forEach(function (k) { config[k] = op[k]; }); },
    abrir: abrir,
    aberto: function () { return !!atual; },
    voltar: voltar,
    fechar: function () { fechar(false); }
  };

  // Site: abre na primeira visita ao mapa (ou quando o perfil pede com ?tutorial=1). O app decide sozinho.
  document.addEventListener('DOMContentLoaded', function () {
    if (window.VAGAS_APP || !document.getElementById('mapa')) return;
    var pedido = /[?&]tutorial=1\b/.test(location.search);
    var visto = true;
    try { visto = localStorage.getItem(CHAVE_VISTO) === '1'; } catch (e) { /* sem armazenamento: não insiste */ }
    if (pedido) history.replaceState(null, '', location.pathname);
    if (pedido || !visto) abrir();
  });
})();
