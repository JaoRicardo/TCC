<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Camping Entity
 *
 * @property int $id
 * @property string $notice
 * @property string $term
 * @property int $planned_man_vacancies
 * @property int $planned_woman_vacancies
 * @property int $planned_couple_vacancies
 * @property int $raffle_man_vacancies
 * @property int $raffle_woman_vacancies
 * @property int $raffle_couple_vacancies
 * @property \Cake\I18n\DateTime $raffle_camper_date
 * @property \Cake\I18n\DateTime $raffle_servant_subscription_start_date
 * @property \Cake\I18n\DateTime $raffle_servant_subscription_end_date
 * @property \Cake\I18n\DateTime $raffle_servant_date
 * @property \Cake\I18n\DateTime $camper_registration_start_date
 * @property \Cake\I18n\DateTime $camper_registration_end_date
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \App\Model\Entity\Activity[] $activities
 */
class Camping extends Entity
{
    protected array $_accessible = [
        'notice'                                 => true,
        'term'                                   => true,
        'planned_man_vacancies'                  => true,
        'planned_woman_vacancies'                => true,
        'planned_couple_vacancies'               => true,
        'raffle_man_vacancies'                   => true,
        'raffle_woman_vacancies'                 => true,
        'raffle_couple_vacancies'                => true,
        'raffle_camper_date'                     => true,
        'camper_form_deadline'                   => true,
        'raffle_servant_subscription_start_date' => true,
        'raffle_servant_subscription_end_date'   => true,
        'raffle_servant_date'                    => true,
        'servant_form_deadline'                  => true,
        'camper_registration_start_date'         => true,
        'camper_registration_end_date'           => true,
        'created'                                => true,
        'modified'                               => true,
        'deleted'                                => true,
        'activities'                             => true,
    ];
}
