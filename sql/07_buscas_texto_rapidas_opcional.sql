-- Beauty Pet - aceleração opcional das buscas por texto (ILIKE '%texto%').
-- Supabase normalmente oferece pg_trgm. Execute este arquivo separado.

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA extensions;

CREATE INDEX IF NOT EXISTS ix_pets_nome_trgm
    ON public.pets USING gin (nome extensions.gin_trgm_ops)
    WHERE ativo = TRUE;

CREATE INDEX IF NOT EXISTS ix_clientes_nome_trgm
    ON public.clientes USING gin (nome extensions.gin_trgm_ops)
    WHERE ativo = TRUE;

CREATE INDEX IF NOT EXISTS ix_clientes_email_trgm
    ON public.clientes USING gin (email extensions.gin_trgm_ops)
    WHERE ativo = TRUE AND email IS NOT NULL;

CREATE INDEX IF NOT EXISTS ix_racas_nome_trgm
    ON public.racas USING gin (nome extensions.gin_trgm_ops)
    WHERE ativo = TRUE;

ANALYZE public.pets;
ANALYZE public.clientes;
ANALYZE public.racas;
