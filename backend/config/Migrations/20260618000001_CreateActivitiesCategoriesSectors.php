<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateActivitiesCategoriesSectors extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('activities_categories_sectors', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_520_ci',
        ]);
        $table
            ->addColumn('id', 'biginteger', [
                'autoIncrement' => true,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('categories_sectors_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('activity_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('actual_base_vacancies', 'integer', ['null' => false])
            ->addColumn('actual_raffle_vacancies', 'integer', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'timestamp', ['null' => false])
            ->addColumn('deleted', 'datetime', ['null' => true, 'default' => null])
            ->addForeignKey('categories_sectors_id', 'categories_sectors', 'id', [
                'constraint' => 'act_cat_sectors_categories_sectors_fk',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('activity_id', 'activities', 'id', [
                'constraint' => 'act_cat_sectors_activities_fk',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
