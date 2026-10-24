<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\TableLocator;

/**
 * Prazos do acampamento: quem tem vaga (sorteado ou indicado) e não envia o
 * formulário até a data-limite, ou envia mas não paga a inscrição até a data de
 * pagamento, perde a vaga sozinho. A vaga fica **aberta** até um administrador
 * ou conselheiro chamar o próximo suplente da fila pela tela do sorteio.
 *
 * Regras que valem aqui:
 *
 * - O prazo do formulário é uma data fixa por acampamento, separada para
 *   campista (`camper_form_deadline`) e servo (`servant_form_deadline`).
 *   Acampamento sem prazo cadastrado nunca desclassifica ninguém — a automação
 *   só age onde o organizador definiu o prazo de propósito.
 * - O prazo do pagamento é a data cadastrada na atividade. Só vale para quem já
 *   enviou o formulário (quem não enviou cai na regra do formulário) e só onde
 *   existe taxa. Suplente chamado para uma vaga ganha alguns dias a partir de
 *   quando foi chamado (ver MercadoPagoService::prazoPagamento).
 * - Desclassificar não apaga o `selection_method_id`: ele continua registrando
 *   como a pessoa tinha sido escolhida, e é o `disqualified_at` que diz que a
 *   vaga não vale mais. Isso também evita que a pessoa volte pro pool e seja
 *   sorteada de novo num resorteio. O `disqualification_reason` diz qual prazo
 *   foi perdido.
 * - Casal é a unidade, igual no sorteio: se um dos dois perde o prazo, o casal
 *   inteiro sai e o próximo casal suplente entra completo.
 * - **Suplente também responde o formulário**, dentro do mesmo prazo do
 *   acampamento. Suplente que deixa o prazo passar sem responder sai da fila.
 * - Suplente não paga enquanto é suplente (ver `PagamentosController`).
 * - **O próximo suplente não entra sozinho** (decisão do usuário em 2026-10-24):
 *   quem chama é o administrador ou conselheiro, sempre o próximo da fila do
 *   mesmo grupo (Homem/Mulher/Casado) ou, para servo, do mesmo setor.
 *
 * Roda pelo comando `bin/cake executar_prazos_formulario` e também de forma
 * oportunista nos endpoints mais acessados, no mesmo esquema já usado pelo
 * sorteio automático (SorteioService::executarSorteiosPendentes).
 */
class PrazoFormularioService
{
    /** Valores gravados em `disqualification_reason`. */
    public const MOTIVO_PRAZO_FORMULARIO = 'prazo_formulario';
    public const MOTIVO_PRAZO_PAGAMENTO  = 'prazo_pagamento';

    public const GRUPOS_CAMPISTA = ['Homem', 'Mulher', 'Casado'];

    private const ERRO_JA_COMECOU = 'O acampamento já começou; não dá mais para chamar suplentes.';

    private $Activities;
    private $Campings;
    private $PreRegistrations;
    private $SelectionMethods;
    private $MaritalStatuses;
    private $AcsSectors;
    private $AcsSectorsUsers;
    private $Questions;
    private MercadoPagoService $mercadoPago;

    public function __construct()
    {
        $locator = new TableLocator();
        $this->Activities       = $locator->get('Activities');
        $this->Campings         = $locator->get('Campings');
        $this->PreRegistrations = $locator->get('PreRegistrations');
        $this->SelectionMethods = $locator->get('SelectionMethods');
        $this->MaritalStatuses  = $locator->get('MaritalStatuses');
        $this->AcsSectors       = $locator->get('ActivitiesCategoriesSectors');
        $this->AcsSectorsUsers  = $locator->get('ActivitiesCategoriesSectorsUsers');
        $this->Questions        = $locator->get('Questions');
        $this->mercadoPago      = new MercadoPagoService();
    }

    // ─── Consulta de prazo (usada também pela tela do formulário) ───────────

    /**
     * Prazo que vale para esta inscrição — o do acampamento, igual para sorteados,
     * indicados e suplentes.
     */
    public function prazoDaInscricao(object $preReg, ?object $camping): ?DateTime
    {
        return $this->prazoGeral($camping, (string)$preReg->subscription_type);
    }

    /** Prazo cadastrado no acampamento para o tipo de inscrição informado. */
    public function prazoGeral(?object $camping, string $subscriptionType): ?DateTime
    {
        if (!$camping) {
            return null;
        }

        $valor = $subscriptionType === 'Servo'
            ? $camping->servant_form_deadline
            : $camping->camper_form_deadline;

        return $valor === null ? null : $this->asDateTime($valor);
    }

