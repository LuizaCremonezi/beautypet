<?php
/**
 * ARQUIVO: ficha-pet.php
 * RESPONSABILIDADE: Ficha detalhada do pet.
 * Carrega dados cadastrais, tutor, pacotes e histórico de serviços/financeiro com paginação.
 */
require __DIR__ . '/../config/config.php';

// Converte o identificador recebido para uso seguro nas consultas e alterações.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: fichas-pets.php?nao_encontrado=1');
    exit;
}

// Consulta otimizada do pet: saldo do tutor e gasto do pet são calculados
// somente para os IDs envolvidos nesta ficha.
$sqlPet = <<<'SQL'
    SELECT
        p.*,
        c.nome AS tutor,
        c.id AS tutor_id,
        e.nome AS especie,
        r.nome AS raca,
        COALESCE(s.saldo_credito, 0.00) AS saldo_credito_tutor,
        COALESCE(s.saldo_debito, 0.00)  AS saldo_debito_tutor,
        COALESCE(g.total_gasto, 0.00)   AS total_gasto
    FROM pets p
    JOIN clientes c ON c.id = p.cliente_id
    JOIN especies e ON e.id = p.especie_id
    LEFT JOIN racas r ON r.id = p.raca_id

    LEFT JOIN LATERAL (
        SELECT
            COALESCE(SUM(
                CASE
                    WHEN m.tipo_saldo = 'CREDITO' AND m.operacao = 'ENTRADA' THEN m.valor
                    WHEN m.tipo_saldo = 'CREDITO' AND m.operacao = 'SAIDA' THEN -m.valor
                    ELSE 0
                END
            ), 0.00) AS saldo_credito,
            COALESCE(SUM(
                CASE
                    WHEN m.tipo_saldo = 'DEBITO' AND m.operacao = 'ENTRADA' THEN m.valor
                    WHEN m.tipo_saldo = 'DEBITO' AND m.operacao = 'SAIDA' THEN -m.valor
                    ELSE 0
                END
            ), 0.00) AS saldo_debito
        FROM movimentacoes_cliente m
        WHERE m.cliente_id = c.id
    ) s ON TRUE

    LEFT JOIN LATERAL (
        SELECT COALESCE(SUM(
            l.valor_servico
            + COALESCE((
                SELECT SUM(la.valor)
                FROM lancamento_adicionais la
                WHERE la.lancamento_diario_id = l.id
            ), 0)
        ), 0) AS total_gasto
        FROM lancamentos_diarios l
        WHERE l.pet_id = p.id
    ) g ON TRUE

    WHERE p.id = :id
      AND p.ativo = TRUE
SQL;

$stmt = $pdo->prepare($sqlPet);
$stmt->execute([':id' => $id]);
$pet = $stmt->fetch();

if (!$pet) {
    header('Location: fichas-pets.php?nao_encontrado=1');
    exit;
}

// Carrega somente os pacotes deste pet e calcula os créditos utilizados diretamente.
$sqlPacotes = <<<'SQL'
    SELECT
        pp.id AS pet_pacote_id,
        pa.nome AS pacote,
        pp.data_compra,
        pp.data_validade,
        pp.quantidade_creditos AS creditos_totais,
        COALESCE(SUM(l.creditos_pacote_usados), 0) AS creditos_utilizados,
        pp.quantidade_creditos - COALESCE(SUM(l.creditos_pacote_usados), 0) AS creditos_restantes,
        pp.status
    FROM pet_pacotes pp
    JOIN pacotes pa ON pa.id = pp.pacote_id
    LEFT JOIN lancamentos_diarios l ON l.pet_pacote_id = pp.id
    WHERE pp.pet_id = :pet_id
      AND pp.status = 'ATIVO'
    GROUP BY
        pp.id,
        pa.nome,
        pp.data_compra,
        pp.data_validade,
        pp.quantidade_creditos,
        pp.status
    HAVING pp.quantidade_creditos - COALESCE(SUM(l.creditos_pacote_usados), 0) > 0
    ORDER BY pp.data_compra DESC
SQL;

$stmt = $pdo->prepare($sqlPacotes);
$stmt->execute([':pet_id' => $id]);
$pacotes = $stmt->fetchAll();

// Soma os créditos ainda disponíveis nos pacotes ativos do pet.
$totalCreditosRestantes = array_sum(
    array_map(
        static fn(array $pacote): int => (int) $pacote['creditos_restantes'],
        $pacotes
    )
);

// --- CONFIGURAÇÃO DA PAGINAÇÃO DO HISTÓRICO (25 itens por página) ---
$itensHistoricoPorPagina = 25;
$paginaHistoricoAtual = max(1, (int) ($_GET['pagina_historico'] ?? 1));
$offsetHistorico = ($paginaHistoricoAtual - 1) * $itensHistoricoPorPagina;

