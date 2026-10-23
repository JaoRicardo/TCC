<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * CategoriesSector Entity
 *
 * @property int $id
 * @property int $category_id
 * @property int $sector_id
 * @property int $vacancies
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \App\Model\Entity\Category $category
 * @property \App\Model\Entity\Sector $sector
 * @property \App\Model\Entity\User[] $users
 */
class CategoriesSector extends Entity
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
        'category_id' => true,
        'sector_id' => true,
        'vacancies' => true,
        'created' => true,
        'modified' => true,
        'deleted' => true,
        'category' => true,
        'sector' => true,
        'users' => true,
    ];
}