    /** true quando o prazo desta inscrição já venceu. */
    public function prazoVencido(object $preReg, ?object $camping): bool
    {
        $prazo = $this->prazoDaInscricao($preReg, $camping);

        return $prazo !== null && $prazo->getTimestamp() < time();
    }

    // ─── Execução automática ────────────────────────────────────────────────

    /**
     * Varre todos os acampamentos e tira a vaga de quem perdeu o prazo do
     * formulário ou do pagamento. Não chama ninguém no lugar.
     *
     * @return string[] Log de ações realizadas, para exibição/registro.
     */
    public function executarPrazosVencidos(): array
    {
        $log = [];

        $activities = $this->Activities->find()
            ->where(['camping_id IS NOT' => null, 'deleted IS' => null])
            ->all();

        foreach ($activities as $activity) {
            $camping = $this->Campings->find()
                ->where(['id' => $activity->camping_id])
                ->first();
            if (!$camping) continue;

            foreach (['Campista', 'Servo'] as $tipo) {
                // Sem prazo cadastrado a automação do formulário não age.
                if ($this->prazoGeral($camping, $tipo) !== null) {
                    $this->processarFormularios($activity, $camping, $tipo, $log);
                }
                $this->processarPagamentos($activity, $tipo, $log);
            }
        }

        return $log;
    }

