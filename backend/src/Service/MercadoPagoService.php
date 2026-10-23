<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\TableLocator;

/**
 * Integração de pagamento com o Mercado Pago no modelo Checkout Pro.
 *
 * Como funciona o fluxo (é o modelo em que nenhum dado de cartão passa pelo nosso
 * servidor — o campista paga dentro do ambiente do Mercado Pago):
 *
 *   1. O campista termina o formulário e clica em pagar.
 *   2. Chamamos POST /checkout/preferences com o valor da taxa, os dados dele e o
 *      nosso `external_reference` (o id da pré-inscrição). A resposta traz um
 *      `init_point`, que é a URL da tela de pagamento.
 *   3. Redirecionamos o campista para essa URL. Ele paga com Pix, cartão ou boleto
 *      e volta para o nosso site pelas `back_urls`.
 *   4. Quem confirma o pagamento de verdade é o webhook: o Mercado Pago chama
 *      `notification_url` com `data.id` (o id do pagamento), consultamos
 *      GET /v1/payments/{id} e, se vier `status: approved`, marcamos
 *      `pre_registrations.is_fee_paid = true`.
 *
 * A volta pelas `back_urls` NUNCA é usada como prova de pagamento: ela é só
 * navegação e pode ser forjada digitando a URL. A fonte da verdade é sempre a
 * consulta à API feita a partir do webhook.
 *
 * Credenciais (nenhuma fica no código — todas vêm do config/.env):
 *   MERCADOPAGO_ACCESS_TOKEN   — token privado, usado nas chamadas de servidor
 *   MERCADOPAGO_WEBHOOK_SECRET — segredo da assinatura do webhook
 *   MERCADOPAGO_BACK_URL_BASE  — base do front pra onde o campista volta
 *   MERCADOPAGO_NOTIFICATION_URL — URL pública que o Mercado Pago chama
 *
 * Enquanto o token não estiver preenchido, `configurado()` devolve false e a API
 * responde uma mensagem clara em vez de tentar chamar o Mercado Pago. Assim o
 * resto do sistema já funciona sem a conta estar criada.
 */
class MercadoPagoService
{
    public const API_BASE = 'https://api.mercadopago.com';

    /** Status do Mercado Pago que contam como pago. */
    public const STATUS_APROVADOS = ['approved'];

    /** Status que ainda podem virar pagos (Pix/boleto aguardando). */
    public const STATUS_PENDENTES = ['pending', 'in_process', 'authorized'];

    private $PreRegistrations;
    private $Payments;
    private $Activities;
    private $Users;

    public function __construct()
    {
        $locator = new TableLocator();
        $this->PreRegistrations = $locator->get('PreRegistrations');
        $this->Payments         = $locator->get('Payments');
        $this->Activities       = $locator->get('Activities');
        $this->Users            = $locator->get('Users');
    }

    // ─── Configuração ───────────────────────────────────────────────────────

    public static function configurado(): bool
    {
        return self::accessToken() !== '';
    }

    private static function accessToken(): string
    {
        return trim((string)Configure::read('MercadoPago.access_token'));
    }

    private static function webhookSecret(): string
    {
        return trim((string)Configure::read('MercadoPago.webhook_secret'));
    }

    /**
     * URL que o Mercado Pago vai chamar para avisar de pagamentos. Em ambiente
     * local ela não é enviada: o Mercado Pago não consegue alcançar `localhost`,
     * e mandar uma URL inacessível faz a criação da preference falhar. Para testar
     * webhook em desenvolvimento, exponha o backend com um túnel (ngrok, cloudflared)
     * e coloque a URL pública em MERCADOPAGO_NOTIFICATION_URL.
     */
    private static function notificationUrl(): ?string
    {
        $url = trim((string)Configure::read('MercadoPago.notification_url'));
        if ($url === '') {
            return null;
        }
        if (preg_match('/localhost|127\.0\.0\.1|::1/', $url)) {
            return null;
        }

        return $url;
    }

