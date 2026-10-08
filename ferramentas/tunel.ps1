# Liga o tunel (Cloudflare, gratuito, sem conta) para testar o app no celular.
# Chamado por "Testar no celular.bat". O endereco muda a cada vez: o script mostra o novo
# e ja copia para a area de transferencia, para colar no app (Servidor de teste -> alterar).
# Fechar esta janela desliga o tunel.

$cloudflared = 'C:\Users\alan.ribeiro\dev\cloudflared.exe'
$log = Join-Path $env:TEMP 'vagas-tunel.log'

Write-Host ''
Write-Host '  Vagas Especiais - teste no celular' -ForegroundColor Cyan
Write-Host ''

if (-not (Test-Path $cloudflared)) {
    Write-Host '  ERRO: cloudflared nao encontrado em C:\Users\alan.ribeiro\dev.' -ForegroundColor Red
    Read-Host '  Pressione Enter para sair'
    exit 1
}

# Garante MySQL e Apache ligados (sem abrir o navegador)
& (Join-Path $PSScriptRoot 'iniciar.ps1') -SemNavegador

Write-Host '  - Ligando o tunel...'
Remove-Item $log -ErrorAction SilentlyContinue
# Porta 8080: so o app e o download do APK (phpMyAdmin e paineis ficam de fora do tunel).
$processo = Start-Process -FilePath $cloudflared -ArgumentList 'tunnel', '--no-autoupdate', '--url', 'http://127.0.0.1:8080' `
    -RedirectStandardError $log -NoNewWindow -PassThru

$url = $null
for ($i = 0; $i -lt 60 -and -not $url; $i++) {
    Start-Sleep -Seconds 1
    if (Test-Path $log) {
        $achou = Select-String -Path $log -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -First 1
        if ($achou) { $url = $achou.Matches[0].Value }
    }
}
if (-not $url) {
    Write-Host '  ERRO: o tunel nao respondeu. Verifique a internet e tente de novo.' -ForegroundColor Red
    Stop-Process -Id $processo.Id -ErrorAction SilentlyContinue
    Read-Host '  Pressione Enter para sair'
    exit 1
}
Set-Clipboard -Value $url

Write-Host ''
Write-Host '  ================================================================' -ForegroundColor Green
Write-Host "    $url" -ForegroundColor Green
Write-Host '  ================================================================' -ForegroundColor Green
Write-Host ''
Write-Host '  O endereco acima ja foi copiado. No app de teste, na tela inicial,'
Write-Host '  toque em "alterar" (Servidor de teste) e informe esse endereco.'
Write-Host ''
Write-Host '  Para instalar o APK no celular, abra no navegador do celular:' -ForegroundColor Cyan
Write-Host "    $url/baixar/VagasEspeciais-teste.apk" -ForegroundColor Cyan
Write-Host '  (o Gmail bloqueia anexos .apk, mas deixa passar este link por e-mail)'
Write-Host ''
Write-Host '  IMPORTANTE: o celular deve usar dados moveis (4G/5G) ou outro Wi-Fi.' -ForegroundColor Yellow
Write-Host '  A rede da empresa nao reconhece enderecos trycloudflare.com.' -ForegroundColor Yellow
Write-Host ''
Write-Host '  Deixe esta janela aberta durante o teste. Para desligar o tunel, feche-a.'
Wait-Process -Id $processo.Id
