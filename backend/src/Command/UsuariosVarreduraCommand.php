<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\PermissoesService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\I18n\DateTime;

/**
 * Cria (ou acerta de novo) as duas contas que a varredura do front usa
 * (`npm run varredura` em front-acamp):
 *
 *   php bin/cake.php usuarios_varredura
 *
 * - "TESTE CLAUDE Varredura": todas as permissões do painel;
 * - "TESTE CLAUDE Varredura Limitado": só Categorias, para conferir que quem
 *   tem acesso limitado não vê as outras telas.
 *
 * As senhas são fixas e conhecidas (VARREDURA_SENHA), então são contas só para
 * o computador de desenvolvimento. Pode rodar quantas vezes quiser: volta a
 * senha, tira a exclusão e regrava as permissões.
 */
class UsuariosVarreduraCommand extends Command
{
    public const SENHA = 'varredura123';

    /** CPF (base de 9 números; os verificadores são calculados) => [nome, permissões; null = todas]. */
    private const CONTAS = [
        '900000001' => ['TESTE CLAUDE Varredura', null],
        '900000002' => ['TESTE CLAUDE Varredura Limitado', ['categorias']],
    ];

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'usuarios_varredura';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Cria ou acerta as contas "TESTE CLAUDE Varredura" usadas pela varredura do front (npm run varredura).';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)->setDescription(static::getDescription());
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $usuarios = $this->fetchTable('Users');
        $solteiro = $this->fetchTable('MaritalStatuses')->find()->where(['title' => 'Solteiro'])->first();
        if ($solteiro === null) {
            $io->error('Estado civil "Solteiro" não encontrado. Rode os seeds antes.');

            return static::CODE_ERROR;
        }
        $permissoes = new PermissoesService();

        foreach (self::CONTAS as $base => [$nome, $chaves]) {
            $cpf = self::comVerificadores((string)$base);
            $user = $usuarios->find()->where(['cpf' => $cpf])->first()
                ?? $usuarios->newEmptyEntity();
            $user = $usuarios->patchEntity($user, [
                'name' => $nome,
                'cpf' => $cpf,
                'password' => self::SENHA,
                'email' => "varredura{$base}@exemplo.invalid",
                'phone' => '66999990000',
                'birthday' => '1990-01-01',
                'sex' => 'M',
                'marital_status_id' => $solteiro->id,
            ]);
            // Fora do patch: não são campos que o cadastro aceita.
            $user->set('deleted', null);
            $user->set('must_change_password', false);
            $user->set('temporary_password_at', null);
            $user->set('modified', DateTime::now());
            $usuarios->saveOrFail($user);

            $gravadas = $permissoes->definir((int)$user->id, $chaves ?? PermissoesService::todas());
            $io->out(sprintf(
                '<success>%s</success>  CPF %s  senha %s  (%d permissões)',
                $nome,
                $cpf,
                self::SENHA,
                count($gravadas),
            ));
        }

        return static::CODE_SUCCESS;
    }

    /** Os 9 números + os 2 dígitos verificadores do CPF. */
    public static function comVerificadores(string $base): string
    {
        foreach ([10, 11] as $peso) {
            $soma = 0;
            for ($i = 0; $i < $peso - 1; $i++) {
                $soma += (int)$base[$i] * ($peso - $i);
            }
            $base .= (string)(($soma * 10) % 11 % 10);
        }

        return $base;
    }
}
