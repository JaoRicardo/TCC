<?php
declare(strict_types=1);
namespace App\Controller\Api\V1;
use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use Cake\Http\Response;

#[AllowDynamicProperties]
class CategoriesController extends ApiController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->Categories = $this->fetchTable('Categories');
    }

    public function index(): Response
    {
        $this->request->allowMethod(['get']);
        $cats = $this->Categories->find()
            ->where(['deleted IS' => null])
            ->orderBy(['type' => 'ASC', 'name' => 'ASC'])
            ->all()
            ->map(fn($c) => [
                'id'          => $c->id,
                'name'        => $c->name,
                'type'        => $c->type,
                'minimal_age' => $c->minimal_age,
                'maximal_age' => $c->maximal_age,
            ])
            ->toArray();
        return $this->apiResponse(['data' => array_values($cats)]);
    }

    public function add(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('acampamentos');
        if ($identity instanceof Response) return $identity;

        $data = $this->request->getData();
        $name   = trim((string)($data['name']   ?? ''));
        $type   = trim((string)($data['type']   ?? ''));
        $minAge = isset($data['minimal_age']) ? (int)$data['minimal_age'] : null;
        $maxAge = isset($data['maximal_age']) ? (int)$data['maximal_age'] : null;

        if ($name === '') {
            return $this->apiError('Informe o nome da categoria', 400);
        }
        if (!in_array($type, ['Acampamento', 'Evento'], true)) {
            return $this->apiError('Tipo deve ser "Acampamento" ou "Evento"', 400);
        }
        if ($minAge === null || $maxAge === null) {
            return $this->apiError('Informe minimal_age e maximal_age', 400);
        }
        if ($minAge < 0 || $maxAge < 0 || $minAge > $maxAge) {
            return $this->apiError('Idades inválidas: mínima deve ser ≤ máxima e ≥ 0', 400);
        }

        $cat = $this->Categories->newEntity([
            'name'        => $name,
            'type'        => $type,
            'minimal_age' => $minAge,
            'maximal_age' => $maxAge,
        ]);
        if (!$this->Categories->save($cat)) {
            return $this->apiError('Erro ao criar categoria', 500);
        }

        return $this->apiResponse([
            'data' => [
                'id'          => $cat->id,
                'name'        => $cat->name,
                'type'        => $cat->type,
                'minimal_age' => $cat->minimal_age,
                'maximal_age' => $cat->maximal_age,
            ],
        ], 201);
    }

    public function edit(int $id): Response
    {
        $this->request->allowMethod(['patch']);
        $identity = $this->requirePermissao('acampamentos');
        if ($identity instanceof Response) return $identity;

        $cat = $this->Categories->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$cat) {
            return $this->apiError('Categoria não encontrada', 404);
        }

        $data = $this->request->getData();
        $minAge = isset($data['minimal_age']) ? (int)$data['minimal_age'] : null;
        $maxAge = isset($data['maximal_age']) ? (int)$data['maximal_age'] : null;

        if ($minAge === null || $maxAge === null) {
            return $this->apiError('Informe minimal_age e maximal_age', 400);
        }
        if ($minAge < 0 || $maxAge < 0 || $minAge > $maxAge) {
            return $this->apiError('Idades inválidas: mínima deve ser ≤ máxima e ≥ 0', 400);
        }

        $this->Categories->patchEntity($cat, ['minimal_age' => $minAge, 'maximal_age' => $maxAge]);
        if (!$this->Categories->save($cat)) {
            return $this->apiError('Erro ao salvar categoria', 500);
        }

        return $this->apiResponse([
            'data' => [
                'id'          => $cat->id,
                'name'        => $cat->name,
                'type'        => $cat->type,
                'minimal_age' => $cat->minimal_age,
                'maximal_age' => $cat->maximal_age,
            ],
        ]);
    }
}
