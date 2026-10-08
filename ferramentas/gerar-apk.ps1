# Gera o APK de teste do app e copia para a Area de Trabalho (VagasEspeciais-teste.apk).
# Chamado por "Gerar APK de teste.bat". Usa o Java 21 e o Android SDK portateis em C:\Users\alan.ribeiro\dev.

$dev = 'C:\Users\alan.ribeiro\dev'
$app = Join-Path (Split-Path -Parent $PSScriptRoot) 'app-mobile'
$destino = Join-Path ([Environment]::GetFolderPath('Desktop')) 'VagasEspeciais-teste.apk'

Write-Host ''
Write-Host '  Vagas Especiais - gerando o APK de teste...' -ForegroundColor Cyan
Write-Host ''

$jdk = Get-ChildItem $dev -Directory -Filter 'jdk-21*' -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $jdk -or -not (Test-Path "$dev\android-sdk\platforms")) {
    Write-Host '  ERRO: Java 21 ou Android SDK nao encontrados em C:\Users\alan.ribeiro\dev.' -ForegroundColor Red
    Read-Host '  Pressione Enter para sair'
    exit 1
}
$env:JAVA_HOME = $jdk.FullName
$env:ANDROID_HOME = "$dev\android-sdk"

Push-Location $app
try {
    Write-Host '  1/3 Preparando as telas'
    & node scripts\build.mjs
    if ($LASTEXITCODE -ne 0) { throw 'falha ao preparar as telas' }

    Write-Host '  2/3 Atualizando o projeto Android'
    & npx.cmd cap sync android *> $null
    if ($LASTEXITCODE -ne 0) { throw 'falha no cap sync' }

    Write-Host '  3/3 Compilando (1 a 3 minutos)'
    Push-Location android
    & .\gradlew.bat assembleDebug --quiet
    $ok = $LASTEXITCODE -eq 0
    Pop-Location
    if (-not $ok) { throw 'falha na compilacao (veja as mensagens acima)' }

    Copy-Item 'android\app\build\outputs\apk\debug\app-debug.apk' $destino -Force
    # Disponivel tambem para download pelo tunel (Testar no celular.bat): /baixar/VagasEspeciais-teste.apk
    $baixar = 'C:\Users\alan.ribeiro\xampp\htdocs-tunel\baixar'
    if (Test-Path $baixar) { Copy-Item $destino (Join-Path $baixar 'VagasEspeciais-teste.apk') -Force }
    Write-Host ''
    Write-Host "  Pronto! APK salvo em: $destino" -ForegroundColor Green
    Write-Host '  Envie para o celular (cabo, e-mail, Drive ou WhatsApp) e instale.'
} catch {
    Write-Host "  ERRO: $_" -ForegroundColor Red
} finally {
    Pop-Location
}
Write-Host ''
Read-Host '  Pressione Enter para fechar'
