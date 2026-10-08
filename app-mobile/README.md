# Vagas Especiais — app Android e iOS

App nativo feito com [Capacitor](https://capacitorjs.com) a partir do mesmo código do site.
As telas ficam **dentro do app** (`www/`); o servidor PHP (`../vagas-especiais`) é usado só como API.

```
app-mobile/
├── src/                 código do app (é aqui que se edita)
│   ├── index.html       telas: início, login, cadastro, perfil, mapa, atualização obrigatória
│   ├── js/main.js       fluxos e telas
│   ├── js/api.js        chamadas à API com token
│   ├── js/plataforma.js GPS, armazenamento e links externos (plugins nativos)
│   ├── css/             ajustes visuais do app
│   ├── config.js        endereço da API de PRODUÇÃO
│   └── config.local.js  endereço da API de desenvolvimento (não vai para o git)
├── scripts/build.mjs    monta www/ (gerado — não editar)
├── assets/              arte do ícone e da tela de abertura
├── android/             projeto Android Studio
└── ios/                 projeto Xcode
```

**Compartilhado com o site:** `www/shared/` recebe `app.css`, `mapa.js` e os ícones de
`../vagas-especiais/assets/` a cada build. Uma correção no mapa vale para o site e para o app.

## Desenvolvimento (no navegador)

Pré-requisito: Node.js 20+ e o XAMPP rodando com o projeto em `http://localhost/vagas-especiais`.

```powershell
npm install
npm run dev        # http://localhost:8100
```

No navegador, os plugins usam as APIs web (localStorage, GPS do navegador). Use o modo celular das
ferramentas do desenvolvedor (F12 → ícone de celular).

## Rodar no celular ou emulador

| Plataforma | Precisa de | Comando |
|---|---|---|
| Android | Android Studio (ou SDK + JDK 21) | `npm run android` → ▶ Run no Android Studio |
| iOS | Mac com Xcode 16+ | `npm run ios` → ▶ Run no Xcode |

O app precisa alcançar o servidor:
- **Emulador Android:** em `src/config.local.js`, use `http://10.0.2.2/vagas-especiais/api`.
  Para servidor sem HTTPS, defina também `"allowMixedContent": true` em `capacitor.config.json` (só em teste).
- **Celular de verdade:** o servidor precisa estar acessível pela rede. O mais simples é um túnel HTTPS
  (ex.: `cloudflared tunnel --url http://localhost`) e usar a URL gerada em `config.local.js`.

## Versão para as lojas

1. Edite `src/config.js` com a URL **HTTPS** da API de produção.
2. Aumente a versão em `package.json` e, em cada release:
   - Android: `versionCode` (+1) e `versionName` em `android/app/build.gradle`;
   - iOS: *Version* e *Build* no Xcode (alvo App → General).
3. `npm run release` — gera o build de produção (falha se a API não for HTTPS) e sincroniza Android e iOS.
4. Android Studio → *Build → Generate Signed App Bundle* (.aab).  Xcode → *Product → Archive* → *Distribute App*.

O passo a passo completo de publicação está em [PUBLICACAO_LOJAS.md](PUBLICACAO_LOJAS.md).

## Versão mínima

O servidor informa a versão mínima aceita (`VAGAS_APP_VERSAO_MINIMA`, padrão `1.0.0`). Versões
abaixo dela mostram “Atualize o aplicativo” — use quando uma mudança na API quebrar versões antigas.

## Ícone e tela de abertura

Arte em `assets/` (`icon-only.png`, `icon-foreground.png`, `icon-background.png`, `splash.png`, `splash-dark.png`).
Depois de alterar:

```powershell
npx @capacitor/assets generate --iconBackgroundColor '#0b5cab' --splashBackgroundColor '#ffffff' --splashBackgroundColorDark '#101828'
```
