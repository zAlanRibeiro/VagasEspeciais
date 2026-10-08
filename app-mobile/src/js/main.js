/*
 * App Vagas Especiais — telas, login por token e integração com o mapa compartilhado (shared/mapa.js).
 */
import { api, API, definirToken, quandoSessaoExpirar, ErroConexao, definirServidorTeste } from './api.js';
import {
  App, nativo, plataforma, lerJson, gravarJson, remover, abrirExterno, observarPosicao,
  pushDisponivel, tipoPush, permissaoPush, ativarPush, desativarPush, ouvirPush, falar, manterTelaLigada,
  permissaoLocalizacao, pedirLocalizacao,
} from './plataforma.js';

/* global __VERSAO_APP__, __DESENVOLVIMENTO__ */
const VERSAO = __VERSAO_APP__;

// Avisa shared/mapa.js que o app controla quando o mapa é montado.
window.VAGAS_APP = true;

const $ = (id) => document.getElementById(id);

const estado = {
  tela: 'carregando',
  usuario: null,
  config: null,
  vagasCarregadasEm: 0,
};

/* ---------- Navegação entre telas ---------- */

function mostrar(tela) {
  if (tela === 'mapa' && !(estado.usuario && estado.usuario.pode_usar_app)) tela = 'perfil';
  estado.tela = tela;
  document.querySelectorAll('[data-tela]').forEach((el) => { el.hidden = el.dataset.tela !== tela; });
  document.body.className = tela === 'mapa' ? 'pagina-mapa' : 'pagina-centro';
  window.scrollTo(0, 0);

  // GPS só com o mapa na tela (economiza bateria).
  if (tela !== 'mapa' && window.VagasMapa) window.VagasMapa.pausarGps();
  if (tela === 'mapa') {
    requestAnimationFrame(() => {
      window.VagasMapa.iniciar();
      window.VagasMapa.retomarGps();
      if (Date.now() - estado.vagasCarregadasEm > 10 * 60 * 1000 && estado.vagasCarregadasEm) window.VagasMapa.recarregar();
      abrirTutorialNaPrimeiraVez();
    });
  } else {
    const titulo = document.querySelector('[data-tela="' + tela + '"] h1');
    if (titulo) { titulo.setAttribute('tabindex', '-1'); titulo.focus({ preventScroll: true }); }
  }
  if (tela === 'perfil') {
    renderizarPerfil();
    renderizarAvisos();
    carregarPerfil().catch(() => { /* offline: mantém o que já está na tela */ });
  }
  if (tela !== 'perfil') avisosNovos = new Set();
  if (tela === 'perfil' || tela === 'mapa') carregarAvisos();
}

function irAposLogin() {
  mostrar(estado.usuario.pode_usar_app ? 'mapa' : 'perfil');
  renovarPush();
}

/* ---------- Sessão ---------- */

async function salvarSessao(token, usuario) {
  definirToken(token);
  estado.usuario = usuario;
  await gravarJson('token', token);
  await gravarJson('usuario', usuario);
}

async function limparSessao() {
  definirToken(null);
  estado.usuario = null;
  estado.perfil = null;
  await remover('token');
  await remover('usuario');
  await remover('vagas_cache');
  estado.avisos = [];
  localStorage.removeItem('push_token');
  $('ponto-aviso').hidden = true;
}

/**
 * Fim da sessão: limpa os dados da conta e reinicia o app, para não sobrar nada dela na memória
 * (vagas do mapa, busca, rota). A mensagem aparece na tela de login depois do reinício.
 */
async function encerrarSessao(mensagem) {
  await limparSessao();
  if (mensagem) sessionStorage.setItem('aviso_login', mensagem);
  window.location.reload();
}

quandoSessaoExpirar(() => encerrarSessao('Sua sessão expirou. Entre novamente.'));

function avisoLogin(msg) {
  $('aviso-login').textContent = msg || '';
  $('aviso-login').hidden = !msg;
}

/* ---------- Formulários ---------- */

