<?php
declare(strict_types=1);
namespace App\Controller\Api\V1;
use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use App\Service\PrazoFormularioService;
use App\Service\SorteioService;
use Cake\Http\Response;

#[AllowDynamicProperties]
class ActivitiesController extends ApiController
{
    private SorteioService $sorteioService;

    public function initialize(): void
    {
        parent::initialize();
        $this->Activities                  = $this->fetchTable('Activities');
        $this->Campings                    = $this->fetchTable('Campings');
        $this->CategoriesSectors           = $this->fetchTable('CategoriesSectors');
        $this->ActivitiesCategoriesSectors = $this->fetchTable('ActivitiesCategoriesSectors');
        $this->AcsSectorsUsers             = $this->fetchTable('ActivitiesCategoriesSectorsUsers');
        $this->ActivitiesGeneralServants   = $this->fetchTable('ActivitiesGeneralServants');
        $this->PreRegistrations            = $this->fetchTable('PreRegistrations');
        $this->Users                       = $this->fetchTable('Users');
        $this->sorteioService              = new SorteioService();
        $this->prazoFormularioService      = new PrazoFormularioService();
    }

    // ─── Público ───────────────────────────────────────────────────────────

    public function index(): Response
    {
        $this->request->allowMethod(['get']);

        // Oportunista: sem depender de um agendador do sistema operacional, a
        // tela mais acessada do site (listagem de atividades) aplica os prazos
        // de formulário e de pagamento já vencidos. O sorteio não roda sozinho:
        // é feito à mão pela organização, em Inscritos e Sorteios.
        $this->prazoFormularioService->executarPrazosVencidos();

        $activities = $this->Activities->find()
            ->contain(['Categories'])
            ->where(['Activities.deleted IS' => null])
            ->orderBy(['Activities.start_date' => 'DESC'])
            ->all()
            ->toArray();

        $campingIds = array_filter(array_column($activities, 'camping_id'));

        $campingsMap = [];
        if ($campingIds) {
            foreach ($this->Campings->find()->where(['id IN' => array_values($campingIds)])->all() as $c) {
                $campingsMap[$c->id] = $c;
            }
        }

        $result = [];
        foreach ($activities as $a) {
            $camping  = $a->camping_id ? ($campingsMap[$a->camping_id] ?? null) : null;
            $result[] = $this->serializeActivity($a, $camping);
        }

        return $this->apiResponse(['data' => $result]);
    }

    public function view(int $id): Response
    {
        $this->request->allowMethod(['get']);

        $activity = $this->Activities->find()
            ->contain(['Categories'])
            ->where(['Activities.id' => $id, 'Activities.deleted IS' => null])
            ->first();

        if (!$activity) {
            return $this->apiError('Atividade não encontrada', 404);
        }

        $camping = $activity->camping_id
            ? $this->Campings->find()->where(['id' => $activity->camping_id])->first()
            : null;

        return $this->apiResponse(['data' => $this->serializeActivity($activity, $camping)]);
    }

    // ─── Admin ─────────────────────────────────────────────────────────────

    /**
     * Dados crus (nomes de coluna do banco) para preencher a tela de edição.
     * O `view()` devolve um resumo formatado para o público e não tem todos os campos.
     */
    public function editData(int $id): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $activity = $this->Activities->find()
            ->where(['Activities.id' => $id, 'Activities.deleted IS' => null])
            ->first();

        // Acampamento pede "acampamentos"; evento (tela Editar Evento), "eventos".
        $identity = $this->requirePermissao(($activity->camping_id ?? null) !== null || !$activity ? 'acampamentos' : 'eventos');
        if ($identity instanceof Response) return $identity;

        if (!$activity) return $this->apiError('Atividade não encontrada', 404);

        $camping = $activity->camping_id
            ? $this->Campings->find()->where(['id' => $activity->camping_id])->first()
            : null;

