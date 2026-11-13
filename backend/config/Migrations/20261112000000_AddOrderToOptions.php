<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Ordem das opções de cada pergunta.
 *
 * Antes, editar as opções de uma pergunta apagava todas e criava de novo, e a
 * ordem saía da sequência dos ids. Agora uma opção que continua na lista mantém
 * o mesmo registro (as respostas e as perguntas condicionais apontam para ele),
 * então a ordem em que a organização escreveu as opções precisa ficar guardada.
 *
 * As opções que já existem recebem a ordem em que foram criadas.
 */
class AddOrderToOptions extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('options')
            ->addColumn('order', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'text',
            ])
            ->update();

        $this->execute(
            'UPDATE options o
             JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY question_id ORDER BY id) AS posicao FROM options) p
               ON p.id = o.id
             SET o.`order` = p.posicao',
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('options')->removeColumn('order')->update();
    }
}
