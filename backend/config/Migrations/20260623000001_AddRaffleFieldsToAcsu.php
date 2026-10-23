<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddRaffleFieldsToAcsu extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('activities_categories_sectors_users');
        $table
            ->addColumn('raffle_position', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'is_coordinator',
            ])
            ->addColumn('is_substitute', 'tinyinteger', [
                'null' => true,
                'default' => null,
                'after' => 'raffle_position',
            ])
            ->update();
    }
}
