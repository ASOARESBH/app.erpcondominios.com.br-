@echo off
chcp 65001 >nul
title Bridge Local — Control iD → ERP Condomínio

echo.
echo  ============================================================
echo   BRIDGE LOCAL — Control iD → ERP Condomínio v4.0
echo  ============================================================
echo.

:: Verificar se Python está instalado
where python >nul 2>&1
if %errorlevel% neq 0 (
    echo  [ERRO] Python nao encontrado!
    echo.
    echo  Instale o Python em: https://www.python.org/downloads/
    echo  Marque a opcao "Add Python to PATH" durante a instalacao.
    echo.
    pause
    exit /b 1
)

:: Verificar se requests está instalado
python -c "import requests" >nul 2>&1
if %errorlevel% neq 0 (
    echo  [AVISO] Instalando dependencia 'requests'...
    pip install requests
    if %errorlevel% neq 0 (
        echo.
        echo  [ERRO] Falha ao instalar requests.
        echo  Execute manualmente: pip install requests
        echo.
        pause
        exit /b 1
    )
    echo  [OK] Dependencia instalada com sucesso!
    echo.
)

:: Mudar para o diretório do script
cd /d "%~dp0"

echo  Iniciando bridge...
echo  Para encerrar: pressione Ctrl+C
echo.

:: Executar o bridge
python controlid_bridge.py

:: Se o script terminar com erro, manter janela aberta
if %errorlevel% neq 0 (
    echo.
    echo  ============================================================
    echo   O bridge encerrou com erro (codigo %errorlevel%).
    echo   Verifique as mensagens acima.
    echo  ============================================================
    echo.
    pause
)
