<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use App\Service\MercadoPagoService;
use App\Service\PrazoFormularioService;
use Cake\Http\Response;

#[AllowDynamicProperties]
class InscricoesController extends ApiController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->PreRegistrations            = $this->fetchTable('PreRegistrations');
        $this->Activities                  = $this->fetchTable('Activities');
        $this->ActivitiesCategoriesSectors = $this->fetchTable('ActivitiesCategoriesSectors');
        $this->AcsSectorsUsers             = $this->fetchTable('ActivitiesCategoriesSectorsUsers');
        $this->Answers                     = $this->fetchTable('Answers');
        $this->Questions                   = $this->fetchTable('Questions');
        $this->Addresses                   = $this->fetchTable('Addresses');
        $this->Campings                    = $this->fetchTable('Campings');
        $this->prazoFormularioService      = new PrazoFormularioService();
        $this->mercadoPagoService          = new MercadoPagoService();
    }

    /**
     * Status em que a pessoa tem formulário para responder. Suplente entra na lista
     * de propósito: ele também precisa entregar o formulário dentro do prazo, e é
     * isso que o habilita a assumir uma vaga liberada sem prazo extra.
     */
    private const STATUS_COM_FORMULARIO = ['Sorteado', 'Indicado', 'Suplente', 'Formulário Enviado'];

    /** Cache de acampamentos por id, para não reconsultar o mesmo a cada inscrição. */
    private array $campingsCache = [];

    /** Cache de "categoria+tipo tem formulário montado?", usado na listagem. */
    private array $formulariosCache = [];

    // Só residentes de Primavera do Leste/Poxoréu são elegíveis pra serem
    // sorteados OU indicados (mesma regra, checada de dois lugares diferentes).
    private function isEligibleCity(int $userId): bool
    {
        $address = $this->Addresses->find()
            ->where(['user_id' => $userId, 'deleted IS' => null])
            ->first();
        if (!$address) return false;

        $cidade = mb_strtolower(trim((string)$address->city));
        $comAcento = ['á','à','â','ã','é','ê','í','ó','ô','õ','ú','ç'];
        $semAcento = ['a','a','a','a','e','e','i','o','o','o','u','c'];
        $cidade    = str_replace($comAcento, $semAcento, $cidade);

        return in_array($cidade, ['primavera do leste', 'poxoreu'], true);
    }

    // POST /api/v1/activities/{id}/inscricoes
    public function subscribe(int $activityId): Response
    {
        $this->request->allowMethod(['post']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $activity = $this->Activities->find()
            ->contain(['Categories'])
            ->where(['Activities.id' => $activityId, 'Activities.deleted IS' => null])
            ->first();

        if (!$activity) {
            return $this->apiError('Atividade não encontrada', 404);
        }

        $jaInscrito = $this->PreRegistrations->find()
            ->where([
                'user_id'    => $identity->id,
                'activity_id' => $activityId,
                'deleted IS' => null,
            ])
            ->count();

        if ($jaInscrito > 0) {
            return $this->apiError('Você já está inscrito nesta atividade', 422);
        }

        $tipo = ucfirst(strtolower($this->request->getData('tipo') ?? 'campista'));
        if (!in_array($tipo, ['Campista', 'Servo', 'Participante'], true)) {
            return $this->apiError('Tipo inválido. Use Campista, Servo ou Participante', 422);
        }

        // A hierarquia de categorias (idade, estado civil, pré-requisitos e a regra de
        // "campista uma vez, depois só servo") só existe para Acampamentos — Eventos
        // (Evento Geral, Celebração) não têm essa estrutura e ficam sem restrição aqui.
        if ($activity->camping_id !== null) {
            $erro = $this->checkAcampamentoEligibility($identity, $activity, $tipo);
            if ($erro !== null) {
                return $this->apiError($erro, 422);
            }
        }

        // Casado(a)/união estável precisa informar o CPF do(a) cônjuge, e essa pessoa
        // precisa já estar cadastrada (o pareamento do sorteio de Casais depende disso).
        $maritalTitle = $this->fetchTable('MaritalStatuses')->find()
            ->where(['id' => $identity->marital_status_id])
            ->first()?->title;
        $exigeConjuge = in_array($maritalTitle, ['Casado', 'União estável'], true);

        $spouseCpf = $this->request->getData('spouse_cpf');
        if ($exigeConjuge && !$spouseCpf) {
            return $this->apiError("Como seu estado civil é \"{$maritalTitle}\", é necessário informar o CPF do(a) cônjuge para se inscrever.", 422);
        }

        $spouseId = null;
        if ($spouseCpf) {
            $spouseCpfClean = preg_replace('/\D/', '', $spouseCpf);
            $spouse = $this->fetchTable('Users')->find()->where(['cpf' => $spouseCpfClean, 'deleted IS' => null])->first();
            if ($spouse) {
                $spouseId = $spouse->id;
            } elseif ($exigeConjuge) {
                return $this->apiError('CPF do(a) cônjuge não encontrado. Ele(a) precisa estar cadastrado(a) no sistema antes da inscrição.', 422);
            }
        }

        // Endereço define elegibilidade de sorteio (só residentes de Primavera do
        // Leste/Poxoréu são sorteados) — se ainda não tem endereço salvo, exige aqui.
        $addressesTable  = $this->fetchTable('Addresses');
        $existingAddress = $addressesTable->find()
            ->where(['user_id' => $identity->id, 'deleted IS' => null])
            ->first();
        if (!$existingAddress) {
            $addrData = [
                'street'       => trim((string)$this->request->getData('street')),
                'number'       => trim((string)$this->request->getData('number')),
                'neighborhood' => trim((string)$this->request->getData('neighborhood')),
                'city'         => trim((string)$this->request->getData('city')),
                'cep'          => trim((string)$this->request->getData('cep')),
            ];
            if (in_array('', $addrData, true)) {
                return $this->apiError('Endereço completo (rua, número, bairro, cidade e CEP) é obrigatório para se inscrever.', 422);
            }
            $address = $addressesTable->newEntity(array_merge($addrData, ['user_id' => $identity->id]));
            if (!$addressesTable->save($address)) {
                return $this->apiResponse(['error' => 'Erro ao salvar endereço', 'errors' => $address->getErrors()], 422);
            }
        }

        $preReg = $this->PreRegistrations->newEntity([
            'subscription_type' => $tipo,
            'is_fee_paid'       => false,
            'is_quitter'        => false,
            'user_id'           => $identity->id,
            'activity_id'       => $activityId,
            'spouse_id'         => $spouseId,
        ]);

        if (!$this->PreRegistrations->save($preReg)) {
            return $this->apiResponse(['error' => 'Erro ao salvar inscrição', 'errors' => $preReg->getErrors()], 422);
        }

        $preRegFull = $this->PreRegistrations->find()
            ->contain(['Activities.Categories', 'SelectionMethods'])
            ->where(['PreRegistrations.id' => $preReg->id])
            ->first();

        return $this->apiResponse(['data' => $this->serializeInscricao($preRegFull)], 201);
    }

    public function adminList(int $activityId): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $subs = $this->PreRegistrations->find()
            ->contain(['Users' => ['MaritalStatuses'], 'SelectionMethods'])
            ->where([
                'PreRegistrations.activity_id' => $activityId,
                'PreRegistrations.deleted IS'  => null,
            ])
            ->all()
            ->toArray();

        $spouseIds = array_filter(array_column($subs, 'spouse_id'));
        $spousesMap = [];
        if ($spouseIds) {
            foreach ($this->fetchTable('Users')->find()->where(['id IN' => array_values($spouseIds)])->all() as $u) {
                $spousesMap[$u->id] = $u->cpf;
            }
        }

        $result = [];
        foreach ($subs as $sub) {
            $user = $sub->user;
            $result[] = [
                'id'                  => $sub->id,
                'subscription_type'   => $sub->subscription_type,
                'status'              => $this->calcularStatus($sub),
                'spouse_cpf'          => $sub->spouse_id ? ($spousesMap[$sub->spouse_id] ?? null) : null,
                'selection_method_id' => $sub->selection_method_id,
                'substitute_position' => $sub->substitute_position,
                'created'             => $sub->created?->format('Y-m-d\TH:i:s'),
                'user' => [
                    'id'             => $user->id,
                    'name'           => $user->name,
                    'cpf'            => $user->cpf,
                    'sex'            => $user->sex,
                    'birthday'       => $user->birthday ? $user->birthday->format('d/m/Y') : null,
                    'marital_status' => $user->marital_status?->title ?? 'Solteiro(a)',
                ]
            ];
        }

        return $this->apiResponse(['subscriptions' => $result]);
    }

    public function indicate(int $activityId): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $preRegId = $this->request->getData('pre_registration_id');
        $methodId = $this->request->getData('selection_method_id');

        if (!$preRegId || !$methodId) return $this->apiError('Faltam dados', 422);

        $preReg = $this->PreRegistrations->find()
            ->where(['id' => $preRegId, 'activity_id' => $activityId, 'deleted IS' => null])
            ->first();
        if (!$preReg) return $this->apiError('Inscrição não encontrada', 404);

        if (!$this->isEligibleCity($preReg->user_id)) {
            return $this->apiError('Só é possível indicar residentes de Primavera do Leste ou Poxoréu.', 422);
        }

        $preReg->selection_method_id = $methodId;
        $preReg->substitute_position = null;
        if (!$this->PreRegistrations->save($preReg)) return $this->apiError('Erro ao salvar indicação', 422);
        $this->clearServoRaffleTrackingIfNeeded($preReg);

        // Casais: se essa inscrição tem cônjuge vinculado (nos dois sentidos — quem
        // declarou o CPF do outro no momento da inscrição pode ter sido qualquer um
        // dos dois), indica a inscrição do cônjuge junto, com o mesmo motivo — mesmo
        // que ele esteja só como suplente (um casal não fica com metade confirmada e
        // metade na fila de espera). Só não mexe se o cônjuge já tem vaga garantida
        // por outro caminho (sorteado titular ou já indicado por conta própria).
        $conjugeIndicado = false;
        $conjugePreReg = $this->findConjugePreReg($preReg);

        if ($conjugePreReg && $conjugePreReg->selection_method_id === null) {
            $conjugePreReg->selection_method_id = $methodId;
            $conjugePreReg->substitute_position = null;
            $this->PreRegistrations->save($conjugePreReg);
            $this->clearServoRaffleTrackingIfNeeded($conjugePreReg);
            $conjugeIndicado = true;
        }

        return $this->apiResponse([
            'message'          => 'Indicação salva com sucesso',
            'conjuge_indicado' => $conjugeIndicado,
        ]);
    }

    // DELETE /api/v1/inscricoes/{id}/indicacao  (admin — desfaz uma indicação por engano)
    public function removeIndication(int $id): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $preReg = $this->PreRegistrations->find()
            ->contain(['SelectionMethods'])
            ->where(['PreRegistrations.id' => $id, 'PreRegistrations.deleted IS' => null])
            ->first();
        if (!$preReg) return $this->apiError('Inscrição não encontrada', 404);

        $metodo = $preReg->selection_method?->method;
        if ($preReg->selection_method_id === null || $metodo === 'Sorteio') {
            return $this->apiError('Esta inscrição não está indicada — só é possível remover indicações (não resultados de sorteio).', 422);
        }

        $preReg->selection_method_id = null;
        $preReg->substitute_position = null;
        $this->PreRegistrations->save($preReg);
        $this->clearServoRaffleTrackingIfNeeded($preReg);

        // Desfaz também a indicação do cônjuge, já que os dois foram indicados juntos.
        $conjugeRemovido = false;
        $conjugePreReg = $this->findConjugePreReg($preReg);
        if ($conjugePreReg && $conjugePreReg->selection_method_id !== null) {
            $conjugePreReg->selection_method_id = null;
            $conjugePreReg->substitute_position = null;
            $this->PreRegistrations->save($conjugePreReg);
            $this->clearServoRaffleTrackingIfNeeded($conjugePreReg);
            $conjugeRemovido = true;
        }

        return $this->apiResponse([
            'message'          => 'Indicação removida com sucesso',
            'conjuge_removido' => $conjugeRemovido,
        ]);
    }

    // Encontra a pré-inscrição do cônjuge na mesma atividade, em qualquer direção
    // do vínculo (quem declarou o CPF de quem no momento da inscrição varia).
    private function findConjugePreReg(object $preReg): ?object
    {
        $orConditions = [['spouse_id' => $preReg->user_id]];
        if ($preReg->spouse_id) {
            $orConditions[] = ['user_id' => $preReg->spouse_id];
        }

        return $this->PreRegistrations->find()
            ->where([
                'activity_id' => $preReg->activity_id,
                'id !='       => $preReg->id,
                'deleted IS'  => null,
                'OR'          => $orConditions,
            ])
            ->first();
    }

    // Ao indicar (ou remover a indicação de) um Servo, limpa qualquer vestígio de
    // sorteio de setor anterior para essa pessoa, para não sobrar posição/suplência
    // desatualizada na tela de resultado do sorteio.
    private function clearServoRaffleTrackingIfNeeded(object $preReg): void
    {
        if ($preReg->subscription_type !== 'Servo') return;

        $acsIds = $this->ActivitiesCategoriesSectors->find()
            ->where(['activity_id' => $preReg->activity_id, 'deleted IS' => null])
            ->all()
            ->map(fn($s) => $s->id)
            ->toArray();

        if (empty($acsIds)) return;

        $this->AcsSectorsUsers->updateAll(
            ['raffle_position' => null, 'is_substitute' => null],
            [
                'activities_categories_sector_id IN' => $acsIds,
                'user_id'                             => $preReg->user_id,
                'is_coordinator'                      => false,
            ]
        );
    }

    // DELETE /api/v1/inscricoes/{id}
    public function cancel(int $id): Response
    {
        $this->request->allowMethod(['delete']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $preReg = $this->PreRegistrations->find()
            ->where([
                'PreRegistrations.id'      => $id,
                'PreRegistrations.user_id' => $identity->id,
                'PreRegistrations.deleted IS' => null,
            ])
            ->first();

        if (!$preReg) {
            return $this->apiError('Inscrição não encontrada', 404);
        }

        $this->PreRegistrations->updateAll(
            ['deleted' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );

        return $this->apiResponse(['deleted' => true]);
    }

    // GET /api/v1/me/inscricoes
    public function minhas(): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        // Oportunista: o painel do campista é a tela que ele mais abre, então é um
        // bom lugar para aplicar prazos de formulário já vencidos (desclassificar
        // quem não respondeu e chamar suplentes) sem depender de agendador.
        $this->prazoFormularioService->executarPrazosVencidos();

        $preRegs = $this->PreRegistrations->find()
            ->contain(['Activities.Categories', 'SelectionMethods'])
            ->where([
                'PreRegistrations.user_id'   => $identity->id,
                'PreRegistrations.deleted IS' => null,
            ])
            ->orderBy(['PreRegistrations.created' => 'DESC'])
            ->all();

        return $this->apiResponse([
            'data' => array_values(
                array_map([$this, 'serializeInscricao'], $preRegs->toArray())
            ),
        ]);
    }

    // GET /api/v1/inscricoes/{id}/formulario
    public function formulario(int $id): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        // Aplica os prazos já vencidos antes de montar a tela: ninguém deve ver um
        // formulário aberto cujo prazo já passou (nem conseguir enviar depois disso).
        $this->prazoFormularioService->executarPrazosVencidos();

        $preReg = $this->PreRegistrations->find()
            ->contain(['Activities.Categories', 'SelectionMethods'])
            ->where([
                'PreRegistrations.id'         => $id,
                'PreRegistrations.user_id'    => $identity->id,
                'PreRegistrations.deleted IS' => null,
            ])
            ->first();

        if (!$preReg) {
            return $this->apiError('Inscrição não encontrada', 404);
        }

        $activity = $preReg->activity ?? null;
        $camping  = $this->campingDaInscricao($preReg);
        $status   = $this->calcularStatus($preReg);

        $prazo        = $this->prazoFormularioService->prazoDaInscricao($preReg, $camping);
        $prazoVencido = $prazo !== null && $prazo->getTimestamp() < time();

        $categoryId = $activity->category->id ?? null;
        $questions  = $categoryId
            ? $this->perguntasDoFormulario((int)$categoryId, (string)$preReg->subscription_type)
            : [];

        // Três coisas diferentes, que antes estavam misturadas num único
        // "formulario_disponivel": (1) é a vez dessa pessoa responder,
        // (2) existe formulário montado para o acampamento dela, e
        // (3) o prazo ainda está aberto para mexer nas respostas.
        //
        // Suplente responde o formulário junto dos sorteados, no mesmo prazo: é o
        // que permite chamá-lo na hora em que uma vaga é liberada.
        $statusPermite     = in_array($status, self::STATUS_COM_FORMULARIO, true);
        $formularioMontado = !empty($questions);
        $temFormulario     = $statusPermite && $formularioMontado;
        $editavel          = $temFormulario && !$prazoVencido;

        $respostas = [];
        foreach ($this->Answers->find()->where(['pre_registration_id' => $preReg->id])->all() as $ans) {
            $respostas[(int)$ans->question_id] = $ans->answer;
        }

        return $this->apiResponse([
            'data' => [
                'status'                => $status,
                'formulario_disponivel' => $temFormulario,
                'formulario_montado'    => $formularioMontado,
                'editavel'              => $editavel,
                'motivo_indisponivel'   => $this->motivoFormularioIndisponivel(
                    $status,
                    $statusPermite,
                    $formularioMontado,
                    $prazoVencido,
                    $prazo,
                    (bool)$preReg->form_submitted,
                    $preReg->substitute_position !== null
                ),
                'respondido'            => (bool)$preReg->form_submitted,
                'total_perguntas'       => count($questions),
                'prazo'                 => $prazo === null ? null : [
                    'limite'      => $prazo->format('Y-m-d\TH:i'),
                    'vencido'     => $prazoVencido,
                    // Suplente vê o mesmo prazo, com outra explicação na tela.
                    'eh_suplente' => $status === 'Suplente',
                ],
                'atividade' => $activity ? [
                    'id'        => $activity->id,
                    'nome'      => $activity->name,
                    'categoria' => $activity->category->name ?? null,
                    'local'     => $activity->place,
                    'inicio'    => $activity->start_date?->format('Y-m-d\TH:i'),
                ] : null,
                'perfil'    => $this->serializePerfilConfirmacao($identity),
                'sections'  => $temFormulario ? $this->montarSections($questions, $respostas) : [],
                'pagamento' => $activity ? $this->serializePagamento($preReg, $activity) : null,
            ],
        ]);
    }

    /**
     * Mensagem pronta explicando por que a pessoa não pode responder/alterar o
     * formulário agora. Antes a tela dizia sempre "ainda não está disponível", o
     * que era enganoso: para um acampamento sem perguntas cadastradas ela abria
     * um formulário vazio que podia ser "enviado" sem nada dentro.
     */
    private function motivoFormularioIndisponivel(
        string $status,
        bool $statusPermite,
        bool $formularioMontado,
        bool $prazoVencido,
        mixed $prazo,
        bool $jaEnviado,
        bool $eraSuplente
    ): ?string {
        $prazoFmt = $prazo !== null ? $prazo->format('d/m/Y') . ' às ' . $prazo->format('H:i') : null;

        if (!$statusPermite) {
            if ($status === 'Prazo Perdido') {
                // A consequência é diferente para titular e suplente: um perde a
                // vaga, o outro perde o lugar na fila.
                return $eraSuplente
                    ? 'O prazo para responder o formulário terminou'
                        . ($prazoFmt ? " em {$prazoFmt}" : '')
                        . ' e você saiu da fila de suplentes, porque só é chamado quem já entregou o formulário. '
                        . 'Procure a organização se achar que houve algum erro.'
                    : 'O prazo para responder o formulário terminou'
                        . ($prazoFmt ? " em {$prazoFmt}" : '')
                        . ' e sua vaga foi liberada para um suplente. Procure a organização se achar que houve algum erro.';
            }
            if ($status === 'Desistência') {
                return 'Esta inscrição foi cancelada, então não há formulário para responder.';
            }

            return 'O formulário fica disponível depois que você for sorteado, indicado ou entrar na lista de suplentes deste acampamento.';
        }

        if (!$formularioMontado) {
            return 'A organização ainda não montou o formulário deste acampamento. '
                . 'Nada a fazer por aqui agora — você será avisado quando as perguntas estiverem prontas.';
        }

        if ($prazoVencido) {
            return $jaEnviado
                ? "O prazo terminou em {$prazoFmt}. Seu formulário já foi enviado e não pode mais ser alterado."
                : "O prazo para responder terminou em {$prazoFmt}.";
        }

        return null;
    }

    // Perguntas de um formulário (categoria + tipo de inscrição), na ordem de exibição.
    private function perguntasDoFormulario(int $categoryId, string $subscriptionType): array
    {
        return $this->Questions->find()
            ->contain(['Options', 'Sections'])
            ->where([
                'Questions.category_id'       => $categoryId,
                'Questions.subscription_type' => $subscriptionType,
                'Questions.deleted IS'        => null,
            ])
            ->orderBy(['Sections.order' => 'ASC', 'Questions.order' => 'ASC'])
            ->all()
            ->toArray();
    }

    // Agrupa as perguntas nas seções, do jeito que a tela exibe.
    private function montarSections(array $questions, array $respostas): array
    {
        $sectionsMap = [];

        foreach ($questions as $q) {
            $sId = $q->section->id ?? 0;
            if (!isset($sectionsMap[$sId])) {
                $sectionsMap[$sId] = [
                    'id'        => $sId,
                    'title'     => $q->section->title ?? 'Geral',
                    'order'     => $q->section->order ?? 0,
                    'questions' => [],
                ];
            }

            $sectionsMap[$sId]['questions'][] = [
                'id'                => $q->id,
                'text'              => $q->text,
                'type'              => $q->type,
                'isRequired'        => (bool)$q->is_required,
                'order'             => $q->order,
                'dependsOnOptionId' => $q->depends_on_option_id,
                'options'           => array_map(
                    fn($o) => ['id' => $o->id, 'text' => $o->text],
                    $this->opcoesDaPergunta($q)
                ),
                'answer'            => $respostas[(int)$q->id] ?? null,
            ];
        }

        $sections = array_values($sectionsMap);
        usort($sections, fn($a, $b) => $a['order'] <=> $b['order']);

        return $sections;
    }

    // Dados que o usuário já cadastrou, mostrados no topo do formulário só pra
    // confirmação — não são editáveis por aqui (mudança de perfil é outra tela).
    private function serializePerfilConfirmacao(object $identity): array
    {
        $user = $this->fetchTable('Users')->find()
            ->contain(['MaritalStatuses'])
            ->where(['Users.id' => $identity->id])
            ->first();

        $address = $this->Addresses->find()
            ->where(['user_id' => $identity->id, 'deleted IS' => null])
            ->first();

        return [
            'nome'          => $user->name,
            'cpf'           => $user->cpf,
            'telefone'      => $user->phone,
            'email'         => $user->email,
            'data_nascimento' => $user->birthday?->format('Y-m-d'),
            'sexo'          => $user->sex,
            'estado_civil'  => $user->marital_status->title ?? null,
            'endereco'      => $address ? [
                'rua'    => $address->street,
                'numero' => $address->number,
                'bairro' => $address->neighborhood,
                'cidade' => $address->city,
                'cep'    => $address->cep,
            ] : null,
        ];
    }

    /**
     * Bloco de pagamento exibido no fim do formulário.
     *
     * Duas regras do processo ficam aqui:
     * - **Suplente não paga.** Ele responde o formulário, mas a taxa só é cobrada de
     *   quem tem vaga de verdade. Se ele for chamado para uma vaga, o pagamento
     *   aparece liberado nesta mesma tela.
     * - Pagar vem **depois** do formulário enviado, e dentro da data de pagamento
     *   cadastrada na atividade.
     *
     * Enquanto o token do Mercado Pago não estiver no .env, `integracao_ativa` vem
     * false e a tela cai no link de pagamento cadastrado à mão na atividade.
     */
    private function serializePagamento(object $preReg, object $activity): array
    {
        $valor    = $this->mercadoPagoService->valorDaTaxa($preReg, $activity);
        $ultimo   = $this->mercadoPagoService->ultimoPagamento((int)$preReg->id);
        $prazoPag = $this->mercadoPagoService->prazoPagamento($preReg, $activity);

        $ehSuplente    = $preReg->substitute_position !== null;
        $prazoVencido  = $this->prazoPagamentoVencido($prazoPag);

        return [
            'necessario'       => $valor > 0,
            'valor'            => $valor,
            'pago'             => (bool)$preReg->is_fee_paid,
            // Suplente aparece com a taxa informada, mas sem cobrança.
            'eh_suplente'      => $ehSuplente,
            'prazo_vencido'    => $prazoVencido,
            'liberado'         => $valor > 0
                && !$preReg->is_fee_paid
                && (bool)$preReg->form_submitted
                && !$ehSuplente
                && !$prazoVencido,
            'integracao_ativa' => MercadoPagoService::configurado(),
            'status_provedor'  => $ultimo->status ?? null,
            'url_pagamento'    => $preReg->is_fee_paid ? null : ($ultimo->init_point ?? null),
            'link_manual'      => $this->mercadoPagoService->linkManual($preReg, $activity),
            'prazo'            => $this->formatarDataHora($prazoPag),
        ];
    }

    /** A data de pagamento cadastrada na atividade já passou? */
    private function prazoPagamentoVencido(mixed $prazoPagamento): bool
    {
        if ($prazoPagamento === null) return false;
        if (!is_object($prazoPagamento) || !method_exists($prazoPagamento, 'getTimestamp')) return false;

        return $prazoPagamento->getTimestamp() < time();
    }

    // POST /api/v1/inscricoes/{id}/formulario
    public function salvarFormulario(int $id): Response
    {
        $this->request->allowMethod(['post']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $this->prazoFormularioService->executarPrazosVencidos();

        $preReg = $this->PreRegistrations->find()
            ->contain(['Activities.Categories', 'SelectionMethods'])
            ->where([
                'PreRegistrations.id'         => $id,
                'PreRegistrations.user_id'    => $identity->id,
                'PreRegistrations.deleted IS' => null,
            ])
            ->first();

        if (!$preReg) {
            return $this->apiError('Inscrição não encontrada', 404);
        }

        $status = $this->calcularStatus($preReg);
        if (!in_array($status, self::STATUS_COM_FORMULARIO, true)) {
            return $this->apiError(
                $status === 'Prazo Perdido'
                    ? 'O prazo para responder o formulário terminou.'
                    : 'Formulário não disponível para esta inscrição',
                422
            );
        }

        $activity = $preReg->activity ?? null;
        if (!$activity) {
            return $this->apiError('Atividade não encontrada', 404);
        }

        // Prazo fechado: não aceita mais nem primeira resposta nem correção.
        $camping = $this->campingDaInscricao($preReg);
        if ($this->prazoFormularioService->prazoVencido($preReg, $camping)) {
            $prazo = $this->prazoFormularioService->prazoDaInscricao($preReg, $camping);
            $quando = $prazo !== null ? $prazo->format('d/m/Y') . ' às ' . $prazo->format('H:i') : 'já';

            return $this->apiError("O prazo para responder o formulário terminou em {$quando}.", 422);
        }

        $categoryId = $activity->category->id ?? null;
        $questions  = $categoryId
            ? $this->perguntasDoFormulario((int)$categoryId, (string)$preReg->subscription_type)
            : [];

        // Sem perguntas cadastradas não existe "formulário enviado": antes dessa
        // checagem era possível enviar um formulário vazio e a inscrição ficava
        // marcada como respondida sem nenhum dado.
        if (empty($questions)) {
            return $this->apiError(
                'A organização ainda não montou o formulário deste acampamento, então não há nada para enviar.',
                422
            );
        }

        $enviadas = [];
        foreach ((array)($this->request->getData('answers') ?? []) as $item) {
            $qId = (int)($item['question_id'] ?? 0);
            if ($qId > 0) {
                $enviadas[$qId] = trim((string)($item['answer'] ?? ''));
            }
        }

        $visiveis = $this->perguntasVisiveis($questions, $enviadas);

        foreach ($questions as $q) {
            $qId = (int)$q->id;
            // Pergunta condicional que não está valendo (ex.: "Qual remédio?" para
            // quem respondeu que não toma remédio) não é exigida nem validada.
            if (!isset($visiveis[$qId])) continue;

            $valor = $enviadas[$qId] ?? '';
            if ($valor === '') {
                if ($q->is_required) {
                    return $this->apiError("A pergunta \"{$q->text}\" é obrigatória.", 422);
                }
                continue;
            }

            $erro = $this->validarResposta($q, $valor);
            if ($erro !== null) {
                return $this->apiError($erro, 422);
            }
        }

        $salvas = 0;
        $ok = $this->Answers->getConnection()->transactional(function () use ($questions, $visiveis, $enviadas, $preReg, &$salvas) {
            $existentes = $this->Answers->find()
                ->where(['pre_registration_id' => $preReg->id])
                ->all()
                ->indexBy('question_id')
                ->toArray();

            foreach ($questions as $q) {
                $qId   = (int)$q->id;
                $valor = $enviadas[$qId] ?? '';

                if (!isset($visiveis[$qId]) || $valor === '') {
                    // Resposta que deixou de valer não pode ficar guardada: se a
                    // pessoa marcou "tem alergia: amendoim" e depois trocou para
                    // "não tem alergia", a ficha não pode continuar dizendo amendoim.
                    if (isset($existentes[$qId])) {
                        $this->Answers->delete($existentes[$qId]);
                    }
                    continue;
                }

                $ans = $existentes[$qId] ?? $this->Answers->newEntity([
                    'pre_registration_id' => $preReg->id,
                    'question_id'         => $qId,
                ]);
                $ans->answer = $valor;

                if (!$this->Answers->save($ans)) {
                    return false;
                }
                $salvas++;
            }

            $preReg->form_submitted = true;

            return (bool)$this->PreRegistrations->save($preReg);
        });

        if (!$ok) {
            return $this->apiError('Não foi possível salvar o formulário. Tente novamente.', 500);
        }

        return $this->apiResponse([
            'saved'     => $salvas,
            'message'   => 'Formulário salvo com sucesso',
            'pagamento' => $this->serializePagamento($preReg, $activity),
        ]);
    }

    /**
     * Quais perguntas estão valendo de fato, resolvendo as condicionais: uma
     * pergunta com `depends_on_option_id` só vale se a pergunta "mãe" (a dona
     * daquela opção) estiver valendo E tiver sido respondida com aquela opção.
     */
    private function perguntasVisiveis(array $questions, array $respostas): array
    {
        $perguntaDaOpcao = [];
        foreach ($questions as $q) {
            foreach ($this->opcoesDaPergunta($q) as $opt) {
                $perguntaDaOpcao[(int)$opt->id] = (int)$q->id;
            }
        }

        $visiveis = [];
        foreach ($questions as $q) {
            if ($q->depends_on_option_id === null) {
                $visiveis[(int)$q->id] = true;
            }
        }

        // Dependência pode ter mais de um nível, e a pergunta-mãe não é
        // necessariamente a anterior na lista — repete até parar de mudar.
        do {
            $mudou = false;
            foreach ($questions as $q) {
                $qId = (int)$q->id;
                if (isset($visiveis[$qId]) || $q->depends_on_option_id === null) continue;

                $dep = (int)$q->depends_on_option_id;
                $mae = $perguntaDaOpcao[$dep] ?? 0;

                // Opção que não existe mais: não dá para avaliar a condição, então
                // a pergunta é tratada como solta (aparece) em vez de desaparecer.
                if ($mae === 0) {
                    $visiveis[$qId] = true;
                    $mudou = true;
                    continue;
                }

                if (isset($visiveis[$mae]) && $this->respostaContemOpcao($respostas[$mae] ?? '', $dep)) {
                    $visiveis[$qId] = true;
                    $mudou = true;
                }
            }
        } while ($mudou);

        return $visiveis;
    }

    // A resposta guardada é o id da opção; múltipla escolha guarda um JSON de ids.
    private function respostaContemOpcao(?string $resposta, int $optionId): bool
    {
        $resposta = trim((string)$resposta);
        if ($resposta === '') return false;
        if ($resposta === (string)$optionId) return true;

        $decodificada = json_decode($resposta, true);
        if (is_array($decodificada)) {
            foreach ($decodificada as $valor) {
                if ((int)$valor === $optionId) return true;
            }
        }

        return false;
    }

    /**
     * Confere se a resposta combina com o tipo da pergunta. Antes qualquer texto
     * era aceito em qualquer pergunta — dava para gravar "abc" num campo de número
     * ou o id de uma opção de outra pergunta numa escolha única.
     *
     * @return string|null Mensagem de erro, ou null se estiver tudo certo.
     */
    private function validarResposta(object $q, string $valor): ?string
    {
        $idsValidos = [];
        foreach ($this->opcoesDaPergunta($q) as $opt) {
            $idsValidos[(int)$opt->id] = true;
        }

        switch ((string)$q->type) {
            case 'numero':
                if (!is_numeric(str_replace(',', '.', $valor))) {
                    return "A pergunta \"{$q->text}\" espera um número.";
                }
                break;

            case 'data':
                if (!$this->dataValida($valor)) {
                    return "A pergunta \"{$q->text}\" espera uma data válida.";
                }
                break;

            case 'texto_curto':
                if (mb_strlen($valor) > 255) {
                    return "A resposta da pergunta \"{$q->text}\" passou de 255 caracteres.";
                }
                break;

            case 'texto_longo':
                if (mb_strlen($valor) > 2000) {
                    return "A resposta da pergunta \"{$q->text}\" passou de 2000 caracteres.";
                }
                break;

            case 'sim_nao':
            case 'unica_escolha':
            case 'lista_suspensa':
                if (!isset($idsValidos[(int)$valor])) {
                    return "Escolha uma das opções da pergunta \"{$q->text}\".";
                }
                break;

            case 'multipla_escolha':
                $ids = json_decode($valor, true);
                if (!is_array($ids) || empty($ids)) {
                    return "Marque ao menos uma opção da pergunta \"{$q->text}\".";
                }
                foreach ($ids as $id) {
                    if (!isset($idsValidos[(int)$id])) {
                        return "Uma das opções marcadas em \"{$q->text}\" não existe mais. Recarregue a página e tente de novo.";
                    }
                }
                break;
        }

        return null;
    }

    private function dataValida(string $valor): bool
    {
        foreach (['Y-m-d', 'd/m/Y'] as $formato) {
            $data = \DateTimeImmutable::createFromFormat($formato, $valor);
            if ($data !== false && $data->format($formato) === $valor) {
                return true;
            }
        }

        return false;
    }

    // Options vem como array (pergunta sem opção) ou coleção, dependendo do contain.
    private function opcoesDaPergunta(object $q): array
    {
        $opcoes = $q->options ?? [];

        return is_array($opcoes) ? $opcoes : $opcoes->toArray();
    }

    // Acampamento (campings) da inscrição — é lá que ficam as datas de sorteio e
    // os prazos de formulário.
    private function campingDaInscricao(object $preReg): ?object
    {
        $campingId = $preReg->activity->camping_id ?? null;

        if ($campingId === null) {
            $activity = $this->Activities->find()
                ->select(['camping_id'])
                ->where(['id' => $preReg->activity_id])
                ->first();
            $campingId = $activity->camping_id ?? null;
        }

        if ($campingId === null) return null;

        if (!array_key_exists((int)$campingId, $this->campingsCache)) {
            $this->campingsCache[(int)$campingId] = $this->Campings->find()
                ->where(['id' => $campingId])
                ->first();
        }

        return $this->campingsCache[(int)$campingId];
    }

    /**
     * Existe pergunta cadastrada para o formulário desta inscrição (categoria da
     * atividade + tipo de inscrição)? O resultado é guardado por chave para a
     * listagem não repetir a mesma contagem em cada inscrição.
     */
    private function temFormularioMontado(object $preReg): bool
    {
        $categoryId = $preReg->activity->category_id ?? ($preReg->activity->category->id ?? null);
        if ($categoryId === null) return false;

        $chave = $categoryId . '|' . $preReg->subscription_type;

        if (!array_key_exists($chave, $this->formulariosCache)) {
            $this->formulariosCache[$chave] = $this->Questions->find()
                ->where([
                    'category_id'       => $categoryId,
                    'subscription_type' => $preReg->subscription_type,
                    'deleted IS'        => null,
                ])
                ->count() > 0;
        }

        return $this->formulariosCache[$chave];
    }

    private function formatarDataHora(mixed $valor): ?string
    {
        if ($valor === null) return null;
        if (is_object($valor) && method_exists($valor, 'format')) {
            return $valor->format('Y-m-d\TH:i');
        }

        return (string)$valor;
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    // Hierarquia por idade dos acampamentos (do mais novo para o mais velho).
    // Casais e Discipulados ficam fora dessa cadeia — cada um é autocontido:
    // só desbloqueiam servir neles mesmos, não influenciam nem são influenciados
    // pelas categorias da cadeia principal.
    private const CATEGORY_CHAIN = ['Mirim' => 1, 'FAC' => 2, 'Juvenil' => 3, 'Sênior' => 4];

    private const CATEGORY_AGE_RANGES = [
        'Mirim'   => [10, 12],
        'FAC'     => [14, 17],
        'Juvenil' => [18, 24],
        'Sênior'  => [25, 60],
        // Casais: sem restrição de idade. Discipulados: nenhuma faixa definida.
    ];

    /**
     * Regras de elegibilidade específicas de Acampamento: uma pessoa só pode ser
     * campista uma vez por categoria; para servir, precisa já ter sido campista
     * naquela categoria (ou em uma "abaixo" dela na hierarquia); Juvenil exclui
     * casados/união estável; Discipulados exige ter sido campista no Sênior antes.
     */
    private function checkAcampamentoEligibility(object $identity, object $activity, string $tipo): ?string
    {
        $categoryName = $activity->category->name ?? null;
        if ($categoryName === null) {
            return null;
        }

        $priorRegs = $this->PreRegistrations->find()
            ->contain(['Activities' => ['Categories']])
            ->where([
                'PreRegistrations.user_id'              => $identity->id,
                'PreRegistrations.subscription_type IN' => ['Campista', 'Participante'],
                'PreRegistrations.is_quitter'            => false,
                'PreRegistrations.deleted IS'            => null,
            ])
            ->all();

        $priorCategoryNames = [];
        foreach ($priorRegs as $reg) {
            $name = $reg->activity->category->name ?? null;
            if ($name !== null) $priorCategoryNames[$name] = true;
        }
        $priorCategoryNames = array_keys($priorCategoryNames);

        if ($tipo === 'Servo') {
            $unlocked = $this->unlockedServoCategories($priorCategoryNames);
            if (!in_array($categoryName, $unlocked, true)) {
                return empty($priorCategoryNames)
                    ? 'Você ainda não pode se inscrever como servo(a): é preciso ter participado como campista em ao menos uma categoria de acampamento antes.'
                    : "Você ainda não pode servir em {$categoryName}. Só é possível servir nas categorias em que você já foi campista ou nas categorias abaixo delas na hierarquia.";
            }
            return null;
        }

        // tipo === 'Campista'
        if (in_array($categoryName, $priorCategoryNames, true)) {
            return "Você já participou como campista em {$categoryName} anteriormente. Não é possível se inscrever novamente como campista nessa categoria.";
        }

        if (isset(self::CATEGORY_AGE_RANGES[$categoryName])) {
            [$min, $max] = self::CATEGORY_AGE_RANGES[$categoryName];
            $age = $this->calcularIdade($identity->birthday);
            if ($age !== null && ($age < $min || $age > $max)) {
                return "A categoria {$categoryName} é para campistas de {$min} a {$max} anos.";
            }
        }

        if ($categoryName === 'Juvenil') {
            $maritalTitle = $this->fetchTable('MaritalStatuses')->find()
                ->where(['id' => $identity->marital_status_id])
                ->first()?->title;
            if (in_array($maritalTitle, ['Casado', 'União estável'], true)) {
                return 'Campistas casados(as) ou em união estável devem se inscrever na categoria Sênior, não no Juvenil.';
            }
        }

        if ($categoryName === 'Discipulados' && !in_array('Sênior', $priorCategoryNames, true)) {
            return 'Para se inscrever no Discipulados é necessário ter participado de um acampamento Sênior antes.';
        }

        return null;
    }

    private function unlockedServoCategories(array $priorCategoryNames): array
    {
        $unlocked = [];
        $maxRank  = 0;

        foreach ($priorCategoryNames as $name) {
            if (isset(self::CATEGORY_CHAIN[$name])) {
                $maxRank = max($maxRank, self::CATEGORY_CHAIN[$name]);
            } else {
                // Casais, Discipulados (ou qualquer categoria fora da cadeia principal).
                $unlocked[] = $name;
            }
        }

        foreach (self::CATEGORY_CHAIN as $name => $rank) {
            if ($rank <= $maxRank) $unlocked[] = $name;
        }

        return $unlocked;
    }

    private function calcularIdade(mixed $birthday): ?int
    {
        if (!$birthday) return null;
        try {
            $bdate = $birthday instanceof \DateTimeInterface
                ? \DateTime::createFromInterface($birthday)
                : new \DateTime($birthday instanceof \Cake\I18n\Date ? $birthday->format('Y-m-d') : (string)$birthday);
            return (int)$bdate->diff(new \DateTime('today'))->y;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function calcularStatus(object $preReg): string
    {
        if ($preReg->is_quitter) return 'Desistência';
        // Perdeu o prazo do formulário: a vaga já foi repassada a um suplente.
        // Vem antes do sorteio/indicação de propósito — o selection_method_id
        // continua gravado (registra como a pessoa tinha sido escolhida), então
        // sem essa checagem ela continuaria aparecendo como "Sorteado".
        if ($preReg->disqualified_at !== null) return 'Prazo Perdido';
        if ($preReg->substitute_position !== null) return 'Suplente';
        if ($preReg->selection_method_id !== null) {
            if ($preReg->form_submitted) return 'Formulário Enviado';
            $metodo = $preReg->selection_method?->method ?? '';
            return $metodo === 'Sorteio' ? 'Sorteado' : 'Indicado';
        }
        return 'Inscrito';
    }

    private function serializeInscricao(object $preReg): array
    {
        $activity = $preReg->activity ?? null;

        $status = $this->calcularStatus($preReg);
        $prazoFormulario = $this->prazoFormularioService->prazoDaInscricao(
            $preReg,
            $this->campingDaInscricao($preReg)
        );

        return [
            'id'           => $preReg->id,
            'tipo'         => $preReg->subscription_type,
            'status'       => $status,
            'dataInscricao' => $preReg->created?->format('Y-m-d'),
            'isPago'       => $preReg->is_fee_paid,
            // Prazo e pendências do formulário, para o painel avisar antes de vencer.
            'prazoFormulario'    => $prazoFormulario?->format('Y-m-d\TH:i'),
            'formularioEnviado'  => (bool)$preReg->form_submitted,
            'formularioPendente' => in_array($status, ['Sorteado', 'Indicado', 'Suplente'], true)
                && !$preReg->form_submitted,
            // Existe formulário montado para a categoria/tipo desta inscrição? Sem
            // isso a tela "Meus Formulários" listaria inscrição que não tem o que responder.
            'temFormulario'      => $this->temFormularioMontado($preReg),
            'perdeuPrazo'        => $preReg->disqualified_at !== null,
            'activity'     => $activity ? [
                'id'           => $activity->id,
                'nome'         => $activity->name,
                'tipo'         => $activity->camping_id ? 'Acampamento' : 'Evento',
                'imagem'       => $activity->image ? 'http://localhost:8765/' . $activity->image : null,
                'categoria'    => $activity->category?->name ?? '',
                'inicioEvento' => $activity->start_date?->format('Y-m-d\TH:i'),
                'fimEvento'    => $activity->start_date
                    ? $activity->start_date->modify('+' . max(0, $activity->duration_days - 1) . ' days')->format('Y-m-d\TH:i')
                    : null,
            ] : null,
        ];
    }
}
