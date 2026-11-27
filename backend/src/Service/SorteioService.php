<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\TableLocator;

/**
 * Toda a lógica de sorteio (campistas e servos) vive aqui, fora do controller.
 *
 * O sorteio é só manual (decisão do usuário, handoff
 * design_handoff_painel_acampamento): a organização sorteia em Inscritos e
 * Sorteios. A data do sorteio no acampamento é só a data prevista; quando o
 * sorteio é feito fica em campings.camper_raffle_done_at / servant_raffle_done_at.
 */
class SorteioService
{
    private $Activities;
    private $Campings;
    private $PreRegistrations;
    private $SelectionMethods;
    private $AcsSectors;
    private $AcsSectorsUsers;
    private $CategoriesSectors;
    private $ActivitiesGeneralServants;
    private $Addresses;

    // Só residentes dessas cidades são elegíveis pra serem sorteados/indicados.
    // Comparação sempre normalizada (minúsculo, sem acento, sem espaços nas pontas).
    private const CIDADES_ELEGIVEIS = ['primavera do leste', 'poxoreu'];

    public function __construct()
    {
        $locator = new TableLocator();
        $this->Activities               = $locator->get('Activities');
        $this->Campings                 = $locator->get('Campings');
        $this->PreRegistrations         = $locator->get('PreRegistrations');
        $this->SelectionMethods         = $locator->get('SelectionMethods');
        $this->AcsSectors               = $locator->get('ActivitiesCategoriesSectors');
        $this->AcsSectorsUsers          = $locator->get('ActivitiesCategoriesSectorsUsers');
        $this->CategoriesSectors        = $locator->get('CategoriesSectors');
        $this->ActivitiesGeneralServants = $locator->get('ActivitiesGeneralServants');
        $this->Addresses                 = $locator->get('Addresses');
    }

    private static function normalizeCidade(string $cidade): string
    {
        $cidade = mb_strtolower(trim($cidade));
        $comAcento    = ['á','à','â','ã','é','ê','í','ó','ô','õ','ú','ç'];
        $semAcento    = ['a','a','a','a','e','e','i','o','o','o','u','c'];
        return str_replace($comAcento, $semAcento, $cidade);
    }

    /** A cidade está na área dos sorteios (Primavera do Leste ou Poxoréu)? */
    public static function moraNaArea(?string $cidade): bool
    {
        return in_array(self::normalizeCidade((string)$cidade), self::CIDADES_ELEGIVEIS, true);
    }

    // IDs de usuário cuja cidade cadastrada está na lista de cidades elegíveis
    // pra sorteio/indicação (residentes de fora não são escolhidos).
    private function getEligibleCityUserIds(): array
    {
        $enderecos = $this->Addresses->find()
            ->select(['user_id', 'city'])
            ->where(['deleted IS' => null])
            ->all();

        $ids = [];
        foreach ($enderecos as $end) {
            if (self::moraNaArea($end->city)) {
                $ids[$end->user_id] = true;
            }
        }
        return $ids;
    }

    /** A categoria do acampamento é só de casais (a única com vagas de casais — couples_only)? */
    public static function categoriaDeCasais(int $activityId): bool
    {
        $activity = (new TableLocator())->get('Activities')->find()
            ->contain(['Categories'])
            ->where(['Activities.id' => $activityId])
            ->first();

        return CategoriasService::soCasais($activity?->category);
    }

    // ─── Situação do sorteio ────────────────────────────────────────────────

    /**
     * Quando a organização fez o sorteio deste tipo (Campista ou Servo), ou null
     * se ainda não fez. Sorteio feito antes de a data ser guardada (migração
     * AddSorteioFeitoEm) conta como feito, mas sem data: devolve `true`.
     */
    public function sorteioFeitoEm(int $activityId, string $tipo): \Cake\I18n\DateTime|bool|null
    {
        $feito = $tipo === 'Servo' ? $this->servosJaRealizado($activityId) : $this->campistasJaRealizado($activityId);
        if (!$feito) return null;

        $activity = $this->Activities->find()->select(['camping_id'])->where(['id' => $activityId])->first();
        $camping  = $activity?->camping_id ? $this->Campings->find()->where(['id' => $activity->camping_id])->first() : null;
        $em       = $tipo === 'Servo' ? $camping?->servant_raffle_done_at : $camping?->camper_raffle_done_at;

        return $em ?? true;
    }

    /** Guarda (ou limpa, com null) quando o sorteio deste tipo foi feito. */
    private function marcarFeitoEm(int $activityId, string $tipo, ?string $quando): void
    {
        $activity = $this->Activities->find()->select(['camping_id'])->where(['id' => $activityId])->first();
        if (!$activity?->camping_id) return;
        $coluna = $tipo === 'Servo' ? 'servant_raffle_done_at' : 'camper_raffle_done_at';
        $this->Campings->updateAll([$coluna => $quando], ['id' => $activity->camping_id]);
    }

    /** O sorteio deste tipo tem a data de quando foi feito (apagada no reset). */
    private function feitoEmGravado(int $activityId, string $tipo): bool
    {
        $activity = $this->Activities->find()->select(['camping_id'])->where(['id' => $activityId])->first();
        if (!$activity?->camping_id) return false;
        $camping = $this->Campings->find()->where(['id' => $activity->camping_id])->first();

        return ($tipo === 'Servo' ? $camping?->servant_raffle_done_at : $camping?->camper_raffle_done_at) !== null;
    }

