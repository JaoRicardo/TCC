<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPaymentFieldsToActivities extends BaseMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/migrations/5/en/migrations.html#the-change-method
     *
     * @return void
     */
    public function change(): void
    {
        $table = $this->table('activities');
        $table->addColumn('participant_payment_date', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('participant_payment_link', 'string', [
            'default' => null,
            'limit' => 255,
            'null' => true,
        ]);
        $table->addColumn('servant_payment_date', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('servant_payment_link', 'string', [
            'default' => null,
            'limit' => 255,
            'null' => true,
        ]);
        $table->update();
    }
}
