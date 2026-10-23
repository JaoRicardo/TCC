<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Remove o prazo individual do suplente promovido.
 *
 * O modelo mudou: sorteados E suplentes respondem o formulário dentro do mesmo
 * prazo do acampamento. Quando uma vaga é liberada, o suplente que assume já
 * respondeu — então não existe mais o caso de "promovido precisa de mais 48h".
 * O prazo passa a ser sempre o do acampamento (`campings.camper_form_deadline` /
 * `servant_form_deadline`).
 *
 * `promoted_at` continua: ele registra quem chegou pela fila de suplentes.
 */
class DropFormDeadlineOverride extends BaseMigration
{
    public function up(): void
    {
        $this->table('pre_registrations')
            ->removeColumn('form_deadline_override')
            ->update();
    }

    public function down(): void
    {
        $this->table('pre_registrations')
            ->addColumn('form_deadline_override', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'promoted_at',
            ])
            ->update();
    }
}
