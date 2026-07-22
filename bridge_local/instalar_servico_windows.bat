@echo off
REM ============================================================
REM  Instala o Bridge como Servico do Windows (auto-start)
REM  Requer: NSSM (Non-Sucking Service Manager)
REM  Download: https://nssm.cc/download
REM ============================================================

echo Instalando Bridge Control iD como Servico do Windows...
echo.

REM Caminho do NSSM (baixe e coloque na mesma pasta)
set NSSM=%~dp0nssm.exe

if not exist "%NSSM%" (
    echo ERRO: nssm.exe nao encontrado nesta pasta.
    echo Baixe em: https://nssm.cc/download
    echo Coloque o nssm.exe na mesma pasta que este arquivo.
    pause
    exit /b 1
)

REM Caminho do Python e do script
for /f "tokens=*" %%i in ('where python') do set PYTHON=%%i
set SCRIPT=%~dp0controlid_bridge.py

echo Python: %PYTHON%
echo Script: %SCRIPT%
echo.

REM Remove servico existente (se houver)
%NSSM% stop ControlIDBridge 2>nul
%NSSM% remove ControlIDBridge confirm 2>nul

REM Instala o servico
%NSSM% install ControlIDBridge "%PYTHON%" "%SCRIPT%"
%NSSM% set ControlIDBridge AppDirectory "%~dp0"
%NSSM% set ControlIDBridge DisplayName "Bridge Control iD - ERP Condominio"
%NSSM% set ControlIDBridge Description "Integra o leitor RFID Control iD com o ERP do condominio"
%NSSM% set ControlIDBridge Start SERVICE_AUTO_START
%NSSM% set ControlIDBridge AppStdout "%~dp0bridge_log.txt"
%NSSM% set ControlIDBridge AppStderr "%~dp0bridge_log.txt"
%NSSM% set ControlIDBridge AppRotateFiles 1
%NSSM% set ControlIDBridge AppRotateBytes 1048576

REM Inicia o servico
%NSSM% start ControlIDBridge

echo.
echo ================================================
echo  Servico instalado e iniciado com sucesso!
echo  O bridge iniciara automaticamente com o Windows.
echo  Para verificar: services.msc -> ControlIDBridge
echo ================================================
pause
