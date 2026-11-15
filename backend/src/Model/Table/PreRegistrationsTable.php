<?php
declare(strict_types=1);

namespace App\Model\Table;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\ORM\RulesChecker;
use InvalidArgumentException;
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

    /**
     * Guarda as datas da aba Respostas num lugar só, valendo para todo caminho
     * que salva a inscrição (sorteio, indicação, suplente, "chamar próximo",
     * envio do formulário):
     *
     * - selected_at: o momento em que a inscrição passou de "sem vaga nem lugar
     *   na fila" para sorteada, indicada ou suplente. Suplente promovido mantém
     *   a data de quando entrou na fila; quem sai da lista perde a data.
     * - form_submitted_at: o primeiro envio do formulário.
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if ($entity->isNew() || $entity->isDirty('selection_method_id') || $entity->isDirty('substitute_position')) {
            $temLugar = $entity->get('selection_method_id') !== null || $entity->get('substitute_position') !== null;
            $tinhaLugar = !$entity->isNew()
                && ($this->valorAnterior($entity, 'selection_method_id') !== null
                    || $this->valorAnterior($entity, 'substitute_position') !== null);
            if (!$temLugar) {
                $entity->set('selected_at', null);
            } elseif (!$tinhaLugar) {
                $entity->set('selected_at', DateTime::now());
            }
        }

        if ($entity->isDirty('form_submitted')) {
            if (!$entity->get('form_submitted')) {
                $entity->set('form_submitted_at', null);
            } elseif ($entity->get('form_submitted_at') === null) {
                $entity->set('form_submitted_at', DateTime::now());
            }
        }
    }

    /**
     * Valor do campo antes desta alteração. Campo que não vinha carregado e foi
     * preenchido agora não tem valor anterior guardado — e o getOriginal comum
     * devolveria o valor novo no lugar; aqui ele conta como vazio.
     */
    private function valorAnterior(EntityInterface $entity, string $campo): mixed
    {
        if (!$entity->isDirty($campo)) {
            return $entity->get($campo);
        }
        try {
            return $entity->getOriginal($campo, false);
        } catch (InvalidArgumentException) {
            return null;
        }
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
