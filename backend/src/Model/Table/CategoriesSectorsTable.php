<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * CategoriesSectors Model
 *
 * @property \App\Model\Table\CategoriesTable&\Cake\ORM\Association\BelongsTo $Categories
 * @property \App\Model\Table\SectorsTable&\Cake\ORM\Association\BelongsTo $Sectors
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsToMany $Users
 *
 * @method \App\Model\Entity\CategoriesSector newEmptyEntity()
 * @method \App\Model\Entity\CategoriesSector newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\CategoriesSector> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\CategoriesSector get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\CategoriesSector findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\CategoriesSector patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\CategoriesSector> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\CategoriesSector|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\CategoriesSector saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\CategoriesSector>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CategoriesSector>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CategoriesSector>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CategoriesSector> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CategoriesSector>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CategoriesSector>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CategoriesSector>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CategoriesSector> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class CategoriesSectorsTable extends Table
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

        $this->setTable('categories_sectors');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Categories', [
            'foreignKey' => 'category_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Sectors', [
            'foreignKey' => 'sector_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsToMany('Users', [
            'foreignKey' => 'categories_sector_id',
            'targetForeignKey' => 'user_id',
            'joinTable' => 'categories_sectors_users',
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
            ->notEmptyString('category_id');

        $validator
            ->notEmptyString('sector_id');

        $validator
            ->integer('vacancies')
            ->requirePresence('vacancies', 'create')
            ->notEmptyString('vacancies');

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
        $rules->add($rules->existsIn(['category_id'], 'Categories'), ['errorField' => 'category_id']);
        $rules->add($rules->existsIn(['sector_id'], 'Sectors'), ['errorField' => 'sector_id']);

        return $rules;
    }
}
