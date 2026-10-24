<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use Cake\Http\Response;

#[AllowDynamicProperties]
class QuestionsController extends ApiController
{
    // Tipos de resposta suportados pelo form builder. Os 4 primeiros usam texto
    // livre; os 4 últimos usam opções (sim_nao gera Sim/Não sozinho, os outros 3
    // pedem uma lista de opções do próprio admin).
    private const TIPOS_TEXTO   = ['texto_curto', 'texto_longo', 'numero', 'data'];
    private const TIPOS_OPCOES  = ['sim_nao', 'unica_escolha', 'multipla_escolha', 'lista_suspensa'];
    private const TIPOS_VALIDOS = [...self::TIPOS_TEXTO, ...self::TIPOS_OPCOES];

    public function initialize(): void
    {
        parent::initialize();
        $this->Questions = $this->fetchTable('Questions');
        $this->Options   = $this->fetchTable('Options');
        $this->Sections  = $this->fetchTable('Sections');
    }

    // GET /admin/perguntas?category_id=&subscription_type=
    public function index(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $categoryId       = intval($this->request->getQuery('category_id') ?? 0);
        $subscriptionType = $this->request->getQuery('subscription_type');

        if (!$categoryId || !in_array($subscriptionType, ['Campista', 'Servo'], true)) {
            return $this->apiError('Informe category_id e subscription_type (Campista|Servo)', 422);
        }

        $questions = $this->Questions->find()
            ->contain(['Options', 'Sections'])
            ->where([
                'Questions.category_id'         => $categoryId,
                'Questions.subscription_type'   => $subscriptionType,
                'Questions.deleted IS'           => null,
            ])
            ->orderBy(['Sections.order' => 'ASC', 'Questions.order' => 'ASC'])
            ->all()
            ->toArray();

        return $this->apiResponse([
            'data' => array_map([$this, 'serializePergunta'], $questions),
        ]);
    }

    // POST /admin/perguntas
    public function add(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $data             = $this->request->getData();
        $categoryId       = intval($data['category_id'] ?? 0);
        $subscriptionType = $data['subscription_type'] ?? null;
        $texto            = trim((string)($data['text'] ?? ''));
        $tipo             = $data['type'] ?? null;
        $isRequired       = (bool)($data['is_required'] ?? true);
        $sectionId        = intval($data['section_id'] ?? 0);
        $sectionNome      = trim((string)($data['section_nome'] ?? ''));
        $dependsOnOptionId = !empty($data['depends_on_option_id']) ? intval($data['depends_on_option_id']) : null;
        $opcoes           = array_values(array_filter(array_map('trim', (array)($data['options'] ?? []))));

        if (!$categoryId || !in_array($subscriptionType, ['Campista', 'Servo'], true)) {
            return $this->apiError('Informe category_id e subscription_type (Campista|Servo)', 422);
        }
        if ($texto === '' || !in_array($tipo, self::TIPOS_VALIDOS, true)) {
            return $this->apiError('Informe o texto e um tipo válido (' . implode(', ', self::TIPOS_VALIDOS) . ')', 422);
        }
        if (in_array($tipo, ['unica_escolha', 'multipla_escolha', 'lista_suspensa'], true) && count($opcoes) < 2) {
            return $this->apiError('Perguntas de opção precisam de pelo menos 2 opções', 422);
        }

        if (!$sectionId) {
            if ($sectionNome === '') {
                return $this->apiError('Informe a seção (section_id) ou um nome para criar uma nova', 422);
            }
            $section = $this->Sections->find()->where(['title' => $sectionNome, 'deleted IS' => null])->first();
            if (!$section) {
                $maxOrd  = $this->Sections->find()->select(['order'])->orderBy(['order' => 'DESC'])->first();
                $section = $this->Sections->newEntity(['title' => $sectionNome, 'order' => ($maxOrd->order ?? 0) + 1]);
                $this->Sections->save($section);
            }
            $sectionId = $section->id;
        }

        $maxOrd = $this->Questions->find()
            ->select(['order'])
            ->where(['category_id' => $categoryId, 'subscription_type' => $subscriptionType, 'section_id' => $sectionId, 'deleted IS' => null])
            ->orderBy(['order' => 'DESC'])
            ->first();

        $question = $this->Questions->newEntity([
            'category_id'          => $categoryId,
            'subscription_type'    => $subscriptionType,
            'text'                 => $texto,
            'type'                 => $tipo,
            'is_required'          => $isRequired,
            'section_id'           => $sectionId,
            'order'                => ($maxOrd->order ?? 0) + 1,
            'accept_generic_answer' => false,
            'depends_on_option_id' => $dependsOnOptionId,
        ]);

        if (!$this->Questions->save($question)) {
            return $this->apiResponse(['error' => 'Erro ao salvar a pergunta', 'errors' => $question->getErrors()], 422);
        }

        $this->salvarOpcoes($question->id, $tipo, $opcoes);

        $full = $this->Questions->find()->contain(['Options', 'Sections'])->where(['Questions.id' => $question->id])->first();

        return $this->apiResponse(['data' => $this->serializePergunta($full)], 201);
    }

