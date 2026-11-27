<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Casal no acampamento de Casais (decidido com o usuário em 2026-11-26):
 * quem se inscreve informa o CPF do cônjuge e o sistema inscreve os dois. A
 * inscrição só vale depois de o cônjuge confirmar que está ciente, numa janela
 * que aparece para ele em qualquer tela. Até o sorteio ser feito dá para
 * confirmar; casal não confirmado não concorre. Se o cônjuge disser que não
 * está ciente, as duas inscrições são canceladas e quem inscreveu é avisado.
 *
 * - awaiting_spouse: nas duas inscrições, enquanto o cônjuge não confirma.
 * - added_by_spouse: a inscrição foi feita pelo cônjuge (é quem confirma).
 * - spouse_confirmed_at: quando o cônjuge confirmou.
 * - spouse_refused_at / refusal_seen_at: na inscrição de quem inscreveu, quando
 *   o cônjuge recusou e quando a pessoa viu o aviso da recusa.
 */
class AddConfirmacaoDoConjuge extends BaseMigration
{
    public function change(): void
    {
        $this->table('pre_registrations')
            ->addColumn('awaiting_spouse', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('added_by_spouse', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('spouse_confirmed_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('spouse_refused_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('refusal_seen_at', 'datetime', ['null' => true, 'default' => null])
            ->update();
    }
}
