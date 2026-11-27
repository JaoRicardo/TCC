<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use App\Service\ParticipacoesService;
use Cake\Http\Response;

/**
 * Gestão › Participações anteriores (permissão `participacoes`): quem já foi
 * campista numa categoria antes do sistema. Só de quem já tem conta (LGPD): o
 * CPF digitado serve para achar a conta e não é guardado. As regras ficam em
 * ParticipacoesService.
 */
class ParticipacoesController extends ApiController
{
    private ParticipacoesService $participacoes;

    public function initialize(): void
    {
        parent::initialize();
        $this->participacoes = new ParticipacoesService();
    }

    /** GET /admin/participacoes — as cadastradas e as categorias para o campo. */
    public function lista(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('participacoes');
        if ($identity instanceof Response) return $identity;

        return $this->apiResponse(['data' => [
            'participacoes' => $this->participacoes->listar(),
            'categorias'    => $this->participacoes->categorias(),
        ]]);
    }

    /** GET /admin/participacoes/cpf/{cpf} — o que já se sabe deste CPF. */
    public function cpf(string $cpf): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('participacoes');
        if ($identity instanceof Response) return $identity;

        return $this->apiResponse(['data' => $this->participacoes->consultar(ParticipacoesService::digitos($cpf))]);
    }

    /** POST /admin/participacoes */
    public function adicionar(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('participacoes');
        if ($identity instanceof Response) return $identity;

        [$linha, $erros] = $this->participacoes->adicionar((array)$this->request->getData(), (int)$identity->id);
        if ($erros) {
            return $this->apiResponse(['error' => 'Confira os campos destacados.', 'erros' => $erros], 422);
        }

        return $this->apiResponse(['data' => $linha], 201);
    }

    /** DELETE /admin/participacoes/{id} */
    public function remover(int $id): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('participacoes');
        if ($identity instanceof Response) return $identity;

        if (!$this->participacoes->remover($id, (int)$identity->id)) {
            return $this->apiError('Participação não encontrada.', 404);
        }

        return $this->apiResponse(['ok' => true]);
    }
}
