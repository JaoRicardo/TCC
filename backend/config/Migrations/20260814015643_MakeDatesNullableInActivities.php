<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MakeDatesNullableInActivities extends BaseMigration
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
        $opts = ['default' => null, 'null' => true];
        $table->changeColumn('servant_registration_start_date', 'datetime', $opts);
        $table->changeColumn('servant_registration_end_date', 'datetime', $opts);
        $table->changeColumn('participant_registration_start_date', 'datetime', $opts);
        $table->changeColumn('participant_registration_end_date', 'datetime', $opts);
        $table->update();
    }
}
