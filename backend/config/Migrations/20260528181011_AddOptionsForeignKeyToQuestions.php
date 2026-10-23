<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Resolves the circular FK between options and questions.
 * options.question_id -> questions.id was deferred from CreateOptions
 * because questions did not yet exist at that point.
 */
class AddOptionsForeignKeyToQuestions extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('options');
        $table
            ->addForeignKey('question_id', 'questions', 'id', [
                'constraint' => 'answers_questions_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->save();
    }
}
