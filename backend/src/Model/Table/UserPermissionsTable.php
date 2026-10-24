<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class UserPermissionsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('user_permissions');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType'   => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('user_id');
        $validator->notEmptyString('permission');

        return $validator;
    }
}
