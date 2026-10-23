<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * CategoriesSectorsUsersFixture
 */
class CategoriesSectorsUsersFixture extends TestFixture
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
                'user_id' => 1,
                'categories_sectors_id' => 1,
                'activity_id' => 1,
                'is_coordinator' => 1,
                'created' => '2026-05-29 16:32:07',
                'modified' => 1780086727,
                'deleted' => '2026-05-29 16:32:07',
            ],
        ];
        parent::init();
    }
}
