<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Created without the FK to `questions` due to the circular dependency
 * between options.question_id -> questions and questions.depends_on_option_id -> options.
 * The FK is added in 20260528181011_AddOptionsForeignKeyToQuestions.php
 * after both tables exist.
 */
class CreateOptions extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('options', [
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
            ->addIndex(['question_id'], [
                'name' => 'answers_questions_FK',
            ])
            ->create();
    }
}
