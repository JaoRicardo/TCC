<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * ActivitiesCategoriesSector Entity
 *
 * @property int $id
 * @property int $categories_sectors_id
 * @property int $activity_id
 * @property int $actual_vacancies
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $deleted
 */
class ActivitiesCategoriesSector extends Entity
{
    protected array $_accessible = [
        'categories_sectors_id'  => true,
        'activity_id'            => true,
        'actual_vacancies'       => true,
        'created'                => true,
        'modified'               => true,
        'deleted'                => true,
    ];
}