    private static function backUrls(int $preRegId): array
    {
        $base = rtrim(trim((string)Configure::read('MercadoPago.back_url_base')), '/');
        if ($base === '') {
            return [];
        }

        $retorno = $base . '/dashboard/formulario/' . $preRegId . '/pagamento';

        return [
            'success' => $retorno . '?resultado=sucesso',
            'pending' => $retorno . '?resultado=pendente',
            'failure' => $retorno . '?resultado=falha',
        ];
    }

    // ─── Criação da cobrança ────────────────────────────────────────────────

    /**
     * Cria (ou reaproveita) a cobrança da inscrição e devolve a URL de pagamento.
     *
     * @return array{ok: bool, error?: string, init_point?: string, payment?: object}
     */
    public function criarCobranca(object $preReg, object $activity, object $user): array
    {
        $valor = $this->valorDaTaxa($preReg, $activity);

        if ($valor <= 0) {
            return ['ok' => false, 'error' => 'Esta inscrição não tem taxa cadastrada para pagamento.'];
        }

        if (!self::configurado()) {
            return [
                'ok' => false,
                'error' => 'O pagamento online ainda não foi configurado pela organização.',
            ];
        }

        // Já existe uma cobrança aberta com o mesmo valor? Reaproveita o link em vez
        // de criar outra preference a cada clique.
        $aberta = $this->Payments->find()
            ->where([
                'pre_registration_id' => $preReg->id,
                'provider'            => 'mercadopago',
                'status IN'           => self::STATUS_PENDENTES,
                'init_point IS NOT'   => null,
                'amount'              => number_format($valor, 2, '.', ''),
                'deleted IS'          => null,
            ])
            ->orderBy(['id' => 'DESC'])
            ->first();

        if ($aberta) {
            return ['ok' => true, 'init_point' => $aberta->init_point, 'payment' => $aberta];
        }

        $body = [
            'items' => [[
                'id'          => (string)$activity->id,
                'title'       => 'Inscrição - ' . $activity->name,
                'description' => 'Taxa de ' . $preReg->subscription_type,
                'quantity'    => 1,
                'currency_id' => 'BRL',
                'unit_price'  => round($valor, 2),
            ]],
            'payer' => [
                'name'  => (string)($user->name ?? ''),
                'email' => (string)($user->email ?? ''),
            ],
            // Nossa chave de volta: toda notificação do Mercado Pago devolve isso,
            // e é por aqui que sabemos de qual inscrição o pagamento é.
            'external_reference' => (string)$preReg->id,
            'metadata' => [
                'pre_registration_id' => (int)$preReg->id,
                'activity_id'         => (int)$activity->id,
                'subscription_type'   => (string)$preReg->subscription_type,
            ],
            'statement_descriptor' => 'ACAMPAMENTO',
        ];

        $cpf = preg_replace('/\D/', '', (string)($user->cpf ?? ''));
        if (strlen($cpf) === 11) {
            $body['payer']['identification'] = ['type' => 'CPF', 'number' => $cpf];
        }

        $backUrls = self::backUrls((int)$preReg->id);
        if (!empty($backUrls)) {
            $body['back_urls'] = $backUrls;
            // auto_return só é aceito junto de back_urls.success.
            $body['auto_return'] = 'approved';
        }

        $notificationUrl = self::notificationUrl();
        if ($notificationUrl !== null) {
            $body['notification_url'] = $notificationUrl;
        }

        $resposta = $this->post('/checkout/preferences', $body, [
            // Evita criar duas cobranças iguais se a pessoa clicar duas vezes.
            'X-Idempotency-Key' => 'prereg-' . $preReg->id . '-' . number_format($valor, 2, '.', ''),
        ]);

        if (!$resposta['ok']) {
            return ['ok' => false, 'error' => $resposta['error']];
        }

        $dados = $resposta['data'];
        $initPoint = (string)($dados['init_point'] ?? $dados['sandbox_init_point'] ?? '');

        if ($initPoint === '') {
            return ['ok' => false, 'error' => 'O Mercado Pago não devolveu a URL de pagamento.'];
        }

        $payment = $this->Payments->newEntity([
            'pre_registration_id' => $preReg->id,
            'provider'            => 'mercadopago',
            'preference_id'       => (string)($dados['id'] ?? ''),
            'external_reference'  => (string)$preReg->id,
            'status'              => 'pending',
            'amount'              => number_format($valor, 2, '.', ''),
            'init_point'          => $initPoint,
            'raw_payload'         => json_encode($dados, JSON_UNESCAPED_UNICODE),
        ]);

        if (!$this->Payments->save($payment)) {
            return ['ok' => false, 'error' => 'Não foi possível registrar a cobrança no sistema.'];
        }

        return ['ok' => true, 'init_point' => $initPoint, 'payment' => $payment];
    }

