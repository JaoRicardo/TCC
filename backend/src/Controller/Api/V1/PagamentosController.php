<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use App\Service\MercadoPagoService;
use Cake\Http\Response;
use Cake\Log\Log;

/**
 * Pagamento da taxa de inscrição via Mercado Pago (Checkout Pro).
 *
 * Rotas:
 *   POST /api/v1/inscricoes/{id}/pagamento   → cria a cobrança e devolve a URL de pagamento
 *   GET  /api/v1/inscricoes/{id}/pagamento   → situação atual do pagamento da inscrição
 *   POST /api/v1/inscricoes/{id}/pagamento/confirmar → confirma na volta do checkout (consulta a API)
 *   POST /api/v1/webhooks/mercadopago        → notificação do Mercado Pago (pública)
 *
 * Ordem do processo: primeiro o formulário (sorteados, indicados E suplentes
 * respondem, dentro do prazo do acampamento), depois o pagamento — e o pagamento
 * é cobrado só de quem tem vaga para participar de verdade. **Suplente não paga**;
 * se ele for chamado para uma vaga liberada, o pagamento libera sozinho aqui.
 */
#[AllowDynamicProperties]
class PagamentosController extends ApiController
{
    private MercadoPagoService $mercadoPago;

    public function initialize(): void
    {
        parent::initialize();
        $this->PreRegistrations = $this->fetchTable('PreRegistrations');
        $this->Activities       = $this->fetchTable('Activities');
        $this->Users            = $this->fetchTable('Users');
        $this->mercadoPago      = new MercadoPagoService();
    }

    // POST /api/v1/inscricoes/{id}/pagamento
    public function criar(int $id): Response
    {
        $this->request->allowMethod(['post']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $preReg = $this->buscarInscricaoDoUsuario($id, (int)$identity->id);
        if (!$preReg) {
            return $this->apiError('Inscrição não encontrada', 404);
        }

        if ($preReg->is_fee_paid) {
            return $this->apiError('Esta inscrição já está paga.', 422);
        }

        if (!$preReg->form_submitted) {
            return $this->apiError('Envie o formulário antes de pagar a inscrição.', 422);
        }

        if ($preReg->disqualified_at !== null || $preReg->is_quitter) {
            return $this->apiError('Esta inscrição não está mais ativa.', 422);
        }

        // Suplente responde o formulário, mas não paga: a taxa é só de quem tem vaga
        // para participar. Se ele for chamado, o pagamento libera automaticamente.
        if ($preReg->substitute_position !== null) {
            return $this->apiError(
                'Suplentes não pagam a taxa. Se uma vaga for liberada para você, o pagamento aparece aqui.',
                422
            );
        }

        $activity = $this->Activities->find()->where(['id' => $preReg->activity_id])->first();
        if (!$activity) {
            return $this->apiError('Atividade não encontrada', 404);
        }

        // Data de pagamento cadastrada na atividade.
        $prazoPagamento = $this->mercadoPago->prazoPagamento($preReg, $activity);
        if (
            is_object($prazoPagamento) && method_exists($prazoPagamento, 'getTimestamp')
            && $prazoPagamento->getTimestamp() < time()
        ) {
            return $this->apiError(
                'O prazo para pagar a inscrição terminou em '
                . $prazoPagamento->format('d/m/Y') . ' às ' . $prazoPagamento->format('H:i')
                . '. Procure a organização.',
                422
            );
        }

        $user = $this->Users->find()->where(['id' => $preReg->user_id])->first();

        $resultado = $this->mercadoPago->criarCobranca($preReg, $activity, $user);
        if (!$resultado['ok']) {
            return $this->apiError($resultado['error'], 422);
        }

        return $this->apiResponse([
            'data' => [
                'url_pagamento' => $resultado['init_point'],
                'valor'         => $this->mercadoPago->valorDaTaxa($preReg, $activity),
            ],
        ]);
    }

    // GET /api/v1/inscricoes/{id}/pagamento
    public function situacao(int $id): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $preReg = $this->buscarInscricaoDoUsuario($id, (int)$identity->id);
        if (!$preReg) {
            return $this->apiError('Inscrição não encontrada', 404);
        }

        return $this->respostaSituacao($preReg);
    }