// Conta o total de registros no histórico deste pet para calcular as páginas.
$stmtCountHist = $pdo->prepare('SELECT COUNT(*) FROM lancamentos_diarios WHERE pet_id = :pet_id');
$stmtCountHist->execute([':pet_id' => $id]);
$totalRegistrosHistorico = (int) $stmtCountHist->fetchColumn();
$totalPaginasHistorico = max(1, ceil($totalRegistrosHistorico / $itensHistoricoPorPagina));

if ($paginaHistoricoAtual > $totalPaginasHistorico) {
    $paginaHistoricoAtual = $totalPaginasHistorico;
    $offsetHistorico = ($paginaHistoricoAtual - 1) * $itensHistoricoPorPagina;
}

// Consulta o histórico do pet paginado, agregando pagamentos e movimentos
// somente para os lançamentos que realmente aparecem nesta página.
$sqlHistorico = <<<'SQL'
    SELECT
        p.id AS pet_id,
        p.nome AS pet,
        c.id AS cliente_id,
        c.nome AS cliente,
        f.data_fechamento,
        l.id AS lancamento_diario_id,
        l.atendimento_id,
        s.id AS servico_id,
        s.nome AS servico,
        l.descricao,
        l.valor_servico,
        COALESCE(ad.total_adicionais, 0) AS total_adicionais,
        l.valor_servico + COALESCE(ad.total_adicionais, 0) AS valor_total,
        COALESCE(
            NULLIF(CONCAT_WS(' + ', pg.formas, mov.movimentos), ''),
            'Não informado'
        ) AS forma_pagamento
    FROM lancamentos_diarios l
    JOIN pets p ON p.id = l.pet_id
    JOIN clientes c ON c.id = p.cliente_id
    JOIN fechamentos_diarios f ON f.id = l.fechamento_diario_id
    JOIN servicos s ON s.id = l.servico_id

    LEFT JOIN LATERAL (
        SELECT COALESCE(SUM(la.valor), 0) AS total_adicionais
        FROM lancamento_adicionais la
        WHERE la.lancamento_diario_id = l.id
    ) ad ON TRUE

    LEFT JOIN LATERAL (
        SELECT STRING_AGG(DISTINCT fp.nome, ' + ' ORDER BY fp.nome) AS formas
        FROM pagamentos pag
        JOIN formas_pagamento fp ON fp.id = pag.forma_pagamento_id
        WHERE pag.estornado = FALSE
          AND pag.lancamento_diario_id = l.id
    ) pg ON TRUE

    LEFT JOIN LATERAL (
        SELECT STRING_AGG(
            DISTINCT CASE
                WHEN m.tipo_saldo = 'CREDITO' AND m.operacao = 'SAIDA' THEN 'Crédito utilizado'
                WHEN m.tipo_saldo = 'CREDITO' AND m.operacao = 'ENTRADA' THEN 'Crédito gerado'
                WHEN m.tipo_saldo = 'DEBITO' AND m.operacao = 'ENTRADA' THEN 'Débito pendente'
                WHEN m.tipo_saldo = 'DEBITO' AND m.operacao = 'SAIDA' THEN 'Débito pago'
            END,
            ' + '
        ) AS movimentos
        FROM movimentacoes_cliente m
        WHERE m.lancamento_diario_id = l.id
    ) mov ON TRUE

    WHERE l.pet_id = :pet_id
    ORDER BY f.data_fechamento DESC, l.id DESC
    LIMIT __LIMIT__ OFFSET __OFFSET__
SQL;

$sqlHistorico = str_replace(
    ['__LIMIT__', '__OFFSET__'],
    [(string) $itensHistoricoPorPagina, (string) $offsetHistorico],
    $sqlHistorico
);

$stmt = $pdo->prepare($sqlHistorico);
$stmt->execute([':pet_id' => $id]);
$historico = $stmt->fetchAll();

// Define o título e os metadados da página.
$tituloPagina = 'Ficha de ' . $pet['nome'];
$paginaAtiva = 'fichas-pets';
$cssPagina = 'ficha';
require __DIR__ . '/../includes/topo.php';
?>

