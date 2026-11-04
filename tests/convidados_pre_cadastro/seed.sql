SET FOREIGN_KEY_CHECKS=0;
TRUNCATE moradores; TRUNCATE dependentes; TRUNCATE visitantes; TRUNCATE acessos_visitantes; TRUNCATE sessoes_portal; TRUNCATE convidados; TRUNCATE registros_acesso;
INSERT INTO moradores (id,tenant_id,nome,cpf,unidade,ativo) VALUES
 (1,1,'Ana Moradora','987.654.321-00','101',1),
 (2,1,'Bruno Vizinho','246.813.579-28','102',1),
 (3,2,'Carla Outro Condominio','135.792.468-28','101',1);
INSERT INTO dependentes (id,tenant_id,morador_id,nome_completo,ativo) VALUES (1,1,1,'Davi Filho da Ana',1),(2,1,2,'Eva Filha do Bruno',1);
INSERT INTO visitantes (id,tenant_id,nome_completo,tipo_documento,documento,morador_id,cadastrado_por_morador_id,ativo) VALUES
 (1,1,'Fabio Visitante da Ana','CPF','529.982.247-25',NULL,1,1),
 (2,1,'Gabi Visitante do Bruno','CPF','111.444.777-35',NULL,2,1),
 (3,1,'Hugo Sem CPF','PASSAPORTE','AB123456',NULL,1,1);
INSERT INTO sessoes_portal (tenant_id,morador_id,token,ativo,data_expiracao) VALUES
 (1,1,'tokenANA_aaaaaaaaaaaaaaaaaaaaaaaa',1,DATE_ADD(NOW(),INTERVAL 1 DAY)),
 (1,2,'tokenBRUNO_bbbbbbbbbbbbbbbbbbbbbbb',1,DATE_ADD(NOW(),INTERVAL 1 DAY)),
 (2,3,'tokenCARLA_cccccccccccccccccccccccc',1,DATE_ADD(NOW(),INTERVAL 1 DAY)),
 (1,1,'tokenEXPIRADO_xxxxxxxxxxxxxxxxxxxxx',1,DATE_SUB(NOW(),INTERVAL 1 DAY));
