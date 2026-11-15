<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Formulários de evento.
 *
 * Evento não tem categoria nem servos, então o formulário dele é um modelo com
 * nome próprio ("Retiro de Carnaval", "Formulário básico de evento") e cada
 * evento aponta para um (activities.event_form_id) — ou para nenhum: evento sem
 * formulário continua concluindo a inscrição na hora.
 *
 * As perguntas usam o mesmo modelo dos acampamentos: uma pergunta é de um
 * formulário de acampamento (category_id + subscription_type) ou de um
 * formulário de evento (event_form_id, com category_id vazio e o tipo
 * "Participante").
 */
class CreateEventForms extends BaseMigration
{
    public function up(): void
    {
        $this->table('event_forms', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_520_ci',
        ])
            ->addColumn('id', 'biginteger', ['autoIncrement' => true, 'null' => false, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('deleted', 'datetime', ['null' => true, 'default' => null])
            ->create();

        $this->table('questions')
            ->changeColumn('category_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('event_form_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'category_id'])
            ->addForeignKey('event_form_id', 'event_forms', 'id', ['delete' => 'NO_ACTION', 'update' => 'NO_ACTION'])
            ->update();

        $this->table('activities')
            ->addColumn('event_form_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'camping_id'])
            ->addForeignKey('event_form_id', 'event_forms', 'id', ['delete' => 'NO_ACTION', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('activities')->dropForeignKey('event_form_id')->removeColumn('event_form_id')->update();
        $this->table('questions')->dropForeignKey('event_form_id')->removeColumn('event_form_id')->update();
        $this->execute('DELETE FROM questions WHERE category_id IS NULL');
        $this->table('questions')
            ->changeColumn('category_id', 'biginteger', ['null' => false, 'signed' => false])
            ->update();
        $this->table('event_forms')->drop()->save();
    }
}
