<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Pedir correção e cancelar inscrição pela organização (aba Respostas).
 *
 * - form_corrections: cada pedido de correção de um formulário já enviado.
 *   Fica aberto até a pessoa reenviar ("reenviado"), a organização desfazer
 *   ("desfeito") ou o acampamento/evento começar sem a correção ("recusado").
 *   O prazo (deadline) vale só para esta inscrição; passar dele não tira a vaga.
 * - pre_registrations.disqualified_by / disqualification_note: quem cancelou e
 *   o motivo escrito (a pessoa lê no site). O código do motivo continua em
 *   disqualification_reason ("cancelada_organizacao", "correcao_nao_feita").
 */
class CreateFormCorrectionsAndCancellation extends BaseMigration
{
    public function up(): void
    {
        $this->table('form_corrections', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['autoIncrement' => true, 'signed' => false])
            ->addColumn('pre_registration_id', 'biginteger', ['signed' => false])
            ->addColumn('requested_by', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('reason', 'text')
            ->addColumn('question_ids', 'text', ['null' => true, 'default' => null])
            ->addColumn('deadline', 'datetime')
            ->addColumn('resolved_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('resolution', 'string', ['limit' => 20, 'null' => true, 'default' => null])
            ->addColumn('resolved_by', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('created', 'datetime')
            ->addColumn('modified', 'datetime')
            ->addIndex(['pre_registration_id'])
            ->addForeignKey('pre_registration_id', 'pre_registrations', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('requested_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('resolved_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('pre_registrations')
            ->addColumn('disqualified_by', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'disqualification_reason'])
            ->addColumn('disqualification_note', 'text', ['null' => true, 'default' => null, 'after' => 'disqualified_by'])
            ->addForeignKey('disqualified_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('pre_registrations')
            ->dropForeignKey('disqualified_by')
            ->removeColumn('disqualified_by')
            ->removeColumn('disqualification_note')
            ->update();
        $this->table('form_corrections')->drop()->save();
    }
}
