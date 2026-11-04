#!/usr/bin/env python3
"""Testes de integração: pré-cadastro de convidados pelo morador (Portal/App) + aba Convidados do painel."""
import json, subprocess, urllib.request, urllib.error, datetime, sys, uuid

BASE = 'http://127.0.0.1:8099/api/'
TOK = {'ana': 'tokenANA_aaaaaaaaaaaaaaaaaaaaaaaa', 'bruno': 'tokenBRUNO_bbbbbbbbbbbbbbbbbbbbbbb',
       'carla': 'tokenCARLA_cccccccccccccccccccccccc', 'expirado': 'tokenEXPIRADO_xxxxxxxxxxxxxxxxxxxxx'}
OPERADOR = '10:1:Porteiro Joao'
HOJE = datetime.date.today()
AMANHA = (HOJE + datetime.timedelta(days=1)).isoformat()
ONTEM = (HOJE - datetime.timedelta(days=1)).isoformat()

falhas = []
total = 0

def req(path, metodo='GET', corpo=None, token=None, operador=None, headers=None):
    h = {'Content-Type': 'application/json'}
    if token: h['Authorization'] = 'Bearer ' + TOK.get(token, token)
    if operador: h['X-Test-Operador'] = operador
    if headers: h.update(headers)
    dados = json.dumps(corpo).encode() if corpo is not None else None
    r = urllib.request.Request(BASE + path, data=dados, method=metodo, headers=h)
    try:
        with urllib.request.urlopen(r) as resp:
            return resp.status, json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        corpo = e.read().decode()
        try: return e.code, json.loads(corpo)
        except Exception: return e.code, {'raw': corpo}

def sql(q):
    return subprocess.run(['mysql', '-N', '-B', 'erptest', '-e', q], capture_output=True, text=True).stdout.strip()

def check(nome, cond, info=''):
    global total
    total += 1
    print(('  OK  ' if cond else '  FALHOU ') + nome + ('' if cond else f'  -> {info}'))
    if not cond: falhas.append(nome)

def portal(acao, metodo='GET', corpo=None, token='ana', extra=''):
    return req(f'api_portal_convidados.php?acao={acao}{extra}', metodo, corpo, token=token)

def painel(acao, metodo='GET', corpo=None, extra=''):
    return req(f'api_convidados.php?acao={acao}{extra}', metodo, corpo, operador=OPERADOR)

def convite(**kw):
    base = {'anfitriao_tipo': 'MORADOR', 'tipo_documento': 'CPF', 'documento': '123.456.789-09', 'nome': 'Joana Convidada',
            'tipo_convidado': 'VISITANTE', 'data_prevista_chegada': AMANHA, 'dias_permanencia': 1, 'modalidade_entradas': 'UNICO'}
    base.update(kw)
    return base

print('== Autenticação')
s, r = portal('opcoes', token=None); check('sem token -> 401', s == 401, (s, r))
s, r = portal('opcoes', token='expirado'); check('token expirado -> 401', s == 401, (s, r))
s, r = portal('opcoes', token='tokenINVALIDO_zzzzzzzzzzzzzzzzzzzzz'); check('token inexistente -> 401', s == 401, (s, r))

print('== Opções do formulário')
s, r = portal('opcoes')
d = r.get('dados', {})
check('opcoes 200', s == 200 and r['sucesso'], r)
check('morador/unidade vêm da sessão', d.get('morador', {}).get('unidade') == '101' and d['morador']['id'] == 1, d.get('morador'))
check('somente dependentes do morador', [x['id'] for x in d.get('dependentes', [])] == [1], d.get('dependentes'))
ids_vis = [x['id'] for x in d.get('visitantes', [])]
check('visitantes só da unidade (sem o do vizinho) e só CPF/RG', ids_vis == [1], d.get('visitantes'))
check('visitante sem documento exposto (só mascarado)', all('documento' not in x for x in d.get('visitantes', [])), d.get('visitantes'))

