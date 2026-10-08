# Vagas Especiais: desempenho e orçamento do Google Maps

*Atualizado em 08/10/2026*

## Resumo

- **O app está leve e rápido.** Num celular intermediário, as vagas aparecem em 0,2 s, o processamento fica em torno de 1% a 2% e a memória usada vai de 2 a 6 MB.
- **O mapa precisa ser trocado antes de publicar nas lojas.** Hoje usamos o servidor gratuito do OpenStreetMap, cuja política de uso não permite uso intenso por aplicativos publicados.
- **Recomendação:** usar o **Google Maps nativo no app**, que é gratuito e sem limite de uso, e o **Google Maps JavaScript no site**, que tem 10 mil aberturas grátis por mês. O custo fica praticamente só no desenvolvimento, estimado em **80 a 120 horas**.

## Resultados dos testes de desempenho

Os testes rodaram em computador, simulando um celular intermediário (processador 4× mais lento) com o app na versão do APK.

### Consumo de dados móveis

| Situação | Dados | Observação |
|---|---|---|
| Abrir o app pela 1ª vez | 473 KB | 98% são as imagens do mapa |
| Abrir de novo (com cache) | 15 KB | mapa já guardado no celular |
| Mapa aberto, parado (1 min) | 0 KB | — |
| Rota + navegação (2 min) | 245 KB | o mapa acompanha o carro |

Uma ida até a vaga gasta de 0,5 a 1,5 MB. Com 2 viagens por dia, isso dá de 30 a 90 MB por mês.

### Processamento e memória (celular intermediário)

| Medida | Resultado |
|---|---|
| Tempo até as vagas aparecerem | 0,2 s (0,4 s com 2.000 vagas) |
| Processador com o mapa parado | ~1% |
| Processador navegando | ~1,6% |
| Memória usada | 2 a 6 MB |
| Busca de ruas (3.337 ruas embutidas) | < 10 ms |

### Servidor (máquina de desenvolvimento)

| Base de vagas | Requisições por segundo | Tempo de resposta (95% dos pedidos) | Falhas |
|---|---|---|---|
| Atual (43 vagas) | 1.100 a 4.000 | até 33 ms | 0 |
| Simulada com 2.000 vagas | 90 | até 170 ms | 0 |

### Bateria

Ainda falta medir em um celular real conectado ao computador. Pelo código, o maior consumo vem da tela acesa e do GPS durante a navegação, como em qualquer app de rotas.

## Correções já feitas após os testes

1. **GPS:** passou a desligar quando o app vai para segundo plano ou quando a pessoa sai da tela do mapa, e religa ao voltar. A alta precisão, que gasta mais bateria, fica ligada só durante a navegação.
2. **Compressão:** as respostas do servidor agora vão comprimidas. A lista de vagas ficou cerca de 80% menor; com uma base de 2.000 vagas, cada abertura baixaria cerca de 15 KB em vez de 223 KB.

## Opções para trocar o mapa (Google Maps)

Preços oficiais do Google Maps Platform, válidos desde março de 2025, em dólar e convertidos a **R$ 5,50 por dólar** (referência; usar o câmbio do dia).

| Opção | Como funciona | Custo de uso | Desenvolvimento (estimativa) |
|---|---|---|---|
| **A. Mapa nativo do Google no app** | O mapa do app passa a ser o do Google (plugin oficial `@capacitor/google-maps`) | **Grátis e sem limite**, desde que não se usem estilos de mapa configurados na nuvem ("Map ID") | 80 a 120 h |
| **B. Google Maps JavaScript** | Troca o mapa dentro da tela atual do app e do site | 10 mil aberturas grátis por mês; depois, US$ 7 por mil | 40 a 60 h |
| C. Só as imagens do Google sobre o mapa atual | Mantém o mapa atual e troca só as imagens | 100 mil imagens grátis por mês; depois, US$ 0,60 por mil. Cada uso consome cerca de 40 imagens | 8 a 16 h |

### Custo mensal estimado

| Usuários ativos | A. Nativo no app | B. JavaScript no app | C. Só as imagens |
|---|---|---|---|
| 1 mil | R$ 0 | ~R$ 385 | ~R$ 2.300 |
| 5 mil | R$ 0 | ~R$ 3.465 | ~R$ 10.900 |
| 20 mil | R$ 0 | ~R$ 12.700 | mais de R$ 23.000 |

**Premissas:** cada usuário abre o app 20 vezes por mês, e cada abertura do mapa é cobrada uma vez (opção B) ou consome cerca de 40 imagens (opção C). Antes de decidir, confirme o número real de usuários.

### Recomendação

- **No app: opção A.** O custo de uso fica zero em qualquer número de usuários.
- **No site: opção B.** As 10 mil aberturas grátis por mês devem cobrir o acesso pelo navegador.
- **A opção C não compensa:** é a mais barata de implantar, mas a mais cara de manter.

### Opcional: trânsito em tempo real nas rotas

Hoje as rotas são calculadas de graça, com dados do OpenStreetMap, e não mostram trânsito. Para passar a usar as rotas do Google:

- **Sem trânsito:** 10 mil rotas grátis por mês, depois US$ 5 por mil.
- **Com trânsito em tempo real:** usa um plano de preço maior.

## Para contratar

- **Conta de faturamento:** é preciso ter uma no Google Cloud, mesmo no uso gratuito.
- **Limite de gastos:** configurar um alerta e um teto no painel do Google.
- **Segurança da chave:** restringir a chave de acesso ao app (Android e iPhone) e ao domínio do site.
- **Forma de contratação:** na Prefeitura, costuma ser feita por um revendedor oficial do Google, por causa da nota fiscal e do processo de compra.

## Fontes

- [Preços do Google Maps Platform](https://developers.google.com/maps/billing-and-pricing/pricing)
- [Detalhes de cobrança por produto (Maps SDK × Dynamic Maps)](https://developers.google.com/maps/billing-and-pricing/sku-details)
- [Cobrança do Maps SDK para Android](https://developers.google.com/maps/documentation/android-sdk/usage-and-billing)
- [Woosmap: preços do Google Maps API em 2026](https://www.woosmap.com/blog/google-maps-api-pricing-breakdown)
- [StoreRocket: preços do Google Maps API](https://storerocket.io/learn/google-maps-api-pricing)
- [Guia de APIs e custos do Google Maps (mar/2026)](https://nicolalazzari.ai/articles/understanding-google-maps-apis-a-comprehensive-guide-to-uses-and-costs)
