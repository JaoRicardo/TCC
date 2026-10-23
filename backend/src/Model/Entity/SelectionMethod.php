<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * SelectionMethod Entity
 *
 * @property int $id
 * @property string $method
 * @property string $description
 * @property string $type
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \App\Model\Entity\CampingPreRegistration[] $camping_pre_registration
 */
class SelectionMethod extends Entity
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
        'method' => true,
        'description' => true,
        'type' => true,
        'created' => true,
        'modified' => true,
        'deleted' => true,
        'camping_pre_registration' => true,
    ];
}
