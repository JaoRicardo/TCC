<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * CategoriesSectorsUser Entity
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $categories_sectors_id
 * @property int $activity_id
 * @property bool $is_coordinator
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \App\Model\Entity\User $user
 * @property \App\Model\Entity\CategoriesSector $categories_sector
 * @property \App\Model\Entity\Activity $activity
 */
class CategoriesSectorsUser extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'user_id' => true,
        'categories_sectors_id' => true,
        'activity_id' => true,
        'is_coordinator' => true,
        'created' => true,
        'modified' => true,
        'deleted' => true,
        'user' => true,
        'categories_sector' => true,
        'activity' => true,
    ];
}
