<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\FormCorrectionsTable;
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
 *   precisa pagar (PagamentoService::precisaPagar) e só onde existe valor. A
 *   vaga só sai PagamentoService::DIAS_DE_FOLGA dias depois do prazo, para a
 *   equipe registrar quem pagou no último dia. Vale também para evento.
 *   Suplente chamado para uma vaga ganha alguns dias a partir de quando foi
 *   chamado (ver PagamentoService::prazo).
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
 * oportunista nos endpoints mais acessados (protegido por TravaExecucao).
 */
class PrazoFormularioService
{
    /** Valores gravados em `disqualification_reason`. */
    public const MOTIVO_PRAZO_FORMULARIO = 'prazo_formulario';
    public const MOTIVO_PRAZO_PAGAMENTO  = 'prazo_pagamento';
    /** A organização cancelou a inscrição (aba Respostas), com o motivo escrito. */
    public const MOTIVO_CANCELADA        = 'cancelada_organizacao';
    /** Correção pedida e não feita até o início da atividade: a inscrição fica "Recusada". */
    public const MOTIVO_CORRECAO         = 'correcao_nao_feita';
    /** Motivos definitivos: um resorteio não devolve a pessoa ao sorteio. */
    public const MOTIVOS_DEFINITIVOS     = [self::MOTIVO_CANCELADA, self::MOTIVO_CORRECAO];

    public const GRUPOS_CAMPISTA = ['Homem', 'Mulher', 'Casado'];

    private const ERRO_JA_COMECOU = 'O acampamento já começou; não dá mais para chamar suplentes.';

    private $Activities;
    private $Campings;
    private $PreRegistrations;
    private $SelectionMethods;
    private $AcsSectors;
    private $AcsSectorsUsers;
    private $Questions;
    private $FormCorrections;
    private $UserChangeLogs;
    private $UserDocuments;
    private PagamentoService $pagamentos;

    public function __construct()
    {
        $locator = new TableLocator();
        $this->Activities       = $locator->get('Activities');
        $this->Campings         = $locator->get('Campings');
        $this->PreRegistrations = $locator->get('PreRegistrations');
        $this->SelectionMethods = $locator->get('SelectionMethods');
        $this->AcsSectors       = $locator->get('ActivitiesCategoriesSectors');
        $this->AcsSectorsUsers  = $locator->get('ActivitiesCategoriesSectorsUsers');
        $this->Questions        = $locator->get('Questions');
        $this->FormCorrections  = $locator->get('FormCorrections');
        $this->UserChangeLogs   = $locator->get('UserChangeLogs');
        $this->UserDocuments    = $locator->get('UserDocuments');
        $this->pagamentos       = new PagamentoService();
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
        return TravaExecucao::executar('prazos_vencidos', fn() => $this->prazosVencidos(), []);
    }

