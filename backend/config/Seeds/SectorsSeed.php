<?php
declare(strict_types=1);

use Migrations\BaseSeed;

class SectorsSeed extends BaseSeed
{
    public function run(): void
    {
        $data = [
            ['name' => 'Louvor', 'place' => 'Interno', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Diaconia', 'place' => 'Interno', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Intercessão', 'place' => 'Interno', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Pregação', 'place' => 'Interno', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Comunicação', 'place' => 'Externo', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Infraestrutura', 'place' => 'Externo', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Recepção', 'place' => 'Externo', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Segurança', 'place' => 'Externo', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
        ];

        $this->table('sectors')->insert($data)->saveData();
    }
}
