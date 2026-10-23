<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Answer Entity
 *
 * @property int $id
 * @property int $pre_registration_id
 * @property int $question_id
 * @property string|null $answer
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \App\Model\Entity\PreRegistration $pre_registration
 * @property \App\Model\Entity\Question $question
 */
class Answer extends Entity
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
        'pre_registration_id' => true,
        'question_id' => true,
        'answer' => true,
        'created' => true,
        'modified' => true,
        'deleted' => true,
        'pre_registration' => true,
        'question' => true,
    ];
}
