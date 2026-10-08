@echo off
rem Gera o APK de teste do app (Android) e copia para a Area de Trabalho.
title Vagas Especiais - gerando APK de teste
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0ferramentas\gerar-apk.ps1"
