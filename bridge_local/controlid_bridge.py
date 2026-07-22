#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
╔══════════════════════════════════════════════════════════════╗
║          BRIDGE LOCAL — Control iD → ERP Condomínio         ║
║                        Versão 4.0                            ║
╚══════════════════════════════════════════════════════════════╝

Como usar:
  1. Edite as CONFIGURAÇÕES abaixo (IP, usuário, senha, API_KEY)
  2. Execute: python controlid_bridge.py
     - No Windows: dê duplo clique em INICIAR_BRIDGE.bat
  3. A janela mostrará o status em tempo real
  4. O arquivo bridge_log.txt guarda o histórico completo

Para rodar em segundo plano (sem janela):
  - Execute INSTALAR_SERVICO.bat como Administrador

Dependências:
  pip install requests
"""

import requests
import time
import json
import os
import sys
import logging
import urllib3
import traceback
from datetime import datetime

# ──────────────────────────────────────────────────────────────
# CONFIGURAÇÕES — EDITE AQUI
# ──────────────────────────────────────────────────────────────
CONTROLID_IP       = "http://192.168.3.150"   # IP do equipamento na rede local
CONTROLID_USER     = "admin"                   # Usuário do equipamento
CONTROLID_PASS     = "admin"                   # Senha do equipamento

ERP_URL            = "https://asl.erpcondominios.com.br/api/bridge_receiver.php"
API_KEY            = "COLOQUE_SUA_CHAVE_AQUI"  # Chave gerada no ERP → Dispositivos → Chave
BRIDGE_ID          = "portaria-principal"       # Nome identificador desta instalação
DISPOSITIVO_ID     = 1                          # ID do dispositivo no ERP

INTERVALO_SEGUNDOS = 5                          # Intervalo entre verificações
MAX_LOGS_POR_CICLO = 50                         # Máximo de eventos por envio
STATE_FILE         = "ultimo_log.txt"           # Arquivo de estado (último log lido)
LOG_FILE           = "bridge_log.txt"           # Arquivo de log local
# ──────────────────────────────────────────────────────────────

# Suprimir avisos SSL
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# ── Cores ANSI para o terminal ────────────────────────────────
class Cor:
    RESET   = "\033[0m"
    BOLD    = "\033[1m"
    VERDE   = "\033[92m"
    AMARELO = "\033[93m"
    VERMELHO= "\033[91m"
    AZUL    = "\033[94m"
    CIANO   = "\033[96m"
    BRANCO  = "\033[97m"
    CINZA   = "\033[90m"

def _habilitar_cores_windows():
    """Habilita suporte a cores ANSI no terminal do Windows."""
    if sys.platform == "win32":
        try:
            import ctypes
            kernel32 = ctypes.windll.kernel32
            # ENABLE_VIRTUAL_TERMINAL_PROCESSING = 0x0004
            kernel32.SetConsoleMode(kernel32.GetStdHandle(-11), 7)
        except Exception:
            pass

_habilitar_cores_windows()


# ── Logger customizado com cores ──────────────────────────────
class LogColorido(logging.Formatter):
    FORMATOS = {
        logging.DEBUG:    Cor.CINZA   + "[DBG] %(message)s" + Cor.RESET,
        logging.INFO:     Cor.BRANCO  + "[INF] %(message)s" + Cor.RESET,
        logging.WARNING:  Cor.AMARELO + "[AVS] %(message)s" + Cor.RESET,
        logging.ERROR:    Cor.VERMELHO+ "[ERR] %(message)s" + Cor.RESET,
        logging.CRITICAL: Cor.VERMELHO+ Cor.BOLD + "[CRT] %(message)s" + Cor.RESET,
    }

    def format(self, record):
        fmt = self.FORMATOS.get(record.levelno, "%(message)s")
        formatter = logging.Formatter(
            Cor.CINZA + "%(asctime)s " + Cor.RESET + fmt,
            datefmt="%H:%M:%S"
        )
        return formatter.format(record)


def _configurar_logger():
    logger = logging.getLogger("bridge")
    logger.setLevel(logging.DEBUG)
    logger.handlers.clear()

    # Handler para arquivo (sem cores, com data completa)
    fh = logging.FileHandler(LOG_FILE, encoding="utf-8")
    fh.setLevel(logging.DEBUG)
    fh.setFormatter(logging.Formatter(
        "%(asctime)s [%(levelname)s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S"
    ))

    # Handler para o terminal (com cores)
    sh = logging.StreamHandler(sys.stdout)
    sh.setLevel(logging.INFO)
    sh.setFormatter(LogColorido())

    logger.addHandler(fh)
    logger.addHandler(sh)
    return logger


log = _configurar_logger()


# ── Headers para o ERP ───────────────────────────────────────
def _montar_headers():
    return {
        "Content-Type": "application/json",
        "Accept":       "application/json",
        "X-API-KEY":    API_KEY,
        "X-Bridge-ID":  BRIDGE_ID,
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/124.0.0.0 Safari/537.36"
        ),
    }


# ── Diagnóstico inicial ───────────────────────────────────────
def diagnostico_inicial():
    """
    Executa verificações antes de entrar no loop principal.
    Exibe resultado colorido e retorna True se tudo OK.
    """
    print()
    print(Cor.CIANO + Cor.BOLD +
          "╔══════════════════════════════════════════════════════════════╗" + Cor.RESET)
    print(Cor.CIANO + Cor.BOLD +
          "║          BRIDGE LOCAL — Control iD → ERP Condomínio         ║" + Cor.RESET)
    print(Cor.CIANO + Cor.BOLD +
          "║                        Versão 4.0                           ║" + Cor.RESET)
    print(Cor.CIANO + Cor.BOLD +
          "╚══════════════════════════════════════════════════════════════╝" + Cor.RESET)
    print()
    print(Cor.BOLD + "  Configurações carregadas:" + Cor.RESET)
    print(f"  {Cor.CINZA}Equipamento  :{Cor.RESET} {Cor.AZUL}{CONTROLID_IP}{Cor.RESET}")
    print(f"  {Cor.CINZA}ERP          :{Cor.RESET} {Cor.AZUL}{ERP_URL}{Cor.RESET}")
    print(f"  {Cor.CINZA}Dispositivo  :{Cor.RESET} ID={DISPOSITIVO_ID} / Bridge={BRIDGE_ID}")
    chave_exib = API_KEY[:8] + "..." + API_KEY[-4:] if len(API_KEY) > 16 else API_KEY
    print(f"  {Cor.CINZA}API Key      :{Cor.RESET} {chave_exib}")
    print(f"  {Cor.CINZA}Log          :{Cor.RESET} {os.path.abspath(LOG_FILE)}")
    print()

    # Verificar se a chave foi configurada
    if API_KEY in ("COLOQUE_SUA_CHAVE_AQUI", "", None):
        print(Cor.VERMELHO + Cor.BOLD +
              "  ✗ API_KEY não configurada!" + Cor.RESET)
        print(Cor.AMARELO +
              "    → Abra este arquivo, localize a variável API_KEY" + Cor.RESET)
        print(Cor.AMARELO +
              "      e cole a chave gerada no ERP (Dispositivos → Chave)." + Cor.RESET)
        print()
        _aguardar_tecla("Pressione ENTER para fechar...")
        return False

    print(Cor.BOLD + "  Verificando conexões..." + Cor.RESET)
    print()

    tudo_ok = True

    # ── Teste 1: Equipamento Control iD ──────────────────────
    print(f"  {Cor.CINZA}[1/3]{Cor.RESET} Equipamento Control iD ({CONTROLID_IP})...", end=" ", flush=True)
    try:
        r = requests.post(
            f"{CONTROLID_IP}/login.fcgi",
            json={"login": CONTROLID_USER, "password": CONTROLID_PASS},
            timeout=5,
            verify=False,
        )
        if r.status_code == 200 and r.json().get("session"):
            print(Cor.VERDE + "✓ Conectado e autenticado" + Cor.RESET)
            # Logout imediato para não deixar sessão aberta
            try:
                sess = r.json()["session"]
                requests.post(f"{CONTROLID_IP}/logout.fcgi?session={sess}", timeout=3, verify=False)
            except Exception:
                pass
        else:
            print(Cor.AMARELO + f"⚠ HTTP {r.status_code} — verifique usuário/senha" + Cor.RESET)
            tudo_ok = False
    except requests.exceptions.ConnectionError:
        print(Cor.VERMELHO + "✗ Sem resposta — verifique o IP e a rede local" + Cor.RESET)
        tudo_ok = False
    except Exception as e:
        print(Cor.VERMELHO + f"✗ Erro: {e}" + Cor.RESET)
        tudo_ok = False

    # ── Teste 2: Internet / ERP ───────────────────────────────
    print(f"  {Cor.CINZA}[2/3]{Cor.RESET} Servidor ERP ({ERP_URL.split('/')[2]})...", end=" ", flush=True)
    try:
        r = requests.get(
            ERP_URL.replace("bridge_receiver.php", "diagnostico_500.php"),
            timeout=8,
            verify=False,
            headers={"User-Agent": _montar_headers()["User-Agent"]},
        )
        if r.status_code < 500:
            print(Cor.VERDE + f"✓ Acessível (HTTP {r.status_code})" + Cor.RESET)
        else:
            print(Cor.AMARELO + f"⚠ HTTP {r.status_code} — servidor com erro" + Cor.RESET)
    except requests.exceptions.ConnectionError:
        print(Cor.VERMELHO + "✗ Sem internet ou servidor fora do ar" + Cor.RESET)
        tudo_ok = False
    except Exception as e:
        print(Cor.VERMELHO + f"✗ Erro: {e}" + Cor.RESET)

    # ── Teste 3: API Key ──────────────────────────────────────
    print(f"  {Cor.CINZA}[3/3]{Cor.RESET} Validando API Key no ERP...", end=" ", flush=True)
    try:
        payload_teste = json.dumps({
            "acao": "heartbeat",
            "bridge_id": BRIDGE_ID,
            "versao": "4.0",
            "dispositivos": [],
        }, separators=(",", ":"))
        headers = _montar_headers()
        headers["Content-Length"] = str(len(payload_teste.encode("utf-8")))
        r = requests.post(
            ERP_URL,
            data=payload_teste.encode("utf-8"),
            headers=headers,
            timeout=10,
            verify=False,
        )
        if r.status_code == 200:
            try:
                data = r.json()
                if data.get("sucesso"):
                    print(Cor.VERDE + "✓ API Key válida — ERP respondeu OK" + Cor.RESET)
                else:
                    print(Cor.AMARELO + f"⚠ ERP respondeu mas retornou erro: {data.get('mensagem','?')}" + Cor.RESET)
            except ValueError:
                print(Cor.AMARELO + f"⚠ ERP retornou HTTP 200 mas não é JSON: {r.text[:80]}" + Cor.RESET)
        elif r.status_code == 401:
            print(Cor.VERMELHO + "✗ API Key INVÁLIDA — verifique a chave no ERP" + Cor.RESET)
            print()
            print(Cor.AMARELO + "    Como obter a chave correta:" + Cor.RESET)
            print(Cor.AMARELO + "    1. Acesse o ERP → Dispositivos de Acesso" + Cor.RESET)
            print(Cor.AMARELO + "    2. Clique no ícone de chave (🔑) do dispositivo" + Cor.RESET)
            print(Cor.AMARELO + "    3. Copie a 'Bridge API Key' e cole na variável API_KEY" + Cor.RESET)
            tudo_ok = False
        elif r.status_code == 406:
            print(Cor.AMARELO + "⚠ HTTP 406 — Mod_Security bloqueou (WAF ativo)" + Cor.RESET)
        else:
            print(Cor.AMARELO + f"⚠ HTTP {r.status_code}: {r.text[:80]}" + Cor.RESET)
    except Exception as e:
        print(Cor.AMARELO + f"⚠ Não foi possível validar: {e}" + Cor.RESET)

    print()

    if tudo_ok:
        print(Cor.VERDE + Cor.BOLD +
              "  ✓ Tudo OK! Iniciando monitoramento..." + Cor.RESET)
    else:
        print(Cor.AMARELO + Cor.BOLD +
              "  ⚠ Alguns testes falharam. O bridge tentará continuar mesmo assim." + Cor.RESET)
        print(Cor.AMARELO +
              "    Verifique as mensagens acima e corrija as configurações." + Cor.RESET)

    print()
    print(Cor.CINZA + "  Pressione Ctrl+C para encerrar o bridge a qualquer momento." + Cor.RESET)
    print(Cor.CINZA + f"  Log completo em: {os.path.abspath(LOG_FILE)}" + Cor.RESET)
    print()
    print(Cor.CIANO + "─" * 64 + Cor.RESET)
    print()

    return True


def _aguardar_tecla(mensagem="Pressione ENTER para continuar..."):
    """Mantém a janela aberta aguardando o usuário pressionar ENTER."""
    print()
    print(Cor.AMARELO + "  " + mensagem + Cor.RESET)
    try:
        input()
    except (EOFError, KeyboardInterrupt):
        pass


# ── Control iD: Login ────────────────────────────────────────
def login_controlid():
    url = f"{CONTROLID_IP}/login.fcgi"
    payload = {"login": CONTROLID_USER, "password": CONTROLID_PASS}
    try:
        res = requests.post(url, json=payload, timeout=5, verify=False)
        if res.status_code == 200:
            session = res.json().get("session")
            if session:
                return session
            log.warning(f"Login OK mas sem session: {res.json()}")
        else:
            log.error(f"Falha no login HTTP {res.status_code}")
    except requests.exceptions.ConnectionError:
        log.warning(f"Equipamento inacessível em {CONTROLID_IP}")
    except Exception as e:
        log.error(f"Exceção no login: {e}")
    return None


def logout_controlid(session):
    try:
        requests.post(f"{CONTROLID_IP}/logout.fcgi?session={session}", timeout=3, verify=False)
    except Exception:
        pass


# ── Estado local ─────────────────────────────────────────────
def obter_ultimo_log_processado():
    if os.path.exists(STATE_FILE):
        try:
            with open(STATE_FILE, "r") as f:
                return int(f.read().strip())
        except (ValueError, IOError):
            pass
    return 0


def salvar_ultimo_log_processado(log_id):
    try:
        with open(STATE_FILE, "w") as f:
            f.write(str(log_id))
    except IOError as e:
        log.error(f"Erro ao salvar estado: {e}")


# ── Control iD: Buscar logs ──────────────────────────────────
def buscar_novos_acessos(session, ultimo_log):
    url = f"{CONTROLID_IP}/load_objects.fcgi?session={session}"
    payload = {
        "object": "access_logs",
        "where": {"access_logs": {"id": {">": ultimo_log}}},
        "order": "id",
        "limit": MAX_LOGS_POR_CICLO,
    }
    try:
        res = requests.post(url, json=payload, timeout=8, verify=False)
        if res.status_code == 200:
            return res.json().get("access_logs", [])
        elif res.status_code == 401:
            log.warning("Sessão expirada no equipamento.")
        else:
            log.error(f"Erro ao buscar logs HTTP {res.status_code}")
    except Exception as e:
        log.error(f"Exceção ao buscar acessos: {e}")
    return []


# ── Formatação dos eventos ────────────────────────────────────
def formatar_eventos(logs):
    eventos = []
    for item in logs:
        card_value = str(item.get("card_value", "")).strip()
        eventos.append({
            "id":         int(item.get("id", 0)),
            "time":       int(item.get("time", 0)),
            "event_type": int(item.get("event", 0)),
            "user_id":    int(item.get("user_id", 0)),
            "uhf_tag":    card_value,
            "card_value": card_value,
            "door_id":    int(item.get("portal_id", 0)),
        })
    return eventos


# ── ERP: Enviar eventos ───────────────────────────────────────
def enviar_para_erp(eventos):
    if not eventos:
        return True

    payload = {
        "acao":           "eventos",
        "dispositivo_id": DISPOSITIVO_ID,
        "bridge_id":      BRIDGE_ID,
        "eventos":        eventos,
    }
    payload_str = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
    headers = _montar_headers()
    headers["Content-Length"] = str(len(payload_str.encode("utf-8")))

    try:
        res = requests.post(
            ERP_URL,
            data=payload_str.encode("utf-8"),
            headers=headers,
            timeout=20,
            verify=False,
        )

        if res.status_code == 200:
            try:
                data = res.json()
            except ValueError:
                log.error(f"ERP retornou HTTP 200 mas body não é JSON: {res.text[:200]}")
                return False

            if data.get("sucesso"):
                proc  = data.get("processados", 0)
                ign   = data.get("ignorados", 0)
                erros = data.get("erros", 0)
                log.info(
                    Cor.VERDE + f"✓ ERP OK — {proc} processado(s), {ign} ignorado(s), {erros} erro(s)" + Cor.RESET
                )
                return True
            else:
                log.error(f"ERP retornou sucesso=false: {data.get('erro', 'desconhecido')}")

        elif res.status_code == 401:
            log.error(
                Cor.VERMELHO + "✗ API Key INVÁLIDA (HTTP 401)!\n"
                "    → Acesse o ERP → Dispositivos → ícone 🔑 → copie a Bridge API Key\n"
                "    → Cole na variável API_KEY deste script e reinicie." + Cor.RESET
            )
        elif res.status_code == 406:
            log.error(
                "HTTP 406 — Mod_Security bloqueou a requisição.\n"
                "    → Acesse o cPanel HostGator → Security → ModSecurity\n"
                "    → Desative para o domínio ou abra chamado no suporte."
            )
        else:
            log.error(f"Erro HTTP {res.status_code}: {res.text[:200]}")

    except requests.exceptions.SSLError as e:
        log.error(f"Erro SSL: {e}")
    except requests.exceptions.ConnectionError:
        log.warning("ERP inacessível — sem internet ou servidor fora do ar.")
    except Exception as e:
        log.error(f"Exceção ao enviar para ERP: {e}")

    return False


# ── ERP: Heartbeat ────────────────────────────────────────────
def enviar_heartbeat():
    payload = {
        "acao":      "heartbeat",
        "bridge_id": BRIDGE_ID,
        "versao":    "4.0",
        "dispositivos": [{
            "id":             DISPOSITIVO_ID,
            "online":         True,
            "ultimo_contato": datetime.now().isoformat(),
        }],
    }
    payload_str = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
    headers = _montar_headers()
    headers["Content-Length"] = str(len(payload_str.encode("utf-8")))

    try:
        res = requests.post(ERP_URL, data=payload_str.encode("utf-8"),
                            headers=headers, timeout=10, verify=False)
        if res.status_code == 200:
            log.info(Cor.CIANO + "♥ Heartbeat enviado — ERP ciente que o bridge está ativo." + Cor.RESET)
        else:
            log.warning(f"Heartbeat retornou HTTP {res.status_code}")
    except Exception as e:
        log.warning(f"Heartbeat falhou: {e}")


# ── Loop principal ────────────────────────────────────────────
def loop_principal():
    heartbeat_counter = 0
    ciclo = 0

    while True:
        try:
            ciclo += 1
            heartbeat_counter += 1

            # Heartbeat a cada ~5 minutos (60 ciclos de 5s)
            if heartbeat_counter >= 60:
                enviar_heartbeat()
                heartbeat_counter = 0

            # Login no equipamento
            session = login_controlid()
            if not session:
                log.warning(f"Ciclo {ciclo}: sem sessão no equipamento. Aguardando...")
                time.sleep(INTERVALO_SEGUNDOS)
                continue

            # Buscar novos logs
            ultimo_log  = obter_ultimo_log_processado()
            logs_brutos = buscar_novos_acessos(session, ultimo_log)
            logout_controlid(session)

            if not logs_brutos:
                # Sem novos eventos — log apenas a cada 12 ciclos (~1 min) para não poluir
                if ciclo % 12 == 0:
                    log.info(
                        Cor.CINZA + f"Aguardando novos acessos... "
                        f"(último ID processado: {ultimo_log})" + Cor.RESET
                    )
                time.sleep(INTERVALO_SEGUNDOS)
                continue

            log.info(f"→ {len(logs_brutos)} novo(s) acesso(s) encontrado(s) após ID {ultimo_log}")

            # Formatar e enviar
            eventos = formatar_eventos(logs_brutos)
            sucesso = enviar_para_erp(eventos)

            if sucesso:
                maior_id = max(e["id"] for e in eventos)
                salvar_ultimo_log_processado(maior_id)
                log.info(f"  Estado salvo. Último ID processado: {maior_id}")
            else:
                log.warning("  Envio falhou. Tentará novamente no próximo ciclo.")

        except KeyboardInterrupt:
            print()
            log.info(Cor.AMARELO + "Bridge encerrado pelo usuário (Ctrl+C)." + Cor.RESET)
            print()
            _aguardar_tecla("Pressione ENTER para fechar a janela...")
            sys.exit(0)
        except Exception as e:
            log.error(f"Erro inesperado: {e}")
            log.debug(traceback.format_exc())

        time.sleep(INTERVALO_SEGUNDOS)


# ── Ponto de entrada ──────────────────────────────────────────
if __name__ == "__main__":
    try:
        ok = diagnostico_inicial()
        if ok:
            loop_principal()
    except KeyboardInterrupt:
        print()
        log.info(Cor.AMARELO + "Encerrado pelo usuário." + Cor.RESET)
        _aguardar_tecla("Pressione ENTER para fechar...")
    except Exception as e:
        print()
        log.critical(f"Erro fatal: {e}")
        log.debug(traceback.format_exc())
        print()
        _aguardar_tecla("Pressione ENTER para fechar...")
        sys.exit(1)
