<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * AddressesFixture
 */
class AddressesFixture extends TestFixture
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
                'street' => 'Lorem ipsum dolor sit amet',
                'number' => 'Lorem ipsum dolor sit amet',
                'neighborhood' => 'Lorem ipsum dolor sit amet',
                'city' => 'Lorem ipsum dolor sit amet',
                'cep' => 'Lorem ',
                'user_id' => 1,
                'created' => '2026-05-29 16:32:06',
                'modified' => '2026-05-29 16:32:06',
                'deleted' => '2026-05-29 16:32:06',
            ],
        ];
        parent::init();
    }
}
