<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * CampingsFixture
 */
class CampingsFixture extends TestFixture
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
                'notice' => 'Lorem ipsum dolor sit amet',
                'term' => 'Lorem ipsum dolor sit amet',
                'image' => 'Lorem ipsum dolor sit amet',
                'minimal_age' => 1,
                'maximal_age' => 1,
                'camper_fee' => 1.5,
                'servant_fee' => 1.5,
                'planned_man_vacancies' => 1,
                'planned_woman_vacancies' => 1,
                'planned_couple_vacancies' => 1,
                'raffle_man_vacancies' => 1,
                'raffle_woman_vacancies' => 1,
                'raffle_couple_vacancies' => 1,
                'raffle_total_vacancies' => 1,
                'raffle_camper_subscription_start_date' => '2026-05-29 16:32:06',
                'raffle_camper_subscription_end_date' => '2026-05-29 16:32:06',
                'raffle_camper_date' => '2026-05-29 16:32:06',
                'raffle_servant_subscription_start_date' => '2026-05-29 16:32:06',
                'raffle_servant_subscription_end_date' => '2026-05-29 16:32:06',
                'raffle_servant_date' => '2026-05-29 16:32:06',
                'camper_registration_start_date' => '2026-05-29 16:32:06',
                'camper_registration_end_date' => '2026-05-29 16:32:06',
                'camper_payment_link' => 'Lorem ipsum dolor sit amet',
                'camper_payment_date' => '2026-05-29 16:32:06',
                'servant_registration_start_date' => '2026-05-29 16:32:06',
                'servant_registration_end_date' => '2026-05-29 16:32:06',
                'servant_payment_link' => 'Lorem ipsum dolor sit amet',
                'servant_payment_date' => '2026-05-29 16:32:06',
                'created' => '2026-05-29 16:32:06',
                'modified' => '2026-05-29 16:32:06',
                'deleted' => '2026-05-29 16:32:06',
            ],
        ];
        parent::init();
    }
}
