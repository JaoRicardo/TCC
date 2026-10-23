<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateCategoriesQuestions extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('categories_questions', [
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
            ->addColumn('question_id', 'biginteger', [
                'null' => false,
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
            ->addForeignKey('category_id', 'categories', 'id', [
                'constraint' => 'categories_questions_categories_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('question_id', 'questions', 'id', [
                'constraint' => 'categories_questions_questions_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
