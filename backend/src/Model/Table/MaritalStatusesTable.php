<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * MaritalStatuses Model
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\HasMany $Users
 *
 * @method \App\Model\Entity\MaritalStatus newEmptyEntity()
 * @method \App\Model\Entity\MaritalStatus newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\MaritalStatus> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\MaritalStatus get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\MaritalStatus findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\MaritalStatus patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\MaritalStatus> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\MaritalStatus|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\MaritalStatus saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\MaritalStatus>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MaritalStatus>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MaritalStatus>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MaritalStatus> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MaritalStatus>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MaritalStatus>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MaritalStatus>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MaritalStatus> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class MaritalStatusesTable extends Table
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

        $this->setTable('marital_statuses');
        $this->setDisplayField('title');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Users', [
            'foreignKey' => 'marital_status_id',
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
            ->scalar('title')
            ->maxLength('title', 255)
            ->requirePresence('title', 'create')
            ->notEmptyString('title');

        $validator
            ->dateTime('deleted')
            ->allowEmptyDateTime('deleted');

        return $validator;
    }
}