<div class="page records-page">
    <a class="back-link" href="fichas-pets.php">
        <i class="fa-solid fa-arrow-left"></i>
        Voltar para fichas dos pets
    </a>

    <?php if (isset($_GET['editado'])): ?>
        <div class="message success detail-edit-message">Dados atualizados com sucesso!</div>
    <?php endif; ?>

    <!-- Seção de topo com identidade, resumos do pet e botão de edição alinhado à direita -->
    <section class="detail-card pet-profile-top">
        <div class="pet-profile-identity">
            <?php $fotoPet = urlFotoPet($pet['foto_url'] ?? null); ?>
            <?php if ($fotoPet): ?>
                <img src="<?= escapar($fotoPet) ?>" alt="Foto de <?= escapar($pet['nome']) ?>">
            <?php else: ?>
                <div class="pet-photo-fallback large">
                    <i class="fa-solid fa-paw"></i>
                </div>
            <?php endif; ?>

            <div>
                <h1><?= escapar($pet['nome']) ?></h1>
                <strong><?= escapar($pet['raca'] ?: $pet['especie']) ?></strong>
                <a href="ficha-tutor.php?id=<?= (int) $pet['tutor_id'] ?>">
                    <?= escapar($pet['tutor']) ?>
                </a>
            </div>
        </div>

       <div class="pet-summary-column">
        <div class="pet-summary-grid">
            <article class="daily-summary-card emphasis">
                <span>Total comercializado</span>
                <strong><?= escapar(formatarMoeda($pet['total_gasto'])) ?></strong>
                <small>Serviços + adicionais</small>
            </article>

            <article class="daily-summary-card green-soft">
                <span>Crédito do tutor</span>
                <strong><?= escapar(formatarMoeda($pet['saldo_credito_tutor'])) ?></strong>
                <small>Saldo disponível</small>
            </article>

            <article class="daily-summary-card danger-soft">
                <span>Débito do tutor</span>
                <strong>
                    <?= (float) $pet['saldo_debito_tutor'] > 0
                        ? escapar(formatarMoeda($pet['saldo_debito_tutor']))
                        : 'Nenhum' ?>
                </strong>
                <small>Valor pendente</small>
            </article>

            <article class="daily-summary-card">
                <span>Créditos de pacote</span>
                <strong><?= $totalCreditosRestantes ?></strong>
                <small>Ainda disponíveis</small>
            </article>
        </div>

        <!-- Botão de edição reposicionado para ficar logo abaixo e alinhado à direita dos cards -->
        <div class="pet-action-wrapper">
            <a class="detail-edit-button" href="cadastro-pet.php?editar=<?= (int) $id ?>">
                Editar pet
            </a>
        </div>
    </div>
