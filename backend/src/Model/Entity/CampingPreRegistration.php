<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * CampingPreRegistration Entity
 *
 * @property int $id
 * @property int|null $substitute_position
 * @property bool $is_quitter
 * @property int|null $selection_method_id
 * @property int|null $spouse_id
 * @property int|null $sector_id
 * @property int|null $sector2_id
 *
 * @property \App\Model\Entity\SelectionMethod $selection_method
 * @property \App\Model\Entity\User $spouse
 * @property \App\Model\Entity\Sector $sector
 * @property \App\Model\Entity\Sector2 $sector2
 * @property \App\Model\Entity\PreRegistration[] $pre_registrations
 */
class CampingPreRegistration extends Entity
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
        'substitute_position' => true,
        'is_quitter' => true,
        'selection_method_id' => true,
        'spouse_id' => true,
        'sector_id' => true,
        'sector2_id' => true,
        'selection_method' => true,
        'spouse' => true,
        'sector' => true,
        'sector2' => true,
        'pre_registrations' => true,
    ];
}
