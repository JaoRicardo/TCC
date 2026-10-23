<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\SelectionMethodsTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\SelectionMethodsTable Test Case
 */
class SelectionMethodsTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \App\Model\Table\SelectionMethodsTable
     */
    protected $SelectionMethods;

    /**
     * Fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'app.SelectionMethods',
        'app.CampingPreRegistration',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('SelectionMethods') ? [] : ['className' => SelectionMethodsTable::class];
        $this->SelectionMethods = $this->getTableLocator()->get('SelectionMethods', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->SelectionMethods);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     * @link \App\Model\Table\SelectionMethodsTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
