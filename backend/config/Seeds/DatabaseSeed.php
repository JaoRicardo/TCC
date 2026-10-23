<?php
declare(strict_types=1);

use Migrations\BaseSeed;

class DatabaseSeed extends BaseSeed
{
    public function run(): void
    {
        $this->call('MaritalStatusesSeed');
        $this->call('SectorsSeed');
        $this->call('SelectionMethodsSeed');
        $this->call('CategoriesSeed');
        $this->call('SectionsSeed');
        $this->call('FormulariosSeed');
        $this->call('UsersSeed');
    }
}
