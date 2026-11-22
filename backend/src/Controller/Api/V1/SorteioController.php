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
        $identity = $this->requirePermissao('inscricoes');
        if ($identity instanceof Response) return $identity;

        // Aplica os prazos vencidos (formulário e pagamento), para a tela já abrir
        // mostrando as vagas abertas. O sorteio só é feito à mão (POST abaixo).
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
        $identity = $this->requirePermissao('inscricoes');
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

        return $this->apiResponse(['chamados' => $resultado['chamados'], 'prazo' => $resultado['prazo'] ?? null]);
    }

    /**
     * Sortear de novo e apagar o resultado ficam bloqueados quando alguém com
     * vaga pelo sorteio ou na fila já enviou o formulário ou já pagou.
     */
    private function recusaSeBloqueado(int $activityId, string $tipo): ?Response
    {
        $jaFeito = $tipo === 'Servo'
            ? $this->sorteioService->servosJaRealizado($activityId)
            : $this->sorteioService->campistasJaRealizado($activityId);
        if (!$jaFeito || !$this->sorteioService->sorteioBloqueado($activityId, $tipo)) return null;

        return $this->apiResponse([
            'error'  => 'O sorteio não pode ser refeito nem apagado: já há quem enviou o formulário ou pagou.',
            'codigo' => 'sorteio_bloqueado',
        ], 422);
    }

    // POST /activities/{id}/sorteio/campistas
    public function realizarCampistas(int $activityId): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('inscricoes');
        if ($identity instanceof Response) return $identity;

        $bloqueio = $this->recusaSeBloqueado($activityId, 'Campista');
        if ($bloqueio) return $bloqueio;

        // As vagas vêm sempre do cadastro do acampamento (Editar acampamento);
        // a tela só as mostra. Casado = número de casais.
        $activity = $this->fetchTable('Activities')->find()->select(['camping_id'])->where(['id' => $activityId, 'deleted IS' => null])->first();
        $camping = $activity?->camping_id ? $this->fetchTable('Campings')->find()->where(['id' => $activity->camping_id])->first() : null;
        if (!$camping) return $this->apiError('Acampamento não encontrado', 404);

        $result = $this->sorteioService->realizarCampistas(
            $activityId,
            (int)$camping->raffle_man_vacancies,
            (int)$camping->raffle_woman_vacancies,
            (int)$camping->raffle_couple_vacancies
        );

        return $this->apiResponse($result);
    }

    // DELETE /activities/{id}/sorteio/campistas
    public function resetarCampistas(int $activityId): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('inscricoes');
        if ($identity instanceof Response) return $identity;

        $bloqueio = $this->recusaSeBloqueado($activityId, 'Campista');
        if ($bloqueio) return $bloqueio;

        $this->sorteioService->resetarCampistas($activityId);

        return $this->apiResponse(['reset' => true]);
    }

    // POST /activities/{id}/sorteio/servos
    public function realizarServos(int $activityId): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('inscricoes');
        if ($identity instanceof Response) return $identity;

        $bloqueio = $this->recusaSeBloqueado($activityId, 'Servo');
        if ($bloqueio) return $bloqueio;

        $result = $this->sorteioService->realizarServos($activityId);

        return $this->apiResponse($result);
    }

    // DELETE /activities/{id}/sorteio/servos
    public function resetarServos(int $activityId): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('inscricoes');
        if ($identity instanceof Response) return $identity;

        $bloqueio = $this->recusaSeBloqueado($activityId, 'Servo');
        if ($bloqueio) return $bloqueio;

        $this->sorteioService->resetarServos($activityId);

        return $this->apiResponse(['reset' => true]);
    }
}
