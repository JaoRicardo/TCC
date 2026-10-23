<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * EventsFixture
 */
class EventsFixture extends TestFixture
{
    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'id' => 1,
                'minimal_age' => 1,
                'is_paid_festival' => 1,
                'ticket_price' => 1,
                'sale_start_date' => '2026-05-29 16:32:07',
                'payment_link' => 1,
                'created' => '2026-05-29 16:32:07',
                'modified' => '2026-05-29 16:32:07',
                'deleted' => '2026-05-29 16:32:07',
            ],
        ];
        parent::init();
    }
}