    private function respostaSituacao(object $preReg): Response
    {
        $activity = $this->Activities->find()->where(['id' => $preReg->activity_id])->first();
        if (!$activity) {
            return $this->apiError('Atividade não encontrada', 404);
        }

        $ultimo = $this->mercadoPago->ultimoPagamento((int)$preReg->id);

        return $this->apiResponse([
            'data' => [
                'pago'             => (bool)$preReg->is_fee_paid,
                'eh_suplente'      => $preReg->substitute_position !== null,
                'valor'            => $this->mercadoPago->valorDaTaxa($preReg, $activity),
                'status_provedor'  => $ultimo->status ?? null,
                'metodo'           => $ultimo->payment_method ?? null,
                'pago_em'          => $ultimo?->paid_at?->format('Y-m-d\TH:i'),
                'url_pagamento'    => $preReg->is_fee_paid ? null : ($ultimo->init_point ?? null),
            ],
        ]);
    }

    /**
     * POST /api/v1/inscricoes/{id}/pagamento/confirmar — chamada pela tela de retorno
     * do checkout, com o `payment_id` que o Mercado Pago coloca na URL de volta.
     *
     * Não confia em nada da URL além do id: consulta o pagamento na API do Mercado
     * Pago, como o webhook faz. Serve para confirmar na hora (e em ambiente local,
     * onde o webhook não chega), sem esperar a notificação.
     */
    public function confirmarRetorno(int $id): Response
    {
        $this->request->allowMethod(['post']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $preReg = $this->buscarInscricaoDoUsuario($id, (int)$identity->id);
        if (!$preReg) {
            return $this->apiError('Inscrição não encontrada', 404);
        }

        $paymentId = preg_replace('/\D/', '', (string)$this->request->getData('payment_id'));
        if ($paymentId === '') {
            return $this->apiError('Pagamento não informado.', 422);
        }

        $resultado = $this->mercadoPago->sincronizarPagamento($paymentId, (int)$preReg->id);
        if (!$resultado['ok']) {
            return $this->apiError($resultado['error'] ?? 'Não foi possível confirmar o pagamento.', 422);
        }

        return $this->respostaSituacao($this->PreRegistrations->get($preReg->id));
    }

    /**
     * POST /api/v1/webhooks/mercadopago — chamada pelo Mercado Pago, sem login.
     *
     * Responde 200 sempre que a notificação é entendida (mesmo que já tenha sido
     * processada antes): o Mercado Pago reenvia a notificação enquanto não recebe
     * 200, e uma resposta de erro aqui viraria reenvio infinito.
     */
    public function webhookMercadoPago(): Response
    {
        $this->request->allowMethod(['post', 'get']);

        $dados = (array)$this->request->getData();

        // O id do pagamento chega em `data.id` (formato novo) ou em `id` na query
        // string (formato antigo, com `topic=payment`).
        $paymentId = (string)(
            $dados['data']['id']
            ?? $this->request->getQuery('data.id')
            ?? $this->request->getQuery('id')
            ?? $dados['id']
            ?? ''
        );

        $tipo = (string)($dados['type'] ?? $this->request->getQuery('topic') ?? '');

        if ($paymentId === '') {
            return $this->apiResponse(['ignorado' => 'notificação sem id de pagamento'], 200);
        }

        // Só pagamento interessa; outros tópicos (merchant_order, plan...) são ignorados.
        if ($tipo !== '' && !str_contains($tipo, 'payment')) {
            return $this->apiResponse(['ignorado' => "tópico {$tipo}"], 200);
        }

        $assinaturaOk = $this->mercadoPago->assinaturaValida(
            $this->request->getHeaderLine('x-signature') ?: null,
            $this->request->getHeaderLine('x-request-id') ?: null,
            $paymentId
        );

        if (!$assinaturaOk) {
            Log::warning('Webhook Mercado Pago com assinatura inválida para o pagamento ' . $paymentId);

            return $this->apiError('Assinatura inválida', 401);
        }

        $resultado = $this->mercadoPago->sincronizarPagamento($paymentId);

        if (!$resultado['ok']) {
            Log::warning('Webhook Mercado Pago não processado: ' . ($resultado['error'] ?? '?'));

            // 200 de propósito: o problema é nosso/da configuração, e reenviar a
            // mesma notificação não resolveria.
            return $this->apiResponse(['ignorado' => $resultado['error'] ?? 'erro ao sincronizar'], 200);
        }

        return $this->apiResponse([
            'recebido'    => true,
            'status'      => $resultado['status'] ?? null,
            'inscricao'   => $resultado['pre_registration_id'] ?? null,
        ]);
    }

    private function buscarInscricaoDoUsuario(int $id, int $userId): ?object
    {
        return $this->PreRegistrations->find()
            ->where([
                'PreRegistrations.id'         => $id,
                'PreRegistrations.user_id'    => $userId,
                'PreRegistrations.deleted IS' => null,
            ])
            ->first();
    }
}
