<?php

declare(strict_types=1);

/**
 * Carrega variáveis de um arquivo .env.
 *
 * Em produção, como no Render, o arquivo .env pode não existir,
 * pois as variáveis são configuradas diretamente no ambiente.
 */
function carregarEnv(string $arquivo): void
{
    // No Render, o .env não existe fisicamente.
    // Nesse caso, usamos as variáveis configuradas no painel.
    if (!is_file($arquivo)) {
        return;
    }

    $linhas = file(
        $arquivo,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($linhas === false) {
        return;
    }

    foreach ($linhas as $linha) {
        $linha = trim($linha);

        // Ignora linhas vazias e comentários.
        if ($linha === '' || str_starts_with($linha, '#')) {
            continue;
        }

        // Ignora linhas que não possuem "=".
        if (!str_contains($linha, '=')) {
            continue;
        }

        [$nome, $valor] = explode('=', $linha, 2);

        $nome = trim($nome);
        $valor = trim($valor);

        if ($nome === '') {
            continue;
        }

        // Remove aspas usadas no .env.
        if (
            strlen($valor) >= 2 &&
            (
                ($valor[0] === '"' && $valor[strlen($valor) - 1] === '"') ||
                ($valor[0] === "'" && $valor[strlen($valor) - 1] === "'")
            )
        ) {
            $valor = substr($valor, 1, -1);
        }

        /*
         * Variáveis definidas pelo servidor têm prioridade.
         * Assim, o Render nunca é sobrescrito por um eventual .env.
         */
        $valorExistente = getenv($nome);

        if ($valorExistente !== false && $valorExistente !== '') {
            $_ENV[$nome] = $valorExistente;
            $_SERVER[$nome] = $valorExistente;
            continue;
        }

        $_ENV[$nome] = $valor;
        $_SERVER[$nome] = $valor;
        putenv("{$nome}={$valor}");
    }
}