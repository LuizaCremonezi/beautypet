<?php
/**
 * ARQUIVO: solicitacoes-agendamento.php
 * RESPONSABILIDADE: Gerenciamento de solicitações de agendamento.
 * Lista solicitações recebidas, permite confirmar ou recusar pedidos pendentes e cancelar pedidos confirmados.
 *
 * Os comentários abaixo descrevem o motivo de cada etapa, os dados manipulados
 * e a relação entre interface, regras de negócio e banco de dados.
 */
require __DIR__ . '/../config/config.php';
// Armazena em "usuarioAtualSolicitacao" o valor necessário para as próximas etapas deste fluxo.
$usuarioAtualSolicitacao = obterUsuarioAtual($pdo);
// Guarda somente o ID do usuário atual para registrar autoria das operações no banco.
$usuarioId = isset($usuarioAtualSolicitacao['id'])
    ? (int) $usuarioAtualSolicitacao['id']
    : null;

// Inicializa a mensagem de retorno que será mostrada ao usuário após validações ou operações.
$mensagem = '';
// Define a classe visual da mensagem de retorno, normalmente sucesso ou erro.
$tipoMensagem = '';
// Armazena em "aba" o valor necessário para as próximas etapas deste fluxo.
$aba = (string) ($_GET['aba'] ?? 'pendentes');

// Verifica a condição abaixo antes de executar este bloco.
if (!in_array($aba, ['pendentes', 'confirmadas'], true)) {
    // Armazena em "aba" o valor necessário para as próximas etapas deste fluxo.
    $aba = 'pendentes';
}

// Executa o processamento abaixo somente quando algum formulário desta página foi enviado.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Identifica qual ação o formulário solicitou para direcionar o processamento correto.
    $acao = (string) ($_POST['acao'] ?? '');
    // Guarda o ID do agendamento criado ou selecionado para edição, status ou exclusão.
    $agendamentoId = inteiroOuNull($_POST['agendamento_id'] ?? null);

    // HEURÍSTICA 9: antes não havia try/catch nesta página; uma falha do banco virava erro fatal.
    try {
        // Valida a existência do identificador antes de alterar ou excluir um agendamento.
        if ($agendamentoId === null) {
            // Inicializa a mensagem de retorno que será mostrada ao usuário após validações ou operações.
            $mensagem = 'Não foi possível identificar a solicitação. Atualize a página e tente novamente.';
            // Define a classe visual da mensagem de retorno, normalmente sucesso ou erro.
            $tipoMensagem = 'error';
        // Caso a condição anterior não tenha sido atendida, testa esta alternativa.
        } elseif (in_array($acao, ['confirmar', 'recusar', 'cancelar'], true)) {
            // Guarda o status solicitado e permite validar se a transição é aceita antes de atualizar o banco.
            $novoStatus = match ($acao) {
                'confirmar' => 'CONFIRMADO',
                'recusar'   => 'RECUSADO',
                'cancelar'  => 'CANCELADO',
            };

            // Prepara a atualização do registro correspondente em "agendamentos" com os novos dados.
            $stmt = $pdo->prepare(
                "UPDATE agendamentos
                 SET status = :status,
                     respondido_por = :usuario_id,
                     respondido_em = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND origem = 'MOBILE'"
            );

            // Executa a instrução preparada enviando os parâmetros de forma separada e segura.
            $stmt->execute([
                ':status'     => $novoStatus,
                ':usuario_id' => $usuarioId,
                ':id'         => $agendamentoId,
            ]);

            // HEURÍSTICA 9: avisa se a solicitação não existe mais (ex.: removida pelo cliente no app).
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Essa solicitação não foi encontrada. Ela pode ter sido removida; atualize a página.');
            }

            // Armazena em "abaRetorno" o valor necessário para as próximas etapas deste fluxo.
            $abaRetorno = $acao === 'cancelar'
                ? 'confirmadas'
                : 'pendentes';

            // Redireciona o navegador após a operação para evitar reenvio do formulário e atualizar a tela.
            header(
                'Location: solicitacoes-agendamento.php?aba='
                . $abaRetorno
                . '&atualizado=1'
            );
            // Encerra a execução desta requisição após o redirecionamento ou resposta enviada.
            exit;
        }
    } catch (Throwable $erro) {
        if (!erroDeRegra($erro)) {
            error_log('[BeautyPet] solicitacoes: ' . $erro->getMessage());
        }
        $mensagem = erroDeRegra($erro)
            ? $erro->getMessage()
            : 'Não foi possível atualizar a solicitação agora. Nada foi alterado: confira a internet e tente novamente.';
        $tipoMensagem = 'error';
    }
}