    // ─── Webhook / confirmação ──────────────────────────────────────────────

    /**
     * Consulta um pagamento no Mercado Pago e guarda o resultado, marcando a
     * inscrição como paga quando o status for aprovado.
     *
     * @return array{ok: bool, error?: string, status?: string, pre_registration_id?: int}
     */
    public function sincronizarPagamento(string $paymentId): array
    {
        if (!self::configurado()) {
            return ['ok' => false, 'error' => 'Integração do Mercado Pago não configurada.'];
        }

        $resposta = $this->get('/v1/payments/' . rawurlencode($paymentId));
        if (!$resposta['ok']) {
            return ['ok' => false, 'error' => $resposta['error']];
        }

        $dados  = $resposta['data'];
        $status = (string)($dados['status'] ?? '');
        $preRegId = (int)($dados['external_reference'] ?? 0);

        if ($preRegId <= 0) {
            $preRegId = (int)($dados['metadata']['pre_registration_id'] ?? 0);
        }
        if ($preRegId <= 0) {
            return ['ok' => false, 'error' => 'Pagamento sem external_reference — não deu para identificar a inscrição.'];
        }

        $preReg = $this->PreRegistrations->find()
            ->where(['id' => $preRegId, 'deleted IS' => null])
            ->first();
        if (!$preReg) {
            return ['ok' => false, 'error' => "Inscrição #{$preRegId} não encontrada."];
        }

        // Uma linha por pagamento do provedor: a mesma notificação pode chegar
        // várias vezes (o Mercado Pago reenvia até receber 200).
        $payment = $this->Payments->find()
            ->where(['provider' => 'mercadopago', 'provider_payment_id' => (string)$paymentId])
            ->first();

        if (!$payment) {
            // Costura com a cobrança criada por nós, quando existir.
            $payment = $this->Payments->find()
                ->where([
                    'pre_registration_id' => $preRegId,
                    'provider'            => 'mercadopago',
                    'provider_payment_id IS' => null,
                    'preference_id'       => (string)($dados['order']['id'] ?? ''),
                ])
                ->orderBy(['id' => 'DESC'])
                ->first();
        }

        if (!$payment) {
            $payment = $this->Payments->newEmptyEntity();
            $payment->pre_registration_id = $preRegId;
            $payment->provider = 'mercadopago';
        }

        $payment->provider_payment_id = (string)$paymentId;
        $payment->external_reference  = (string)$preRegId;
        $payment->status              = $status !== '' ? $status : 'unknown';
        $payment->status_detail       = substr((string)($dados['status_detail'] ?? ''), 0, 60) ?: null;
        $payment->amount              = number_format((float)($dados['transaction_amount'] ?? 0), 2, '.', '');
        $payment->payment_method      = substr((string)($dados['payment_type_id'] ?? ''), 0, 40) ?: null;
        $payment->raw_payload         = json_encode($dados, JSON_UNESCAPED_UNICODE);

        if (in_array($status, self::STATUS_APROVADOS, true)) {
            $payment->paid_at = $this->parseData($dados['date_approved'] ?? null) ?? DateTime::now();
        }

        $this->Payments->save($payment);

        if (in_array($status, self::STATUS_APROVADOS, true) && !$preReg->is_fee_paid) {
            $preReg->is_fee_paid = true;
            $this->PreRegistrations->save($preReg);
        }

        return ['ok' => true, 'status' => $status, 'pre_registration_id' => $preRegId];
    }