function mostrarErro(el, msg) {
  if (!el) return;
  el.textContent = msg || '';
  el.hidden = !msg;
}

/** Valida, desabilita o botão durante o envio e mostra erros de conexão. */
function aoEnviar(form, fn) {
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const erro = form.querySelector('[data-erro]');
    const botao = form.querySelector('[type="submit"]');
    mostrarErro(erro, '');
    if (!form.checkValidity()) {
      mostrarErro(erro, 'Preencha todos os campos obrigatórios.');
      form.reportValidity();
      return;
    }
    const conteudo = botao.innerHTML;
    botao.disabled = true;
    botao.textContent = 'Enviando…';
    try {
      const msg = await fn(new FormData(form));
      if (msg) mostrarErro(erro, msg);
    } catch (e) {
      mostrarErro(erro, e instanceof ErroConexao ? e.message : 'Não foi possível concluir. Tente novamente.');
    } finally {
      botao.disabled = false;
      botao.innerHTML = conteudo;
    }
  });
}

/* Mostrar/ocultar senha */
document.querySelectorAll('.mostrar-senha').forEach((b) => {
  b.addEventListener('click', () => {
    const input = b.parentElement.querySelector('input');
    const mostrar = input.type === 'password';
    input.type = mostrar ? 'text' : 'password';
    b.setAttribute('aria-pressed', String(mostrar));
    b.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
    b.querySelector('use').setAttribute('href', mostrar ? '#i-olho-fechado' : '#i-olho');
  });
});

/* Área de envio de arquivo: mostra o nome escolhido */
function prepararArquivo(input) {
  const caixa = input.closest('.arquivo');
  const nome = caixa.querySelector('[data-arquivo-nome]');
  const original = nome.textContent;
  const atualizar = () => {
    const arq = input.files && input.files[0];
    nome.textContent = arq ? arq.name : original;
    caixa.classList.toggle('escolhido', !!arq);
    caixa.querySelector('use').setAttribute('href', arq ? '#i-check' : '#i-camera');
  };
  input.addEventListener('change', atualizar);
  input.form.addEventListener('reset', () => setTimeout(atualizar));
}
document.querySelectorAll('.arquivo input[type="file"]').forEach(prepararArquivo);

/* Cadastro: cada perfil PCD/idoso marcado mostra o campo da sua comprovação (obrigatório só quando aparece). */
const caixasDocumento = document.querySelectorAll('[data-documento]');
function atualizarDocumentos() {
  caixasDocumento.forEach((c) => {
    const alvo = $(c.dataset.documento);
    alvo.hidden = !c.checked;
    alvo.querySelector('input[type="file"]').required = c.checked;
  });
}
caixasDocumento.forEach((c) => c.addEventListener('change', atualizarDocumentos));

function dadosApp(fd) {
  fd.append('modo', 'app');
  fd.append('dispositivo', 'App ' + plataforma + ' ' + VERSAO);
  return fd;
}

aoEnviar($('form-login'), async (fd) => {
  const d = await api('login.php', { dados: dadosApp(fd) });
  if (!d.ok) return d.erro;
  await salvarSessao(d.token, d.usuario);
  $('form-login').reset();
  avisoLogin('');
  irAposLogin();
});

aoEnviar($('form-cadastro'), async (fd) => {
  if (fd.get('senha') !== fd.get('confirmar')) return 'As senhas não conferem.';
  const d = await api('cadastro.php', { dados: dadosApp(fd) });
  if (!d.ok) return d.erro;
  await salvarSessao(d.token, d.usuario);
  $('form-cadastro').reset();
  atualizarDocumentos();
  await carregarPerfil();
  // Só rotativo: já pode usar o mapa. Com PCD/idoso, o perfil mostra que a comprovação está em análise.
  mostrar(d.usuario.perfis.every((x) => x.tipo === 'ROTATIVO') ? 'mapa' : 'perfil');
});

