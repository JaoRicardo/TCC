<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\PrazoFormularioService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Aplica o prazo do formulário: desclassifica quem foi selecionado e não enviou o
 * formulário até a data-limite cadastrada no acampamento, e promove o primeiro
 * suplente da fila para cada vaga liberada.
 *
 * Pensado para rodar periodicamente via agendador do sistema operacional
 * (Agendador de Tarefas no Windows, cron no Linux):
 *
 *   php bin/cake executar_prazos_formulario
 *
 * Igual ao sorteio automático, também roda "de graça" sem agendador nenhum: os
 * endpoints mais acessados (listagem de atividades, resultado do sorteio e
 * "minhas inscrições") chamam a mesma checagem a cada requisição.
 */
class ExecutarPrazosFormularioCommand extends Command
{
    public static function defaultName(): string
    {
        return 'executar_prazos_formulario';
    }

    public static function getDescription(): string
    {
        return 'Desclassifica quem perdeu o prazo do formulário e chama os suplentes das vagas liberadas.';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);
        $parser->setDescription(static::getDescription());

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $service = new PrazoFormularioService();
        $log = $service->executarPrazosVencidos();

        if (empty($log)) {
            $io->out('Nenhum prazo de formulário vencido no momento.');

            return static::CODE_SUCCESS;
        }

        foreach ($log as $linha) {
            $io->out("<success>{$linha}</success>");
        }

        return static::CODE_SUCCESS;
    }
}
