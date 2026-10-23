<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Redesenha o sistema de perguntas pra virar um form builder de verdade:
 * - `type` deixa de ser um enum fixo (Aberta/Fechada) e vira string livre, com o
 *   conjunto de tipos suportados controlado na aplicação (texto_curto, texto_longo,
 *   numero, data, sim_nao, unica_escolha, multipla_escolha, lista_suspensa).
 * - Cada pergunta passa a pertencer a exatamente um formulário (categoria + tipo de
 *   inscrição), então `category_id`/`subscription_type` vão direto pra `questions` e
 *   a tabela `categories_questions` (many-to-many, nunca chegou a ter dado real) é
 *   removida.
 * - `is_required` permite marcar pergunta obrigatória.
 */
class RedesignQuestionsForFormBuilder extends BaseMigration
{
    public function up(): void
    {
        $this->table('questions')
            ->changeColumn('type', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('is_required', 'boolean', ['null' => false, 'default' => true, 'after' => 'type'])
            ->addColumn('category_id', 'biginteger', ['null' => false, 'signed' => false, 'after' => 'id'])
            ->addColumn('subscription_type', 'string', ['limit' => 20, 'null' => false, 'default' => 'Campista', 'after' => 'category_id'])
            ->addForeignKey('category_id', 'categories', 'id', [
                'constraint' => 'questions_categories_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->update();

        $this->table('categories_questions')->drop()->save();
    }

    public function down(): void
    {
        $table = $this->table('categories_questions', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_520_ci',
        ]);
        $table
            ->addColumn('id', 'biginteger', ['autoIncrement' => true, 'null' => false, 'signed' => false])
            ->addColumn('category_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('question_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('deleted', 'datetime', ['null' => true, 'default' => null])
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

        $this->table('questions')
            ->dropForeignKey('category_id')
            ->removeColumn('category_id')
            ->removeColumn('subscription_type')
            ->removeColumn('is_required')
            ->changeColumn('type', 'enum', ['values' => ['Aberta', 'Fechada'], 'null' => false])
            ->update();
    }
}
