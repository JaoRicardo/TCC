<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * CampingPreRegistration Model
 *
 * @property \App\Model\Table\SelectionMethodsTable&\Cake\ORM\Association\BelongsTo $SelectionMethods
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Spouses
 * @property \App\Model\Table\SectorsTable&\Cake\ORM\Association\BelongsTo $Sectors
 * @property \App\Model\Table\SectorsTable&\Cake\ORM\Association\BelongsTo $Sector2s
 * @property \App\Model\Table\PreRegistrationsTable&\Cake\ORM\Association\HasMany $PreRegistrations
 *
 * @method \App\Model\Entity\CampingPreRegistration newEmptyEntity()
 * @method \App\Model\Entity\CampingPreRegistration newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\CampingPreRegistration> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\CampingPreRegistration get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\CampingPreRegistration findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\CampingPreRegistration patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\CampingPreRegistration> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\CampingPreRegistration|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\CampingPreRegistration saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\CampingPreRegistration>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CampingPreRegistration>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CampingPreRegistration>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CampingPreRegistration> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CampingPreRegistration>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CampingPreRegistration>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CampingPreRegistration>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CampingPreRegistration> deleteManyOrFail(iterable $entities, array $options = [])
 */
class CampingPreRegistrationTable extends Table
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

        $this->setTable('camping_pre_registration');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->belongsTo('SelectionMethods', [
            'foreignKey' => 'selection_method_id',
        ]);
        $this->belongsTo('Spouses', [
            'foreignKey' => 'spouse_id',
            'className' => 'Users',
        ]);
        $this->belongsTo('Sectors', [
            'foreignKey' => 'sector_id',
        ]);
        $this->belongsTo('Sector2s', [
            'foreignKey' => 'sector2_id',
            'className' => 'Sectors',
        ]);
        $this->hasMany('PreRegistrations', [
            'foreignKey' => 'camping_pre_registration_id',
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
            ->integer('substitute_position')
            ->allowEmptyString('substitute_position');

        $validator
            ->boolean('is_quitter')
            ->notEmptyString('is_quitter');

        $validator
            ->allowEmptyString('selection_method_id');

        $validator
            ->allowEmptyString('spouse_id');

        $validator
            ->allowEmptyString('sector_id');

        $validator
            ->allowEmptyString('sector2_id');

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
        $rules->add($rules->existsIn(['selection_method_id'], 'SelectionMethods'), ['errorField' => 'selection_method_id']);
        $rules->add($rules->existsIn(['spouse_id'], 'Spouses'), ['errorField' => 'spouse_id']);
        $rules->add($rules->existsIn(['sector_id'], 'Sectors'), ['errorField' => 'sector_id']);
        $rules->add($rules->existsIn(['sector2_id'], 'Sector2s'), ['errorField' => 'sector2_id']);

        return $rules;
    }
}
