# Beauty Pet Web — otimização de desempenho

Esta versão mantém o visual e as regras do sistema, mas reduz consultas desnecessárias ao Supabase.

## O que foi otimizado

- Fichas de pets: limita os pets antes de calcular gastos e pacotes.
- Fichas de tutores: limita os tutores antes de calcular pets, gastos e saldos.
- Dashboard: evita repetir a agregação de pets sem retorno e reduz consultas por data.
- Atendimentos do Dashboard: agrega serviços/financeiro apenas dos agendamentos exibidos.
- Agenda: usa intervalo de timestamp em vez de converter a coluna para `::date`.
- Fechamento mensal: calcula somente os dias do mês selecionado, sem consolidar a base inteira primeiro.
- Fechamento diário: pagamentos, adicionais e saldos são agregados apenas para o dia/lancamento atual.
- Cabeçalho: usuário/lista de usuários têm cache curto de 60 s; o sino de solicitações, 10 s.

## No Supabase

Execute primeiro:

`sql/06_otimizacao_indices_supabase.sql`

Opcionalmente, para acelerar bastante buscas contendo texto, execute também:

`sql/07_buscas_texto_rapidas_opcional.sql`

## Deploy

Substitua a pasta `beautypet-web` do repositório por esta versão, faça commit/push e no Render use `Deploy latest commit`.

A `DATABASE_URL` continua sendo configurada no ambiente do Render; não coloque `.env` real no GitHub.