</section>

    <!-- Colunas de detalhes do pet -->
    <div class="pet-detail-columns">
        <section class="detail-card section-card">
            <h2>Dados do pet</h2>

            <div class="data-grid">
                <div>
                    <small>Nome</small>
                    <strong><?= escapar($pet['nome']) ?></strong>
                </div>

                <div>
                    <small>Espécie</small>
                    <strong><?= escapar($pet['especie']) ?></strong>
                </div>

                <div>
                    <small>Raça</small>
                    <strong><?= escapar($pet['raca'] ?: 'Não informada') ?></strong>
                </div>

                <div>
                    <small>Sexo</small>
                    <strong><?= escapar(nomeSexo($pet['sexo'])) ?></strong>
                </div>

                <div>
                    <small>Idade</small>
                    <strong>
                        <?= escapar(
                            idadePet(
                                $pet['data_nascimento'],
                                (bool) $pet['nascimento_aproximado']
                            )
                        ) ?>
                    </strong>
                </div>

                <div>
                    <small>Porte</small>
                    <strong><?= escapar(nomePorte($pet['porte'])) ?></strong>
                </div>

                <div>
                    <small>Peso</small>
                    <strong>
                        <?= $pet['peso'] !== null
                            ? escapar(number_format((float) $pet['peso'], 2, ',', '.') . ' kg')
                            : 'Não informado' ?>
                    </strong>
                </div>

                <div>
                    <small>Cor</small>
                    <strong><?= escapar($pet['cor'] ?: 'Não informada') ?></strong>
                </div>

                <div>
                    <small>Castrado(a)</small>
                    <strong><?= escapar(textoBooleano($pet['castrado'])) ?></strong>
                </div>

                <div>
                    <small>Vacinação em dia</small>
                    <strong><?= escapar(textoBooleano($pet['vacinacao_em_dia'])) ?></strong>
                </div>

                <div class="wide">
                    <small>Data de nascimento</small>
                    <strong><?= escapar(formatarData($pet['data_nascimento'])) ?></strong>
                </div>
            </div>
        </section>

        <section class="detail-card section-card">
            <h2>Comportamento e saúde</h2>

            <div class="text-information">
                <div>
                    <small>Comportamento</small>
                    <p>
                        <?= nl2br(
                            escapar(
                                $pet['observacoes_comportamentais']
                                ?: 'Nenhuma observação comportamental cadastrada.'
                            )
                        ) ?>
                    </p>
                </div>

                <div>
                    <small>Medicamentos</small>
                    <p>
                        <?= nl2br(
                            escapar(
                                $pet['medicamentos']
                                ?: 'Nenhum medicamento cadastrado.'
                            )
                        ) ?>
                    </p>
                </div>

                <div>
                    <small>Observações médicas</small>
                    <p>
                        <?= nl2br(
                            escapar(
                                $pet['observacoes_medicas']
                                ?: 'Nenhuma observação médica cadastrada.'
                            )
                        ) ?>
                    </p>
                </div>
            </div>
        </section>
    </div>

    <!-- Seção de pacotes ativos -->
    <section class="detail-card section-card">
        <div class="section-title">
            <h2>Pacotes ativos</h2>
            <small>Somente pacotes com créditos restantes.</small>
        </div>

        <?php if (!$pacotes): ?>
            <div class="inline-empty">
                Este pet não possui pacote ativo. Os atendimentos avulsos continuam aparecendo normalmente no histórico.
            </div>
        <?php else: ?>
            <div class="package-grid">
                <?php foreach ($pacotes as $pacote): ?>
                    <article class="package-card">
                        <strong><?= escapar($pacote['pacote']) ?></strong>

                        <div>
                            <span>
                                <?= (int) $pacote['creditos_utilizados'] ?>
                                usado(s)
                            </span>
                            <span>
                                <?= (int) $pacote['creditos_restantes'] ?>
                                restante(s)
                            </span>
                        </div>

                        <small>
                            Compra: <?= escapar(formatarData($pacote['data_compra'])) ?>

                            <?php if (!empty($pacote['data_validade'])): ?>
                                • Validade: <?= escapar(formatarData($pacote['data_validade'])) ?>
                            <?php endif; ?>
                        </small>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Seção de histórico financeiro em formato de tabela idêntico ao tutor -->
    <section class="detail-card history-card">
        <div class="section-title">
            <h2>Histórico de atendimentos</h2>
            <small>Valores inseridos nos lançamentos do fechamento diário.</small>
        </div>

        <?php if (!$historico): ?>
            <div class="inline-empty">
                Ainda não existem atendimentos lançados para este pet.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="records-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Pet</th>
                            <th>Serviço</th>
                            <th>Valor</th>
                            <th>Forma de pagamento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historico as $item): ?>
                            <tr>
                                <td>
                                    <?= escapar(formatarData($item['data_fechamento'])) ?>
                                </td>
                                <td>
                                    <?= escapar($item['pet']) ?>
                                </td>
                                <td>
                                    <strong><?= escapar($item['servico']) ?></strong>
                                    <?php if (!empty($item['descricao'])): ?>
                                        <small><?= escapar($item['descricao']) ?></small>
                                    <?php endif; ?>
                                    <?php if ((float) $item['total_adicionais'] > 0): ?>
                                        <small>
                                            Inclui <?= escapar(formatarMoeda($item['total_adicionais'])) ?>
                                            em adicional(is)
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td class="money">
                                    <?= escapar(formatarMoeda($item['valor_total'])) ?>
                                </td>
                                <td>
                                    <?= escapar($item['forma_pagamento']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Barra de paginação numérica do histórico -->
            <?php if ($totalPaginasHistorico > 1): ?>
                <div class="pagination-bar">
                    <?php if ($paginaHistoricoAtual > 1): ?>
                        <a href="ficha-pet.php?id=<?= $id ?>&pagina_historico=<?= $paginaHistoricoAtual - 1 ?>" class="pagination-button">
                            <i class="fa-solid fa-chevron-left"></i> Anterior
                        </a>
                    <?php else: ?>
                        <span class="pagination-button disabled"><i class="fa-solid fa-chevron-left"></i> Anterior</span>
                    <?php endif; ?>

                    <div class="pagination-numbers">
                        <?php for ($i = 1; $i <= $totalPaginasHistorico; $i++): ?>
                            <?php if ($i == 1 || $i == $totalPaginasHistorico || ($i >= $paginaHistoricoAtual - 2 && $i <= $paginaHistoricoAtual + 2)): ?>
                                <a href="ficha-pet.php?id=<?= $id ?>&pagina_historico=<?= $i ?>" class="pagination-num <?= $i === $paginaHistoricoAtual ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php elseif ($i == $paginaHistoricoAtual - 3 || $i == $paginaHistoricoAtual + 3): ?>
                                <span class="pagination-dots">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>

                    <?php if ($paginaHistoricoAtual < $totalPaginasHistorico): ?>
                        <a href="ficha-pet.php?id=<?= $id ?>&pagina_historico=<?= $paginaHistoricoAtual + 1 ?>" class="pagination-button">
                            Seguinte <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-button disabled">Seguinte <i class="fa-solid fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../includes/rodape.php'; ?>