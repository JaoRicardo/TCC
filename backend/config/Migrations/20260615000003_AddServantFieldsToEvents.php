<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddServantFieldsToEvents extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('events');
        $table
            ->addColumn('servant_registration_start_date', 'datetime', [
                'default' => null,
                'null'    => true,
                'after'   => 'payment_link',
            ])
            ->addColumn('servant_registration_end_date', 'datetime', [
                'default' => null,
                'null'    => true,
                'after'   => 'servant_registration_start_date',
            ])
            ->addColumn('servant_vacancies', 'integer', [
                'default' => 0,
                'null'    => true,
                'after'   => 'servant_registration_end_date',
            ])
            ->update();
    }

    public function down(): void
    {
        $table = $this->table('events');
        $table
            ->removeColumn('servant_registration_start_date')
            ->removeColumn('servant_registration_end_date')
            ->removeColumn('servant_vacancies')
            ->update();
    }
}