aoEnviar($('form-excluir'), async (fd) => {
  const d = await api('conta.php', { dados: fd });
  if (!d.ok) return d.erro;
  await encerrarSessao('Sua conta e seus dados foram excluídos.');
});

/* ---------- Perfil ---------- */

async function carregarPerfil() {
  const d = await api('perfil.php');
  if (!d.ok) return false;
  estado.usuario = d.usuario;
  estado.perfil = d;
  await gravarJson('usuario', d.usuario);
  if (estado.tela === 'perfil') renderizarPerfil();
  return true;
}

/** Símbolo de cada perfil, o mesmo dos alfinetes do mapa. */
const SIMBOLO = { PCD: svgIcone('pcd', 'icone-pcd'), IDOSO: '60+', ROTATIVO: 'P' };

/** Linha de um perfil: ícone, nome, selo de situação (opcional) e a frase que o servidor manda. */
function linhaPerfil(x, selo, frase, tag = 'div') {
  const linha = document.createElement(tag);
  linha.className = 'perfil-linha';
  linha.innerHTML = '<span class="opcao-icone opcao-icone-' + x.tipo.toLowerCase() + '" aria-hidden="true">' + SIMBOLO[x.tipo] + '</span>';
  const corpo = document.createElement('span');
  corpo.className = 'perfil-texto';
  const titulo = document.createElement('span');
  titulo.className = 'perfil-titulo';
  titulo.appendChild(texto('strong', x.nome));
  if (selo) {
    const s = texto('span', selo);
    s.className = 'selo selo-' + x.status;
    titulo.appendChild(s);
  }
  corpo.append(titulo, texto('small', frase));
  linha.appendChild(corpo);
  return linha;
}

/** Formulário de comprovação de um perfil PCD/idoso (novo, sem documento ou recusado). */
function formDocumento(x, rotulo) {
  const form = document.createElement('form');
  form.className = 'form-perfil';
  form.noValidate = true;
  const id = 'doc-' + x.tipo.toLowerCase() + '-' + (++formsDocumento);
  form.innerHTML = '<input type="hidden" name="perfil" value="' + x.tipo + '">'
    + '<label class="arquivo" for="' + id + '"><input id="' + id + '" name="documento" type="file" accept="image/jpeg,image/png,application/pdf" required>'
    + '<span class="arquivo-icone">' + svgIcone('camera', 'icone icone-g') + '</span>'
    + '<span class="arquivo-texto"><strong data-arquivo-nome>Tirar foto ou escolher arquivo</strong><small></small></span></label>'
    + '<p class="erro" data-erro role="alert" hidden></p><button class="botao botao-primario" type="submit"></button>';
  form.querySelector('.arquivo-texto small').textContent = x.documento + ' · PDF, JPG ou PNG · até 5 MB';
  form.querySelector('[type="submit"]').textContent = rotulo;
  prepararArquivo(form.querySelector('input[type="file"]'));
  aoEnviar(form, async (fd) => {
    const d = await api('perfil.php', { dados: fd });
    if (!d.ok) return d.erro;
    await carregarPerfil();
  });
  return form;
}
let formsDocumento = 0;

function svgIcone(nome, classe = 'icone') {
  return '<svg class="' + classe + '" aria-hidden="true" focusable="false"><use href="#i-' + nome + '"></use></svg>';
}

