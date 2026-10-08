/* Painel administrativo: confirmações e seleção de coordenadas no mapa. */
(function () {
  'use strict';

  // Botões com data-confirmar pedem confirmação antes de enviar.
  document.querySelectorAll('[data-confirmar]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      if (!window.confirm(b.dataset.confirmar)) ev.preventDefault();
    });
  });

  var el = document.getElementById('mapa-edicao');
  if (!el || !window.L) return;

  var lat = document.getElementById('latitude');
  var lng = document.getElementById('longitude');
  var ler = function (i) { return parseFloat(String(i.value).replace(',', '.')); };

  var temPosicao = !isNaN(ler(lat)) && !isNaN(ler(lng));
  var inicio = temPosicao ? [ler(lat), ler(lng)] : [-22.9035, -43.1095];

  var mapa = L.map(el).setView(inicio, temPosicao ? 18 : 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; colaboradores do <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
  }).addTo(mapa);

  var marcador = L.marker(inicio, { draggable: true, opacity: temPosicao ? 1 : 0.5 }).addTo(mapa);

  function definir(p) {
    marcador.setLatLng(p).setOpacity(1);
    lat.value = p.lat.toFixed(7);
    lng.value = p.lng.toFixed(7);
  }

  mapa.on('click', function (ev) { definir(ev.latlng); });
  marcador.on('dragend', function () { definir(marcador.getLatLng()); });

  [lat, lng].forEach(function (i) {
    i.addEventListener('change', function () {
      var a = ler(lat), b = ler(lng);
      if (!isNaN(a) && !isNaN(b)) { marcador.setLatLng([a, b]).setOpacity(1); mapa.panTo([a, b]); }
    });
  });
})();

/* Gráfico "Uso por dia": linha vertical, pontos e dica com os valores do dia sob o mouse. */
(function () {
  'use strict';
  document.querySelectorAll('[data-grafico]').forEach(function (fig) {
    var dados = JSON.parse(fig.dataset.grafico);
    var g = dados.geo;
    var svg = fig.querySelector('svg');
    var area = fig.querySelector('.area-hover');
    var cruz = fig.querySelector('.cruz');
    var marcas = fig.querySelectorAll('.marca-hover');
    var dica = fig.querySelector('.dica-grafico');
    var n = dados.serie.length;
    var x = function (i) { return g.esq + (n > 1 ? i * g.larg / (n - 1) : g.larg / 2); };
    var y = function (v) { return g.topo + g.alt - (v / g.max) * g.alt; };

    function mostrar(evento) {
      var caixa = svg.getBoundingClientRect();
      var escala = g.W / caixa.width;
      var px = (evento.clientX - caixa.left) * escala;
      var i = Math.max(0, Math.min(n - 1, Math.round((px - g.esq) / (n > 1 ? g.larg / (n - 1) : 1))));
      var ponto = dados.serie[i];
      cruz.setAttribute('x1', x(i));
      cruz.setAttribute('x2', x(i));
      cruz.removeAttribute('hidden');   // elementos SVG não têm a propriedade .hidden
      dados.linhas.forEach(function (l, s) {
        marcas[s].setAttribute('cx', x(i));
        marcas[s].setAttribute('cy', y(ponto[l.chave]));
        marcas[s].removeAttribute('hidden');
      });
      var partes = ponto.dia.split('-');
      dica.textContent = '';
      var titulo = document.createElement('strong');
      titulo.textContent = partes[2] + '/' + partes[1] + '/' + partes[0];
      dica.appendChild(titulo);
      dados.linhas.forEach(function (l, s) {
        var linha = document.createElement('div');
        linha.className = 'linha-dica';
        linha.innerHTML = '<span class="amostra serie-' + (s + 1) + '"></span>';
        linha.append(l.nome);
        var valor = document.createElement('b');
        valor.textContent = ponto[l.chave];
        linha.appendChild(valor);
        dica.appendChild(linha);
      });
      dica.hidden = false;
      var largDica = dica.offsetWidth;
      var xTela = x(i) / escala;
      dica.style.left = (xTela + 14 + largDica > caixa.width ? xTela - largDica - 14 : xTela + 14) + 'px';
      dica.style.top = '8px';
    }
    function esconder() {
      cruz.setAttribute('hidden', '');
      dica.hidden = true;
      marcas.forEach(function (m) { m.setAttribute('hidden', ''); });
    }
    area.addEventListener('mousemove', mostrar);
    area.addEventListener('mouseleave', esconder);
  });
})();

/* Mapa "Onde falta vaga": destinos buscados sem vaga a menos de 1 km (círculo maior = mais buscas). */
(function () {
  'use strict';
  var el = document.getElementById('mapa-demanda');
  if (!el || !window.L) return;
  var pontos = JSON.parse(el.dataset.pontos);
  var vagas = JSON.parse(el.dataset.vagas);
  var mapa = L.map(el, { scrollWheelZoom: false }).setView([-22.92, -43.08], 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
  }).addTo(mapa);
  vagas.forEach(function (v) {
    L.circleMarker(v, { radius: 3, stroke: false, fillColor: '#8a8580', fillOpacity: 0.9, interactive: false }).addTo(mapa);
  });
  var maximo = Math.max.apply(null, pontos.map(function (p) { return p.buscas; }));
  var limites = [];
  pontos.forEach(function (p) {
    var raio = 7 + 15 * Math.sqrt(p.buscas / maximo);
    L.circleMarker([p.lat, p.lng], { radius: raio, color: '#ffffff', weight: 2, fillColor: '#4a3aa7', fillOpacity: 0.75 })
      .bindTooltip(p.nome + ' — ' + p.buscas + (p.buscas === 1 ? ' busca' : ' buscas') + ' sem vaga por perto')
      .addTo(mapa);
    limites.push([p.lat, p.lng]);
  });
  if (limites.length > 1) mapa.fitBounds(limites, { padding: [40, 40], maxZoom: 15 });
  else if (limites.length === 1) mapa.setView(limites[0], 14);
})();
