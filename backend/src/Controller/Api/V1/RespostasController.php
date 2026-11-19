<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use App\Model\Table\FormCorrectionsTable;
use App\Model\Table\UserDocumentsTable;
use App\Service\FormulariosService;
use App\Service\PrazoFormularioService;
use Cake\Datasource\EntityInterface;
use Cake\Http\Response;

/**
 * Aba Respostas da tela Formulários. As respostas têm dados de saúde
 * (alergias, remédios, restrições) e os arquivos enviados (documento…), então
 * tudo aqui exige a permissão "respostas_formularios" no servidor — esconder
 * na tela não basta. É também daqui que a organização pede correção de um
 * formulário e cancela uma inscrição.
 */
class RespostasController extends ApiController
{
    /**
     * GET /admin/formularios/respostas?category_id=&subscription_type=&atividade_id=
     *
     * As respostas de um formulário numa edição de acampamento da categoria.
     * Sem atividade_id, escolhe a edição com o prazo do formulário aberto (a
     * que vence primeiro) ou, se nenhuma estiver aberta, a mais recente.
     *
     * Entram as mesmas pessoas que a regra do prazo cobra: sorteados, indicados
     * e suplentes que não desistiram nem perderam a vaga — mais quem a
     * organização cancelou ou recusou (`encerrada`), para a ficha mostrar o
     * motivo. `enviou` separa quem já respondeu (Resumo, Por pergunta, Por
     * pessoa, Tabela) de quem falta (Pendentes). Respostas de escolha vêm como
     * o id da opção (múltipla escolha: JSON de ids), do jeito que estão
     * gravadas; de arquivo, o id do arquivo (os dados dele vêm em `arquivos`).
     */
    public function index(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('respostas_formularios');
        if ($identity instanceof Response) return $identity;

        // Recusa quem passou do prazo da correção antes de montar Pendentes.
        (new PrazoFormularioService())->executarPrazosVencidos();

        $pedida = intval($this->request->getQuery('atividade_id') ?? 0);

        // Formulário de evento: as "edições" são os eventos que usam o formulário.
        $eventFormId = intval($this->request->getQuery('event_form_id') ?? 0);
        if ($eventFormId) {
            if (!$this->fetchTable('EventForms')->find()->where(['id' => $eventFormId, 'deleted IS' => null])->count()) {
                return $this->apiError('Formulário de evento não encontrado', 404);
            }
            $edicoes = $this->eventosDoFormulario($eventFormId);
            $escolhida = $this->edicaoEscolhida($edicoes, $pedida);

            return $this->apiResponse(['data' => [
                'edicoes'      => $edicoes,
                'atividade_id' => $escolhida['id'] ?? null,
                'prazo'        => $escolhida['prazo'] ?? null,
                'perguntas'    => $this->perguntas(FormulariosService::deEvento($eventFormId)),
                'pessoas'      => $escolhida ? $this->pessoas($escolhida['id'], 'Participante', true) : [],
            ]]);
        }

        $categoryId = intval($this->request->getQuery('category_id') ?? 0);
        $tipo       = $this->request->getQuery('subscription_type');
        if (!$categoryId || !in_array($tipo, ['Campista', 'Servo'], true)) {
            return $this->apiError('Informe o formulário: category_id e subscription_type (Campista|Servo), ou event_form_id', 422);
        }

        $edicoes = $this->edicoes($categoryId, $tipo);
        $escolhida = $this->edicaoEscolhida($edicoes, $pedida);

        return $this->apiResponse(['data' => [
            'edicoes'      => $edicoes,
            'atividade_id' => $escolhida['id'] ?? null,
            'prazo'        => $escolhida['prazo'] ?? null,
            'perguntas'    => $this->perguntas(['category_id' => $categoryId, 'subscription_type' => $tipo, 'event_form_id' => null]),
            'pessoas'      => $escolhida ? $this->pessoas($escolhida['id'], $tipo) : [],
        ]]);
    }