// Trata os parâmetros da URL usados principalmente para exibir mensagens após redirecionamentos.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['atualizado'])) {
    // Inicializa a mensagem de retorno que será mostrada ao usuário após validações ou operações.
    $mensagem = 'Solicitação atualizada com sucesso.';
    // Define a classe visual da mensagem de retorno, normalmente sucesso ou erro.
    $tipoMensagem = 'success';
}

// Agrupa os totais calculados para exibição no resumo financeiro da página.
$totais = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'SOLICITADO' THEN 1 ELSE 0 END), 0) AS pendentes,
        COALESCE(SUM(CASE WHEN status = 'CONFIRMADO' THEN 1 ELSE 0 END), 0) AS confirmadas
     FROM agendamentos
     WHERE origem = 'MOBILE'"
)->fetch();

// Calcula/armazena o total de "totalPendentes" que será usado nos resumos e na interface.
$totalPendentes = (int) ($totais['pendentes'] ?? 0);
// Calcula/armazena o total de "totalConfirmadas" que será usado nos resumos e na interface.
$totalConfirmadas = (int) ($totais['confirmadas'] ?? 0);

// Armazena em "statusConsulta" o valor necessário para as próximas etapas deste fluxo.
$statusConsulta = $aba === 'pendentes'
    ? 'SOLICITADO'
    : 'CONFIRMADO';

// Prepara uma consulta em "agendamentos" para carregar os dados necessários a esta etapa.
$stmt = $pdo->prepare(
    "SELECT
        a.id,
        a.data_hora_inicio,
        a.data_hora_fim,
        a.status,
        a.observacoes_cliente,
        p.nome AS pet,
        p.foto_url,
        c.nome AS tutor,
        STRING_AGG(DISTINCT s.nome, ', ' ORDER BY s.nome) AS servicos
     FROM agendamentos a
     JOIN pets p
        ON p.id = a.pet_id
     JOIN clientes c
        ON c.id = p.cliente_id
     LEFT JOIN agendamento_itens ai
        ON ai.agendamento_id = a.id
     LEFT JOIN servicos s
        ON s.id = ai.servico_id
     WHERE a.origem = 'MOBILE'
        AND a.status = :status
     GROUP BY
        a.id,
        a.data_hora_inicio,
        a.data_hora_fim,
        a.status,
        a.observacoes_cliente,
        p.nome,
        p.foto_url,
        c.nome
     ORDER BY a.data_hora_inicio"
);
// Executa a instrução preparada enviando os parâmetros de forma separada e segura.
$stmt->execute([':status' => $statusConsulta]);
// Transforma todas as linhas retornadas pela consulta em uma lista para uso na página.
$solicitacoes = $stmt->fetchAll();

// Define o título exibido na aba/navegador pelo template compartilhado.
$tituloPagina = 'Solicitações de agendamento';
// Informa ao menu lateral qual item deve aparecer destacado nesta página.
$paginaAtiva = 'solicitacoes-agendamento';
// Define o(s) CSS desta página em assets/css/ (solicitacoes-agendamento.css); o includes/topo.php monta os <link> correspondentes.
$cssPagina = 'solicitacoes-agendamento';
// Carrega o arquivo compartilhado necessário para usar conexão, funções, layout ou outras dependências desta página.
require __DIR__ . '/../includes/topo.php';
?>

