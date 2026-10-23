<?php
declare(strict_types=1);

use Migrations\BaseSeed;

class UsersSeed extends BaseSeed
{
    public function run(): void
    {
        $data = [
            [
                'cpf' => '00000000000',
                'name' => 'Administrador',
                'birthday' => '1990-01-01',
                'sex' => 'M',
                'phone' => '65999999999',
                'email' => 'admin@acampamento.com',
                'is_counselor' => false,
                'is_admin' => true,
                'password' => password_hash('Admin@123', PASSWORD_DEFAULT),
                'marital_status_id' => 1,
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s'),
            ],
        ];

        $this->table('users')->insert($data)->saveData();
    }
}