print('== Criação pelo Portal')
s, r = portal('criar', 'POST', convite(morador_id=2, origem_cadastro='ADMINISTRATIVO', unidade='999'))
check('criar 201', s == 201 and r['sucesso'], (s, r))
id1 = r.get('dados', {}).get('id')
linha = sql(f"SELECT morador_id, unidade, origem_cadastro, cadastrado_por_tipo, cadastrado_por_morador_id, cadastrado_por_usuario_id, cadastrado_por_nome, status FROM convidados WHERE id={id1}")
check('morador/unidade/origem/autor definidos pelo servidor (payload ignorado)',
      linha == '1\t101\tPORTAL_MORADOR\tMORADOR\t1\tNULL\tAna Moradora\tPREVISTO', linha)

print('== Criação pelo App (canal=app)')
s, r = req('api_portal_convidados.php?acao=criar&canal=app', 'POST', convite(documento='52998224725', nome='Pelo App'), token='ana')
check('criar via app 201', s == 201, (s, r))
id_app = r.get('dados', {}).get('id')
check('origem APP_MORADOR', sql(f"SELECT origem_cadastro FROM convidados WHERE id={id_app}") == 'APP_MORADOR')

print('== Validações')
s, r = portal('criar', 'POST', convite(documento='111.111.111-11', nome='X'))
check('CPF inválido rejeitado', s == 400 and not r['sucesso'], (s, r))
s, r = portal('criar', 'POST', convite(documento='246.813.579-28', nome='Bruno'))
check('CPF de morador ativo rejeitado', s == 400 and 'morador' in r['mensagem'], (s, r))
s, r = portal('criar', 'POST', convite(documento='13579246828', data_prevista_chegada=ONTEM))
check('data passada rejeitada', s == 400, (s, r))
s, r = portal('criar', 'POST', convite(documento='13579246828', data_prevista_chegada='2026-11-31'))
check('data inexistente (31/11) rejeitada', s == 400, (s, r))
s, r = portal('criar', 'POST', convite(documento='13579246828', modalidade_entradas='MULTIPLO', limite_entradas=3))
check('limite de entradas fora da lista rejeitado', s == 400, (s, r))
s, r = portal('criar', 'POST', convite(documento='13579246828', tipo_convidado='MORADOR'))
check('tipo inválido rejeitado', s == 400, (s, r))
s, r = portal('criar', 'POST', convite())
check('duplicidade em período sobreposto -> 409', s == 409 and r['dados'].get('codigo') == 'CONVIDADO_DUPLICADO', (s, r))

print('== Anfitrião dependente')
s, r = portal('criar', 'POST', convite(anfitriao_tipo='DEPENDENTE', dependente_id=2, documento='13579246828', nome='Conv do dep alheio'))
check('dependente de outro morador rejeitado', s == 400 and not r['sucesso'], (s, r))
s, r = portal('criar', 'POST', convite(anfitriao_tipo='DEPENDENTE', dependente_id=1, documento='13579246828', nome='Amigo do Davi', tipo_convidado='FAMILIAR', modalidade_entradas='MULTIPLO', limite_entradas=4, dias_permanencia=3))
check('dependente próprio aceito', s == 201, (s, r))
id_dep = r.get('dados', {}).get('id')
check('dependente gravado + validade calculada', sql(f"SELECT anfitriao_tipo, dependente_id, limite_entradas, DATEDIFF(validade_fim, data_prevista_chegada) FROM convidados WHERE id={id_dep}") == 'DEPENDENTE\t1\t4\t2')

print('== Visitante da unidade')
s, r = portal('criar', 'POST', convite(visitante_id=2, documento='', nome=''))
check('visitante do vizinho -> 403', s == 403 and r['dados'].get('codigo') == 'VISITANTE_FORA_DO_ESCOPO', (s, r))
s, r = portal('criar', 'POST', convite(visitante_id=3, documento='', nome=''))
check('visitante sem CPF/RG -> 422 com orientação', s == 422, (s, r))
s, r = portal('criar', 'POST', convite(visitante_id=1, documento='', nome='nome ignorado', tipo_convidado='PRESTADOR', data_prevista_chegada=(HOJE + datetime.timedelta(days=40)).isoformat()))
check('visitante da unidade: documento e nome vêm do cadastro', s == 201, (s, r))
id_vis = r.get('dados', {}).get('id')
check('visitante vinculado', sql(f"SELECT visitante_id, documento_normalizado, nome FROM convidados WHERE id={id_vis}") == '1\t52998224725\tFabio Visitante da Ana')

