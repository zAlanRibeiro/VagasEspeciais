@echo off
rem Desliga o Vagas Especiais: MySQL (com desligamento seguro), Apache e o servidor das telas do app.
setlocal
set "XAMPP=C:\Users\alan.ribeiro\xampp"

echo.
echo  Vagas Especiais - desligando...

"%XAMPP%\mysql\bin\mysqladmin.exe" -u root shutdown >nul 2>&1
taskkill /im httpd.exe /f >nul 2>&1
taskkill /im cloudflared.exe /f >nul 2>&1
for /f "tokens=5" %%p in ('netstat -ano ^| findstr "127.0.0.1:8100" ^| findstr LISTENING') do taskkill /pid %%p /f >nul 2>&1

echo  Pronto.
timeout /t 3 >nul
