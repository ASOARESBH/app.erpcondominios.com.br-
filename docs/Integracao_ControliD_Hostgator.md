# Integração Control iD com Sistema ERP em Nuvem (Hostgator)

## 1. O Cenário e o Desafio

A arquitetura atual possui um desafio clássico de redes e integração de hardware:
*   **O Equipamento:** O leitor Control iD (UHF/RFID) está instalado fisicamente na portaria, conectado à rede local (IP: `192.168.3.150`).
*   **O Sistema:** O ERP do condomínio está hospedado em nuvem na Hostgator (`app.erpcondominios.com.br`).
*   **O Problema:** O equipamento Control iD em modo *Standalone* não consegue enviar os eventos de acesso diretamente para a Hostgator porque a Hostgator não possui um IP fixo/porta aberta dedicada para receber conexões diretas do equipamento de forma transparente (sem configuração de DNS dinâmico e redirecionamento de portas no roteador local, o que é inseguro e instável).

## 2. A Solução: Arquitetura "Bridge" (Relay Local)

Para resolver isso de forma segura e estável, o sistema ERP já possui a fundação para uma arquitetura de **Bridge (Ponte)**. O arquivo `bridge_receiver.php` no Hostgator foi projetado exatamente para receber dados de um "agente intermediário".

A solução consiste em instalar um pequeno script (o "Bridge") em um computador local na mesma rede do equipamento (ex: o computador da portaria).

### Como funciona o fluxo:

1.  **Leitura do TAG:** O morador passa com o veículo e o leitor UHF lê a TAG RFID.
2.  **Registro Local:** O equipamento Control iD registra o acesso em sua memória interna.
3.  **Coleta pelo Bridge:** O script Bridge rodando no computador local (PC da Portaria) consulta periodicamente a API do equipamento (`192.168.3.150`) buscando novos logs de acesso.
4.  **Envio para a Nuvem:** O script Bridge empacota esses novos logs em formato JSON e os envia via requisição HTTP POST segura (HTTPS) para a API no Hostgator (`https://app.erpcondominios.com.br/api/bridge_receiver.php`).
5.  **Processamento no ERP:** O `bridge_receiver.php` autentica a requisição (via API Key), processa os eventos, identifica o morador vinculado à TAG RFID e registra o acesso na tabela `registros_acesso`.

## 3. Implementação Prática

### Passo 3.1: Configurar o Equipamento Control iD

Acesse a interface web do equipamento (`http://192.168.3.150`) e certifique-se de que ele está configurado no **Modo Standalone** (modo autônomo). Neste modo, ele autoriza os acessos localmente e apenas guarda os logs.

### Passo 3.2: O Script Bridge Local (Python)

Você precisará de um computador na portaria rodando Python. Este script consultará o Control iD e enviará os dados para o Hostgator.

Crie um arquivo chamado `controlid_bridge.py` no computador local:

