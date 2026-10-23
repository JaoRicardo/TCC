<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateCategoriesSectors extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('categories_sectors', [
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
            ->addColumn('category_id', 'biginteger', [
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('sector_id', 'biginteger', [
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('base_vacancies', 'integer', [
                'null' => false,
            ])
            ->addColumn('raffle_vacancies', 'integer', [
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addColumn('deleted', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addForeignKey('category_id', 'categories', 'id', [
                'constraint' => 'activitycategories_sectors_activity_categories_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('sector_id', 'sectors', 'id', [
                'constraint' => 'activitycategories_sectors_sectors_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
