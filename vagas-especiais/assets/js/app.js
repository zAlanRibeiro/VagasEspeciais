/* Vagas Especiais — utilidades comuns: envio de formulários para a API e registro do PWA. */
(function () {
  'use strict';

  var meta = document.querySelector('meta[name="csrf-token"]');
  var csrf = meta ? meta.content : '';

  async function postForm(url, formData) {
    var resp = await fetch(url, {
      method: 'POST',
      body: formData,
      headers: { 'X-CSRF-Token': csrf },
      credentials: 'same-origin'
    });
    var dados;
    try {
      dados = await resp.json();
    } catch (e) {
      dados = { ok: false, erro: 'Erro inesperado no servidor (' + resp.status + ').' };
    }
    return dados;
  }

  function mostrarErro(el, msg) {
    if (!el) return;
    el.textContent = msg || '';
    el.hidden = !msg;
  }

  // Formulários com data-api="url" são enviados via fetch; a resposta JSON pode trazer redirect.
  document.querySelectorAll('form[data-api]').forEach(function (form) {
    form.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      var erro = form.querySelector('[data-erro]');
      var botao = form.querySelector('[type="submit"]');
      mostrarErro(erro, '');

      if (!form.checkValidity()) {
        mostrarErro(erro, 'Preencha todos os campos obrigatórios.');
        form.reportValidity();
        return;
      }

      var conteudoBotao = botao.innerHTML;
      botao.disabled = true;
      botao.textContent = 'Enviando…';
      try {
        var d = await postForm(form.dataset.api, new FormData(form));
        if (d.ok) {
          if (d.redirect) { window.location.href = d.redirect; return; }
          if (d.mensagem) mostrarErro(erro, d.mensagem);
        } else {
          mostrarErro(erro, d.erro || 'Não foi possível concluir.');
        }
      } catch (e) {
        mostrarErro(erro, 'Sem conexão. Verifique sua internet e tente novamente.');
      } finally {
        botao.disabled = false;
        botao.innerHTML = conteudoBotao;
      }
    });
  });

  /* Mostrar/ocultar senha */
  document.querySelectorAll('.mostrar-senha').forEach(function (b) {
    b.addEventListener('click', function () {
      var input = b.parentElement.querySelector('input');
      var mostrar = input.type === 'password';
      input.type = mostrar ? 'text' : 'password';
      b.setAttribute('aria-pressed', String(mostrar));
      b.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
      b.querySelector('use').setAttribute('href', mostrar ? '#i-olho-fechado' : '#i-olho');
    });
  });

  /* Área de envio de arquivo: mostra o nome escolhido */
  document.querySelectorAll('.arquivo input[type="file"]').forEach(function (input) {
    var caixa = input.closest('.arquivo');
    var nome = caixa.querySelector('[data-arquivo-nome]');
    var original = nome.textContent;
    input.addEventListener('change', function () {
      var arq = input.files && input.files[0];
      nome.textContent = arq ? arq.name : original;
      caixa.classList.toggle('escolhido', !!arq);
      caixa.querySelector('use').setAttribute('href', arq ? '#i-check' : '#i-camera');
    });
    input.form.addEventListener('reset', function () {
      nome.textContent = original;
      caixa.classList.remove('escolhido');
      caixa.querySelector('use').setAttribute('href', '#i-camera');
    });
  });

  /* Cadastro: cada perfil PCD/idoso marcado mostra o campo da sua comprovação (obrigatório só quando aparece). */
  document.querySelectorAll('[data-documento]').forEach(function (caixa) {
    var alvo = document.getElementById(caixa.dataset.documento);
    var arquivo = alvo.querySelector('input[type="file"]');
    function atualizar() {
      alvo.hidden = !caixa.checked;
      arquivo.required = caixa.checked;
    }
    caixa.addEventListener('change', atualizar);
    atualizar();
  });

  /* "Letra maior e alto contraste": vale para este aparelho (cookie, para o servidor já entregar a página certa). */
  function marcarBotoesAcessivel(ligado) {
    document.querySelectorAll('[data-modo-acessivel]').forEach(function (b) {
      b.setAttribute(b.getAttribute('role') === 'switch' ? 'aria-checked' : 'aria-pressed', String(ligado));
    });
  }
  // Delegado: vale também para botões criados depois (ex.: o do tutorial).
  document.addEventListener('click', function (ev) {
    if (!ev.target.closest('[data-modo-acessivel]')) return;
    var ligado = !document.documentElement.classList.contains('modo-acessivel');
    document.documentElement.classList.toggle('modo-acessivel', ligado);
    document.cookie = 'modo_acessivel=' + (ligado ? '1' : '0') + '; path=/; max-age=' + (ligado ? 31536000 : 0) + '; SameSite=Lax';
    marcarBotoesAcessivel(ligado);
    if (window.VagasMapa) window.VagasMapa.atualizarEstilo();
  });

  /* Avisos no celular (Web Push): o servidor só "cutuca" o aparelho; o service worker busca o texto do aviso. */
  var botaoPush = document.querySelector('[data-push]');
  if (botaoPush) iniciarPush(botaoPush);

  function chaveParaBytes(base64url) {
    var b64 = (base64url + '==='.slice((base64url.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    var bin = atob(b64);
    var bytes = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return bytes;
  }

  async function iniciarPush(botao) {
    var estado = document.querySelector('[data-push-estado]');
    var convite = document.querySelector('[data-push-convite]');
    var textoPadrao = estado.textContent;
    var avisar = function (msg) { estado.textContent = msg || textoPadrao; };

    var suportado = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && botao.dataset.vapid;
    if (!suportado) {
      avisar(/iPhone|iPad/.test(navigator.userAgent)
        ? 'No iPhone, adicione este site à Tela de Início (Compartilhar → Adicionar à Tela de Início) para receber avisos.'
        : 'Este navegador não permite avisos. Instale o app Vagas Especiais para recebê-los.');
      return;
    }
    if (Notification.permission === 'denied') {
      avisar('Os avisos estão bloqueados. Libere as notificações deste site nas configurações do navegador.');
      return;
    }

    // Avisos exigem o service worker ativo (só funciona em HTTPS ou localhost).
    var registro = await Promise.race([
      navigator.serviceWorker.ready,
      new Promise(function (ok) { setTimeout(function () { ok(null); }, 8000); })
    ]);
    if (!registro) {
      avisar('Os avisos precisam de conexão segura (HTTPS). Disponível quando o site estiver publicado.');
      return;
    }
    var inscricao = await registro.pushManager.getSubscription();
    botao.disabled = false;
    botao.setAttribute('aria-checked', String(!!inscricao));
    if (convite) convite.hidden = !!inscricao;

    async function ativar() {
      botao.disabled = true;
      try {
        var permissao = await Notification.requestPermission();
        if (permissao !== 'granted') {
          avisar(permissao === 'denied'
            ? 'Os avisos estão bloqueados. Libere as notificações deste site nas configurações do navegador.'
            : 'Para receber avisos, toque em "Permitir" quando o navegador perguntar.');
          return;
        }
        inscricao = await registro.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: chaveParaBytes(botao.dataset.vapid) });
        var fd = new FormData();
        fd.append('acao', 'inscrever');
        fd.append('tipo', 'web');
        fd.append('endereco', inscricao.endpoint);
        var d = await postForm('../api/push.php', fd);
        if (!d.ok) throw new Error(d.erro);
        botao.setAttribute('aria-checked', 'true');
        if (convite) convite.hidden = true;
        avisar('Pronto! Você receberá os avisos neste aparelho.');
      } catch (e) {
        avisar('Não foi possível ativar os avisos agora. Tente novamente.');
      } finally {
        botao.disabled = false;
      }
    }

    async function desativar() {
      botao.disabled = true;
      try {
        if (inscricao) {
          var fd = new FormData();
          fd.append('acao', 'remover');
          fd.append('endereco', inscricao.endpoint);
          await postForm('../api/push.php', fd);
          await inscricao.unsubscribe();
          inscricao = null;
        }
        botao.setAttribute('aria-checked', 'false');
        avisar('Avisos desligados neste aparelho.');
      } finally {
        botao.disabled = false;
      }
    }

    botao.addEventListener('click', function () {
      if (botao.getAttribute('aria-checked') === 'true') desativar(); else ativar();
    });
    var botaoConvite = document.querySelector('[data-push-ativar]');
    if (botaoConvite) botaoConvite.addEventListener('click', ativar);
  }

  if ('serviceWorker' in navigator && document.querySelector('link[rel="manifest"]')) {
    navigator.serviceWorker.register('sw.js').catch(function () { /* PWA é opcional */ });
  }

  /* Implementação web das funções usadas por mapa.js (o app móvel tem a sua). */

  async function carregarVagas() {
    var resp = await fetch('../api/vagas.php', { credentials: 'same-origin' });
    if (resp.status === 401) { window.location.href = 'login.php'; return null; }
    if (resp.status === 403) { window.location.href = 'perfil.php'; return null; }
    var d = await resp.json();
    if (!d.ok) throw new Error(d.erro);
    d.offline = !!resp.headers.get('X-Vagas-Offline');
    return d;
  }

  function enviarRelato(formData) {
    return postForm('../api/relatos.php', formData);
  }

  async function buscarEndereco(q) {
    var resp = await fetch('../api/geocodificar.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
    return resp.json();
  }

  function buscarRota(de, para) {
    var fd = new FormData();
    fd.append('de', de.lat + ',' + de.lng);
    fd.append('para', para.lat + ',' + para.lng);
    return postForm('../api/rota.php', fd);
  }

  /* Voz do navegador para as instruções da rota (em português, quando houver voz instalada). */
  function falar(texto) {
    if (!('speechSynthesis' in window)) return;
    var fala = new SpeechSynthesisUtterance(texto);
    fala.lang = 'pt-BR';
    var voz = window.speechSynthesis.getVoices().filter(function (v) { return /^pt(-|_)BR/i.test(v.lang); })[0];
    if (voz) fala.voice = voz;
    window.speechSynthesis.cancel();
    window.speechSynthesis.speak(fala);
  }

  /* Tela acesa durante a navegação (onde o navegador permitir). */
  var travaTela = null;
  async function manterTelaLigada(ligar) {
    try {
      if (ligar && 'wakeLock' in navigator) travaTela = await navigator.wakeLock.request('screen');
      else if (!ligar && travaTela) { await travaTela.release(); travaTela = null; }
    } catch (e) { /* sem suporte: a tela segue a configuração do aparelho */ }
  }

  function registrarEvento(dados) {
    var fd = new FormData();
    Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
    return postForm('../api/eventos.php', fd);
  }

  function carregarIndiceRuas() {
    return fetch('../assets/data/ruas-niteroi.json').then(function (r) {
      if (!r.ok) throw new Error('índice indisponível');
      return r.json();
    });
  }

  function observarPosicao(ok, erro, altaPrecisao) {
    if (!('geolocation' in navigator)) {
      erro({ code: -1, message: 'Seu navegador não oferece localização.' });
      return null;
    }
    var id = navigator.geolocation.watchPosition(ok, erro, { enableHighAccuracy: !!altaPrecisao, maximumAge: 15000, timeout: 20000 });
    return function () { navigator.geolocation.clearWatch(id); };
  }

  /* Permissão de localização para o tutorial: 'granted' | 'prompt' | 'denied' | 'desligado' | 'indisponivel'. */
  async function permissaoLocalizacao() {
    if (!('geolocation' in navigator)) return 'indisponivel';
    try {
      if (navigator.permissions && navigator.permissions.query) {
        return (await navigator.permissions.query({ name: 'geolocation' })).state;
      }
    } catch (e) { /* navegador sem a API de permissões: só perguntando para saber */ }
    return 'prompt';
  }

  function pedirLocalizacao() {
    return new Promise(function (resolver) {
      if (!('geolocation' in navigator)) { resolver('indisponivel'); return; }
      navigator.geolocation.getCurrentPosition(function () { resolver('granted'); }, function (err) {
        // 1 = recusada; 2 = sem posição (localização do aparelho desligada); 3 = demorou, mas a permissão foi dada.
        resolver(err.code === 1 ? 'denied' : (err.code === 2 ? 'desligado' : 'granted'));
      }, { enableHighAccuracy: false, maximumAge: 60000, timeout: 15000 });
    });
  }

  window.Vagas = {
    csrf: csrf,
    postForm: postForm,
    mostrarErro: mostrarErro,
    carregarVagas: carregarVagas,
    enviarRelato: enviarRelato,
    buscarEndereco: buscarEndereco,
    carregarIndiceRuas: carregarIndiceRuas,
    buscarRota: buscarRota,
    registrarEvento: registrarEvento,
    falar: falar,
    manterTelaLigada: manterTelaLigada,
    observarPosicao: observarPosicao,
    permissaoLocalizacao: permissaoLocalizacao,
    pedirLocalizacao: pedirLocalizacao
  };
})();
