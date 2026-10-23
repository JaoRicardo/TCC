<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\TableLocator;

/**
 * Prazo do formulário: quem é selecionado (sorteado ou indicado) e não envia o
 * formulário até a data-limite cadastrada no acampamento perde a vaga, e o
 * primeiro suplente da fila assume no lugar.
 *
 * Regras que valem aqui:
 *
 * - O prazo é uma data fixa por acampamento, separada para campista
 *   (`camper_form_deadline`) e servo (`servant_form_deadline`). Acampamento sem
 *   prazo cadastrado nunca desclassifica ninguém — a automação só age onde o
 *   organizador definiu o prazo de propósito.
 * - Desclassificar não apaga o `selection_method_id`: ele continua registrando
 *   como a pessoa tinha sido escolhida, e é o `disqualified_at` que diz que a
 *   vaga não vale mais. Isso também evita que a pessoa volte pro pool e seja
 *   sorteada de novo num resorteio.
 * - Casal é a unidade, igual no sorteio: se um dos dois não responde, o casal
 *   inteiro sai e o próximo casal suplente entra completo.
 * - **Suplente também responde o formulário**, dentro do mesmo prazo do
 *   acampamento. É o que garante que, quando uma vaga é liberada, quem assume já
 *   está com a ficha entregue — não existe prazo extra depois da promoção.
 * - Suplente que deixa o prazo passar sem responder sai da fila: ele não pode ser
 *   chamado para uma vaga sem ter entregado o formulário.
 * - Pagamento é outra etapa, com data própria na atividade, e vale só para quem
 *   tem vaga de verdade — suplente não paga (ver `InscricoesController`).
 *
 * Roda pelo comando `bin/cake executar_prazos_formulario` e também de forma
 * oportunista nos endpoints mais acessados, no mesmo esquema já usado pelo
 * sorteio automático (SorteioService::executarSorteiosPendentes).
 */
class PrazoFormularioService
{
    /** Valor gravado em `disqualification_reason`. */
    public const MOTIVO_PRAZO_FORMULARIO = 'prazo_formulario';

    private $Activities;
    private $Campings;
    private $PreRegistrations;
    private $SelectionMethods;
    private $MaritalStatuses;
    private $AcsSectors;
    private $AcsSectorsUsers;
    private $Questions;

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
     * Varre todos os acampamentos com prazo cadastrado e já vencido, desclassifica
     * quem não enviou o formulário e promove os suplentes das vagas liberadas.
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
                // Sem prazo cadastrado a automação não age.
                if ($this->prazoGeral($camping, $tipo) === null) continue;

