<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class ActivitiesCategoriesSectorsUsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('activities_categories_sectors_users');
        $this->setPrimaryKey('id');

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType'   => 'INNER',
        ]);
        $this->belongsTo('ActivitiesCategoriesSectors', [
            'foreignKey' => 'activities_categories_sector_id',
            'joinType'   => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('user_id');
        $validator->notEmptyString('activities_categories_sector_id');
        $validator->boolean('is_coordinator')->notEmptyString('is_coordinator');
        $validator->boolean('is_indicated')->notEmptyString('is_indicated');

        return $validator;
    }
}
