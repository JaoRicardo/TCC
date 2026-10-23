<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateCategoriesSectorsUsers extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('categories_sectors_users', [
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
            ->addColumn('user_id', 'biginteger', [
                'null' => true,
                'default' => null,
                'signed' => false,
            ])
            ->addColumn('categories_sectors_id', 'biginteger', [
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('activity_id', 'biginteger', [
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('is_coordinator', 'boolean', [
                'null' => false,
                'default' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'timestamp', [
                'null' => false,
            ])
            ->addColumn('deleted', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addForeignKey('categories_sectors_id', 'categories_sectors', 'id', [
                'constraint' => 'activitycategoriessectors_users_activitycategories_sectors_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'constraint' => 'activitycategoriessectors_users_users_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('activity_id', 'activities', 'id', [
                'constraint' => 'categories_sectors_users_activities_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