    // PATCH /admin/perguntas/{id}
    public function edit(int $id): Response
    {
        $this->request->allowMethod(['patch', 'put']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $question = $this->Questions->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$question) return $this->apiError('Pergunta não encontrada', 404);

        $data = $this->request->getData();
        $patch = [];

        if (isset($data['text']))        $patch['text']        = trim((string)$data['text']);
        if (isset($data['is_required'])) $patch['is_required'] = (bool)$data['is_required'];
        if (isset($data['order']))       $patch['order']       = intval($data['order']);
        if (isset($data['section_id']))  $patch['section_id']  = intval($data['section_id']);
        if (array_key_exists('depends_on_option_id', $data)) {
            $patch['depends_on_option_id'] = $data['depends_on_option_id'] ? intval($data['depends_on_option_id']) : null;
        }

        $tipo = $question->type;
        if (isset($data['type'])) {
            if (!in_array($data['type'], self::TIPOS_VALIDOS, true)) {
                return $this->apiError('Tipo inválido', 422);
            }
            $tipo = $data['type'];
            $patch['type'] = $tipo;
        }

        if (isset($data['options'])) {
            $opcoes = array_values(array_filter(array_map('trim', (array)$data['options'])));
            if (in_array($tipo, ['unica_escolha', 'multipla_escolha', 'lista_suspensa'], true) && count($opcoes) < 2) {
                return $this->apiError('Perguntas de opção precisam de pelo menos 2 opções', 422);
            }
            $this->Options->deleteAll(['question_id' => $id]);
            $this->salvarOpcoes($id, $tipo, $opcoes);
        }

        $this->Questions->patchEntity($question, $patch);
        if (!$this->Questions->save($question)) {
            return $this->apiResponse(['error' => 'Erro ao atualizar', 'errors' => $question->getErrors()], 422);
        }

        $full = $this->Questions->find()->contain(['Options', 'Sections'])->where(['Questions.id' => $id])->first();

        return $this->apiResponse(['data' => $this->serializePergunta($full)]);
    }

    // DELETE /admin/perguntas/{id}
    public function delete(int $id): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $question = $this->Questions->find()->contain(['Options'])->where(['Questions.id' => $id, 'Questions.deleted IS' => null])->first();
        if (!$question) return $this->apiError('Pergunta não encontrada', 404);

        $now = date('Y-m-d H:i:s');
        $optionIds = array_map(fn($o) => $o->id, $question->options ?? []);

        if (!empty($optionIds)) {
            $this->Questions->updateAll(['deleted' => $now], ['depends_on_option_id IN' => $optionIds, 'deleted IS' => null]);
        }
        $this->Questions->updateAll(['deleted' => $now], ['id' => $id]);

