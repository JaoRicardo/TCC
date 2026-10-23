<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * CategoriesSectorsUsers Model
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 * @property \App\Model\Table\CategoriesSectorsTable&\Cake\ORM\Association\BelongsTo $CategoriesSectors
 * @property \App\Model\Table\ActivitiesTable&\Cake\ORM\Association\BelongsTo $Activities
 *
 * @method \App\Model\Entity\CategoriesSectorsUser newEmptyEntity()
 * @method \App\Model\Entity\CategoriesSectorsUser newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\CategoriesSectorsUser> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\CategoriesSectorsUser get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\CategoriesSectorsUser findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\CategoriesSectorsUser patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\CategoriesSectorsUser> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\CategoriesSectorsUser|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\CategoriesSectorsUser saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\CategoriesSectorsUser>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CategoriesSectorsUser>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CategoriesSectorsUser>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CategoriesSectorsUser> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CategoriesSectorsUser>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CategoriesSectorsUser>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CategoriesSectorsUser>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CategoriesSectorsUser> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class CategoriesSectorsUsersTable extends Table
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

        $this->setTable('categories_sectors_users');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
        ]);
        $this->belongsTo('CategoriesSectors', [
            'foreignKey' => 'categories_sectors_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Activities', [
            'foreignKey' => 'activity_id',
            'joinType' => 'INNER',
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
            ->allowEmptyString('user_id');

        $validator
            ->notEmptyString('categories_sectors_id');

        $validator
            ->notEmptyString('activity_id');

        $validator
            ->boolean('is_coordinator')
            ->notEmptyString('is_coordinator');

        $validator
            ->dateTime('deleted')
            ->allowEmptyDateTime('deleted');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['user_id'], 'Users'), ['errorField' => 'user_id']);
        $rules->add($rules->existsIn(['categories_sectors_id'], 'CategoriesSectors'), ['errorField' => 'categories_sectors_id']);
        $rules->add($rules->existsIn(['activity_id'], 'Activities'), ['errorField' => 'activity_id']);

        return $rules;
    }
}
