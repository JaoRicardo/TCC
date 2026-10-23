<?php
declare(strict_types=1);
use Migrations\BaseMigration;
class DropImageFromCampings extends BaseMigration {
    public function change(): void {
        $table = $this->table('campings');
        if ($table->hasColumn('image')) {
            $table->removeColumn('image')->update();
        }
    }
}
