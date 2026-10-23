<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateSelectionMethods extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('selection_methods', [
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
            ->addColumn('method', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('description', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('type', 'enum', [
                'values' => ['Acampamento', 'Evento'],
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
