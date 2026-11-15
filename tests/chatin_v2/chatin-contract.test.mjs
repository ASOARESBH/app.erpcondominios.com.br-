import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const widget = read('frontend/js/chatin-widget.js');
const helper = read('api/helpers/chatin_helper.php');
const api = read('api/api_chatin.php');
const configApi = read('api/api_chatin_config.php');
const migration = read('sql/migration_chatin_v2_mysql.sql');
const menu = read('frontend/js/menu-controller.js');
const guards = read('frontend/js/permissoes-modulos.js');
const controlChannels = read('api/helpers/notificacao_canais_helper.php');
const controlChannelsApi = read('api/api_notificacao_canais.php');
const configPage = read('frontend/pages/config_chatin.html');
const configPageCss = read('assets/css/pages/config_chatin.css');

test('sync pagina eventos sem avançar cursor além do último item retornado', () => {
    assert.match(api, /\$saida\['eventos_cursor'\]\s*=\s*max\(\$eventosDesde,\s*\$ultimoEvento\)/);
    assert.match(helper, /function chatin_eventos_novos[\s\S]*?ORDER BY m\.id ASC LIMIT/);
    assert.doesNotMatch(helper.match(/function chatin_eventos_novos[\s\S]*?\n\}/)?.[0] || '', /AND NOT \(m\.remetente_tipo/);
    assert.match(widget, /ev\.remetente_tipo === S\.eu\.tipo/);
});

test('API não libera CORS credenciado a subdomínios e não faz downgrade de Bearer inválido para sessão', () => {
    assert.doesNotMatch(api, /Access-Control-Allow-Origin/);
    assert.match(api, /chatin_validar_origem_sessao\(\)/);
    assert.match(api, /\$metodo === 'GET' && \$acaoGet === 'sync'/);
    assert.match(api, /if \(\$token === false\) chatin_responder/);
    assert.match(api, /ORIGIN_DENIED/);
    assert.doesNotMatch(configApi, /Access-Control-Allow-Origin/);
    assert.match(configApi, /api_chatin_config_validar_origem_sessao\(\$metodo\)/);
    assert.match(configApi, /ORIGIN_REQUIRED/);
    assert.match(api, /!isset\(\$partesOrigem\['query'\]\)/);
    assert.match(configApi, /isset\(\$recebida\['query'\]\)/);
});

test('Cutucar é POST autenticado, tenant-scoped, direto, opt-in no destinatário e limitado no servidor', () => {
    assert.match(api, /\$metodo === 'POST' && \$acao === 'cutucar'/);
    assert.match(helper, /function chatin_emitir_cutucada[\s\S]*?recurso_cutucar/);
    assert.match(helper, /permitir_cutucar/);
    assert.match(helper, /INTERVAL 30 SECOND/);
    assert.match(helper, /INTERVAL 10 MINUTE/);
    assert.match(helper, /CUTUCAR_LIMITE/);
    assert.match(api, /Retry-After:/);
    assert.match(api, /!is_string\(\$dados\['corpo'\]\)/);
    assert.match(api, /!is_string\(\$dados\['idempotency_key'\]\)/);
    assert.match(widget, /erro\.retryAfter/);
    assert.match(widget, /Aguarde \$\{restante\} s/);
    assert.match(widget, /m\.tipo === 'CUTUCAR'/);
});

test('preferências exigem booleanos estritos e respeitam recursos desativados no backend', () => {
    const salvar = helper.match(/function chatin_salvar_preferencias[\s\S]*?\n\}/)?.[0] || '';
    assert.match(helper, /function chatin_booleano_estrito/);
    assert.match(salvar, /chatin_booleano_estrito\(\$dados\[\$campo\]\)/);
    assert.match(salvar, /recurso_push_app/);
    assert.match(salvar, /recurso_sons/);
    assert.match(salvar, /recurso_cutucar/);
    assert.doesNotMatch(salvar, /notificacao_navegador\s*=>/);
    assert.match(widget, /morador && S\.config\?\.recurso_push_app/);
    assert.match(salvar, /CHATIN_RECURSO_DESATIVADO/);
    assert.match(salvar, /begin_transaction\(\)/);
    assert.match(salvar, /rollback\(\)/);
    assert.match(helper, /MIGRATION_V2_PENDENTE/);
});