print('== Idempotência')
chave = str(uuid.uuid4())
corpo = convite(documento='31415926590', nome='Idem', idempotency_key=chave, data_prevista_chegada=(HOJE + datetime.timedelta(days=5)).isoformat())
s1, r1 = portal('criar', 'POST', corpo)
s2, r2 = portal('criar', 'POST', corpo)
check('repetição com mesma chave não duplica', s1 == 201 and s2 == 200 and r2['dados'].get('idempotente') and r1['dados']['id'] == r2['dados']['id'], (s1, r1, s2, r2))
corpo['nome'] = 'Outro'
s3, r3 = portal('criar', 'POST', corpo)
check('mesma chave com dados diferentes -> 409', s3 == 409, (s3, r3))
s, r = portal('criar', 'POST', convite(documento='98765432100', nome='A propria Ana', data_prevista_chegada=(HOJE + datetime.timedelta(days=50)).isoformat()))
check('morador não convida o próprio CPF', s == 400, (s, r))

print('== Listagem e escopo')
s, r = portal('listar')
nomes = [c['nome'] for c in r['dados']['convidados']]
check('Ana lista seus convites ativos', s == 200 and 'Joana Convidada' in nomes and 'Amigo do Davi' in nomes, nomes)
check('lista sem documento completo', all('documento' not in c for c in r['dados']['convidados']))
s, r = portal('listar', token='bruno')
check('Bruno (mesmo condomínio, outra unidade) não vê convites da Ana', s == 200 and r['dados']['convidados'] == [], r['dados'])
s, r = portal('obter', extra=f'&id={id1}', token='bruno')
check('Bruno não obtém convite da Ana -> 404', s == 404, (s, r))
s, r = portal('obter', extra=f'&id={id1}', token='carla')
check('Carla (outro condomínio) não obtém -> 404', s == 404, (s, r))
s, r = portal('obter', extra=f'&id={id1}')
check('Ana obtém detalhe com documento completo (cadastro próprio)', s == 200 and r['dados'].get('documento') == '123.456.789-09' and r['dados']['pode_editar'], r)

print('== Edição pelo Portal')
s, r = portal('editar', 'POST', convite(id=id1, nome='Joana Editada', dias_permanencia=2))
check('editar próprio PREVISTO', s == 200, (s, r))
check('origem preservada na edição', sql(f"SELECT nome, origem_cadastro, atualizado_por_tipo, atualizado_por_id FROM convidados WHERE id={id1}") == 'Joana Editada\tPORTAL_MORADOR\tMORADOR\t1')
s, r = req('api_portal_convidados.php?acao=editar', 'POST', convite(id=id1, nome='Hack'), token='bruno')
check('Bruno não edita convite da Ana -> 404', s == 404, (s, r))

print('== Integração com a portaria (painel)')
s, r = painel('listar', extra=f'&data={AMANHA}')
check('painel lista os pré-cadastros do morador', s == 200 and any(c['id'] == id1 for c in r['dados']['convidados']), r)
c1 = next((c for c in r['dados']['convidados'] if c['id'] == id1), {})
check('painel vê autoria MORADOR e origem PORTAL_MORADOR', c1.get('cadastrado_por_tipo') == 'MORADOR' and c1.get('origem_cadastro') == 'PORTAL_MORADOR', c1)
check('resumo do painel traz pre_cadastros_morador', r['dados']['resumo'].get('pre_cadastros_morador', 0) >= 3, r['dados']['resumo'])
s, r = painel('listar', extra=f'&data={AMANHA}&origem=MORADOR')
check('filtro origem=MORADOR', s == 200 and all(c['origem_cadastro'] in ('PORTAL_MORADOR', 'APP_MORADOR') for c in r['dados']['convidados']) and len(r['dados']['convidados']) >= 3, r['dados'])
s, r = painel('listar', extra='&origem=XPTO')
check('filtro de origem inválido rejeitado', s == 400, (s, r))
s, r = painel('buscar_documento', extra=f'&tipo_documento=CPF&documento=12345678909&data={AMANHA}')
check('portaria encontra o pré-cadastro pelo documento', s == 200 and any(c['id'] == id1 for c in r['dados']['convidados']), r)

