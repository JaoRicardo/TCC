<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tira o que sobrou do pagamento online (decisão do usuário, 2026-11-21): a
 * pessoa paga direto com a organização, que registra o pagamento na própria
 * inscrição (`pre_registrations.fee_*`).
 *
 * - `payments`: cobranças do Mercado Pago (nunca usada de verdade; vazia).
 * - `activities.participant_payment_link` / `servant_payment_link` e
 *   `events.payment_link`: links de pagamento cadastrados à mão, que nenhuma
 *   tela mostra mais.
 */
class RemoveMercadoPago extends BaseMigration
{
    public function up(): void
    {
        $this->table('payments')->drop()->save();

        $this->table('activities')
            ->removeColumn('participant_payment_link')
            ->removeColumn('servant_payment_link')
            ->update();

        $this->table('events')
            ->removeColumn('payment_link')
            ->update();
    }

    public function down(): void
    {
        $this->table('events')
            ->addColumn('payment_link', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'sale_start_date'])
            ->update();

        $this->table('activities')
            ->addColumn('participant_payment_link', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'participant_payment_date'])
            ->addColumn('servant_payment_link', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'servant_payment_date'])
            ->update();

        $this->table('payments', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_520_ci',
        ])
            ->addColumn('id', 'biginteger', ['autoIncrement' => true, 'null' => false, 'signed' => false])
            ->addColumn('pre_registration_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('provider', 'string', ['limit' => 20, 'null' => false, 'default' => 'mercadopago'])
            ->addColumn('preference_id', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('provider_payment_id', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('external_reference', 'string', ['limit' => 80, 'null' => true, 'default' => null])
            ->addColumn('status', 'string', ['limit' => 30, 'null' => false, 'default' => 'pending'])
            ->addColumn('status_detail', 'string', ['limit' => 60, 'null' => true, 'default' => null])
            ->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => false])
            ->addColumn('payment_method', 'string', ['limit' => 40, 'null' => true, 'default' => null])
            ->addColumn('init_point', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('raw_payload', 'text', ['null' => true, 'default' => null])
            ->addColumn('paid_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('deleted', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['pre_registration_id'])
            ->addIndex(['external_reference'])
            ->addIndex(['provider_payment_id'])
            ->addIndex(['preference_id'])
            ->addForeignKey('pre_registration_id', 'pre_registrations', 'id', [
                'constraint' => 'payments_pre_registrations_FK',
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
