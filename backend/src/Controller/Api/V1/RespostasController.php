<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use App\Model\Table\UserDocumentsTable;
use Cake\Http\Response;

/**
 * Aba Respostas da tela Formulários. As respostas têm dados de saúde
 * (alergias, remédios, restrições), então tudo aqui exige a permissão
 * "respostas_formularios" no servidor — esconder na tela não basta.
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
     * e suplentes que não desistiram nem perderam a vaga. `enviou` separa quem
     * já respondeu (Resumo, Por pergunta, Por pessoa, Tabela) de quem falta
     * (Pendentes). Respostas de escolha vêm como o id da opção (múltipla
     * escolha: JSON de ids), do jeito que estão gravadas.
     */
    public function index(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('respostas_formularios');
        if ($identity instanceof Response) return $identity;

        $categoryId = intval($this->request->getQuery('category_id') ?? 0);
        $tipo       = $this->request->getQuery('subscription_type');
        if (!$categoryId || !in_array($tipo, ['Campista', 'Servo'], true)) {
            return $this->apiError('Informe category_id e subscription_type (Campista|Servo)', 422);
        }

        $edicoes = $this->edicoes($categoryId, $tipo);
        $escolhida = $this->edicaoEscolhida($edicoes, intval($this->request->getQuery('atividade_id') ?? 0));

        return $this->apiResponse(['data' => [
            'edicoes'      => $edicoes,
            'atividade_id' => $escolhida['id'] ?? null,
            'prazo'        => $escolhida['prazo'] ?? null,
            'perguntas'    => $this->perguntas($categoryId, $tipo),
            'pessoas'      => $escolhida ? $this->pessoas($escolhida['id'], $tipo) : [],
        ]]);
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
    private function perguntas(int $categoryId, string $tipo): array
    {
        $perguntas = $this->fetchTable('Questions')->find()
            ->contain(['Options', 'Sections'])
            ->where([
                'Questions.category_id'       => $categoryId,
                'Questions.subscription_type' => $tipo,
                'Questions.deleted IS'        => null,
            ])
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

    private function pessoas(int $activityId, string $tipo): array
    {
        $inscricoes = $this->fetchTable('PreRegistrations')->find()
            ->contain(['Users', 'SelectionMethods'])
            ->where([
                'PreRegistrations.activity_id'        => $activityId,
                'PreRegistrations.subscription_type'  => $tipo,
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.is_quitter'         => false,
                'PreRegistrations.disqualified_at IS' => null,
                'Users.deleted IS'                    => null,
                'OR' => [
                    'PreRegistrations.selection_method_id IS NOT' => null,
                    'PreRegistrations.substitute_position IS NOT' => null,
                ],
            ])
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

        // Documento só vai para quem tem a permissão "documentos" (dado pessoal sensível).
        $comDocumento = $this->temPermissao($this->request->getAttribute('identity'), 'documentos');
        $documentos = $comDocumento
            ? $this->fetchTable('UserDocuments')->dasPessoas(array_map(fn($i) => (int)$i->user_id, $inscricoes))
            : [];

        $hoje = new \DateTimeImmutable('today');
        $pessoas = [];
        foreach ($inscricoes as $i) {
            $u = $i->user;
            $metodo = $i->selection_method->method ?? '';
            $pessoa = [
                'inscricao_id'  => (int)$i->id,
                'nome'          => $u->name,
                'idade'         => $u->birthday
                    ? (new \DateTimeImmutable($u->birthday->format('Y-m-d')))->diff($hoje)->y
                    : null,
                'telefone'      => $u->phone,
                'email'         => $u->email,
                'situacao'      => $i->substitute_position !== null ? 'Suplente' : ($metodo === 'Sorteio' ? 'Sorteado' : 'Indicado'),
                'enviou'        => (bool)$i->form_submitted,
                // Datas guardadas desde 2026-11-15; inscrições anteriores vêm null.
                'confirmado_em' => $i->selected_at?->format('Y-m-d\TH:i:s'),
                'enviado_em'    => $i->form_submitted_at?->format('Y-m-d\TH:i:s'),
                'atualizado_em' => isset($atualizado[(int)$i->id]) ? date('Y-m-d\TH:i:s', $atualizado[(int)$i->id]) : null,
                'respostas'     => (object)($respostas[(int)$i->id] ?? []),
            ];
            if ($comDocumento) {
                $pessoa['documento'] = UserDocumentsTable::resumo($documentos[(int)$i->user_id] ?? null);
            }
            $pessoas[] = $pessoa;
        }

        return $pessoas;
    }
}
