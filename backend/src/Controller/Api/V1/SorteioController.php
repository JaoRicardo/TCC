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
        $identity = $this->requireAdminOrCounselor();
        if ($identity instanceof Response) return $identity;

        // Oportunista: se a data configurada na criação do acampamento já chegou
        // e o sorteio ainda não foi feito, roda automaticamente antes de responder.
        // Depois aplica os prazos vencidos (formulário e pagamento), para a tela já
        // abrir mostrando as vagas abertas.
        $this->sorteioService->executarSorteiosPendentes();
        $this->prazoFormularioService->executarPrazosVencidos();

        return $this->apiResponse($this->sorteioService->resultado($activityId));
    }

    // POST /activities/{id}/sorteio/chamar-proximo
    // body: { tipo: 'Campista', grupo: 'Homem'|'Mulher'|'Casado' } ou { tipo: 'Servo', setor: <acsId> }
    // Chama o próximo suplente da fila para uma vaga aberta — sempre o próximo na
    // ordem do sorteio, nunca um escolhido à mão.
    public function chamarProximo(int $activityId): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requireAdminOrCounselor();
        if ($identity instanceof Response) return $identity;

        // Aplica os prazos antes, para não chamar alguém que já devia ter saído da fila.
        $this->prazoFormularioService->executarPrazosVencidos();

        $tipo = (string)$this->request->getData('tipo');
        if ($tipo === 'Campista') {
            $resultado = $this->prazoFormularioService->chamarProximoCampista(
                $activityId,
                (string)$this->request->getData('grupo')
            );
        } elseif ($tipo === 'Servo') {
            $resultado = $this->prazoFormularioService->chamarProximoServo(
                $activityId,
                (int)$this->request->getData('setor')
            );
        } else {
            return $this->apiError('Tipo inválido', 422);
        }

        if (!$resultado['ok']) {
            return $this->apiError($resultado['error'], 422);
        }

        return $this->apiResponse(['chamados' => $resultado['chamados']]);
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
