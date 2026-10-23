<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * MaritalStatusesFixture
 */
class MaritalStatusesFixture extends TestFixture
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
                'title' => 'Lorem ipsum dolor sit amet',
                'created' => '2026-05-29 16:32:07',
                'modified' => '2026-05-29 16:32:07',
                'deleted' => '2026-05-29 16:32:07',
            ],
        ];
        parent::init();
    }
}
