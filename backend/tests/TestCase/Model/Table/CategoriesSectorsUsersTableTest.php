<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\CategoriesSectorsUsersTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\CategoriesSectorsUsersTable Test Case
 */
class CategoriesSectorsUsersTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \App\Model\Table\CategoriesSectorsUsersTable
     */
    protected $CategoriesSectorsUsers;

    /**
     * Fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'app.CategoriesSectorsUsers',
        'app.Users',
        'app.CategoriesSectors',
        'app.Activities',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('CategoriesSectorsUsers') ? [] : ['className' => CategoriesSectorsUsersTable::class];
        $this->CategoriesSectorsUsers = $this->getTableLocator()->get('CategoriesSectorsUsers', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->CategoriesSectorsUsers);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     * @link \App\Model\Table\CategoriesSectorsUsersTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test buildRules method
     *
     * @return void
     * @link \App\Model\Table\CategoriesSectorsUsersTable::buildRules()
     */
    public function testBuildRules(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