test('limites e gravações concorrentes são serializados e notificações só saem após commit', () => {
    const cutucar = helper.match(/function chatin_emitir_cutucada[\s\S]*?\n\}/)?.[0] || '';
    const enviar = helper.match(/function chatin_enviar\([\s\S]*?\n\}/)?.[0] || '';
    const aviso = helper.match(/function chatin_enviar_aviso[\s\S]*?\n\}/)?.[0] || '';
    assert.ok(cutucar.indexOf('begin_transaction()') < cutucar.indexOf('FOR UPDATE'));
    assert.ok(cutucar.indexOf('FOR UPDATE') < cutucar.indexOf('SELECT COUNT(*) AS n'));
    assert.ok(cutucar.indexOf('SELECT COUNT(*) AS n') < cutucar.indexOf('chatin_inserir_mensagem'));
    assert.ok(cutucar.indexOf('chatin_inserir_mensagem') < cutucar.indexOf('commit()'));
    assert.ok(enviar.indexOf('begin_transaction()') < enviar.indexOf('ORDER BY id DESC LIMIT'));
    assert.match(enviar, /ORDER BY id DESC LIMIT '\s*\.\s*\(CHATIN_LIMITE_MSG_POR_MINUTO \+ 1\)/);
    assert.match(enviar, /IDEMPOTENCY_CONFLICT/);
    assert.match(enviar, /chatin_bloquear_pessoas_conversa/);
    assert.ok(enviar.indexOf('commit()') < enviar.indexOf('chatin_push_morador'));
    assert.ok(aviso.indexOf('begin_transaction()') < aviso.indexOf('chatin_inserir_mensagem'));
    assert.ok(aviso.indexOf('commit()') < aviso.indexOf('chatin_push_morador'));
    assert.match(enviar, /recurso_emojis/);
    assert.match(enviar, /CHATIN_EMOJI_DESATIVADO/);
    assert.match(helper, /\\p\{Extended_Pictographic\}/);
});