function renderizarPerfil() {
  const u = estado.usuario;
  if (!u) return;
  const p = estado.perfil || { comprovacoes: [], relatos: [] };

  const partes = String(u.nome).trim().split(/\s+/);
  $('perfil-avatar').textContent = (partes[0][0] + (partes.length > 1 ? partes[partes.length - 1][0] : '')).toUpperCase();
  $('perfil-ola').textContent = 'Olá, ' + partes[0];
  $('perfil-email').textContent = u.email;

  const perfis = u.perfis || [];
  const selos = $('perfil-selos');
  selos.textContent = '';
  if (u.papel !== 'admin') {
    perfis.filter((x) => x.status === 'aprovado').forEach((x) => {
      const s = document.createElement('span');
      s.className = 'selo selo-' + x.tipo.toLowerCase();
      s.innerHTML = SIMBOLO[x.tipo];
      s.append(' ' + x.nome);
      selos.appendChild(s);
    });
  }

  $('perfil-suspenso').hidden = !u.suspenso;
  const lista = $('lista-perfis');
  lista.textContent = '';
  perfis.forEach((x) => {
    const li = document.createElement('li');
    li.className = 'perfil perfil-' + x.status;
    li.appendChild(linhaPerfil(x, x.status_texto, x.descricao));
    if (x.aceita_documento && !u.suspenso) li.appendChild(formDocumento(x, 'Enviar comprovação'));
    lista.appendChild(li);
  });

  const novos = $('lista-perfis-novos');
  novos.textContent = '';
  (u.perfis_novos || []).forEach((x) => {   // só PCD/idoso: o rotativo todos já têm
    const li = document.createElement('li');
    li.className = 'perfil';
    const det = document.createElement('details');
    det.className = 'perfil-adicionar';
    const resumo = linhaPerfil(x, '', 'Precisa de comprovação (' + x.documento + '). A equipe analisa antes de liberar.', 'summary');
    resumo.insertAdjacentHTML('beforeend', '<span class="botao botao-pequeno" aria-hidden="true">Adicionar</span>');
    det.append(resumo, formDocumento(x, 'Enviar e pedir análise'));
    li.appendChild(det);
    novos.appendChild(li);
  });
  $('bloco-perfis-novos').hidden = !novos.children.length || u.papel === 'admin' || !!u.suspenso;

  $('btn-atualizar-status').hidden = u.pode_usar_app || !perfis.some((x) => x.status === 'em_analise');
  $('btn-abrir-mapa').hidden = !u.pode_usar_app;
  $('perfil-voltar-mapa').hidden = !u.pode_usar_app;
  $('perfil-marca').hidden = u.pode_usar_app;
  $('bloco-excluir').hidden = u.papel === 'admin';
  $('cfg-tutorial-item').hidden = !u.pode_usar_app;
  atualizarConvitePush();

  preencherLista('lista-comprovacoes', 'bloco-comprovacoes', p.comprovacoes, (c) => ({
    icone: 'documento', bolha: '', titulo: c.nome_original, meta: (c.perfil_texto ? c.perfil_texto + ' · e' : 'E') + 'nviada em ' + c.data,
    extra: c.observacao, status: c.status, statusTexto: c.status_texto,
  }));
  preencherLista('lista-relatos', 'bloco-relatos', p.relatos, (r) => ({
    icone: 'bandeira', bolha: 'bolha-ambar', titulo: r.tipo_texto,
    meta: r.logradouro + (r.numero ? ', ' + r.numero : '') + ' · ' + r.bairro,
    extra: r.resposta_admin ? 'Resposta: ' + r.resposta_admin : '', status: r.status, statusTexto: r.status_texto,
  }));
}

function texto(tag, conteudo) {
  const el = document.createElement(tag);
  el.textContent = conteudo;
  return el;
}

function preencherLista(idLista, idBloco, itens, dados) {
  const ul = $(idLista);
  ul.textContent = '';
  (itens || []).forEach((item) => {
    const d = dados(item);
    const li = document.createElement('li');
    li.innerHTML = '<span class="bolha ' + d.bolha + '">' + svgIcone(d.icone) + '</span>';
    const corpo = document.createElement('span');
    corpo.className = 'item-corpo';
    corpo.append(texto('strong', d.titulo), texto('small', d.meta));
    if (d.extra) {
      const extra = texto('span', d.extra);
      extra.className = 'resposta';
      corpo.appendChild(extra);
    }
    const selo = texto('span', d.statusTexto);
    selo.className = 'selo selo-' + d.status;
    li.append(corpo, selo);
    ul.appendChild(li);
  });
  $(idBloco).hidden = !(itens && itens.length);
}