print('== Painel: regras de origem')
s, r = painel('criar', 'POST', convite(morador_id=1, documento='13579246828', nome='Via painel', origem_cadastro='PORTAL_MORADOR', data_prevista_chegada=(HOJE + datetime.timedelta(days=10)).isoformat()))
check('painel não pode se passar por pré-cadastro do morador', s == 400, (s, r))
s, r = painel('criar', 'POST', convite(morador_id=1, documento='13579246828', nome='Via painel', origem_cadastro='WHATSAPP', data_prevista_chegada=(HOJE + datetime.timedelta(days=10)).isoformat()))
check('painel cria normalmente (regressão)', s == 201, (s, r))
id_painel = r.get('dados', {}).get('id')
check('autoria USUARIO no painel', sql(f"SELECT cadastrado_por_tipo, cadastrado_por_usuario_id, cadastrado_por_morador_id, cadastrado_por_nome, atualizado_por_tipo, atualizado_por_id FROM convidados WHERE id={id_painel}") == 'USUARIO\t10\tNULL\tPorteiro Joao\tUSUARIO\t10')
s, r = painel('editar', 'PUT', convite(id=id_app, morador_id=1, documento='52998224725', nome='Editado na portaria', origem_cadastro='ADMINISTRATIVO'))
check('portaria edita pré-cadastro do app', s == 200, (s, r))
check('origem APP_MORADOR preservada na edição da portaria', sql(f"SELECT origem_cadastro, atualizado_por_tipo FROM convidados WHERE id={id_app}") == 'APP_MORADOR\tUSUARIO')

print('== Portal x convite criado pela portaria')
s, r = portal('listar')
cp = next((c for c in r['dados']['convidados'] if c['id'] == id_painel), None)
check('morador vê convite que a portaria criou para ele', cp is not None, r['dados'])
check('não editável pelo morador, mas cancelável', cp and not cp['pode_editar'] and cp['pode_cancelar'], cp)
s, r = portal('editar', 'POST', convite(id=id_painel, documento='13579246828', nome='Tentativa'))
check('morador não edita convite da portaria -> 403', s == 403 and r['dados'].get('codigo') == 'EDICAO_NAO_PERMITIDA', (s, r))
s, r = portal('obter', extra=f'&id={id_painel}')
check('documento completo não exposto para convite da portaria', s == 200 and 'documento' not in r['dados'], r['dados'])

print('== Cancelamento')
s, r = portal('cancelar', 'POST', {'id': id_painel})
check('cancelar sem motivo -> 400', s == 400, (s, r))
s, r = portal('cancelar', 'POST', {'id': id1, 'motivo': 'x'}, token='bruno')
check('Bruno não cancela convite da Ana', s == 409 and sql(f"SELECT status FROM convidados WHERE id={id1}") == 'PREVISTO', (s, r))
s, r = portal('cancelar', 'POST', {'id': id_painel, 'motivo': 'Visita desmarcada'})
check('morador cancela', s == 200, (s, r))
check('cancelamento auditado com o morador', sql(f"SELECT status, cancelado_por_nome, motivo_cancelamento, atualizado_por_tipo FROM convidados WHERE id={id_painel}") == 'CANCELADO\tAna Moradora\tVisita desmarcada\tMORADOR')
s, r = portal('cancelar', 'POST', {'id': id_painel, 'motivo': 'de novo'})
check('cancelar de novo -> 409', s == 409, (s, r))
s, r = portal('listar', extra='&filtro=historico')
check('cancelado aparece no histórico', any(c['id'] == id_painel for c in r['dados']['convidados']), r['dados'])
s, r = painel('cancelar', 'POST', {'id': id_dep, 'motivo': 'Portaria cancelou'})
check('painel cancela (regressão)', s == 200 and sql(f"SELECT atualizado_por_tipo, cancelado_por_nome FROM convidados WHERE id={id_dep}") == 'USUARIO\tPorteiro Joao', (s, r))

