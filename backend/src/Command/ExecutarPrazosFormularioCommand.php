<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\PrazoFormularioService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Aplica os prazos do acampamento: tira a vaga de quem foi selecionado e não
 * enviou o formulário até a data-limite, ou enviou mas não pagou até a data de
 * pagamento. A vaga fica aberta até um administrador ou conselheiro chamar o
 * próximo suplente pela tela do sorteio.
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
        return 'Tira a vaga de quem perdeu o prazo do formulário ou do pagamento (o próximo suplente é chamado pela tela do sorteio).';
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
            $io->out('Nenhum prazo de formulário ou pagamento vencido no momento.');

            return static::CODE_SUCCESS;
        }

        foreach ($log as $linha) {
            $io->out("<success>{$linha}</success>");
        }

        return static::CODE_SUCCESS;
    }
}
