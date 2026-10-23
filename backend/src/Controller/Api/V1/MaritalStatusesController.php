<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use Cake\Http\Response;

class MaritalStatusesController extends ApiController
{
    public function initialize(): void
    {
        parent::initialize();

        /** @var \App\Model\Table\MaritalStatusesTable $MaritalStatuses */
        $this->MaritalStatuses = $this->fetchTable('MaritalStatuses');
    }

    // GET /api/v1/marital-statuses (público)
    public function index(): Response
    {
        $this->request->allowMethod(['get']);

        $statuses = $this->MaritalStatuses->find()
            ->where(['deleted IS' => null])
            ->orderBy(['id' => 'ASC'])
            ->all()
            ->map(fn($s) => ['id' => $s->id, 'title' => $s->title])
            ->toArray();

        return $this->apiResponse(['data' => array_values($statuses)]);
    }
}
