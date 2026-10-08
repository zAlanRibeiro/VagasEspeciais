# Inicia o Vagas Especiais em segundo plano (sem janelas) e abre o navegador.
# Chamado por "Iniciar Vagas Especiais.bat". Os servidores continuam ligados ate
# "Parar Vagas Especiais.bat" - fechar janelas nao os desliga.

param([switch]$SemNavegador)   # -SemNavegador: so liga os servidores (usado pelo teste no celular)

$xampp  = 'C:\Users\alan.ribeiro\xampp'
$raiz   = Split-Path -Parent $PSScriptRoot
$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'

function Porta-Aberta([int]$porta) {
    [bool](Get-NetTCPConnection -LocalPort $porta -State Listen -ErrorAction SilentlyContinue)
}

function Esperar-Porta([int]$porta, [int]$segundos = 30) {
    for ($i = 0; $i -lt $segundos * 2; $i++) {
        if (Porta-Aberta $porta) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return $false
}

function Falhou([string]$mensagem) {
    Write-Host "`n  ERRO: $mensagem" -ForegroundColor Red
    Write-Host '  Feche esta janela e tente de novo. Se continuar, avise o desenvolvedor.'
    Read-Host '  Pressione Enter para sair'
    exit 1
}

Write-Host ''
Write-Host '  Vagas Especiais - iniciando...' -ForegroundColor Cyan
Write-Host ''

# Banco de dados
if (Porta-Aberta 3306) {
    Write-Host '  - Banco de dados MySQL: ja estava ligado'
} else {
    Write-Host '  - Banco de dados MySQL: ligando'
    Start-Process -FilePath "$xampp\mysql\bin\mysqld.exe" `
        -ArgumentList "--defaults-file=`"$xampp\mysql\bin\my.ini`"", '--standalone' `
        -WorkingDirectory $xampp -WindowStyle Hidden
    if (-not (Esperar-Porta 3306)) { Falhou 'o MySQL nao iniciou.' }
}

# Site e API
if (Porta-Aberta 80) {
    Write-Host '  - Servidor Apache: ja estava ligado'
} else {
    Write-Host '  - Servidor Apache: ligando'
    Start-Process -FilePath "$xampp\apache\bin\httpd.exe" -WorkingDirectory $xampp -WindowStyle Hidden
    if (-not (Esperar-Porta 80)) { Falhou 'o Apache nao iniciou (a porta 80 pode estar ocupada por outro programa).' }
}

# Telas do app (sempre gera de novo, para refletir alteracoes no codigo)
Write-Host '  - Telas do app: preparando'
Push-Location "$raiz\app-mobile"
& npm.cmd run build *> $null
Pop-Location
if (-not (Porta-Aberta 8100)) {
    Start-Process -FilePath 'node' -ArgumentList 'scripts\serve.mjs' -WorkingDirectory "$raiz\app-mobile" -WindowStyle Hidden
    if (-not (Esperar-Porta 8100 15)) { Falhou 'o servidor das telas do app nao iniciou.' }
}

if ($SemNavegador) { return }

# Abre o site e o app
Start-Process 'http://localhost/vagas-especiais/'
if (Test-Path $chrome) {
    Start-Process -FilePath $chrome -ArgumentList '--app=http://localhost:8100', '--window-size=420,860'
} else {
    Start-Process 'http://localhost:8100'
}

Write-Host ''
Write-Host '  Pronto! Tudo ligado em segundo plano.' -ForegroundColor Green
Write-Host '    Site e painel admin:   http://localhost/vagas-especiais/'
Write-Host '    App (tela de celular): http://localhost:8100'
Write-Host ''
Write-Host '  Pode fechar esta janela. Para desligar, use "Parar Vagas Especiais.bat".'
Start-Sleep -Seconds 8
