<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\TableLocator;

/**
 * Pagamento registrado à mão pela organização (handoff design_handoff_painel_evento).
 *
 * Não há pagamento online: a pessoa paga direto com a organização (dinheiro ou
 * Pix) e quem tem a tela "Inscritos e Sorteios" registra. Vale para
 * acampamento e evento.
 *
 * - **Quem precisa pagar** (é quando o prazo passa a valer): no acampamento,
 *   quem tem vaga e já enviou o formulário (sorteado, indicado ou suplente
 *   chamado); no evento, quem concluiu a inscrição. Sempre com valor > 0.
 * - "Marcar como pago" vale para **qualquer inscrição ativa** de atividade
 *   paga, mesmo antes de ela precisar pagar (pagamento adiantado).
 * - Quem precisa pagar e não pagou perde a vaga só **DIAS_DE_FOLGA dias depois
 *   do prazo**, para a equipe registrar quem pagou no último dia. Para a pessoa,
 *   o prazo continua sendo a data cadastrada. Não existe "devolver a vaga".
 */
class PagamentoService
{
    public const DIAS_DE_FOLGA = 2;

    /** Formas aceitas (valor gravado => texto). */
    public const FORMAS = ['dinheiro' => 'Dinheiro', 'pix' => 'Pix'];

    public const NAO_PAGO     = 'nao_pago';
    public const PAGO         = 'pago';
    public const PERDEU_VAGA  = 'perdeu_vaga';

    private $PreRegistrations;
    private $Users;
    private $UserChangeLogs;
    private $Payments;
    private MercadoPagoService $mercadoPago;

    public function __construct()
    {
        $locator = new TableLocator();
        $this->PreRegistrations = $locator->get('PreRegistrations');
        $this->Users            = $locator->get('Users');
        $this->UserChangeLogs   = $locator->get('UserChangeLogs');
        $this->Payments         = $locator->get('Payments');
        $this->mercadoPago      = new MercadoPagoService();
    }

    public function valor(object $preReg, object $activity): float
    {
        return $this->mercadoPago->valorDaTaxa($preReg, $activity);
    }

    /** Prazo desta inscrição (suplente chamado ganha dias; nunca passa do início). */
    public function prazo(object $preReg, object $activity): ?DateTime
    {
        $prazo = $this->mercadoPago->prazoPagamento($preReg, $activity);

        return $prazo === null ? null : new DateTime($prazo->format('Y-m-d H:i:s'));
    }

    /** Quando a vaga sai de quem não pagou: o prazo mais os dias de folga. */
    public function limiteDaVaga(?DateTime $prazo): ?DateTime
    {
        return $prazo?->addDays(self::DIAS_DE_FOLGA);
    }

    public function ehEvento(object $activity): bool
    {
        return ($activity->camping_id ?? null) === null;
    }

    /** Inscrição que ainda vale (não desistiu, não saiu, não foi retirada do sorteio). */
    public function ativa(object $preReg): bool
    {
        if ($preReg->is_quitter || $preReg->disqualified_at !== null || $preReg->deleted !== null) {
            return false;
        }

        // Retirado do sorteio sem indicação depois não concorre a nada.
        return !($preReg->removed_from_raffle_at !== null && $preReg->selection_method_id === null);
    }

    /** Já chegou a hora de pagar (o prazo vale para ela)? */
    public function precisaPagar(object $preReg, object $activity): bool
    {
        if (!$this->ativa($preReg) || $this->valor($preReg, $activity) <= 0) {
            return false;
        }
        if ($this->ehEvento($activity)) {
            // Concluiu a inscrição: sem formulário conclui na hora; com, no envio.
            return $activity->event_form_id === null || (bool)$preReg->form_submitted;
        }

        return $preReg->selection_method_id !== null
            && $preReg->substitute_position === null
            && (bool)$preReg->form_submitted;
    }

    /**
     * Situação para a coluna "Pagamento" e a janela "Pagamentos". Null quando a
     * inscrição não entra no pagamento (atividade gratuita, inscrição encerrada
     * sem pagamento registrado).
     */
    public function situacao(object $preReg, object $activity): ?string
    {
        if ($this->valor($preReg, $activity) <= 0) {
            return null;
        }
        if ($preReg->disqualified_at !== null && $preReg->disqualification_reason === PrazoFormularioService::MOTIVO_PRAZO_PAGAMENTO) {
            return self::PERDEU_VAGA;
        }
        // Pago continua "Pago" mesmo se depois a organização cancelou (a janela avisa).
        if ($preReg->is_fee_paid) {
            return self::PAGO;
        }

        return $this->ativa($preReg) ? self::NAO_PAGO : null;
    }

    /** Precisa pagar, não pagou, e o prazo + a folga já passaram: a vaga sai. */
    public function deveTirarVaga(object $preReg, object $activity, ?DateTime $agora = null): bool
    {
        if ($preReg->is_fee_paid || !$this->precisaPagar($preReg, $activity)) {
            return false;
        }
        $limite = $this->limiteDaVaga($this->prazo($preReg, $activity));

        return $limite !== null && ($agora ?? DateTime::now())->getTimestamp() > $limite->getTimestamp();
    }

    /** Data do pagamento: a registrada pela organização ou a do Mercado Pago (antigo). */
    public function pagoEm(object $preReg): ?string
    {
        if (!$preReg->is_fee_paid) {
            return null;
        }
        if ($preReg->fee_paid_on !== null) {
            return $preReg->fee_paid_on->format('Y-m-d');
        }
        $pagamento = $this->Payments->find()
            ->where(['pre_registration_id' => $preReg->id, 'paid_at IS NOT' => null, 'deleted IS' => null])
            ->orderBy(['paid_at' => 'DESC'])
            ->first();

        return $pagamento?->paid_at?->format('Y-m-d');
    }

