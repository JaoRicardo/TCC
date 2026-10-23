<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddAgeToCategories extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('categories');
        
        $table->addColumn('min_age', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => true,
            'after' => 'type',
        ]);
        
        $table->addColumn('max_age', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => true,
            'after' => 'min_age',
        ]);

        $table->update();
    }
}
