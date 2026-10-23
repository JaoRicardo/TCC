<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class CampingsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('campings');
        $this->setDisplayField('notice');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Activities', [
            'foreignKey' => 'camping_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('notice')
            ->maxLength('notice', 255)
            ->requirePresence('notice', 'create')
            ->notEmptyString('notice');

        $validator
            ->scalar('term')
            ->maxLength('term', 255)
            ->requirePresence('term', 'create')
            ->notEmptyString('term');

        $validator
            ->integer('planned_man_vacancies')
            ->allowEmptyString('planned_man_vacancies');

        $validator
            ->integer('planned_woman_vacancies')
            ->allowEmptyString('planned_woman_vacancies');

        $validator
            ->integer('planned_couple_vacancies')
            ->allowEmptyString('planned_couple_vacancies');

        $validator
            ->integer('raffle_man_vacancies')
            ->allowEmptyString('raffle_man_vacancies');

        $validator
            ->integer('raffle_woman_vacancies')
            ->allowEmptyString('raffle_woman_vacancies');

        $validator
            ->integer('raffle_couple_vacancies')
            ->allowEmptyString('raffle_couple_vacancies');

        $validator
            ->dateTime('raffle_camper_date')
            ->allowEmptyDateTime('raffle_camper_date');

        $validator
            ->dateTime('raffle_servant_subscription_start_date')
            ->allowEmptyDateTime('raffle_servant_subscription_start_date');

        $validator
            ->dateTime('raffle_servant_subscription_end_date')
            ->allowEmptyDateTime('raffle_servant_subscription_end_date');

        $validator
            ->dateTime('raffle_servant_date')
            ->allowEmptyDateTime('raffle_servant_date');

        $validator
            ->dateTime('camper_registration_start_date')
            ->allowEmptyDateTime('camper_registration_start_date');

        $validator
            ->dateTime('camper_registration_end_date')
            ->allowEmptyDateTime('camper_registration_end_date');

        $validator
            ->dateTime('deleted')
            ->allowEmptyDateTime('deleted');

        return $validator;
    }
}
