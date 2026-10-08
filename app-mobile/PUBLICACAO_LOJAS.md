# Publicação na Play Store e na App Store — checklist

O que precisa estar pronto, em ordem. Itens marcados ✅ já estão feitos no código.

## 1. Decisões que travam o resto

- [ ] **Quem publica.** O app aparece nas lojas com o nome da conta de desenvolvedor. Se for da
      Prefeitura/Nittrans, as contas devem ser abertas no nome do órgão (pedem CNPJ e número **D-U-N-S**,
      gratuito, solicitado em dnb.com — leva alguns dias).
- [ ] **Identificador do app** — hoje `br.gov.rj.niteroi.vagasespeciais` (em `capacitor.config.json`).
      **Não pode ser mudado depois da primeira publicação.** Use o domínio de quem publica, ao contrário.
      Para trocar: `capacitor.config.json` (`appId`), `android/app/build.gradle` (`namespace` e
      `applicationId`), mover `MainActivity.java` para a pasta do novo pacote e, no Xcode, *Bundle Identifier*.
- [ ] **Servidor de produção com HTTPS e domínio** (ver README do servidor). As lojas exigem HTTPS e o
      app só é gerado para produção com uma URL `https://` em `src/config.js`.

## 2. Contas

| | Google Play Console | Apple Developer Program |
|---|---|---|
| Custo | US$ 25, uma vez | US$ 99 por ano (órgãos públicos podem pedir isenção) |
| Organização | D-U-N-S + verificação | D-U-N-S + verificação |
| Para compilar | Qualquer computador com Android Studio | **Mac com Xcode** (obrigatório) |

## 3. Exigências das lojas

- ✅ **Excluir conta dentro do app** (Perfil → Excluir minha conta) — obrigatório nas duas lojas.
- ✅ **Link web para exclusão de conta** (exigido pela Play): `https://SEU-DOMINIO/vagas-especiais/public/privacidade.php#excluir-conta`
- ✅ **Textos das permissões** (iOS): localização, câmera e fotos explicadas em português.
- ✅ Localização só com o app aberto (sem localização em segundo plano: não precisa de declaração especial na Play).
- ✅ Telas e mapa empacotados no app, com GPS nativo e vagas salvas para uso sem internet
      (reduz o risco de recusa da Apple por “app que é só um site”, diretriz 4.2).
- [ ] **Política de privacidade definitiva** publicada em URL pública (o texto atual é provisório —
      revisar com DPO/jurídico). A URL vai nas duas lojas.
- [ ] **Conta de demonstração para os revisores.** O mapa só abre para cadastro aprovado, então crie
      uma conta já aprovada e informe e-mail/senha nas notas de revisão, explicando:
      *“O acesso exige comprovação de deficiência ou idade, analisada pela Nittrans. Use a conta de teste
      abaixo, já aprovada.”*

## 4. Formulários de privacidade

Responder igual nas duas lojas (Play: *Segurança dos dados*; Apple: *Privacidade do app*):

| Dado | Coletado? | Finalidade | Observação |
|---|---|---|---|
| Nome, e-mail | Sim | Gestão da conta | Vinculado ao usuário |
| Documento de comprovação (foto/PDF) | Sim | Verificar direito à vaga | Pode conter **dado de saúde** (laudo) — dado sensível |
| Localização (precisa) | Sim, só ao pedir rota | Calcular a rota até a vaga | Ponto de partida enviado ao servidor e ao Valhalla (OpenStreetMap); rota em cache por até 24 h, sem vínculo com a conta; não registrada no log de acessos |
| Relatos de problemas | Sim | Manutenção da base | Ficam anônimos se a conta é excluída |
| Endereços buscados no mapa | Só quando não há vaga a menos de 1 km | Encontrar o local; planejar novas vagas | Enviados ao OpenStreetMap (Nominatim) sem dados da pessoa; o servidor guarda só o nome do destino e a posição arredondada (~100 m), sem vínculo com a conta |
| Identificador do aparelho para notificações | Sim, se a pessoa ativar | Enviar avisos | Apagado ao desligar os avisos, sair da conta ou excluí-la |
| Interações no app (vagas abertas, rotas, chegadas) | Sim, agregado | Estatísticas do painel (análise) | Somas por dia e por vaga, sem identificar a pessoa |
| Dias em que a conta usou o mapa | Sim | Contar usuários ativos (análise) | Vinculado à conta; apagado após 90 dias ou ao excluir a conta |
| Anúncios / rastreamento entre apps | Não | — | Sem SDK de analytics de terceiros, sem anúncios |