    /** Eventos que usam o formulário; o prazo é o fim das inscrições (sem ele, o início do evento). */
    private function eventosDoFormulario(int $eventFormId): array
    {
        $eventos = $this->fetchTable('Activities')->find()
            ->where(['event_form_id' => $eventFormId, 'camping_id IS' => null, 'deleted IS' => null])
            ->orderBy(['start_date' => 'DESC', 'id' => 'DESC'])
            ->all();

        $agora = time();
        $lista = [];
        foreach ($eventos as $e) {
            $prazo = $e->participant_registration_end_date ?? $e->start_date;
            $lista[] = [
                'id'     => (int)$e->id,
                'nome'   => $e->name,
                'inicio' => $e->start_date->format('Y-m-d'),
                'fim'    => $e->start_date->modify('+' . max(0, (int)$e->duration_days - 1) . ' days')->format('Y-m-d'),
                'prazo'  => $prazo?->format('Y-m-d\TH:i:s'),
                'aberta' => $prazo !== null && $prazo->getTimestamp() > $agora,
            ];
        }

        return $lista;
    }

    /** Acampamentos da categoria, do mais recente para o mais antigo, com o prazo do formulário deste tipo. */
    private function edicoes(int $categoryId, string $tipo): array
    {
        $campoPrazo = $tipo === 'Servo' ? 'servant_form_deadline' : 'camper_form_deadline';
        $linhas = $this->fetchTable('Activities')->find()
            ->select([
                'id'       => 'Activities.id',
                'nome'     => 'Activities.name',
                'inicio'   => 'Activities.start_date',
                'duracao'  => 'Activities.duration_days',
                'prazo'    => "Campings.{$campoPrazo}",
            ])
            ->innerJoin(['Campings' => 'campings'], ['Campings.id = Activities.camping_id'])
            ->where(['Activities.category_id' => $categoryId, 'Activities.deleted IS' => null])
            ->orderBy(['Activities.start_date' => 'DESC', 'Activities.id' => 'DESC'])
            ->disableHydration()
            ->all();

        $agora = time();
        $edicoes = [];
        foreach ($linhas as $l) {
            $inicio = $this->instante($l['inicio']);
            $dias = max(1, (int)$l['duracao']);
            $prazo = $this->instante($l['prazo']);
            $edicoes[] = [
                'id'     => (int)$l['id'],
                'nome'   => $l['nome'],
                'inicio' => date('Y-m-d', $inicio),
                'fim'    => date('Y-m-d', strtotime('+' . ($dias - 1) . ' days', $inicio)),
                'prazo'  => $prazo ? date('Y-m-d\TH:i:s', $prazo) : null,
                'aberta' => $prazo !== null && $prazo > $agora,
            ];
        }

        return $edicoes;
    }

    /** Data do banco (objeto de data do Cake ou texto) como timestamp; null se vazia. */
    private function instante(mixed $valor): ?int
    {
        if ($valor instanceof \DateTimeInterface) return $valor->getTimestamp();
        if ($valor === null || $valor === '') return null;
        $ts = strtotime((string)$valor);

        return $ts === false ? null : $ts;
    }

    private function edicaoEscolhida(array $edicoes, int $pedida): ?array
    {
        foreach ($edicoes as $e) {
            if ($e['id'] === $pedida) return $e;
        }
        $abertas = array_filter($edicoes, fn($e) => $e['aberta']);
        usort($abertas, fn($a, $b) => $a['prazo'] <=> $b['prazo']);

        return $abertas[0] ?? $edicoes[0] ?? null;
    }

    /** Perguntas do formulário, na ordem de exibição, com as opções (só as que valem). */
    private function perguntas(array $formulario): array
    {
        $perguntas = $this->fetchTable('Questions')->find()
            ->contain(['Options', 'Sections'])
            ->where(FormulariosService::condicoes($formulario, 'Questions.') + ['Questions.deleted IS' => null])
            ->orderBy(['Sections.order' => 'ASC', 'Sections.id' => 'ASC', 'Questions.order' => 'ASC'])
            ->all();

        $lista = [];
        foreach ($perguntas as $q) {
            $lista[] = [
                'id'                   => (int)$q->id,
                'text'                 => $q->text,
                'type'                 => $q->type,
                'is_required'          => (bool)$q->is_required,
                'depends_on_option_id' => $q->depends_on_option_id === null ? null : (int)$q->depends_on_option_id,
                'section'              => ['id' => (int)$q->section->id, 'titulo' => $q->section->title],
                'options'              => array_map(fn($o) => ['id' => (int)$o->id, 'text' => $o->text], $q->options ?? []),
            ];
        }

        return $lista;
    }

