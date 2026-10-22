# Vagas Especiais Niterói

Mapa das vagas de estacionamento reservadas em Niterói, para quem dirige encontrar a vaga mais perto e chegar até ela.
Cada pessoa vê as vagas dos perfis que tem:

| Perfil | Vê as vagas | Aprovação |
|---|---|---|
| **Rotativo** | rotativas | Todos têm, já liberado no cadastro |
| **PCD** | para pessoas com deficiência | Envio de comprovação e análise da Nittrans |
| **Idoso** | para pessoas idosas (60+) | Envio de comprovação e análise da Nittrans |

Uma pessoa pode ter mais de um perfil. Os pedidos de PCD e Idoso são analisados no painel administrativo.

## O que tem

- **Site (PWA)** e **app Android/iOS** com o mesmo mapa: busca de endereço, filtros por tipo,
  lista por distância, rotas dentro do app, relato de problema na vaga, avisos no celular,
  tutorial no primeiro acesso e modo letra maior/alto contraste.
- **Painel administrativo:** análise das comprovações por perfil, usuários (revogar perfil, suspender),
  cadastro e importação da base de vagas (CSV/XLSX), relatos e estatísticas de uso.
- **Sem APIs pagas:** mapas e rotas com OpenStreetMap (Leaflet e Valhalla). A troca por Google Maps está
  orçada em [ORCAMENTO_MAPAS.md](ORCAMENTO_MAPAS.md).

## Estrutura

```
vagas-especiais/   servidor PHP + MySQL: site, API do app e painel admin
app-mobile/        app Android e iOS (Capacitor), usa o servidor como API
ferramentas/       scripts dos atalhos .bat (Windows + XAMPP portátil)
```

| Pasta | Como instalar e rodar |
|---|---|
| [vagas-especiais/](vagas-especiais/README.md) | Requisitos, instalação, teste rápido com SQLite, importação da base, API |
| [app-mobile/](app-mobile/README.md) | Rodar no navegador, no emulador e no celular; gerar a versão das lojas |
| [app-mobile/PUBLICACAO_LOJAS.md](app-mobile/PUBLICACAO_LOJAS.md) | Passo a passo para publicar na Google Play e na App Store |

## Começo rápido (sem MySQL)

Com PHP 8.2.35+ (ou 8.3.36+, 8.4.27+, 8.5.12+; ver [requisitos](vagas-especiais/README.md#requisitos)):

```powershell
cd vagas-especiais
$env:VAGAS_DB_DRIVER = "sqlite"
php database/install.php --admin=admin@teste.local --senha=admin12345 --exemplo
php -S localhost:8000 router.php
```

Abra http://localhost:8000 e entre com o administrador acima. A opção `--exemplo` carrega vagas **fictícias**.
Essa conta é só para teste local: em produção, crie o administrador com e-mail e senha próprios.

No Windows com XAMPP portátil, `Iniciar Vagas Especiais.bat` liga tudo, `Testar no celular.bat` abre
um túnel para o APK de teste e `Gerar APK de teste.bat` compila o app.

## Tecnologias

PHP 8 (PDO) · MySQL/MariaDB (ou SQLite em desenvolvimento) · Apache · JavaScript sem framework ·
Leaflet + OpenStreetMap · Capacitor 8 (Android e iOS)