    /**
     * Valida a assinatura do webhook (header `x-signature`), no formato oficial
     * `ts=<timestamp>,v1=<hash>`: o hash é um HMAC-SHA256, com o segredo cadastrado
     * no painel do Mercado Pago, sobre o texto
     * `id:<data.id>;request-id:<x-request-id>;ts:<ts>;` — partes ausentes na
     * notificação são omitidas do texto antes de calcular.
     *
     * Sem segredo cadastrado a validação é considerada "não exigida" (devolve true),
     * para o webhook continuar funcionando em ambiente de teste.
     */
    public function assinaturaValida(?string $xSignature, ?string $xRequestId, string $dataId): bool
    {
        $secret = self::webhookSecret();
        if ($secret === '') {
            return true;
        }
        if (!$xSignature) {
            return false;
        }

        $ts = null;
        $hash = null;
        foreach (explode(',', $xSignature) as $parte) {
            $par = explode('=', trim($parte), 2);
            if (count($par) !== 2) continue;
            $chave = trim($par[0]);
            $valor = trim($par[1]);
            if ($chave === 'ts') $ts = $valor;
            if ($chave === 'v1') $hash = $valor;
        }

        if ($ts === null || $hash === null) {
            return false;
        }

        $manifest = '';
        if ($dataId !== '') {
            $manifest .= 'id:' . strtolower($dataId) . ';';
        }
        if ($xRequestId) {
            $manifest .= 'request-id:' . $xRequestId . ';';
        }
        $manifest .= 'ts:' . $ts . ';';

        $esperado = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($esperado, $hash);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    public function valorDaTaxa(object $preReg, object $activity): float
    {
        return (float)($preReg->subscription_type === 'Servo'
            ? ($activity->servant_fee ?? 0)
            : ($activity->participant_fee ?? 0));
    }

    /** Link de pagamento fixo cadastrado à mão na atividade (fallback antigo). */
    public function linkManual(object $preReg, object $activity): ?string
    {
        $link = $preReg->subscription_type === 'Servo'
            ? ($activity->servant_payment_link ?? null)
            : ($activity->participant_payment_link ?? null);

        return $link !== null && trim((string)$link) !== '' ? (string)$link : null;
    }

    /** Prazo de pagamento cadastrado na atividade, se houver. */
    public function prazoPagamento(object $preReg, object $activity): mixed
    {
        return $preReg->subscription_type === 'Servo'
            ? ($activity->servant_payment_date ?? null)
            : ($activity->participant_payment_date ?? null);
    }

    /** Última cobrança registrada para a inscrição. */
    public function ultimoPagamento(int $preRegId): ?object
    {
        return $this->Payments->find()
            ->where(['pre_registration_id' => $preRegId, 'deleted IS' => null])
            ->orderBy(['id' => 'DESC'])
            ->first();
    }

    private function post(string $path, array $body, array $headersExtra = []): array
    {
        return $this->request('post', $path, $body, $headersExtra);
    }

    private function get(string $path): array
    {
        return $this->request('get', $path, null, []);
    }

    private function request(string $metodo, string $path, ?array $body, array $headersExtra): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . self::accessToken(),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ] + $headersExtra;

        try {
            $client = new Client(['timeout' => 20]);
            $url = self::API_BASE . $path;

            $resposta = $metodo === 'post'
                ? $client->post($url, json_encode($body, JSON_UNESCAPED_UNICODE), ['headers' => $headers])
                : $client->get($url, [], ['headers' => $headers]);

            $dados = $resposta->getJson() ?? [];

            if (!$resposta->isOk() && $resposta->getStatusCode() !== 201) {
                $msg = (string)($dados['message'] ?? $dados['error'] ?? 'erro desconhecido');
                Log::error("Mercado Pago {$metodo} {$path} respondeu {$resposta->getStatusCode()}: {$msg}");

                return ['ok' => false, 'error' => "Mercado Pago recusou a requisição ({$msg})."];
            }

            return ['ok' => true, 'data' => $dados];
        } catch (\Throwable $e) {
            Log::error('Mercado Pago falhou: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Não foi possível falar com o Mercado Pago agora. Tente de novo em alguns minutos.'];
        }
    }

    private function parseData(mixed $valor): ?DateTime
    {
        if (!$valor) {
            return null;
        }
        try {
            return new DateTime((string)$valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
