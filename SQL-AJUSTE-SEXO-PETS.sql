-- Executar no SQL Editor do Supabase ANTES da importação, após backup.
-- O valor padrão NAO_INFORMADO tem 13 caracteres e não cabe em VARCHAR(8).
ALTER TABLE public.pets ALTER COLUMN sexo TYPE VARCHAR(20);

-- Verificação: deverá aparecer 20 em character_maximum_length.
SELECT table_name, column_name, character_maximum_length
FROM information_schema.columns
WHERE table_schema='public' AND table_name='pets' AND column_name='sexo';
