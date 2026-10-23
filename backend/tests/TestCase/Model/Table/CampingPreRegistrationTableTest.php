<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\CampingPreRegistrationTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\CampingPreRegistrationTable Test Case
 */
class CampingPreRegistrationTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \App\Model\Table\CampingPreRegistrationTable
     */
    protected $CampingPreRegistration;

    /**
     * Fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'app.CampingPreRegistration',
        'app.SelectionMethods',
        'app.Spouses',
        'app.Sectors',
        'app.Sector2s',
        'app.PreRegistrations',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('CampingPreRegistration') ? [] : ['className' => CampingPreRegistrationTable::class];
        $this->CampingPreRegistration = $this->getTableLocator()->get('CampingPreRegistration', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->CampingPreRegistration);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     * @link \App\Model\Table\CampingPreRegistrationTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test buildRules method
     *
     * @return void
     * @link \App\Model\Table\CampingPreRegistrationTable::buildRules()
     */
    public function testBuildRules(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