    private function processarFormularios(object $activity, object $camping, string $tipo, array &$log): void
    {
        // Proteção importante: sem pergunta cadastrada não existe formulário para
        // responder, então ninguém pode perder a vaga por "não ter respondido".
        // Sem isso, cadastrar um prazo num acampamento cujo formulário ainda não
        // foi montado desclassificaria todos os selecionados de uma vez.
        if ($this->contarPerguntas($activity, $tipo) === 0) {
            return;
        }

        // Candidatos: todo mundo que tinha formulário para entregar e não entregou —
        // titulares (sorteados/indicados) e suplentes.
        $pendentes = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'        => $activity->id,
                'PreRegistrations.subscription_type'  => $tipo,
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.is_quitter'         => false,
                'PreRegistrations.disqualified_at IS' => null,
                'PreRegistrations.form_submitted'     => false,
                'OR' => [
                    'PreRegistrations.selection_method_id IS NOT' => null,
                    'PreRegistrations.substitute_position IS NOT' => null,
                ],
            ])
            ->all()
            ->toArray();

        $jaTratados = [];

        foreach ($pendentes as $preReg) {
            if (isset($jaTratados[$preReg->id])) continue;
            if (!$this->prazoVencido($preReg, $camping)) continue;

            // Suplente que não respondeu perde o lugar na fila, mas não libera vaga
            // nenhuma — ele ainda não tinha vaga.
            if ($preReg->substitute_position !== null) {
                $this->tirarSuplenteDaFila($activity, $preReg, $tipo, $jaTratados, $log);
                continue;
            }

            $this->tirarVaga($activity, $preReg, self::MOTIVO_PRAZO_FORMULARIO, $jaTratados, $log);
        }
    }

    /**
     * Quem enviou o formulário mas não pagou até a data de pagamento perde a vaga.
     * Só age onde há taxa e data de pagamento cadastradas.
     */
    private function processarPagamentos(object $activity, string $tipo, array &$log): void
    {
        $naoPagos = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'                => $activity->id,
                'PreRegistrations.subscription_type'          => $tipo,
                'PreRegistrations.deleted IS'                 => null,
                'PreRegistrations.is_quitter'                 => false,
                'PreRegistrations.disqualified_at IS'         => null,
                'PreRegistrations.selection_method_id IS NOT' => null,
                'PreRegistrations.form_submitted'             => true,
                'PreRegistrations.is_fee_paid'                => false,
            ])
            ->all()
            ->toArray();

        $jaTratados = [];

        foreach ($naoPagos as $preReg) {
            if (isset($jaTratados[$preReg->id])) continue;
            if ($this->mercadoPago->valorDaTaxa($preReg, $activity) <= 0) continue;

            $prazo = $this->mercadoPago->prazoPagamento($preReg, $activity);
            if ($prazo === null || $this->asDateTime($prazo)->getTimestamp() >= time()) continue;

            $this->tirarVaga($activity, $preReg, self::MOTIVO_PRAZO_PAGAMENTO, $jaTratados, $log);
        }
    }

    // ─── Perda de vaga ──────────────────────────────────────────────────────

    /**
     * Tira a vaga de um titular (e do cônjuge, se for casal campista). Para servo,
     * a pessoa também sai da equipe — o setor fica com a vaga aberta.
     */
    private function tirarVaga(object $activity, object $preReg, string $motivo, array &$jaTratados, array &$log): void
    {
        $tipo = (string)$preReg->subscription_type;
        $doGrupo = [$preReg];

        // Casal sai junto: mesmo que só um dos dois tenha perdido o prazo, o casal
        // é a unidade da vaga (mesma regra do sorteio de casais).
        if ($tipo === 'Campista' && $this->grupoDoCampista($preReg) === 'Casado') {
            $conjuge = $this->findConjugePreReg($preReg);
            if (
                $conjuge && $conjuge->subscription_type === 'Campista'
                && !$conjuge->is_quitter && $conjuge->disqualified_at === null
                && $conjuge->selection_method_id !== null
            ) {
                $doGrupo[] = $conjuge;
            }
        }

        $acsIds = $tipo === 'Servo' ? $this->acsIdsDaAtividade((int)$activity->id) : [];

        foreach ($doGrupo as $pr) {
            $this->desclassificar($pr, $motivo);
            $jaTratados[$pr->id] = true;

            if (!empty($acsIds)) {
                $this->AcsSectorsUsers->deleteAll([
                    'activities_categories_sector_id IN' => $acsIds,
                    'user_id'                            => $pr->user_id,
                    'is_coordinator'                     => false,
                ]);
            }

            $nome = $pr->user->name ?? "usuário #{$pr->user_id}";
            $log[] = "Atividade #{$activity->id}: {$nome} ({$tipo}) perdeu a vaga {$this->textoMotivo($motivo)}. A vaga ficou aberta para chamar o próximo suplente.";
        }
    }

    private function tirarSuplenteDaFila(object $activity, object $preReg, string $tipo, array &$jaTratados, array &$log): void
    {
        $this->desclassificar($preReg, self::MOTIVO_PRAZO_FORMULARIO);
        $jaTratados[$preReg->id] = true;

        if ($tipo === 'Servo') {
            $acsIds = $this->acsIdsDaAtividade((int)$activity->id);
            if (!empty($acsIds)) {
                $this->AcsSectorsUsers->deleteAll([
                    'activities_categories_sector_id IN' => $acsIds,
                    'user_id'                            => $preReg->user_id,
                    'is_substitute'                      => true,
                ]);
            }
        }

        $nome = $preReg->user->name ?? "usuário #{$preReg->user_id}";
        $pos  = (int)$preReg->substitute_position;
        $log[] = "Atividade #{$activity->id}: suplente {$nome} ({$tipo}, posição {$pos}) saiu da fila por não responder o formulário no prazo.";
    }

    private function textoMotivo(string $motivo): string
    {
        return $motivo === self::MOTIVO_PRAZO_PAGAMENTO
            ? 'por não pagar a inscrição no prazo'
            : 'por não responder o formulário no prazo';
    }

    // ─── Vagas abertas e "chamar o próximo" ─────────────────────────────────

    /**
     * Vagas de campista abertas por grupo: vagas perdidas (titulares desclassificados)
     * menos suplentes já chamados. Casal conta como uma vaga só.
     *
     * @return array<string, int> ['Homem' => n, 'Mulher' => n, 'Casado' => n]
     */
    public function vagasAbertasCampistas(int $activityId): array
    {
        $inscricoes = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'       => $activityId,
                'PreRegistrations.subscription_type' => 'Campista',
                'PreRegistrations.deleted IS'         => null,
            ])
            ->all()
            ->toArray();

        $conjugeDe = [];
        foreach ($inscricoes as $pr) {
            if ($pr->spouse_id) {
                $conjugeDe[$pr->user_id]   = (int)$pr->spouse_id;
                $conjugeDe[$pr->spouse_id] = (int)$pr->user_id;
            }
        }

        $perdidas  = array_fill_keys(self::GRUPOS_CAMPISTA, []);
        $chamadas  = array_fill_keys(self::GRUPOS_CAMPISTA, []);
        foreach ($inscricoes as $pr) {
            $grupo = $this->grupoDoCampista($pr);
            // Casal: os dois caem na mesma chave, então contam uma vaga só.
            $unidade = $grupo === 'Casado'
                ? min((int)$pr->user_id, $conjugeDe[$pr->user_id] ?? (int)$pr->user_id)
                : (int)$pr->user_id;

            if ($pr->disqualified_at !== null && $pr->selection_method_id !== null) {
                $perdidas[$grupo][$unidade] = true;
            }
            if ($pr->promoted_at !== null) {
                $chamadas[$grupo][$unidade] = true;
            }
        }

        $abertas = [];
        foreach (self::GRUPOS_CAMPISTA as $grupo) {
            $abertas[$grupo] = max(0, count($perdidas[$grupo]) - count($chamadas[$grupo]));
        }

        return $abertas;
    }

    /**
     * Vagas abertas num setor: vagas do setor menos quem está na equipe (líderes,
     * colocados à mão e sorteados titulares; suplente não ocupa vaga).
     */
    public function vagasAbertasSetor(object $acs): int
    {
        $ocupadas = $this->AcsSectorsUsers->find()
            ->where([
                'activities_categories_sector_id' => $acs->id,
                'OR' => ['is_substitute IS' => null, 'is_substitute' => false],
            ])
            ->count();

        return max(0, (int)$acs->actual_vacancies - $ocupadas);
    }

    /**
     * Chama o próximo suplente da fila de um grupo de campistas para uma vaga aberta.
     * Casal suplente entra completo.
     *
     * @return array{ok: bool, error?: string, chamados?: string[]}
     */
    public function chamarProximoCampista(int $activityId, string $grupo): array
    {
        if (!in_array($grupo, self::GRUPOS_CAMPISTA, true)) {
            return ['ok' => false, 'error' => 'Grupo inválido'];
        }
        if ($this->acampamentoJaComecou($activityId)) {
            return ['ok' => false, 'error' => self::ERRO_JA_COMECOU];
        }
        if (($this->vagasAbertasCampistas($activityId)[$grupo] ?? 0) <= 0) {
            return ['ok' => false, 'error' => "Não há vaga aberta de {$grupo}."];
        }

        $suplentes = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'                => $activityId,
                'PreRegistrations.subscription_type'          => 'Campista',
                'PreRegistrations.deleted IS'                 => null,
                'PreRegistrations.is_quitter'                 => false,
                'PreRegistrations.disqualified_at IS'         => null,
                'PreRegistrations.selection_method_id IS'     => null,
                'PreRegistrations.substitute_position IS NOT' => null,
            ])
            ->orderBy(['PreRegistrations.substitute_position' => 'ASC'])
            ->all()
            ->toArray();

        $doGrupo = array_values(array_filter(
            $suplentes,
            fn($pr) => $this->grupoDoCampista($pr) === $grupo
        ));
        if (empty($doGrupo)) {
            return ['ok' => false, 'error' => "Não há suplente de {$grupo} na fila."];
        }

        // Casal suplente ocupa uma posição só, compartilhada pelos dois.
        $posicao = (int)$doGrupo[0]->substitute_position;
        $chamar = $grupo === 'Casado'
            ? array_values(array_filter($doGrupo, fn($pr) => (int)$pr->substitute_position === $posicao))
            : [$doGrupo[0]];

        $nomes = [];
        foreach ($chamar as $pr) {
            if (!$this->promover($pr)) {
                return ['ok' => false, 'error' => 'Não foi possível chamar o suplente.'];
            }
            $nomes[] = $pr->user->name ?? "usuário #{$pr->user_id}";
        }

        return ['ok' => true, 'chamados' => $nomes];
    }

    /**
     * Chama o próximo suplente da fila de um setor para uma vaga aberta nele.
     *
     * @return array{ok: bool, error?: string, chamados?: string[]}
     */
    public function chamarProximoServo(int $activityId, int $acsId): array
    {
        $acs = $this->AcsSectors->find()
            ->where(['id' => $acsId, 'activity_id' => $activityId, 'deleted IS' => null])
            ->first();
        if (!$acs) {
            return ['ok' => false, 'error' => 'Setor não encontrado'];
        }
        if ($this->acampamentoJaComecou($activityId)) {
            return ['ok' => false, 'error' => self::ERRO_JA_COMECOU];
        }
        if ($this->vagasAbertasSetor($acs) <= 0) {
            return ['ok' => false, 'error' => 'Não há vaga aberta neste setor.'];
        }

        $fila = $this->AcsSectorsUsers->find()
            ->where([
                'activities_categories_sector_id' => $acsId,
                'is_coordinator'                  => false,
                'is_substitute'                   => true,
            ])
            ->orderBy(['raffle_position' => 'ASC'])
            ->all();

        foreach ($fila as $linha) {
            $pr = $this->PreRegistrations->find()
                ->contain(['Users'])
                ->where([
                    'PreRegistrations.activity_id'        => $activityId,
                    'PreRegistrations.user_id'            => $linha->user_id,
                    'PreRegistrations.subscription_type'  => 'Servo',
                    'PreRegistrations.deleted IS'         => null,
                    'PreRegistrations.is_quitter'         => false,
                    'PreRegistrations.disqualified_at IS' => null,
                ])
                ->first();
            if (!$pr) continue;

            if (!$this->promover($pr)) {
                return ['ok' => false, 'error' => 'Não foi possível chamar o suplente.'];
            }
            $linha->is_substitute = false;
            $this->AcsSectorsUsers->save($linha);

            return ['ok' => true, 'chamados' => [$pr->user->name ?? "usuário #{$pr->user_id}"]];
        }

        return ['ok' => false, 'error' => 'Não há suplente na fila deste setor.'];
    }

    // Depois do início não adianta chamar ninguém: o prazo de pagamento nunca passa
    // do início do acampamento, então quem fosse chamado já entraria com ele vencido.
    private function acampamentoJaComecou(int $activityId): bool
    {
        $activity = $this->Activities->find()->where(['id' => $activityId])->first();
        $inicio = $activity->start_date ?? null;

        return $inicio !== null && $this->asDateTime($inicio)->getTimestamp() <= time();
    }

    // ─── Operações básicas ──────────────────────────────────────────────────

    private function desclassificar(object $preReg, string $motivo): void
    {
        $preReg->disqualified_at = DateTime::now();
        $preReg->disqualification_reason = $motivo;
        $this->PreRegistrations->save($preReg);
    }

    /**
     * Suplente assume a vaga: passa a ser selecionado e sai da fila de suplentes.
     * O `promoted_at` marca quando foi chamado — é dele que contam os dias extras
     * para pagar (MercadoPagoService::prazoPagamento).
     */
    private function promover(object $preReg): bool
    {
        $raffleMethodId = $this->getRaffleMethodId();
        // Sem o método "Sorteio" cadastrado não há como registrar a promoção —
        // melhor não promover do que gravar um vínculo inválido.
        if ($raffleMethodId === 0) {
            return false;
        }

        $preReg->selection_method_id = $raffleMethodId;
        $preReg->substitute_position = null;
        $preReg->promoted_at = DateTime::now();

        return (bool)$this->PreRegistrations->save($preReg);
    }

    /** Homem / Mulher / Casado — mesma classificação usada no sorteio de campistas. */
    private function grupoDoCampista(object $preReg): string
    {
        $casadoId = $this->getCasadoMaritalStatusId();
        $msId = $preReg->user->marital_status_id ?? 0;

        if ($casadoId && (int)$msId === $casadoId) {
            return 'Casado';
        }

        return ($preReg->user->sex ?? '') === 'M' ? 'Homem' : 'Mulher';
    }

    /** Quantas perguntas o formulário desta atividade tem para o tipo de inscrição. */
    private function contarPerguntas(object $activity, string $subscriptionType): int
    {
        $categoryId = $activity->category_id ?? null;
        if ($categoryId === null) {
            return 0;
        }

        return $this->Questions->find()
            ->where([
                'category_id'       => $categoryId,
                'subscription_type' => $subscriptionType,
                'deleted IS'        => null,
            ])
            ->count();
    }

    private function acsIdsDaAtividade(int $activityId): array
    {
        return $this->AcsSectors->find()
            ->where(['activity_id' => $activityId, 'deleted IS' => null])
            ->all()
            ->map(fn($s) => $s->id)
            ->toArray();
    }

    // Mesma busca de cônjuge usada nas inscrições: o vínculo pode ter sido
    // declarado por qualquer um dos dois.
    private function findConjugePreReg(object $preReg): ?object
    {
        $orConditions = [['spouse_id' => $preReg->user_id]];
        if ($preReg->spouse_id) {
            $orConditions[] = ['user_id' => $preReg->spouse_id];
        }

        return $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id' => $preReg->activity_id,
                'PreRegistrations.id !='       => $preReg->id,
                'PreRegistrations.deleted IS'  => null,
                'OR'                           => $orConditions,
            ])
            ->first();
    }

    private function getCasadoMaritalStatusId(): int
    {
        $status = $this->MaritalStatuses->find()
            ->where(['title' => 'Casado', 'deleted IS' => null])
            ->first();

        return $status ? (int)$status->id : 0;
    }

    private function getRaffleMethodId(): int
    {
        $method = $this->SelectionMethods->find()
            ->where(['method' => 'Sorteio', 'deleted IS' => null])
            ->first();

        return $method ? (int)$method->id : 0;
    }

    private function asDateTime(mixed $valor): DateTime
    {
        if ($valor instanceof DateTime) {
            return $valor;
        }
        if ($valor instanceof \DateTimeInterface) {
            return new DateTime($valor->format('Y-m-d H:i:s'));
        }

        return new DateTime((string)$valor);
    }
}
