<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateMaritalStatuses extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('marital_statuses', [
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
            ->addColumn('title', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('deleted', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->create();
    }
}
