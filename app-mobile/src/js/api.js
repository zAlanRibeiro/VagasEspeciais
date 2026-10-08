/*
 * Cliente da API do Vagas Especiais. Autenticação por token (Authorization: Bearer).
 */

/* global __DESENVOLVIMENTO__ */
const API_PADRAO = String((window.VAGAS_CONFIG && window.VAGAS_CONFIG.apiUrl) || '').replace(/\/+$/, '');

/** Só nas versões de teste: aponta para outro servidor (ex.: túnel temporário) sem gerar o app de novo. */
export let API = (__DESENVOLVIMENTO__ && localStorage.getItem('servidor_teste')) || API_PADRAO;

export function definirServidorTeste(endereco) {
  let url = String(endereco || '').trim().replace(/\/+$/, '');
  if (!url) {
    localStorage.removeItem('servidor_teste');
    API = API_PADRAO;
    return API;
  }
  if (!/^https?:\/\//.test(url)) url = 'https://' + url;
  if (!/\/api$/.test(url)) url += '/vagas-especiais/api';
  localStorage.setItem('servidor_teste', url);
  API = url;
  return API;
}

export class ErroConexao extends Error {
  constructor() { super('Sem conexão com o servidor. Verifique sua internet e tente novamente.'); }
}

let token = null;
let aoExpirar = null;

export function definirToken(valor) { token = valor; }
export function temToken() { return !!token; }
/** Chamado quando o servidor recusa o token (expirado, revogado ou conta excluída). */
export function quandoSessaoExpirar(fn) { aoExpirar = fn; }

/**
 * @param {string} caminho  ex.: 'vagas.php'
 * @param {{metodo?: string, dados?: FormData}} op
 * @returns {Promise<object>} JSON da API com o campo extra "status" (HTTP)
 */
export async function api(caminho, op = {}) {
  const headers = {};
  if (token) headers.Authorization = 'Bearer ' + token;

  let resp;
  try {
    resp = await fetch(API + '/' + caminho, { method: op.metodo || (op.dados ? 'POST' : 'GET'), headers, body: op.dados });
  } catch {
    throw new ErroConexao();
  }

  let d;
  try {
    d = await resp.json();
  } catch {
    d = { ok: false, erro: 'Resposta inesperada do servidor (' + resp.status + ').' };
  }
  d.status = resp.status;

  if (resp.status === 401 && token && aoExpirar) {
    await aoExpirar();
  }
  return d;
}
