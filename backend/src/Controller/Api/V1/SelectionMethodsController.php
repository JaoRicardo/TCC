<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use Cake\Http\Response;

class SelectionMethodsController extends ApiController
{
    public function initialize(): void
    {
        parent::initialize();

        /** @var \App\Model\Table\SelectionMethodsTable $SelectionMethods */
        $this->SelectionMethods = $this->fetchTable('SelectionMethods');
    }

    // GET /api/v1/admin/selection-methods (admin)
    public function index(): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $type = $this->request->getQuery('type');

        $query = $this->SelectionMethods->find()
            ->where(['deleted IS' => null])
            ->orderBy(['id' => 'ASC']);

        if ($type) {
            $query = $query->where(['type' => $type]);
        }

        $methods = $query
            ->all()
            ->map(fn($m) => [
                'id'          => $m->id,
                'method'      => $m->method,
                'description' => $m->description,
                'type'        => $m->type,
            ])
            ->toArray();

        return $this->apiResponse(['data' => array_values($methods)]);
    }
}
