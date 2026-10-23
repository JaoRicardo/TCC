<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateCampingPreRegistration extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('camping_pre_registration', [
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
            ->addColumn('substitute_position', 'integer', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('is_quitter', 'boolean', [
                'null' => false,
                'default' => false,
            ])
            ->addColumn('selection_method_id', 'biginteger', [
                'null' => true,
                'default' => null,
                'signed' => false,
            ])
            ->addColumn('spouse_id', 'biginteger', [
                'null' => true,
                'default' => null,
                'signed' => false,
            ])
            ->addColumn('sector_id', 'biginteger', [
                'null' => true,
                'default' => null,
                'signed' => false,
            ])
            ->addColumn('sector2_id', 'biginteger', [
                'null' => true,
                'default' => null,
                'signed' => false,
            ])
            ->addForeignKey('selection_method_id', 'selection_methods', 'id', [
                'constraint' => 'camping_pre_registration_selection_methods_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('spouse_id', 'users', 'id', [
                'constraint' => 'camping_pre_registration_users_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('sector_id', 'sectors', 'id', [
                'constraint' => 'camping_pre_registration_sectors_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('sector2_id', 'sectors', 'id', [
                'constraint' => 'camping_pre_registration_sectors_FK_1',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