$('btn-atualizar-status').addEventListener('click', async (ev) => {
  const b = ev.currentTarget;
  b.disabled = true;
  try {
    await carregarPerfil();
    if (estado.usuario.pode_usar_app) mostrar('mapa');
  } catch {
    window.alert(new ErroConexao().message);
  } finally {
    b.disabled = false;
  }
});

/* ---------- Letra maior e alto contraste (preferência deste aparelho) ---------- */

function aplicarModoAcessivel(ligado) {
  document.documentElement.classList.toggle('modo-acessivel', ligado);
  document.querySelectorAll('[data-modo-acessivel]').forEach((b) => {
    b.setAttribute(b.getAttribute('role') === 'switch' ? 'aria-checked' : 'aria-pressed', String(ligado));
  });
}

aplicarModoAcessivel(localStorage.getItem('modo_acessivel') === '1');
// Delegado: vale também para botões criados depois (ex.: o do tutorial).
document.addEventListener('click', (ev) => {
  if (!ev.target.closest('[data-modo-acessivel]')) return;
  const ligado = !document.documentElement.classList.contains('modo-acessivel');
  try { localStorage.setItem('modo_acessivel', ligado ? '1' : '0'); } catch { /* sem armazenamento: vale só agora */ }
  aplicarModoAcessivel(ligado);
  if (window.VagasMapa) window.VagasMapa.atualizarEstilo();
});

/* ---------- Tutorial de primeiro acesso (shared/tutorial.js) ---------- */

if (window.VagasTutorial) {
  window.VagasTutorial.configurar({
    plataforma: nativo ? plataforma : 'web',
    imagens: 'shared/img/',
    // Vale para este aparelho: não aparece de novo, nem ao trocar de conta.
    aoFechar: () => gravarJson('tutorial_visto', true),
  });
}

async function abrirTutorialNaPrimeiraVez() {
  if (!window.VagasTutorial || window.VagasTutorial.aberto() || estado.tela !== 'mapa') return;
  if (await lerJson('tutorial_visto')) return;
  if (estado.tela === 'mapa') window.VagasTutorial.abrir();
}

/* ---------- Avisos (central no perfil + pontinho no botão do perfil) ---------- */

async function carregarAvisos() {
  if (!estado.usuario) return;
  try {
    const d = await api('avisos.php');
    if (!d.ok) return;
    estado.avisos = d.avisos;
    const n = d.nao_lidos;
    $('ponto-aviso').hidden = !n;
    $('ponto-aviso').textContent = n > 9 ? '9+' : String(n);
    $('botao-perfil').setAttribute('aria-label', n ? 'Meu perfil — ' + n + (n === 1 ? ' aviso novo' : ' avisos novos') : 'Meu perfil');
    if (estado.tela === 'perfil') renderizarAvisos();
  } catch { /* offline: fica o que já havia */ }
}

/** Avisos que estavam sem ler quando o perfil abriu: seguem destacados até sair da tela. */
let avisosNovos = new Set();

function renderizarAvisos() {
  const avisos = estado.avisos || [];
  const ul = $('lista-avisos');
  ul.textContent = '';
  avisos.forEach((a) => {
    const li = document.createElement('li');
    if (!a.lido) avisosNovos.add(a.id);
    if (avisosNovos.has(a.id)) li.className = 'nao-lido';
    li.innerHTML = '<span class="bolha bolha-ambar">' + svgIcone('sino') + '</span>';
    const corpo = document.createElement('span');
    corpo.className = 'item-corpo';
    const texto = document.createElement('span');
    texto.className = 'resposta';
    texto.textContent = a.corpo;
    const titulo = document.createElement('strong');
    titulo.textContent = a.titulo;
    const data = document.createElement('small');
    data.textContent = a.data;
    corpo.append(titulo, texto, data);
    li.appendChild(corpo);
    ul.appendChild(li);
  });
  $('bloco-avisos').hidden = !avisos.length;

  // Vistos no perfil = lidos (o destaque "Novo" continua até sair da tela).
  if (avisos.some((a) => !a.lido)) {
    api('avisos.php', { dados: new FormData() }).then(() => {
      avisos.forEach((a) => { a.lido = true; });
      $('ponto-aviso').hidden = true;
      $('botao-perfil').setAttribute('aria-label', 'Meu perfil');
    }).catch(() => {});
  }
}

