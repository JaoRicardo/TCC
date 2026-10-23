<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateQuestions extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('questions', [
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
            ->addColumn('text', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('order', 'integer', [
                'null' => false,
            ])
            ->addColumn('type', 'enum', [
                'values' => ['Aberta', 'Fechada'],
                'null' => false,
            ])
            ->addColumn('accept_generic_answer', 'boolean', [
                'null' => false,
                'default' => false,
            ])
            ->addColumn('section_id', 'biginteger', [
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('depends_on_option_id', 'biginteger', [
                'null' => true,
                'default' => null,
                'signed' => false,
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
            ->addForeignKey('section_id', 'sections', 'id', [
                'constraint' => 'questions_sections_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('depends_on_option_id', 'options', 'id', [
                'constraint' => 'questions_options_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
