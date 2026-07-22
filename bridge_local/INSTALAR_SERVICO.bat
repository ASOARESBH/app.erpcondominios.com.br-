@echo off
chcp 65001 >nul
title Instalador de Serviço — Bridge Control iD

:: Verificar privilégios de Administrador
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo.
    echo  [ERRO] Este script precisa ser executado como Administrador!
    echo.
    echo  Clique com o botao direito em INSTALAR_SERVICO.bat
    echo  e selecione "Executar como administrador".
    echo.
    pause
    exit /b 1
)

echo.
echo  ============================================================
echo   Instalador de Servico Windows — Bridge Control iD v4.0
echo  ============================================================
echo.

set SCRIPT_DIR=%~dp0
set SCRIPT_PATH=%SCRIPT_DIR%controlid_bridge.py
set SERVICE_NAME=BridgeControlID
set DISPLAY_NAME=Bridge Local Control iD - ERP Condominio

:: Verificar se NSSM está disponível (gerenciador de serviços)
where nssm >nul 2>&1
if %errorlevel% equ 0 (
    goto instalar_nssm
)

:: Verificar se pythonw está disponível (execução sem janela)
where pythonw >nul 2>&1
if %errorlevel% equ 0 (
    goto instalar_sc
)

echo  [AVISO] NSSM nao encontrado. Usando Task Scheduler como alternativa.
goto instalar_taskscheduler

:: ── Instalar via NSSM (recomendado) ──────────────────────────
:instalar_nssm
echo  [INFO] Usando NSSM para instalar o servico...
echo.

:: Remover serviço anterior se existir
nssm stop %SERVICE_NAME% >nul 2>&1
nssm remove %SERVICE_NAME% confirm >nul 2>&1

:: Instalar novo serviço
nssm install %SERVICE_NAME% pythonw "%SCRIPT_PATH%"
nssm set %SERVICE_NAME% DisplayName "%DISPLAY_NAME%"
nssm set %SERVICE_NAME% Description "Bridge local que sincroniza acessos da Control iD com o ERP Condominio"
nssm set %SERVICE_NAME% AppDirectory "%SCRIPT_DIR%"
nssm set %SERVICE_NAME% Start SERVICE_AUTO_START
nssm set %SERVICE_NAME% AppStdout "%SCRIPT_DIR%bridge_log.txt"
nssm set %SERVICE_NAME% AppStderr "%SCRIPT_DIR%bridge_log.txt"
nssm set %SERVICE_NAME% AppRotateFiles 1
nssm set %SERVICE_NAME% AppRotateBytes 5242880

:: Iniciar o serviço
nssm start %SERVICE_NAME%

echo.
echo  [OK] Servico instalado e iniciado!
echo  Nome do servico: %SERVICE_NAME%
echo  Para verificar: services.msc
echo  Para parar: nssm stop %SERVICE_NAME%
echo  Para remover: nssm remove %SERVICE_NAME% confirm
goto fim

:: ── Instalar via SC (serviço nativo) ─────────────────────────
:instalar_sc
echo  [INFO] Instalando via Task Scheduler (pythonw)...
goto instalar_taskscheduler

:: ── Instalar via Task Scheduler ──────────────────────────────
:instalar_taskscheduler
echo  [INFO] Criando tarefa agendada para iniciar com o Windows...
echo.

set TASK_NAME=BridgeControlID
where pythonw >nul 2>&1
if %errorlevel% equ 0 (
    set PYTHON_EXE=pythonw
) else (
    set PYTHON_EXE=python
)

:: Remover tarefa anterior
schtasks /delete /tn "%TASK_NAME%" /f >nul 2>&1

:: Criar tarefa que inicia com o logon do usuário
schtasks /create /tn "%TASK_NAME%" /tr "\"%PYTHON_EXE%\" \"%SCRIPT_PATH%\"" /sc ONLOGON /rl HIGHEST /f

if %errorlevel% equ 0 (
    echo  [OK] Tarefa agendada criada com sucesso!
    echo  Nome da tarefa: %TASK_NAME%
    echo  A tarefa iniciara automaticamente no proximo logon.
    echo.
    echo  Para iniciar agora:
    schtasks /run /tn "%TASK_NAME%"
    echo  [OK] Bridge iniciado em segundo plano.
) else (
    echo  [ERRO] Falha ao criar tarefa agendada.
    echo  Use o INICIAR_BRIDGE.bat para iniciar manualmente.
)

:fim
echo.
echo  Log do bridge: %SCRIPT_DIR%bridge_log.txt
echo.
pause