                $this->processarTipo($activity, $camping, $tipo, $log);
            }
        }

        return $log;
    }

    private function processarTipo(object $activity, object $camping, string $tipo, array &$log): void
    {
        // Proteção importante: sem pergunta cadastrada não existe formulário para
        // responder, então ninguém pode perder a vaga por "não ter respondido".
        // Sem isso, cadastrar um prazo num acampamento cujo formulário ainda não
        // foi montado desclassificaria todos os selecionados de uma vez.
        if ($this->contarPerguntas($activity, $tipo) === 0) {
            return;
        }

        // Candidatos: todo mundo que tinha formulário para entregar e não entregou —
        // titulares (sorteados/indicados) e suplentes. Suplente entra aqui porque,
        // no modelo atual, ele também precisa responder dentro do prazo.
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

        // Suplentes primeiro: quem não entregou sai da fila antes de alguém ser
        // chamado, para nunca promover um suplente sem formulário.
        usort($pendentes, fn($a, $b) => ($b->substitute_position !== null ? 1 : 0) <=> ($a->substitute_position !== null ? 1 : 0));

        $jaTratados = [];

        foreach ($pendentes as $preReg) {
            if (isset($jaTratados[$preReg->id])) continue;
            if (!$this->prazoVencido($preReg, $camping)) continue;

            // Suplente que não respondeu perde o lugar na fila, mas não libera vaga
            // nenhuma — ele ainda não tinha vaga.
            if ($preReg->substitute_position !== null) {
                $this->desclassificar($preReg);
                $jaTratados[$preReg->id] = true;
                $nome = $preReg->user->name ?? "usuário #{$preReg->user_id}";
                $pos  = (int)$preReg->substitute_position;
                $log[] = "Atividade #{$activity->id}: suplente {$nome} ({$tipo}, posição {$pos}) saiu da fila por não responder o formulário no prazo.";
                continue;
            }

            if ($tipo === 'Campista') {
                $this->tratarCampistaVencido($activity, $camping, $preReg, $jaTratados, $log);
            } else {
                $this->tratarServoVencido($activity, $camping, $preReg, $jaTratados, $log);
            }
        }
    }

    // ─── Campistas ──────────────────────────────────────────────────────────

    private function tratarCampistaVencido(
        object $activity,
        object $camping,
        object $preReg,
        array &$jaTratados,
        array &$log
    ): void {
        $grupo = $this->grupoDoCampista($preReg);

        // Casal sai junto: mesmo que só um dos dois tenha deixado o prazo passar,
        // o casal é a unidade da vaga (mesma regra do sorteio de casais).
        $doGrupo = [$preReg];
        if ($grupo === 'Casado') {
            $conjuge = $this->findConjugePreReg($preReg);
            if (
                $conjuge && $conjuge->subscription_type === 'Campista'
                && !$conjuge->is_quitter && $conjuge->disqualified_at === null
                && $conjuge->selection_method_id !== null
            ) {
                $doGrupo[] = $conjuge;
            }
        }

        foreach ($doGrupo as $pr) {
            $this->desclassificar($pr);
            $jaTratados[$pr->id] = true;
            $nome = $pr->user->name ?? "usuário #{$pr->user_id}";
            $log[] = "Atividade #{$activity->id}: {$nome} (Campista) perdeu a vaga por não responder o formulário no prazo.";
        }

        $this->promoverSuplenteCampista($activity, $camping, $grupo, $log);
    }

    /**
     * Promove o próximo suplente do mesmo grupo (Homem/Mulher/Casado) para a vaga
     * liberada. Para casais promove os dois de uma vez.
     *
     * Só entra na fila quem **já enviou o formulário**: é justamente por isso que
     * o suplente responde dentro do prazo normal — assim a vaga é preenchida na
     * hora, sem precisar dar um prazo extra a quem foi chamado.
     */
    private function promoverSuplenteCampista(object $activity, object $camping, string $grupo, array &$log): void
    {
        $suplentes = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'                => $activity->id,
                'PreRegistrations.subscription_type'          => 'Campista',
                'PreRegistrations.deleted IS'                 => null,
                'PreRegistrations.is_quitter'                 => false,
                'PreRegistrations.disqualified_at IS'         => null,
                'PreRegistrations.selection_method_id IS'     => null,
                'PreRegistrations.substitute_position IS NOT' => null,
                'PreRegistrations.form_submitted'             => true,
            ])
            ->orderBy(['PreRegistrations.substitute_position' => 'ASC'])
            ->all()
            ->toArray();

        $doGrupo = array_values(array_filter(
            $suplentes,
            fn($pr) => $this->grupoDoCampista($pr) === $grupo
        ));

        if (empty($doGrupo)) {
            $log[] = "Atividade #{$activity->id}: vaga de {$grupo} liberada, mas não há suplente com formulário enviado na fila.";
            return;
        }

        $posicao = (int)$doGrupo[0]->substitute_position;

        // Casal suplente ocupa uma posição só, compartilhada pelos dois — promove
        // todos os que estão naquela posição.
        $promover = $grupo === 'Casado'
            ? array_values(array_filter($doGrupo, fn($pr) => (int)$pr->substitute_position === $posicao))
            : [$doGrupo[0]];

        foreach ($promover as $pr) {
            $nome = $pr->user->name ?? "usuário #{$pr->user_id}";
            if (!$this->promover($pr, $camping)) {
                $log[] = "Atividade #{$activity->id}: não foi possível promover o suplente {$nome} (Campista).";
                continue;
            }
            $log[] = "Atividade #{$activity->id}: suplente {$nome} (Campista, posição {$posicao}) assumiu a vaga liberada — formulário já estava entregue.";
        }
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

    // ─── Servos ─────────────────────────────────────────────────────────────

    private function tratarServoVencido(
        object $activity,
        object $camping,
        object $preReg,
        array &$jaTratados,
        array &$log
    ): void {
        // Equipe em que a pessoa estava, para chamar um suplente do mesmo setor.
        $acsIds = $this->acsIdsDaAtividade((int)$activity->id);
        $vinculo = null;
        if (!empty($acsIds)) {
            $vinculo = $this->AcsSectorsUsers->find()
                ->where([
                    'activities_categories_sector_id IN' => $acsIds,
                    'user_id'                            => $preReg->user_id,
                    'is_coordinator'                     => false,
                ])
                ->first();
        }

        $this->desclassificar($preReg);
        $jaTratados[$preReg->id] = true;
        $nome = $preReg->user->name ?? "usuário #{$preReg->user_id}";
        $log[] = "Atividade #{$activity->id}: {$nome} (Servo) perdeu a vaga por não responder o formulário no prazo.";

        if (!$vinculo) {
            $log[] = "Atividade #{$activity->id}: vaga de servo liberada sem equipe identificada — nenhum suplente foi chamado automaticamente.";
            return;
        }

        $acsId = (int)$vinculo->activities_categories_sector_id;

        // Quem perdeu a vaga sai da equipe; o registro de que perdeu fica na
        // própria inscrição (disqualified_at).
        $this->AcsSectorsUsers->delete($vinculo);

        $suplentes = $this->AcsSectorsUsers->find()
            ->where([
                'activities_categories_sector_id' => $acsId,
                'is_coordinator'                  => false,
                'is_substitute'                   => true,
            ])
            ->orderBy(['raffle_position' => 'ASC'])
            ->all();

        foreach ($suplentes as $linha) {
            $prSuplente = $this->PreRegistrations->find()
                ->contain(['Users'])
                ->where([
                    'PreRegistrations.activity_id'        => $activity->id,
                    'PreRegistrations.user_id'            => $linha->user_id,
                    'PreRegistrations.subscription_type'  => 'Servo',
                    'PreRegistrations.deleted IS'         => null,
                    'PreRegistrations.is_quitter'         => false,
                    'PreRegistrations.disqualified_at IS' => null,
                    // Mesma regra dos campistas: só assume a vaga quem já entregou
                    // o formulário dentro do prazo.
                    'PreRegistrations.form_submitted'     => true,
                ])
                ->first();

            if (!$prSuplente) continue;

            $nomeSup = $prSuplente->user->name ?? "usuário #{$prSuplente->user_id}";
            if (!$this->promover($prSuplente, $camping)) {
                $log[] = "Atividade #{$activity->id}: não foi possível promover o suplente {$nomeSup} (Servo).";
                return;
            }

            $linha->is_substitute = false;
            $this->AcsSectorsUsers->save($linha);

            $log[] = "Atividade #{$activity->id}: suplente {$nomeSup} (Servo) assumiu a vaga liberada na equipe #{$acsId} — formulário já estava entregue.";
            return;
        }

        $log[] = "Atividade #{$activity->id}: vaga de servo liberada na equipe #{$acsId}, mas não há suplente com formulário enviado.";
    }

    // ─── Operações básicas ──────────────────────────────────────────────────

    private function desclassificar(object $preReg): void
    {
        $preReg->disqualified_at = DateTime::now();
        $preReg->disqualification_reason = self::MOTIVO_PRAZO_FORMULARIO;
        $this->PreRegistrations->save($preReg);
    }

    /**
     * Suplente assume a vaga: passa a ser selecionado e sai da fila de suplentes.
     * Não ganha prazo novo — o formulário dele já foi entregue (é pré-requisito
     * para estar nesta fila), e o que falta é só o pagamento, que tem data própria.
     */
    private function promover(object $preReg, ?object $camping): bool
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

        return new DateTime((string)$valor);
    }
}