<div class="page requests-page">
    <!-- Bloco visual "page-heading agenda-heading" que organiza esta parte da interface. -->
    <div class="page-heading agenda-heading">
        <div>
            <a class="back-link" href="agenda.php">
                <i class="fa-solid fa-arrow-left"></i>
                Voltar para a agenda
            </a>
            <h1>Solicitações de agendamento</h1>
            <small>Pedidos enviados pelo aplicativo do cliente.</small>
        </div>
    </div>

    <?php if ($mensagem !== ''): ?>
        <div class="message <?= escapar($tipoMensagem) ?>" role="<?= $tipoMensagem === 'error' ? 'alert' : 'status' ?>">
            <?= escapar($mensagem) ?>
        </div>
    <?php endif; ?>

    <!-- Inicia uma seção visual que agrupa informações relacionadas desta página. -->
    <section class="requests-card">
        <nav class="request-tabs">
            <a href="solicitacoes-agendamento.php?aba=pendentes"
                class="<?= $aba === 'pendentes' ? 'active' : '' ?>">
                Solicitações pendentes
                <span><?= $totalPendentes ?></span>
            </a>

            <a href="solicitacoes-agendamento.php?aba=confirmadas"
                class="<?= $aba === 'confirmadas' ? 'active' : '' ?>">
                Solicitações confirmadas
                <span><?= $totalConfirmadas ?></span>
            </a>
        </nav>

        <?php if (!$solicitacoes): ?>
            <div class="agenda-empty">
                Nenhuma solicitação nesta categoria.
            </div>
        <?php else: ?>
            <div class="request-list">
                <?php foreach ($solicitacoes as $item): ?>
                    <article class="request-item">
                        <div class="agenda-pet">
                            <?php $fotoPet = urlFotoPet($item['foto_url'] ?? null); ?>
                            <?php if ($fotoPet): ?>
                                <img src="<?= escapar($fotoPet) ?>"
                                    alt="Foto de <?= escapar($item['pet']) ?>">
                            <?php else: ?>
                                <span class="agenda-pet-fallback">
                                    <i class="fa-solid fa-paw"></i>
                                </span>
                            <?php endif; ?>

                            <div>
                                <strong><?= escapar($item['pet']) ?></strong>
                                <small><?= escapar($item['tutor']) ?></small>
                            </div>
                        </div>

                        <div class="request-info">
                            <small>Data e horário</small>
                            <strong><?= escapar(formatarDataHoraAgenda($item['data_hora_inicio'])) ?></strong>
                        </div>

                        <div class="request-info">
                            <small>Serviço</small>
                            <strong><?= escapar($item['servicos'] ?: 'Não informado') ?></strong>
                        </div>

                        <?php if ($aba === 'pendentes'): ?>
                            <div class="request-actions">
                                <!-- Formulário responsável por enviar os dados desta ação para processamento no PHP. -->
                                <form method="post"
                                    action="solicitacoes-agendamento.php?aba=pendentes">
                                    <!-- Campo "acao" utilizado para enviar esta informação no formulário. -->
                                    <input type="hidden" name="acao" value="confirmar">
                                    <!-- Campo "agendamento_id" utilizado para enviar esta informação no formulário. -->
                                    <input type="hidden" name="agendamento_id"
                                        value="<?= (int) $item['id'] ?>">
                                    <button class="request-button confirm" type="submit">
                                        Confirmar
                                    </button>
                                </form>

                                <!-- Formulário responsável por enviar os dados desta ação para processamento no PHP. -->
                                <form method="post"
                                    action="solicitacoes-agendamento.php?aba=pendentes">
                                    <!-- Campo "acao" utilizado para enviar esta informação no formulário. -->
                                    <input type="hidden" name="acao" value="recusar">
                                    <!-- Campo "agendamento_id" utilizado para enviar esta informação no formulário. -->
                                    <input type="hidden" name="agendamento_id"
                                        value="<?= (int) $item['id'] ?>">
                                    <button class="icon-action-button danger"
                                        type="submit" title="Recusar">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="request-actions">
                                <!-- Formulário responsável por enviar os dados desta ação para processamento no PHP. -->
                                <form method="post"
                                    action="solicitacoes-agendamento.php?aba=confirmadas">
                                    <!-- Campo "acao" utilizado para enviar esta informação no formulário. -->
                                    <input type="hidden" name="acao" value="cancelar">
                                    <!-- Campo "agendamento_id" utilizado para enviar esta informação no formulário. -->
                                    <input type="hidden" name="agendamento_id"
                                        value="<?= (int) $item['id'] ?>">
                                    <button class="icon-action-button danger"
                                        type="submit" title="Cancelar">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../includes/rodape.php'; ?>
