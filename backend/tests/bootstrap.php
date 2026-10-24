<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     3.0.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Chronos\Chronos;
use Cake\Core\Configure;
use Cake\TestSuite\ConnectionHelper;
use Migrations\TestSuite\Migrator;

/**
 * Test runner bootstrap.
 *
 * Add additional configuration/setup your application needs when running
 * unit tests in this file.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

require dirname(__DIR__) . '/config/bootstrap.php';

if (empty($_SERVER['HTTP_HOST']) && !Configure::read('App.fullBaseUrl')) {
    Configure::write('App.fullBaseUrl', 'http://localhost');
}

// Fixate now to avoid one-second-leap-issues
Chronos::setTestNow(Chronos::now());

// Fixate sessionid early on, as php7.2+
// does not allow the sessionid to be set after stdout
// has been written to.
session_id('cli');

// Trava de segurança: os testes apagam e recriam o banco de teste. Se ele estiver
// apontando para o mesmo banco da aplicação, para aqui antes de mexer em qualquer coisa.
$bancoReal = \Cake\Datasource\ConnectionManager::getConfig('default')['database'] ?? null;
$bancoTeste = \Cake\Datasource\ConnectionManager::getConfig('test')['database'] ?? null;
if ($bancoTeste === null || $bancoTeste === $bancoReal) {
    fwrite(STDERR, "Testes abortados: o banco de teste ('{$bancoTeste}') não pode ser o banco da aplicação ('{$bancoReal}'). Veja DATABASE_TEST_NAME no config/.env.\n");
    exit(1);
}

// Connection aliasing needs to happen before migrations are run.
// Otherwise, table objects inside migrations would use the default datasource
ConnectionHelper::addTestAliases();

// Use migrations to build test database schema.
//
// Will rebuild the database if the migration state differs
// from the migration history in files.
//
// If you are not using CakePHP's migrations you can
// hook into your migration tool of choice here or
// load schema from a SQL dump file with
// use Cake\TestSuite\Fixture\SchemaLoader;
// (new SchemaLoader())->loadSqlFiles('./tests/schema.sql', 'test');

(new Migrator())->run();

// O CakePHP guarda a estrutura das tabelas em cache (tmp/cache/models). Depois de
// uma migration nova, o cache do banco de teste ficava com a estrutura antiga e
// as colunas novas eram ignoradas nos testes. Limpa a cada execução.
\Cake\Cache\Cache::clear('_cake_model_');
