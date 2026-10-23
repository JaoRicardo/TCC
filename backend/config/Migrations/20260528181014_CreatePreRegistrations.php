<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePreRegistrations extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('pre_registrations', [
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
            ->addColumn('subscription_type', 'enum', [
                'values' => ['Servo', 'Campista', 'Participante'],
                'null' => false,
            ])
            ->addColumn('is_fee_paid', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('qrcode_data', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('is_qrcode_used', 'boolean', ['null' => true, 'default' => false])
            ->addColumn('user_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('activity_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('substitute_position', 'integer', ['null' => true, 'default' => null])
            ->addColumn('is_quitter', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('selection_method_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('spouse_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('sector_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('sector2_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('deleted', 'datetime', ['null' => true, 'default' => null])
            ->addForeignKey('user_id', 'users', 'id', [
                'constraint' => 'subscriptions_user_id_foreign',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('activity_id', 'activities', 'id', [
                'constraint' => 'subscriptions_event_id_foreign',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('sector_id', 'sectors', 'id', [
                'constraint' => 'pre_registrations_sectors_FK',
            ])
            ->addForeignKey('sector2_id', 'sectors', 'id', [
                'constraint' => 'pre_registrations_sectors_FK_1',
            ])
            ->addForeignKey('selection_method_id', 'selection_methods', 'id', [
                'constraint' => 'pre_registrations_selection_methods_FK',
            ])
            ->create();
    }
}
