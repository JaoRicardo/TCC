<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * UserPermission Entity — uma tela do painel liberada para uma pessoa
 * (ver App\Service\PermissoesService).
 *
 * @property int $id
 * @property int $user_id
 * @property string $permission
 * @property \Cake\I18n\DateTime $created
 */
class UserPermission extends Entity
{
    protected array $_accessible = [
        'user_id'    => true,
        'permission' => true,
        'created'    => true,
    ];
}
