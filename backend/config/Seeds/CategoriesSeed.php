<?php
declare(strict_types=1);

use App\Service\CategoriasService;
use Migrations\BaseSeed;

class CategoriesSeed extends BaseSeed
{
    public function run(): void
    {
        $this->getAdapter()->execute('SET FOREIGN_KEY_CHECKS = 0');
        $this->getAdapter()->execute('TRUNCATE TABLE categories');
        $this->getAdapter()->execute('SET FOREIGN_KEY_CHECKS = 1');

        $agora = date('Y-m-d H:i:s');
        $data = [];
        $posicao = 0;
        // Categorias de acampamento, na ordem da lista, com as regras de cada uma
        // (CategoriasService::ORIGINAIS — as mesmas que os testes usam).
        foreach (CategoriasService::ORIGINAIS as $nome => $regras) {
            $data[] = [
                'name'               => $nome,
                'type'               => 'Acampamento',
                'position'           => ++$posicao,
                'min_age'            => $regras['min_age'] ?? null,
                'max_age'            => $regras['max_age'] ?? null,
                'in_hierarchy'       => (int)($regras['in_hierarchy'] ?? false),
                'couples_only'       => (int)($regras['couples_only'] ?? false),
                'accepts_married'    => (int)($regras['accepts_married'] ?? true),
                'filled_by_guardian' => (int)($regras['filled_by_guardian'] ?? false),
                'created'            => $agora,
                'modified'           => $agora,
            ];
        }
        foreach (['Evento Geral', 'Celebração'] as $nome) {
            // Mesmas colunas das de acampamento: o insert em lote usa as chaves da primeira linha.
            $data[] = [
                'name' => $nome, 'type' => 'Evento', 'position' => 0, 'min_age' => null, 'max_age' => null,
                'in_hierarchy' => 0, 'couples_only' => 0, 'accepts_married' => 1, 'filled_by_guardian' => 0,
                'created' => $agora, 'modified' => $agora,
            ];
        }

        $this->table('categories')->insert($data)->saveData();

        foreach (CategoriasService::ORIGINAIS as $nome => $regras) {
            if (isset($regras['requer'])) {
                $this->execute(
                    "UPDATE categories d JOIN categories r ON r.name = ? AND r.type = 'Acampamento'
                     SET d.required_category_id = r.id WHERE d.name = ? AND d.type = 'Acampamento'",
                    [$regras['requer'], $nome],
                );
            }
        }
    }
}
