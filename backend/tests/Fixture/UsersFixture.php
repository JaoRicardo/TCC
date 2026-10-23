<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * UsersFixture
 */
class UsersFixture extends TestFixture
{
    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'id' => 1,
                'cpf' => 'Lorem ipsum dolor sit amet',
                'name' => 'Lorem ipsum dolor sit amet',
                'birthday' => '2026-05-29',
                'sex' => 'Lorem ipsum dolor sit amet',
                'phone' => 'Lorem ipsum dolor sit amet',
                'email' => 'Lorem ipsum dolor sit amet',
                'photo' => 'Lorem ipsum dolor sit amet',
                'is_counselor' => 1,
                'is_admin' => 1,
                'password' => 'Lorem ipsum dolor sit amet',
                'access_token' => 'Lorem ipsum dolor sit amet',
                'refresh_token' => 'Lorem ipsum dolor sit amet',
                'marital_status_id' => 1,
                'created' => '2026-05-29 16:32:12',
                'modified' => '2026-05-29 16:32:12',
                'deleted' => '2026-05-29 16:32:12',
            ],
        ];
        parent::init();
    }
}
