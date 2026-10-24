<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\TableLocator;

/**
 * Toda a lógica de sorteio (campistas e servos) vive aqui, fora do controller,
 * para poder ser chamada tanto pela API (admin aciona manualmente) quanto pelo
 * executor automático (roda sozinho quando a data configurada na criação do
 * acampamento chega) sem duplicar código.
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
    private $MaritalStatuses;
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
        $this->MaritalStatuses          = $locator->get('MaritalStatuses');
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
            if (in_array(self::normalizeCidade((string)$end->city), self::CIDADES_ELEGIVEIS, true)) {
                $ids[$end->user_id] = true;
            }
        }
        return $ids;
    }

    // ─── Execução automática ────────────────────────────────────────────────

    /**
     * Varre todos os acampamentos e, para cada um, dispara o sorteio de campistas
     * e/ou de servos se a data configurada na criação já chegou E ele ainda não
     * foi realizado (manual ou automaticamente) — nunca resorteia sozinho por
     * cima de um sorteio que o admin já fez. Usa as vagas cadastradas no
     * acampamento (raffle_man/woman/couple_vacancies e actual_vacancies
     * por setor), sem precisar de nenhuma entrada manual.
     *
     * Chamado tanto pelo comando de console (`bin/cake executar_sorteios_pendentes`,
     * pensado para rodar num agendador do sistema operacional) quanto de forma
     * oportunista a partir de endpoints públicos bastante acessados — assim o
     * sorteio acontece na prática assim que alguém carrega o site após a data
     * chegar, mesmo sem um agendador configurado.
     *
     * @return string[] Log de ações realizadas, para exibição/registro.
     */
    public function executarSorteiosPendentes(): array
    {
        $log = [];
        $now = time();

        $activities = $this->Activities->find()
            ->where(['camping_id IS NOT' => null, 'deleted IS' => null])
            ->all();

        foreach ($activities as $activity) {
            $camping = $this->Campings->find()
                ->where(['id' => $activity->camping_id])
                ->first();
            if (!$camping) continue;

            $raffleCamperTs   = $camping->raffle_camper_date?->getTimestamp();
            $raffleServantTs  = $camping->raffle_servant_date?->getTimestamp();

            if ($raffleCamperTs !== null && $raffleCamperTs <= $now && !$this->campistasJaRealizado((int)$activity->id)) {
                $this->realizarCampistas(
                    (int)$activity->id,
                    (int)$camping->raffle_man_vacancies,
                    (int)$camping->raffle_woman_vacancies,
                    (int)$camping->raffle_couple_vacancies,
                );
                $log[] = "Sorteio de campistas realizado automaticamente para a atividade #{$activity->id} ({$activity->name}).";
            }

            if ($raffleServantTs !== null && $raffleServantTs <= $now && !$this->servosJaRealizado((int)$activity->id)) {
                $this->realizarServos((int)$activity->id);
                $log[] = "Sorteio de servos realizado automaticamente para a atividade #{$activity->id} ({$activity->name}).";
            }
        }

        return $log;
    }

    public function campistasJaRealizado(int $activityId): bool
    {
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

    public function realizarCampistas(int $activityId, int $homem, int $mulher, int $casado): array
    {
        $raffleMethodId = $this->getRaffleMethodId();

        // Reset só o resultado do sorteio anterior (Sorteados/Suplentes). Indicações
        // (Base/Forania/Conselho) nunca são tocadas aqui — sobrevivem a quantos
        // resorteios forem feitos, até serem removidas manualmente via removeIndicacao.
        $this->PreRegistrations->updateAll(
            [
                'selection_method_id' => null,
                'substitute_position' => null,
                // Sorteio refeito/zerado recomeça do zero: desclassificação por prazo
                // e promoção de suplente pertencem ao resultado anterior.
                'disqualified_at' => null,
                'disqualification_reason' => null,
                'promoted_at' => null,
            ],
            [
                'activity_id'       => $activityId,
                'subscription_type' => 'Campista',
                'deleted IS'        => null,
                'OR'                => [
                    'selection_method_id IS' => null,
                    'selection_method_id'    => $raffleMethodId,
                ],
            ]
        );

        // Pool do sorteio: só quem ainda não tem indicação — quem já foi indicado
        // tem vaga garantida fora do sorteio e não concorre nem conta nas vagas informadas.
        // Só residentes de Primavera do Leste/Poxoréu são elegíveis pra serem sorteados.
        $cidadesElegiveis = $this->getEligibleCityUserIds();
        $campistas = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'            => $activityId,
                'PreRegistrations.subscription_type'      => 'Campista',
                'PreRegistrations.deleted IS'              => null,
                'PreRegistrations.is_quitter'              => false,
                'PreRegistrations.selection_method_id IS' => null,
            ])
            ->all()
            ->toArray();
        $campistas = array_values(array_filter($campistas, fn($pr) => isset($cidadesElegiveis[$pr->user_id])));

        $casadoId = $this->getCasadoMaritalStatusId();
        $grupos = ['Homem' => [], 'Mulher' => [], 'Casado' => []];
        foreach ($campistas as $pr) {
            $msId = $pr->user->marital_status_id ?? 0;
            if ($msId == $casadoId) {
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
        $pares = $this->pairUpCasais($grupos['Casado']);
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

        return $this->buildCampistasResult($activityId, $homem, $mulher, $casado);
    }

    public function resetarCampistas(int $activityId): void
    {
        $raffleMethodId = $this->getRaffleMethodId();

        // Só zera o resultado do sorteio — indicações permanecem intactas.
        $this->PreRegistrations->updateAll(
            [
                'selection_method_id' => null,
                'substitute_position' => null,
                // Sorteio refeito/zerado recomeça do zero: desclassificação por prazo
                // e promoção de suplente pertencem ao resultado anterior.
                'disqualified_at' => null,
                'disqualification_reason' => null,
                'promoted_at' => null,
            ],
            [
                'activity_id'       => $activityId,
                'subscription_type' => 'Campista',
                'deleted IS'        => null,
                'OR'                => [
                    'selection_method_id IS' => null,
                    'selection_method_id'    => $raffleMethodId,
                ],
            ]
        );
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
        $this->PreRegistrations->updateAll(
            [
                'selection_method_id' => null,
                'substitute_position' => null,
                // Sorteio refeito/zerado recomeça do zero: desclassificação por prazo
                // e promoção de suplente pertencem ao resultado anterior.
                'disqualified_at' => null,
                'disqualification_reason' => null,
                'promoted_at' => null,
            ],
            [
                'activity_id'       => $activityId,
                'subscription_type' => 'Servo',
                'deleted IS'        => null,
                'OR'                => [
                    'selection_method_id IS' => null,
                    'selection_method_id'    => $raffleMethodId,
                ],
            ]
        );

        // Depois do delete acima, as linhas que sobram em activities_categories_sectors_users
        // para esses setores são só as colocações manuais — então já servem como a lista
        // de "quem já tem vaga garantida numa equipe" (líderes inclusive).
        $placedUserIds = [];
        if (!empty($acsIds)) {
            $placedUserIds = $this->AcsSectorsUsers->find()
                ->where(['activities_categories_sector_id IN' => $acsIds])
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

        // Pool: servos restantes — inscritos que não têm vaga garantida em lugar nenhum.
        $poolQuery = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'       => $activityId,
                'PreRegistrations.subscription_type' => 'Servo',
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.is_quitter'         => false,
                'Users.deleted IS'                    => null,
            ]);
        if (!empty($excludedUserIds)) {
            $poolQuery = $poolQuery->where(['PreRegistrations.user_id NOT IN' => $excludedUserIds]);
        }
        // Só residentes de Primavera do Leste/Poxoréu são elegíveis pra serem sorteados.
        $cidadesElegiveis = $this->getEligibleCityUserIds();
        $pool = array_values(array_filter(
            $poolQuery->all()->toArray(),
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

        return $this->buildServosResult($activityId);
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

        $this->PreRegistrations->updateAll(
            [
                'selection_method_id' => null,
                'substitute_position' => null,
                // Sorteio refeito/zerado recomeça do zero: desclassificação por prazo
                // e promoção de suplente pertencem ao resultado anterior.
                'disqualified_at' => null,
                'disqualification_reason' => null,
                'promoted_at' => null,
            ],
            [
                'activity_id'       => $activityId,
                'subscription_type' => 'Servo',
                'deleted IS'        => null,
                'OR'                => [
                    'selection_method_id IS' => null,
                    'selection_method_id'    => $raffleMethodId,
                ],
            ]
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

    // Busca o id de "Casado" pelo título, em vez de assumir um id fixo — o seed de
    // marital_statuses já mudou de ordem uma vez (id 2 hoje é "Namorando", não "Casado").
    private function getCasadoMaritalStatusId(): int
    {
        $status = $this->MaritalStatuses->find()
            ->where(['title' => 'Casado', 'deleted IS' => null])
            ->first();

        return $status ? (int)$status->id : 0;
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

        $casadoId = $this->getCasadoMaritalStatusId();

        $counts = ['Homem' => 0, 'Mulher' => 0, 'Casado' => 0];
        foreach ($allCampistas as $pr) {
            $msId = $pr->user->marital_status_id ?? 0;
            if ($msId == $casadoId) $counts['Casado']++;
            elseif ($pr->user->sex === 'M') $counts['Homem']++;
            else $counts['Mulher']++;
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

            $msId = $pr->user->marital_status_id ?? 0;
            $cat  = $msId == $casadoId ? 'Casado' : ($pr->user->sex === 'M' ? 'Homem' : 'Mulher');
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

        $naoSorteados = [];
        if ($raffleRealizado) {
            foreach ($allCampistas as $pr) {
                if (!isset($drawnIds[$pr->user_id])) {
                    $msId = $pr->user->marital_status_id ?? 0;
                    $cat  = $msId == $casadoId ? 'Casado' : ($pr->user->sex === 'M' ? 'Homem' : 'Mulher');
                    $item = $this->serializeCampistaItem($pr, $cat);
                    $item['posicao'] = count($naoSorteados) + 1;
                    $naoSorteados[]  = $item;
                }
            }
        }

        usort($desclassificados, fn($a, $b) => strcmp($a['nome'], $b['nome']));

        $result = [
            'realizado'               => $raffleRealizado,
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

        if (empty($acsRecords)) {
            return [
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

        // Quem já entregou o formulário, para marcar na lista de suplentes: suplente
        // sem formulário entregue não pode ser chamado para uma vaga liberada.
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
                'vacancies'    => $n,
                'sorteados'    => $sorteados,
                'suplentes'    => $suplentes,
                // Vagas do setor sem ninguém (quem perdeu o prazo saiu da equipe).
                'vagas_abertas' => $prazos->vagasAbertasSetor($acs),
            ];
        }

        if (!$anyDrawn) {
            return [
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
        foreach ($allServos as $pr) {
            if (
                !isset($drawnUserIds[$pr->user_id])
                && !isset($indicatedUserIds[$pr->user_id])
                && !isset($manuallyPlacedUserIds[$pr->user_id])
                && !isset($generalServantUserIds[$pr->user_id])
                // Quem perdeu a vaga por prazo aparece na própria lista, abaixo.
                && empty($desclassificadoPorUsuario[$pr->user_id])
            ) {
                $naoSorteados[] = $this->serializeServoItem($pr->user, 0);
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
            $item['desclassificado_em'] = $pr->disqualified_at->format('Y-m-d\TH:i');
            $desclassificados[] = $item;
        }

        return [
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
