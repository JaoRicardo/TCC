<?php
declare(strict_types=1);
use Migrations\BaseMigration;
class FixEventsTable extends BaseMigration {
    public function change(): void {
        $this->table('events')
            ->changeColumn('payment_link', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->changeColumn('sale_start_date', 'datetime', ['null' => true, 'default' => null])
            ->update();
    }
}
