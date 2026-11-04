-- Beauty Pet - índices para acelerar navegação no web
-- Seguro para executar mais de uma vez: todos usam IF NOT EXISTS.
-- Rode no Supabase > SQL Editor.

-- Fechamentos por data: usado no dashboard, fechamento diário e mensal.
CREATE INDEX IF NOT EXISTS ix_fechamentos_diarios_data
    ON public.fechamentos_diarios (data_fechamento);

-- Agenda: leitura dos itens de um agendamento e consultas por status/data.
CREATE INDEX IF NOT EXISTS ix_agendamento_itens_agendamento
    ON public.agendamento_itens (agendamento_id);

CREATE INDEX IF NOT EXISTS ix_agendamentos_status_data
    ON public.agendamentos (status, data_hora_inicio);

CREATE INDEX IF NOT EXISTS ix_agendamentos_mobile_solicitados
    ON public.agendamentos (origem, status)
    WHERE origem = 'MOBILE' AND status = 'SOLICITADO';

-- Listagens ordenadas de cadastros ativos.
CREATE INDEX IF NOT EXISTS ix_pets_ativos_nome
    ON public.pets (nome, id)
    WHERE ativo = TRUE;

CREATE INDEX IF NOT EXISTS ix_clientes_ativos_nome
    ON public.clientes (nome, id)
    WHERE ativo = TRUE;

-- Relações mais usadas nas fichas e nos fechamentos.
CREATE INDEX IF NOT EXISTS ix_lancamentos_pet_fechamento
    ON public.lancamentos_diarios (pet_id, fechamento_diario_id);

CREATE INDEX IF NOT EXISTS ix_lancamentos_fechamento_pet
    ON public.lancamentos_diarios (fechamento_diario_id, pet_id);

CREATE INDEX IF NOT EXISTS ix_lancamentos_pacote_creditos
    ON public.lancamentos_diarios (pet_pacote_id, creditos_pacote_usados)
    WHERE pet_pacote_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS ix_pagamentos_fechamento_estornado_forma
    ON public.pagamentos (fechamento_diario_id, estornado, forma_pagamento_id);

CREATE INDEX IF NOT EXISTS ix_pagamentos_lancamento_estornado
    ON public.pagamentos (lancamento_diario_id, estornado)
    WHERE lancamento_diario_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS ix_despesas_caixa_data
    ON public.despesas_caixa (data_hora_despesa);

CREATE INDEX IF NOT EXISTS ix_movimentacoes_fechamento_tipo_operacao
    ON public.movimentacoes_cliente (fechamento_diario_id, tipo_saldo, operacao);

CREATE INDEX IF NOT EXISTS ix_movimentacoes_lancamento_tipo_operacao
    ON public.movimentacoes_cliente (lancamento_diario_id, tipo_saldo, operacao)
    WHERE lancamento_diario_id IS NOT NULL;

-- Atualiza estatísticas para o planejador usar os índices imediatamente.
ANALYZE public.fechamentos_diarios;
ANALYZE public.agendamentos;
ANALYZE public.agendamento_itens;
ANALYZE public.pets;
ANALYZE public.clientes;
ANALYZE public.lancamentos_diarios;
ANALYZE public.lancamento_adicionais;
ANALYZE public.pagamentos;
ANALYZE public.despesas_caixa;
ANALYZE public.movimentacoes_cliente;
ANALYZE public.pet_pacotes;

-- Conferência dos índices criados.
SELECT
    tablename,
    indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname IN (
      'ix_fechamentos_diarios_data',
      'ix_agendamento_itens_agendamento',
      'ix_agendamentos_status_data',
      'ix_agendamentos_mobile_solicitados',
      'ix_pets_ativos_nome',
      'ix_clientes_ativos_nome',
      'ix_lancamentos_pet_fechamento',
      'ix_lancamentos_fechamento_pet',
      'ix_lancamentos_pacote_creditos',
      'ix_pagamentos_fechamento_estornado_forma',
      'ix_pagamentos_lancamento_estornado',
      'ix_despesas_caixa_data',
      'ix_movimentacoes_fechamento_tipo_operacao',
      'ix_movimentacoes_lancamento_tipo_operacao'
  )
ORDER BY tablename, indexname;
