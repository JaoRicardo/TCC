<?php
declare(strict_types=1);

use Migrations\BaseSeed;

class SelectionMethodsSeed extends BaseSeed
{
    public function run(): void
    {
        $this->getAdapter()->execute('SET FOREIGN_KEY_CHECKS = 0');
        $this->getAdapter()->execute('TRUNCATE TABLE selection_methods');
        $this->getAdapter()->execute('SET FOREIGN_KEY_CHECKS = 1');

        $data = [
            ['method' => 'Sorteio', 'description' => 'Selecionado por meio de sorteio', 'type' => 'Acampamento', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['method' => 'Forania', 'description' => 'Indicado pelo páraco', 'type' => 'Acampamento', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['method' => 'Conselho', 'description' => 'Indicado pelo conselho', 'type' => 'Acampamento', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['method' => 'Base', 'description' => 'Indicado pelo coordenador de setor', 'type' => 'Acampamento', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
        ];

        $this->table('selection_methods')->insert($data)->saveData();
    }
}