    /**
     * No acampamento: quem precisa enviar (sorteados, indicados, suplentes). No
     * evento (sem sorteio): todos os inscritos — quem não enviou ainda está com
     * a inscrição em andamento.
     */
    private function pessoas(int $activityId, string $tipo, bool $evento = false): array
    {
        $onde = [
            'PreRegistrations.activity_id'        => $activityId,
            'PreRegistrations.subscription_type'  => $tipo,
            'PreRegistrations.deleted IS'         => null,
            'PreRegistrations.is_quitter'         => false,
            'Users.deleted IS'                    => null,
            'AND' => [['OR' => [
                'PreRegistrations.disqualified_at IS'         => null,
                'PreRegistrations.disqualification_reason IN' => PrazoFormularioService::MOTIVOS_DEFINITIVOS,
            ]]],
        ];
        if (!$evento) {
            $onde['AND'][] = ['OR' => [
                'PreRegistrations.selection_method_id IS NOT' => null,
                'PreRegistrations.substitute_position IS NOT' => null,
            ]];
        }
        $inscricoes = $this->fetchTable('PreRegistrations')->find()
            ->contain(['Users', 'SelectionMethods'])
            ->where($onde)
            ->orderBy(['Users.name' => 'ASC'])
            ->all()
            ->toList();
        if (!$inscricoes) return [];

        $ids = array_map(fn($i) => (int)$i->id, $inscricoes);
        $respostas = [];
        $atualizado = [];
        $answers = $this->fetchTable('Answers')->find()
            ->select(['pre_registration_id', 'question_id', 'answer', 'modified'])
            ->where(['pre_registration_id IN' => $ids, 'deleted IS' => null])
            ->disableHydration()
            ->all();
        foreach ($answers as $a) {
            $p = (int)$a['pre_registration_id'];
            $respostas[$p][(int)$a['question_id']] = $a['answer'];
            $quando = $this->instante($a['modified']);
            if ($quando !== null && (!isset($atualizado[$p]) || $quando > $atualizado[$p])) $atualizado[$p] = $quando;
        }

        $arquivos = $this->fetchTable('UserDocuments')->dasInscricoes($ids);
        $correcoes = $this->fetchTable('FormCorrections')->abertas($ids);
        $quemCancelou = $this->nomes(array_filter(array_map(fn($i) => $i->disqualified_by, $inscricoes)));

        // Casal: o cônjuge também sai se a inscrição for cancelada (a vaga é do casal).
        $nomePorUsuario = [];
        foreach ($inscricoes as $i) $nomePorUsuario[(int)$i->user_id] = $i->user->name;

        $hoje = new \DateTimeImmutable('today');
        $pessoas = [];
        foreach ($inscricoes as $i) {
            $u = $i->user;
            $metodo = $i->selection_method->method ?? '';
            $conjuge = null;
            if ($i->spouse_id && isset($nomePorUsuario[(int)$i->spouse_id])) {
                $conjuge = $nomePorUsuario[(int)$i->spouse_id];
            } else {
                foreach ($inscricoes as $outra) {
                    if ((int)$outra->spouse_id === (int)$i->user_id) $conjuge = $outra->user->name;
                }
            }
            $pessoa = [
                'inscricao_id'  => (int)$i->id,
                'nome'          => $u->name,
                'idade'         => $u->birthday
                    ? (new \DateTimeImmutable($u->birthday->format('Y-m-d')))->diff($hoje)->y
                    : null,
                'telefone'      => $u->phone,
                'email'         => $u->email,
                'situacao'      => $evento
                    ? ($i->form_submitted ? 'Inscrito' : 'Em andamento')
                    : ($i->substitute_position !== null ? 'Suplente' : ($metodo === 'Sorteio' ? 'Sorteado' : 'Indicado')),
                'enviou'        => (bool)$i->form_submitted,
                // Datas guardadas desde 2026-11-15; inscrições anteriores vêm null.
                'confirmado_em' => $i->selected_at?->format('Y-m-d\TH:i:s'),
                'enviado_em'    => $i->form_submitted_at?->format('Y-m-d\TH:i:s'),
                'atualizado_em' => isset($atualizado[(int)$i->id]) ? date('Y-m-d\TH:i:s', $atualizado[(int)$i->id]) : null,
                'respostas'     => (object)($respostas[(int)$i->id] ?? []),
                // id da pergunta => arquivo enviado (o mesmo id que está na resposta).
                'arquivos'      => (object)array_map(
                    fn($d) => UserDocumentsTable::resumo($d),
                    $arquivos[(int)$i->id] ?? []
                ),
                'correcao'      => FormCorrectionsTable::resumo($correcoes[(int)$i->id] ?? null),
                // Cancelada pela organização ou recusada (correção não feita).
                'encerrada'     => $i->disqualified_at === null ? null : [
                    'situacao' => $i->disqualification_reason === PrazoFormularioService::MOTIVO_CANCELADA
                        ? 'Cancelada pela organização'
                        : 'Recusada',
                    'em'       => $i->disqualified_at->format('Y-m-d\TH:i'),
                    'por'      => $quemCancelou[(int)$i->disqualified_by] ?? null,
                    'motivo'   => (string)$i->disqualification_note,
                ],
                'conjuge'       => $conjuge,
            ];
            $pessoas[] = $pessoa;
        }

        return $pessoas;
    }

