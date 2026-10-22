# Vagas Especiais Niterói — MVP

PWA para localizar vagas de estacionamento reservadas para **pessoas com deficiência (PCD)** e
**pessoas idosas** em Niterói, a partir da base de coordenadas da Nittrans. Sem APIs pagas:
mapa com Leaflet + OpenStreetMap, localização pelo GPS do aparelho e navegação aberta no Waze/Google Maps.

## O que está no MVP (fases 1 a 5 do plano + segurança básica)

| Fase | Entregue |
|---|---|
| 1. Protótipo | Mapa Leaflet, marcadores por categoria (cor + símbolo), agrupamento de marcadores, filtro por categoria e bairro, localização do usuário, lista ordenada por distância |
| 2. Navegação | Botões “Ir com Waze” e “Ir com Google Maps” com a coordenada da vaga |
| 3. Cadastro | Cadastro com categoria e envio de comprovação (PDF/JPG/PNG), login, status da análise, reenvio após recusa |
| 4. Administração | Dashboard com indicadores, usuários, análise de comprovações, CRUD de vagas com seleção no mapa, importação CSV/XLSX com prévia e validação |
| 5. Relatos | Usuário relata problema na vaga; admin responde, muda status e pode inativar a vaga |
| 6. Segurança (parcial) | Senhas com hash, PDO/prepared statements, escape de saída (XSS), CSRF, cabeçalhos de segurança/CSP, documentos fora da área pública e servidos só a admins, registro de acessos, bloqueio após 5 logins errados |
| PWA | Manifest, ícones, service worker (guarda a última lista de vagas para sinal fraco) |

**Ainda não incluído** (fases 6–8): HTTPS/certificado, rotina de backup, política de privacidade
definitiva (há um texto provisório em `public/privacidade.php`), recuperação de senha por e-mail,
testes formais de acessibilidade/usabilidade e publicação no servidor institucional.

## Requisitos

- PHP 8.2.35+, 8.3.36+, 8.4.27+ ou 8.5.12+ com as extensões `pdo_mysql`, `mbstring` e `zip` (esta última só para importar `.xlsx`).
  Versões anteriores têm as falhas críticas CVE-2026-109618 e CVE-2026-109619 (execução remota de código sem login).
  Em produção, deixe `session.upload_progress.enabled = Off` no `php.ini` (o sistema não usa)
- MySQL 5.7+/MariaDB 10.3+
- Apache com `mod_rewrite` e `AllowOverride All` (os `.htaccess` bloqueiam `config/`, `includes/`, `database/` e `storage/`)

## Instalação (Apache + MySQL)

1. Copie a pasta `vagas-especiais/` para o servidor (ex.: `htdocs/vagas-especiais`).
2. Ajuste o acesso ao banco em `config/database.php` (ou pelas variáveis de ambiente `VAGAS_DB_HOST`,
   `VAGAS_DB_NAME`, `VAGAS_DB_USER`, `VAGAS_DB_PASS`).
3. Crie as tabelas e o primeiro administrador:
   ```bash
   php database/install.php --admin=seu@email.gov.br --senha=UmaSenhaForte --nome="Seu Nome"
   ```
   (Alternativa: `mysql -u root -p < database/schema.sql` e depois só o comando com `--admin`.)
4. Garanta que o Apache pode gravar em `storage/documentos/`.
5. Acesse `http://servidor/vagas-especiais/`, entre com o administrador e importe a base em **Admin → Importar base**.

## Rodar localmente sem MySQL (teste rápido)

Com o PHP instalado, use SQLite e o servidor embutido:

```powershell
cd vagas-especiais
$env:VAGAS_DB_DRIVER = "sqlite"
php database/install.php --admin=admin@teste.local --senha=admin12345 --exemplo
php -S localhost:8000 router.php
```

Abra http://localhost:8000. A opção `--exemplo` carrega 30 vagas **fictícias** (`database/exemplo_vagas.csv`)
só para demonstração. A localização do navegador funciona em `localhost`; em outro endereço exige HTTPS.

