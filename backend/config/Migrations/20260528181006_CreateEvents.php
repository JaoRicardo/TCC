<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateEvents extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('events', [
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
            ->addColumn('minimal_age', 'integer', [
                'null' => false,
            ])
            ->addColumn('is_paid_festival', 'boolean', [
                'null' => false,
            ])
            ->addColumn('ticket_price', 'integer', [
                'null' => false,
            ])
            ->addColumn('sale_start_date', 'datetime', [
                'null' => false,
            ])
            ->addColumn('payment_link', 'integer', [
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
            ->create();
    }
}