/* ---------- Avisos no celular (push) ---------- */

const PUSH_TEXTO = $('push-estado').textContent;
const estadoPush = (msg) => { $('push-estado').textContent = msg || PUSH_TEXTO; };
const tokenPush = () => localStorage.getItem('push_token');

async function prepararPush() {
  const chave = $('interruptor-push');
  if (!pushDisponivel) {
    estadoPush(nativo
      ? 'Ainda não disponível nesta versão do app (falta configurar o serviço de notificações).'
      : 'Disponível no app instalado no celular.');
    return;
  }
  const permissao = await permissaoPush();
  chave.disabled = false;
  chave.setAttribute('aria-checked', String(permissao === 'granted' && !!tokenPush()));
  if (permissao === 'denied') {
    estadoPush('Os avisos estão bloqueados. Libere as notificações do Vagas Especiais nas configurações do celular.');
  }
  atualizarConvitePush();
}

function atualizarConvitePush() {
  const u = estado.usuario;
  $('convite-push').hidden = !(pushDisponivel && u && (u.perfis || []).some((x) => ['pendente', 'em_analise'].includes(x.status))
    && $('interruptor-push').getAttribute('aria-checked') !== 'true');
}

async function enviarTokenPush(token) {
  const fd = new FormData();
  fd.append('acao', 'inscrever');
  fd.append('tipo', tipoPush);
  fd.append('endereco', token);
  const d = await api('push.php', { dados: fd });
  if (!d.ok) throw new Error(d.erro);
  localStorage.setItem('push_token', token);
}

async function ligarPush() {
  const chave = $('interruptor-push');
  chave.disabled = true;
  try {
    await enviarTokenPush(await ativarPush());
    chave.setAttribute('aria-checked', 'true');
    estadoPush('Pronto! Você receberá os avisos neste celular.');
  } catch (e) {
    estadoPush(e.message === 'negado'
      ? 'Para receber avisos, permita as notificações quando o celular perguntar (ou nas configurações do celular).'
      : (e instanceof ErroConexao ? e.message : 'Os avisos ainda não estão disponíveis nesta versão do app.'));
  } finally {
    chave.disabled = false;
    atualizarConvitePush();
  }
}

/** Tira este celular da lista de quem recebe avisos (ao desligar ou ao sair da conta). */
async function desligarPush(silencioso) {
  const token = tokenPush();
  try {
    if (token) {
      const fd = new FormData();
      fd.append('acao', 'remover');
      fd.append('endereco', token);
      await api('push.php', { dados: fd });
      await desativarPush();
    }
  } catch { /* sem conexão: o servidor descarta o token quando ele deixar de valer */ }
  localStorage.removeItem('push_token');
  $('interruptor-push').setAttribute('aria-checked', 'false');
  if (!silencioso) estadoPush('Avisos desligados neste celular.');
  atualizarConvitePush();
}

/** O token do aparelho pode mudar (reinstalação, atualização do sistema): renova ao abrir o app. */
async function renovarPush() {
  if (!pushDisponivel || !tokenPush() || (await permissaoPush()) !== 'granted') return;
  try {
    const token = await ativarPush();
    if (token !== tokenPush()) await enviarTokenPush(token);
  } catch { /* tenta na próxima abertura */ }
}

$('interruptor-push').addEventListener('click', () => {
  if ($('interruptor-push').getAttribute('aria-checked') === 'true') desligarPush(); else ligarPush();
});
$('btn-convite-push').addEventListener('click', ligarPush);

