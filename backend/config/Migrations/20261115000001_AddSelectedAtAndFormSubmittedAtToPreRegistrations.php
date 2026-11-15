<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Duas datas que a aba Respostas do painel precisa e que o sistema não guardava:
 *
 * - selected_at: quando a inscrição passou a precisar enviar o formulário —
 *   foi sorteada, indicada ou entrou na fila de suplentes ("Confirmado em").
 * - form_submitted_at: quando a pessoa enviou o formulário pela primeira vez
 *   ("Enviado em"). Corrigir as respostas depois não muda essa data.
 *
 * As duas são preenchidas por PreRegistrationsTable::beforeSave. Inscrições
 * anteriores a esta migração ficam sem as datas (null): não há como saber.
 */
class AddSelectedAtAndFormSubmittedAtToPreRegistrations extends BaseMigration
{
    public function change(): void
    {
        $this->table('pre_registrations')
            ->addColumn('selected_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'substitute_position'])
            ->addColumn('form_submitted_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'form_submitted'])
            ->update();
    }
}
