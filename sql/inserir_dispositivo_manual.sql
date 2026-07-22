-- ================================================================================
-- SCRIPT PARA INSERIR DISPOSITIVO MANUALMENTE NO BANCO
-- ================================================================================
-- Use este script se o formulário web não estiver funcionando
-- Execute no phpMyAdmin
-- ================================================================================

-- ================================================================================
-- EXEMPLO 1: Tablet Portaria Principal
-- ================================================================================
INSERT INTO dispositivos_console (
    nome_dispositivo,
    token_acesso,
    tipo_dispositivo,
    ativo,
    data_cadastro
) VALUES (
    'Tablet Portaria Principal',     -- Nome do dispositivo
    'A9F3K7L2Q8M4',                   -- Token de 12 caracteres (ALTERE ESTE TOKEN!)
    'Tablet',                         -- Tipo: Tablet, Câmera, Totem, Outro
    1,                                -- Status: 1 = Ativo, 0 = Inativo
    NOW()                             -- Data de cadastro (automático)
);

-- ================================================================================
-- EXEMPLO 2: Tablet Portaria Secundária
-- ================================================================================
INSERT INTO dispositivos_console (
    nome_dispositivo,
    token_acesso,
    tipo_dispositivo,
    ativo,
    data_cadastro
) VALUES (
    'Tablet Portaria Secundária',
    'B3H8N5P9R2K7',                   -- Token diferente do anterior
    'Tablet',
    1,
    NOW()
);

-- ================================================================================
-- EXEMPLO 3: Câmera Entrada
-- ================================================================================
INSERT INTO dispositivos_console (
    nome_dispositivo,
    token_acesso,
    tipo_dispositivo,
    ativo,
    data_cadastro
) VALUES (
    'Câmera Entrada',
    'C7M2Q4W8X5Y9',
    'Câmera',
    1,
    NOW()
);

-- ================================================================================
-- EXEMPLO 4: Totem Recepção
-- ================================================================================
INSERT INTO dispositivos_console (
    nome_dispositivo,
    token_acesso,
    tipo_dispositivo,
    ativo,
    data_cadastro
) VALUES (
    'Totem Recepção',
    'D9K3L7N2P5T8',
    'Totem',
    1,
    NOW()
);

-- ================================================================================
-- TEMPLATE PARA COPIAR E COLAR
-- ================================================================================
/*
INSERT INTO dispositivos_console (
    nome_dispositivo,
    token_acesso,
    tipo_dispositivo,
    ativo,
    data_cadastro
) VALUES (
    'NOME_DO_DISPOSITIVO',            -- Altere aqui
    'XXXXXXXXXXXX',                   -- Token de 12 caracteres - Altere aqui
    'Tablet',                         -- Tablet, Câmera, Totem, Outro
    1,                                -- 1 = Ativo, 0 = Inativo
    NOW()
);
*/

-- ================================================================================
-- COMO GERAR TOKEN DE 12 CARACTERES
-- ================================================================================
-- Use apenas letras maiúsculas (A-Z) e números (0-9)
-- Evite caracteres confusos: I, O, 0, 1
-- Exemplos de tokens válidos:
--   A9F3K7L2Q8M4
--   B3H8N5P9R2K7
--   C7M2Q4W8X5Y9
--   D9K3L7N2P5T8
--   E2J6M9S4V7Z3
--   F8K3N7Q2T5W9
--   G4L8P2R6U9X3
--   H7M3Q8S2V6Y9

-- ================================================================================
-- VERIFICAR DISPOSITIVOS CADASTRADOS
-- ================================================================================
SELECT 
    id,
    nome_dispositivo,
    token_acesso,
    tipo_dispositivo,
    ativo,
    data_cadastro,
    data_ultimo_acesso,
    total_acessos
FROM dispositivos_console
ORDER BY data_cadastro DESC;

-- ================================================================================
-- ATUALIZAR STATUS DO DISPOSITIVO
-- ================================================================================
-- Para ATIVAR um dispositivo:
-- UPDATE dispositivos_console SET ativo = 1 WHERE id = 1;

-- Para DESATIVAR um dispositivo:
-- UPDATE dispositivos_console SET ativo = 0 WHERE id = 1;

-- ================================================================================
-- ATUALIZAR TOKEN DO DISPOSITIVO
-- ================================================================================
-- Se precisar alterar o token de um dispositivo:
-- UPDATE dispositivos_console SET token_acesso = 'NOVO_TOKEN_12' WHERE id = 1;

-- ================================================================================
-- EXCLUIR DISPOSITIVO
-- ================================================================================
-- ⚠️ CUIDADO: Esta ação é irreversível!
-- DELETE FROM dispositivos_console WHERE id = 1;

-- ================================================================================
-- VERIFICAR SE TOKEN JÁ EXISTE
-- ================================================================================
-- Antes de inserir, verifique se o token já está em uso:
-- SELECT * FROM dispositivos_console WHERE token_acesso = 'A9F3K7L2Q8M4';
-- Se retornar algum resultado, o token já está em uso. Gere outro token.

-- ================================================================================
-- INSTRUÇÕES DE USO
-- ================================================================================
-- 1. Copie um dos exemplos acima ou use o template
-- 2. Altere o nome do dispositivo
-- 3. Gere um token único de 12 caracteres
-- 4. Escolha o tipo de dispositivo
-- 5. Defina o status (1 = Ativo, 0 = Inativo)
-- 6. Execute o INSERT no phpMyAdmin
-- 7. Anote o token para configurar o tablet
-- 8. Acesse console_acesso.html e digite o token
-- 9. Pronto! O tablet está configurado

-- ================================================================================
-- OBSERVAÇÕES IMPORTANTES
-- ================================================================================
-- ✅ Token deve ter EXATAMENTE 12 caracteres
-- ✅ Token deve ser ÚNICO (não pode repetir)
-- ✅ Use apenas letras maiúsculas (A-Z) e números (0-9)
-- ✅ Evite caracteres confusos: I, O, 0, 1
-- ✅ Anote o token após cadastrar (não será exibido novamente)
-- ✅ Configure o tablet com o token gerado
-- ✅ Dispositivo inativo (ativo = 0) não pode validar QR Codes

-- ================================================================================
-- FIM DO SCRIPT
-- ================================================================================
