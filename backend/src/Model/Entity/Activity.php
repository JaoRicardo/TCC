<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Activity Entity
 *
 * @property int $id
 * @property string $name
 * @property string $image
 * @property string $place
 * @property int $year
 * @property \Cake\I18n\DateTime $start_date
 * @property int $duration_days
 * @property int $total_vacancies
 * @property \Cake\I18n\DateTime|null $servant_registration_start_date
 * @property \Cake\I18n\DateTime|null $servant_registration_end_date
 * @property \Cake\I18n\DateTime|null $participant_registration_start_date
 * @property \Cake\I18n\DateTime|null $participant_registration_end_date
 * @property float $participant_fee
 * @property float $servant_fee
 * @property bool $is_featured
 * @property int $category_id
 * @property int|null $camping_id
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \App\Model\Entity\Category $category
 * @property \App\Model\Entity\Camping|null $camping
 * @property \App\Model\Entity\PreRegistration[] $pre_registrations
 * @property \App\Model\Entity\ActivitiesCategoriesSector[] $activities_categories_sectors
 */
class Activity extends Entity
{
    protected array $_accessible = [
        'name'                                => true,
        'image'                               => true,
        'place'                               => true,
        'year'                                => true,
        'start_date'                          => true,
        'duration_days'                       => true,
        'total_vacancies'                     => true,
        'servant_registration_start_date'     => true,
        'servant_registration_end_date'       => true,
        'participant_registration_start_date' => true,
        'participant_registration_end_date'   => true,
        'participant_fee'                     => true,
        'servant_fee'                         => true,
        'participant_payment_date'            => true,
        'participant_payment_link'            => true,
        'servant_payment_date'                => true,
        'servant_payment_link'                => true,
        'min_age'                             => true,
        'max_age'                             => true,
        'target_audience'                     => true,
        'is_featured'                         => true,
        'category_id'                         => true,
        'camping_id'                          => true,
        'created'                             => true,
        'modified'                            => true,
        'deleted'                             => true,
        'category'                            => true,
        'camping'                             => true,
        'pre_registrations'                   => true,
        'activities_categories_sectors'       => true,
    ];
}
