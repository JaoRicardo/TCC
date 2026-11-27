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
 * - **O pagamento fica na vaga** (decidido com o usuário em 2026-11-26): quem
 *   tinha vaga, pagou e saiu (por qualquer motivo) deixa o pagamento para o
 *   suplente chamado no lugar, que entra já como pago (`fee_inherited_from_id`).
 *   Não há devolução de dinheiro. Ver creditosLivres() e passarPagamento().
 */
class PagamentoService
{
    public const DIAS_DE_FOLGA = 2;

    /**
     * Dias que o suplente chamado para uma vaga tem para enviar o formulário e
     * pagar (ver prazoDoChamado()).
     */
    public const DIAS_PARA_PAGAR_APOS_CHAMADO = 3;

    /** Formas aceitas (valor gravado => texto). */
    public const FORMAS = ['dinheiro' => 'Dinheiro', 'pix' => 'Pix'];

    public const NAO_PAGO     = 'nao_pago';
    public const PAGO         = 'pago';
    public const PERDEU_VAGA  = 'perdeu_vaga';

    private $PreRegistrations;
    private $Users;
    private $UserChangeLogs;

    public function __construct()
    {
        $locator = new TableLocator();
        $this->PreRegistrations = $locator->get('PreRegistrations');
        $this->Users            = $locator->get('Users');
        $this->UserChangeLogs   = $locator->get('UserChangeLogs');
    }

    /** Taxa da inscrição: a de servo ou a de participante, conforme o tipo. */
    public function valor(object $preReg, object $activity): float
    {
        return (float)($preReg->subscription_type === 'Servo'
            ? ($activity->servant_fee ?? 0)
            : ($activity->participant_fee ?? 0));
    }

    /**
     * Prazo de pagamento desta inscrição: a data cadastrada na atividade. Suplente
     * chamado para uma vaga tem pelo menos DIAS_PARA_PAGAR_APOS_CHAMADO dias a
     * partir de quando foi chamado — senão quem é chamado depois da data já
     * entraria com o prazo vencido.
     *
     * Nunca passa do fim do primeiro dia da atividade: se muita gente seguida
     * deixar de pagar, os dias de cada chamado iriam empurrando o prazo.
     */
    public function prazo(object $preReg, object $activity): ?DateTime
    {
        $data = $preReg->subscription_type === 'Servo'
            ? ($activity->servant_payment_date ?? null)
            : ($activity->participant_payment_date ?? null);
        if ($data === null) {
            return null;
        }

        return self::prazoDoChamado(new DateTime($data->format('Y-m-d H:i:s')), $preReg, $activity);
    }