print('== Alertas da unidade')
s, r = portal('verificar_alertas', 'POST', convite(documento='13579246828', nome='Teste alerta', data_prevista_chegada=(HOJE + datetime.timedelta(days=20)).isoformat()))
check('verificar_alertas responde', s == 200 and r['sucesso'], (s, r))

print('== Alertas: morador não vê alerta de outra unidade')
sql("DELETE FROM alertas_unidade_alvos; DELETE FROM alertas_unidade; DELETE FROM alertas_unidade_cache_ids;")
sql("""INSERT INTO alertas_unidade (tenant_id,unidade,titular_tipo,morador_id,titulo,mensagem,nivel,acao,publico,abrangencia,vigencia_inicio,status,origem,criado_por_tipo,criado_por_morador_id)
VALUES (1,'101','MORADOR',1,'Viagem da Ana','x','CRITICO','BLOQUEAR','VISITANTE','TODOS_DO_TIPO',NOW() - INTERVAL 1 DAY,'ATIVO','PORTAL','MORADOR',1),
       (1,'102','MORADOR',2,'Alerta privado do Bruno','x','CRITICO','BLOQUEAR','TODOS','ESPECIFICO',NOW() - INTERVAL 1 DAY,'ATIVO','PORTAL','MORADOR',2)""")
sql("INSERT INTO alertas_unidade_alvos (tenant_id,alerta_id,tipo_alvo,tipo_documento,documento_normalizado) SELECT 1,id,'DOCUMENTO','CPF','27182818205' FROM alertas_unidade WHERE unidade='102'")
corpo_alerta = convite(documento='271.828.182-05', nome='Pessoa X', data_prevista_chegada=(HOJE + datetime.timedelta(days=3)).isoformat())
s, r = portal('verificar_alertas', 'POST', corpo_alerta)
check('morador vê só o alerta da própria unidade', [a['titulo'] for a in r['dados']['alertas']] == ['Viagem da Ana'] and r['dados']['aviso'] != '', r)
s, r = painel('verificar_alertas', 'POST', dict(corpo_alerta, morador_id=1, origem_cadastro='WHATSAPP'))
check('portaria vê os dois alertas', len(r['dados']['alertas']) == 2, r)

print('== Fallback sem migration APP_MORADOR')
sql("UPDATE convidados SET origem_cadastro='PORTAL_MORADOR' WHERE origem_cadastro='APP_MORADOR'")
sql("ALTER TABLE convidados MODIFY origem_cadastro ENUM('EMAIL','WHATSAPP','PRESENCIAL','ADMINISTRATIVO','PORTAL_MORADOR') NOT NULL")
s, r = req('api_portal_convidados.php?acao=criar&canal=app', 'POST', convite(documento='13579246828', nome='Sem migration', data_prevista_chegada=(HOJE + datetime.timedelta(days=30)).isoformat()), token='ana')
check('app sem migration grava PORTAL_MORADOR (não quebra)', s == 201 and sql(f"SELECT origem_cadastro FROM convidados WHERE id={r['dados']['id']}") == 'PORTAL_MORADOR', (s, r))
subprocess.run('mysql erptest < sql/migration_convidados_pre_cadastro_morador_mysql57.sql', shell=True, check=True)
check('migration reaplicada restaura APP_MORADOR', 'APP_MORADOR' in sql("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='erptest' AND TABLE_NAME='convidados' AND COLUMN_NAME='origem_cadastro'"))

print(f'\n{total - len(falhas)}/{total} testes OK')
if falhas:
    print('FALHAS:', *falhas, sep='\n - ')
    sys.exit(1)
