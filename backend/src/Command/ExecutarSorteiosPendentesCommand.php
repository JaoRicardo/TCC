<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\SorteioService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Roda os sorteios de campistas e servos que já chegaram na data configurada
 * na criação do acampamento e ainda não foram realizados (nem manualmente,
 * nem por uma execução automática anterior) — nunca resorteia por cima de um
 * sorteio que o admin já fez.
 *
 * Pensado para rodar periodicamente via agendador do sistema operacional
 * (Agendador de Tarefas no Windows, cron no Linux), por exemplo a cada 5-15
 * minutos:
 *
 *   php bin/cake executar_sorteios_pendentes
 *
 * O mesmo resultado também é obtido "de graça" sem nenhum agendador: os
 * endpoints públicos mais acessados (listagem de atividades e resultado do
 * sorteio) já chamam essa mesma checagem sozinhos a cada requisição.
 */
class ExecutarSorteiosPendentesCommand extends Command
{
    public static function defaultName(): string
    {
        return 'executar_sorteios_pendentes';
    }

    public static function getDescription(): string
    {
        return 'Executa automaticamente os sorteios de campistas/servos cuja data configurada já chegou.';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);
        $parser->setDescription(static::getDescription());

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $service = new SorteioService();
        $log = $service->executarSorteiosPendentes();

        if (empty($log)) {
            $io->out('Nenhum sorteio pendente no momento.');
            return static::CODE_SUCCESS;
        }

        foreach ($log as $linha) {
            $io->out("<success>{$linha}</success>");
        }

        return static::CODE_SUCCESS;
    }
}
