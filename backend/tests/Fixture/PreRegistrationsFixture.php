<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * PreRegistrationsFixture
 */
class PreRegistrationsFixture extends TestFixture
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
                'subscription_type' => 'Lorem ipsum dolor sit amet',
                'is_fee_paid' => 1,
                'payment_code' => 'Lorem ipsum dolor sit amet',
                'qrcode_data' => 'Lorem ipsum dolor sit amet',
                'is_qrcode_used' => 1,
                'user_id' => 1,
                'activity_id' => 1,
                'camping_pre_registration_id' => 1,
                'created' => '2026-05-29 16:32:07',
                'modified' => '2026-05-29 16:32:07',
                'deleted' => '2026-05-29 16:32:07',
            ],
        ];
        parent::init();
    }
}
