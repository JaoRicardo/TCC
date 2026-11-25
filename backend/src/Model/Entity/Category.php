<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Category Entity
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property int $position
 * @property int|null $min_age
 * @property int|null $max_age
 * @property bool $in_hierarchy
 * @property int|null $required_category_id
 * @property bool $couples_only
 * @property bool $accepts_married
 * @property bool $filled_by_guardian
 * @property \Cake\I18n\DateTime|null $archived_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \App\Model\Entity\Activity[] $activities
 * @property \App\Model\Entity\Question[] $questions
 * @property \App\Model\Entity\Sector[] $sectors
 */
class Category extends Entity
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
        'name' => true,
        'type' => true,
        'position' => true,
        'min_age' => true,
        'max_age' => true,
        'in_hierarchy' => true,
        'required_category_id' => true,
        'couples_only' => true,
        'accepts_married' => true,
        'filled_by_guardian' => true,
        'archived_at' => true,
        'created' => true,
        'modified' => true,
        'deleted' => true,
        'activities' => true,
        'questions' => true,
        'sectors' => true,
    ];
}
