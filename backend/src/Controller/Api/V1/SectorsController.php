<?php
declare(strict_types=1);
namespace App\Controller\Api\V1;
use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use Cake\Http\Response;

#[AllowDynamicProperties]
class SectorsController extends ApiController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->Sectors           = $this->fetchTable('Sectors');
        $this->CategoriesSectors = $this->fetchTable('CategoriesSectors');
        $this->Categories        = $this->fetchTable('Categories');
    }

    // GET /admin/sectors
    public function index(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $sectors = $this->Sectors->find()
            ->where(['Sectors.deleted IS' => null])
            ->orderBy(['Sectors.name' => 'ASC'])
            ->all()
            ->toArray();

        $sectorIds = array_column($sectors, 'id');
        $csMap = [];
        if ($sectorIds) {
            $links = $this->CategoriesSectors->find()
                ->contain(['Categories'])
                ->where(['CategoriesSectors.sector_id IN' => array_values($sectorIds), 'CategoriesSectors.deleted IS' => null])
                ->all();
            foreach ($links as $link) {
                $csMap[$link->sector_id][] = [
                    'id'            => $link->id,
                    'category_id'   => $link->category_id,
                    'category_name' => $link->category->name ?? '',
                    'category_type' => $link->category->type ?? '',
                    'vacancies'     => $link->vacancies,
                ];
            }
        }

        $result = [];
        foreach ($sectors as $s) {
            $result[] = [
                'id'         => $s->id,
                'name'       => $s->name,
                'place'      => $s->place,
                'categories' => $csMap[$s->id] ?? [],
            ];
        }

        return $this->apiResponse(['data' => $result]);
    }

    // POST /admin/sectors
    public function add(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $data   = $this->request->getData();
        $sector = $this->Sectors->newEntity([
            'name'  => $data['name']  ?? '',
            'place' => $data['place'] ?? 'Interno',
        ]);

        if (!$this->Sectors->save($sector)) {
            return $this->apiResponse(['error' => 'Erro ao salvar setor', 'errors' => $sector->getErrors()], 422);
        }

        return $this->apiResponse(['data' => [
            'id'         => $sector->id,
            'name'       => $sector->name,
            'place'      => $sector->place,
            'categories' => [],
        ]], 201);
    }

    // PATCH /admin/sectors/{id}
    public function edit(int $id): Response
    {
        $this->request->allowMethod(['patch', 'put']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $sector = $this->Sectors->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$sector) return $this->apiError('Setor não encontrado', 404);

        $data  = $this->request->getData();
        $patch = [];
        if (isset($data['name']))  $patch['name']  = $data['name'];
        if (isset($data['place'])) $patch['place'] = $data['place'];

        $this->Sectors->patchEntity($sector, $patch);
        if (!$this->Sectors->save($sector)) {
            return $this->apiResponse(['error' => 'Erro ao atualizar setor', 'errors' => $sector->getErrors()], 422);
        }

        return $this->apiResponse(['data' => ['id' => $sector->id, 'name' => $sector->name, 'place' => $sector->place]]);
    }

    // DELETE /admin/sectors/{id}
    public function delete(int $id): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $sector = $this->Sectors->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$sector) return $this->apiError('Setor não encontrado', 404);

        $now = date('Y-m-d H:i:s');
        $this->Sectors->updateAll(['deleted' => $now], ['id' => $id]);
        $this->CategoriesSectors->updateAll(['deleted' => $now], ['sector_id' => $id, 'deleted IS' => null]);

        return $this->apiResponse(['deleted' => true]);
    }

    // POST /admin/sectors/{id}/categories
    public function addCategory(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $sector = $this->Sectors->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$sector) return $this->apiError('Setor não encontrado', 404);

        $data       = $this->request->getData();
        $categoryId = intval($data['category_id'] ?? 0);
        if (!$categoryId) return $this->apiError('category_id obrigatório', 422);

        $existing = $this->CategoriesSectors->find()
            ->where(['sector_id' => $id, 'category_id' => $categoryId, 'deleted IS' => null])
            ->first();
        if ($existing) return $this->apiError('Setor já vinculado a essa categoria', 409);

        $cs = $this->CategoriesSectors->newEntity([
            'sector_id'   => $id,
            'category_id' => $categoryId,
            'vacancies'   => intval($data['vacancies'] ?? 0),
        ]);

        if (!$this->CategoriesSectors->save($cs)) {
            return $this->apiResponse(['error' => 'Erro ao vincular', 'errors' => $cs->getErrors()], 422);
        }

        $category = $this->Categories->find()->where(['id' => $categoryId])->first();

        return $this->apiResponse(['data' => [
            'id'            => $cs->id,
            'category_id'   => $cs->category_id,
            'category_name' => $category->name ?? '',
            'category_type' => $category->type ?? '',
            'vacancies'     => $cs->vacancies,
        ]], 201);
    }

    // PATCH /admin/categories-sectors/{csId}
    public function editCategory(int $csId): Response
    {
        $this->request->allowMethod(['patch', 'put']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $cs = $this->CategoriesSectors->find()
            ->contain(['Categories'])
            ->where(['CategoriesSectors.id' => $csId, 'CategoriesSectors.deleted IS' => null])
            ->first();
        if (!$cs) return $this->apiError('Vínculo não encontrado', 404);

        $data  = $this->request->getData();
        $patch = [];
        if (isset($data['vacancies'])) $patch['vacancies'] = intval($data['vacancies']);

        $this->CategoriesSectors->patchEntity($cs, $patch);
        if (!$this->CategoriesSectors->save($cs)) {
            return $this->apiResponse(['error' => 'Erro ao atualizar', 'errors' => $cs->getErrors()], 422);
        }

        return $this->apiResponse(['data' => [
            'id'            => $cs->id,
            'category_id'   => $cs->category_id,
            'category_name' => $cs->category->name ?? '',
            'category_type' => $cs->category->type ?? '',
            'vacancies'     => $cs->vacancies,
        ]]);
    }

    // DELETE /admin/categories-sectors/{csId}
    public function deleteCategory(int $csId): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $cs = $this->CategoriesSectors->find()
            ->where(['id' => $csId, 'deleted IS' => null])
            ->first();
        if (!$cs) return $this->apiError('Vínculo não encontrado', 404);

        $this->CategoriesSectors->updateAll(['deleted' => date('Y-m-d H:i:s')], ['id' => $csId]);

        return $this->apiResponse(['deleted' => true]);
    }
}
