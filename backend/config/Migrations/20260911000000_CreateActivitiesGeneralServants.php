<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateActivitiesGeneralServants extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('activities_general_servants', [
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
            ->addColumn('activity_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addForeignKey('activity_id', 'activities', 'id', [
                'constraint' => 'act_general_servants_activities_fk',
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'constraint' => 'act_general_servants_users_fk',
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addIndex(['activity_id', 'user_id'], ['unique' => true, 'name' => 'act_general_servants_unique'])
            ->create();
    }
}
