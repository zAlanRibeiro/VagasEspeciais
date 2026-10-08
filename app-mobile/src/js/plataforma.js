/*
 * Recursos do aparelho via plugins do Capacitor. No navegador (desenvolvimento), os mesmos
 * plugins usam as APIs web equivalentes (localStorage, navigator.geolocation, window.open).
 */
import { Capacitor } from '@capacitor/core';
import { Preferences } from '@capacitor/preferences';
import { Geolocation } from '@capacitor/geolocation';
import { AppLauncher } from '@capacitor/app-launcher';
import { App } from '@capacitor/app';
import { PushNotifications } from '@capacitor/push-notifications';
import { TextToSpeech } from '@capacitor-community/text-to-speech';
import { KeepAwake } from '@capacitor-community/keep-awake';

/* ---------- Navegação: voz e tela acesa ---------- */

/** Fala a instrução com a voz do aparelho (no navegador, a voz do próprio navegador). */
export async function falar(texto) {
  try {
    await TextToSpeech.stop();
    await TextToSpeech.speak({ text: texto, lang: 'pt-BR', rate: 1.0, volume: 1.0, category: 'playback' });
  } catch { /* sem voz em português instalada: segue só com o texto na tela */ }
}

export async function manterTelaLigada(ligar) {
  try {
    if (ligar) await KeepAwake.keepAwake();
    else await KeepAwake.allowSleep();
  } catch { /* sem suporte: a tela segue a configuração do aparelho */ }
}

export const nativo = Capacitor.isNativePlatform();
export const plataforma = Capacitor.getPlatform(); // 'android' | 'ios' | 'web'
export { App };

/* ---------- Armazenamento (no aparelho: SharedPreferences / UserDefaults) ---------- */

export async function lerJson(chave) {
  const { value } = await Preferences.get({ key: chave });
  if (value === null) return null;
  try { return JSON.parse(value); } catch { return null; }
}

export function gravarJson(chave, valor) {
  return Preferences.set({ key: chave, value: JSON.stringify(valor) });
}

export function remover(chave) {
  return Preferences.remove({ key: chave });
}

/* ---------- Links externos (Waze, Google Maps, política de privacidade) ---------- */

export async function abrirExterno(url) {
  if (!nativo) {
    window.open(url, '_blank', 'noopener');
    return;
  }
  // Abre no app correspondente (Waze/Google Maps) quando instalado; senão, no navegador.
  await AppLauncher.openUrl({ url });
}

/* ---------- Avisos no celular (push nativo) ----------
 * Android: Firebase Cloud Messaging (precisa do google-services.json no projeto Android).
 * iPhone: Apple Push (precisa da capacidade "Push Notifications" ativada no Xcode).
 * No navegador (desenvolvimento) não há push nativo. */

/* global __FIREBASE_ANDROID__ */
// iPhone usa o push da Apple direto; no Android só com o Firebase no build (senão o plugin derruba o app).
export const pushConfigurado = plataforma === 'ios' || (plataforma === 'android' && __FIREBASE_ANDROID__);
export const pushDisponivel = nativo && pushConfigurado;
export const tipoPush = plataforma === 'ios' ? 'apns' : 'fcm';

/** 'granted' | 'denied' | 'prompt' */
export async function permissaoPush() {
  if (!nativo) return 'denied';
  const p = await PushNotifications.checkPermissions();
  return p.receive === 'granted' ? 'granted' : (p.receive === 'denied' ? 'denied' : 'prompt');
}

/** Pede permissão e registra o aparelho. Resolve com o token; rejeita com Error('negado' | 'registro'). */
export async function ativarPush() {
  let p = await PushNotifications.checkPermissions();
  if (p.receive !== 'granted') p = await PushNotifications.requestPermissions();
  if (p.receive !== 'granted') throw new Error('negado');

  const ouvintes = [];
  try {
    return await new Promise((resolver, rejeitar) => {
      PushNotifications.addListener('registration', (t) => resolver(t.value)).then((h) => ouvintes.push(h));
      PushNotifications.addListener('registrationError', () => rejeitar(new Error('registro'))).then((h) => ouvintes.push(h));
      PushNotifications.register().catch(() => rejeitar(new Error('registro')));
      setTimeout(() => rejeitar(new Error('registro')), 20000);
    });
  } finally {
    ouvintes.forEach((h) => h.remove());
  }
}

