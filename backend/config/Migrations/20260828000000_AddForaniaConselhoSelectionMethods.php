<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddForaniaConselhoSelectionMethods extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $this->fetchAll("SELECT method FROM selection_methods WHERE method IN ('Forania', 'Conselho')");
        $existingMethods = array_column($existing, 'method');

        $data = [];
        if (!in_array('Forania', $existingMethods, true)) {
            $data[] = [
                'method' => 'Forania',
                'description' => 'Indicado pelo pároco',
                'type' => 'Acampamento',
                'created' => $now,
                'modified' => $now,
            ];
        }
        if (!in_array('Conselho', $existingMethods, true)) {
            $data[] = [
                'method' => 'Conselho',
                'description' => 'Indicado pelo conselho',
                'type' => 'Acampamento',
                'created' => $now,
                'modified' => $now,
            ];
        }

        if ($data) {
            $this->table('selection_methods')->insert($data)->saveData();
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM selection_methods WHERE method IN ('Forania', 'Conselho')");
    }
}
