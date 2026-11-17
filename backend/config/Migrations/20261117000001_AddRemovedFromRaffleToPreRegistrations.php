<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * "Retirado do sorteio": a organização corrigiu na ficha da pessoa a data de
 * nascimento, o sexo ou a cidade, e com os dados novos a inscrição que ainda
 * aguardava sorteio deixou de poder concorrer (ver RetiradaDoSorteioService).
 *
 * - removed_from_raffle_at: quando saiu. Com ele preenchido, a inscrição não
 *   entra mais no sorteio de campistas nem no de servos.
 * - removed_from_raffle_reason: idade, sexo ou cidade.
 */
class AddRemovedFromRaffleToPreRegistrations extends BaseMigration
{
    public function change(): void
    {
        $this->table('pre_registrations')
            ->addColumn('removed_from_raffle_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'disqualification_reason'])
            ->addColumn('removed_from_raffle_reason', 'string', ['limit' => 20, 'null' => true, 'default' => null, 'after' => 'removed_from_raffle_at'])
            ->update();
    }
}
