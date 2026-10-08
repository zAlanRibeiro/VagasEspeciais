/**
 * Monta a pasta www/ (o que vai dentro do app):
 *  - telas e estilos próprios do app (src/)
 *  - estilos, mapa e ícones compartilhados com o site (../vagas-especiais/assets)
 *  - Leaflet (node_modules)
 *  - código do app empacotado com esbuild (src/js/main.js → www/build/app.js)
 */
import { build } from 'esbuild';
import { cpSync, mkdirSync, rmSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const www = join(raiz, 'www');
const site = join(raiz, '..', 'vagas-especiais', 'assets');
const modulos = join(raiz, 'node_modules');

rmSync(www, { recursive: true, force: true });
mkdirSync(www, { recursive: true });

// Telas do app, com o sprite de ícones do site embutido (os <use href="#i-..."> dependem dele)
const icones = readFileSync(join(site, 'img', 'icones.svg'), 'utf8');
const html = readFileSync(join(raiz, 'src', 'index.html'), 'utf8').replace('<!-- ICONES -->', icones);
writeFileSync(join(www, 'index.html'), html);
cpSync(join(raiz, 'src', 'css'), join(www, 'css'), { recursive: true });

// Configuração (endereço da API). Em desenvolvimento, src/config.local.js tem prioridade (não vai para o git).
// Em produção (--producao) usa sempre src/config.js e exige HTTPS.
const producao = process.argv.includes('--producao');
const configLocal = join(raiz, 'src', 'config.local.js');
const configUsada = !producao && existsSync(configLocal) ? configLocal : join(raiz, 'src', 'config.js');
if (producao) {
  const conteudo = readFileSync(configUsada, 'utf8');
  if (!/apiUrl:\s*'https:\/\//.test(conteudo) || conteudo.includes('SEU-DOMINIO')) {
    console.error('✘ src/config.js precisa apontar para a API de produção em HTTPS antes do build das lojas.');
    process.exit(1);
  }
}
cpSync(configUsada, join(www, 'config.js'));

// Compartilhado com o site: uma única fonte para estilos, lógica do mapa e ícones.
cpSync(join(site, 'css', 'app.css'), join(www, 'shared', 'app.css'));
cpSync(join(site, 'js', 'mapa.js'), join(www, 'shared', 'mapa.js'));
cpSync(join(site, 'js', 'tutorial.js'), join(www, 'shared', 'tutorial.js'));
cpSync(join(site, 'img'), join(www, 'shared', 'img'), { recursive: true });
cpSync(join(site, 'fonts'), join(www, 'fonts'), { recursive: true }); // app.css usa ../fonts/
if (existsSync(join(site, 'data'))) {
  cpSync(join(site, 'data'), join(www, 'shared', 'data'), { recursive: true }); // índice de ruas (OpenStreetMap)
}

// Leaflet local (sem CDN: o app funciona offline e não depende de terceiros para abrir)
cpSync(join(modulos, 'leaflet', 'dist'), join(www, 'vendor', 'leaflet'), { recursive: true });
cpSync(join(modulos, 'leaflet.markercluster', 'dist'), join(www, 'vendor', 'markercluster'), { recursive: true });

// Push no Android depende do Firebase (google-services.json). Sem ele, chamar o plugin derruba o app.
const firebaseAndroid = existsSync(join(raiz, 'android', 'app', 'google-services.json'));

// Versão do app exibida e comparada com a versão mínima exigida pelo servidor
const versao = JSON.parse(readFileSync(join(raiz, 'package.json'), 'utf8')).version;

await build({
  entryPoints: [join(raiz, 'src', 'js', 'main.js')],
  bundle: true,
  format: 'iife',
  target: ['chrome89', 'safari15'],
  minify: producao,
  sourcemap: !producao,
  define: {
    __VERSAO_APP__: JSON.stringify(versao),
    __DESENVOLVIMENTO__: JSON.stringify(!producao),
    __FIREBASE_ANDROID__: JSON.stringify(firebaseAndroid),
  },
  outfile: join(www, 'build', 'app.js'),
  logLevel: 'warning',
});

writeFileSync(join(www, 'versao.txt'), versao + '\n');
console.log('✔ www/ gerado (versão ' + versao + (producao ? ', PRODUÇÃO' : ', desenvolvimento: ' + basename(configUsada))
  + (firebaseAndroid ? '' : ', sem Firebase: push desligado no Android') + ')');
