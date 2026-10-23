<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateActivitiesCategoriesSectorsUsers extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('activities_categories_sectors_users', [
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
            ->addColumn('user_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('activities_categories_sector_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('is_coordinator', 'boolean', ['null' => false, 'default' => false])
            ->addForeignKey('user_id', 'users', 'id', [
                'constraint' => 'activities_categories_sectors_users_users_FK',
            ])
            ->addForeignKey('activities_categories_sector_id', 'activities_categories_sectors', 'id', [
                'constraint' => 'activities_categories_sectors_users_act_categories_sectors_FK',
            ])
            ->create();
    }
}
