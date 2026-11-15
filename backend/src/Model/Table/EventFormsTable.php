<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Formulário de evento: um modelo com nome próprio, que vários eventos podem
 * usar (activities.event_form_id). As perguntas são as mesmas dos acampamentos,
 * com questions.event_form_id no lugar da categoria.
 */
class EventFormsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('event_forms');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->hasMany('Questions', ['foreignKey' => 'event_form_id']);
        $this->hasMany('Activities', ['foreignKey' => 'event_form_id']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 150)
            ->requirePresence('name', 'create')
            ->notEmptyString('name', 'Dê um nome ao formulário.');

        return $validator;
    }
}