// Tocar na notificação leva à tela certa; com o app aberto, só atualiza os avisos.
ouvirPush((dados) => {
  carregarAvisos();
  if (estado.usuario) {
    carregarPerfil().catch(() => {}).finally(() => mostrar(dados.tela === 'mapa' ? 'mapa' : 'perfil'));
  }
}, () => {
  carregarAvisos();
  if (estado.tela === 'perfil') carregarPerfil().catch(() => {});
});

prepararPush();

/* ---------- Ações gerais ---------- */

document.addEventListener('click', (ev) => {
  const alvo = ev.target.closest('[data-ir], [data-sair], [data-privacidade], [data-externo], [data-tutorial]');
  if (!alvo) return;
  ev.preventDefault();

  if (alvo.dataset.ir) mostrar(alvo.dataset.ir);
  else if ('tutorial' in alvo.dataset) {
    // Revisto pelo perfil: abre sobre o mapa, que já mostra a posição se a localização for liberada.
    mostrar('mapa');
    if (window.VagasTutorial) window.VagasTutorial.abrir();
  }
  else if ('sair' in alvo.dataset) sair();
  else if ('privacidade' in alvo.dataset) abrirExterno(urlPrivacidade());
  else if (alvo.href) abrirExterno(alvo.href);
});

async function sair() {
  await desligarPush(true);
  try { await api('logout.php', { metodo: 'POST' }); } catch { /* sem conexão: sai mesmo assim */ }
  await encerrarSessao();
}

function urlPrivacidade() {
  return (estado.config && estado.config.url_privacidade) || API.replace(/\/api$/, '') + '/public/privacidade.php';
}

/* ---------- Mapa: implementação de window.Vagas usada por shared/mapa.js ---------- */

window.Vagas = {
  mostrarErro,
  observarPosicao,

  async carregarVagas() {
    try {
      const d = await api('vagas.php');
      if (d.status === 403) { await carregarPerfil(); mostrar('perfil'); return null; }
      if (d.status === 401) return null;
      if (!d.ok) throw new Error(d.erro);
      estado.vagasCarregadasEm = Date.now();
      await gravarJson('vagas_cache', { vagas: d.vagas, bairros: d.bairros, categorias: d.categorias });
      return { vagas: d.vagas, bairros: d.bairros, categorias: d.categorias, offline: false };
    } catch (e) {
      // Sem conexão: usa a última lista salva no aparelho.
      const cache = await lerJson('vagas_cache');
      if (e instanceof ErroConexao && cache) return { ...cache, offline: true };
      throw e;
    }
  },

  async enviarRelato(fd) {
    return api('relatos.php', { dados: fd });
  },

  async buscarEndereco(q) {
    return api('geocodificar.php?q=' + encodeURIComponent(q));
  },

  // Permissão de localização (tutorial e abertura do mapa).
  permissaoLocalizacao,
  pedirLocalizacao,

  // Rotas dentro do app (servidor → Valhalla/OpenStreetMap), voz e tela acesa na navegação.
  async buscarRota(de, para) {
    const fd = new FormData();
    fd.append('de', de.lat + ',' + de.lng);
    fd.append('para', para.lat + ',' + para.lng);
    return api('rota.php', { dados: fd });
  },
  falar,
  manterTelaLigada,

  // Estatísticas de uso (anônimas) para o painel administrativo.
  async registrarEvento(dados) {
    const fd = new FormData();
    Object.keys(dados).forEach((k) => fd.append(k, dados[k]));
    return api('eventos.php', { dados: fd });
  },

  // Índice de ruas embutido no app: funciona sem internet.
  async carregarIndiceRuas() {
    const r = await fetch('shared/data/ruas-niteroi.json');
    if (!r.ok) throw new Error('índice indisponível');
    return r.json();
  },
};

/* ---------- Botão "voltar" do Android e retorno ao app ---------- */

