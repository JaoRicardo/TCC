<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Sectors Model
 *
 * @property \App\Model\Table\CampingPreRegistrationTable&\Cake\ORM\Association\HasMany $CampingPreRegistration
 * @property \App\Model\Table\CategoriesTable&\Cake\ORM\Association\BelongsToMany $Categories
 *
 * @method \App\Model\Entity\Sector newEmptyEntity()
 * @method \App\Model\Entity\Sector newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Sector> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Sector get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Sector findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Sector patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Sector> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Sector|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Sector saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Sector>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Sector>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Sector>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Sector> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Sector>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Sector>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Sector>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Sector> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class SectorsTable extends Table
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

        $this->setTable('sectors');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('CampingPreRegistration', [
            'foreignKey' => 'sector_id',
        ]);
        $this->belongsToMany('Categories', [
            'foreignKey' => 'sector_id',
            'targetForeignKey' => 'category_id',
            'joinTable' => 'categories_sectors',
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
            ->scalar('name')
            ->maxLength('name', 255)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('place')
            ->requirePresence('place', 'create')
            ->notEmptyString('place');

        $validator
            ->dateTime('deleted')
            ->allowEmptyDateTime('deleted');

        return $validator;
    }
}
