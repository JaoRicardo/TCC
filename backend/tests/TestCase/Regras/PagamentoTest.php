<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\MercadoPagoService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Http\Client;
use Cake\Http\Client\Response;

/**
 * Pagamento da taxa pelo Mercado Pago (Checkout Pro), de ponta a ponta na API.
 *
 * As respostas do Mercado Pago são simuladas (Client::addMockResponse), então os
 * testes não precisam de conta, credencial nem internet. O que se verifica é o
 * nosso lado: quem pode pagar, e que a inscrição só vira "paga" quando a API do
 * Mercado Pago diz que o pagamento foi aprovado — nunca pelo que vem na URL.
 */
class PagamentoTest extends RegrasTestCase
{
    private const API = MercadoPagoService::API_BASE;

    protected function setUp(): void
    {
        parent::setUp();
        // Credencial fictícia: só liga a integração; nenhuma chamada sai de verdade.
        Configure::write('MercadoPago.access_token', 'TEST-credencial-ficticia');
        Configure::write('MercadoPago.webhook_secret', '');
        Configure::write('MercadoPago.notification_url', '');
        Configure::write('MercadoPago.back_url_base', 'http://localhost:5173');
    }

    protected function tearDown(): void
    {
        Client::clearMockResponses();
        parent::tearDown();
    }

    /** Campista sorteado, com formulário enviado, num acampamento de taxa R$ 120. */
    private function inscricaoSelecionada(array $extra = []): EntityInterface
    {
        $fac = $this->criarAcampamento('FAC', [], [
            'participant_fee' => 120,
            'participant_payment_date' => $this->amanha(),
        ]);

        return $this->inscrever($this->criarUsuario('Campista pagante'), $fac, 'Campista', $extra + [
            'selection_method_id' => $this->metodos['Sorteio'],
            'form_submitted' => true,
        ]);
    }

    private function simularRespostaMercadoPago(string $metodo, string $caminho, array $corpo, int $status = 200): void
    {
        $linhaStatus = $status === 201 ? 'HTTP/1.1 201 Created' : "HTTP/1.1 {$status} OK";
        Client::addMockResponse($metodo, self::API . $caminho, new Response(
            [$linhaStatus, 'Content-Type: application/json'],
            (string)json_encode($corpo),
        ));
    }

    /** Pagamento como a API do Mercado Pago devolve em GET /v1/payments/{id}. */
    private function simularPagamento(string $paymentId, string $status, int $inscricaoId): void
    {
        $this->simularRespostaMercadoPago('GET', "/v1/payments/{$paymentId}", [
            'id' => (int)$paymentId,
            'status' => $status,
            'status_detail' => $status === 'approved' ? 'accredited' : 'pending_waiting_payment',
            'external_reference' => (string)$inscricaoId,
            'transaction_amount' => 120,
            'payment_type_id' => 'credit_card',
            'date_approved' => $status === 'approved' ? '2026-10-23T10:00:00.000-04:00' : null,
        ]);
    }

    private function donoDa(EntityInterface $inscricao): EntityInterface
    {
        return $this->fetchTable('Users')->get($inscricao->user_id);
    }

    private function enviarWebhook(string $paymentId, array $cabecalhos = []): void
    {
        $this->configRequest(['headers' => $cabecalhos + ['Content-Type' => 'application/json']]);
        $this->post('/api/v1/webhooks/mercadopago', (string)json_encode([
            'type' => 'payment',
            'data' => ['id' => $paymentId],
        ]));
    }

    // ─── Criar a cobrança ───────────────────────────────────────────────────

    public function testSemCredencialAvisaQueOPagamentoAindaNaoFoiConfigurado(): void
    {
        Configure::write('MercadoPago.access_token', '');
        $inscricao = $this->inscricaoSelecionada();

        $this->autenticarComo($this->donoDa($inscricao));
        $this->post("/api/v1/inscricoes/{$inscricao->id}/pagamento");

        $this->assertResponseCode(422);
        $this->assertStringContainsString('ainda não foi configurado', $this->respostaJson()['error'] ?? '');
    }

    public function testCriaACobrancaEReaproveitaOMesmoLinkNoSegundoClique(): void
    {
        $inscricao = $this->inscricaoSelecionada();
        $this->simularRespostaMercadoPago('POST', '/checkout/preferences', [
            'id' => 'pref-1',
            'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1',
        ], 201);

        $this->autenticarComo($this->donoDa($inscricao));
        $this->post("/api/v1/inscricoes/{$inscricao->id}/pagamento");
        $this->assertResponseOk();
        $primeiro = $this->respostaJson()['data']['url_pagamento'] ?? null;
        $this->assertStringContainsString('pref_id=pref-1', (string)$primeiro);
        $this->assertSame(120.0, (float)$this->respostaJson()['data']['valor']);

        // Segundo clique (cada requisição do teste precisa do login de novo).
        $this->autenticarComo($this->donoDa($inscricao));
        $this->post("/api/v1/inscricoes/{$inscricao->id}/pagamento");
        $this->assertSame($primeiro, $this->respostaJson()['data']['url_pagamento'] ?? null);
        $this->assertSame(1, $this->fetchTable('Payments')->find()->where(['pre_registration_id' => $inscricao->id])->count());
    }

