<?php

declare(strict_types=1);

/**
 * Carrega variáveis de um arquivo .env quando ele existir.
 *
 * No ambiente local (XAMPP), o arquivo config/.env pode ser usado normalmente.
 * No Render, o arquivo físico não é necessário: as variáveis são cadastradas
 * no painel do serviço e ficam disponíveis por getenv().
 */
function carregarEnv(string $arquivo): void
{
    // Em produção o .env pode não existir; nesse caso usamos as variáveis
    // fornecidas pelo próprio servidor (por exemplo, pelo Render).
    if (!is_file($arquivo)) {
        return;
    }

    $linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($linhas === false) {
        return;
    }

    foreach ($linhas as $linha) {
        $linha = trim($linha);

        // Ignora linhas vazias e comentários.
        if ($linha === '' || str_starts_with($linha, '#')) {
            continue;
        }

        // Ignora linhas que não seguem o formato NOME=VALOR.
        if (!str_contains($linha, '=')) {
            continue;
        }

        [$nome, $valor] = explode('=', $linha, 2);

        $nome = trim($nome);
        $valor = trim($valor);

        if ($nome === '') {
            continue;
        }

        // Variáveis definidas pelo servidor têm prioridade sobre o .env local.
        $valorDoAmbiente = getenv($nome);
        if ($valorDoAmbiente !== false && $valorDoAmbiente !== '') {
            $_ENV[$nome] = $valorDoAmbiente;
            $_SERVER[$nome] = $valorDoAmbiente;
            continue;
        }

        // Permite valores entre aspas simples ou duplas no arquivo .env.
        if (strlen($valor) >= 2) {
            $primeiro = $valor[0];
            $ultimo = $valor[strlen($valor) - 1];

            if (($primeiro === '"' && $ultimo === '"') || ($primeiro === "'" && $ultimo === "'")) {
                $valor = substr($valor, 1, -1);
            }
        }

        $_ENV[$nome] = $valor;
        $_SERVER[$nome] = $valor;
        putenv($nome . '=' . $valor);
    }
}
