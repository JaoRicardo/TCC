<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateActivities extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('activities', [
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
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('image', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('place', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('year', 'integer', ['null' => false])
            ->addColumn('start_date', 'datetime', ['null' => false])
            ->addColumn('duration_days', 'integer', ['null' => false])
            ->addColumn('total_vacancies', 'integer', ['null' => false])
            ->addColumn('servant_registration_start_date', 'datetime', ['null' => false])
            ->addColumn('servant_registration_end_date', 'datetime', ['null' => false])
            ->addColumn('participant_registration_start_date', 'datetime', ['null' => false])
            ->addColumn('participant_registration_end_date', 'datetime', ['null' => false])
            ->addColumn('participant_fee', 'float', ['null' => false])
            ->addColumn('servant_fee', 'float', ['null' => false])
            ->addColumn('category_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('camping_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'timestamp', ['null' => false])
            ->addColumn('deleted', 'datetime', ['null' => true, 'default' => null])
            ->addForeignKey('category_id', 'categories', 'id', [
                'constraint' => 'activities_activity_categories_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('camping_id', 'campings', 'id', [
                'constraint' => 'activities_campings_FK',
            ])
            ->create();
    }
}