    /**
     * O prazo do acampamento ajustado para quem foi chamado da fila de suplentes
     * (vale para o formulário e para o pagamento, que têm o mesmo prazo): pelo
     * menos até 23h59 do DIAS_PARA_PAGAR_APOS_CHAMADO-ésimo dia depois da
     * chamada — o suplente só recebe o formulário quando é chamado, então quem
     * é chamado perto ou depois da data geral precisa desses dias. Nunca passa
     * do fim do primeiro dia da atividade.
     */
    public static function prazoDoChamado(DateTime $prazo, object $preReg, object $activity): DateTime
    {
        if (($preReg->promoted_at ?? null) !== null) {
            $doChamado = (new DateTime($preReg->promoted_at->format('Y-m-d')))
                ->addDays(self::DIAS_PARA_PAGAR_APOS_CHAMADO)
                ->setTime(23, 59);
            if ($doChamado->getTimestamp() > $prazo->getTimestamp()) {
                $prazo = $doChamado;
            }
        }

        // As datas não têm hora e o prazo vale até 23h59 do dia.
        $inicio = $activity->start_date ?? null;
        if ($inicio !== null) {
            $limite = new DateTime($inicio->format('Y-m-d') . ' 23:59:00');
            if ($prazo->getTimestamp() > $limite->getTimestamp()) {
                return $limite;
            }
        }

        return $prazo;
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

    /**
     * Tem vaga (é de quem se espera o pagamento), mesmo que ainda não precise
     * pagar: no acampamento, sorteado, indicado ou suplente chamado (suplente na
     * fila não); no evento, quem concluiu a inscrição.
     */
    public function temVaga(object $preReg, object $activity): bool
    {
        if (!$this->ativa($preReg)) {
            return false;
        }
        if ($this->ehEvento($activity)) {
            return $activity->event_form_id === null || (bool)$preReg->form_submitted;
        }

        return $preReg->selection_method_id !== null && $preReg->substitute_position === null;
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

    /** Data do pagamento registrada pela organização. */
    public function pagoEm(object $preReg): ?string
    {
        return $preReg->is_fee_paid ? $preReg->fee_paid_on?->format('Y-m-d') : null;
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
        $herdadoDe = $preReg->is_fee_paid && ($preReg->fee_inherited_from_id ?? null) !== null
            ? $this->nomeDaInscricao((int)$preReg->fee_inherited_from_id)
            : null;
        $saiu = $preReg->is_fee_paid && $preReg->disqualified_at !== null;
        $passouPara = $saiu ? $this->quemHerdou((int)$preReg->id) : null;

        return [
            'valor'         => $this->valor($preReg, $activity),
            'situacao'      => $situacao,
            'precisaPagar'  => $this->precisaPagar($preReg, $activity),
            // Para o "A receber": suplente na fila e inscrito antes do sorteio não contam.
            'temVaga'       => $this->temVaga($preReg, $activity),
            'prazo'         => $prazo?->format('Y-m-d\TH:i'),
            // Até quando a vaga fica, se não pagar (prazo + folga).
            'limite'        => $this->limiteDaVaga($prazo)?->format('Y-m-d\TH:i'),
            'pagoEm'        => $this->pagoEm($preReg),
            'forma'         => $preReg->fee_payment_method,
            'registradoPor' => $registrou !== null ? ($nomes[$registrou] ?: null) : null,
            'registradoEm'  => $preReg->fee_registered_at?->format('Y-m-d\TH:i'),
            'observacao'    => $preReg->fee_note,
            // Entrou numa vaga já paga: o pagamento é o de quem saiu (não é dinheiro novo).
            'herdado'       => $herdadoDe !== null,
            'herdadoDe'     => $herdadoDe,
            // Pagou e depois saiu da vaga (qualquer motivo): o pagamento fica na vaga.
            'saiuEm'        => $saiu ? $preReg->disqualified_at?->format('Y-m-d') : null,
            // Para quem o pagamento passou (null = ainda ninguém foi chamado para a vaga).
            'passouPara'    => $passouPara?->user?->name,
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
        $herdeiro = $this->quemHerdou((int)$preReg->id);
        if ($herdeiro !== null) {
            return "Este pagamento já passou para {$herdeiro->user->name}, que entrou na vaga. Desfaça primeiro o pagamento dessa pessoa.";
        }

        $herdadoDe = ($preReg->fee_inherited_from_id ?? null) !== null
            ? $this->nomeDaInscricao((int)$preReg->fee_inherited_from_id)
            : null;
        $forma = $herdadoDe !== null
            ? "o pagamento de {$herdadoDe}, que saiu da vaga"
            : (self::FORMAS[$preReg->fee_payment_method ?? ''] ?? 'pago');
        $em = $this->pagoEm($preReg);
        $preReg->is_fee_paid = false;
        $preReg->fee_paid_on = null;
        $preReg->fee_payment_method = null;
        $preReg->fee_registered_by = null;
        $preReg->fee_registered_at = null;
        $preReg->fee_note = null;
        $preReg->fee_inherited_from_id = null;
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

    // ─── O pagamento fica na vaga ──────────────────────────────────────────

    /**
     * Pagamentos de quem tinha vaga, pagou e saiu, que ainda não passaram para
     * ninguém — do mais antigo para o mais novo. Quem chama filtra pela vaga
     * (grupo do campista ou setor do servo).
     *
     * @return list<object> inscrições (com Users) de quem saiu
     */
    public function creditosLivres(int $activityId, string $tipo): array
    {
        $usados = $this->PreRegistrations->find()
            ->select(['fee_inherited_from_id'])
            ->where([
                'activity_id'                => $activityId,
                'deleted IS'                 => null,
                'is_fee_paid'                => true,
                'fee_inherited_from_id IS NOT' => null,
            ]);

        return $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'             => $activityId,
                'PreRegistrations.subscription_type'       => $tipo,
                'PreRegistrations.deleted IS'              => null,
                'PreRegistrations.is_fee_paid'             => true,
                'PreRegistrations.disqualified_at IS NOT'  => null,
                // Só quem tinha vaga (sorteado, indicado ou chamado) deixa a vaga paga.
                'PreRegistrations.selection_method_id IS NOT' => null,
                'PreRegistrations.id NOT IN'               => $usados,
            ])
            ->orderBy(['PreRegistrations.disqualified_at' => 'ASC', 'PreRegistrations.id' => 'ASC'])
            ->all()
            ->toArray();
    }

    /**
     * O suplente chamado entra na vaga de quem saiu já como pago: o pagamento
     * de `$de` passa para `$para`. Fica nas duas fichas.
     */
    public function passarPagamento(object $de, object $para, object $activity, ?int $por): void
    {
        $para->is_fee_paid = true;
        $para->fee_paid_on = Date::today();
        $para->fee_payment_method = null;
        $para->fee_registered_by = $por;
        $para->fee_registered_at = DateTime::now();
        $para->fee_note = null;
        $para->fee_inherited_from_id = $de->id;
        $this->PreRegistrations->saveOrFail($para);

        $nomeDe = $de->user->name ?? $this->nomeDaInscricao((int)$de->id);
        $nomePara = $para->user->name ?? $this->nomeDaInscricao((int)$para->id);
        $this->UserChangeLogs->registrar((int)$para->user_id, $por, sprintf(
            'Chamado(a) para a vaga de %s em %s (%s): não precisa pagar, porque o pagamento de %s passou para esta inscrição.',
            $nomeDe,
            $activity->name,
            $this->tipo($para),
            $nomeDe,
        ));
        $this->UserChangeLogs->registrar((int)$de->user_id, $por, sprintf(
            'O pagamento de %s (%s) passou para %s, que foi chamado(a) para a vaga.',
            $activity->name,
            $this->tipo($de),
            $nomePara,
        ));
    }

    /** A inscrição ativa e paga que recebeu o pagamento desta, se houver. */
    public function quemHerdou(int $preRegistrationId): ?object
    {
        return $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.fee_inherited_from_id' => $preRegistrationId,
                'PreRegistrations.is_fee_paid'           => true,
                'PreRegistrations.deleted IS'            => null,
            ])
            ->first();
    }

    private function nomeDaInscricao(int $preRegistrationId): ?string
    {
        return $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where(['PreRegistrations.id' => $preRegistrationId])
            ->first()?->user?->name;
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
