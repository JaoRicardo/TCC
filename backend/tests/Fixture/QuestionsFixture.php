<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * QuestionsFixture
 */
class QuestionsFixture extends TestFixture
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
                'order' => 1,
                'type' => 'Lorem ipsum dolor sit amet',
                'accept_generic_answer' => 1,
                'section_id' => 1,
                'depends_on_option_id' => 1,
                'created' => '2026-05-29 16:32:07',
                'modified' => '2026-05-29 16:32:07',
                'deleted' => '2026-05-29 16:32:07',
            ],
        ];
        parent::init();
    }
}
