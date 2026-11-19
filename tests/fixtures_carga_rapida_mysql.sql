DROP DATABASE IF EXISTS erp_pg_source; CREATE DATABASE erp_pg_source CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE erp_pg_source;
SET SESSION sql_mode='';
CREATE TABLE tenants (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, nome varchar(100) NOT NULL, slug varchar(50) NOT NULL, UNIQUE KEY uk_slug(slug)) ;
CREATE TABLE moradores (
 id int NOT NULL AUTO_INCREMENT, tenant_id int unsigned NOT NULL, nome varchar(150) COLLATE utf8mb4_0900_ai_ci NOT NULL,
 email varchar(150) DEFAULT NULL, senha_hash varchar(255) NOT NULL DEFAULT '', ativo tinyint(1) NOT NULL DEFAULT 1,
 status enum('ativo','inativo') NOT NULL DEFAULT 'ativo', saldo decimal(12,2) NOT NULL DEFAULT 0.00,
 nascimento date DEFAULT NULL, criado_em timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizado_em timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 ultimo_acesso datetime NOT NULL DEFAULT '0000-00-00 00:00:00', hora time DEFAULT NULL, obs text,
 prefs json DEFAULT NULL, foto longblob, big bigint unsigned DEFAULT NULL,
 PRIMARY KEY(id), KEY idx_tenant(tenant_id), KEY idx_obs(obs(50)), UNIQUE KEY uk_email(tenant_id,email),
 CONSTRAINT fk_mor_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE) ;
CREATE TABLE leituras (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, tenant_id int unsigned NOT NULL,
 leitura_anterior decimal(10,2) NOT NULL DEFAULT 0, leitura_atual decimal(10,2) NOT NULL DEFAULT 0,
 consumo decimal(10,2) GENERATED ALWAYS AS (leitura_atual - leitura_anterior) STORED);
CREATE TABLE logs_sistema (tenant_id int, msg varchar(255) COLLATE utf8mb3_unicode_ci, quando datetime DEFAULT CURRENT_TIMESTAMP) CHARACTER SET utf8mb3;
CREATE TABLE mt_protocolos_id_zero_backup (id int, texto varchar(20));
CREATE TABLE codigos (id int AUTO_INCREMENT PRIMARY KEY, codigo varchar(20) NOT NULL, UNIQUE KEY uk_c(codigo)) COLLATE utf8mb4_bin;
CREATE TABLE `order` (id int AUTO_INCREMENT PRIMARY KEY, `user` varchar(20), `select` int);
CREATE TABLE orfaos (id int AUTO_INCREMENT PRIMARY KEY, tenant_id int unsigned, KEY(tenant_id), CONSTRAINT fk_orf FOREIGN KEY (tenant_id) REFERENCES tenants(id));
INSERT INTO tenants VALUES (1,'Cond A','cond-a'),(2,'Cond B','cond-b');
INSERT INTO moradores (tenant_id,nome,email,senha_hash,status,saldo,nascimento,ultimo_acesso,hora,obs,prefs,foto,big)
VALUES (1,'José Ção','a@x.com','$2y$h',  'ativo',10.50,'0000-00-00','0000-00-00 00:00:00','838:00:00',CONCAT('a',CHAR(0),'b'),'{"x":1}',UNHEX('00FF0041'),18446744073709551615),
       (1,'MARIA','A@X.COM2','h','',-3.25,'1990-05-10','2024-01-01 10:00:00','12:30:00','ok',NULL,NULL,5),
       (2,'Zé','b@y.com','h','inativo',0,NULL,'2025-02-28 23:59:59',NULL,NULL,'[1,2]','',NULL);
INSERT INTO leituras (tenant_id,leitura_anterior,leitura_atual) VALUES (1,10,25.5),(2,3,3);
INSERT INTO logs_sistema VALUES (1,'x','2024-01-01'),(1,'x','2024-01-01');
INSERT INTO mt_protocolos_id_zero_backup VALUES (0,'z');
INSERT INTO codigos (codigo) VALUES ('abc'),('ABC');
INSERT INTO `order` (`user`,`select`) VALUES ('u',1);
SET FOREIGN_KEY_CHECKS=0; INSERT INTO orfaos (tenant_id) VALUES (1),(99); SET FOREIGN_KEY_CHECKS=1;
CREATE TRIGGER trg_x BEFORE INSERT ON `order` FOR EACH ROW SET NEW.`select`=1;
USE erp_pg_source;
CREATE TABLE rbac_sessoes (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, sessao_hash char(64) NOT NULL);
CREATE TABLE rbac_auditoria (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, sessao_id bigint unsigned DEFAULT NULL,
  KEY(sessao_id), CONSTRAINT fk_rbac_auditoria_sessao FOREIGN KEY (sessao_id) REFERENCES rbac_sessoes(id) ON DELETE SET NULL ON UPDATE CASCADE);
CREATE TABLE contador_grande (id int AUTO_INCREMENT PRIMARY KEY, valor bigint unsigned);
INSERT INTO rbac_sessoes (sessao_hash) VALUES (REPEAT('a',64)),(REPEAT('b',64));
INSERT INTO rbac_auditoria (sessao_id) VALUES (1),(2),(NULL);
INSERT INTO contador_grande (valor) VALUES (18446744073709551615),(1);