    public function campistasJaRealizado(int $activityId): bool
    {
        // Sorteio em que ninguém pôde ser sorteado (ex.: ninguém mora em
        // Primavera do Leste/Poxoréu) continua feito: vale a data gravada.
        if ($this->feitoEmGravado($activityId, 'Campista')) return true;

        $raffleMethodId = $this->getRaffleMethodId();

        return $this->PreRegistrations->find()
            ->where([
                'activity_id'       => $activityId,
                'subscription_type' => 'Campista',
                'deleted IS'        => null,
                'OR'                => [
                    'selection_method_id'        => $raffleMethodId,
                    'substitute_position IS NOT' => null,
                ],
            ])
            ->count() > 0;
    }

    public function servosJaRealizado(int $activityId): bool
    {
        if ($this->feitoEmGravado($activityId, 'Servo')) return true;

        $acsIds = $this->AcsSectors->find()
            ->where(['activity_id' => $activityId, 'deleted IS' => null])
            ->all()
            ->map(fn($s) => $s->id)
            ->toArray();
        if (empty($acsIds)) return false;

        return $this->AcsSectorsUsers->find()
            ->where([
                'activities_categories_sector_id IN' => $acsIds,
                'is_coordinator'                     => false,
                'raffle_position IS NOT'              => null,
            ])
            ->count() > 0;
    }

    // ─── Sorteio de Campistas ───────────────────────────────────────────────