    /** @return array<int, string> id => nome */
    private function nomes(array $ids): array
    {
        if (!$ids) return [];

        return $this->fetchTable('Users')->find('list', keyField: 'id', valueField: 'name')
            ->where(['id IN' => array_values(array_unique(array_map('intval', $ids)))])
            ->toArray();
    }

    /**
     * POST /admin/inscricoes/{id}/correcao  { motivo, perguntas: [ids], prazo: "AAAA-MM-DD" }
     *
     * Pede à pessoa que corrija o formulário já enviado. A inscrição continua
     * com a vaga e o formulário volta a ficar aberto. O prazo é só desta
     * inscrição: se não corrigir até ele, a inscrição fica "Recusada" e a vaga
     * vai para "Chamar próximo" (evento: volta para as inscrições).
     */
    public function pedirCorrecao(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('respostas_formularios');
        if ($identity instanceof Response) return $identity;

        $preReg = $this->inscricaoAtiva($id);
        if ($preReg instanceof Response) return $preReg;
        if (!$preReg->form_submitted) {
            return $this->apiError('Esta pessoa ainda não enviou o formulário, então não há o que corrigir.', 422);
        }
        $correcoes = $this->fetchTable('FormCorrections');
        if ($correcoes->aberta((int)$preReg->id)) {
            return $this->apiError('Já existe um pedido de correção aberto para esta pessoa.', 422);
        }

        $motivo = trim((string)$this->request->getData('motivo'));
        if ($motivo === '') return $this->apiError('Escreva o motivo: a pessoa vai ler no site.', 422);
        if (mb_strlen($motivo) > 1000) return $this->apiError('O motivo passou de 1000 caracteres.', 422);

        $prazo = \DateTime::createFromFormat('!Y-m-d', (string)$this->request->getData('prazo'));
        if (!$prazo || $prazo->format('Y-m-d') !== (string)$this->request->getData('prazo')) {
            return $this->apiError('Informe até quando a pessoa pode corrigir (dia/mês/ano).', 422);
        }
        if ($prazo->format('Y-m-d') < date('Y-m-d')) {
            return $this->apiError('A data para corrigir não pode estar no passado.', 422);
        }
        $inicio = $preReg->activity->start_date;
        if ($inicio !== null && $prazo->format('Y-m-d') > $inicio->format('Y-m-d')) {
            return $this->apiError('Escolha uma data até o início da atividade (' . $inicio->format('d/m/Y') . ').', 422);
        }
        $prazo->setTime(23, 59, 59);

        $perguntas = array_values(array_unique(array_filter(
            array_map('intval', (array)($this->request->getData('perguntas') ?? []))
        )));

        $pedido = $correcoes->saveOrFail($correcoes->newEntity([
            'pre_registration_id' => $preReg->id,
            'requested_by'        => $identity->id,
            'reason'              => $motivo,
            'question_ids'        => json_encode($perguntas),
            'deadline'            => $prazo,
        ], ['accessibleFields' => ['*' => true]]));
        $this->fetchTable('UserChangeLogs')->registrar(
            (int)$preReg->user_id,
            (int)$identity->id,
            "Correção pedida no formulário de {$preReg->activity->name}: “{$motivo}”"
        );

        return $this->apiResponse(['data' => FormCorrectionsTable::resumo($correcoes->aberta((int)$pedido->pre_registration_id))], 201);
    }

