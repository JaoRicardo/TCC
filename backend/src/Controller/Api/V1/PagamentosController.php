<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use App\Service\PagamentoService;
use App\Service\PrazoFormularioService;
use Cake\Http\Response;

/**
 * Pagamento da taxa de inscrição, registrado à mão pela organização.
 *
 * Rotas:
 *   POST   /api/v1/admin/inscricoes/{id}/pagamento → a organização registra um pagamento
 *   DELETE /api/v1/admin/inscricoes/{id}/pagamento → desfaz o registro (marcado por engano)
 *
 * Não há pagamento online: a pessoa paga direto com a organização (dinheiro ou
 * Pix) e a equipe registra. As regras ficam no PagamentoService.
 */
#[AllowDynamicProperties]
class PagamentosController extends ApiController
{
    private PagamentoService $pagamentos;

    public function initialize(): void
    {
        parent::initialize();
        $this->PreRegistrations = $this->fetchTable('PreRegistrations');
        $this->pagamentos       = new PagamentoService();
    }

    // POST /api/v1/admin/inscricoes/{id}/pagamento (forma, data, observacao)
    public function registrar(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('inscricoes');
        if ($identity instanceof Response) return $identity;

        $preReg = $this->inscricaoDoPainel($id);
        if (!$preReg) return $this->apiError('Inscrição não encontrada', 404);

        $erros = $this->pagamentos->marcar(
            $preReg,
            $preReg->activity,
            $this->request->getData('forma'),
            $this->request->getData('data'),
            $this->request->getData('observacao'),
            (int)$identity->id,
        );
        if ($erros) {
            return $this->apiResponse(['error' => $erros['geral'] ?? reset($erros), 'errors' => $erros], 422);
        }

        return $this->apiResponse(['data' => $this->pagamentos->resumo($preReg, $preReg->activity)]);
    }

    // DELETE /api/v1/admin/inscricoes/{id}/pagamento
    public function desfazer(int $id): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('inscricoes');
        if ($identity instanceof Response) return $identity;

        $preReg = $this->inscricaoDoPainel($id);
        if (!$preReg) return $this->apiError('Inscrição não encontrada', 404);

        $erro = $this->pagamentos->desfazer($preReg, $preReg->activity, (int)$identity->id);
        if ($erro !== null) return $this->apiError($erro, 422);

        // Desfeito depois do prazo e da folga: a vaga sai na hora.
        (new PrazoFormularioService())->executarPrazosVencidos();
        $preReg = $this->inscricaoDoPainel($id);

        return $this->apiResponse(['data' => $this->pagamentos->resumo($preReg, $preReg->activity)]);
    }

    private function inscricaoDoPainel(int $id): ?object
    {
        return $this->PreRegistrations->find()
            ->contain(['Activities'])
            ->where([
                'PreRegistrations.id'         => $id,
                'PreRegistrations.deleted IS' => null,
                'Activities.deleted IS'       => null,
            ])
            ->first();
    }
}
