<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class ActivitiesCategoriesSectorsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('activities_categories_sectors');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('CategoriesSectors', [
            'foreignKey' => 'categories_sectors_id',
            'joinType'   => 'INNER',
        ]);
        $this->belongsTo('Activities', [
            'foreignKey' => 'activity_id',
            'joinType'   => 'INNER',
        ]);
        $this->hasMany('ActivitiesCategoriesSectorsUsers', [
            'foreignKey' => 'activities_categories_sector_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('categories_sectors_id');
        $validator->notEmptyString('activity_id');
        $validator->integer('actual_vacancies')->notEmptyString('actual_vacancies');
        $validator->dateTime('deleted')->allowEmptyDateTime('deleted');

        return $validator;
    }
}
