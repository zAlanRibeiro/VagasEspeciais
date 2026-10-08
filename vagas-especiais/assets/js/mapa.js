/* Vagas Especiais — mapa (Leaflet + OpenStreetMap), filtros, localização e navegação externa.
 *
 * Compartilhado entre o site (public/mapa.php) e o app (app-mobile). O acesso a dados e ao GPS
 * vem de window.Vagas, que cada lado implementa:
 *   Vagas.carregarVagas()           → Promise<{vagas, bairros, offline}> (ou null se redirecionou)
 *   Vagas.enviarRelato(formData)    → Promise<{ok, mensagem | erro}>
 *   Vagas.observarPosicao(ok, erro, altaPrecisao) → acompanha a posição; devolve (ou promete) a função que desliga o GPS
 *   Vagas.permissaoLocalizacao()    → Promise<'granted' | 'aproximada' | 'prompt' | 'denied' | …> (também usado pelo tutorial)
 *   Vagas.mostrarErro(el, msg)
 * Os ícones vêm do sprite SVG embutido na página (#i-*).
 * O site inicia o mapa sozinho; o app define window.VAGAS_APP e chama VagasMapa.iniciar(). */
(function () {
  'use strict';

  var CENTRO_NITEROI = [-22.9035, -43.1095];
  var ROTULO = { PCD: 'Pessoa com deficiência', IDOSO: 'Pessoa idosa', ROTATIVO: 'Rotativo' };
  var SIMBOLO = {
    PCD: '<svg class="icone-pcd" aria-hidden="true" focusable="false"><use href="#i-pcd"></use></svg>',
    IDOSO: '60+',
    ROTATIVO: 'P'
  };

  function icone(nome) {
    return '<svg class="icone" aria-hidden="true" focusable="false"><use href="#i-' + nome + '"></use></svg>';
  }

  var estado = {
    vagas: [],
    categoria: '',
    bairro: '',
    posicao: null,       // {lat, lng} do aparelho
    referencia: null,    // {lat, lng, nome} do endereço buscado
    selecionada: null,
    centralizouUsuario: false
  };

  var $ = function (id) { return document.getElementById(id); };

  function montar() {

  /* ---------- Mapa ---------- */

  var mapa = L.map('mapa', { zoomControl: false }).setView(CENTRO_NITEROI, 14);
  // No celular o zoom é por gesto; no computador mostra os botões.
  if (!L.Browser.mobile) L.control.zoom({ position: 'topright' }).addTo(mapa);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
  }).addTo(mapa);

  var grupo = L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 45 });
  mapa.addLayer(grupo);

  var marcadorUsuario = null;
  var circuloPrecisao = null;

  /** Tamanho dos marcadores: maiores no modo "letra maior e alto contraste" (a ponta segue no local exato). */
  function medidasMarcador() {
    return document.documentElement.classList.contains('modo-acessivel')
      ? { iconSize: [52, 60], iconAnchor: [26, 58] }
      : { iconSize: [40, 48], iconAnchor: [20, 46] };
  }

  function iconeDestino() {
    return L.divIcon(Object.assign({
      className: 'marcador marcador-busca',
      html: '<span class="marcador-corpo"><span class="marcador-icone">' + icone('pin') + '</span></span>'
    }, medidasMarcador()));
  }

  function iconeVaga(categoria) {
    return L.divIcon(Object.assign({
      className: 'marcador marcador-' + categoria.toLowerCase(),
      html: '<span class="marcador-corpo"><span class="marcador-icone" aria-hidden="true">' + SIMBOLO[categoria] + '</span></span>'
    }, medidasMarcador()));
  }

  function endereco(v) {
    return v.logradouro + (v.numero ? ', ' + v.numero : '');
  }

  /* Estatísticas de uso (anônimas, agregadas por dia no servidor) para o painel administrativo. */
  var vistasNestaSessao = {};
  function registrar(tipo, vagaId, extras) {
    if (!Vagas.registrarEvento) return;
    if (tipo === 'vaga_vista') {
      if (vistasNestaSessao[vagaId]) return;   // abrir a mesma vaga de novo não infla a contagem
      vistasNestaSessao[vagaId] = true;
    }
    var dados = Object.assign({ tipo: tipo, vaga_id: vagaId || 0 }, extras || {});
    Promise.resolve().then(function () { return Vagas.registrarEvento(dados); }).catch(function () { /* sem conexão: perde só a contagem */ });
  }

  /* ---------- Distância ---------- */

  function distanciaM(a, b) {
    var R = 6371000, rad = Math.PI / 180;
    var dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
    var h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
  }

  function formatarDistancia(m) {
    if (m < 1000) return Math.round(m / 10) * 10 + ' m';
    return (m / 1000).toFixed(1).replace('.', ',') + ' km';
  }

  /* ---------- Filtros ---------- */

  function vagasFiltradas() {
    return estado.vagas.filter(function (v) {
      return (!estado.categoria || v.categoria === estado.categoria)
          && (!estado.bairro || v.bairro === estado.bairro);
    });
  }

  function renderizar(ajustarZoom) {
    var lista = vagasFiltradas();
    grupo.clearLayers();
    lista.forEach(function (v) {
      if (!v._marcador) {
        v._marcador = L.marker([v.lat, v.lng], {
          icon: iconeVaga(v.categoria),
          title: ROTULO[v.categoria] + ' — ' + endereco(v),
          alt: ROTULO[v.categoria] + ' — ' + endereco(v),
          keyboard: true,
          riseOnHover: true
        }).on('click', function () { selecionar(v); });
      }
      grupo.addLayer(v._marcador);
    });

    if (ajustarZoom && lista.length) {
      mapa.fitBounds(L.latLngBounds(lista.map(function (v) { return [v.lat, v.lng]; })), { padding: [60, 60], maxZoom: 17 });
    }

    aviso(lista.length ? '' : 'Nenhuma vaga com esses filtros.');
    renderizarLista(lista);
  }

  var RAIO_BUSCA = 1000; // metros ao redor do endereço buscado
  var DICA_PADRAO = $('dica-lista').textContent;

  /** Distâncias são medidas a partir do endereço buscado ou, sem busca, da posição do aparelho. */
  function pontoReferencia() {
    return estado.referencia || estado.posicao;
  }

  function renderizarLista(lista) {
    var ref = pontoReferencia();
    var dica = $('dica-lista');
    if (ref) {
      lista = lista.map(function (v) { v._dist = distanciaM(ref, v); return v; })
                   .sort(function (a, b) { return a._dist - b._dist; });
    }

    if (estado.referencia) {
      // Vagas a até 1 km do destino; se houver poucas, completa com as mais próximas.
      var perto = lista.filter(function (v) { return v._dist <= RAIO_BUSCA; });
      if (perto.length >= 3 || perto.length === lista.length) {
        lista = perto;
        dica.hidden = true;
      } else {
        lista = lista.slice(0, Math.max(5, perto.length));
        dica.textContent = perto.length
          ? 'Há poucas vagas a menos de 1 km do destino — incluímos as mais próximas.'
          : 'Não há vagas a menos de 1 km do destino. Estas são as mais próximas.';
        dica.hidden = false;
      }
      $('t-lista-texto').textContent = 'Perto de ' + estado.referencia.nome;
    } else {
      dica.textContent = DICA_PADRAO;
      dica.hidden = !!estado.posicao;
      $('t-lista-texto').textContent = estado.posicao ? 'Mais próximas' : 'Vagas';
    }
    $('contador').textContent = lista.length;

    var ol = $('lista-vagas');
    ol.textContent = '';
    if (!lista.length) {
      var vazio = document.createElement('li');
      vazio.className = 'lista-vazia';
      vazio.textContent = 'Nenhuma vaga encontrada com esses filtros.';
      ol.appendChild(vazio);
      return;
    }
    lista.slice(0, 200).forEach(function (v) {
      var li = document.createElement('li');
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'item-vaga';

      var avatar = document.createElement('span');
      avatar.className = 'item-avatar item-avatar-' + v.categoria.toLowerCase();
      avatar.innerHTML = SIMBOLO[v.categoria];
      avatar.setAttribute('role', 'img');
      avatar.setAttribute('aria-label', ROTULO[v.categoria]);

      var texto = document.createElement('span');
      texto.className = 'item-texto';
      var t1 = document.createElement('strong');
      t1.textContent = endereco(v);
      var t2 = document.createElement('small');
      t2.textContent = v.bairro;
      texto.append(t1, t2);

      b.append(avatar, texto);
      if (ref) {
        var dist = document.createElement('span');
        dist.className = 'item-distancia';
        dist.textContent = formatarDistancia(v._dist);
        b.appendChild(dist);
      }
      b.insertAdjacentHTML('beforeend', icone('seta-dir'));
      b.addEventListener('click', function () { selecionar(v, true); });
      li.appendChild(b);
      ol.appendChild(li);
    });
  }

  /** Só os filtros dos tipos de vaga que a pessoa vê (perfis aprovados); com um tipo só, os filtros somem. */
  function ajustarFiltros(categorias) {
    if (!categorias) {   // lista salva no aparelho por versões anteriores
      categorias = estado.vagas.map(function (v) { return v.categoria; })
        .filter(function (c, i, todas) { return todas.indexOf(c) === i; });
    }
    document.querySelectorAll('.chip[data-categoria]').forEach(function (c) {
      if (c.dataset.categoria) c.hidden = categorias.indexOf(c.dataset.categoria) === -1;
    });
    document.querySelector('.chips').hidden = categorias.length < 2;
    if (estado.categoria && categorias.indexOf(estado.categoria) === -1) {
      estado.categoria = '';
      document.querySelectorAll('.chip').forEach(function (c) { c.setAttribute('aria-checked', String(!c.dataset.categoria)); });
    }
  }

  document.querySelectorAll('.chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      document.querySelectorAll('.chip').forEach(function (c) { c.setAttribute('aria-checked', 'false'); });
      chip.setAttribute('aria-checked', 'true');
      estado.categoria = chip.dataset.categoria;
      renderizar(false);
    });
  });

  $('filtro-bairro').addEventListener('change', function (ev) {
    limparReferencia(true);
    campo.value = '';
    botaoLimpar.hidden = true;
    estado.bairro = ev.target.value;
    renderizar(true);
  });

  /* ---------- Busca de endereço ----------
   * Enquanto a pessoa digita: sugestões locais (bairros e ruas com vagas), sem internet.
   * Ao confirmar: busca o endereço no servidor (Vagas.buscarEndereco), que consulta o OpenStreetMap. */

  var campo = $('busca-campo');
  var caixaSug = $('busca-sugestoes');
  var botaoLimpar = $('busca-limpar');
  var marcadorBusca = null;
  var circuloBusca = null;
  var opcoes = [];      // opções navegáveis: [{el, acao}]
  var ativa = -1;
  var buscaAtual = 0;   // descarta respostas de buscas antigas

  function normalizar(s) {
    return String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  }

  /** Distância de edição com troca de letras vizinhas ("cs" ↔ "sc" conta como 1). */
  function distanciaEdicao(a, b) {
    var d = [];
    for (var i = 0; i <= a.length; i++) { d[i] = [i]; }
    for (var j = 0; j <= b.length; j++) { d[0][j] = j; }
    for (i = 1; i <= a.length; i++) {
      for (j = 1; j <= b.length; j++) {
        var custo = a[i - 1] === b[j - 1] ? 0 : 1;
        d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + custo);
        if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) {
          d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
        }
      }
    }
    return d[a.length][b.length];
  }

  function plural(n, um, varios) {
    return n + ' ' + (n === 1 ? um : varios);
  }

  /* Índice de ruas e lugares de Niterói (OpenStreetMap), carregado na primeira vez que a busca é usada. */
  var indice = null;          // { bairros: [{nome, lat, lng}], itens: [{nome, bairro, lat, lng, tipo, palavras}] }
  var carregandoIndice = null;

  function prepararIndice() {
    if (indice || carregandoIndice || !Vagas.carregarIndiceRuas) return;
    carregandoIndice = Vagas.carregarIndiceRuas().then(function (d) {
      var centros = d.bairros.map(function (nome) { return { nome: nome, lat: 0, lng: 0, n: 0 }; });
      var itens = d.itens.map(function (i) {
        var c = centros[i[1]];
        c.lat += i[2]; c.lng += i[3]; c.n++;
        return { nome: i[0], bairro: d.bairros[i[1]], lat: i[2], lng: i[3], tipo: i[4], palavras: palavrasDe(i[0]) };
      });
      indice = {
        itens: itens,
        bairros: centros.filter(function (c) { return c.n; }).map(function (c) {
          return { nome: c.nome, lat: c.lat / c.n, lng: c.lng / c.n, palavras: palavrasDe(c.nome) };
        })
      };
      if (!caixaSug.hidden) atualizarSugestoes();
    }).catch(function () { carregandoIndice = null; /* tenta de novo na próxima busca */ });
  }

  var ABREVIACOES = {
    av: 'avenida', avda: 'avenida', r: 'rua', tv: 'travessa', trav: 'travessa', est: 'estrada', estr: 'estrada',
    al: 'alameda', pc: 'praca', pca: 'praca', rod: 'rodovia', lgo: 'largo', dr: 'doutor', dra: 'doutora',
    prof: 'professor', profa: 'professora', gal: 'general', gen: 'general', cel: 'coronel', alm: 'almirante',
    pres: 'presidente', eng: 'engenheiro', cap: 'capitao', ten: 'tenente', mal: 'marechal', min: 'ministro',
    des: 'desembargador', sta: 'santa', sto: 'santo'
  };
  var CONECTIVOS = { de: 1, da: 1, do: 1, dos: 1, das: 1, e: 1 };

  /** Palavras de um nome, sem acento e sem "de/da/do" (variam entre a placa e o cadastro). */
  function palavrasDe(texto) {
    return normalizar(texto).split(/[^a-z0-9]+/).filter(function (p) { return p && !CONECTIVOS[p]; });
  }

  function termosDaBusca(q) {
    return palavrasDe(q).filter(function (t) { return !/^\d+$/.test(t); }).map(function (t) { return ABREVIACOES[t] || t; });
  }

  /**
   * Quanto o nome combina com os termos: -1 se algum termo não aparece; senão, soma por termo
   * (3 = palavra igual, 2 = começo de palavra, 1 = com um erro de digitação — dois em termos longos),
   * mais SEM_ERRO quando nenhum termo precisou da tolerância a erro.
   */
  var SEM_ERRO = 100;
  function pontuar(termos, palavras) {
    var total = 0;
    var comErro = false;
    for (var k = 0; k < termos.length; k++) {
      var t = termos[k];
      var melhor = -1;
      for (var w = 0; w < palavras.length && melhor < 3; w++) {
        var p = palavras[w];
        if (p === t) melhor = 3;
        else if (p.indexOf(t) === 0) melhor = Math.max(melhor, 2);
        else if (melhor < 1 && t.length >= 5 && distanciaEdicao(t, p.slice(0, t.length)) <= (t.length >= 8 ? 2 : 1)) melhor = 1;
      }
      if (melhor < 0) return -1;
      if (melhor === 1) comErro = true;
      total += melhor;
    }
    return total + (comErro ? 0 : SEM_ERRO);
  }

  /** Itens que combinam com os termos, com a pontuação de cada um. */
  function pontuados(lista, termos, nomeDe) {
    return lista
      .map(function (item) { return { item: item, pontos: pontuar(termos, item.palavras || palavrasDe(nomeDe(item))) }; })
      .filter(function (r) { return r.pontos >= 0; });
  }

  /** Os melhores, por pontuação e, no empate, pelo nome mais curto. */
  function melhores(candidatos, nomeDe, minimo, limite) {
    return candidatos
      .filter(function (r) { return r.pontos >= minimo; })
      .sort(function (a, b) {
        return b.pontos - a.pontos || nomeDe(a.item).length - nomeDe(b.item).length || nomeDe(a.item).localeCompare(nomeDe(b.item), 'pt-BR');
      })
      .slice(0, limite)
      .map(function (r) { return r.item; });
  }

  function sugestoesLocais(q) {
    var termos = termosDaBusca(q);
    if (!termos.length) return { bairros: [], ruasComVagas: [], ruas: [] };

    // Bairros e ruas que têm vagas cadastradas
    var vagasPorBairro = {};
    var ruasVagas = {};
    estado.vagas.forEach(function (v) {
      vagasPorBairro[v.bairro] = (vagasPorBairro[v.bairro] || 0) + 1;
      var r = ruasVagas[v.logradouro + '|' + v.bairro] ||
        (ruasVagas[v.logradouro + '|' + v.bairro] = { nome: v.logradouro, bairro: v.bairro, lat: 0, lng: 0, n: 0 });
      r.lat += v.lat; r.lng += v.lng; r.n++;
    });

    // Bairros: os com vagas filtram o mapa; os demais viram "perto de" pelo centro do bairro.
    var bairros = Object.keys(vagasPorBairro).map(function (b) { return { nome: b, vagas: vagasPorBairro[b] }; });
    if (indice) {
      var jaTem = {};
      bairros.forEach(function (b) { jaTem[normalizar(b.nome)] = true; });
      indice.bairros.forEach(function (b) {
        if (!jaTem[normalizar(b.nome)]) bairros.push({ nome: b.nome, vagas: 0, lat: b.lat, lng: b.lng });
      });
    }
    var nome = function (x) { return x.nome; };
    var cBairros = pontuados(bairros, termos, nome);
    var cRuasVagas = pontuados(Object.keys(ruasVagas).map(function (k) {
      var r = ruasVagas[k];
      return { nome: r.nome, bairro: r.bairro, n: r.n, lat: r.lat / r.n, lng: r.lng / r.n };
    }), termos, nome);
    var cRuas = indice ? pontuados(indice.itens, termos, nome) : [];

    // Se algo bate sem erro de digitação, os "parecidos" ficam de fora:
    // "barcas" mostra o Barcas Shopping, não a Rua das Garças.
    var temExato = cBairros.concat(cRuasVagas, cRuas).some(function (r) { return r.pontos >= SEM_ERRO; });
    var minimo = temExato ? SEM_ERRO : 0;

    bairros = melhores(cBairros, nome, minimo, 3);
    var ruasComVagas = melhores(cRuasVagas, nome, minimo, 4);
    var mostradas = {};
    ruasComVagas.forEach(function (r) { mostradas[normalizar(r.nome + '|' + r.bairro)] = true; });
    var ruas = melhores(cRuas, nome, minimo, 10)
      .filter(function (i) { return !mostradas[normalizar(i.nome + '|' + i.bairro)]; })
      .slice(0, 6);
    return { bairros: bairros, ruasComVagas: ruasComVagas, ruas: ruas };
  }

  function abrirSugestoes() {
    caixaSug.hidden = false;
    campo.setAttribute('aria-expanded', 'true');
  }

  function fecharSugestoes() {
    caixaSug.hidden = true;
    campo.setAttribute('aria-expanded', 'false');
    campo.removeAttribute('aria-activedescendant');
    ativa = -1;
  }

  /** grupos: [{titulo, itens: [{icone, bolha, titulo, detalhe, acao}]}] ou [{info, icone, carregando}] */
  function mostrarSugestoes(grupos) {
    caixaSug.textContent = '';
    opcoes = [];
    ativa = -1;
    grupos.forEach(function (g, gi) {
      if (g.info) {
        var info = document.createElement('p');
        info.className = 'sugestao-info';
        info.setAttribute('role', 'status');
        info.innerHTML = icone(g.carregando ? 'atualizar' : (g.icone || 'info'));
        if (g.carregando) info.firstChild.classList.add('girando');
        info.append(g.info);
        caixaSug.appendChild(info);
        return;
      }
      if (!g.itens.length) return;
      var bloco = document.createElement('div');
      bloco.setAttribute('role', 'group');
      var titulo = document.createElement('p');
      titulo.className = 'sugestao-titulo';
      titulo.id = 'sug-grupo-' + gi;
      titulo.textContent = g.titulo;
      bloco.setAttribute('aria-labelledby', titulo.id);
      var ul = document.createElement('ul');
      ul.className = 'sugestao-lista';
      ul.setAttribute('role', 'presentation');
      g.itens.forEach(function (it) {
        var li = document.createElement('li');
        li.className = 'sugestao';
        li.id = 'sug-' + opcoes.length;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.innerHTML = '<span class="bolha ' + (it.bolha || '') + '">' + icone(it.icone) + '</span>';
        var t = document.createElement('span');
        t.className = 'sugestao-texto';
        var forte = document.createElement('strong');
        forte.textContent = it.titulo;
        var pequeno = document.createElement('small');
        pequeno.textContent = it.detalhe;
        t.append(forte, pequeno);
        li.appendChild(t);
        // mousedown sem efeito padrão: o campo não perde o foco antes do clique.
        li.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
        li.addEventListener('click', it.acao);
        opcoes.push({ el: li, acao: it.acao });
        ul.appendChild(li);
      });
      bloco.append(titulo, ul);
      caixaSug.appendChild(bloco);
    });
    abrirSugestoes();
  }

  function marcarAtiva(i) {
    if (!opcoes.length) return;
    if (ativa >= 0) opcoes[ativa].el.setAttribute('aria-selected', 'false');
    ativa = (i + opcoes.length) % opcoes.length;
    var el = opcoes[ativa].el;
    el.setAttribute('aria-selected', 'true');
    campo.setAttribute('aria-activedescendant', el.id);
    el.scrollIntoView({ block: 'nearest' });
  }

  function atualizarSugestoes() {
    var q = campo.value.trim();
    botaoLimpar.hidden = !campo.value;
    prepararIndice();
    if (q.length < 2) { fecharSugestoes(); return; }
    var l = sugestoesLocais(q);

    var grupoBairros = { titulo: 'Bairros', itens: l.bairros.map(function (b) {
      return b.vagas
        ? { icone: 'mapa', titulo: b.nome, detalhe: plural(b.vagas, 'vaga', 'vagas'), acao: function () { escolherBairro(b.nome); } }
        : { icone: 'mapa', titulo: b.nome, detalhe: 'Ver as vagas mais próximas',
            acao: function () { definirReferencia({ lat: b.lat, lng: b.lng, nome: b.nome }); } };
    }) };
    var grupoRuasVagas = { titulo: 'Ruas com vagas', itens: l.ruasComVagas.map(function (r) {
      return { icone: 'pin', titulo: r.nome, detalhe: r.bairro + ' · ' + plural(r.n, 'vaga', 'vagas'),
               acao: function () { definirReferencia({ lat: r.lat, lng: r.lng, nome: r.nome }); } };
    }) };
    var grupoRuas = { titulo: 'Ruas e lugares de Niterói', itens: l.ruas.map(function (i) {
      return { icone: i.tipo === 1 ? 'bandeira' : 'pin', bolha: i.tipo === 1 ? 'bolha-verde' : '',
               titulo: i.nome, detalhe: i.bairro,
               acao: function () { definirReferencia({ lat: i.lat, lng: i.lng, nome: i.nome }); } };
    }) };
    var temNumero = /\d/.test(q);
    var grupoBuscar = { titulo: temNumero ? 'Endereço com número' : 'Não achou? Busque no mapa', itens: [] };
    if (q.length >= 3 && Vagas.buscarEndereco) {
      grupoBuscar.itens.push({
        icone: 'busca', bolha: 'bolha-ambar', titulo: 'Buscar “' + q + '”',
        detalhe: temNumero ? 'Localiza o número exato' : 'Endereço, número ou ponto de referência',
        acao: function () { buscarEndereco(q); }
      });
    }

    // Com número ("Rua X, 500"), a busca exata vem primeiro: o índice local não tem numeração.
    var grupos = temNumero
      ? [grupoBuscar, grupoRuasVagas, grupoRuas, grupoBairros]
      : [grupoBairros, grupoRuasVagas, grupoRuas, grupoBuscar];
    if (grupos.every(function (g) { return !g.itens.length; })) {
      mostrarSugestoes([{ info: carregandoIndice && !indice ? 'Carregando ruas de Niterói…' : 'Continue digitando para buscar o endereço.',
                          icone: 'busca', carregando: !!(carregandoIndice && !indice) }]);
      return;
    }
    mostrarSugestoes(grupos);
  }

  async function buscarEndereco(q) {
    if (q.length < 3) { mostrarSugestoes([{ info: 'Digite pelo menos 3 letras.', icone: 'info' }]); return; }
    if (!Vagas.buscarEndereco) return;
    var esta = ++buscaAtual;
    mostrarSugestoes([{ info: 'Buscando o endereço… pode levar alguns segundos.', carregando: true }]);
    try {
      var d = await Vagas.buscarEndereco(q);
      if (esta !== buscaAtual) return;
      if (!d.ok) {
        mostrarSugestoes([{ info: d.erro || 'Não foi possível buscar agora.', icone: 'alerta' }]);
      } else if (!d.resultados.length) {
        mostrarSugestoes([{ info: 'Nenhum lugar encontrado em Niterói. Tente incluir o número ou o bairro.', icone: 'info' }]);
      } else {
        mostrarSugestoes([{ titulo: 'Resultados para “' + q + '”', itens: d.resultados.map(function (r) {
          return { icone: 'pin', bolha: 'bolha-ambar', titulo: r.titulo, detalhe: r.detalhe,
                   acao: function () { definirReferencia({ lat: r.lat, lng: r.lng, nome: r.titulo }); } };
        }) }]);
        marcarAtiva(0);
      }
    } catch (e) {
      if (esta === buscaAtual) {
        mostrarSugestoes([{ info: 'Sem conexão para buscar endereços. Você ainda pode buscar por bairro ou rua com vagas.', icone: 'alerta' }]);
      }
    }
  }

  function escolherBairro(nome) {
    limparReferencia(true);
    estado.bairro = nome;
    $('filtro-bairro').value = nome;
    campo.value = nome;
    botaoLimpar.hidden = false;
    fecharSugestoes();
    campo.blur();
    renderizar(true);
  }

  function definirReferencia(r) {
    estado.referencia = r;
    if (estado.bairro) { estado.bairro = ''; $('filtro-bairro').value = ''; }
    campo.value = r.nome;
    botaoLimpar.hidden = false;
    fecharSugestoes();
    campo.blur();

    if (!marcadorBusca) {
      circuloBusca = L.circle([r.lat, r.lng], { radius: RAIO_BUSCA, className: 'raio-busca', interactive: false }).addTo(mapa);
      marcadorBusca = L.marker([r.lat, r.lng], {
        icon: iconeDestino(),
        title: 'Destino: ' + r.nome, keyboard: false, interactive: false, zIndexOffset: 900
      }).addTo(mapa);
    } else {
      marcadorBusca.setLatLng([r.lat, r.lng]);
      circuloBusca.setLatLng([r.lat, r.lng]);
    }

    renderizar(false);
    abrirPainel('painel-lista');

    // Enquadra o destino e as 5 vagas mais próximas, deixando espaço para o painel.
    var proximas = vagasFiltradas()
      .map(function (v) { return { v: v, d: distanciaM(r, v) }; })
      .sort(function (a, b) { return a.d - b.d; })
      .slice(0, 5);
    registrar('busca');
    // Considera todas as vagas (não só as do filtro da tela) para saber se falta vaga de verdade ali.
    var maisPerto = estado.vagas.reduce(function (min, v) { return Math.min(min, distanciaM(r, v)); }, Infinity);
    if (maisPerto > RAIO_BUSCA) {
      // Ninguém tem vaga a menos de 1 km deste destino: sinal para a Nittrans de onde faltam vagas.
      registrar('busca_sem_vaga', 0, { lat: r.lat, lng: r.lng, nome: r.nome });
    }
    var pontos = [[r.lat, r.lng]].concat(proximas.map(function (p) { return [p.v.lat, p.v.lng]; }));
    var largo = window.innerWidth >= 760;
    mapa.fitBounds(L.latLngBounds(pontos), {
      paddingTopLeft: [largo ? 430 : 30, 160],
      paddingBottomRight: [30, largo ? 30 : Math.round(window.innerHeight * 0.55)],
      maxZoom: 17
    });
  }

  function limparReferencia(semRenderizar) {
    if (!estado.referencia) return;
    estado.referencia = null;
    if (marcadorBusca) {
      mapa.removeLayer(marcadorBusca);
      mapa.removeLayer(circuloBusca);
      marcadorBusca = circuloBusca = null;
    }
    if (!semRenderizar) renderizarLista(vagasFiltradas());
  }

  var esperaDigitacao = null;
  campo.addEventListener('input', function () {
    clearTimeout(esperaDigitacao);
    esperaDigitacao = setTimeout(atualizarSugestoes, 90);
  });
  campo.addEventListener('focus', function () {
    prepararIndice();
    if (campo.value.trim().length >= 2) atualizarSugestoes();
  });
  campo.addEventListener('blur', function () { setTimeout(fecharSugestoes, 200); });
  campo.addEventListener('keydown', function (ev) {
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      if (caixaSug.hidden) atualizarSugestoes();
      marcarAtiva(ativa + 1);
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      marcarAtiva(ativa - 1);
    } else if (ev.key === 'Escape' && !caixaSug.hidden) {
      ev.stopPropagation();
      fecharSugestoes();
    }
  });
  $('form-busca').addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (ativa >= 0 && !caixaSug.hidden) { opcoes[ativa].acao(); return; }
    buscarEndereco(campo.value.trim());
  });
  botaoLimpar.addEventListener('click', function () {
    campo.value = '';
    botaoLimpar.hidden = true;
    fecharSugestoes();
    limparReferencia();
    if (estado.bairro) {
      estado.bairro = '';
      $('filtro-bairro').value = '';
      renderizar(false);
    }
    campo.focus();
  });

  /* ---------- Painéis ---------- */

  function abrirPainel(id) {
    ['painel-lista', 'painel-detalhe', 'painel-rota'].forEach(function (p) { $(p).hidden = p !== id; });
    $('btn-lista').setAttribute('aria-expanded', String(id === 'painel-lista'));
    if (id !== 'painel-detalhe') destacar(null);
  }

  function fecharPainel(id) {
    $(id).hidden = true;
    if (id === 'painel-lista') $('btn-lista').setAttribute('aria-expanded', 'false');
    if (id === 'painel-detalhe') destacar(null);
  }

  document.querySelectorAll('[data-fechar]').forEach(function (b) {
    b.addEventListener('click', function () { fecharPainel(b.dataset.fechar); });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      if (navegando) { encerrarNavegacao(); return; }
      fecharPainel('painel-detalhe');
      fecharPainel('painel-lista');
    }
  });

  $('btn-lista').addEventListener('click', function () {
    if ($('painel-lista').hidden) abrirPainel('painel-lista'); else fecharPainel('painel-lista');
  });

  /* ---------- Detalhes da vaga ---------- */

  function destacar(v) {
    if (estado.selecionada && estado.selecionada._marcador) {
      var anterior = estado.selecionada._marcador.getElement();
      if (anterior) anterior.classList.remove('marcador-ativo');
    }
    estado.selecionada = v;
    if (v && v._marcador) {
      var el = v._marcador.getElement();
      if (el) el.classList.add('marcador-ativo');
    }
  }

  function selecionar(v, centralizar) {
    var selo = $('det-categoria');
    selo.className = 'selo selo-' + v.categoria.toLowerCase();
    selo.innerHTML = SIMBOLO[v.categoria];
    selo.append(' ' + ROTULO[v.categoria]);
    $('det-titulo').textContent = endereco(v);
    $('det-bairro').textContent = v.bairro;

    var ref = pontoReferencia();
    $('det-distancia').hidden = !ref;
    if (ref) {
      $('det-distancia-texto').textContent = formatarDistancia(distanciaM(ref, v)) + (estado.referencia ? ' do destino' : ' de você');
    }
    $('det-atualizado').textContent = 'Atualizada em ' + v.atualizado;

    var ll = v.lat + ',' + v.lng;
    $('det-waze').href = 'https://waze.com/ul?ll=' + ll + '&navigate=yes';
    $('det-gmaps').href = 'https://www.google.com/maps/dir/?api=1&destination=' + ll + '&travelmode=driving';

    $('form-relato').hidden = true;
    $('relato-ok').hidden = true;
    $('btn-relatar').hidden = false;
    $('relato-vaga').value = v.id;

    abrirPainel('painel-detalhe');
    destacar(v);
    registrar('vaga_vista', v.id);
    if (centralizar) {
      grupo.zoomToShowLayer(v._marcador, function () { mapa.panTo([v.lat, v.lng]); destacar(v); });
    }
    $('det-titulo').setAttribute('tabindex', '-1');
    $('det-titulo').focus({ preventScroll: true });
  }

  // Ao reagrupar marcadores (zoom), o elemento é recriado: reaplica o destaque.
  mapa.on('zoomend moveend', function () {
    if (estado.selecionada && !$('painel-detalhe').hidden) destacar(estado.selecionada);
  });

  /* ---------- Relatos ---------- */

  $('btn-relatar').addEventListener('click', function () {
    $('btn-relatar').hidden = true;
    $('form-relato').hidden = false;
    $('relato-tipo').focus();
  });

  $('form-relato').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    var form = ev.target;
    var erro = form.querySelector('[data-erro]');
    Vagas.mostrarErro(erro, '');
    if (!$('relato-tipo').value) { Vagas.mostrarErro(erro, 'Selecione o tipo de problema.'); return; }

    var botao = form.querySelector('[type="submit"]');
    botao.disabled = true;
    try {
      var d = await Vagas.enviarRelato(new FormData(form));
      if (d.ok) {
        form.reset();
        form.hidden = true;
        $('relato-ok').textContent = d.mensagem;
        $('relato-ok').hidden = false;
      } else {
        Vagas.mostrarErro(erro, d.erro);
      }
    } catch (e) {
      Vagas.mostrarErro(erro, 'Sem conexão. Tente novamente.');
    } finally {
      botao.disabled = false;
    }
  });

  /* ---------- Localização do usuário (GPS do aparelho; nada é enviado ao servidor) ---------- */

  function atualizarPosicao(pos) {
    var p = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    estado.posicao = p;
    if (esperandoPosicao.length) {
      var esperando = esperandoPosicao;
      esperandoPosicao = [];
      esperando.forEach(function (e) { e.ok(p); });
    }
    if (!marcadorUsuario) {
      circuloPrecisao = L.circle([p.lat, p.lng], { radius: pos.coords.accuracy, className: 'precisao', interactive: false }).addTo(mapa);
      marcadorUsuario = L.marker([p.lat, p.lng], {
        icon: L.divIcon({ className: 'voce-marcador', html: '<span class="voce-pulso"></span><span class="voce-ponto"></span>', iconSize: [24, 24], iconAnchor: [12, 12] }),
        interactive: false, keyboard: false, zIndexOffset: 1000
      }).addTo(mapa);
    } else {
      marcadorUsuario.setLatLng([p.lat, p.lng]);
      circuloPrecisao.setLatLng([p.lat, p.lng]).setRadius(pos.coords.accuracy);
    }
    $('btn-localizar').classList.add('ativo');
    if (!estado.centralizouUsuario) {
      estado.centralizouUsuario = true;
      if (!rotaAtual) mapa.setView([p.lat, p.lng], 16);
      aviso('');
    }
    if (navegando) aoMoverNaRota(p);
    else renderizarLista(vagasFiltradas());
  }

  function erroPosicao(err) {
    $('btn-localizar').classList.remove('ativo');
    var msg = err.code === 1
      ? 'Permissão de localização negada. Ative a localização nas configurações do aparelho.'
      : (err.message && err.code === -1 ? err.message : 'Não foi possível obter sua localização.');
    aviso(msg);
  }

  var observandoPosicao = false;
  $('btn-localizar').addEventListener('click', function () {
    // Voltar para "perto de mim" desfaz a busca por endereço.
    if (estado.referencia) {
      limparReferencia();
      campo.value = '';
      botaoLimpar.hidden = true;
    }
    if (estado.posicao) { mapa.setView([estado.posicao.lat, estado.posicao.lng], 16); }
    garantirLocalizacao();
  });

  var gps = Promise.resolve(null);   // promessa da função que desliga o GPS
  var precisaoAtual = false;
  var retomarGps = false;

  function garantirLocalizacao() {
    if (observandoPosicao) return;
    observandoPosicao = true;
    precisaoAtual = navegando;   // alta precisão (mais bateria) só na navegação
    if (!estado.posicao) aviso('Obtendo sua localização…');
    gps = Promise.resolve(Vagas.observarPosicao(atualizarPosicao, function (err) {
      // Demora ou sinal fraco (túnel, prédios) não encerra o acompanhamento: o GPS volta sozinho.
      var definitivo = err.code === 1 || err.code === -1;
      if (!definitivo && estado.posicao) {
        if (navegando && Date.now() - ultimoAvisoSinal > 30000) {
          ultimoAvisoSinal = Date.now();
          aviso('Sinal de GPS fraco…');
        }
        return;
      }
      if (definitivo) observandoPosicao = false;
      erroPosicao(err);
      var esperando = esperandoPosicao;
      esperandoPosicao = [];
      esperando.forEach(function (e) { e.falhou(); });
    }, precisaoAtual));
  }
  var ultimoAvisoSinal = 0;

  /** Desliga o GPS. Devolve se ele estava ligado. */
  function pausarLocalizacao() {
    if (!observandoPosicao) return false;
    observandoPosicao = false;
    gps.then(function (parar) { if (parar) parar(); });
    return true;
  }

  /** Ao entrar ou sair da navegação, religa o GPS na precisão certa. */
  function ajustarPrecisao() {
    if (observandoPosicao && precisaoAtual !== navegando && pausarLocalizacao()) garantirLocalizacao();
  }

  // Fora da tela (segundo plano, outra aba ou outra tela do app) o GPS fica desligado; volta ao retornar.
  function pausarGps() { retomarGps = pausarLocalizacao() || retomarGps; }
  function retomarGpsSePreciso() {
    if (retomarGps) { retomarGps = false; garantirLocalizacao(); }
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) pausarGps(); else retomarGpsSePreciso();
  });

  /* ---------- Rotas dentro do app ----------
   * O servidor calcula a rota (Valhalla, dados do OpenStreetMap) e devolve a linha e os passos em português.
   * No modo navegação o mapa segue o GPS, mostra a próxima manobra, fala as instruções, recalcula se a
   * pessoa sair do caminho e avisa na chegada. Sem trânsito em tempo real (para isso, Waze/Google Maps). */

  var rotaAtual = null;        // { dados, vaga, camadas, acumulado[], indice, avisados }
  var navegando = false;
  var seguirUsuario = true;
  var vozLigada = true;
  var foraDaRota = 0;
  var ultimoRecalculo = 0;
  var esperandoPosicao = [];   // quem aguarda a primeira posição do GPS

  function obterPosicao() {
    if (estado.posicao) return Promise.resolve(estado.posicao);
    return new Promise(function (ok, falha) {
      var item = {
        ok: ok,
        falhou: function () { falha(new Error('Ative a localização do aparelho para traçar a rota.')); }
      };
      esperandoPosicao.push(item);
      garantirLocalizacao();
      setTimeout(function () {
        var i = esperandoPosicao.indexOf(item);
        if (i !== -1) {
          esperandoPosicao.splice(i, 1);
          falha(new Error('Não foi possível obter sua localização. Tente de novo.'));
        }
      }, 20000);
    });
  }

  function formatarDuracao(segundos) {
    var min = Math.max(1, Math.round(segundos / 60));
    if (min < 60) return min + ' min';
    return Math.floor(min / 60) + ' h ' + (min % 60 ? (min % 60) + ' min' : '');
  }

  function formatarHora(data) {
    return String(data.getHours()).padStart(2, '0') + ':' + String(data.getMinutes()).padStart(2, '0');
  }

  /** Distância para ser falada: "200 metros", "1,5 quilômetro". */
  function falarDistancia(m) {
    if (m < 1000) return Math.max(50, Math.round(m / 50) * 50) + ' metros';
    var km = (m / 1000).toFixed(1).replace('.', ',').replace(',0', '');
    return km + (km === '1' ? ' quilômetro' : ' quilômetros');
  }

  function minusculaInicial(texto) {
    return texto.charAt(0).toLowerCase() + texto.slice(1);
  }

  function falar(texto) {
    if (!vozLigada || !Vagas.falar) return;
    try { Vagas.falar(texto); } catch (e) { /* sem voz neste aparelho */ }
  }

  function limparCamadasRota() {
    if (rotaAtual) rotaAtual.camadas.forEach(function (c) { mapa.removeLayer(c); });
  }

  function mostrarRota(dados, v, semEnquadrar) {
    limparCamadasRota();
    var linha = dados.linha;
    var borda = L.polyline(linha, { className: 'rota-borda', interactive: false }).addTo(mapa);
    var traco = L.polyline(linha, { className: 'rota-linha', interactive: false }).addTo(mapa);
    // Distância acumulada até cada ponto da linha (para saber quanto falta e onde está cada manobra).
    var acumulado = [0];
    for (var i = 1; i < linha.length; i++) {
      acumulado.push(acumulado[i - 1] + distanciaM({ lat: linha[i - 1][0], lng: linha[i - 1][1] }, { lat: linha[i][0], lng: linha[i][1] }));
    }
    rotaAtual = { dados: dados, vaga: v, camadas: [borda, traco], acumulado: acumulado, indice: 0, avisados: {} };

    $('rota-titulo').textContent = 'Rota até ' + endereco(v);
    $('rota-tempo').textContent = formatarDuracao(dados.duracao_s);
    $('rota-distancia').textContent = formatarDistancia(dados.distancia_m);
    $('rota-chegada').textContent = 'chegada às ' + formatarHora(new Date(Date.now() + dados.duracao_s * 1000));
    var ol = $('rota-passos');
    ol.textContent = '';
    dados.passos.forEach(function (p) {
      var li = document.createElement('li');
      li.innerHTML = '<span class="bolha">' + icone(p.icone) + '</span>';
      var texto = document.createElement('span');
      texto.className = 'passo-texto';
      texto.textContent = p.texto;
      li.appendChild(texto);
      if (p.distancia_m > 0) {
        var dist = document.createElement('small');
        dist.textContent = formatarDistancia(p.distancia_m);
        li.appendChild(dist);
      }
      ol.appendChild(li);
    });

    if (!navegando) abrirPainel('painel-rota');
    destacar(v);
    if (!semEnquadrar) {
      var largo = window.innerWidth >= 760;
      mapa.fitBounds(traco.getBounds(), {
        paddingTopLeft: [largo ? 430 : 30, 150],
        paddingBottomRight: [30, largo ? 30 : Math.round(window.innerHeight * 0.55)]
      });
    }
  }

  async function tracarRota(v) {
    var botao = $('btn-rota');
    var conteudo = botao.innerHTML;
    botao.disabled = true;
    botao.textContent = 'Calculando a rota…';
    try {
      var de = await obterPosicao();
      var d = await Vagas.buscarRota(de, { lat: v.lat, lng: v.lng });
      if (!d.ok) throw new Error(d.erro || 'Não foi possível calcular a rota.');
      mostrarRota(d.rota, v);
      registrar('rota', v.id);
    } catch (e) {
      var msg = e && e.message && !/fetch|network/i.test(e.message) ? e.message : 'Sem conexão para calcular a rota.';
      aviso(msg);
    } finally {
      botao.disabled = false;
      botao.innerHTML = conteudo;
    }
  }

  function cancelarRota() {
    encerrarNavegacao();
    limparCamadasRota();
    rotaAtual = null;
    fecharPainel('painel-rota');
  }

  function iniciarNavegacao() {
    if (!rotaAtual) return;
    registrar('navegacao', rotaAtual.vaga.id);
    navegando = true;
    seguirUsuario = true;
    foraDaRota = 0;
    rotaAtual.avisados = {};
    document.documentElement.classList.add('navegando');
    $('navegacao').hidden = false;
    $('nav-centralizar').hidden = true;
    if (Vagas.manterTelaLigada) Vagas.manterTelaLigada(true);
    ajustarPrecisao();
    garantirLocalizacao();
    falar(rotaAtual.dados.passos.length ? rotaAtual.dados.passos[0].texto : 'Siga a rota.');
    var inicio = estado.posicao ? [estado.posicao.lat, estado.posicao.lng] : rotaAtual.dados.linha[0];
    mapa.setView(inicio, 17);
    if (estado.posicao) aoMoverNaRota(estado.posicao); else atualizarPainelNavegacao(0);
  }

  function encerrarNavegacao() {
    if (!navegando) return;
    navegando = false;
    document.documentElement.classList.remove('navegando');
    $('navegacao').hidden = true;
    if (Vagas.manterTelaLigada) Vagas.manterTelaLigada(false);
    ajustarPrecisao();
    if (rotaAtual) abrirPainel('painel-rota');
  }

  /** Ponto da rota mais próximo da posição (projeção nos trechos, perto do último ponto conhecido). */
  function localizarNaRota(p) {
    var linha = rotaAtual.dados.linha;
    var ac = rotaAtual.acumulado;
    var inicio = foraDaRota ? 0 : Math.max(0, rotaAtual.indice - 10);
    var fim = foraDaRota ? linha.length - 1 : Math.min(linha.length - 1, rotaAtual.indice + 200);
    var cosLat = Math.cos(p.lat * Math.PI / 180);
    var melhor = { dist: Infinity, indice: rotaAtual.indice, percorrido: ac[rotaAtual.indice] || 0 };
    for (var i = inicio; i < fim; i++) {
      var ax = (linha[i][1] - p.lng) * 111320 * cosLat, ay = (linha[i][0] - p.lat) * 110540;
      var bx = (linha[i + 1][1] - p.lng) * 111320 * cosLat, by = (linha[i + 1][0] - p.lat) * 110540;
      var dx = bx - ax, dy = by - ay, tam2 = dx * dx + dy * dy;
      var t = tam2 ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / tam2)) : 0;
      var d = Math.sqrt(Math.pow(ax + t * dx, 2) + Math.pow(ay + t * dy, 2));
      if (d < melhor.dist) melhor = { dist: d, indice: i, percorrido: ac[i] + t * (ac[i + 1] - ac[i]) };
    }
    return melhor;
  }

  function aoMoverNaRota(p) {
    if (!rotaAtual || !navegando) return;
    var ac = rotaAtual.acumulado;
    var pos = localizarNaRota(p);

    if (pos.dist > 45) {
      // Fora do caminho por algumas leituras seguidas: recalcula (no máximo a cada 15 s).
      foraDaRota++;
      if (foraDaRota >= 3 && Date.now() - ultimoRecalculo > 15000) recalcularRota();
    } else {
      foraDaRota = 0;
      rotaAtual.indice = pos.indice;
    }
    if (seguirUsuario) mapa.panTo([p.lat, p.lng]);

    var restante = ac[ac.length - 1] - pos.percorrido;
    if (distanciaM(p, rotaAtual.vaga) < 30 || (pos.dist <= 45 && restante < 15)) {
      chegou();
      return;
    }
    atualizarPainelNavegacao(pos.percorrido);
  }

  function atualizarPainelNavegacao(percorrido) {
    var dados = rotaAtual.dados;
    var ac = rotaAtual.acumulado;
    var passos = dados.passos;
    if (!passos.length) return;
    // Próxima manobra: o primeiro passo que começa à frente do ponto atual.
    var k = passos.length - 1;
    for (var i = 1; i < passos.length; i++) {
      if (ac[passos[i].inicio] > percorrido + 5) { k = i; break; }
    }
    var proximo = passos[k];
    var ate = Math.max(0, (ac[proximo.inicio] || 0) - percorrido);
    $('nav-distancia').textContent = formatarDistancia(ate);
    $('nav-instrucao').textContent = proximo.texto;
    $('nav-icone').innerHTML = icone(proximo.icone);

    // Voz: um aviso antecipado ("Em 200 metros, …") e outro na hora da manobra.
    var av = rotaAtual.avisados;
    if (ate <= 300 && ate > 80 && !av[k + 'a']) {
      av[k + 'a'] = true;
      falar('Em ' + falarDistancia(ate) + ', ' + minusculaInicial(proximo.texto));
    }
    if (ate <= 60 && !av[k + 'b']) {
      av[k + 'a'] = av[k + 'b'] = true;
      falar(proximo.texto);
    }

    var total = ac[ac.length - 1];
    var restante = Math.max(0, total - percorrido);
    var segundos = dados.duracao_s * (total ? restante / total : 0);
    $('nav-tempo').textContent = formatarDuracao(segundos);
    $('nav-km').textContent = formatarDistancia(restante) + ' · chegada às ' + formatarHora(new Date(Date.now() + segundos * 1000));
  }

  async function recalcularRota() {
    ultimoRecalculo = Date.now();
    foraDaRota = 0;
    aviso('Recalculando a rota…');
    falar('Recalculando a rota.');
    try {
      var v = rotaAtual.vaga;
      var d = await Vagas.buscarRota(estado.posicao, { lat: v.lat, lng: v.lng });
      if (d.ok && navegando) {
        mostrarRota(d.rota, v, true);
        aviso('');
        if (estado.posicao) aoMoverNaRota(estado.posicao);
      }
    } catch (e) { /* sem conexão: tenta de novo na próxima saída do caminho */ }
  }

  function chegou() {
    var v = rotaAtual.vaga;
    registrar('chegada', v.id);
    falar('Você chegou à vaga. Não esqueça de deixar a credencial no painel do carro.');
    encerrarNavegacao();
    limparCamadasRota();
    rotaAtual = null;
    selecionar(v);
    aviso('Você chegou! Não esqueça a credencial no painel do carro.');
  }

  $('det-waze').addEventListener('click', function () { if (estado.selecionada) registrar('waze', estado.selecionada.id); });
  $('det-gmaps').addEventListener('click', function () { if (estado.selecionada) registrar('gmaps', estado.selecionada.id); });

  $('btn-rota').addEventListener('click', function () {
    if (estado.selecionada) tracarRota(estado.selecionada);
  });
  $('btn-iniciar').addEventListener('click', iniciarNavegacao);
  $('rota-fechar').addEventListener('click', cancelarRota);
  $('nav-encerrar').addEventListener('click', encerrarNavegacao);
  $('nav-som').addEventListener('click', function () {
    vozLigada = !vozLigada;
    var b = $('nav-som');
    b.setAttribute('aria-pressed', String(vozLigada));
    b.setAttribute('aria-label', vozLigada ? 'Desligar a voz' : 'Ligar a voz');
    b.querySelector('use').setAttribute('href', vozLigada ? '#i-som' : '#i-mudo');
  });
  $('nav-centralizar').addEventListener('click', function () {
    seguirUsuario = true;
    $('nav-centralizar').hidden = true;
    if (estado.posicao) mapa.setView([estado.posicao.lat, estado.posicao.lng], 17);
  });
  // Arrastar o mapa durante a navegação para de segui-lo até tocar em "centralizar".
  mapa.on('dragstart', function () {
    if (navegando) {
      seguirUsuario = false;
      $('nav-centralizar').hidden = false;
    }
  });

  /* ---------- Avisos ---------- */

  var temporizadorAviso = null;
  function aviso(msg) {
    var el = $('aviso-mapa');
    el.textContent = msg;
    el.hidden = !msg;
    clearTimeout(temporizadorAviso);
    if (msg) temporizadorAviso = setTimeout(function () { el.hidden = true; }, 6000);
  }

  /* ---------- Carga inicial ---------- */

  async function carregar() {
    aviso('Carregando vagas…');
    try {
      var d = await Vagas.carregarVagas();
      if (!d) return;

      // Mantém os marcadores já criados para as vagas que continuam iguais.
      var anteriores = {};
      estado.vagas.forEach(function (v) { anteriores[v.id] = v; });
      estado.vagas = d.vagas.map(function (v) {
        var a = anteriores[v.id];
        return a && a.lat === v.lat && a.lng === v.lng && a.categoria === v.categoria ? Object.assign(a, v) : v;
      });

      var sel = $('filtro-bairro');
      sel.length = 1;
      d.bairros.forEach(function (b) {
        var o = document.createElement('option');
        o.value = b;
        o.textContent = b;
        sel.appendChild(o);
      });
      sel.value = d.bairros.indexOf(estado.bairro) !== -1 ? estado.bairro : '';
      estado.bairro = sel.value;
      ajustarFiltros(d.categorias);
      aviso('');
      renderizar(false);
      if (d.offline) aviso('Sem conexão: mostrando as vagas salvas no aparelho.');
    } catch (e) {
      aviso('Não foi possível carregar as vagas. Verifique sua conexão.');
    }
  }

  /** Refaz os marcadores (ex.: depois de ligar/desligar o modo de letra maior). */
  function atualizarEstilo() {
    estado.vagas.forEach(function (v) { v._marcador = null; });
    if (marcadorBusca) marcadorBusca.setIcon(iconeDestino());
    renderizar(false);
  }

  carregar();

  // Com a permissão já dada, mostra a posição logo ao abrir (pedir a permissão fica com o tutorial e o botão).
  if (Vagas.permissaoLocalizacao) {
    Vagas.permissaoLocalizacao().then(function (p) {
      if (p === 'granted' || p === 'aproximada') garantirLocalizacao();
    }).catch(function () { /* sem como saber: espera o toque no botão */ });
  }

  return {
    mapa: mapa,
    carregar: carregar,
    localizar: garantirLocalizacao,
    pausarGps: pausarGps,
    retomarGps: retomarGpsSePreciso,
    atualizarEstilo: atualizarEstilo,
    estaNavegando: function () { return navegando; },
    encerrarNavegacao: encerrarNavegacao
  };
  }

  /* ---------- Inicialização ---------- */

  var instancia = null;
  window.VagasMapa = {
    iniciar: function () {
      if (!instancia) instancia = montar();
      else instancia.mapa.invalidateSize();
    },
    recarregar: function () { if (instancia) instancia.carregar(); },
    localizar: function () { if (instancia) instancia.localizar(); },
    pausarGps: function () { if (instancia) instancia.pausarGps(); },
    retomarGps: function () { if (instancia) instancia.retomarGps(); },
    atualizarEstilo: function () { if (instancia) instancia.atualizarEstilo(); },
    estaNavegando: function () { return !!instancia && instancia.estaNavegando(); },
    encerrarNavegacao: function () { if (instancia) instancia.encerrarNavegacao(); }
  };

  if (!window.VAGAS_APP) window.VagasMapa.iniciar();
})();
