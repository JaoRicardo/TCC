<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Participações anteriores como campista, cadastradas à mão pelo CPF (ver a
 * migração CreatePreviousCampers e ParticipacoesService).
 */
class PreviousCampersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('previous_campers');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Categories', ['foreignKey' => 'category_id', 'joinType' => 'INNER']);
        $this->belongsTo('Registradores', ['className' => 'Users', 'foreignKey' => 'registered_by', 'propertyName' => 'registrador']);
    }
}