## Formato da base para importação

CSV (`;`, `,` ou tabulação; UTF-8 ou padrão do Excel) ou Excel `.xlsx`, com cabeçalho na primeira linha:

```
id;logradouro;numero;bairro;categoria;latitude;longitude;status
NIT-0001;Rua Gavião Peixoto;100;Icaraí;PCD;-22.9042;-43.1089;ativa
```

- `id` é o identificador da vaga na base Nittrans — com ele, importações seguintes **atualizam** a vaga em vez de duplicar.
- `categoria`: PCD ou IDOSO (aceita variações como “Deficiente”, “Idosos”).
- Coordenadas em graus decimais (vírgula ou ponto) e dentro dos limites de Niterói; o sistema avisa se parecerem invertidas.
- O admin vê uma prévia com as linhas com erro antes de confirmar. Opcionalmente, pode inativar as vagas que não estão no arquivo (quando o arquivo é a base completa).
- Há uma planilha modelo para baixar na tela de importação.

## API para o app móvel

O app Android/iOS (pasta `../app-mobile`) usa a mesma API, autenticando por **token** em vez de cookie:

- `api/login.php` e `api/cadastro.php` com `modo=app` devolvem `{token, usuario}`; o app envia
  `Authorization: Bearer <token>` nas demais chamadas (ou `X-Auth-Token`, se a hospedagem remover o
  `Authorization`). Tokens valem 90 dias, renovados a cada uso; no banco fica só o hash.
- `api/logout.php` revoga o token · `api/conta.php` exclui a conta (exigência das lojas) ·
  `api/config.php` informa rótulos, link da política de privacidade e versão mínima do app.
- CORS liberado para as origens do app (`capacitor://localhost`, `https://localhost`) e `localhost`
  em qualquer porta; outras origens via variável `VAGAS_CORS_ORIGENS` (separadas por vírgula).
- `VAGAS_APP_VERSAO_MINIMA` (ex.: `1.2.0`) força a atualização de versões antigas do app.

## Busca de endereço no mapa

O campo "Para onde você vai?" sugere, enquanto a pessoa digita e **sem consultar nenhum serviço**:
bairros, ruas com vagas e **todas as ruas e lugares conhecidos de Niterói** (hospitais, escolas, shoppings,
parques, estação das barcas…), tolerando abreviações ("Av", "Dr") e erros de digitação. Essas sugestões vêm
do índice `assets/data/ruas-niteroi.json`, gerado a partir do OpenStreetMap:

```bash
php database/atualizar_ruas.php   # baixa bairro por bairro (alguns minutos); rode a cada ~3 meses
```

O script usa a API pública Overpass, faz pausas entre as consultas, troca de servidor se um estiver
ocupado e retoma de onde parou se for interrompido. Depois de atualizar, gere o app de novo
(`npm run sync` em `app-mobile`) para que ele leve o índice novo. Os dados são © colaboradores do
OpenStreetMap, sob licença ODbL — o crédito aparece no mapa e nos termos de uso.

Ao confirmar a busca (útil para número exato ou lugares fora do índice), o servidor procura o endereço
em `api/geocodificar.php`. Esse endpoint
consulta o **Nominatim** (OpenStreetMap, gratuito) respeitando a política de uso: no máximo 1 consulta por
segundo, resultados guardados por 30 dias em `storage/cache/geocodificacao/` e nenhuma consulta a cada letra.

- `VAGAS_GEOCODER_CONTATO`: e-mail institucional enviado na identificação das consultas (recomendado em produção).
- `VAGAS_GEOCODER_URL`: troca o provedor (ex.: um Nominatim próprio) se o volume crescer — o serviço público
  não é feito para uso intenso.

**Atualizando uma instalação existente:** rode `php database/install.php` de novo — ele cria só as
tabelas que faltam (`api_tokens`) sem apagar dados.

## Rotas dentro do app