        return $this->apiResponse(['deleted' => true]);
    }

    // GET /admin/secoes
    public function secoes(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $secoes = $this->Sections->find()->where(['deleted IS' => null])->orderBy(['order' => 'ASC'])->all();

        return $this->apiResponse([
            'data' => array_map(fn($s) => ['id' => $s->id, 'titulo' => $s->title, 'ordem' => $s->order], $secoes->toArray()),
        ]);
    }

    // POST /admin/secoes
    public function criarSecao(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $titulo = trim((string)($this->request->getData('titulo') ?? ''));
        if ($titulo === '') return $this->apiError('Informe o título da seção', 422);

        $existing = $this->Sections->find()->where(['title' => $titulo, 'deleted IS' => null])->first();
        if ($existing) {
            return $this->apiResponse(['data' => ['id' => $existing->id, 'titulo' => $existing->title, 'ordem' => $existing->order]]);
        }

        $maxOrd = $this->Sections->find()->select(['order'])->orderBy(['order' => 'DESC'])->first();
        $secao  = $this->Sections->newEntity(['title' => $titulo, 'order' => ($maxOrd->order ?? 0) + 1]);
        if (!$this->Sections->save($secao)) return $this->apiError('Erro ao criar seção', 500);

        return $this->apiResponse(['data' => ['id' => $secao->id, 'titulo' => $secao->title, 'ordem' => $secao->order]], 201);
    }

    // PATCH /admin/secoes/{id}
    public function editarSecao(int $id): Response
    {
        $this->request->allowMethod(['patch', 'put']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $secao = $this->Sections->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$secao) return $this->apiError('Seção não encontrada', 404);

        $data  = $this->request->getData();
        $patch = [];
        if (isset($data['titulo'])) $patch['title'] = trim((string)$data['titulo']);
        if (isset($data['ordem']))  $patch['order'] = intval($data['ordem']);

        $this->Sections->patchEntity($secao, $patch);
        if (!$this->Sections->save($secao)) return $this->apiError('Erro ao atualizar seção', 422);

        return $this->apiResponse(['data' => ['id' => $secao->id, 'titulo' => $secao->title, 'ordem' => $secao->order]]);
    }

    // DELETE /admin/secoes/{id}
    public function excluirSecao(int $id): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $count = $this->Questions->find()->where(['section_id' => $id, 'deleted IS' => null])->count();
        if ($count > 0) {
            return $this->apiError("Não é possível excluir: existem {$count} pergunta(s) nesta seção.", 422);
        }

        $this->Sections->updateAll(['deleted' => date('Y-m-d H:i:s')], ['id' => $id, 'deleted IS' => null]);

        return $this->apiResponse(['deleted' => true]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function salvarOpcoes(int $questionId, string $tipo, array $opcoes): void
    {
        if ($tipo === 'sim_nao') {
            $this->Options->save($this->Options->newEntity(['text' => 'Sim', 'question_id' => $questionId]));
            $this->Options->save($this->Options->newEntity(['text' => 'Não', 'question_id' => $questionId]));
            return;
        }
        if (!in_array($tipo, ['unica_escolha', 'multipla_escolha', 'lista_suspensa'], true)) return;

        foreach ($opcoes as $texto) {
            $this->Options->save($this->Options->newEntity(['text' => $texto, 'question_id' => $questionId]));
        }
    }

    private function serializePergunta(object $q): array
    {
        $opts = is_array($q->options ?? null) ? $q->options : ($q->options?->toArray() ?? []);

        return [
            'id'                   => $q->id,
            'category_id'          => $q->category_id,
            'subscription_type'    => $q->subscription_type,
            'text'                 => $q->text,
            'type'                 => $q->type,
            'is_required'          => (bool)$q->is_required,
            'order'                => $q->order,
            'depends_on_option_id' => $q->depends_on_option_id,
            'section'              => ['id' => $q->section->id ?? 0, 'titulo' => $q->section->title ?? ''],
            'options'              => array_map(fn($o) => ['id' => $o->id, 'text' => $o->text], $opts),
        ];
    }
}