    /**
     * Campistas que entrariam no sorteio se ele fosse feito agora, antes do
     * filtro da cidade: sem indicação, sem ter desistido, saído ou sido retirado.
     * Depois de um sorteio, os sorteados (que ganharam o método "Sorteio") ficam
     * de fora desta lista — ela só serve antes do sorteio e no próprio sorteio
     * (que zera o resultado antes de chamar).
     *
     * @return array<object>
     */
    private function candidatosCampistas(int $activityId): array
    {
        return $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'            => $activityId,
                'PreRegistrations.subscription_type'      => 'Campista',
                'PreRegistrations.deleted IS'              => null,
                'PreRegistrations.is_quitter'              => false,
                'PreRegistrations.selection_method_id IS' => null,
                // Retirado do sorteio ao corrigir nascimento, sexo ou cidade na ficha.
                'PreRegistrations.removed_from_raffle_at IS' => null,
                // Cancelado pela organização ou recusado: não concorre de novo.
                'PreRegistrations.disqualified_at IS'        => null,
                // Casal de Casais que o cônjuge ainda não confirmou (CasalService).
                'PreRegistrations.awaiting_spouse'           => false,
                'Users.deleted IS'                           => null,
            ])
            ->all()
            ->toArray();
    }

    /**
     * Quem tem resultado do sorteio (com vaga pelo sorteio ou na fila de
     * suplentes) e já enviou o formulário ou já pagou: com alguém assim, o
     * sorteio não pode mais ser refeito nem apagado (decisão do usuário,
     * 2026-11-23). O pagamento pode ser adiantado, antes do formulário.
     *
     * @return array{formularios: int, pagos: int}
     */
    public function bloqueioDoSorteio(int $activityId, string $tipo): array
    {
        $doResultado = $this->PreRegistrations->find()
            ->where([
                'activity_id'       => $activityId,
                'subscription_type' => $tipo,
                'deleted IS'        => null,
                'is_quitter'        => false,
                'disqualified_at IS' => null,
                'OR' => [
                    'selection_method_id'        => $this->getRaffleMethodId(),
                    'substitute_position IS NOT' => null,
                ],
            ])
            ->all();

        $formularios = 0;
        $pagos = 0;
        foreach ($doResultado as $pr) {
            if ($pr->form_submitted) $formularios++;
            if ($pr->is_fee_paid) $pagos++;
        }

        return ['formularios' => $formularios, 'pagos' => $pagos];
    }

    public function sorteioBloqueado(int $activityId, string $tipo): bool
    {
        $b = $this->bloqueioDoSorteio($activityId, $tipo);

        return $b['formularios'] + $b['pagos'] > 0;
    }

    /** O acampamento (campings) da atividade, ou null. */
    private function acampamentoDa(int $activityId): ?object
    {
        $activity = $this->Activities->find()->select(['camping_id'])->where(['id' => $activityId])->first();

        return $activity?->camping_id ? $this->Campings->find()->where(['id' => $activity->camping_id])->first() : null;
    }

    /** Quando o sorteio foi feito (Y-m-d\TH:i), ou null (não feito, ou feito antes de a data ser guardada). */
    private function dataDoSorteio(?object $camping, string $tipo): ?string
    {
        $em = $tipo === 'Servo' ? $camping?->servant_raffle_done_at : $camping?->camper_raffle_done_at;

        return $em?->format('Y-m-d\TH:i');
    }

    /** Candidatos que não concorrem porque não moram na área: nome e cidade, para a tela explicar. */
    private function naoConcorrem(array $candidatos, callable $grupoDe): array
    {
        $daArea = $this->getEligibleCityUserIds();
        $fora = array_filter($candidatos, fn($pr) => !isset($daArea[$pr->user_id]));
        if (!$fora) return [];

        $cidades = [];
        foreach ($this->Addresses->find()->where(['user_id IN' => array_map(fn($pr) => $pr->user_id, $fora), 'deleted IS' => null])->all() as $end) {
            $cidades[$end->user_id] = $end->city;
        }

        return array_values(array_map(fn($pr) => [
            'user_id'   => (int)$pr->user_id,
            'nome'      => $pr->user->name,
            'categoria' => $grupoDe($pr),
            'cidade'    => $cidades[$pr->user_id] ?? null,
        ], $fora));
    }

    public function realizarCampistas(int $activityId, int $homem, int $mulher, int $casado): array
    {
        $raffleMethodId = $this->getRaffleMethodId();

        // Reset só o resultado do sorteio anterior (Sorteados/Suplentes). Indicações
        // (Base/Forania/Conselho) nunca são tocadas aqui — sobrevivem a quantos
        // resorteios forem feitos, até serem removidas manualmente via removeIndicacao.
        $this->zerarResultado($activityId, 'Campista', $raffleMethodId);

        // Pool do sorteio: só quem ainda não tem indicação — quem já foi indicado
        // tem vaga garantida fora do sorteio e não concorre nem conta nas vagas informadas.
        // Só residentes de Primavera do Leste/Poxoréu são elegíveis pra serem sorteados.
        $cidadesElegiveis = $this->getEligibleCityUserIds();
        $campistas = array_values(array_filter(
            $this->candidatosCampistas($activityId),
            fn($pr) => isset($cidadesElegiveis[$pr->user_id])
        ));

        // Casais só existem na categoria Casais (que só tem casais): lá todo mundo
        // concorre como casal. Nas outras, quem é casado concorre como homem ou mulher.
        $ehCasais = self::categoriaDeCasais($activityId);
        $grupos = ['Homem' => [], 'Mulher' => [], 'Casado' => []];
        foreach ($campistas as $pr) {
            if ($ehCasais) {
                $grupos['Casado'][] = $pr;
            } elseif ($pr->user->sex === 'M') {
                $grupos['Homem'][] = $pr;
            } else {
                $grupos['Mulher'][] = $pr;
            }
        }

        $vagas       = ['Homem' => $homem, 'Mulher' => $mulher];
        $suplantePos = 1;

        foreach (['Homem', 'Mulher'] as $cat) {
            $pool = $grupos[$cat];
            shuffle($pool);
            $n = $vagas[$cat];
            foreach ($pool as $idx => $pr) {
                if ($idx < $n) {
                    $pr->selection_method_id = $raffleMethodId;
                    $pr->substitute_position = null;
                    $this->PreRegistrations->save($pr);
                } elseif ($idx < $n * 2) {
                    $pr->selection_method_id = null;
                    $pr->substitute_position = $suplantePos++;
                    $this->PreRegistrations->save($pr);
                }
            }
        }

        // Casais: o casal é a unidade do sorteio — nunca separa os dois entre
        // titular, suplente e não sorteado. Agrupa em pares antes de sortear.
        // Só casal completo concorre: quem ficou sem o par (cônjuge de fora da
        // área, que desistiu ou não se inscreveu) não ocupa uma vaga de casal.
        $pares = array_values(array_filter($this->pairUpCasais($grupos['Casado']), fn($par) => count($par) === 2));
        shuffle($pares);
        foreach ($pares as $idx => $par) {
            if ($idx < $casado) {
                foreach ($par as $pr) {
                    $pr->selection_method_id = $raffleMethodId;
                    $pr->substitute_position = null;
                    $this->PreRegistrations->save($pr);
                }
            } elseif ($idx < $casado * 2) {
                $pos = $suplantePos++;
                foreach ($par as $pr) {
                    $pr->selection_method_id = null;
                    $pr->substitute_position = $pos;
                    $this->PreRegistrations->save($pr);
                }
            }
        }

        $this->marcarFeitoEm($activityId, 'Campista', date('Y-m-d H:i:s'));

        return $this->buildCampistasResult($activityId, $homem, $mulher, $casado);
    }

    public function resetarCampistas(int $activityId): void
    {
        $raffleMethodId = $this->getRaffleMethodId();

        // Só zera o resultado do sorteio — indicações permanecem intactas.
        $this->zerarResultado($activityId, 'Campista', $raffleMethodId);
        $this->marcarFeitoEm($activityId, 'Campista', null);
    }

    // ─── Sorteio de Servos ──────────────────────────────────────────────────

    /**
     * Sorteia só as vagas que sobraram em cada setor, depois de descontar quem já
     * está na equipe (líder do setor e quem foi colocado manualmente pelo líder,
     * servo geral ou admin). Indicados no nível geral (Base/Forania/Conselho) e o
     * servo geral também ficam fora do sorteio. Regra confirmada com o usuário:
     * a organização monta as equipes à mão, e o sorteio completa o que faltar. O pool do
     * sorteio é todo servo inscrito nesta atividade que ainda não está em nenhuma
     * das duas situações acima — não depende de a pessoa ter "escolhido" um setor,
     * o sorteio distribui quem sobrou entre os setores com vaga aberta, do mesmo
     * jeito que o sorteio de campistas distribui por Homem/Mulher/Casado.
     */
    public function realizarServos(int $activityId): array
    {
        $raffleMethodId = $this->getRaffleMethodId();

        // Indicados no nível geral (Base/Forania/Conselho) — vaga garantida, fora do sorteio.
        $indicatedUserIds = $this->PreRegistrations->find()
            ->where([
                'activity_id'                 => $activityId,
                'subscription_type'           => 'Servo',
                'deleted IS'                  => null,
                'selection_method_id IS NOT'  => null,
                'selection_method_id !='      => $raffleMethodId,
            ])
            ->all()
            ->map(fn($pr) => $pr->user_id)
            ->toArray();

        $acsRecords = $this->AcsSectors->find()
            ->where(['activity_id' => $activityId, 'deleted IS' => null])
            ->all()
            ->toArray();
        $acsIds = array_column($acsRecords, 'id');

        // Apaga só o resultado do sorteio anterior (as linhas que o próprio sorteio
        // criou). Quem foi colocado manualmente (is_indicated=true) nunca é tocado.
        if (!empty($acsIds)) {
            $this->AcsSectorsUsers->deleteAll([
                'activities_categories_sector_id IN' => $acsIds,
                'is_coordinator'                     => false,
                'is_indicated'                         => false,
            ]);
        }
        $this->zerarResultado($activityId, 'Servo', $raffleMethodId);

        // Pool: servos que não têm vaga garantida em lugar nenhum e moram na área.
        $cidadesElegiveis = $this->getEligibleCityUserIds();
        $pool = array_values(array_filter(
            $this->candidatosServos($activityId, $indicatedUserIds, $acsIds),
            fn($pr) => isset($cidadesElegiveis[$pr->user_id])
        ));
        shuffle($pool);

        // Vagas restantes por setor: total do setor menos quem já está nele (líderes e
        // membros colocados à mão). É a mesma conta do "faltam N vagas" da tela da
        // equipe (ActivitiesController::sectors) — o sorteio preenche exatamente o
        // que a tela mostra como faltando.
        $setoresComVaga = [];
        foreach ($acsRecords as $sector) {
            // is_substitute fica NULL em quem foi colocado à mão — e NULL não é suplente.
            $jaGarantidosNoSetor = $this->AcsSectorsUsers->find()
                ->where([
                    'activities_categories_sector_id' => $sector->id,
                    'OR' => ['is_substitute IS' => null, 'is_substitute' => false],
                ])
                ->count();
            $n = (int)$sector->actual_vacancies - $jaGarantidosNoSetor;
            if ($n > 0) {
                $setoresComVaga[] = ['acs' => $sector, 'n' => $n];
            }
        }
        // Sorteia também a ordem entre setores, pra não sempre privilegiar o mesmo
        // quando o pool for menor que a soma de vagas restantes.
        shuffle($setoresComVaga);

        $cursor      = 0;
        $poolCount   = count($pool);
        $suplentePos = 1;

        foreach ($setoresComVaga as $info) {
            $sector = $info['acs'];
            $n      = $info['n'];

            for ($i = 0; $i < $n && $cursor < $poolCount; $i++, $cursor++) {
                $this->criarResultadoSorteioServo($sector->id, $pool[$cursor], $i + 1, 0, $raffleMethodId, null);
            }
            for ($i = 0; $i < $n && $cursor < $poolCount; $i++, $cursor++) {
                $this->criarResultadoSorteioServo($sector->id, $pool[$cursor], $n + $i + 1, 1, $raffleMethodId, $suplentePos++);
            }
        }

        $this->marcarFeitoEm($activityId, 'Servo', date('Y-m-d H:i:s'));

        return $this->buildServosResult($activityId);
    }

    /**
     * Servos que entrariam no sorteio agora, antes do filtro da cidade: fora os
     * indicados, quem já está numa equipe (colocado à mão ou líder) e o servo
     * geral. Depois de um sorteio, os sorteados e suplentes também têm linha na
     * equipe — esta lista só serve antes do sorteio e no próprio sorteio (que
     * apaga o resultado antes de chamar).
     *
     * @param array<int> $indicatedUserIds
     * @param array<int> $acsIds
     * @return array<object>
     */
    private function candidatosServos(int $activityId, array $indicatedUserIds, array $acsIds): array
    {
        $placedUserIds = [];
        if (!empty($acsIds)) {
            $placedUserIds = $this->AcsSectorsUsers->find()
                ->where([
                    'activities_categories_sector_id IN' => $acsIds,
                    'OR' => ['is_indicated' => true, 'is_coordinator' => true],
                ])
                ->all()
                ->map(fn($r) => $r->user_id)
                ->toArray();
        }
        // Servo geral da atividade não entra no sorteio de equipe — os dois papéis
        // são mutuamente exclusivos (mesma regra de ActivitiesController::addSectorMember).
        $generalServantUserIds = $this->ActivitiesGeneralServants->find()
            ->where(['activity_id' => $activityId])
            ->all()
            ->map(fn($g) => $g->user_id)
            ->toArray();

        $excludedUserIds = array_unique(array_merge($indicatedUserIds, $placedUserIds, $generalServantUserIds));

        $query = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'       => $activityId,
                'PreRegistrations.subscription_type' => 'Servo',
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.is_quitter'         => false,
                'PreRegistrations.removed_from_raffle_at IS' => null,
                // Cancelado pela organização ou recusado: não concorre de novo.
                'PreRegistrations.disqualified_at IS'        => null,
                'Users.deleted IS'                    => null,
            ]);
        if (!empty($excludedUserIds)) {
            $query = $query->where(['PreRegistrations.user_id NOT IN' => $excludedUserIds]);
        }

        return $query->all()->toArray();
    }

    private function criarResultadoSorteioServo(
        int $acsId,
        object $pr,
        int $posicao,
        int $isSubstitute,
        int $raffleMethodId,
        ?int $substitutePosition
    ): void {
        $this->AcsSectorsUsers->save($this->AcsSectorsUsers->newEntity([
            'activities_categories_sector_id' => $acsId,
            'user_id'                         => $pr->user_id,
            'is_coordinator'                  => false,
            'is_indicated'                     => false,
            'raffle_position'                  => $posicao,
            'is_substitute'                    => $isSubstitute,
        ]));

        $pr->selection_method_id = $isSubstitute === 0 ? $raffleMethodId : null;
        $pr->substitute_position = $substitutePosition;
        $this->PreRegistrations->save($pr);
    }

    public function resetarServos(int $activityId): void
    {
        $raffleMethodId = $this->getRaffleMethodId();

        $acsIds = $this->AcsSectors->find()
            ->where(['activity_id' => $activityId, 'deleted IS' => null])
            ->all()
            ->map(fn($s) => $s->id)
            ->toArray();

        // O resultado do sorteio são as próprias linhas is_indicated=false — resetar
        // é apagá-las (não existiam antes do sorteio rodar). Colocações manuais
        // (is_indicated=true) nunca são tocadas.
        if (!empty($acsIds)) {
            $this->AcsSectorsUsers->deleteAll([
                'activities_categories_sector_id IN' => $acsIds,
                'is_coordinator'                     => false,
                'is_indicated'                         => false,
            ]);
        }

        $this->zerarResultado($activityId, 'Servo', $raffleMethodId);
        $this->marcarFeitoEm($activityId, 'Servo', null);
    }

    /**
     * Zera o resultado do sorteio anterior (sorteados e suplentes) de um tipo de
     * inscrição. Indicações (Base/Forania/Conselho) não são tocadas: sobrevivem a
     * quantos resorteios forem feitos, até serem removidas manualmente.
     *
     * Sorteio refeito/zerado recomeça do zero: desclassificação por prazo e
     * promoção de suplente pertencem ao resultado anterior. Só o cancelamento
     * pela organização e a recusa por correção não feita continuam valendo — a
     * pessoa não volta para o sorteio (os pools ignoram quem está desclassificado).
     */
    private function zerarResultado(int $activityId, string $tipo, int $raffleMethodId): void
    {
        $doResultado = [
            'activity_id'       => $activityId,
            'subscription_type' => $tipo,
            'deleted IS'        => null,
            'OR'                => [
                'selection_method_id IS' => null,
                'selection_method_id'    => $raffleMethodId,
            ],
        ];
        $definitivo = ['disqualification_reason IN' => PrazoFormularioService::MOTIVOS_DEFINITIVOS];

        $this->PreRegistrations->updateAll(
            ['selection_method_id' => null, 'substitute_position' => null, 'promoted_at' => null, 'selected_at' => null],
            $doResultado + ['AND' => [$definitivo]]
        );
        $this->PreRegistrations->updateAll(
            [
                'selection_method_id'     => null,
                'substitute_position'     => null,
                'disqualified_at'         => null,
                'disqualification_reason' => null,
                'promoted_at'             => null,
                'selected_at'             => null,
            ],
            $doResultado + ['AND' => [['OR' => [
                'disqualification_reason IS'     => null,
                'disqualification_reason NOT IN' => PrazoFormularioService::MOTIVOS_DEFINITIVOS,
            ]]]]
        );
    }

    // ─── Resultado ──────────────────────────────────────────────────────────

    public function resultado(int $activityId): array
    {
        return [
            'campistas' => $this->buildCampistasResult($activityId),
            'servos'    => $this->buildServosResult($activityId),
        ];
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function getRaffleMethodId(): int
    {
        $method = $this->SelectionMethods->find()
            ->where(['method' => 'Sorteio', 'deleted IS' => null])
            ->first();

        if (!$method) {
            $method = $this->SelectionMethods->newEntity([
                'method'      => 'Sorteio',
                'description' => 'Selecionado por sorteio aleatório',
                'type'        => 'Acampamento',
            ]);
            $this->SelectionMethods->save($method);
        }

        return (int)$method->id;
    }

    // Agrupa campistas "Casado" em pares (o casal), usando o vínculo de cônjuge
    // (spouse_id) nos dois sentidos — quem declarou o CPF de quem na inscrição
    // pode ter sido qualquer um dos dois. Quem não tem cônjuge também inscrito
    // nessa mesma atividade vira um "par" de 1 pessoa só (sorteado individualmente).
    private function pairUpCasais(array $pool): array
    {
        $byUserId = [];
        foreach ($pool as $pr) {
            $byUserId[$pr->user_id] = $pr;
        }

        $pairs = [];
        $seen  = [];
        foreach ($pool as $pr) {
            if (isset($seen[$pr->user_id])) continue;
            $seen[$pr->user_id] = true;

            $partner = null;
            if ($pr->spouse_id && isset($byUserId[$pr->spouse_id]) && !isset($seen[$pr->spouse_id])) {
                $partner = $byUserId[$pr->spouse_id];
            } else {
                foreach ($pool as $other) {
                    if (!isset($seen[$other->user_id]) && $other->spouse_id === $pr->user_id) {
                        $partner = $other;
                        break;
                    }
                }
            }

            if ($partner) {
                $seen[$partner->user_id] = true;
                $pairs[] = [$pr, $partner];
            } else {
                $pairs[] = [$pr];
            }
        }

        return $pairs;
    }

    private function buildCampistasResult(int $activityId, ?int $homem = null, ?int $mulher = null, ?int $casado = null): array
    {
        $allCampistas = $this->PreRegistrations->find()
            ->contain(['Users', 'SelectionMethods'])
            ->where([
                'PreRegistrations.activity_id'       => $activityId,
                'PreRegistrations.subscription_type' => 'Campista',
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.is_quitter'         => false,
            ])
            ->all()
            ->toArray();

        // Mesmo agrupamento do sorteio: casal só na categoria Casais.
        $ehCasais = self::categoriaDeCasais($activityId);
        $grupoDe  = fn($pr) => $ehCasais ? 'Casado' : ($pr->user->sex === 'M' ? 'Homem' : 'Mulher');

        $counts = ['Homem' => 0, 'Mulher' => 0, 'Casado' => 0];
        foreach ($allCampistas as $pr) {
            $counts[$grupoDe($pr)]++;
        }

        $sorteados = [];
        $suplentes = [];
        $indicados = [];
        $desclassificados = [];
        $drawnIds  = [];
        $catPos    = ['Homem' => 0, 'Mulher' => 0, 'Casado' => 0];
        $indPos    = 0;
        $descPos   = 0;
        $raffleRealizado = false;

        foreach ($allCampistas as $pr) {
            if ($pr->selection_method_id === null && $pr->substitute_position === null) continue;

            $cat  = $grupoDe($pr);
            $item = $this->serializeCampistaItem($pr, $cat);
            $drawnIds[$pr->user_id] = true;

            // Perdeu o prazo do formulário: continua com o selection_method_id
            // gravado, mas a vaga já foi repassada — não pode contar como sorteado.
            if ($pr->disqualified_at !== null) {
                $descPos++;
                $raffleRealizado = true;
                $item['posicao']             = $descPos;
                $item['metodo']              = $pr->selection_method->method ?? '';
                $item['motivo']              = (string)$pr->disqualification_reason;
                // Cancelada/recusada pela organização: o motivo que a pessoa lê.
                $item['nota']                = $pr->disqualification_note;
                $item['desclassificado_em']  = $pr->disqualified_at->format('Y-m-d\TH:i');
                $item['pre_registration_id'] = $pr->id;
                $desclassificados[] = $item;
                continue;
            }

            if ($pr->substitute_position !== null) {
                $raffleRealizado = true;
                $item['posicao'] = (int)$pr->substitute_position;
                $suplentes[]     = $item;
            } elseif (($pr->selection_method?->method ?? null) !== 'Sorteio') {
                $indPos++;
                $item['posicao']             = $indPos;
                $item['metodo']              = $pr->selection_method->method ?? '';
                $item['pre_registration_id'] = $pr->id;
                $indicados[]     = $item;
            } else {
                $raffleRealizado = true;
                $catPos[$cat]++;
                $item['posicao'] = $catPos[$cat];
                $sorteados[]     = $item;
            }
        }

        usort($sorteados, fn($a, $b) => strcmp($a['categoria'], $b['categoria']) ?: $a['posicao'] - $b['posicao']);
        usort($suplentes, fn($a, $b) => $a['posicao'] - $b['posicao']);
        usort($indicados, fn($a, $b) => strcmp($a['nome'], $b['nome']));

        $raffleRealizado = $raffleRealizado || $this->campistasJaRealizado($activityId);

        $naoSorteados = [];
        if ($raffleRealizado) {
            $daArea = $this->getEligibleCityUserIds();
            foreach ($allCampistas as $pr) {
                if (!isset($drawnIds[$pr->user_id])) {
                    $item = $this->serializeCampistaItem($pr, $grupoDe($pr));
                    $item['posicao'] = count($naoSorteados) + 1;
                    // Não concorreu: só quem mora em Primavera do Leste/Poxoréu entra no sorteio.
                    $item['foraDaArea'] = !isset($daArea[$pr->user_id]);
                    $naoSorteados[]  = $item;
                }
            }
        }

        usort($desclassificados, fn($a, $b) => strcmp($a['nome'], $b['nome']));

        // Antes do sorteio: quem concorre (por grupo) e quem não concorre pela cidade.
        $candidatos = $this->candidatosCampistas($activityId);
        $daArea = $this->getEligibleCityUserIds();
        $concorrem = ['Homem' => 0, 'Mulher' => 0, 'Casado' => 0];
        foreach ($candidatos as $pr) {
            if (isset($daArea[$pr->user_id])) $concorrem[$grupoDe($pr)]++;
        }
        $camping = $this->acampamentoDa($activityId);

        $result = [
            'realizado'               => $raffleRealizado,
            'feito_em'                => $raffleRealizado ? $this->dataDoSorteio($camping, 'Campista') : null,
            'previsto'                => $camping?->raffle_camper_date?->format('Y-m-d\TH:i'),
            // Vagas do cadastro do acampamento (Casado = número de casais).
            'vagas'                   => [
                'Homem'  => (int)($camping->raffle_man_vacancies ?? 0),
                'Mulher' => (int)($camping->raffle_woman_vacancies ?? 0),
                'Casado' => (int)($camping->raffle_couple_vacancies ?? 0),
            ],
            'casais'                  => $ehCasais,
            'concorrem'               => $concorrem,
            'nao_concorrem'           => $this->naoConcorrem($candidatos, $grupoDe),
            'bloqueio'                => $this->bloqueioDoSorteio($activityId, 'Campista'),
            'inscritos_por_categoria' => $counts,
            'indicados'               => $indicados,
            'sorteados'               => $sorteados,
            'suplentes'               => $suplentes,
            'nao_sorteados'           => $naoSorteados,
            // Quem foi selecionado e perdeu a vaga (formulário ou pagamento fora do prazo).
            'desclassificados'        => $desclassificados,
            // Vagas perdidas que ainda esperam alguém chamar o próximo suplente.
            'vagas_abertas'           => (new PrazoFormularioService())->vagasAbertasCampistas($activityId),
        ];

        if ($homem !== null) {
            $result['configuracao'] = ['homem' => $homem, 'mulher' => $mulher, 'casado' => $casado];
        }

        return $result;
    }

    private function serializeCampistaItem(object $pr, string $categoria): array
    {
        return [
            'user_id'     => (int)$pr->user->id,
            'nome'        => $pr->user->name,
            'cpf'         => $pr->user->cpf,
            'categoria'   => $categoria,
            'sexo'        => $pr->user->sex === 'M' ? 'Masculino' : 'Feminino',
            'idade'       => $this->calcularIdade($pr->user->birthday),
            'estadoCivil' => '',
            'posicao'     => 0,
            // Assumiu uma vaga liberada por alguém que perdeu o prazo do formulário.
            'promovido'   => $pr->promoted_at !== null,
            'formularioEnviado' => (bool)$pr->form_submitted,
        ];
    }

    private function buildServosResult(int $activityId): array
    {
        $acsRecords = $this->AcsSectors->find()
            ->where(['activity_id' => $activityId, 'deleted IS' => null])
            ->orderBy(['id' => 'ASC'])
            ->all()
            ->toArray();

        $totalInscritos = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'       => $activityId,
                'PreRegistrations.subscription_type' => 'Servo',
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.is_quitter'         => false,
                'Users.deleted IS'                    => null,
            ])
            ->count();

        $indicadosRegs = $this->PreRegistrations->find()
            ->contain(['Users', 'SelectionMethods'])
            ->where([
                'PreRegistrations.activity_id'       => $activityId,
                'PreRegistrations.subscription_type' => 'Servo',
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.is_quitter'         => false,
                'PreRegistrations.selection_method_id IS NOT' => null,
            ])
            ->all()
            ->toArray();

        $indicados        = [];
        $indicatedUserIds = [];
        foreach ($indicadosRegs as $pr) {
            if (($pr->selection_method?->method ?? null) === 'Sorteio') continue;
            $item                         = $this->serializeServoItem($pr->user, 0);
            $item['metodo']               = $pr->selection_method->method ?? '';
            $item['pre_registration_id']  = $pr->id;
            $indicados[]                  = $item;
            $indicatedUserIds[$pr->user_id] = true;
        }
        usort($indicados, fn($a, $b) => strcmp($a['nome'], $b['nome']));

        // Servos colocados manualmente numa equipe (líder/geral/admin, via a tela de
        // montagem de equipe) já têm vaga garantida naquele setor — não devem aparecer
        // como "não sorteados" aqui, mesmo sem terem passado pelo sorteio. Líderes de
        // setor entram aqui também. Quem for removido da equipe sai desta lista e
        // volta sozinho para "não sorteados" (ela é montada na hora, a cada consulta).
        $alocacoesManuais = $this->AcsSectorsUsers->find()
            ->contain(['Users', 'ActivitiesCategoriesSectors' => ['CategoriesSectors' => ['Sectors']]])
            ->where([
                'ActivitiesCategoriesSectors.activity_id'      => $activityId,
                'ActivitiesCategoriesSectors.deleted IS'       => null,
                'ActivitiesCategoriesSectorsUsers.is_indicated' => true,
            ])
            ->all()
            ->toArray();

        $alocadosManualmente  = [];
        $manuallyPlacedUserIds = [];
        foreach ($alocacoesManuais as $am) {
            $item          = $this->serializeServoItem($am->user, 0);
            $item['setor'] = $am->activities_categories_sector->categories_sector->sector->name ?? '';
            $item['lider'] = (bool)$am->is_coordinator;
            $alocadosManualmente[] = $item;
            $manuallyPlacedUserIds[$am->user_id] = true;
        }
        usort($alocadosManualmente, fn($a, $b) => strcmp($a['nome'], $b['nome']));

        // Servo geral da atividade também não entra no sorteio nem conta como
        // "não sorteado" — é um papel confirmado, só que sem setor específico.
        $servosGerais = $this->ActivitiesGeneralServants->find()
            ->contain(['Users'])
            ->where(['activity_id' => $activityId])
            ->all()
            ->toArray();

        $servoGeral            = [];
        $generalServantUserIds = [];
        foreach ($servosGerais as $sg) {
            $servoGeral[]                          = $this->serializeServoItem($sg->user, 0);
            $generalServantUserIds[$sg->user_id]   = true;
        }
        usort($servoGeral, fn($a, $b) => strcmp($a['nome'], $b['nome']));

        // O que a tela mostra antes e depois do sorteio, nos dois casos.
        $acsIdsTodos = array_column($acsRecords, 'id');
        $candidatos = $this->candidatosServos($activityId, array_keys($indicatedUserIds), $acsIdsTodos);
        $daArea = $this->getEligibleCityUserIds();
        $camping = $this->acampamentoDa($activityId);
        $feito = $this->servosJaRealizado($activityId);
        $extras = [
            'feito_em'      => $feito ? $this->dataDoSorteio($camping, 'Servo') : null,
            'previsto'      => $camping?->raffle_servant_date?->format('Y-m-d\TH:i'),
            'concorrem'     => count(array_filter($candidatos, fn($pr) => isset($daArea[$pr->user_id]))),
            'nao_concorrem' => $this->naoConcorrem($candidatos, fn($pr) => 'Servo'),
            'bloqueio'      => $this->bloqueioDoSorteio($activityId, 'Servo'),
        ];

        if (empty($acsRecords)) {
            return $extras + [
                'realizado'            => false,
                'setores'              => [],
                'indicados'            => $indicados,
                'alocados_manualmente' => $alocadosManualmente,
                'servo_geral'          => $servoGeral,
                'nao_sorteados'        => [],
                'total_inscritos'      => (int)$totalInscritos,
            ];
        }

        $acsIds = array_column($acsRecords, 'id');

        $csIds     = array_unique(array_column($acsRecords, 'categories_sectors_id'));
        $csRecords = $this->CategoriesSectors->find()
            ->contain(['Sectors'])
            ->where(['CategoriesSectors.id IN' => $csIds])
            ->all()
            ->toArray();
        $csMap = [];
        foreach ($csRecords as $cs) {
            $csMap[$cs->id] = $cs->sector?->name ?? "Setor #{$cs->id}";
        }

        // Quem já entregou o formulário (titular; suplente na fila não responde).
        $formEnviadoPorUsuario = [];
        $desclassificadoPorUsuario = [];
        foreach (
            $this->PreRegistrations->find()
                ->select(['user_id', 'form_submitted', 'disqualified_at'])
                ->where([
                    'activity_id'       => $activityId,
                    'subscription_type' => 'Servo',
                    'deleted IS'        => null,
                ])
                ->all() as $prServo
        ) {
            $formEnviadoPorUsuario[$prServo->user_id] = (bool)$prServo->form_submitted;
            $desclassificadoPorUsuario[$prServo->user_id] = $prServo->disqualified_at !== null;
        }
        $prazos = new PrazoFormularioService();

        $setores      = [];
        $drawnUserIds = [];
        $anyDrawn     = false;

        foreach ($acsRecords as $acs) {
            // Mesma conta do realizarServos(): líder também ocupa vaga.
            $jaIndicadosNoSetor = $this->AcsSectorsUsers->find()
                ->where([
                    'activities_categories_sector_id' => $acs->id,
                    'is_indicated'                     => true,
                ])
                ->count();
            $n    = (int)$acs->actual_vacancies - $jaIndicadosNoSetor;
            $name = $csMap[$acs->categories_sectors_id] ?? "Setor #{$acs->id}";

            if ($n <= 0) continue;

            $members = $this->AcsSectorsUsers->find()
                ->contain(['Users'])
                ->where([
                    'activities_categories_sector_id' => $acs->id,
                    'is_coordinator'                  => false,
                    'raffle_position IS NOT'           => null,
                    'is_substitute IS NOT'             => null,
                ])
                ->orderBy(['raffle_position' => 'ASC'])
                ->all()
                ->toArray();

            $sorteados = [];
            $suplentes = [];
            foreach ($members as $m) {
                if (isset($indicatedUserIds[$m->user_id])) continue;
                // Suplente que saiu da fila por prazo não pode ser chamado.
                if (!empty($desclassificadoPorUsuario[$m->user_id])) continue;

                $anyDrawn = true;
                $item     = $this->serializeServoItem($m->user, (int)$m->raffle_position);
                $item['formularioEnviado'] = !empty($formEnviadoPorUsuario[$m->user_id]);
                if ((int)$m->is_substitute === 0) {
                    $sorteados[] = $item;
                } else {
                    $suplentes[] = $item;
                }
                $drawnUserIds[$m->user_id] = true;
            }

            $setores[] = [
                'id'           => (int)$acs->id,
                'sector_name'  => $name,
                // Vagas do sorteio = do setor menos quem já está na equipe.
                'vacancies'    => $n,
                'vagas_total'  => (int)$acs->actual_vacancies,
                'na_equipe'    => $jaIndicadosNoSetor,
                'sorteados'    => $sorteados,
                'suplentes'    => $suplentes,
                // Vagas do setor sem ninguém (quem perdeu o prazo saiu da equipe).
                'vagas_abertas' => $prazos->vagasAbertasSetor($acs),
            ];
        }

        if (!$anyDrawn && !$feito) {
            return $extras + [
                'realizado'            => false,
                'setores'              => $setores,
                'indicados'            => $indicados,
                'alocados_manualmente' => $alocadosManualmente,
                'servo_geral'          => $servoGeral,
                'nao_sorteados'        => [],
                'total_inscritos'      => (int)$totalInscritos,
            ];
        }

        $allServos = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'       => $activityId,
                'PreRegistrations.subscription_type' => 'Servo',
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.is_quitter'         => false,
                'Users.deleted IS'                    => null,
            ])
            ->all()
            ->toArray();

        $naoSorteados = [];
        $daArea = $this->getEligibleCityUserIds();
        foreach ($allServos as $pr) {
            if (
                !isset($drawnUserIds[$pr->user_id])
                && !isset($indicatedUserIds[$pr->user_id])
                && !isset($manuallyPlacedUserIds[$pr->user_id])
                && !isset($generalServantUserIds[$pr->user_id])
                // Quem perdeu a vaga por prazo aparece na própria lista, abaixo.
                && empty($desclassificadoPorUsuario[$pr->user_id])
            ) {
                $item = $this->serializeServoItem($pr->user, 0);
                // Não concorreu: só quem mora em Primavera do Leste/Poxoréu entra no sorteio.
                $item['foraDaArea'] = !isset($daArea[$pr->user_id]);
                $naoSorteados[] = $item;
            }
        }

        // Servos que perderam a vaga por não responder o formulário no prazo. A
        // linha da equipe deles já foi apagada quando a vaga foi repassada, então
        // essa lista é montada direto das inscrições.
        $desclassificados = [];
        $descPos = 0;
        $descQuery = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'        => $activityId,
                'PreRegistrations.subscription_type'  => 'Servo',
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.disqualified_at IS NOT' => null,
            ])
            ->all();
        foreach ($descQuery as $pr) {
            $descPos++;
            $item = $this->serializeServoItem($pr->user, $descPos);
            $item['motivo'] = (string)$pr->disqualification_reason;
            $item['nota'] = $pr->disqualification_note;
            $item['desclassificado_em'] = $pr->disqualified_at->format('Y-m-d\TH:i');
            $desclassificados[] = $item;
        }

        return $extras + [
            'realizado'            => true,
            'setores'              => $setores,
            'indicados'            => $indicados,
            'alocados_manualmente' => $alocadosManualmente,
            'servo_geral'          => $servoGeral,
            'nao_sorteados'        => $naoSorteados,
            'desclassificados'     => $desclassificados,
            'total_inscritos'      => (int)$totalInscritos,
        ];
    }

    private function serializeServoItem(object $user, int $posicao): array
    {
        return [
            'user_id'     => (int)$user->id,
            'nome'        => $user->name,
            'cpf'         => $user->cpf,
            'sexo'        => $user->sex === 'M' ? 'Masculino' : 'Feminino',
            'idade'       => $this->calcularIdade($user->birthday),
            'estadoCivil' => '',
            'posicao'     => $posicao,
        ];
    }

    private function calcularIdade(mixed $birthday): int
    {
        if (!$birthday) return 0;
        try {
            $bdate = $birthday instanceof \DateTimeInterface
                ? \DateTime::createFromInterface($birthday)
                : new \DateTime($birthday instanceof \Cake\I18n\Date ? $birthday->format('Y-m-d') : (string)$birthday);
            return (int)$bdate->diff(new \DateTime('today'))->y;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
