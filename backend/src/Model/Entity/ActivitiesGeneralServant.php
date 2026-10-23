<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * ActivitiesGeneralServant Entity
 *
 * @property int $id
 * @property int $activity_id
 * @property int $user_id
 * @property \Cake\I18n\DateTime $created
 */
class ActivitiesGeneralServant extends Entity
{
    protected array $_accessible = [
        'activity_id' => true,
        'user_id'     => true,
        'created'     => true,
    ];
}
