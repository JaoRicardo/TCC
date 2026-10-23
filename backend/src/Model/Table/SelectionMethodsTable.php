<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * SelectionMethods Model
 *
 * @property \App\Model\Table\CampingPreRegistrationTable&\Cake\ORM\Association\HasMany $CampingPreRegistration
 *
 * @method \App\Model\Entity\SelectionMethod newEmptyEntity()
 * @method \App\Model\Entity\SelectionMethod newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\SelectionMethod> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\SelectionMethod get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\SelectionMethod findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\SelectionMethod patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\SelectionMethod> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\SelectionMethod|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\SelectionMethod saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\SelectionMethod>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SelectionMethod>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SelectionMethod>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SelectionMethod> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SelectionMethod>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SelectionMethod>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SelectionMethod>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SelectionMethod> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class SelectionMethodsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('selection_methods');
        $this->setDisplayField('method');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('CampingPreRegistration', [
            'foreignKey' => 'selection_method_id',
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('method')
            ->maxLength('method', 255)
            ->requirePresence('method', 'create')
            ->notEmptyString('method');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->requirePresence('description', 'create')
            ->notEmptyString('description');

        $validator
            ->scalar('type')
            ->requirePresence('type', 'create')
            ->notEmptyString('type');

        $validator
            ->dateTime('deleted')
            ->allowEmptyDateTime('deleted');

        return $validator;
    }
}