export async function desativarPush() {
  if (pushDisponivel && PushNotifications.unregister) await PushNotifications.unregister().catch(() => {});
}

/** Toque na notificação (app em segundo plano) e chegada com o app aberto. */
export function ouvirPush(aoTocar, aoChegar) {
  if (!pushDisponivel) return;
  PushNotifications.addListener('pushNotificationActionPerformed', (a) => aoTocar((a.notification && a.notification.data) || {}));
  PushNotifications.addListener('pushNotificationReceived', () => aoChegar());
}

/* ---------- Localização ---------- */

/** 'granted' (precisa) | 'aproximada' (Android: a pessoa escolheu "Aproximada") | 'prompt' | 'denied' */
function estadoPermissao(p) {
  if (p.location === 'granted') return 'granted';
  if (p.coarseLocation === 'granted') return 'aproximada';
  return p.location === 'denied' ? 'denied' : 'prompt';   // 'prompt-with-rationale' ainda pode perguntar
}

function estadoDoErro(e) {
  // O Android recusa a consulta quando a localização do aparelho está desligada.
  return /not enabled|disabled|services/i.test(String((e && e.message) || '')) ? 'desligado' : 'prompt';
}

/** Permissão de localização para o tutorial e o mapa (sem perguntar nada à pessoa). */
export async function permissaoLocalizacao() {
  if (!nativo) return permissaoWeb();
  try {
    return estadoPermissao(await Geolocation.checkPermissions());
  } catch (e) {
    return estadoDoErro(e);
  }
}

/** Mostra o pedido de permissão do sistema (se ainda couber) e devolve o estado final. */
export async function pedirLocalizacao() {
  if (!nativo) return pedirWeb();
  try {
    let p = await Geolocation.checkPermissions();
    if (p.location !== 'granted') p = await Geolocation.requestPermissions({ permissions: ['location'] });
    return estadoPermissao(p);
  } catch (e) {
    return estadoDoErro(e);
  }
}

// Modo navegador (desenvolvimento): as mesmas respostas a partir das APIs web.
async function permissaoWeb() {
  if (!('geolocation' in navigator)) return 'indisponivel';
  try {
    if (navigator.permissions) return (await navigator.permissions.query({ name: 'geolocation' })).state;
  } catch { /* sem a API de permissões */ }
  return 'prompt';
}

function pedirWeb() {
  return new Promise((resolver) => {
    if (!('geolocation' in navigator)) { resolver('indisponivel'); return; }
    navigator.geolocation.getCurrentPosition(() => resolver('granted'),
      (err) => resolver(err.code === 1 ? 'denied' : (err.code === 2 ? 'desligado' : 'granted')),
      { enableHighAccuracy: false, maximumAge: 60000, timeout: 15000 });
  });
}

/** Como navigator.geolocation.watchPosition (erro.code 1 = permissão negada); resolve com a função que desliga o GPS. */
export async function observarPosicao(ok, erro, altaPrecisao) {
  try {
    let precisa = true;
    if (nativo) {
      let perm = await Geolocation.checkPermissions();
      if (perm.location !== 'granted' && perm.coarseLocation !== 'granted') {
        perm = await Geolocation.requestPermissions({ permissions: ['location'] });
      }
      const estado = estadoPermissao(perm);
      if (estado !== 'granted' && estado !== 'aproximada') {
        erro({ code: 1 });
        return null;
      }
      // Só com a aproximada, não pede alta precisão: senão o Android repete o pedido de permissão toda vez.
      precisa = estado === 'granted';
    }
    const id = await Geolocation.watchPosition({ enableHighAccuracy: !!altaPrecisao && precisa, maximumAge: 15000, timeout: 20000 }, (pos, err) => {
      if (err || !pos) {
        const msg = String((err && err.message) || '');
        if (/denied|permission/i.test(msg)) erro({ code: 1 });
        else if (/disabled|not enabled|services/i.test(msg)) erro({ code: -1, message: 'Ative a localização (GPS) do aparelho.' });
        else erro({ code: 2 });
        return;
      }
      ok(pos);
    });
    return () => Geolocation.clearWatch({ id });
  } catch (e) {
    const msg = String((e && e.message) || '');
    if (/disabled|not enabled|services/i.test(msg)) erro({ code: -1, message: 'Ative a localização (GPS) do aparelho.' });
    else erro({ code: /denied|permission/i.test(msg) ? 1 : 2 });
  }
}
