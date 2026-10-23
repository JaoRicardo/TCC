<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MergeSectorVacancyFields extends BaseMigration
{
    public function up(): void
    {
        $this->table('categories_sectors')
            ->addColumn('vacancies', 'integer', ['null' => false, 'default' => 0, 'after' => 'sector_id'])
            ->update();
        $this->execute('UPDATE categories_sectors SET vacancies = base_vacancies + raffle_vacancies');
        $this->table('categories_sectors')
            ->removeColumn('base_vacancies')
            ->removeColumn('raffle_vacancies')
            ->update();

        $this->table('activities_categories_sectors')
            ->addColumn('actual_vacancies', 'integer', ['null' => false, 'default' => 0, 'after' => 'activity_id'])
            ->update();
        $this->execute('UPDATE activities_categories_sectors SET actual_vacancies = actual_base_vacancies + actual_raffle_vacancies');
        $this->table('activities_categories_sectors')
            ->removeColumn('actual_base_vacancies')
            ->removeColumn('actual_raffle_vacancies')
            ->update();

        $this->table('activities_categories_sectors_users')
            ->addColumn('is_indicated', 'boolean', ['null' => false, 'default' => false, 'after' => 'is_coordinator'])
            ->update();
    }

    public function down(): void
    {
        $this->table('categories_sectors')
            ->addColumn('base_vacancies', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('raffle_vacancies', 'integer', ['null' => false, 'default' => 0])
            ->update();
        $this->execute('UPDATE categories_sectors SET base_vacancies = vacancies, raffle_vacancies = 0');
        $this->table('categories_sectors')->removeColumn('vacancies')->update();

        $this->table('activities_categories_sectors')
            ->addColumn('actual_base_vacancies', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('actual_raffle_vacancies', 'integer', ['null' => false, 'default' => 0])
            ->update();
        $this->execute('UPDATE activities_categories_sectors SET actual_base_vacancies = actual_vacancies, actual_raffle_vacancies = 0');
        $this->table('activities_categories_sectors')->removeColumn('actual_vacancies')->update();

        $this->table('activities_categories_sectors_users')->removeColumn('is_indicated')->update();
    }
}
