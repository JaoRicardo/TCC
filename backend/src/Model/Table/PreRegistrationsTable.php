<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PreRegistrationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('pre_registrations');
        $this->setDisplayField('subscription_type');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType'   => 'INNER',
        ]);
        $this->belongsTo('Activities', [
            'foreignKey' => 'activity_id',
            'joinType'   => 'INNER',
        ]);
        $this->belongsTo('SelectionMethods', [
            'foreignKey' => 'selection_method_id',
            'joinType'   => 'LEFT',
        ]);
        $this->belongsTo('Sectors', [
            'foreignKey' => 'sector_id',
            'joinType'   => 'LEFT',
        ]);
        $this->hasMany('Answers', [
            'foreignKey' => 'pre_registration_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('subscription_type')
            ->requirePresence('subscription_type', 'create')
            ->notEmptyString('subscription_type');

        $validator
            ->boolean('is_fee_paid')
            ->notEmptyString('is_fee_paid');

        $validator
            ->scalar('qrcode_data')
            ->maxLength('qrcode_data', 255)
            ->allowEmptyString('qrcode_data');

        $validator
            ->boolean('is_qrcode_used')
            ->allowEmptyString('is_qrcode_used');

        $validator
            ->notEmptyString('user_id');

        $validator
            ->notEmptyString('activity_id');

        $validator
            ->integer('substitute_position')
            ->allowEmptyString('substitute_position');

        $validator
            ->boolean('is_quitter')
            ->notEmptyString('is_quitter');

        $validator
            ->integer('selection_method_id')
            ->allowEmptyString('selection_method_id');

        $validator
            ->integer('spouse_id')
            ->allowEmptyString('spouse_id');

        $validator
            ->integer('sector_id')
            ->allowEmptyString('sector_id');

        $validator
            ->integer('sector2_id')
            ->allowEmptyString('sector2_id');

        $validator
            ->dateTime('deleted')
            ->allowEmptyDateTime('deleted');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['user_id'], 'Users'), ['errorField' => 'user_id']);
        $rules->add($rules->existsIn(['activity_id'], 'Activities'), ['errorField' => 'activity_id']);

        return $rules;
    }
}
