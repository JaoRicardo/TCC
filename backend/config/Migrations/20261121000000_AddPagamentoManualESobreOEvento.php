<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Pagamento registrado à mão pela organização e "Sobre o evento"
 * (handoff design_handoff_painel_evento).
 *
 * - O Mercado Pago não é mais usado: a pessoa paga direto com a organização
 *   (dinheiro ou Pix) e alguém com a tela "Inscritos e Sorteios" registra.
 *   `is_fee_paid` continua sendo o "está pago"; estas colunas dizem como,
 *   quando e por quem. "Desfazer pagamento" limpa todas.
 * - activities.description: texto livre do evento (programação, horário, o
 *   que levar), mostrado na página de detalhes.
 */
class AddPagamentoManualESobreOEvento extends BaseMigration
{
    public function up(): void
    {
        $this->table('pre_registrations')
            ->addColumn('fee_paid_on', 'date', ['null' => true, 'default' => null, 'after' => 'is_fee_paid'])
            ->addColumn('fee_payment_method', 'string', ['limit' => 10, 'null' => true, 'default' => null, 'after' => 'fee_paid_on'])
            ->addColumn('fee_registered_by', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'fee_payment_method'])
            ->addColumn('fee_registered_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'fee_registered_by'])
            ->addColumn('fee_note', 'text', ['null' => true, 'default' => null, 'after' => 'fee_registered_at'])
            ->addForeignKey('fee_registered_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();

        $this->table('activities')
            ->addColumn('description', 'text', ['null' => true, 'default' => null, 'after' => 'place'])
            ->update();
    }

    public function down(): void
    {
        $this->table('activities')->removeColumn('description')->update();
        $this->table('pre_registrations')
            ->dropForeignKey('fee_registered_by')
            ->removeColumn('fee_paid_on')
            ->removeColumn('fee_payment_method')
            ->removeColumn('fee_registered_by')
            ->removeColumn('fee_registered_at')
            ->removeColumn('fee_note')
            ->update();
    }
}
