<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * CampingPreRegistrationFixture
 */
class CampingPreRegistrationFixture extends TestFixture
{
    /**
     * Table name
     *
     * @var string
     */
    public string $table = 'camping_pre_registration';
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
                'substitute_position' => 1,
                'is_quitter' => 1,
                'selection_method_id' => 1,
                'spouse_id' => 1,
                'sector_id' => 1,
                'sector2_id' => 1,
            ],
        ];
        parent::init();
    }
}