Na Play, isso corresponde a marcar **"Atividade no app → Interações com o app"** (finalidade: *Análise*) e **"Localização aproximada"** (finalidade: *Funcionalidade do app* e *Análise*). Na Apple: **"Dados de uso → Interação com o produto"**, *não* usado para rastreamento.

Dados criptografados em trânsito (HTTPS): **sim**. Usuário pode pedir exclusão: **sim**.

## 5. Página da loja

- Nome: **Vagas Especiais Niterói** · Categoria: *Mapas e navegação* · Classificação: livre
- Descrição curta (Play, até 80 caracteres): *Vagas de estacionamento para PCD e idosos em Niterói.*
- Capturas de tela: celular (Play: mínimo 2; Apple: 6,9" e 6,5"). Sugestão: mapa, lista por distância,
  detalhe com botões Waze/Maps, cadastro.
- Play: ícone 512×512 (`assets/icon-only.png` redimensionado) e imagem de destaque 1024×500.
- Mapa: o app usa os mapas do OpenStreetMap. Em uso amplo, contratar um provedor de mapas ou servidor
  próprio (a política de uso dos servidores gratuitos do OSM não permite tráfego alto).

## 6. Gerar e enviar

**Android**
1. `npm run release`
2. Android Studio → *Build → Generate Signed App Bundle* → criar a **chave de assinatura** (keystore).
   ⚠️ Guarde a keystore e as senhas em local seguro e com backup: sem elas não há como atualizar o app.
   (Recomendado: ativar a *Assinatura de apps do Google Play*.)
3. Play Console → Teste interno → enviar o `.aab` → testar → Produção.

**iOS** (no Mac)
1. `npm install && npm run release`
2. Xcode → selecionar o time da conta Apple → *Product → Archive* → *Distribute App → App Store Connect*.
3. TestFlight para teste → enviar para revisão.

## 7. Avisos no celular (notificações)

A central de avisos (no perfil) já funciona sem configurar nada. Para a notificação **aparecer no celular
com o app fechado**, cada loja exige uma credencial — sem ela, o app avisa “ainda não disponível” e segue normal.

**Android (Firebase, gratuito)**
1. Em console.firebase.google.com, crie um projeto e adicione um app Android com o identificador do app
   (hoje `br.gov.rj.niteroi.vagasespeciais`).
2. Baixe o `google-services.json` e coloque em `app-mobile/android/app/`. Gere o app de novo.
3. No Firebase: *Configurações do projeto → Contas de serviço → Gerar nova chave privada*. Salve o arquivo
   no servidor como `vagas-especiais/storage/push/firebase.json` (pasta privada — nunca publique esse arquivo).

**iPhone (Apple Push)**
1. No Xcode, alvo *App → Signing & Capabilities → + Capability → Push Notifications*.
2. Em developer.apple.com → *Keys*, crie uma chave com “Apple Push Notifications service”. Baixe o `.p8`.
3. No servidor: salve como `vagas-especiais/storage/push/apns.p8` e defina `VAGAS_APNS_KEY_ID` (id da chave),
   `VAGAS_APNS_TEAM_ID` (id do time) e, na versão da loja, `VAGAS_APNS_PRODUCAO=1`.

**Site / PWA:** funciona sozinho (padrão Web Push). No iPhone, só com o site adicionado à Tela de Início.
Defina `VAGAS_PUSH_CONTATO` com o e-mail institucional (exigido pelos serviços de push).

Para conferir: `api/config.php` mostra `"push": {"web": true, "fcm": true, "apns": true}` quando tudo está configurado.

## 8. A cada nova versão

Aumentar a versão (`package.json`, `versionCode`/`versionName`, Version/Build no Xcode), `npm run release`,
gerar e enviar. Se a mudança no servidor quebrar versões antigas, subir `VAGAS_APP_VERSAO_MINIMA` no servidor.
