<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateCampings extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('campings', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $table
            ->addColumn('id', 'biginteger', [
                'autoIncrement' => true,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('notice', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('term', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('planned_man_vacancies', 'integer', ['null' => false])
            ->addColumn('planned_woman_vacancies', 'integer', ['null' => false])
            ->addColumn('planned_couple_vacancies', 'integer', ['null' => false])
            ->addColumn('raffle_man_vacancies', 'integer', ['null' => false])
            ->addColumn('raffle_woman_vacancies', 'integer', ['null' => false])
            ->addColumn('raffle_couple_vacancies', 'integer', ['null' => false])
            ->addColumn('raffle_camper_date', 'datetime', ['null' => false])
            ->addColumn('raffle_servant_subscription_start_date', 'datetime', ['null' => false])
            ->addColumn('raffle_servant_subscription_end_date', 'datetime', ['null' => false])
            ->addColumn('raffle_servant_date', 'datetime', ['null' => false])
            ->addColumn('camper_registration_start_date', 'datetime', ['null' => false])
            ->addColumn('camper_registration_end_date', 'datetime', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('deleted', 'datetime', ['null' => true, 'default' => null])
            ->create();
    }
}
