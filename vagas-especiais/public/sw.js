/* Service worker do PWA: guarda arquivos estáticos e a última lista de vagas para uso com sinal fraco. */
'use strict';

var VERSAO = 'vagas-v5';
var CACHE_ESTATICO = VERSAO + '-estatico';
var CACHE_DADOS = VERSAO + '-dados';

var ESTATICOS = [
  '../assets/css/app.css',
  '../assets/js/app.js',
  '../assets/js/mapa.js',
  '../assets/js/tutorial.js',
  '../assets/img/icon.svg',
  '../assets/img/ilustracao-boas-vindas.svg',
  '../assets/fonts/nunito.woff2',
  '../assets/img/icon-192.png',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
  'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css',
  'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css',
  'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js'
];

self.addEventListener('install', function (ev) {
  // Arquivos do site são obrigatórios; os da CDN entram "se der" — uma falha neles não pode
  // impedir a instalação (sem service worker não há modo offline nem avisos no celular).
  ev.waitUntil(caches.open(CACHE_ESTATICO).then(function (c) {
    var locais = ESTATICOS.filter(function (u) { return u.indexOf('http') !== 0; });
    var externos = ESTATICOS.filter(function (u) { return u.indexOf('http') === 0; });
    return c.addAll(locais).then(function () {
      return Promise.all(externos.map(function (u) {
        return c.add(u).catch(function () { /* fica para a próxima visita */ });
      }));
    });
  }).catch(function () {
    // Sem armazenamento (ex.: janela anônima): instala mesmo assim — os avisos não dependem do cache.
  }));
  self.skipWaiting();
});

self.addEventListener('activate', function (ev) {
  ev.waitUntil(caches.keys().then(function (nomes) {
    return Promise.all(nomes.filter(function (n) { return n.indexOf(VERSAO) !== 0; })
                            .map(function (n) { return caches.delete(n); }));
  }));
  self.clients.claim();
});

/* ---------- Avisos no celular (Web Push) ----------
 * O servidor manda a notificação vazia (sem dados pessoais passando pelo serviço de push);
 * aqui buscamos o aviso mais recente e mostramos. */

self.addEventListener('push', function (ev) {
  var padrao = { titulo: 'Vagas Especiais', corpo: 'Você tem um novo aviso.', tela: 'perfil' };
  ev.waitUntil(
    fetch('../api/avisos.php', { credentials: 'include', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var novo = d.ok && d.avisos && d.avisos.filter(function (a) { return !a.lido; })[0];
        return novo || padrao;
      })
      .catch(function () { return padrao; })
      .then(function (a) {
        return self.registration.showNotification(a.titulo, {
          body: a.corpo,
          icon: '../assets/img/icon-192.png',
          badge: '../assets/img/icon-192.png',
          tag: 'aviso-' + (a.id || 'novo'),
          lang: 'pt-BR',
          data: { url: a.tela === 'mapa' ? 'mapa.php' : 'perfil.php' }
        });
      })
  );
});

self.addEventListener('notificationclick', function (ev) {
  ev.notification.close();
  var destino = new URL(ev.notification.data && ev.notification.data.url || 'perfil.php', self.registration.scope).href;
  ev.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (janelas) {
    for (var i = 0; i < janelas.length; i++) {
      if (janelas[i].url.indexOf(self.registration.scope) === 0 && 'focus' in janelas[i]) {
        return janelas[i].navigate(destino).then(function (j) { return (j || janelas[i]).focus(); });
      }
    }
    return self.clients.openWindow(destino);
  }));
});

self.addEventListener('fetch', function (ev) {
  var req = ev.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);

  // Lista de vagas: rede primeiro; sem conexão, usa a última versão salva.
  if (url.origin === location.origin && url.pathname.endsWith('/api/vagas.php')) {
    ev.respondWith(
      fetch(req).then(function (resp) {
        if (resp.ok) {
          var copia = resp.clone();
          caches.open(CACHE_DADOS).then(function (c) { c.put(req, copia); });
        }
        return resp;
      }).catch(function () {
        return caches.match(req).then(function (cache) {
          if (!cache) return Response.error();
          return cache.text().then(function (corpo) {
            return new Response(corpo, { headers: { 'Content-Type': 'application/json', 'X-Vagas-Offline': '1' } });
          });
        });
      })
    );
    return;
  }

  // Bibliotecas da CDN têm versão fixa na URL: cache primeiro.
  if (url.hostname === 'unpkg.com') {
    ev.respondWith(caches.match(req).then(function (cache) { return cache || fetch(req); }));
    return;
  }

  // Arquivos do próprio app: rede primeiro (para receber atualizações), cache se estiver offline.
  if (url.origin === location.origin && url.pathname.indexOf('/assets/') !== -1) {
    ev.respondWith(
      fetch(req).then(function (resp) {
        if (resp.ok) {
          var copia = resp.clone();
          caches.open(CACHE_ESTATICO).then(function (c) { c.put(req, copia); });
        }
        return resp;
      }).catch(function () { return caches.match(req); })
    );
  }
});
