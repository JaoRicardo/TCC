<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Payments Model
 *
 * @property \App\Model\Table\PreRegistrationsTable&\Cake\ORM\Association\BelongsTo $PreRegistrations
 *
 * @method \App\Model\Entity\Payment newEmptyEntity()
 * @method \App\Model\Entity\Payment newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\Payment patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\Payment|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class PaymentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('payments');
        $this->setDisplayField('status');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('PreRegistrations', [
            'foreignKey' => 'pre_registration_id',
            'joinType'   => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->notEmptyString('pre_registration_id');

        $validator
            ->scalar('provider')
            ->maxLength('provider', 20)
            ->notEmptyString('provider');

        $validator
            ->scalar('status')
            ->maxLength('status', 30)
            ->notEmptyString('status');

        $validator
            ->decimal('amount')
            ->notEmptyString('amount');

        $validator
            ->scalar('preference_id')
            ->maxLength('preference_id', 100)
            ->allowEmptyString('preference_id');

        $validator
            ->scalar('provider_payment_id')
            ->maxLength('provider_payment_id', 100)
            ->allowEmptyString('provider_payment_id');

        $validator
            ->scalar('external_reference')
            ->maxLength('external_reference', 80)
            ->allowEmptyString('external_reference');

        $validator
            ->scalar('status_detail')
            ->maxLength('status_detail', 60)
            ->allowEmptyString('status_detail');

        $validator
            ->scalar('payment_method')
            ->maxLength('payment_method', 40)
            ->allowEmptyString('payment_method');

        $validator
            ->scalar('init_point')
            ->maxLength('init_point', 500)
            ->allowEmptyString('init_point');

        $validator
            ->allowEmptyString('raw_payload');

        $validator
            ->dateTime('paid_at')
            ->allowEmptyDateTime('paid_at');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['pre_registration_id'], 'PreRegistrations'), ['errorField' => 'pre_registration_id']);

        return $rules;
    }
}