        return $this->apiResponse(['data' => [
            'activity' => $this->entityToFormArray($activity),
            'camping'  => $camping ? $this->entityToFormArray($camping) : null,
            // Avisos da tela Editar Evento ("já tem n inscrições", "n pagamentos").
            'resumo'   => [
                'inscricoes'    => $this->contarInscricoes($activity, false),
                'vagasOcupadas' => $this->contarInscricoes($activity, true),
                'pagamentos'    => $this->fetchTable('PreRegistrations')->find()
                    ->where(['activity_id' => $activity->id, 'deleted IS' => null, 'is_fee_paid' => true])
                    ->count(),
                // Acampamento: avisos de cada aba ("já há n inscrições", "o sorteio já foi feito").
                'inscricoesCampistas' => $camping ? $this->contarInscricoesDoTipo($activity, 'Campista') : 0,
                'inscricoesServos'    => $camping ? $this->contarInscricoesDoTipo($activity, 'Servo') : 0,
                'sorteioCampistas'    => $camping ? $this->situacaoDoSorteio((int)$activity->id, 'Campista') : null,
                'sorteioServos'       => $camping ? $this->situacaoDoSorteio((int)$activity->id, 'Servo') : null,
                // Tamanho em bytes do edital e do termo atuais (null sem arquivo).
                'documentos'          => $camping ? [
                    'notice' => $this->tamanhoDoArquivo($camping->notice),
                    'term'   => $this->tamanhoDoArquivo($camping->term),
                ] : null,
            ],
        ]]);
    }

    /** Formulário de evento escolhido no cadastro (vazio ou inexistente = nenhum). */
    private function formularioDeEvento(array $ad): ?int
    {
        $id = intval($ad['event_form_id'] ?? 0);
        if (!$id) return null;

        $existe = $this->fetchTable('EventForms')->find()->where(['id' => $id, 'deleted IS' => null])->count();

        return $existe ? $id : null;
    }

    /** Datas no formato do <input type="datetime-local">, sem conversão de fuso. */
    private function entityToFormArray(object $entity): array
    {
        $out = [];
        foreach ($entity->toArray() as $field => $value) {
            $out[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d\TH:i') : $value;
        }

        return $out;
    }

    public function add(): Response
    {
        $this->request->allowMethod(['post']);

        $data  = $this->request->getData();
        $files = $this->request->getUploadedFiles();
        $type  = $data['type'] ?? null;

        $identity = $this->requirePermissao($type === 'Event' ? 'eventos' : 'acampamentos');
        if ($identity instanceof Response) return $identity;

        if (!in_array($type, ['Camping', 'Event'], true)) {
            return $this->apiError('Tipo inválido. Use "Camping" ou "Event"', 422);
        }
        if ($type === 'Event') {
            $erros = $this->validarEvento($data['activity'] ?? [], null);
            if ($erros) return $this->apiResponse(['error' => 'Confira os campos do evento.', 'errors' => $erros], 422);
        }
        if ($type === 'Camping') {
            $ad = $data['activity'] ?? [];
            $cd = $data['camping'] ?? [];
            $erros = $this->validarAcampamento($ad, $cd, null, null);
            if ($erros) return $this->apiResponse(['error' => 'Confira os campos do acampamento.', 'errors' => $erros], 422);
            [$data['activity'], $data['camping']] = $this->normalizarAcampamento($ad, $cd, null, null);
        }

        $imageBase64 = $data['activity']['image_base64'] ?? null;
        $imagePath   = $imageBase64
            ? $this->saveBase64File($imageBase64, 'activities')
            : $this->saveFile($files['activity']['image'] ?? null, 'activities');

        $cd         = $data['camping'] ?? [];
        $noticePath = null;
        $termPath   = null;
        if ($type === 'Camping') {
            $noticePath = ($cd['notice_base64'] ?? null)
                ? $this->saveBase64File($cd['notice_base64'], 'campings/notices')
                : $this->saveFile($files['camping']['notice'] ?? null, 'campings/notices');
            $termPath = ($cd['term_base64'] ?? null)
                ? $this->saveBase64File($cd['term_base64'], 'campings/terms')
                : $this->saveFile($files['camping']['term'] ?? null, 'campings/terms');
        }

        $responseData = null;
        $error        = null;

        $this->Activities->getConnection()->transactional(function () use (
            $data, $type, $cd, $imagePath, $noticePath, $termPath, &$responseData, &$error
        ) {
            $campingId = null;
            $camping   = null;

            if ($type === 'Camping') {
                $campingData = [
                    'notice'                                 => $noticePath ?? '#',
                    'term'                                   => $termPath   ?? '#',
                    'planned_man_vacancies'                  => intval($cd['planned_man_vacancies']    ?? 0),
                    'planned_woman_vacancies'                => intval($cd['planned_woman_vacancies']  ?? 0),
                    'planned_couple_vacancies'               => intval($cd['planned_couple_vacancies'] ?? 0),
                    'raffle_man_vacancies'                   => intval($cd['raffle_man_vacancies']     ?? 0),
                    'raffle_woman_vacancies'                 => intval($cd['raffle_woman_vacancies']   ?? 0),
                    'raffle_couple_vacancies'                => intval($cd['raffle_couple_vacancies']  ?? 0),
                    'raffle_camper_date'                     => $cd['raffle_camper_date']                     ?? null,
                    'camper_form_deadline'                   => $cd['camper_form_deadline']                   ?? null,
                    'servant_form_deadline'                  => $cd['servant_form_deadline']                  ?? null,
                    'raffle_servant_subscription_start_date' => $cd['raffle_servant_subscription_start_date'] ?? null,
                    'raffle_servant_subscription_end_date'   => $cd['raffle_servant_subscription_end_date']   ?? null,
                    'raffle_servant_date'                    => $cd['raffle_servant_date']                    ?? null,
                    'camper_registration_start_date'         => $cd['camper_registration_start_date']         ?? null,
                    'camper_registration_end_date'           => $cd['camper_registration_end_date']           ?? null,
                ];
                $camping = $this->Campings->newEntity($campingData);
                if (!$this->Campings->save($camping)) {
                    $error = ['error' => 'Erro ao salvar camping', 'errors' => $camping->getErrors()];
                    return false;
                }
                $campingId = $camping->id;
            }

            $ad           = $data['activity'] ?? [];
            $startDate    = $ad['start_date'] ?? null;
            $fimEvento    = $ad['end_date']   ?? null;
            $durationDays = max(1, intval($ad['duration_days'] ?? 1));
            if ($startDate && $fimEvento) {
                $durationDays = max(1, (int)ceil((strtotime($fimEvento) - strtotime($startDate)) / 86400));
            }

            $activity = $this->Activities->newEntity([
                'name'                                => $ad['name']   ?? '',
                'image'                               => $imagePath    ?? '',
                'place'                               => $ad['place']  ?? '',
                'year'                                => $startDate ? (int)date('Y', strtotime($startDate)) : (int)date('Y'),
                'start_date'                          => $startDate,
                'duration_days'                       => $durationDays,
                'total_vacancies'                     => intval($ad['total_vacancies'] ?? 0),
                'category_id'                         => !empty($ad['category_id']) ? intval($ad['category_id']) : null,
                'camping_id'                          => $campingId,
                'participant_registration_start_date' => $ad['participant_registration_start_date'] ?? null,
                'participant_registration_end_date'   => $ad['participant_registration_end_date']   ?? null,
                'servant_registration_start_date'     => $ad['servant_registration_start_date']     ?? null,
                'servant_registration_end_date'       => $ad['servant_registration_end_date']       ?? null,
                'participant_fee'                     => floatval($ad['participant_fee'] ?? 0),
                'servant_fee'                         => floatval($ad['servant_fee']     ?? 0),
                'participant_payment_date'            => $ad['participant_payment_date'] ?? null,
                'participant_payment_link'            => $ad['participant_payment_link'] ?? null,
                'servant_payment_date'                => $ad['servant_payment_date']     ?? null,
                'servant_payment_link'                => $ad['servant_payment_link']     ?? null,
                'min_age'                             => isset($ad['min_age']) && $ad['min_age'] !== '' && $ad['min_age'] !== 'null' ? intval($ad['min_age']) : null,
                'max_age'                             => isset($ad['max_age']) && $ad['max_age'] !== '' && $ad['max_age'] !== 'null' ? intval($ad['max_age']) : null,
                'target_audience'                     => $ad['target_audience'] ?? 'Geral',
                'description'                         => $this->textoOuNulo($ad['description'] ?? null),
                // Formulário de inscrição do evento (vazio = a inscrição conclui na hora).
                'event_form_id'                       => $type === 'Event' ? $this->formularioDeEvento($ad) : null,
            ]);

            if (!$this->Activities->save($activity)) {
                $error = ['error' => 'Erro ao salvar atividade', 'errors' => $activity->getErrors()];
                return false;
            }

            // Auto-populate sectors for this activity's category only if it has one
            if ($activity->category_id) {
                $csLinks = $this->CategoriesSectors->find()
                    ->where(['category_id' => $activity->category_id, 'deleted IS' => null])
                    ->all();
                foreach ($csLinks as $cs) {
                    $acs = $this->ActivitiesCategoriesSectors->newEntity([
                        'categories_sectors_id' => $cs->id,
                        'activity_id'           => $activity->id,
                        'actual_vacancies'      => $cs->vacancies,
                    ]);
                    $this->ActivitiesCategoriesSectors->save($acs);
                }
            }

            $activityFull = $this->Activities->find()
                ->contain(['Categories'])
                ->where(['Activities.id' => $activity->id])
                ->first();

            $responseData = $this->serializeActivity($activityFull, $camping);
            return true;
        });

        if ($error !== null) {
            return $this->apiResponse($error, 422);
        }

        return $this->apiResponse(['data' => $responseData], 201);
    }

    public function edit(int $id): Response
    {
        $this->request->allowMethod(['patch', 'put']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $activity = $this->Activities->find()
            ->contain(['Categories'])
            ->where(['Activities.id' => $id, 'Activities.deleted IS' => null])
            ->first();

        // Permissão de acordo com o tipo — mesma resposta para "não existe" e "não
        // pode", para não revelar nada a quem não tem acesso.
        $permissao = ($activity->camping_id ?? null) !== null ? 'acampamentos' : 'eventos';
        $identity = $this->requirePermissao($permissao);
        if ($identity instanceof Response) return $identity;

        if (!$activity) return $this->apiError('Atividade não encontrada', 404);

        $data  = $this->request->getData();
        $files = $this->request->getUploadedFiles();
        $ad    = $data['activity'] ?? [];

        if ($activity->camping_id === null) {
            $erros = $this->validarEvento($ad, $activity);
            if ($erros) return $this->apiResponse(['error' => 'Confira os campos do evento.', 'errors' => $erros], 422);
        } else {
            $campingAtual = $this->Campings->find()->where(['id' => $activity->camping_id])->first();
            $cd = $data['camping'] ?? [];
            $erros = $this->validarAcampamento($ad, $cd, $activity, $campingAtual);
            if ($erros) return $this->apiResponse(['error' => 'Confira os campos do acampamento.', 'errors' => $erros], 422);
            [$ad, $data['camping']] = $this->normalizarAcampamento($ad, $cd, $activity, $campingAtual);
        }

        $imageBase64 = $ad['image_base64'] ?? null;
        $imagePath   = $imageBase64
            ? $this->saveBase64File($imageBase64, 'activities')
            : $this->saveFile($files['activity']['image'] ?? null, 'activities');

        $startDate    = $ad['start_date'] ?? $activity->start_date?->format('Y-m-d H:i:s');
        $fimEvento    = $ad['end_date'] ?? null;
        $durationDays = isset($ad['duration_days']) ? max(1, intval($ad['duration_days'])) : $activity->duration_days;
        if ($startDate && $fimEvento) {
            $durationDays = max(1, (int)ceil((strtotime($fimEvento) - strtotime($startDate)) / 86400));
        }

        $activityPatch = [
            'name'            => $ad['name']            ?? $activity->name,
            'place'           => $ad['place']           ?? $activity->place,
            'start_date'      => $startDate,
            'duration_days'   => $durationDays,
            'total_vacancies' => intval($ad['total_vacancies'] ?? $activity->total_vacancies),
            'category_id'     => !empty($ad['category_id']) ? intval($ad['category_id']) : $activity->category_id,
            'year'            => $startDate ? (int)date('Y', strtotime($startDate)) : $activity->year,
        ];
        if ($imagePath) {
            $activityPatch['image'] = $imagePath;
        } elseif (!empty($ad['remove_image'])) {
            // Sem imagem, o Início usa o vitral com o brasão.
            $activityPatch['image'] = '';
        }
        if (array_key_exists('description', $ad)) $activityPatch['description'] = $this->textoOuNulo($ad['description']);
        if (isset($ad['min_age'])) $activityPatch['min_age'] = ($ad['min_age'] !== '' && $ad['min_age'] !== 'null') ? intval($ad['min_age']) : null;
        if (isset($ad['max_age'])) $activityPatch['max_age'] = ($ad['max_age'] !== '' && $ad['max_age'] !== 'null') ? intval($ad['max_age']) : null;
        if (isset($ad['target_audience'])) $activityPatch['target_audience'] = $ad['target_audience'];
        if (isset($ad['participant_fee']))                     $activityPatch['participant_fee']                     = floatval($ad['participant_fee']);
        if (isset($ad['servant_fee']))                         $activityPatch['servant_fee']                         = floatval($ad['servant_fee']);
        if (isset($ad['participant_registration_start_date'])) $activityPatch['participant_registration_start_date'] = $ad['participant_registration_start_date'];
        if (isset($ad['participant_registration_end_date']))   $activityPatch['participant_registration_end_date']   = $ad['participant_registration_end_date'];
        if (isset($ad['servant_registration_start_date']))     $activityPatch['servant_registration_start_date']     = $ad['servant_registration_start_date'];
        if (isset($ad['servant_registration_end_date']))       $activityPatch['servant_registration_end_date']       = $ad['servant_registration_end_date'];
        if (isset($ad['participant_payment_date']))            $activityPatch['participant_payment_date']            = $ad['participant_payment_date'];
        if (isset($ad['participant_payment_link']))            $activityPatch['participant_payment_link']            = $ad['participant_payment_link'];
        if (isset($ad['servant_payment_date']))                $activityPatch['servant_payment_date']                = $ad['servant_payment_date'];
        if (isset($ad['servant_payment_link']))                $activityPatch['servant_payment_link']                = $ad['servant_payment_link'];
        if (array_key_exists('event_form_id', $ad) && $activity->camping_id === null) {
            $activityPatch['event_form_id'] = $this->formularioDeEvento($ad);
        }

        $this->Activities->patchEntity($activity, $activityPatch);
        if (!$this->Activities->save($activity)) {
            return $this->apiResponse(['error' => 'Erro ao atualizar atividade', 'errors' => $activity->getErrors()], 422);
        }

        // Sync sectors: add any categories_sectors for this category that aren't in activities_categories_sectors yet.
        // Evento sem categoria não tem setores (antes, editar um evento assim dava erro 500).
        $csLinks = $activity->category_id === null ? [] : $this->CategoriesSectors->find()
            ->where(['category_id' => $activity->category_id, 'deleted IS' => null])
            ->all();
        foreach ($csLinks as $cs) {
            $exists = $this->ActivitiesCategoriesSectors->find()
                ->where([
                    'categories_sectors_id' => $cs->id,
                    'activity_id'           => $activity->id,
                    'deleted IS'            => null,
                ])
                ->count();
            if (!$exists) {
                $acs = $this->ActivitiesCategoriesSectors->newEntity([
                    'categories_sectors_id' => $cs->id,
                    'activity_id'           => $activity->id,
                    'actual_vacancies'      => $cs->vacancies,
                ]);
                $this->ActivitiesCategoriesSectors->save($acs);
            }
        }

        $camping = null;
        if ($activity->camping_id) {
            $camping = $this->Campings->find()->where(['id' => $activity->camping_id])->first();
            if ($camping) {
                $cd    = $data['camping'] ?? [];
                $patch = [];
                foreach (['planned_man_vacancies', 'planned_woman_vacancies', 'planned_couple_vacancies',
                          'raffle_man_vacancies', 'raffle_woman_vacancies', 'raffle_couple_vacancies'] as $f) {
                    if (isset($cd[$f])) $patch[$f] = intval($cd[$f]);
                }
                foreach (['raffle_camper_date', 'raffle_servant_subscription_start_date',
                          'raffle_servant_subscription_end_date', 'raffle_servant_date',
                          'camper_registration_start_date', 'camper_registration_end_date',
                          'camper_form_deadline', 'servant_form_deadline'] as $f) {
                    if (isset($cd[$f])) $patch[$f] = $cd[$f];
                }
                // Edital e termo: só troca o arquivo quando um novo é enviado (mesmas formas aceitas no add()).
                $noticePath = ($cd['notice_base64'] ?? null)
                    ? $this->saveBase64File($cd['notice_base64'], 'campings/notices')
                    : $this->saveFile($files['camping']['notice'] ?? null, 'campings/notices');
                $termPath = ($cd['term_base64'] ?? null)
                    ? $this->saveBase64File($cd['term_base64'], 'campings/terms')
                    : $this->saveFile($files['camping']['term'] ?? null, 'campings/terms');
                // Sem arquivo, o banco guarda "#" (a coluna não aceita vazio).
                if ($noticePath) $patch['notice'] = $noticePath;
                elseif (!empty($cd['remove_notice'])) $patch['notice'] = '#';
                if ($termPath) $patch['term'] = $termPath;
                elseif (!empty($cd['remove_term'])) $patch['term'] = '#';
                $this->Campings->patchEntity($camping, $patch);
                $this->Campings->save($camping);
            }
        }

        $activityFull = $this->Activities->find()
            ->contain(['Categories'])
            ->where(['Activities.id' => $activity->id])
            ->first();

        return $this->apiResponse(['data' => $this->serializeActivity($activityFull, $camping)]);
    }

    public function delete(int $id): Response
    {
        $this->request->allowMethod(['delete']);

        $identity = $this->requirePermissao('excluir_atividades');
        if ($identity instanceof Response) return $identity;

        $activity = $this->Activities->find()
            ->where(['Activities.id' => $id, 'Activities.deleted IS' => null])
            ->first();

        if (!$activity) return $this->apiError('Atividade não encontrada', 404);

        // Com inscrições, quem exclui tem que digitar o nome da atividade
        // (handoff design_handoff_gestao_atividades) — a tela pede, e o servidor
        // confere para não depender só dela.
        $inscritos = $this->inscricoesAtivasPorAtividade([(int)$activity->id]);
        if (count($inscritos[(int)$activity->id] ?? []) > 0) {
            $digitado = (string)($this->request->getData('confirmacao') ?? '');
            if ($this->nomeParaConferir($digitado) !== $this->nomeParaConferir((string)$activity->name)) {
                return $this->apiResponse([
                    'error'  => 'Para excluir uma atividade com inscrições, digite o nome dela.',
                    'codigo' => 'confirmar_nome',
                ], 422);
            }
        }

        $this->Activities->getConnection()->transactional(function () use ($activity) {
            $now = date('Y-m-d H:i:s');
            $this->Activities->updateAll(['deleted' => $now], ['id' => $activity->id]);
            if ($activity->camping_id) {
                $this->Campings->updateAll(['deleted' => $now], ['id' => $activity->camping_id]);
            }
        });

        return $this->apiResponse(['deleted' => true], 200);
    }

    /**
     * GET /admin/atividades/resumo — números da Gestão de Atividades, por id da
     * atividade (a lista em si vem de GET /activities, que é pública):
     *
     * - `inscritos`: inscrições ativas (campistas, servos ou participantes);
     * - `pagamento`: null quando não há cobrança para mostrar (atividade
     *   gratuita, ou ninguém ainda precisa pagar — acampamento antes do
     *   sorteio); senão `noPrazo` (precisa pagar, não pagou, prazo não passou) e
     *   `vencido` (prazo passou: está nos dias de folga antes de perder a vaga).
     */
    public function resumoDoPainel(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('atividades');
        if ($identity instanceof Response) return $identity;

        $atividades = $this->Activities->find()
            ->where(['Activities.deleted IS' => null])
            ->all()
            ->indexBy('id')
            ->toArray();

        $pagamentos = new \App\Service\PagamentoService();
        $agora = \Cake\I18n\DateTime::now()->getTimestamp();
        $porAtividade = $this->inscricoesAtivasPorAtividade(array_keys($atividades));

        $resumo = [];
        foreach ($atividades as $id => $atividade) {
            $inscricoes = $porAtividade[$id] ?? [];
            $cobra = false;
            $noPrazo = 0;
            $vencido = 0;
            foreach ($inscricoes as $inscricao) {
                if (!$pagamentos->precisaPagar($inscricao, $atividade)) continue;
                $cobra = true;
                if ($inscricao->is_fee_paid) continue;
                $prazo = $pagamentos->prazo($inscricao, $atividade);
                if ($prazo !== null && $agora > $prazo->getTimestamp()) {
                    $vencido++;
                } else {
                    $noPrazo++;
                }
            }
            $resumo[(string)$id] = [
                'inscritos' => count($inscricoes),
                'pagamento' => $cobra ? ['noPrazo' => $noPrazo, 'vencido' => $vencido] : null,
            ];
        }

        return $this->apiResponse(['data' => $resumo]);
    }

    /**
     * Inscrições ativas (PagamentoService::ativa) agrupadas pela atividade.
     *
     * @param array<int> $atividadeIds
     * @return array<int, array<object>>
     */
    private function inscricoesAtivasPorAtividade(array $atividadeIds): array
    {
        if (!$atividadeIds) return [];

        $pagamentos = new \App\Service\PagamentoService();
        $porAtividade = [];
        $inscricoes = $this->PreRegistrations->find()
            ->innerJoinWith('Users', fn($q) => $q->where(['Users.deleted IS' => null]))
            ->where([
                'PreRegistrations.activity_id IN' => $atividadeIds,
                'PreRegistrations.deleted IS'     => null,
            ])
            ->all();
        foreach ($inscricoes as $inscricao) {
            if ($pagamentos->ativa($inscricao)) {
                $porAtividade[(int)$inscricao->activity_id][] = $inscricao;
            }
        }

        return $porAtividade;
    }

    /** Nome comparado sem maiúsculas, acentos e espaços a mais (confirmação da exclusão). */
    private function nomeParaConferir(string $nome): string
    {
        $semAcento = \Cake\Utility\Text::transliterate($nome, 'Any-Latin; Latin-ASCII');

        return mb_strtolower(trim((string)preg_replace('/\s+/', ' ', $semAcento)));
    }

    public function feature(int $id): Response
    {
        $this->request->allowMethod(['patch']);

        $identity = $this->requirePermissao('atividades');
        if ($identity instanceof Response) return $identity;

        $activity = $this->Activities->find()
            ->where(['Activities.id' => $id, 'Activities.deleted IS' => null])
            ->first();

        if (!$activity) return $this->apiError('Atividade não encontrada', 404);

        $this->Activities->updateAll(['is_featured' => false], ['deleted IS' => null]);
        $this->Activities->updateAll(['is_featured' => true], ['id' => $id]);

        return $this->apiResponse(['id' => $id, 'destaque' => true]);
    }

    // ─── Activity Sectors ──────────────────────────────────────────────────

    // GET /activities/{id}/sectors
    public function sectors(int $id): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $activity = $this->Activities->find()
            ->where(['Activities.id' => $id, 'Activities.deleted IS' => null])
            ->first();
        if (!$activity) return $this->apiError('Atividade não encontrada', 404);

        // Sectors already linked to this activity
        $linked = $this->ActivitiesCategoriesSectors->find()
            ->contain(['CategoriesSectors' => ['Sectors', 'Categories']])
            ->where([
                'ActivitiesCategoriesSectors.activity_id' => $id,
                'ActivitiesCategoriesSectors.deleted IS'  => null,
            ])
            ->all();

        $linkedCsIds = [];
        $result = [];
        foreach ($linked as $acs) {
            $cs = $acs->categories_sector;
            $linkedCsIds[] = $acs->categories_sectors_id;
            $result[] = [
                'id'                    => $acs->id,
                'categories_sectors_id' => $acs->categories_sectors_id,
                'sector_id'             => $cs->sector_id,
                'sector_name'           => $cs->sector->name  ?? '',
                'sector_place'          => $cs->sector->place ?? '',
                'category_id'           => $cs->category_id,
                'category_name'         => $cs->category->name ?? '',
                'default_vacancies'     => $cs->vacancies,
                'actual_vacancies'      => $acs->actual_vacancies,
                'linked'                => true,
            ];
        }

        // Sectors available (in categories_sectors for this activity's category) but not yet linked
        $available = $this->CategoriesSectors->find()
            ->contain(['Sectors', 'Categories'])
            ->where([
                'CategoriesSectors.category_id' => $activity->category_id,
                'CategoriesSectors.deleted IS'  => null,
            ])
            ->all();

        foreach ($available as $cs) {
            if (in_array($cs->id, $linkedCsIds, true)) continue;
            $result[] = [
                'id'                    => null,
                'categories_sectors_id' => $cs->id,
                'sector_id'             => $cs->sector_id,
                'sector_name'           => $cs->sector->name  ?? '',
                'sector_place'          => $cs->sector->place ?? '',
                'category_id'           => $cs->category_id,
                'category_name'         => $cs->category->name ?? '',
                'default_vacancies'     => $cs->vacancies,
                'actual_vacancies'      => $cs->vacancies,
                'linked'                => false,
            ];
        }

        // Fetch coordinators + regular members for all linked sectors in one query
        $linkedIds = array_values(array_filter(array_column($result, 'id')));
        $coordMap  = [];
        $memberMap = [];
        $myAcsCoordIds = [];
        if (!empty($linkedIds)) {
            $members = $this->AcsSectorsUsers->find()
                ->contain(['Users'])
                ->where(['activities_categories_sector_id IN' => $linkedIds])
                ->all();
            foreach ($members as $m) {
                // Suplentes do sorteio (ainda não convocados) não contam como membro
                // confirmado da equipe — só aparecem na tela de resultado do sorteio.
                if ((int)$m->is_substitute === 1) continue;

                $row = [
                    'user_id' => $m->user_id,
                    'nome'    => $m->user->name ?? '',
                    'cpf'     => $this->maskCpf((string)($m->user->cpf ?? '')),
                ];
                if ($m->is_coordinator) {
                    $coordMap[$m->activities_categories_sector_id][] = $row;
                    if ($m->user_id === $identity->id) $myAcsCoordIds[] = $m->activities_categories_sector_id;
                } else {
                    $memberMap[$m->activities_categories_sector_id][] = $row;
                }
            }
        }

        foreach ($result as &$r) {
            $r['coordenadores'] = ($r['linked'] && isset($coordMap[$r['id']])) ? $coordMap[$r['id']] : [];
            $r['membros']       = ($r['linked'] && isset($memberMap[$r['id']])) ? $memberMap[$r['id']] : [];
            $ocupadas           = count($r['coordenadores']) + count($r['membros']);
            $r['vagas_faltando'] = max(0, $r['actual_vacancies'] - $ocupadas);
        }
        unset($r);

        // Servos gerais desta atividade
        $servoGeral = $this->ActivitiesGeneralServants->find()
            ->contain(['Users'])
            ->where(['activity_id' => $id])
            ->all();
        $servoGeralData = [];
        $souGeral = false;
        foreach ($servoGeral as $sg) {
            $servoGeralData[] = [
                'user_id' => $sg->user_id,
                'nome'    => $sg->user->name ?? '',
                'cpf'     => $this->maskCpf((string)($sg->user->cpf ?? '')),
            ];
            if ($sg->user_id === $identity->id) $souGeral = true;
        }

        // Servos inscritos nesta atividade (pool elegível para compor equipes)
        $inscritosServo = $this->PreRegistrations->find()
            ->contain(['Users'])
            ->where([
                'PreRegistrations.activity_id'       => $id,
                'PreRegistrations.subscription_type' => 'Servo',
                'PreRegistrations.deleted IS'         => null,
                'Users.deleted IS'                    => null,
            ])
            ->all();
        $servosInscritos = [];
        foreach ($inscritosServo as $insc) {
            $servosInscritos[] = [
                'user_id' => $insc->user_id,
                'nome'    => $insc->user->name ?? '',
                'cpf'     => $this->maskCpf((string)($insc->user->cpf ?? '')),
            ];
        }

        return $this->apiResponse(['data' => [
            'setores'      => $result,
            'servo_geral'  => $servoGeralData,
            'servos_inscritos' => $servosInscritos,
            'meu_papel'    => [
                // "Admin" desta tela = quem tem a permissão Equipes de Servos no painel.
                'is_admin'           => $this->temPermissao($identity, 'equipes'),
                'is_servo_geral'     => $souGeral,
                'lidera_setores_ids' => $myAcsCoordIds,
            ],
        ]]);
    }

    // POST /activities/{id}/sectors
    public function addSector(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('equipes');
        if ($identity instanceof Response) return $identity;

        $activity = $this->Activities->find()
            ->where(['Activities.id' => $id, 'Activities.deleted IS' => null])
            ->first();
        if (!$activity) return $this->apiError('Atividade não encontrada', 404);

        $data  = $this->request->getData();
        $csId  = intval($data['categories_sectors_id'] ?? 0);
        if (!$csId) return $this->apiError('categories_sectors_id obrigatório', 422);

        $existing = $this->ActivitiesCategoriesSectors->find()
            ->where(['activity_id' => $id, 'categories_sectors_id' => $csId, 'deleted IS' => null])
            ->first();
        if ($existing) return $this->apiError('Setor já vinculado a essa atividade', 409);

        $acs = $this->ActivitiesCategoriesSectors->newEntity([
            'categories_sectors_id' => $csId,
            'activity_id'           => $id,
            'actual_vacancies'      => intval($data['actual_vacancies'] ?? 0),
        ]);

        if (!$this->ActivitiesCategoriesSectors->save($acs)) {
            return $this->apiResponse(['error' => 'Erro ao vincular', 'errors' => $acs->getErrors()], 422);
        }

        $cs = $this->CategoriesSectors->find()
            ->contain(['Sectors', 'Categories'])
            ->where(['CategoriesSectors.id' => $csId])
            ->first();

        return $this->apiResponse(['data' => [
            'id'                    => $acs->id,
            'categories_sectors_id' => $acs->categories_sectors_id,
            'sector_id'             => $cs->sector_id,
            'sector_name'           => $cs->sector->name  ?? '',
            'sector_place'          => $cs->sector->place ?? '',
            'category_id'           => $cs->category_id,
            'category_name'         => $cs->category->name ?? '',
            'default_vacancies'     => $cs->vacancies,
            'actual_vacancies'      => $acs->actual_vacancies,
            'linked'                => true,
        ]], 201);
    }

    // PATCH /activities/{activityId}/sectors/{acsId}
    public function updateSector(int $activityId, int $acsId): Response
    {
        $this->request->allowMethod(['patch', 'put']);
        $identity = $this->requirePermissao('equipes');
        if ($identity instanceof Response) return $identity;

        $acs = $this->ActivitiesCategoriesSectors->find()
            ->where(['id' => $acsId, 'activity_id' => $activityId, 'deleted IS' => null])
            ->first();
        if (!$acs) return $this->apiError('Vínculo não encontrado', 404);

        $data  = $this->request->getData();
        $patch = [];
        if (isset($data['actual_vacancies'])) $patch['actual_vacancies'] = intval($data['actual_vacancies']);

        $this->ActivitiesCategoriesSectors->patchEntity($acs, $patch);
        if (!$this->ActivitiesCategoriesSectors->save($acs)) {
            return $this->apiResponse(['error' => 'Erro ao atualizar', 'errors' => $acs->getErrors()], 422);
        }

        return $this->apiResponse(['data' => [
            'id'               => $acs->id,
            'actual_vacancies' => $acs->actual_vacancies,
        ]]);
    }

    // DELETE /activities/{activityId}/sectors/{acsId}
    public function deleteSector(int $activityId, int $acsId): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('equipes');
        if ($identity instanceof Response) return $identity;

        $acs = $this->ActivitiesCategoriesSectors->find()
            ->where(['id' => $acsId, 'activity_id' => $activityId, 'deleted IS' => null])
            ->first();
        if (!$acs) return $this->apiError('Vínculo não encontrado', 404);

        $pessoasDoSetor = $this->AcsSectorsUsers->find()
            ->select(['user_id'])
            ->where(['activities_categories_sector_id' => $acsId])
            ->all()
            ->extract('user_id')
            ->toArray();

        $this->ActivitiesCategoriesSectors->updateAll(['deleted' => date('Y-m-d H:i:s')], ['id' => $acsId]);
        $this->desfazerResultadoDoSorteio($activityId, $pessoasDoSetor);

        return $this->apiResponse(['deleted' => true]);
    }

    // ─── Activity Sector Members ───────────────────────────────────────────

    // POST /activities/{activityId}/sectors/{acsId}/members
    // body: { user_id, is_coordinator? } — is_coordinator=true indica um líder do
    // setor; só admin/servo geral podem indicar líderes. Um membro comum pode ser
    // adicionado também pelo líder daquele setor.
    public function addSectorMember(int $activityId, int $acsId): Response
    {
        $this->request->allowMethod(['post']);

        $data          = $this->request->getData();
        $isCoordinator = (bool)($data['is_coordinator'] ?? false);

        $identity = $isCoordinator
            ? $this->requireAdminOrGeneral($activityId)
            : $this->requireCanManageSector($activityId, $acsId);
        if ($identity instanceof Response) return $identity;

        $acs = $this->ActivitiesCategoriesSectors->find()
            ->where(['id' => $acsId, 'activity_id' => $activityId, 'deleted IS' => null])
            ->first();
        if (!$acs) return $this->apiError('Setor não encontrado', 404);

        $userId = intval($data['user_id'] ?? 0);
        if (!$userId) return $this->apiError('user_id obrigatório', 422);

        $user = $this->Users->find()->where(['id' => $userId, 'deleted IS' => null])->first();
        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        if (!$this->isEligibleServant($activityId, $userId)) {
            return $this->apiError('Usuário precisa estar inscrito como Servo nesta atividade', 422);
        }

        if ($this->isGeneralServant($activityId, $userId)) {
            return $this->apiError('Usuário é servo geral desta atividade e não pode fazer parte de uma equipe específica', 422);
        }

        $setorAtual = $this->findSectorTeamOf($activityId, $userId);
        if ($setorAtual !== null) {
            $mesmoSetor = $this->AcsSectorsUsers->exists(['activities_categories_sector_id' => $acsId, 'user_id' => $userId]);
            if ($mesmoSetor) {
                return $this->apiError('Usuário já está nesta equipe', 409);
            }
            return $this->apiError("Usuário já está no setor \"{$setorAtual}\" desta atividade. Remova-o de lá primeiro para movê-lo.", 409);
        }

        $this->tirarDaFilaDeSuplentes($activityId, $userId);

        $record = $this->AcsSectorsUsers->newEntity([
            'activities_categories_sector_id' => $acsId,
            'user_id'                         => $userId,
            'is_coordinator'                  => $isCoordinator,
            'is_indicated'                     => true,
        ]);
        if (!$this->AcsSectorsUsers->save($record)) {
            return $this->apiError('Erro ao salvar', 422);
        }

        return $this->apiResponse(['data' => [
            'user_id'         => $userId,
            'nome'            => $user->name ?? '',
            'cpf'             => $this->maskCpf((string)($user->cpf ?? '')),
            'is_coordinator'  => $isCoordinator,
        ]], 201);
    }

    // DELETE /activities/{activityId}/sectors/{acsId}/members/{userId}
    public function removeSectorMember(int $activityId, int $acsId, int $userId): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requireCanManageSector($activityId, $acsId);
        if ($identity instanceof Response) return $identity;

        if (!$this->temPermissao($identity, 'equipes') && $identity->id === $userId) {
            return $this->apiError('Você não pode remover a si mesmo desta equipe. Peça para outro líder, o servo geral ou um administrador fazer isso.', 403);
        }

        $acs = $this->ActivitiesCategoriesSectors->find()
            ->where(['id' => $acsId, 'activity_id' => $activityId, 'deleted IS' => null])
            ->first();
        if (!$acs) return $this->apiError('Setor não encontrado', 404);

        $removidas = $this->AcsSectorsUsers->deleteAll([
            'activities_categories_sector_id' => $acsId,
            'user_id'                         => $userId,
        ]);
        if ($removidas > 0) {
            $this->desfazerResultadoDoSorteio($activityId, [$userId]);
        }

        return $this->apiResponse(['deleted' => true]);
    }

    // ─── Servo geral da atividade ───────────────────────────────────────────

    // POST /activities/{id}/general-servants  body: { user_id }
    public function addGeneralServant(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('equipes');
        if ($identity instanceof Response) return $identity;

        $activity = $this->Activities->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$activity) return $this->apiError('Atividade não encontrada', 404);

        $userId = intval($this->request->getData('user_id') ?? 0);
        if (!$userId) return $this->apiError('user_id obrigatório', 422);

        $user = $this->Users->find()->where(['id' => $userId, 'deleted IS' => null])->first();
        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        if (!$this->isEligibleServant($id, $userId)) {
            return $this->apiError('Usuário precisa estar inscrito como Servo nesta atividade', 422);
        }

        if ($this->isGeneralServant($id, $userId)) {
            return $this->apiError('Usuário já é servo geral desta atividade', 409);
        }

        $setorAtual = $this->findSectorTeamOf($id, $userId);
        if ($setorAtual !== null) {
            return $this->apiError("Usuário já faz parte da equipe do setor \"{$setorAtual}\" e não pode virar servo geral ao mesmo tempo. Remova-o de lá primeiro.", 422);
        }

        $this->tirarDaFilaDeSuplentes($id, $userId);

        $record = $this->ActivitiesGeneralServants->newEntity([
            'activity_id' => $id,
            'user_id'     => $userId,
            'created'     => date('Y-m-d H:i:s'),
        ]);
        if (!$this->ActivitiesGeneralServants->save($record)) {
            return $this->apiError('Erro ao salvar', 422);
        }

        return $this->apiResponse(['data' => [
            'user_id' => $userId,
            'nome'    => $user->name ?? '',
            'cpf'     => $this->maskCpf((string)($user->cpf ?? '')),
        ]], 201);
    }

    // DELETE /activities/{id}/general-servants/{userId}
    public function removeGeneralServant(int $id, int $userId): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('equipes');
        if ($identity instanceof Response) return $identity;

        $this->ActivitiesGeneralServants->deleteAll(['activity_id' => $id, 'user_id' => $userId]);

        return $this->apiResponse(['deleted' => true]);
    }

    // GET /me/equipes — em quais atividades o usuário logado tem papel de servo
    public function minhasEquipes(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $geral = $this->ActivitiesGeneralServants->find()
            ->contain(['Activities'])
            ->where(['user_id' => $identity->id, 'Activities.deleted IS' => null])
            ->all();

        $geralData = [];
        foreach ($geral as $g) {
            $geralData[] = ['activity_id' => $g->activity_id, 'activity_name' => $g->activity->name ?? ''];
        }

        $minhasLinhas = $this->AcsSectorsUsers->find()
            ->contain(['ActivitiesCategoriesSectors' => ['CategoriesSectors' => ['Sectors'], 'Activities']])
            ->where(['ActivitiesCategoriesSectorsUsers.user_id' => $identity->id])
            ->all();

        $lideranca = [];
        $equipes   = [];
        foreach ($minhasLinhas as $linha) {
            $acs = $linha->activities_categories_sector;
            if (!$acs || ($acs->deleted ?? null) !== null) continue;
            $item = [
                'activities_categories_sector_id' => $acs->id,
                'activity_id'                      => $acs->activity_id,
                'activity_name'                    => $acs->activity->name ?? '',
                'sector_id'                        => $acs->categories_sector->sector_id ?? null,
                'sector_name'                       => $acs->categories_sector->sector->name ?? '',
            ];
            if ($linha->is_coordinator) {
                $lideranca[] = $item;
            } else {
                $equipes[] = $item;
            }
        }

        return $this->apiResponse(['data' => [
            'servo_geral' => $geralData,
            'lideranca'   => $lideranca,
            'equipes'     => $equipes,
        ]]);
    }

    // ─── Autorização: equipes de servos ────────────────────────────────────

    private function isGeneralServant(int $activityId, int $userId): bool
    {
        return $this->ActivitiesGeneralServants->exists([
            'activity_id' => $activityId,
            'user_id'     => $userId,
        ]);
    }

    private function isSectorCoordinator(int $acsId, int $userId): bool
    {
        return $this->AcsSectorsUsers->exists([
            'activities_categories_sector_id' => $acsId,
            'user_id'                         => $userId,
            'is_coordinator'                  => true,
        ]);
    }

    // Regra de negócio: só quem se inscreveu como Servo naquela atividade pode
    // ser colocado numa equipe (líder ou membro) ou virar servo geral — a
    // inscrição por si só já basta, não depende do sorteio ter sido feito.
    private function isEligibleServant(int $activityId, int $userId): bool
    {
        return $this->PreRegistrations->exists([
            'PreRegistrations.activity_id'       => $activityId,
            'PreRegistrations.user_id'           => $userId,
            'PreRegistrations.subscription_type' => 'Servo',
            'PreRegistrations.deleted IS'         => null,
        ]);
    }

    // Setor (nome) em que a pessoa já está alocada nesta atividade, se houver.
    // Servo geral e membro/líder de um setor específico são papéis mutuamente
    // exclusivos — quem é geral não entra numa equipe, e vice-versa.
    private function findSectorTeamOf(int $activityId, int $userId): ?string
    {
        $acsIdsDaAtividade = $this->acsIdsDaAtividade($activityId);
        if (empty($acsIdsDaAtividade)) return null;

        $existing = $this->AcsSectorsUsers->find()
            ->contain(['ActivitiesCategoriesSectors' => ['CategoriesSectors' => ['Sectors']]])
            ->where([
                'activities_categories_sector_id IN' => $acsIdsDaAtividade,
                'user_id'                            => $userId,
                'OR' => [
                    'is_substitute IS' => null,
                    'is_substitute'    => 0,
                ],
            ])
            ->first();

        if (!$existing) return null;

        return $existing->activities_categories_sector->categories_sector->sector->name ?? 'outro setor';
    }

    // Suplente do sorteio ainda não tem lugar em equipe nenhuma, então pode ser
    // colocado à mão em outro setor (ou virar servo geral). Ao ser colocado, sai
    // da fila de suplentes — senão, se fosse chamado depois, ficaria em dois
    // lugares na mesma atividade.
    private function tirarDaFilaDeSuplentes(int $activityId, int $userId): void
    {
        $acsIdsDaAtividade = $this->acsIdsDaAtividade($activityId);
        if (empty($acsIdsDaAtividade)) return;

        $removidas = $this->AcsSectorsUsers->deleteAll([
            'activities_categories_sector_id IN' => $acsIdsDaAtividade,
            'user_id'                            => $userId,
            'is_substitute'                      => true,
        ]);
        if ($removidas === 0) return;

        $this->PreRegistrations->updateAll(
            ['substitute_position' => null],
            [
                'activity_id'       => $activityId,
                'user_id'           => $userId,
                'subscription_type' => 'Servo',
                'deleted IS'        => null,
            ]
        );
    }

    // Quem sai de uma equipe (removido, mesmo que sem querer, ou porque o setor
    // foi tirado da atividade) volta para "não sorteados" no quadro do sorteio —
    // e a inscrição também deixa de dizer "Sorteado"/"Suplente", senão a tabela
    // de inscritos continuaria mostrando uma vaga que não existe mais. Indicação
    // (Base/Forania/Conselho) não é mexida.
    private function desfazerResultadoDoSorteio(int $activityId, array $userIds): void
    {
        if (empty($userIds)) return;

        $inscricoes = [
            'activity_id'       => $activityId,
            'user_id IN'        => $userIds,
            'subscription_type' => 'Servo',
            'deleted IS'        => null,
        ];
        $this->PreRegistrations->updateAll(['substitute_position' => null], $inscricoes);

        $metodoSorteio = $this->fetchTable('SelectionMethods')->find()
            ->select(['id'])
            ->where(['method' => 'Sorteio'])
            ->all()
            ->extract('id')
            ->toArray();
        if (!empty($metodoSorteio)) {
            $this->PreRegistrations->updateAll(
                ['selection_method_id' => null],
                $inscricoes + ['selection_method_id IN' => $metodoSorteio]
            );
        }
    }

    private function acsIdsDaAtividade(int $activityId): array
    {
        return $this->ActivitiesCategoriesSectors->find()
            ->select(['id'])
            ->where(['activity_id' => $activityId, 'deleted IS' => null])
            ->all()
            ->extract('id')
            ->toArray();
    }

    // Quem tem a permissão "Equipes de Servos" no painel, ou o servo geral daquela atividade
    private function requireAdminOrGeneral(int $activityId): mixed
    {
        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;
        if ($this->temPermissao($identity, 'equipes') || $this->isGeneralServant($activityId, $identity->id)) {
            return $identity;
        }
        return $this->apiError('Acesso restrito ao servo geral desta atividade', 403);
    }

    // Permissão "Equipes de Servos", servo geral da atividade, ou líder do setor específico
    private function requireCanManageSector(int $activityId, int $acsId): mixed
    {
        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;
        if (
            $this->temPermissao($identity, 'equipes')
            || $this->isGeneralServant($activityId, $identity->id)
            || $this->isSectorCoordinator($acsId, $identity->id)
        ) {
            return $identity;
        }
        return $this->apiError('Acesso restrito ao servo geral ou ao líder deste setor', 403);
    }

    // ─── Helpers privados ──────────────────────────────────────────────────

    private function saveBase64File(string $base64, string $folder): ?string
    {
        if (!preg_match('/^data:(\w+\/[\w+\-]+);base64,(.+)$/s', $base64, $m)) {
            return null;
        }

        $ext = match (strtolower($m[1])) {
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'image/gif'       => 'gif',
            'application/pdf' => 'pdf',
            default           => 'bin',
        };

        $data = base64_decode($m[2]);
        if (!$data) return null;

        $dir = WWW_ROOT . 'uploads' . DS . str_replace('/', DS, $folder);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        file_put_contents($dir . DS . $filename, $data);

        return 'uploads/' . $folder . '/' . $filename;
    }

    private function saveFile(?object $file, string $folder): ?string
    {
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        $webrootPath = WWW_ROOT . 'uploads' . DS . str_replace('/', DS, $folder);
        if (!is_dir($webrootPath)) {
            mkdir($webrootPath, 0755, true);
        }

        $ext      = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
        $file->moveTo($webrootPath . DS . $filename);

        return 'uploads/' . $folder . '/' . $filename;
    }

    private function serializeActivity(object $activity, ?object $camping): array
    {
        $isCamping = $activity->camping_id !== null;

        $base = [
            'id'          => $activity->id,
            'destaque'    => (bool)$activity->is_featured,
            'tipo'        => $isCamping ? 'Acampamento' : 'Evento',
            'nome'        => $activity->name,
            'local'       => $activity->place,
            'imagem'      => $activity->image ? 'http://localhost:8765/' . $activity->image : null,
            'publicoAlvo' => $activity->category->name ?? '',
            'categoria'   => $activity->category
                ? ['id' => $activity->category->id, 'nome' => $activity->category->name, 'tipo' => $activity->category->type]
                : null,
            'vagasTotais'  => $activity->total_vacancies,
            'inicioEvento' => $activity->start_date?->format('Y-m-d\TH:i'),
            'fimEvento'    => $this->calcEndDate($activity->start_date, $activity->duration_days),
            'status'       => $this->calcStatus($activity),
            // O Início mostra da criada mais recente para a mais antiga.
            'criadoEm'     => $activity->created?->format('Y-m-d\TH:i:s'),
            'idadeMinima'  => $activity->min_age,
            'idadeMaxima'  => $activity->max_age,
            'targetAudience' => $activity->target_audience ?? 'Geral',
            // "Sobre o evento": texto livre da organização (quebras de linha mantidas).
            'sobre'          => $activity->description,
            // Registration fields live on activity for both types
            'taxaParticipante'      => (float)$activity->participant_fee,
            'taxaServo'             => (float)$activity->servant_fee,
            'inicioInscricoes'      => $activity->participant_registration_start_date?->format('Y-m-d\TH:i'),
            'fimInscricoes'         => $activity->participant_registration_end_date?->format('Y-m-d\TH:i'),
            'inicioInscricoesServo' => $activity->servant_registration_start_date?->format('Y-m-d\TH:i'),
            'fimInscricoesServo'    => $activity->servant_registration_end_date?->format('Y-m-d\TH:i'),
            // O link de pagamento não é público: vem na inscrição de quem pode pagar
            // (GET /me/inscricoes, "linkPagamento").
            'prazoPagamentoParticipante' => $activity->participant_payment_date?->format('Y-m-d\TH:i'),
            'prazoPagamentoServo'        => $activity->servant_payment_date?->format('Y-m-d\TH:i'),
            // Evento com formulário: a inscrição só conclui quando a pessoa envia.
            'formularioId'               => $activity->event_form_id,
        ];

        // Backward-compat aliases used by frontend
        $base['taxaCampista'] = $base['taxaParticipante'];
        $base['taxaEvento']   = $base['taxaParticipante'];

        if ($isCamping && $camping) {
            $base = array_merge($base, [
                'vagasReservadas'       => $camping->raffle_man_vacancies + $camping->raffle_woman_vacancies + $camping->raffle_couple_vacancies,
                'vagasSorteioHomem'     => $camping->raffle_man_vacancies,
                'vagasSorteioMulher'    => $camping->raffle_woman_vacancies,
                'vagasSorteioCasal'     => $camping->raffle_couple_vacancies,
                'inicioSorteio'         => $camping->raffle_camper_date?->format('Y-m-d\TH:i'),
                // O selo "Aguardando sorteio" do site: um sorteio feito à mão antes
                // da data já conta como feito.
                'sorteioCampistasRealizado' => $this->sorteioService->campistasJaRealizado((int)$activity->id),
                'sorteioServosRealizado'    => $this->sorteioService->servosJaRealizado((int)$activity->id),
                // Prazo para o selecionado responder o formulário; vencido, a vaga
                // vai para o primeiro suplente da fila.
                'prazoFormularioCampista' => $camping->camper_form_deadline?->format('Y-m-d\TH:i'),
                'prazoFormularioServo'    => $camping->servant_form_deadline?->format('Y-m-d\TH:i'),
                'inicioSorteioServo'    => $camping->raffle_servant_date?->format('Y-m-d\TH:i'),
                'inicioInscricoesServos'=> $camping->raffle_servant_subscription_start_date?->format('Y-m-d\TH:i'),
                'fimInscricoesServos'   => $camping->raffle_servant_subscription_end_date?->format('Y-m-d\TH:i'),
                'inicioFormulario'      => $camping->camper_registration_start_date?->format('Y-m-d\TH:i'),
                'fimFormulario'         => $camping->camper_registration_end_date?->format('Y-m-d\TH:i'),
                'editalEvento'          => $camping->notice ? 'http://localhost:8765/' . $camping->notice : null,
                'termoAutorizacao'      => $camping->term   ? 'http://localhost:8765/' . $camping->term   : null,
                // Tamanho em bytes (null sem arquivo: o banco guarda "#").
                'editalTamanho'         => $this->tamanhoDoArquivo($camping->notice),
                'termoTamanho'          => $this->tamanhoDoArquivo($camping->term),
            ]);
        }

        return $base;
    }

    private function textoOuNulo(mixed $valor): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    /**
     * Inscrições ativas do evento; com $concluidas, só as que ocupam vaga (com
     * formulário, só quem enviou) — a mesma conta do "acabaram as vagas".
     */
    private function contarInscricoes(object $activity, bool $concluidas): int
    {
        $where = ['activity_id' => $activity->id, 'deleted IS' => null, 'is_quitter' => false, 'disqualified_at IS' => null];
        if ($concluidas && $activity->event_form_id) $where['form_submitted'] = true;

        return $this->fetchTable('PreRegistrations')->find()->where($where)->count();
    }

    /**
     * Regras da tela Criar / Editar Evento (handoff design_handoff_painel_evento).
     * As datas do evento não têm hora: a tela manda o primeiro dia às 00h00, a
     * abertura às 00h00 e o fechamento e o prazo do pagamento às 23h59.
     *
     * @return array<string, array<string, string>> erros por campo, no formato do Cake
     */
    private function validarEvento(array $ad, ?object $atual): array
    {
        $valor = function (string $campo) use ($ad, $atual): mixed {
            if (array_key_exists($campo, $ad)) return $ad[$campo];
            $v = $atual?->get($campo);

            return $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v;
        };
        $dia = function (mixed $v): ?string {
            if (!is_string($v) || trim($v) === '') return null;
            $t = strtotime($v);

            return $t === false ? null : date('Y-m-d', $t);
        };
        $br = fn(string $ymd) => date('d/m/Y', strtotime($ymd));
        $erros = [];
        $erro = function (string $campo, string $msg) use (&$erros): void {
            $erros[$campo] ??= ['regra' => $msg];
        };

        if (trim((string)$valor('name')) === '') $erro('name', 'Informe o nome do evento.');
        if (trim((string)$valor('place')) === '') $erro('place', 'Informe o local.');

        $inicio = $dia($valor('start_date'));
        if ($inicio === null) {
            $erro('start_date', 'Informe o primeiro dia do evento.');
        } elseif ($atual === null && $inicio < date('Y-m-d')) {
            $erro('start_date', 'O primeiro dia já passou. Escolha hoje ou uma data futura.');
        }

        $vagas = (int)$valor('total_vacancies');
        if ($vagas < 1) {
            $erro('total_vacancies', 'Informe quantas vagas o evento tem (pelo menos 1).');
        } elseif ($atual !== null && $vagas < ($ocupadas = $this->contarInscricoes($atual, true))) {
            $erro('total_vacancies', "Já há {$ocupadas} vagas ocupadas: o total não pode ser menor que isso.");
        }

        $abre = $dia($valor('participant_registration_start_date'));
        $fecha = $dia($valor('participant_registration_end_date'));
        if ($abre === null) $erro('participant_registration_start_date', 'Informe quando as inscrições abrem.');
        if ($fecha === null) {
            $erro('participant_registration_end_date', 'Informe quando as inscrições fecham.');
        } elseif ($abre !== null && $fecha < $abre) {
            $erro('participant_registration_end_date', 'O fechamento não pode ser antes da abertura.');
        } elseif ($inicio !== null && $fecha > $inicio) {
            $erro('participant_registration_end_date', "As inscrições precisam fechar até o primeiro dia do evento ({$br($inicio)}).");
        }

        $idades = [];
        foreach (['min_age', 'max_age'] as $campo) {
            $v = $valor($campo);
            if ($v === null || $v === '' || $v === 'null') continue;
            if (!is_numeric($v) || (int)$v != $v || (int)$v < 0 || (int)$v > 120) {
                $erro($campo, 'Use idades entre 0 e 120 anos.');
                continue;
            }
            $idades[$campo] = (int)$v;
        }
        if (isset($idades['min_age'], $idades['max_age']) && $idades['min_age'] > $idades['max_age']) {
            $erro('min_age', 'A idade mínima não pode ser maior que a máxima.');
        }

        if ((float)$valor('participant_fee') > 0) {
            $prazo = $dia($valor('participant_payment_date'));
            if ($prazo === null) {
                $erro('participant_payment_date', 'Informe o prazo do pagamento.');
            } elseif ($inicio !== null && $prazo > $inicio) {
                $erro('participant_payment_date', "O prazo do pagamento precisa ser até o primeiro dia do evento ({$br($inicio)}).");
            } elseif ($abre !== null && $prazo < $abre) {
                $erro('participant_payment_date', 'O prazo do pagamento não pode ser antes da abertura das inscrições.');
            }
        } elseif ($valor('participant_fee') !== null && (float)$valor('participant_fee') < 0) {
            $erro('participant_fee', 'Informe o valor por pessoa. Se não houver cobrança, escolha “Gratuito”.');
        }

        return $erros;
    }

    /**
     * Regras da tela Criar / Editar Acampamento (handoff design_handoff_painel_acampamento).
     * As datas do acampamento não têm hora: a tela manda o primeiro dia e as
     * aberturas às 00h00, e os fechamentos e prazos às 23h59. Por isso as
     * comparações são por dia. Campos que faltam no pedido vêm do que já está
     * gravado (edição).
     *
     * @return array<string, array<string, string>> erros por coluna, no formato do Cake
     */
    private function validarAcampamento(array $ad, array $cd, ?object $atividade, ?object $camping): array
    {
        $valor = function (array $dados, ?object $atual, string $campo): mixed {
            if (array_key_exists($campo, $dados)) return $dados[$campo];
            $v = $atual?->get($campo);

            return $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v;
        };
        $daAtividade = fn(string $campo) => $valor($ad, $atividade, $campo);
        $doAcampamento = fn(string $campo) => $valor($cd, $camping, $campo);
        $dia = function (mixed $v): ?string {
            if (!is_string($v) || trim($v) === '') return null;
            $t = strtotime($v);

            return $t === false ? null : date('Y-m-d', $t);
        };
        $br = fn(string $ymd) => date('d/m/Y', strtotime($ymd));
        $erros = [];
        $erro = function (string $campo, string $msg) use (&$erros): void {
            $erros[$campo] ??= ['regra' => $msg];
        };

        if (trim((string)$daAtividade('name')) === '') $erro('name', 'Informe o nome do acampamento.');
        if (trim((string)$daAtividade('place')) === '') $erro('place', 'Informe o local.');
        $categoria = $this->categoriaDeAcampamento($daAtividade('category_id'));
        if ($categoria === null) $erro('category_id', 'Escolha a categoria.');

        $inicio = $dia($daAtividade('start_date'));
        if ($inicio === null) {
            $erro('start_date', 'Informe o primeiro dia do acampamento.');
        } elseif ($atividade === null && $inicio < date('Y-m-d')) {
            $erro('start_date', 'O primeiro dia já passou. Escolha hoje ou uma data futura.');
        }
        if ((int)$daAtividade('duration_days') < 1) $erro('duration_days', 'O último dia não pode ser antes do primeiro.');

        // Campista: período na atividade; servo: no acampamento. Sorteio e prazo
        // ficam no acampamento; o valor, na atividade.
        $trilhas = [
            'campistas' => ['participant_fee', 'participant_registration_start_date', 'participant_registration_end_date', 'raffle_camper_date', 'camper_form_deadline'],
            'servos'    => ['servant_fee', 'raffle_servant_subscription_start_date', 'raffle_servant_subscription_end_date', 'raffle_servant_date', 'servant_form_deadline'],
        ];
        foreach ($trilhas as $quem => [$colunaValor, $colAbre, $colFecha, $colSorteio, $colPrazo]) {
            $abre = $dia($quem === 'campistas' ? $daAtividade($colAbre) : $doAcampamento($colAbre));
            $fecha = $dia($quem === 'campistas' ? $daAtividade($colFecha) : $doAcampamento($colFecha));
            $sorteio = $dia($doAcampamento($colSorteio));
            $prazo = $dia($doAcampamento($colPrazo));
            $taxa = $daAtividade($colunaValor);

            if ($abre === null) $erro($colAbre, "Informe quando as inscrições de {$quem} abrem.");
            if ($fecha === null) {
                $erro($colFecha, "Informe quando as inscrições de {$quem} fecham.");
            } elseif ($abre !== null && $fecha < $abre) {
                $erro($colFecha, 'O fechamento não pode ser antes da abertura.');
            }

            if ($sorteio === null) {
                $erro($colSorteio, 'Informe a data prevista do sorteio.');
            } elseif ($fecha !== null && $sorteio <= $fecha) {
                $erro($colSorteio, 'O sorteio precisa ser depois do fechamento das inscrições: a partir de ' . $br(date('Y-m-d', strtotime($fecha . ' +1 day'))) . '.');
            } elseif ($inicio !== null && $sorteio >= $inicio) {
                $erro($colSorteio, "O sorteio precisa ser antes do primeiro dia do acampamento ({$br($inicio)}).");
            }

            if ($prazo === null) {
                $erro($colPrazo, (float)$taxa > 0 ? 'Informe o prazo do formulário e do pagamento.' : 'Informe o prazo do formulário.');
            } elseif ($sorteio !== null && $prazo < $sorteio) {
                $erro($colPrazo, "O prazo não pode ser antes do sorteio previsto ({$br($sorteio)}).");
            } elseif ($inicio !== null && $prazo > $inicio) {
                $erro($colPrazo, "O prazo precisa ser até o primeiro dia do acampamento ({$br($inicio)}).");
            }

            if ($taxa !== null && $taxa !== '' && (!is_numeric($taxa) || (float)$taxa < 0)) {
                $erro($colunaValor, 'Informe o valor. Se não houver cobrança, escolha “Gratuito”.');
            }
        }

        // Vagas do sorteio: no Casais só casais; nas outras, homens e mulheres.
        // Indicações não contam: são ilimitadas, fora do sorteio.
        $vagas = [];
        foreach (['raffle_man_vacancies', 'raffle_woman_vacancies', 'raffle_couple_vacancies'] as $campo) {
            $v = $doAcampamento($campo);
            if ($v === null || $v === '') $v = 0;
            if (!is_numeric($v) || (int)$v != $v || (int)$v < 0) {
                $erro($campo, 'Use um número inteiro, 0 ou mais.');
                $v = 0;
            }
            $vagas[$campo] = (int)$v;
        }
        if ($categoria === 'Casais') {
            if ($vagas['raffle_couple_vacancies'] < 1 && !isset($erros['raffle_couple_vacancies'])) {
                $erro('raffle_couple_vacancies', 'Informe quantos casais serão sorteados.');
            }
        } elseif ($categoria !== null && $vagas['raffle_man_vacancies'] + $vagas['raffle_woman_vacancies'] < 1
            && !isset($erros['raffle_man_vacancies']) && !isset($erros['raffle_woman_vacancies'])) {
            $erro('raffle_man_vacancies', 'Informe pelo menos uma vaga.');
        }

        return $erros;
    }

    /**
     * Deixa os dados do acampamento coerentes com as regras da tela:
     * - um prazo só por trilha: o prazo do pagamento é o mesmo do formulário;
     * - vagas: no Casais só casais, nas outras só homens e mulheres; as
     *   "planejadas" (antigas) acompanham as do sorteio e o total da atividade é
     *   a soma, com o casal contando 2 pessoas;
     * - sem link de pagamento (a pessoa paga direto com a organização).
     *
     * @return array{0: array, 1: array} [activity, camping]
     */
    private function normalizarAcampamento(array $ad, array $cd, ?object $atividade, ?object $camping): array
    {
        $atual = fn(?object $e, string $campo) => $e?->get($campo);
        foreach (['camper_form_deadline' => 'participant_payment_date', 'servant_form_deadline' => 'servant_payment_date'] as $prazo => $pagamento) {
            if (array_key_exists($prazo, $cd)) $ad[$pagamento] = $cd[$prazo];
        }
        $ad['participant_payment_link'] = '';
        $ad['servant_payment_link'] = '';

        $categoria = $this->categoriaDeAcampamento($ad['category_id'] ?? $atual($atividade, 'category_id'));
        $vaga = fn(string $campo) => max(0, (int)($cd[$campo] ?? $atual($camping, $campo) ?? 0));
        $homens = $categoria === 'Casais' ? 0 : $vaga('raffle_man_vacancies');
        $mulheres = $categoria === 'Casais' ? 0 : $vaga('raffle_woman_vacancies');
        $casais = $categoria === 'Casais' ? $vaga('raffle_couple_vacancies') : 0;
        $cd['raffle_man_vacancies'] = $cd['planned_man_vacancies'] = $homens;
        $cd['raffle_woman_vacancies'] = $cd['planned_woman_vacancies'] = $mulheres;
        $cd['raffle_couple_vacancies'] = $cd['planned_couple_vacancies'] = $casais;
        $ad['total_vacancies'] = $homens + $mulheres + 2 * $casais;

        return [$ad, $cd];
    }

    /** Nome da categoria de acampamento com este id, ou null se não for uma. */
    private function categoriaDeAcampamento(mixed $id): ?string
    {
        if (!is_numeric($id) || (int)$id <= 0) return null;
        $categoria = $this->fetchTable('Categories')->find()
            ->where(['id' => (int)$id, 'type' => 'Acampamento', 'deleted IS' => null])
            ->first();

        return $categoria?->name;
    }

    /** Inscrições ativas de um tipo (Campista ou Servo) num acampamento. */
    private function contarInscricoesDoTipo(object $activity, string $tipo): int
    {
        return $this->fetchTable('PreRegistrations')->find()->where([
            'activity_id' => $activity->id,
            'subscription_type' => $tipo,
            'deleted IS' => null,
            'is_quitter' => false,
            'disqualified_at IS' => null,
        ])->count();
    }

    /** {feito, em}: o sorteio deste tipo já foi feito e quando ("Y-m-d", null se não se sabe). */
    private function situacaoDoSorteio(int $activityId, string $tipo): array
    {
        $em = $this->sorteioService->sorteioFeitoEm($activityId, $tipo);

        return ['feito' => $em !== null, 'em' => $em instanceof \DateTimeInterface ? $em->format('Y-m-d') : null];
    }

    /**
     * GET /admin/acampamentos/apoio[?atividade={id}] — o que a tela Criar /
     * Editar Acampamento mostra sem poder editar: para cada categoria de
     * acampamento, se os formulários de campista e de servo já existem e os
     * setores do modelo (tela Setores de Servos); na edição, também os setores
     * deste acampamento.
     */
    public function apoioAcampamento(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('acampamentos');
        if ($identity instanceof Response) return $identity;

        $perguntas = $this->fetchTable('Questions');
        $temFormulario = fn(int $categoriaId, string $tipo) => $perguntas->find()->where([
            'category_id' => $categoriaId,
            'subscription_type' => $tipo,
            'event_form_id IS' => null,
            'deleted IS' => null,
        ])->count() > 0;

        $categorias = [];
        $lista = $this->fetchTable('Categories')->find()
            ->where(['type' => 'Acampamento', 'deleted IS' => null])
            ->orderBy(['id' => 'ASC'])
            ->all();
        foreach ($lista as $c) {
            $setores = [];
            $modelos = $this->CategoriesSectors->find()
                ->contain(['Sectors'])
                ->where(['CategoriesSectors.category_id' => $c->id, 'CategoriesSectors.deleted IS' => null, 'Sectors.deleted IS' => null])
                ->orderBy(['Sectors.name' => 'ASC'])
                ->all();
            foreach ($modelos as $cs) {
                $setores[] = ['nome' => $cs->sector->name, 'vagas' => (int)$cs->vacancies];
            }
            $categorias[] = [
                'id' => $c->id,
                'nome' => $c->name,
                'formularioCampista' => $temFormulario((int)$c->id, 'Campista'),
                'formularioServo' => $temFormulario((int)$c->id, 'Servo'),
                'setores' => $setores,
            ];
        }

        $setoresDaAtividade = null;
        $atividadeId = (int)$this->request->getQuery('atividade');
        if ($atividadeId > 0) {
            $setoresDaAtividade = [];
            $ligados = $this->ActivitiesCategoriesSectors->find()
                ->contain(['CategoriesSectors' => ['Sectors']])
                ->where(['ActivitiesCategoriesSectors.activity_id' => $atividadeId, 'ActivitiesCategoriesSectors.deleted IS' => null])
                ->all();
            foreach ($ligados as $acs) {
                $setoresDaAtividade[] = ['nome' => $acs->categories_sector->sector->name ?? '', 'vagas' => (int)$acs->actual_vacancies];
            }
            usort($setoresDaAtividade, fn($a, $b) => strcmp($a['nome'], $b['nome']));
        }

        return $this->apiResponse(['data' => ['categorias' => $categorias, 'setoresDaAtividade' => $setoresDaAtividade]]);
    }

    private function tamanhoDoArquivo(?string $caminho): ?int
    {
        if (!$caminho || $caminho === '#') return null;
        $arquivo = WWW_ROOT . ltrim($caminho, '/');

        return is_file($arquivo) ? (int)filesize($arquivo) : null;
    }

    private function calcEndDate(?\Cake\I18n\DateTime $startDate, int $durationDays): ?string
    {
        if (!$startDate) return null;
        $days = max(0, $durationDays - 1);
        return $startDate->modify("+{$days} days")->format('Y-m-d\TH:i');
    }

    private function calcStatus(object $activity): string
    {
        $now       = new \DateTime();
        $inscStart = $activity->participant_registration_start_date;
        $inscEnd   = $activity->participant_registration_end_date;

        // Evento: sem data de início, as inscrições já estão abertas (o campo só
        // existe para evento pago, "Início das vendas"); sem fim, vão até o
        // início do evento. Antes, evento gratuito ficava "Em Breve" para sempre.
        if ($activity->camping_id === null) {
            if ($inscStart && $now < $inscStart) return 'Em Breve';
            $fim = $inscEnd ?? $activity->start_date;

            return $fim && $now <= $fim ? 'Inscrições Abertas' : 'Encerrado';
        }

        if (!$inscStart) return 'Em Breve';
        if ($now < $inscStart) return 'Em Breve';
        if ($now <= $inscEnd) return 'Inscrições Abertas';
        return 'Encerrado';
    }
}
