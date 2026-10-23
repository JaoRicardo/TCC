<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAnswers extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('answers', [
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
            ->addColumn('pre_registration_id', 'biginteger', [
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('question_id', 'biginteger', [
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('answer', 'text', [
                'null' => true,
                'default' => null,
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
            ->addForeignKey('pre_registration_id', 'pre_registrations', 'id', [
                'constraint' => 'NewTable_pre_registrations_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('question_id', 'questions', 'id', [
                'constraint' => 'NewTable_questions_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