test('marcar como lida valida tenant, conversa e mensagem antes de avançar o cursor', () => {
    const lida = helper.match(/function chatin_marcar_lida[\s\S]*?\n\}/)?.[0] || '';
    assert.match(lida, /WHERE tenant_id = \? AND conversa_id = \? AND id = \?/);
    assert.match(lida, /LEITURA_INVALIDA/);
    assert.match(lida, /GREATEST\(ultima_leitura_id, \?\)/);
    assert.doesNotMatch(api, /\$_GET\[['"]marcar_lida['"]\]/);
    assert.match(api, /\$metodo === 'POST' && \$acao === 'lida'/);
    assert.match(widget, /api\('lida', \{ metodo: 'POST'/);
});

test('digitando usa TTL, rate limit por presença e nega conversas somente leitura', () => {
    assert.match(helper, /DATE_ADD\(NOW\(\), INTERVAL 6 SECOND\)/);
    assert.match(helper, /chatin_exigir_conversa_permitida\([^;]+\$conversaId, 'digitando'\)/);
    assert.match(helper, /p\.digitando_ate > NOW\(\)/);
    assert.match(widget, /typingTimer/);
    const typing = helper.match(/function chatin_digitando_atualizar[\s\S]*?\n\}/)?.[0] || '';
    assert.ok(typing.indexOf('begin_transaction()') < typing.indexOf('chatin_config_tenant'));
    assert.ok(typing.indexOf('FOR UPDATE') < typing.indexOf('INSERT INTO chatin_presenca'));
    assert.ok(typing.indexOf('INSERT INTO chatin_presenca') < typing.lastIndexOf('commit()'));
});

test('notificação do browser requer gesto/consentimento e não contém corpo ou prévia', () => {
    assert.match(widget, /Notification\.requestPermission\(\)/);
    assert.match(widget, /body: 'Nova mensagem'/);
    assert.doesNotMatch(widget, /body:\s*(?:ev|evento)\.(?:corpo|preview)/);
    assert.match(widget, /INTERVALO_FOCO = 2500/);
    assert.match(widget, /INTERVALO_FECHADO = 20000/);
    assert.match(widget, /Notification\.permission === 'granted'/);
    assert.match(widget, /processarFallbackNaoLidas/);
    assert.match(widget, /conversa\.ultima\?\.remetente_nome/);
    assert.doesNotMatch(widget, /remetente_nome: 'ERP Condomínio'/);
    assert.match(widget, /S\.vista === 'conversa' && vista !== 'conversa'\) pararDigitando\(\)/);
});

test('prévia manual não sofre cooldown; cutucada inicia cooldown de 30 s e navegação usa botões acessíveis', () => {
    const tocar = widget.match(/function tocarSom\([\s\S]*?\n    \}/)?.[0] || '';
    const cutucar = widget.match(/async function enviarCutucada\([\s\S]*?\n    \}/)?.[0] || '';
    assert.match(tocar, /!previsualizacao && agora - S\.ultimaNotificacaoSom < 2000/);
    assert.match(cutucar, /Date\.now\(\) \+ 30000/);
    assert.match(widget, /aria-label="Navegação do Chat-In"/);
    assert.doesNotMatch(widget, /role="tablist"|role="tab"/);
    assert.match(configPage, /somente leitura, sem apagar mensagens/);
    assert.match(configPageCss, /\.config-chatin-loading/);
    assert.doesNotMatch(configPageCss, /\.page-config-chatin-loading/);
});

test('widget não concede acesso global: módulo e sistema são checados separadamente', () => {
    assert.match(menu, /config_chatin:'chatin'/);
    assert.match(menu, /state\.permissoes\.chatin\?\.pode_acessar \|\| state\.permissoes\.sistema\?\.pode_acessar/);
    assert.match(guards, /config_chatin:\s*'chatin'/);
    assert.match(guards, /page === 'config_chatin'.*temAcesso\('chatin', p\).*temAcesso\('sistema', p\)/);
    assert.match(configApi, /\$alternativas = \[\['chatin', \$acao\], \['sistema', \$acao\]\]/);
});

test('migration provisiona Chat-In por tenant e preserva o estado push legado sem sobrescrever linhas existentes', () => {
    assert.match(migration, /CREATE TABLE IF NOT EXISTS `chatin_configuracoes`/);
    assert.match(migration, /FROM `tenants`/);
    assert.match(migration, /INSERT IGNORE INTO `chatin_configuracoes`/);
    assert.match(migration, /push_chatin_ativo/);
    assert.match(migration, /IF v_push_tenant = 1 THEN/);
    assert.match(migration, /LEFT JOIN `pwa_configuracoes` p ON p\.`tenant_id`=t\.`id`/);
    assert.match(migration, /INSERT IGNORE INTO `rbac_grupo_permissoes`/);
    assert.match(migration, /WHERE g\.`tenant_id` IS NOT NULL/);
    assert.match(migration, /revisao_permissoes` = `revisao_permissoes` \+ 1/);
    assert.match(migration, /som_volume fora da faixa 0\.\.100/);
    assert.match(migration, /digitando_conversa_id possui definição divergente/);
});

test('canal Chat-In indisponível por tenant também não pode ser reativado pela API de canais', () => {
    assert.match(controlChannels, /function notif_canais_chatin_disponivel/);
    assert.match(controlChannels, /\$canal !== 'CHATIN' \|\| \$chatinDisponivel/);
    assert.match(controlChannels, /Indisponível: o Chat-In foi desativado/);
    assert.match(controlChannels, /if \(!\$stmt->execute\(\)\)/);
    assert.match(controlChannelsApi, /notif_canais_booleano_estrito/);
    assert.match(controlChannelsApi, /begin_transaction\(\)/);
    assert.match(controlChannelsApi, /rollback\(\)/);
    assert.match(controlChannelsApi, /if \(!\$auditado\) throw/);
});