    /**
     * Tudo o que as telas mostram sobre o pagamento de uma inscrição.
     *
     * @param array<int, string> $nomes nomes de quem registrou, por id (evita uma consulta por linha)
     */
    public function resumo(object $preReg, object $activity, array $nomes = []): array
    {
        $prazo = $this->prazo($preReg, $activity);
        $situacao = $this->situacao($preReg, $activity);
        $registrou = $preReg->fee_registered_by;
        if ($registrou !== null && !isset($nomes[$registrou])) {
            $nomes[$registrou] = (string)($this->Users->find()->select(['name'])->where(['id' => $registrou])->first()?->name ?? '');
        }

        return [
            'valor'         => $this->valor($preReg, $activity),
            'situacao'      => $situacao,
            'precisaPagar'  => $this->precisaPagar($preReg, $activity),
            'prazo'         => $prazo?->format('Y-m-d\TH:i'),
            // Até quando a vaga fica, se não pagar (prazo + folga).
            'limite'        => $this->limiteDaVaga($prazo)?->format('Y-m-d\TH:i'),
            'pagoEm'        => $this->pagoEm($preReg),
            'forma'         => $preReg->fee_payment_method,
            'registradoPor' => $registrou !== null ? ($nomes[$registrou] ?: null) : null,
            'registradoEm'  => $preReg->fee_registered_at?->format('Y-m-d\TH:i'),
            'observacao'    => $preReg->fee_note,
            // Pago e depois cancelado pela organização: talvez precise devolver.
            'canceladaEm'   => $preReg->is_fee_paid && $preReg->disqualification_reason === PrazoFormularioService::MOTIVO_CANCELADA
                ? $preReg->disqualified_at?->format('Y-m-d')
                : null,
            'perdeuEm'      => $situacao === self::PERDEU_VAGA ? $preReg->disqualified_at?->format('Y-m-d') : null,
        ];
    }

    /**
     * Registra o pagamento. Devolve os erros por campo (vazio = registrado).
     *
     * @return array<string, string>
     */
    public function marcar(object $preReg, object $activity, mixed $forma, mixed $data, mixed $observacao, int $por): array
    {
        if ($this->valor($preReg, $activity) <= 0) {
            return ['geral' => 'Esta inscrição não tem valor a pagar.'];
        }
        if ($preReg->is_fee_paid) {
            return ['geral' => 'Esta inscrição já está paga.'];
        }
        if ($this->situacao($preReg, $activity) !== self::NAO_PAGO) {
            return ['geral' => 'Esta inscrição não está mais ativa.'];
        }

        $erros = [];
        if (!is_string($forma) || !isset(self::FORMAS[$forma])) {
            $erros['forma'] = 'Escolha a forma de pagamento.';
        }
        $dia = is_string($data) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? Date::parse($data) : null;
        if ($dia === null) {
            $erros['data'] = 'Informe a data do pagamento.';
        } elseif ($dia->format('Y-m-d') > Date::today()->format('Y-m-d')) {
            $erros['data'] = 'A data do pagamento não pode ser depois de hoje.';
        }
        if ($erros) {
            return $erros;
        }

        $obs = is_string($observacao) && trim($observacao) !== '' ? trim($observacao) : null;
        $preReg->is_fee_paid = true;
        $preReg->fee_paid_on = $dia;
        $preReg->fee_payment_method = $forma;
        $preReg->fee_registered_by = $por;
        $preReg->fee_registered_at = DateTime::now();
        $preReg->fee_note = $obs;
        $this->PreRegistrations->saveOrFail($preReg);

        $texto = sprintf(
            'Registrou o pagamento de %s (%s): %s · %s · pago em %s.',
            $activity->name,
            $this->tipo($preReg),
            self::reais($this->valor($preReg, $activity)),
            self::FORMAS[$forma],
            $dia->format('d/m/Y'),
        );
        if ($obs !== null) {
            $texto .= " Observação: “{$obs}”";
        }
        $this->UserChangeLogs->registrar((int)$preReg->user_id, $por, $texto);

        return [];
    }

    /** Apaga o registro do pagamento (marcado por engano). */
    public function desfazer(object $preReg, object $activity, int $por): ?string
    {
        if (!$preReg->is_fee_paid) {
            return 'Esta inscrição não tem pagamento registrado.';
        }

        $forma = self::FORMAS[$preReg->fee_payment_method ?? ''] ?? 'pagamento online';
        $em = $this->pagoEm($preReg);
        $preReg->is_fee_paid = false;
        $preReg->fee_paid_on = null;
        $preReg->fee_payment_method = null;
        $preReg->fee_registered_by = null;
        $preReg->fee_registered_at = null;
        $preReg->fee_note = null;
        $this->PreRegistrations->saveOrFail($preReg);

        $this->UserChangeLogs->registrar((int)$preReg->user_id, $por, sprintf(
            'Desfez o pagamento de %s (%s) que estava registrado como %s%s.',
            $activity->name,
            $this->tipo($preReg),
            $forma,
            $em ? ', pago em ' . Date::parse($em)->format('d/m/Y') : '',
        ));

        return null;
    }

    private function tipo(object $preReg): string
    {
        return mb_strtolower((string)$preReg->subscription_type);
    }

    public static function reais(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }
}
