<?php
declare(strict_types=1);

use Migrations\BaseSeed;

class SectionsSeed extends BaseSeed
{
    public function run(): void
    {
        $data = [
            ['title' => 'Informações Pessoais', 'order' => 1, 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['title' => 'Informações Espirituais', 'order' => 2, 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['title' => 'Informações de Saúde', 'order' => 3, 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
        ];

        $this->table('sections')->insert($data)->saveData();
    }
}
