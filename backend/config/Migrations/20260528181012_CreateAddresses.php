<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAddresses extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('addresses', [
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
            ->addColumn('street', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('number', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('neighborhood', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('city', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('cep', 'string', [
                'limit' => 8,
                'null' => false,
            ])
            ->addColumn('user_id', 'biginteger', [
                'null' => false,
                'signed' => false,
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
            ->addForeignKey('user_id', 'users', 'id', [
                'constraint' => 'addresses_users_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