```python
import requests
import time
import json
import os

# ================= CONFIGURAÇÕES =================
CONTROLID_IP = "http://192.168.3.150"
CONTROLID_USER = "admin"
CONTROLID_PASS = "admin" # Substitua pela senha real do equipamento

ERP_URL = "https://app.erpcondominios.com.br/api/bridge_receiver.php"
API_KEY = "SUA_CHAVE_API_AQUI" # Obtenha esta chave na tabela 'configuracoes' do banco
DISPOSITIVO_ID = 1 # ID do dispositivo cadastrado no ERP

# Arquivo para guardar o último log lido
STATE_FILE = "ultimo_log.txt"
# =================================================

def login_controlid():
    url = f"{CONTROLID_IP}/login.fcgi"
    payload = {"login": CONTROLID_USER, "password": CONTROLID_PASS}
    try:
        res = requests.post(url, json=payload, timeout=5)
        if res.status_code == 200:
            return res.json().get("session")
    except Exception as e:
        print(f"Erro ao logar no Control iD: {e}")
    return None

def obter_ultimo_log_processado():
    if os.path.exists(STATE_FILE):
        with open(STATE_FILE, "r") as f:
            return int(f.read().strip())
    return 0

def salvar_ultimo_log_processado(log_id):
    with open(STATE_FILE, "w") as f:
        f.write(str(log_id))

def buscar_novos_acessos(session, ultimo_log):
    url = f"{CONTROLID_IP}/load_objects.fcgi?session={session}"
    payload = {
        "object": "access_logs",
        "where": {"access_logs": {"id": {">": ultimo_log}}}
    }
    try:
        res = requests.post(url, json=payload, timeout=5)
        if res.status_code == 200:
            return res.json().get("access_logs", [])
    except Exception as e:
        print(f"Erro ao buscar logs: {e}")
    return []

def enviar_para_erp(logs):
    if not logs:
        return True

    # Formatar os logs para o formato esperado pelo bridge_receiver.php
    eventos_formatados = []
    for log in logs:
        eventos_formatados.append({
            "id": log["id"],
            "time": log["time"], # Timestamp Unix
            "event_type": log["event"],
            "user_id": log["user_id"],
            "uhf_tag": str(log.get("card_value", "")), # O número da TAG lida
            "door_id": log.get("portal_id", 0)
        })

    payload = {
        "acao": "eventos",
        "dispositivo_id": DISPOSITIVO_ID,
        "eventos": eventos_formatados
    }
    
    headers = {
        "X-API-KEY": API_KEY,
        "Content-Type": "application/json"
    }

    try:
        res = requests.post(ERP_URL, json=payload, headers=headers, timeout=10)
        if res.status_code == 200:
            return True
        else:
            print(f"Erro do ERP: {res.text}")
    except Exception as e:
        print(f"Erro de conexao com ERP: {e}")
    return False

def loop_principal():
    print("Iniciando Bridge Control iD -> ERP Hostgator...")
    while True:
        session = login_controlid()
        if session:
            ultimo_log = obter_ultimo_log_processado()
            logs = buscar_novos_acessos(session, ultimo_log)
            
            if logs:
                print(f"Encontrados {len(logs)} novos acessos. Enviando...")
                sucesso = enviar_para_erp(logs)
                
                if sucesso:
                    maior_id = max(log["id"] for log in logs)
                    salvar_ultimo_log_processado(maior_id)
                    print(f"Enviados com sucesso. Ultimo ID: {maior_id}")
            
            # Fazer logout para não travar sessões no equipamento
            requests.post(f"{CONTROLID_IP}/logout.fcgi?session={session}")
        
        # Aguarda 5 segundos antes de checar novamente
        time.sleep(5)

if __name__ == "__main__":
    loop_principal()
```

### Passo 3.3: Como colocar para rodar

1.  **No Hostgator (Banco de Dados):**
    *   Acesse o banco de dados via phpMyAdmin.
    *   Vá na tabela `configuracoes`.
    *   Encontre ou crie a chave `bridge_api_key` e defina um valor seguro (ex: `SenhaForte123`).
    *   Certifique-se de ter um registro na tabela `dispositivos_controlid` com ID 1.

2.  **No Computador da Portaria:**
    *   Instale o Python (baixe em python.org).
    *   Abra o prompt de comando (CMD) e instale a biblioteca de requisições: `pip install requests`
    *   Edite o script `controlid_bridge.py` colocando o IP do equipamento, senha e a `API_KEY` que você definiu no banco.
    *   Execute o script: `python controlid_bridge.py`
    *   *Dica:* Você pode configurar este script para rodar como um serviço do Windows ou usar o "Agendador de Tarefas" para iniciar junto com o Windows e ficar rodando em segundo plano.

## 4. Por que não usar o "Push" ou "Monitor" nativo da Control iD?

A Control iD possui os modos *Push* e *Monitor* nativos, onde o próprio equipamento tenta enviar dados para a nuvem. No entanto:

1.  **Monitor:** Exige que a Hostgator receba as requisições, mas frequentemente as hospedagens compartilhadas (como Hostgator) bloqueiam requisições de IPs dinâmicos não autenticados via ModSecurity, ou o formato JSON nativo da Control iD não bate perfeitamente com a API atual sem reescrever muita coisa.
2.  **Modo Push (Enterprise):** Exige que a lógica de liberação (abrir ou não a cancela) seja feita na nuvem. Se a internet do condomínio cair, a cancela para de funcionar.

**A solução via Script Bridge (Polling)** mantém o equipamento no modo *Standalone* (a cancela abre mesmo sem internet) e o script no computador local garante a entrega dos dados para o Hostgator de forma robusta e segura. O `bridge_receiver.php` já está programado exatamente para receber dados deste formato.
