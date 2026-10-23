<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class User extends Entity
{
    protected array $_accessible = [
        'cpf' => true,
        'name' => true,
        'birthday' => true,
        'sex' => true,
        'phone' => true,
        'email' => true,
        'photo' => true,
        'is_counselor' => true,
        'is_admin' => true,
        'password' => true,
        'access_token' => true,
        'refresh_token' => true,
        'marital_status_id' => true,
    ];

    protected array $_hidden = ['password', 'access_token', 'refresh_token'];

    protected function _setPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    protected function _setCpf(string $cpf): string
    {
        return preg_replace('/\D/', '', $cpf);
    }

    protected function _setPhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }
}