App.addListener('backButton', () => {
  if (window.VagasTutorial && window.VagasTutorial.aberto()) { window.VagasTutorial.voltar(); return; }
  if (estado.tela === 'mapa') {
    if (window.VagasMapa && window.VagasMapa.estaNavegando()) { window.VagasMapa.encerrarNavegacao(); return; }
    if (!$('busca-sugestoes').hidden) { $('busca-campo').blur(); return; }
    const painel = ['painel-detalhe', 'painel-lista'].find((id) => !$(id).hidden);
    if (painel) { document.querySelector('[data-fechar="' + painel + '"]').click(); return; }
    App.exitApp();
  } else if (estado.tela === 'perfil' && estado.usuario && estado.usuario.pode_usar_app) {
    mostrar('mapa');
  } else if (estado.tela === 'login' || estado.tela === 'cadastro') {
    mostrar('inicio');
  } else {
    App.exitApp();
  }
});

App.addListener('pause', () => { if (window.VagasMapa) window.VagasMapa.pausarGps(); });
App.addListener('resume', () => {
  if (estado.tela === 'mapa' && window.VagasMapa) window.VagasMapa.retomarGps();
  if (!estado.usuario) return;
  carregarAvisos();
  if (estado.tela === 'perfil') carregarPerfil().catch(() => {});
  if (estado.tela === 'mapa' && Date.now() - estado.vagasCarregadasEm > 10 * 60 * 1000) window.VagasMapa.recarregar();
});

/* ---------- Versão de teste: trocar o servidor (o endereço do túnel muda a cada vez) ---------- */

if (__DESENVOLVIMENTO__) {
  $('servidor-teste').hidden = false;
  $('servidor-teste-url').textContent = API.replace(/^https?:\/\//, '').replace(/\/vagas-especiais\/api$/, '');
  $('btn-servidor-teste').addEventListener('click', async () => {
    const novo = window.prompt('Endereço do servidor de teste (ex.: https://xxxx.trycloudflare.com):', API.replace(/\/vagas-especiais\/api$/, ''));
    if (novo === null) return;
    definirServidorTeste(novo);
    await limparSessao();
    window.location.reload();
  });
}

/* ---------- Início ---------- */

function versaoMenor(a, b) {
  const pa = String(a).split('.').map(Number);
  const pb = String(b).split('.').map(Number);
  for (let i = 0; i < 3; i++) {
    if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) < (pb[i] || 0);
  }
  return false;
}

async function iniciar() {
  $('versao-app').textContent = VERSAO;

  try {
    estado.config = await api('config.php');
    if (estado.config.ok && versaoMenor(VERSAO, estado.config.versao_minima)) {
      mostrar('atualizar');
      return;
    }
  } catch {
    // Offline: segue com o que estiver salvo. Na versão de teste, avisa que o servidor não respondeu
    // (o endereço do túnel muda quando ele é religado).
    if (__DESENVOLVIMENTO__) {
      $('servidor-teste-status').textContent = ' — sem resposta. O endereço mudou? Toque em “alterar”.';
      $('servidor-teste').classList.add('erro');
    }
  }

  const token = await lerJson('token');
  if (!token) {
    const aviso = sessionStorage.getItem('aviso_login');   // deixado por encerrarSessao
    sessionStorage.removeItem('aviso_login');
    avisoLogin(aviso);
    mostrar(aviso ? 'login' : 'inicio');
    return;
  }

  definirToken(token);
  estado.usuario = await lerJson('usuario');
  let atualizado;
  try {
    atualizado = await carregarPerfil();
  } catch {
    atualizado = null; // offline: entra com os dados salvos (o mapa usa as vagas em cache)
  }
  if (atualizado === false) {
    // Token recusado: quandoSessaoExpirar já reinicia o app na tela de login.
    if (estado.tela !== 'login') mostrar('inicio');
    return;
  }
  if (estado.usuario) irAposLogin();
  else mostrar('inicio');
}

if (!nativo) console.info('Vagas Especiais ' + VERSAO + ' (modo navegador) — API: ' + API);
// Só depois de todos os scripts (shared/mapa.js vem depois deste): senão o mapa pode ser aberto antes de existir.
document.addEventListener('DOMContentLoaded', iniciar);
