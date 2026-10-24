<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Marca quando o sorteio automático de cada acampamento já rodou.
 *
 * Antes, o sistema decidia se o sorteio "já tinha sido feito" olhando se havia
 * alguém sorteado. Num acampamento sem ninguém para sortear (sem inscritos, sem
 * setores), ele sorteava de novo a cada visita ao site; e resetar um sorteio
 * depois da data fazia ele disparar sozinho outra vez.
 *
 * - `camper_raffle_auto_ran_at` / `servant_raffle_auto_ran_at`: quando o
 *   sistema tratou a data do sorteio de campistas / servos. Se a data do sorteio
 *   for mudada para depois disso, ele roda de novo na nova data.
 */
class AddAutoRaffleRanAtToCampings extends BaseMigration
{
    public function change(): void
    {
        $this->table('campings')
            ->addColumn('camper_raffle_auto_ran_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'raffle_camper_date',
            ])
            ->addColumn('servant_raffle_auto_ran_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'raffle_servant_date',
            ])
            ->update();
    }
}
