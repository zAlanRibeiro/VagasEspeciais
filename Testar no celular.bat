@echo off
rem Liga os servidores e o tunel para testar o app de teste (APK) no celular.
title Vagas Especiais - tunel para o celular (deixe esta janela aberta)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0ferramentas\tunel.ps1"
