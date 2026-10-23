<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * ActivitiesFixture
 */
class ActivitiesFixture extends TestFixture
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
                'name' => 'Lorem ipsum dolor sit amet',
                'image' => 'Lorem ipsum dolor sit amet',
                'place' => 'Lorem ipsum dolor sit amet',
                'year' => '',
                'start_date' => '2026-05-29 16:32:06',
                'duration_days' => 1,
                'total_vacancies' => 1,
                'category_id' => 1,
                'activitable_type' => 'Lorem ipsum dolor sit amet',
                'activitable_id' => 1,
                'created' => '2026-05-29 16:32:06',
                'modified' => 1780086726,
                'deleted' => '2026-05-29 16:32:06',
            ],
        ];
        parent::init();
    }
}