    private function prazosVencidos(): array
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
            }
        }

        // Pagamento: acampamento e evento, onde houver valor e prazo.
        $comValor = $this->Activities->find()
            ->where([
                'deleted IS' => null,
                'OR' => [
                    ['participant_fee >' => 0, 'participant_payment_date IS NOT' => null],
                    ['servant_fee >' => 0, 'servant_payment_date IS NOT' => null],
                ],
            ])
            ->all();
        foreach ($comValor as $activity) {
            $this->processarPagamentos($activity, $log);
        }

        $this->recusarCorrecoesNaoFeitas($log);
        $apagados = $this->UserDocuments->limparNaoEnviados();
        if ($apagados) {
            $log[] = "{$apagados} arquivo(s) de formulários nunca enviados foram apagados.";
        }

        return $log;
    }

    /**
     * Correção pedida e não feita até o prazo dela (ou até o início da
     * atividade, se não tiver prazo): a inscrição fica "Recusada" e a vaga entra
     * em "Chamar próximo", como no prazo perdido (evento: a vaga volta).
     */
    private function recusarCorrecoesNaoFeitas(array &$log): void
    {
        $vencidas = $this->FormCorrections->find()
            ->contain(['PreRegistrations' => ['Users', 'Activities']])
            ->where([
                'FormCorrections.resolved_at IS' => null,
                'OR' => [
                    'FormCorrections.deadline <' => date('Y-m-d H:i:s'),
                    'Activities.start_date <='   => date('Y-m-d H:i:s'),
                ],
            ])
            ->all();

        foreach ($vencidas as $pedido) {
            $preReg = $pedido->pre_registration;
            $this->FormCorrections->resolver((int)$preReg->id, FormCorrectionsTable::RECUSADO, null);
            if ($preReg->deleted !== null || $preReg->disqualified_at !== null) continue;

            $activity = $preReg->activity;
            $quando = $pedido->deadline !== null
                ? $pedido->deadline->format('d/m/Y')
                : 'o início ' . ($activity->camping_id === null ? 'do evento' : 'do acampamento');
            $jaTratados = [];
            $recusadas = $this->tirar($activity, $preReg, self::MOTIVO_CORRECAO, $jaTratados, $log, null, "Correção não feita até {$quando}.");
            foreach ($recusadas as $pr) {
                $this->UserChangeLogs->registrar(
                    (int)$pr->user_id,
                    null,
                    "Inscrição em {$activity->name} recusada: correção não feita até {$quando}."
                );
            }
        }
    }

    /**
     * A organização cancela a inscrição (aba Respostas), com o motivo que a
     * pessoa vai ler no site. Acampamento: a vaga entra em "Chamar próximo"
     * (casal sai junto; servo sai da equipe; suplente sai da fila). Evento: a
     * vaga volta para as inscrições. Não dá para desfazer.
     *
     * @return list<object> as inscrições canceladas (a pedida e, num casal, a do cônjuge)
     */
    public function cancelarPelaOrganizacao(object $preReg, string $motivo, int $por): array
    {
        $activity = $this->Activities->get($preReg->activity_id);
        $preReg = $this->PreRegistrations->get($preReg->id, contain: ['Users']);

        $log = [];
        $jaTratados = [];
        $canceladas = $this->tirar($activity, $preReg, self::MOTIVO_CANCELADA, $jaTratados, $log, $por, $motivo);
        foreach ($canceladas as $pr) {
            $this->FormCorrections->resolver((int)$pr->id, FormCorrectionsTable::CANCELADO, $por);
            $this->UserChangeLogs->registrar(
                (int)$pr->user_id,
                $por,
                "Inscrição em {$activity->name} cancelada pela organização: “{$motivo}”"
            );
        }

        return $canceladas;
    }

    /**
     * Tira a vaga do titular ou o lugar na fila do suplente.
     *
     * @return list<object> as inscrições atingidas
     */
    private function tirar(object $activity, object $preReg, string $motivo, array &$jaTratados, array &$log, ?int $por, ?string $nota): array
    {
        if ($preReg->substitute_position !== null) {
            $this->tirarSuplenteDaFila($activity, $preReg, (string)$preReg->subscription_type, $jaTratados, $log, $motivo, $por, $nota);

            return [$preReg];
        }

        return $this->tirarVaga($activity, $preReg, $motivo, $jaTratados, $log, $por, $nota);
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
     * Quem precisa pagar e não tem pagamento registrado perde a vaga quando passam
     * o prazo e os dias de folga (PagamentoService). Acampamento: a vaga entra em
     * "Chamar próximo" (casal sai junto); evento: a vaga volta para as inscrições.
     */
    private function processarPagamentos(object $activity, array &$log): void
    {
        $naoPagos = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'        => $activity->id,
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.is_quitter'         => false,
                'PreRegistrations.disqualified_at IS' => null,
                'PreRegistrations.is_fee_paid'        => false,
            ])
            ->all()
            ->toArray();

        $jaTratados = [];

        foreach ($naoPagos as $preReg) {
            if (isset($jaTratados[$preReg->id])) continue;
            if (!$this->pagamentos->deveTirarVaga($preReg, $activity)) continue;

            $prazo = $this->pagamentos->prazo($preReg, $activity)?->format('d/m/Y');
            foreach ($this->tirarVaga($activity, $preReg, self::MOTIVO_PRAZO_PAGAMENTO, $jaTratados, $log) as $pr) {
                $this->UserChangeLogs->registrar(
                    (int)$pr->user_id,
                    null,
                    "Inscrição em {$activity->name} perdeu a vaga: pagamento não registrado até {$prazo}."
                );
            }
        }
    }

    // ─── Perda de vaga ──────────────────────────────────────────────────────

    /**
     * Tira a vaga de um titular (e do cônjuge, se for casal campista). Para servo,
     * a pessoa também sai da equipe — o setor fica com a vaga aberta.
     *
     * @return list<object> as inscrições que perderam a vaga
     */
    private function tirarVaga(
        object $activity,
        object $preReg,
        string $motivo,
        array &$jaTratados,
        array &$log,
        ?int $por = null,
        ?string $nota = null
    ): array {
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
            $this->desclassificar($pr, $motivo, $por, $nota);
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

        return $doGrupo;
    }

    private function tirarSuplenteDaFila(
        object $activity,
        object $preReg,
        string $tipo,
        array &$jaTratados,
        array &$log,
        string $motivo = self::MOTIVO_PRAZO_FORMULARIO,
        ?int $por = null,
        ?string $nota = null
    ): void {
        $this->desclassificar($preReg, $motivo, $por, $nota);
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
        $log[] = "Atividade #{$activity->id}: suplente {$nome} ({$tipo}, posição {$pos}) saiu da fila {$this->textoMotivo($motivo)}.";
    }

    private function textoMotivo(string $motivo): string
    {
        return match ($motivo) {
            self::MOTIVO_PRAZO_PAGAMENTO => 'por não pagar a inscrição no prazo',
            self::MOTIVO_CANCELADA       => 'porque a organização cancelou a inscrição',
            self::MOTIVO_CORRECAO        => 'por não corrigir o formulário no prazo',
            default                      => 'por não responder o formulário no prazo',
        };
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
    /** Novo prazo de quem acabou de ser chamado da fila (formulário e pagamento). */
    private function prazoDoChamado(object $pr): ?string
    {
        $activity = $this->Activities->find()->where(['id' => $pr->activity_id])->first();
        if (!$activity) return null;

        return $this->pagamentos->prazo($pr, $activity)?->format('Y-m-d\TH:i');
    }

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

        return ['ok' => true, 'chamados' => $nomes, 'prazo' => $this->prazoDoChamado($chamar[0])];
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

            return ['ok' => true, 'chamados' => [$pr->user->name ?? "usuário #{$pr->user_id}"], 'prazo' => $this->prazoDoChamado($pr)];
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

    private function desclassificar(object $preReg, string $motivo, ?int $por = null, ?string $nota = null): void
    {
        $preReg->disqualified_at = DateTime::now();
        $preReg->disqualification_reason = $motivo;
        $preReg->disqualified_by = $por;
        $preReg->disqualification_note = $nota;
        $this->PreRegistrations->save($preReg);
    }

    /**
     * Suplente assume a vaga: passa a ser selecionado e sai da fila de suplentes.
     * O `promoted_at` marca quando foi chamado — é dele que contam os dias extras
     * para pagar (PagamentoService::prazo).
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

    /**
     * Homem / Mulher / Casado — mesma classificação do sorteio de campistas:
     * casal só na categoria Casais; fora dela, quem é casado é homem ou mulher.
     */
    private function grupoDoCampista(object $preReg): string
    {
        $id = (int)$preReg->activity_id;
        $this->ehCasais[$id] ??= SorteioService::categoriaDeCasais($id);
        if ($this->ehCasais[$id]) {
            return 'Casado';
        }

        return ($preReg->user->sex ?? '') === 'M' ? 'Homem' : 'Mulher';
    }

    /** @var array<int, bool> atividade => é da categoria Casais */
    private array $ehCasais = [];

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