    public function testSuplenteNaoPaga(): void
    {
        $inscricao = $this->inscricaoSelecionada(['selection_method_id' => null, 'substitute_position' => 1]);

        $this->autenticarComo($this->donoDa($inscricao));
        $this->post("/api/v1/inscricoes/{$inscricao->id}/pagamento");

        $this->assertResponseCode(422);
        $this->assertStringContainsString('Suplentes não pagam', $this->respostaJson()['error'] ?? '');
    }

    public function testPrecisaEnviarOFormularioAntesDePagar(): void
    {
        $inscricao = $this->inscricaoSelecionada(['form_submitted' => false]);

        $this->autenticarComo($this->donoDa($inscricao));
        $this->post("/api/v1/inscricoes/{$inscricao->id}/pagamento");

        $this->assertResponseCode(422);
        $this->assertStringContainsString('Envie o formulário antes', $this->respostaJson()['error'] ?? '');
    }

    // ─── Confirmação pelo webhook ───────────────────────────────────────────

    public function testWebhookComPagamentoAprovadoMarcaAInscricaoComoPaga(): void
    {
        $inscricao = $this->inscricaoSelecionada();
        $this->simularPagamento('999', 'approved', (int)$inscricao->id);

        $this->enviarWebhook('999');

        $this->assertResponseOk();
        $this->assertTrue($this->recarregar($inscricao)->is_fee_paid);
        $registro = $this->fetchTable('Payments')->find()->where(['provider_payment_id' => '999'])->firstOrFail();
        $this->assertSame('approved', $registro->status);
        $this->assertNotNull($registro->paid_at);
    }

    public function testPagamentoPendenteAindaNaoContaComoPago(): void
    {
        $inscricao = $this->inscricaoSelecionada();
        $this->simularPagamento('999', 'pending', (int)$inscricao->id);

        $this->enviarWebhook('999');

        $this->assertResponseOk();
        $this->assertFalse($this->recarregar($inscricao)->is_fee_paid);
    }

    public function testWebhookComAssinaturaInvalidaEhRecusado(): void
    {
        Configure::write('MercadoPago.webhook_secret', 'segredo-teste');
        $inscricao = $this->inscricaoSelecionada();
        $this->simularPagamento('999', 'approved', (int)$inscricao->id);

        $this->enviarWebhook('999', ['x-signature' => 'ts=1700000000,v1=assinatura-falsa', 'x-request-id' => 'req-1']);
        $this->assertResponseCode(401);
        $this->assertFalse($this->recarregar($inscricao)->is_fee_paid);

        // Com a assinatura certa (HMAC do texto que o Mercado Pago assina), passa.
        $assinatura = hash_hmac('sha256', 'id:999;request-id:req-1;ts:1700000000;', 'segredo-teste');
        $this->enviarWebhook('999', ['x-signature' => "ts=1700000000,v1={$assinatura}", 'x-request-id' => 'req-1']);
        $this->assertResponseOk();
        $this->assertTrue($this->recarregar($inscricao)->is_fee_paid);
    }

    // ─── Confirmação na volta do checkout ───────────────────────────────────

    public function testVoltaDoCheckoutConfirmaConsultandoOMercadoPago(): void
    {
        $inscricao = $this->inscricaoSelecionada();
        $this->simularPagamento('999', 'approved', (int)$inscricao->id);

        $this->autenticarComo($this->donoDa($inscricao));
        $this->post("/api/v1/inscricoes/{$inscricao->id}/pagamento/confirmar", ['payment_id' => '999']);

        $this->assertResponseOk();
        $this->assertTrue($this->respostaJson()['data']['pago'] ?? false);
        $this->assertTrue($this->recarregar($inscricao)->is_fee_paid);
    }

    public function testVoltaDoCheckoutSemPagamentoAprovadoNaoMarcaComoPago(): void
    {
        // A URL de volta pode ser digitada à mão com "status=approved"; o que vale é a API.
        $inscricao = $this->inscricaoSelecionada();
        $this->simularPagamento('999', 'rejected', (int)$inscricao->id);

        $this->autenticarComo($this->donoDa($inscricao));
        $this->post("/api/v1/inscricoes/{$inscricao->id}/pagamento/confirmar", ['payment_id' => '999', 'status' => 'approved']);

        $this->assertResponseOk();
        $this->assertFalse($this->respostaJson()['data']['pago'] ?? true);
        $this->assertFalse($this->recarregar($inscricao)->is_fee_paid);
    }

    public function testVoltaComPagamentoDeOutraInscricaoEhRecusada(): void
    {
        $minha = $this->inscricaoSelecionada();
        $deOutraPessoa = $this->inscricaoSelecionada();
        $this->simularPagamento('999', 'approved', (int)$deOutraPessoa->id);

        $this->autenticarComo($this->donoDa($minha));
        $this->post("/api/v1/inscricoes/{$minha->id}/pagamento/confirmar", ['payment_id' => '999']);

        $this->assertResponseCode(422);
        $this->assertStringContainsString('não pertence a esta inscrição', $this->respostaJson()['error'] ?? '');
        $this->assertFalse($this->recarregar($minha)->is_fee_paid);
        $this->assertFalse($this->recarregar($deOutraPessoa)->is_fee_paid, 'Nada deveria ser gravado quando o pagamento é recusado.');
    }
}
