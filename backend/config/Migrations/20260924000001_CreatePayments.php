<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tabela de pagamentos (Mercado Pago).
 *
 * Uma linha por tentativa de pagamento de uma inscrição. `pre_registrations.is_fee_paid`
 * continua sendo o "resumo" consultado pelo resto do sistema; esta tabela guarda o
 * histórico e os dados que o Mercado Pago devolve, para conferência depois.
 *
 * Campos do provedor:
 * - `preference_id`: id da preference criada por nós (Checkout Pro).
 * - `provider_payment_id`: id do pagamento em si, que só existe depois que a
 *   pessoa paga — é o que chega no webhook em `data.id`.
 * - `external_reference`: nossa chave de volta (pre_registration_id), devolvida
 *   pelo Mercado Pago em toda notificação.
 * - `raw_payload`: JSON cru da última consulta, para depurar sem depender do painel deles.
 */
class CreatePayments extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('payments', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_520_ci',
        ]);

        $table
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

    public function down(): void
    {
        $this->table('payments')->drop()->save();
    }
}
