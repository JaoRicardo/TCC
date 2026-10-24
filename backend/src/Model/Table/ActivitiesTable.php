<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ActivitiesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('activities');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Categories', [
            'foreignKey' => 'category_id',
            'joinType'   => 'LEFT',
        ]);
        $this->belongsTo('Campings', [
            'foreignKey' => 'camping_id',
            'joinType'   => 'LEFT',
        ]);
        $this->hasMany('ActivitiesCategoriesSectors', [
            'foreignKey' => 'activity_id',
        ]);
        $this->hasMany('PreRegistrations', [
            'foreignKey' => 'activity_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 255)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('image')
            ->maxLength('image', 255)
            ->allowEmptyString('image');

        $validator
            ->scalar('place')
            ->maxLength('place', 255)
            ->requirePresence('place', 'create')
            ->notEmptyString('place');

        $validator
            ->requirePresence('year', 'create')
            ->notEmptyString('year');

        $validator
            ->dateTime('start_date')
            ->requirePresence('start_date', 'create')
            ->notEmptyDateTime('start_date');

        $validator
            ->integer('duration_days')
            ->requirePresence('duration_days', 'create')
            ->notEmptyString('duration_days');

        $validator
            ->integer('total_vacancies')
            ->requirePresence('total_vacancies', 'create')
            ->notEmptyString('total_vacancies');

        $validator
            ->integer('category_id')
            ->allowEmptyString('category_id');

        $validator
            ->integer('camping_id')
            ->allowEmptyString('camping_id');

        $validator
            ->decimal('participant_fee')
            ->allowEmptyString('participant_fee');

        $validator
            ->decimal('servant_fee')
            ->allowEmptyString('servant_fee');

        $validator
            ->dateTime('participant_registration_start_date')
            ->allowEmptyDateTime('participant_registration_start_date');

        $validator
            ->dateTime('participant_registration_end_date')
            ->allowEmptyDateTime('participant_registration_end_date');

        $validator
            ->dateTime('servant_registration_start_date')
            ->allowEmptyDateTime('servant_registration_start_date');

        $validator
            ->dateTime('servant_registration_end_date')
            ->allowEmptyDateTime('servant_registration_end_date');

        $validator
            ->dateTime('participant_payment_date')
            ->allowEmptyDateTime('participant_payment_date');

        $validator
            ->scalar('participant_payment_link')
            ->maxLength('participant_payment_link', 255)
            ->allowEmptyString('participant_payment_link');

        $validator
            ->dateTime('servant_payment_date')
            ->allowEmptyDateTime('servant_payment_date');

        $validator
            ->scalar('servant_payment_link')
            ->maxLength('servant_payment_link', 255)
            ->allowEmptyString('servant_payment_link');

        $validator
            ->dateTime('deleted')
            ->allowEmptyDateTime('deleted');

        $validator
            ->integer('min_age')
            ->allowEmptyString('min_age');

        $validator
            ->integer('max_age')
            ->allowEmptyString('max_age');

        $validator
            ->scalar('target_audience')
            ->maxLength('target_audience', 255)
            ->allowEmptyString('target_audience');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['category_id'], 'Categories'), ['errorField' => 'category_id']);

        // Pagamento tem que fechar antes de o acampamento começar.
        foreach (['participant_payment_date', 'servant_payment_date'] as $campo) {
            $rules->add(
                fn($activity) => self::pagamentoAntesDoInicio($activity->get($campo), $activity->start_date),
                "{$campo}_antes_do_inicio",
                [
                    'errorField' => $campo,
                    'message'    => 'O prazo de pagamento não pode ser depois do início do acampamento.',
                ]
            );
        }

        return $rules;
    }

    private static function pagamentoAntesDoInicio(mixed $pagamento, mixed $inicio): bool
    {
        if (!$pagamento instanceof \DateTimeInterface || !$inicio instanceof \DateTimeInterface) {
            return true;
        }

        return $pagamento <= $inicio;
    }
}
