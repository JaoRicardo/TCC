<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use App\Service\FormulariosService;
use Cake\Http\Response;

/**
 * Formulários de evento (modelos com nome próprio que os eventos usam).
 * As perguntas são editadas pelo QuestionsController, com event_form_id.
 */
class EventFormsController extends ApiController
{
    // GET /admin/formularios-evento — para o campo "Formulário de inscrição" de Criar/Editar Evento.
    public function index(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao(['formularios', 'respostas_formularios', 'eventos']);
        if ($identity instanceof Response) return $identity;

        $lista = $this->fetchTable('EventForms')->find()
            ->select(['id', 'name'])
            ->where(['deleted IS' => null])
            ->orderBy(['name' => 'ASC'])
            ->disableHydration()
            ->all()
            ->map(fn($f) => ['id' => (int)$f['id'], 'nome' => $f['name']])
            ->toList();

        return $this->apiResponse(['data' => $lista]);
    }

    /**
     * POST /admin/formularios-evento
     *
     * nome; copiar_de (opcional): {category_id, subscription_type} ou
     * {event_form_id} — copia todas as perguntas; eventos (opcional): ids de
     * eventos sem formulário que passam a usar este.
     */
    public function add(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $nome = trim((string)$this->request->getData('nome'));
        if ($nome === '') return $this->apiError('Dê um nome ao formulário.', 422);
        $tabela = $this->fetchTable('EventForms');
        if ($tabela->find()->where(['name' => $nome, 'deleted IS' => null])->count()) {
            return $this->apiError('Já existe um formulário de evento com esse nome.', 422);
        }

        $origem = null;
        $copiarDe = (array)($this->request->getData('copiar_de') ?? []);
        if (!empty($copiarDe['event_form_id'])) {
            $origem = FormulariosService::deEvento(intval($copiarDe['event_form_id']));
        } elseif (!empty($copiarDe['category_id'])) {
            if (!in_array($copiarDe['subscription_type'] ?? null, ['Campista', 'Servo'], true)) {
                return $this->apiError('Formulário de origem inválido', 422);
            }
            $origem = ['category_id' => intval($copiarDe['category_id']), 'subscription_type' => $copiarDe['subscription_type'], 'event_form_id' => null];
        }

        $eventos = array_values(array_filter(array_map('intval', (array)($this->request->getData('eventos') ?? []))));

        $formulario = $tabela->getConnection()->transactional(function () use ($tabela, $nome, $origem, $eventos) {
            $novo = $tabela->saveOrFail($tabela->newEntity(['name' => $nome]));

            if ($origem !== null) {
                $ids = $this->fetchTable('Questions')->find()
                    ->select(['id'])
                    ->where(FormulariosService::condicoes($origem) + ['deleted IS' => null])
                    ->all()
                    ->extract('id')
                    ->toList();
                (new FormulariosService())->copiar(array_map('intval', $ids), FormulariosService::deEvento((int)$novo->id));
            }

            if ($eventos) {
                // Só evento (sem acampamento) que ainda não tem formulário.
                $this->fetchTable('Activities')->updateAll(
                    ['event_form_id' => $novo->id],
                    ['id IN' => $eventos, 'camping_id IS' => null, 'event_form_id IS' => null, 'deleted IS' => null]
                );
            }

            return $novo;
        });

        return $this->apiResponse(['data' => ['id' => (int)$formulario->id, 'nome' => $formulario->name]], 201);
    }

    // PATCH /admin/formularios-evento/{id}  (nome)
    public function edit(int $id): Response
    {
        $this->request->allowMethod(['patch', 'put']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $tabela = $this->fetchTable('EventForms');
        $formulario = $tabela->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$formulario) return $this->apiError('Formulário de evento não encontrado', 404);

        $nome = trim((string)$this->request->getData('nome'));
        if ($nome === '') return $this->apiError('Dê um nome ao formulário.', 422);
        $formulario->name = $nome;
        $tabela->saveOrFail($formulario);

        return $this->apiResponse(['data' => ['id' => (int)$formulario->id, 'nome' => $formulario->name]]);
    }
}
