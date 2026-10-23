<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\CampingsTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\CampingsTable Test Case
 */
class CampingsTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \App\Model\Table\CampingsTable
     */
    protected $Campings;

    /**
     * Fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'app.Campings',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('Campings') ? [] : ['className' => CampingsTable::class];
        $this->Campings = $this->getTableLocator()->get('Campings', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->Campings);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     * @link \App\Model\Table\CampingsTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
