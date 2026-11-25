
<?php
/**
 * Routes configuration.
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * It's loaded within the context of `Application::routes()` method which
 * receives a `RouteBuilder` instance `$routes` as method argument.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;
use Cake\Routing\Route\Route;

/*
 * This file is loaded in the context of the `Application` class.
 * So you can use `$this` to reference the application class instance
 * if required.
 */
return function (RouteBuilder $routes): void {
    /*
     * The default class to use for all routes
     *
     * The following route classes are supplied with CakePHP and are appropriate
     * to set as the default:
     *
     * - Route
     * - InflectedRoute
     * - DashedRoute
     *
     * If no call is made to `Router::defaultRouteClass()`, the class used is
     * `Route` (`Cake\Routing\Route\Route`)
     *
     * Note that `Route` does not do any inflections on URLs which will result in
     * inconsistently cased URLs when used with `{plugin}`, `{controller}` and
     * `{action}` markers.
     */
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        /*
         * Here, we are connecting '/' (base path) to a controller called 'Pages',
         * its action called 'display', and we pass a param to select the view file
         * to use (in this case, templates/Pages/home.php)...
         */
        $builder->connect('/', ['controller' => 'Pages', 'action' => 'display', 'home']);

        /*
         * ...and connect the rest of 'Pages' controller's URLs.
         */
        $builder->connect('/pages/*', 'Pages::display');

        /*
         * Connect catchall routes for all controllers.
         *
         * The `fallbacks` method is a shortcut for
         *
         * ```
         * $builder->connect('/{controller}', ['action' => 'index']);
         * $builder->connect('/{controller}/{action}/*', []);
         * ```
         *
         * It is NOT recommended to use fallback routes after your initial prototyping phase!
         * See https://book.cakephp.org/5/en/development/routing.html#fallbacks-method for more information
         */
        $builder->fallbacks();
    });

    $routes->prefix('Api', ['path' => '/api'], function (RouteBuilder $builder): void {
        $builder->prefix('V1', ['path' => '/v1'], function (RouteBuilder $builder): void {
            $builder->setExtensions(['json']);

            // --- Dados de referência (públicos) ---
            $builder->get('/marital-statuses', ['controller' => 'MaritalStatuses', 'action' => 'index']);

            // Categorias
            $builder->get('/categories', ['controller' => 'Categories', 'action' => 'index']);

            // Activities
            $builder->get('/activities', ['controller' => 'Activities', 'action' => 'index']);
            $builder->get('/activities/{id}', ['controller' => 'Activities', 'action' => 'view'])->setPass(['id']);
            $builder->get('/activities/{id}/edit', ['controller' => 'Activities', 'action' => 'editData'])->setPass(['id']);
            // Tela Criar / Editar Acampamento: formulários e setores de cada categoria.
            $builder->get('/admin/acampamentos/apoio', ['controller' => 'Activities', 'action' => 'apoioAcampamento']);
            // Gestão de Atividades: inscritos e pagamentos pendentes de cada atividade.
            $builder->get('/admin/atividades/resumo', ['controller' => 'Activities', 'action' => 'resumoDoPainel']);
            $builder->get('/activities/{id}/elegibilidade', ['controller' => 'Inscricoes', 'action' => 'elegibilidade'])->setPass(['id']);
            $builder->post('/activities', ['controller' => 'Activities', 'action' => 'add']);
            $builder->patch('/activities/{id}', ['controller' => 'Activities', 'action' => 'edit'])->setPass(['id']);
            $builder->patch('/activities/{id}/feature', ['controller' => 'Activities', 'action' => 'feature'])->setPass(['id']);
            $builder->delete('/activities/{id}', ['controller' => 'Activities', 'action' => 'delete'])->setPass(['id']);

            // Activity Sectors
            $builder->get('/activities/{id}/sectors', ['controller' => 'Activities', 'action' => 'sectors'])->setPass(['id']);
            $builder->post('/activities/{id}/sectors/aplicar-modelo', ['controller' => 'Activities', 'action' => 'aplicarModelo'])->setPass(['id']);
            $builder->post('/activities/{id}/sectors', ['controller' => 'Activities', 'action' => 'addSector'])->setPass(['id']);
            $builder->patch('/activities/{activityId}/sectors/{acsId}', ['controller' => 'Activities', 'action' => 'updateSector'])->setPass(['activityId', 'acsId']);
            $builder->delete('/activities/{activityId}/sectors/{acsId}', ['controller' => 'Activities', 'action' => 'deleteSector'])->setPass(['activityId', 'acsId']);
            $builder->post('/activities/{activityId}/sectors/{acsId}/members', ['controller' => 'Activities', 'action' => 'addSectorMember'])->setPass(['activityId', 'acsId']);
            $builder->delete('/activities/{activityId}/sectors/{acsId}/members/{userId}', ['controller' => 'Activities', 'action' => 'removeSectorMember'])->setPass(['activityId', 'acsId', 'userId']);
            $builder->patch('/activities/{activityId}/sectors/{acsId}/members/{userId}', ['controller' => 'Activities', 'action' => 'updateSectorMember'])->setPass(['activityId', 'acsId', 'userId']);
            $builder->post('/activities/{activityId}/sectors/{acsId}/members/{userId}/mover', ['controller' => 'Activities', 'action' => 'moverSectorMember'])->setPass(['activityId', 'acsId', 'userId']);

            // Servo geral da atividade
            $builder->post('/activities/{id}/general-servants', ['controller' => 'Activities', 'action' => 'addGeneralServant'])->setPass(['id']);
            $builder->delete('/activities/{id}/general-servants/{userId}', ['controller' => 'Activities', 'action' => 'removeGeneralServant'])->setPass(['id', 'userId']);

            // Papéis de servo do usuário logado
            $builder->get('/me/equipes', ['controller' => 'Activities', 'action' => 'minhasEquipes']);

            // --- Admin: perguntas e seções do formulário ---
            $builder->get('/admin/perguntas', ['controller' => 'Questions', 'action' => 'index']);
            $builder->post('/admin/perguntas', ['controller' => 'Questions', 'action' => 'add']);
            $builder->patch('/admin/perguntas/{id}', ['controller' => 'Questions', 'action' => 'edit'])->setPass(['id']);
            $builder->delete('/admin/perguntas/{id}', ['controller' => 'Questions', 'action' => 'delete'])->setPass(['id']);
            $builder->post('/admin/perguntas/{id}/restaurar', ['controller' => 'Questions', 'action' => 'restaurar'])->setPass(['id']);
            $builder->get('/admin/formularios/resumo', ['controller' => 'Questions', 'action' => 'resumo']);
            $builder->post('/admin/formularios/importar', ['controller' => 'Questions', 'action' => 'importar']);
            $builder->get('/admin/formularios/respostas', ['controller' => 'Respostas', 'action' => 'index']);
            // Pedir correção do formulário e cancelar inscrição pela organização.
            $builder->post('/admin/inscricoes/{id}/correcao', ['controller' => 'Respostas', 'action' => 'pedirCorrecao'])->setPass(['id']);
            $builder->delete('/admin/inscricoes/{id}/correcao', ['controller' => 'Respostas', 'action' => 'desfazerCorrecao'])->setPass(['id']);
            $builder->post('/admin/inscricoes/{id}/cancelar', ['controller' => 'Respostas', 'action' => 'cancelar'])->setPass(['id']);
            // Pagamento registrado à mão pela organização (Inscritos e Sorteios › Pagamentos).
            $builder->post('/admin/inscricoes/{id}/pagamento', ['controller' => 'Pagamentos', 'action' => 'registrar'])->setPass(['id']);
            $builder->delete('/admin/inscricoes/{id}/pagamento', ['controller' => 'Pagamentos', 'action' => 'desfazer'])->setPass(['id']);
            $builder->get('/admin/formularios-evento', ['controller' => 'EventForms', 'action' => 'index']);
            $builder->post('/admin/formularios-evento', ['controller' => 'EventForms', 'action' => 'add']);
            $builder->patch('/admin/formularios-evento/{id}', ['controller' => 'EventForms', 'action' => 'edit'])->setPass(['id']);

            // --- Arquivos das respostas (enviados em /inscricoes/{id}/arquivo) ---
            $builder->get('/admin/documentos/{id}/arquivo', ['controller' => 'Documentos', 'action' => 'arquivo'])->setPass(['id']);
            $builder->get('/admin/secoes', ['controller' => 'Questions', 'action' => 'secoes']);
            $builder->post('/admin/secoes', ['controller' => 'Questions', 'action' => 'criarSecao']);
            $builder->patch('/admin/secoes/{id}', ['controller' => 'Questions', 'action' => 'editarSecao'])->setPass(['id']);
            $builder->delete('/admin/secoes/{id}', ['controller' => 'Questions', 'action' => 'excluirSecao'])->setPass(['id']);
            $builder->post('/admin/secoes/{id}/excluir-perguntas', ['controller' => 'Questions', 'action' => 'excluirPerguntasDaSecao'])->setPass(['id']);
            $builder->post('/admin/secoes/{id}/restaurar-perguntas', ['controller' => 'Questions', 'action' => 'restaurarPerguntasDaSecao'])->setPass(['id']);

            // --- Admin: Gestão › Categorias (permissão "categorias") ---
            $builder->get('/admin/categorias', ['controller' => 'Categories', 'action' => 'lista']);
            $builder->post('/admin/categorias', ['controller' => 'Categories', 'action' => 'criar']);
            $builder->post('/admin/categorias/ordem', ['controller' => 'Categories', 'action' => 'ordem']);
            $builder->patch('/admin/categorias/{id}', ['controller' => 'Categories', 'action' => 'editar'])->setPass(['id'])->setPatterns(['id' => '\d+']);
            $builder->delete('/admin/categorias/{id}', ['controller' => 'Categories', 'action' => 'excluir'])->setPass(['id'])->setPatterns(['id' => '\d+']);
            $builder->post('/admin/categorias/{id}/arquivar', ['controller' => 'Categories', 'action' => 'arquivar'])->setPass(['id'])->setPatterns(['id' => '\d+']);
            $builder->post('/admin/categorias/{id}/desarquivar', ['controller' => 'Categories', 'action' => 'desarquivar'])->setPass(['id'])->setPatterns(['id' => '\d+']);

            // --- Admin: métodos de seleção/indicação ---
            $builder->get('/admin/selection-methods', ['controller' => 'SelectionMethods', 'action' => 'index']);

            // --- Admin: setores ---
            $builder->get('/admin/sectors', ['controller' => 'Sectors', 'action' => 'index']);
            $builder->post('/admin/sectors', ['controller' => 'Sectors', 'action' => 'add']);
            $builder->patch('/admin/sectors/{id}', ['controller' => 'Sectors', 'action' => 'edit'])->setPass(['id']);
            $builder->delete('/admin/sectors/{id}', ['controller' => 'Sectors', 'action' => 'delete'])->setPass(['id']);
            $builder->post('/admin/sectors/{id}/categories', ['controller' => 'Sectors', 'action' => 'addCategory'])->setPass(['id']);
            $builder->patch('/admin/categories-sectors/{csId}', ['controller' => 'Sectors', 'action' => 'editCategory'])->setPass(['csId']);
            $builder->delete('/admin/categories-sectors/{csId}', ['controller' => 'Sectors', 'action' => 'deleteCategory'])->setPass(['csId']);

            // Sorteio
            $builder->get('/activities/{id}/sorteio', ['controller' => 'Sorteio', 'action' => 'resultado'])->setPass(['id']);
            $builder->post('/activities/{id}/sorteio/campistas', ['controller' => 'Sorteio', 'action' => 'realizarCampistas'])->setPass(['id']);
            $builder->delete('/activities/{id}/sorteio/campistas', ['controller' => 'Sorteio', 'action' => 'resetarCampistas'])->setPass(['id']);
            $builder->post('/activities/{id}/sorteio/servos', ['controller' => 'Sorteio', 'action' => 'realizarServos'])->setPass(['id']);
            $builder->delete('/activities/{id}/sorteio/servos', ['controller' => 'Sorteio', 'action' => 'resetarServos'])->setPass(['id']);
            $builder->get('/admin/users/{id}/permissoes', ['controller' => 'Users', 'action' => 'adminPermissoes'])->setPass(['id']);
            $builder->put('/admin/users/{id}/permissoes', ['controller' => 'Users', 'action' => 'adminSalvarPermissoes'])->setPass(['id']);
            $builder->post('/activities/{id}/sorteio/chamar-proximo', ['controller' => 'Sorteio', 'action' => 'chamarProximo'])->setPass(['id']);

            // Inscrições
            $builder->get('/activities/{id}/subscriptions', ['controller' => 'Inscricoes', 'action' => 'adminList'])->setPass(['id']);
            $builder->post('/activities/{id}/indicate', ['controller' => 'Inscricoes', 'action' => 'indicate'])->setPass(['id']);
            $builder->delete('/inscricoes/{id}/indicacao', ['controller' => 'Inscricoes', 'action' => 'removeIndication'])->setPass(['id']);
            $builder->post('/activities/{id}/inscricoes', ['controller' => 'Inscricoes', 'action' => 'subscribe'])->setPass(['id']);
            $builder->delete('/inscricoes/{id}', ['controller' => 'Inscricoes', 'action' => 'cancel'])->setPass(['id']);
            $builder->get('/me/inscricoes', ['controller' => 'Inscricoes', 'action' => 'minhas']);
            $builder->get('/inscricoes/{id}/formulario', ['controller' => 'Inscricoes', 'action' => 'formulario'])->setPass(['id']);
            $builder->post('/inscricoes/{id}/formulario', ['controller' => 'Inscricoes', 'action' => 'salvarFormulario'])->setPass(['id']);
            $builder->post('/inscricoes/{id}/arquivo', ['controller' => 'Inscricoes', 'action' => 'enviarArquivo'])->setPass(['id']);

            // --- Autenticação (públicas) ---
            $builder->post('/auth/login', ['controller' => 'Auth', 'action' => 'login']);
            $builder->post('/auth/refresh', ['controller' => 'Auth', 'action' => 'refresh']);

            // --- Autenticação (protegidas — requerem Bearer token) ---
            $builder->post('/auth/logout', ['controller' => 'Auth', 'action' => 'logout']);
            $builder->get('/auth/me', ['controller' => 'Auth', 'action' => 'me']);

            // --- Usuários ---
            $builder->post('/users', ['controller' => 'Users', 'action' => 'add']);        // cadastro (público)
            $builder->get('/users/me', ['controller' => 'Users', 'action' => 'me']);       // perfil completo (protegido)
            $builder->patch('/users/me', ['controller' => 'Users', 'action' => 'atualizarMe']); // "Meu cadastro"
            $builder->post('/users/me/senha', ['controller' => 'Users', 'action' => 'trocarSenha']);

            // --- Admin: usuários ---
            $builder->get('/admin/users', ['controller' => 'Users', 'action' => 'adminIndex']);
            $builder->get('/admin/users/{id}', ['controller' => 'Users', 'action' => 'adminFicha'])->setPass(['id']); // ficha da pessoa
            $builder->post('/admin/users/{id}/senha-provisoria', ['controller' => 'Users', 'action' => 'adminSenhaProvisoria'])->setPass(['id']);
            $builder->get('/admin/users/{id}/inscricoes', ['controller' => 'Inscricoes', 'action' => 'daPessoa'])->setPass(['id']);
            $builder->get('/admin/users/{id}/alteracoes', ['controller' => 'Users', 'action' => 'adminAlteracoes'])->setPass(['id']); // registro de alterações
            $builder->patch('/admin/users/{id}', ['controller' => 'Users', 'action' => 'adminEdit'])->setPass(['id']);
            $builder->post('/admin/users/{id}/previa', ['controller' => 'Users', 'action' => 'adminPrevia'])->setPass(['id']); // "Conferir antes de salvar"
            $builder->delete('/admin/users/{id}', ['controller' => 'Users', 'action' => 'adminDelete'])->setPass(['id']);
        });
    });
};