Na vaga, **Traçar rota até aqui** mostra o caminho no próprio mapa (tempo, distância, chegada e passo a
passo) e **Iniciar** entra no modo navegação: o mapa segue o GPS, mostra a próxima manobra, fala as
instruções em português, recalcula se a pessoa sair do caminho e avisa na chegada (lembrando a credencial).
Waze e Google Maps continuam disponíveis como opção (“ou abrir no…”), para quem quer trânsito em tempo real.

- Motor de rotas: **Valhalla** com dados do OpenStreetMap, pelo servidor público da FOSSGIS
  (`valhalla1.openstreetmap.de`) — gratuito e sem chave, mas de **uso justo**: o servidor guarda as rotas
  por 24 h e faz no máximo 1 consulta por segundo. Para produção com muitos usuários, rode um Valhalla
  próprio (Docker, com o recorte do RJ) e aponte `VAGAS_ROTAS_URL` para ele.
- Sem trânsito em tempo real (limitação dos dados abertos).
- Privacidade: o ponto de partida vai por POST (não aparece no registro de acessos); as rotas em cache
  são apagadas após 24 h. Ver `public/privacidade.php`.

## Avisos e notificações

O usuário recebe avisos quando o cadastro é aprovado/recusado, quando o acesso é suspenso/liberado e
quando um relato dele é respondido. Os avisos aparecem no perfil (com destaque “Novo” e um pontinho no
botão do perfil) e, se a pessoa ativar em *Perfil → Configurações → Avisos no celular*, como notificação:

- **Site/PWA:** Web Push com chaves VAPID geradas sozinhas em `storage/push/vapid.json`. **Inclua esse
  arquivo no backup** — se ele for perdido, todos precisam reativar os avisos. A notificação vai sem
  conteúdo; o navegador busca o texto em `api/avisos.php` (nenhum dado pessoal passa pelo serviço de push).
- **App Android/iPhone:** Firebase e Apple Push — configuração em `app-mobile/PUBLICACAO_LOJAS.md`, seção 7.
- `VAGAS_PUSH_CONTATO`: e-mail institucional informado aos serviços de push.

O envio acontece no momento da ação do administrador e nunca a impede: se um serviço de push estiver fora,
o aviso fica registrado no perfil do mesmo jeito.

## Estatísticas do dashboard

O dashboard (`admin/index.php`) mostra, para os últimos 7, 30 ou 90 dias:

- **Uso do aplicativo:** usuários ativos, vagas vistas, rotas traçadas, chegadas às vagas e aberturas no
  Waze/Google Maps, com o gráfico **Uso por dia** (passe o mouse para ver os números; há também a tabela).
- **Vagas mais procuradas:** ranking que pesa chegadas (×5), rotas (×3) e vagas vistas (×1).
- **Procura por bairro:** onde as vagas existentes são mais usadas.
- **Onde falta vaga:** destinos buscados sem nenhuma vaga especial a menos de 1 km, no mapa e em tabela.
  É a lista de candidatos a receber vagas novas.
- **Cadastros e base:** novos cadastros, tempo médio de análise das comprovações, fila de análise, relatos
  e vagas ativas.

O app e o site enviam os eventos para `api/eventos.php` (somente usuários logados). Os eventos são
agregados por dia em `estatisticas` (sem identificar a pessoa). Os destinos sem vaga ficam em
`demanda_sem_vaga`, com a posição arredondada para ~100 m. A tabela `uso_diario` guarda só *em que dias*
cada conta usou o mapa, para contar usuários ativos, e é apagada após 90 dias ou quando a conta é
excluída. Nada disso passa por serviços de terceiros.

**Atualizando uma instalação existente:** rode `php database/install.php`. Ele cria as tabelas novas
(`estatisticas`, `demanda_sem_vaga`, `uso_diario`) sem apagar dados.

## Tutorial de primeiro acesso

Na primeira vez que o mapa abre em um aparelho, um passo a passo de 6 telas (`assets/js/tutorial.js`,
usado pelo site e pelo app) explica:

