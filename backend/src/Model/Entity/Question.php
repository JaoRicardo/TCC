<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Question Entity
 *
 * @property int $id
 * @property int $category_id
 * @property string $subscription_type
 * @property string $text
 * @property int $order
 * @property string $type
 * @property bool $is_required
 * @property bool $accept_generic_answer
 * @property int $section_id
 * @property int|null $depends_on_option_id
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \App\Model\Entity\Category $category
 * @property \App\Model\Entity\Section $section
 * @property \App\Model\Entity\Option $depends_on_option
 * @property \App\Model\Entity\Answer[] $answers
 * @property \App\Model\Entity\Option[] $options
 */
class Question extends Entity
{
    protected array $_accessible = [
        'category_id' => true,
        'subscription_type' => true,
        'text' => true,
        'order' => true,
        'type' => true,
        'is_required' => true,
        'accept_generic_answer' => true,
        'section_id' => true,
        'depends_on_option_id' => true,
        'created' => true,
        'modified' => true,
        'deleted' => true,
        'category' => true,
        'section' => true,
        'depends_on_option' => true,
        'answers' => true,
        'options' => true,
    ];
}