    /** DELETE /admin/inscricoes/{id}/correcao — desfaz o pedido; a situação volta a "Formulário Enviado". */
    public function desfazerCorrecao(int $id): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('respostas_formularios');
        if ($identity instanceof Response) return $identity;

        $preReg = $this->inscricaoAtiva($id);
        if ($preReg instanceof Response) return $preReg;
        $correcoes = $this->fetchTable('FormCorrections');
        if (!$correcoes->aberta((int)$preReg->id)) {
            return $this->apiError('Não há pedido de correção aberto para esta pessoa.', 422);
        }

        $correcoes->resolver((int)$preReg->id, FormCorrectionsTable::DESFEITO, (int)$identity->id);
        $this->fetchTable('UserChangeLogs')->registrar(
            (int)$preReg->user_id,
            (int)$identity->id,
            "Pedido de correção desfeito no formulário de {$preReg->activity->name}."
        );

        return $this->apiResponse(['ok' => true]);
    }

    /**
     * POST /admin/inscricoes/{id}/cancelar  { motivo }
     *
     * Cancela a inscrição pela organização (não dá para desfazer). Acampamento:
     * a vaga entra em "Chamar próximo"; casal sai junto. Evento: a vaga volta
     * para as inscrições. A pessoa vê "Cancelada pela organização" e o motivo.
     */
    public function cancelar(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('respostas_formularios');
        if ($identity instanceof Response) return $identity;

        $preReg = $this->inscricaoAtiva($id);
        if ($preReg instanceof Response) return $preReg;
        $evento = $preReg->activity->camping_id === null;
        if (!$evento && $preReg->selection_method_id === null && $preReg->substitute_position === null) {
            return $this->apiError('Só dá para cancelar por aqui quem foi sorteado, indicado ou é suplente.', 422);
        }

        $motivo = trim((string)$this->request->getData('motivo'));
        if ($motivo === '') return $this->apiError('Escreva o motivo: a pessoa vai ler no site.', 422);
        if (mb_strlen($motivo) > 1000) return $this->apiError('O motivo passou de 1000 caracteres.', 422);

        $canceladas = (new PrazoFormularioService())->cancelarPelaOrganizacao($preReg, $motivo, (int)$identity->id);

        return $this->apiResponse(['data' => [
            'canceladas' => array_map(fn($pr) => $pr->user->name ?? '', $canceladas),
            'evento'     => $evento,
        ]]);
    }

    /** Inscrição que ainda vale (não excluída, não desistente, não cancelada nem recusada), com a atividade. */
    private function inscricaoAtiva(int $id): EntityInterface|Response
    {
        $preReg = $this->fetchTable('PreRegistrations')->find()
            ->contain(['Users', 'Activities'])
            ->where([
                'PreRegistrations.id'         => $id,
                'PreRegistrations.deleted IS' => null,
                'Activities.deleted IS'       => null,
                'Users.deleted IS'            => null,
            ])
            ->first();
        if (!$preReg) return $this->apiError('Inscrição não encontrada', 404);
        if ($preReg->is_quitter || $preReg->disqualified_at !== null) {
            return $this->apiError('Esta inscrição já não vale mais (cancelada, recusada ou perdeu o prazo).', 422);
        }

        return $preReg;
    }
}