1. as boas-vindas;
2. a liberação da localização, com o pedido do sistema ilustrado para Android, iPhone ou navegador;
3. os alfinetes e filtros;
4. a busca de destino;
5. a rota com voz;
6. os ajustes. Aqui dá para ligar a letra maior na hora.

Na etapa da localização, o próprio tutorial faz o pedido de permissão e mostra o que fazer quando ela está
bloqueada, quando o GPS está desligado ou quando só a localização aproximada foi permitida. Com a
permissão dada, o mapa já abre na posição da pessoa.

- **Rever:** *Perfil → Configurações → Como usar o aplicativo*. No site, use `mapa.php?tutorial=1`.
- **"Já visto":** fica salvo no aparelho (`localStorage` no site, Preferences no app). Trocar de conta
  não faz o tutorial voltar.

## Letra maior e alto contraste

Em *Perfil → Configurações* (e no botão “Letra maior” das telas de entrada). Vale para o aparelho: aumenta o
texto em ~19%, usa texto preto, bordas pretas, foco amarelo e marcadores maiores no mapa.

## Estrutura

```
vagas-especiais/
├── public/      páginas do usuário (index, login, cadastro, mapa, perfil, privacidade) + manifest e service worker
├── admin/       painel (dashboard, usuários, comprovações, vagas, importar, relatos, documento)
├── api/         endpoints JSON (login, cadastro, vagas, relatos, perfil, rota, avisos, push, eventos)
├── config/      database.php
├── includes/    bootstrap, auth, admin_auth, security, dominio (regras), importador, layout
├── storage/     documentos/ (comprovações — privado) e o banco SQLite de desenvolvimento
├── assets/      css, js (app, mapa, admin), img (ícones)
└── database/    schema.sql (MySQL), schema.sqlite.sql, install.php, exemplo_vagas.csv
```

## Perfis de usuário

Cada pessoa tem um ou mais perfis (tabela `perfis`), e cada perfil aprovado libera um tipo de vaga no mapa:

| Perfil | Vagas que vê | Aprovação |
|---|---|---|
| PCD | `PCD` | Comprovação + análise da equipe |
| Idoso | `IDOSO` | Comprovação + análise da equipe |
| Rotativo | `ROTATIVO` (estacionamento rotativo) | **Todos têm**, já aprovado, sem comprovação |

- **No cadastro:** todos ganham o rotativo. Quem marca PCD ou idoso envia uma comprovação para cada um, e
  a conta só é criada com todas elas. Mais tarde, PCD/idoso podem ser adicionados em *Perfil → Adicionar perfil*.
- **Na análise:** a comprovação aprova ou recusa só o perfil que ela comprova. Em *Usuários*, o
  administrador pode revogar ou liberar um perfil PCD/idoso, ou suspender a conta inteira.
- **Quem pode usar o mapa:** quem tem algum perfil aprovado e não está com a conta suspensa. Os
  administradores veem tudo.
- **Quem tinha cadastro antes desta versão:** `php database/install.php` converte a categoria e o status
  antigos em perfis e dá o rotativo a quem ainda não tem. Ele pode rodar de novo sem efeito. Teste:
  `php database/teste_perfis.php`.

## Fluxos

- **Usuário:** cadastro com perfis → rotativo liberado na hora; PCD/idoso “Em análise” → admin aprova → vagas daquele tipo aparecem no mapa → rota até a vaga.
- **Manutenção:** usuário relata problema → admin analisa → corrige/inativa a vaga → mapa atualizado.

## Antes de publicar em produção

- Ativar HTTPS e descomentar o redirecionamento no `.htaccess` da raiz (o cookie de sessão passa a ser `Secure` automaticamente).
- Usar um usuário de banco próprio (não `root`) com senha forte.
- Configurar backup do banco **e** da pasta `storage/documentos/`.
- Revisar o texto de privacidade/termos com o DPO e o jurídico.
- Mapa: os tiles do OpenStreetMap têm [política de uso](https://operations.osmfoundation.org/policies/tiles/); para tráfego alto, considerar um servidor de tiles próprio ou um provedor dedicado.
