<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * ActivitiesCategoriesSectorsUser Entity
 *
 * @property int $id
 * @property int $user_id
 * @property int $activities_categories_sector_id
 * @property bool $is_coordinator
 * @property bool $is_indicated
 */
class ActivitiesCategoriesSectorsUser extends Entity
{
    protected array $_accessible = [
        'user_id'                          => true,
        'activities_categories_sector_id'  => true,
        'is_coordinator'                   => true,
        'is_indicated'                     => true,
        'raffle_position'                  => true,
        'is_substitute'                    => true,
    ];
}
