<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * OptionsFixture
 */
class OptionsFixture extends TestFixture
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
                'text' => 'Lorem ipsum dolor sit amet',
                'question_id' => 1,
                'created' => '2026-05-29 16:32:07',
                'modified' => '2026-05-29 16:32:07',
                'deleted' => '2026-05-29 16:32:07',
            ],
        ];
        parent::init();
    }
}
