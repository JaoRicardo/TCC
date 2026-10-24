<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionManager;

/**
 * Garante que uma rotina automática (sorteio, prazos) não rode duas vezes ao
 * mesmo tempo — por exemplo, duas abas abrindo o painel no mesmo segundo.
 *
 * Usa a trava nomeada do MySQL (GET_LOCK). Quem não consegue a trava na hora
 * não espera: só pula, porque a outra execução já está fazendo o mesmo trabalho.
 * O nome leva o banco atual, para o banco de testes não travar o de verdade.
 */
class TravaExecucao
{
    /**
     * @template T
     * @param callable(): T $rotina
     * @param T $seOcupada Valor devolvido quando outra execução já está rodando.
     * @return T
     */
    public static function executar(string $nome, callable $rotina, mixed $seOcupada = null): mixed
    {
        $conexao = ConnectionManager::get('default');
        $trava = $conexao->execute('SELECT CONCAT(DATABASE(), ?) AS nome', [":{$nome}"])->fetch('assoc')['nome'];

        $obteve = (int)$conexao->execute('SELECT GET_LOCK(?, 0) AS ok', [$trava])->fetch('assoc')['ok'];
        if ($obteve !== 1) {
            return $seOcupada;
        }

        try {
            return $rotina();
        } finally {
            $conexao->execute('SELECT RELEASE_LOCK(?)', [$trava]);
        }
    }
}
