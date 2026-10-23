<?php
declare(strict_types=1);

use Migrations\BaseSeed;

class CategoriesSeed extends BaseSeed
{
    public function run(): void
    {
        $this->getAdapter()->execute('SET FOREIGN_KEY_CHECKS = 0');
        $this->getAdapter()->execute('TRUNCATE TABLE categories');
        $this->getAdapter()->execute('SET FOREIGN_KEY_CHECKS = 1');

        $data = [
            ['name' => 'FAC',           'type' => 'Acampamento', 'min_age' => 14,   'max_age' => 17,   'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Juvenil',       'type' => 'Acampamento', 'min_age' => 18,   'max_age' => 24,   'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Sênior',        'type' => 'Acampamento', 'min_age' => 25,   'max_age' => 60,   'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Casais',        'type' => 'Acampamento', 'min_age' => null, 'max_age' => null, 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Mirim',         'type' => 'Acampamento', 'min_age' => 10,   'max_age' => 12,   'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Discipulados',  'type' => 'Acampamento', 'min_age' => null, 'max_age' => null, 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Evento Geral',  'type' => 'Evento',      'min_age' => null, 'max_age' => null, 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Celebração',    'type' => 'Evento',      'min_age' => null, 'max_age' => null, 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
        ];

        $this->table('categories')->insert($data)->saveData();
    }
}
