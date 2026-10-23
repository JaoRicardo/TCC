<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddIsFeaturedToActivities extends BaseMigration
{
    public function change(): void
    {
        $this->table('activities')
            ->addColumn('is_featured', 'boolean', [
                'default' => false,
                'null'    => false,
                'after'   => 'total_vacancies',
            ])
            ->update();
    }
}
