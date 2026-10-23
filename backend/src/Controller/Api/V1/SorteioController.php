<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use App\Service\PrazoFormularioService;
use App\Service\SorteioService;
use Cake\Http\Response;

#[AllowDynamicProperties]
class SorteioController extends ApiController
{
    private SorteioService $sorteioService;
    private PrazoFormularioService $prazoFormularioService;

    public function initialize(): void
    {
        parent::initialize();
        $this->sorteioService = new SorteioService();
        $this->prazoFormularioService = new PrazoFormularioService();
    }

    // GET /activities/{id}/sorteio
    public function resultado(int $activityId): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        // Oportunista: se a data configurada na criação do acampamento já chegou
        // e o sorteio ainda não foi feito, roda automaticamente antes de responder.
        // Depois aplica os prazos de formulário vencidos, para o admin já abrir a
        // tela vendo as vagas liberadas e os suplentes que assumiram.
        $this->sorteioService->executarSorteiosPendentes();
        $this->prazoFormularioService->executarPrazosVencidos();

        return $this->apiResponse($this->sorteioService->resultado($activityId));
    }

    // POST /activities/{id}/sorteio/campistas
    public function realizarCampistas(int $activityId): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $homem  = (int)($this->request->getData('homem')  ?? 0);
        $mulher = (int)($this->request->getData('mulher') ?? 0);
        $casado = (int)($this->request->getData('casado') ?? 0);

        if ($homem < 0 || $mulher < 0 || $casado < 0) {
            return $this->apiError('Valores de vagas não podem ser negativos', 422);
        }

        $result = $this->sorteioService->realizarCampistas($activityId, $homem, $mulher, $casado);

        return $this->apiResponse($result);
    }

    // DELETE /activities/{id}/sorteio/campistas
    public function resetarCampistas(int $activityId): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $this->sorteioService->resetarCampistas($activityId);

        return $this->apiResponse(['reset' => true]);
    }

    // POST /activities/{id}/sorteio/servos
    public function realizarServos(int $activityId): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $result = $this->sorteioService->realizarServos($activityId);

        return $this->apiResponse($result);
    }

    // DELETE /activities/{id}/sorteio/servos
    public function resetarServos(int $activityId): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $this->sorteioService->resetarServos($activityId);

        return $this->apiResponse(['reset' => true]);
    }
}
