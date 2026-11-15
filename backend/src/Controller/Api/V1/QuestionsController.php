<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use Cake\Http\Response;

#[AllowDynamicProperties]
class QuestionsController extends ApiController
{
    // Tipos de resposta suportados pelo form builder. Os 4 primeiros usam texto
    // livre; os 4 últimos usam opções (sim_nao gera Sim/Não sozinho, os outros 3
    // pedem uma lista de opções do próprio admin).
    private const TIPOS_TEXTO   = ['texto_curto', 'texto_longo', 'numero', 'data'];
    private const TIPOS_OPCOES  = ['sim_nao', 'unica_escolha', 'multipla_escolha', 'lista_suspensa'];
    private const TIPOS_VALIDOS = [...self::TIPOS_TEXTO, ...self::TIPOS_OPCOES];
    /** Tipos em que a própria organização escreve as opções (mínimo 2). */
    private const TIPOS_ESCOLHA = ['unica_escolha', 'multipla_escolha', 'lista_suspensa'];
    private const OPCOES_SIM_NAO = ['Sim', 'Não'];
    /**
     * Ver perguntas, lista e seções: quem só tem "Respostas dos formulários"
     * também precisa, para abrir o editor em modo leitura. Mudar exige "formularios".
     */
    private const LER = ['formularios', 'respostas_formularios'];

    public function initialize(): void
    {
        parent::initialize();
        $this->Questions = $this->fetchTable('Questions');
        $this->Options   = $this->fetchTable('Options');
        $this->Sections  = $this->fetchTable('Sections');
    }

    // GET /admin/perguntas?category_id=&subscription_type=
    public function index(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao(self::LER);
        if ($identity instanceof Response) return $identity;

        $categoryId       = intval($this->request->getQuery('category_id') ?? 0);
        $subscriptionType = $this->request->getQuery('subscription_type');

        if (!$categoryId || !in_array($subscriptionType, ['Campista', 'Servo'], true)) {
            return $this->apiError('Informe category_id e subscription_type (Campista|Servo)', 422);
        }

        $questions = $this->Questions->find()
            ->contain(['Options', 'Sections'])
            ->where([
                'Questions.category_id'         => $categoryId,
                'Questions.subscription_type'   => $subscriptionType,
                'Questions.deleted IS'           => null,
            ])
            ->orderBy(['Sections.order' => 'ASC', 'Questions.order' => 'ASC'])
            ->all()
            ->toArray();
        $respostas = $this->contarRespostas(array_map(fn($q) => (int)$q->id, $questions));

        return $this->apiResponse([
            'data' => array_map(fn($q) => $this->serializePergunta($q, $respostas[(int)$q->id] ?? 0), $questions),
        ]);
    }

    // POST /admin/perguntas
    public function add(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $data             = $this->request->getData();
        $categoryId       = intval($data['category_id'] ?? 0);
        $subscriptionType = $data['subscription_type'] ?? null;
        $texto            = trim((string)($data['text'] ?? ''));
        $tipo             = $data['type'] ?? null;
        $isRequired       = (bool)($data['is_required'] ?? true);
        $sectionId        = intval($data['section_id'] ?? 0);
        $sectionNome      = trim((string)($data['section_nome'] ?? ''));
        $dependsOnOptionId = !empty($data['depends_on_option_id']) ? intval($data['depends_on_option_id']) : null;
        $opcoes           = $this->lerOpcoes((array)($data['options'] ?? []));

        if (!$categoryId || !in_array($subscriptionType, ['Campista', 'Servo'], true)) {
            return $this->apiError('Informe category_id e subscription_type (Campista|Servo)', 422);
        }
        if ($texto === '' || !in_array($tipo, self::TIPOS_VALIDOS, true)) {
            return $this->apiError('Informe o texto e um tipo válido (' . implode(', ', self::TIPOS_VALIDOS) . ')', 422);
        }
        if (in_array($tipo, self::TIPOS_ESCOLHA, true) && count($opcoes) < 2) {
            return $this->apiError('Perguntas de opção precisam de pelo menos 2 opções', 422);
        }

        if (!$sectionId) {
            if ($sectionNome === '') {
                return $this->apiError('Informe a seção (section_id) ou um nome para criar uma nova', 422);
            }
            $section = $this->Sections->find()->where(['title' => $sectionNome, 'deleted IS' => null])->first();
            if (!$section) {
                $maxOrd  = $this->Sections->find()->select(['order'])->orderBy(['order' => 'DESC'])->first();
                $section = $this->Sections->newEntity(['title' => $sectionNome, 'order' => ($maxOrd->order ?? 0) + 1]);
                $this->Sections->save($section);
            }
            $sectionId = $section->id;
        }

        if ($dependsOnOptionId !== null) {
            $erro = $this->validarCondicao($categoryId, $subscriptionType, $sectionId, $dependsOnOptionId);
            if ($erro !== null) return $this->apiError($erro, 422);
        }

        $maxOrd = $this->Questions->find()
            ->select(['order'])
            ->where(['category_id' => $categoryId, 'subscription_type' => $subscriptionType, 'section_id' => $sectionId, 'deleted IS' => null])
            ->orderBy(['order' => 'DESC'])
            ->first();

        $question = $this->Questions->newEntity([
            'category_id'          => $categoryId,
            'subscription_type'    => $subscriptionType,
            'text'                 => $texto,
            'type'                 => $tipo,
            'is_required'          => $isRequired,
            'section_id'           => $sectionId,
            'order'                => ($maxOrd->order ?? 0) + 1,
            'accept_generic_answer' => false,
            'depends_on_option_id' => $dependsOnOptionId,
        ]);

        if (!$this->Questions->save($question)) {
            return $this->apiResponse(['error' => 'Erro ao salvar a pergunta', 'errors' => $question->getErrors()], 422);
        }

        $this->sincronizarOpcoes(
            (int)$question->id,
            $tipo === 'sim_nao'
                ? $this->lerOpcoes(self::OPCOES_SIM_NAO)
                : (in_array($tipo, self::TIPOS_ESCOLHA, true) ? $opcoes : [])
        );

        $full = $this->Questions->find()->contain(['Options', 'Sections'])->where(['Questions.id' => $question->id])->first();

        return $this->apiResponse(['data' => $this->serializePergunta($full)], 201);
    }

    // PATCH /admin/perguntas/{id}
    public function edit(int $id): Response
    {
        $this->request->allowMethod(['patch', 'put']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $question = $this->Questions->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$question) return $this->apiError('Pergunta não encontrada', 404);

        $data = $this->request->getData();
        $patch = [];

        if (isset($data['text']))        $patch['text']        = trim((string)$data['text']);
        if (isset($data['is_required'])) $patch['is_required'] = (bool)$data['is_required'];
        if (isset($data['order']))       $patch['order']       = intval($data['order']);
        if (isset($data['section_id']))  $patch['section_id']  = intval($data['section_id']);
        if (array_key_exists('depends_on_option_id', $data)) {
            $patch['depends_on_option_id'] = $data['depends_on_option_id'] ? intval($data['depends_on_option_id']) : null;

            // Só confere a condição quando ela muda: perguntas antigas, montadas por
            // script, podem depender de uma pergunta de outra seção e continuam
            // podendo ter o texto corrigido.
            $novaCondicao = $patch['depends_on_option_id'];
            if ($novaCondicao !== null && $novaCondicao !== $question->depends_on_option_id) {
                $erro = $this->validarCondicao(
                    (int)$question->category_id,
                    (string)$question->subscription_type,
                    $patch['section_id'] ?? (int)$question->section_id,
                    $novaCondicao,
                    $id
                );
                if ($erro !== null) return $this->apiError($erro, 422);
            }
        }

        $tipoAntigo = $question->type;
        $tipo = $tipoAntigo;
        if (isset($data['type'])) {
            if (!in_array($data['type'], self::TIPOS_VALIDOS, true)) {
                return $this->apiError('Tipo inválido', 422);
            }
            $tipo = $data['type'];
            $patch['type'] = $tipo;
        }

        // Opções que a pergunta fica tendo depois de salvar (null = não mexe nelas).
        $opcoes = null;
        if ($tipo === 'sim_nao') {
            // Sim e Não são fixos: só são criados quando a pergunta passa a ser Sim/Não.
            if ($tipoAntigo !== 'sim_nao') $opcoes = $this->lerOpcoes(self::OPCOES_SIM_NAO);
        } elseif (in_array($tipo, self::TIPOS_ESCOLHA, true)) {
            if (isset($data['options'])) {
                $opcoes = $this->lerOpcoes((array)$data['options']);
            } elseif (!in_array($tipoAntigo, self::TIPOS_ESCOLHA, true)) {
                $opcoes = [];
            }
            if ($opcoes !== null && count($opcoes) < 2) {
                return $this->apiError('Perguntas de opção precisam de pelo menos 2 opções', 422);
            }
        } else {
            $opcoes = []; // pergunta de texto não tem opções
        }

        $this->Questions->patchEntity($question, $patch);
        if ($question->hasErrors()) {
            return $this->apiResponse(['error' => 'Erro ao atualizar', 'errors' => $question->getErrors()], 422);
        }

        $resultado = $this->Questions->getConnection()->transactional(function () use ($question, $id, $opcoes) {
            if ($opcoes !== null) {
                $erro = $this->sincronizarOpcoes($id, $opcoes);
                if ($erro !== null) return $erro;
            }

            return $this->Questions->save($question) ? null : false;
        });
        if ($resultado === false) {
            return $this->apiResponse(['error' => 'Erro ao atualizar', 'errors' => $question->getErrors()], 422);
        }
        if (is_string($resultado)) {
            return $this->apiError($resultado, 422);
        }

        $full = $this->Questions->find()->contain(['Options', 'Sections'])->where(['Questions.id' => $id])->first();

        return $this->apiResponse(['data' => $this->serializePergunta($full, $this->contarRespostas([$id])[$id] ?? 0)]);
    }

    // DELETE /admin/perguntas/{id}
    public function delete(int $id): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $question = $this->Questions->find()->contain(['Options'])->where(['Questions.id' => $id, 'Questions.deleted IS' => null])->first();
        if (!$question) return $this->apiError('Pergunta não encontrada', 404);

        $now = date('Y-m-d H:i:s');
        $optionIds = array_map(fn($o) => $o->id, $question->options ?? []);

        if (!empty($optionIds)) {
            $this->Questions->updateAll(['deleted' => $now], ['depends_on_option_id IN' => $optionIds, 'deleted IS' => null]);
        }
        $this->Questions->updateAll(['deleted' => $now], ['id' => $id]);

        return $this->apiResponse(['deleted' => true]);
    }

    /**
     * POST /admin/perguntas/{id}/restaurar
     *
     * O "Desfazer" depois de excluir: traz de volta a pergunta e as condicionais
     * que saíram junto com ela (excluídas no mesmo instante). Uma condicional que
     * já tinha sido excluída antes, sozinha, continua excluída.
     */
    public function restaurar(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $question = $this->Questions->find()->where(['id' => $id, 'deleted IS NOT' => null])->first();
        if (!$question) return $this->apiError('Pergunta não encontrada ou não está excluída', 404);

        if ($question->depends_on_option_id !== null) {
            $opcao = $this->Options->find()
                ->innerJoinWith('Questions', fn($q) => $q->where(['Questions.deleted IS' => null]))
                ->where(['Options.id' => $question->depends_on_option_id, 'Options.deleted IS' => null])
                ->first();
            if (!$opcao) {
                return $this->apiError('A pergunta de que esta depende foi excluída. Restaure aquela pergunta primeiro.', 422);
            }
        }

        $optionIds = $this->Options->find()
            ->select(['id'])
            ->where(['question_id' => $id, 'deleted IS' => null])
            ->all()
            ->extract('id')
            ->toList();

        $ids = [$id];
        if ($optionIds) {
            $filhas = $this->Questions->find()
                ->select(['id'])
                ->where(['depends_on_option_id IN' => $optionIds, 'deleted' => $question->deleted])
                ->all()
                ->extract('id')
                ->toList();
            $ids = [...$ids, ...$filhas];
        }
        $this->Questions->updateAll(['deleted' => null], ['id IN' => $ids]);

        $restauradas = $this->Questions->find()
            ->contain(['Options', 'Sections'])
            ->where(['Questions.id IN' => $ids])
            ->all()
            ->toArray();
        $respostas = $this->contarRespostas($ids);

        return $this->apiResponse([
            'data' => array_map(fn($q) => $this->serializePergunta($q, $respostas[(int)$q->id] ?? 0), $restauradas),
        ]);
    }

    /**
     * POST /admin/formularios/importar
     *
     * Copia perguntas de um formulário para outro (category_id + subscription_type
     * de destino, question_ids da origem). As cópias entram no fim de cada seção,
     * com opções novas, e as condicionais passam a depender das opções copiadas.
     * Uma condicional vem sempre com a pergunta de que depende, mesmo que só ela
     * tenha sido marcada.
     */
    public function importar(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $data             = $this->request->getData();
        $categoryId       = intval($data['category_id'] ?? 0);
        $subscriptionType = $data['subscription_type'] ?? null;
        $ids              = array_values(array_unique(array_filter(array_map('intval', (array)($data['question_ids'] ?? [])))));

        if (!$categoryId || !in_array($subscriptionType, ['Campista', 'Servo'], true)) {
            return $this->apiError('Informe category_id e subscription_type (Campista|Servo)', 422);
        }
        $categoria = $this->fetchTable('Categories')->find()
            ->where(['id' => $categoryId, 'type' => 'Acampamento', 'deleted IS' => null])
            ->first();
        if (!$categoria) return $this->apiError('Formulário de destino não encontrado', 404);
        if (!$ids) return $this->apiError('Escolha ao menos uma pergunta para importar', 422);

        $buscar = fn(array $quais) => $this->Questions->find()
            ->contain(['Options', 'Sections'])
            ->where(['Questions.id IN' => $quais, 'Questions.deleted IS' => null])
            ->all()
            ->indexBy('id')
            ->toArray();

        $origem = $buscar($ids);
        if (count($origem) !== count($ids)) {
            return $this->apiError('Alguma das perguntas escolhidas não existe mais. Recarregue a página e tente de novo.', 422);
        }
        $primeira = current($origem);
        $mesmoFormulario = fn($q) => (int)$q->category_id === (int)$primeira->category_id
            && $q->subscription_type === $primeira->subscription_type;
        foreach ($origem as $q) {
            if (!$mesmoFormulario($q)) {
                return $this->apiError('As perguntas precisam ser todas do mesmo formulário', 422);
            }
        }
        if ((int)$primeira->category_id === $categoryId && $primeira->subscription_type === $subscriptionType) {
            return $this->apiError('Escolha um formulário diferente deste para importar', 422);
        }

        // Traz junto a pergunta de que cada condicional depende. Repete porque a
        // pai trazida agora pode, ela mesma, depender de outra.
        do {
            $antes = count($origem);
            $opcoesNaLista = [];
            foreach ($origem as $q) {
                foreach ($q->options ?? [] as $o) $opcoesNaLista[(int)$o->id] = true;
            }
            $faltam = [];
            foreach ($origem as $q) {
                $opcaoPai = $q->depends_on_option_id;
                if ($opcaoPai !== null && !isset($opcoesNaLista[(int)$opcaoPai])) $faltam[] = (int)$opcaoPai;
            }
            if ($faltam) {
                $pais = $this->Options->find()
                    ->select(['question_id'])
                    ->where(['id IN' => $faltam, 'deleted IS' => null])
                    ->all()
                    ->extract('question_id')
                    ->toList();
                $pais = array_diff(array_map('intval', $pais), array_keys($origem));
                if ($pais) $origem += array_filter($buscar($pais), $mesmoFormulario);
            }
        } while (count($origem) > $antes);

        // Na ordem do formulário de origem: seção por seção, pergunta por pergunta.
        $lista = array_values($origem);
        usort($lista, fn($a, $b) => [$a->section->order ?? 0, $a->section_id, $a->order, $a->id]
            <=> [$b->section->order ?? 0, $b->section_id, $b->order, $b->id]);

        $novas = $this->Questions->getConnection()->transactional(function () use ($lista, $categoryId, $subscriptionType) {
            $proximaOrdem = [];
            $opcaoNova = [];
            $criadas = [];
            foreach ($lista as $q) {
                $secaoId = (int)$q->section_id;
                if (!isset($proximaOrdem[$secaoId])) {
                    $ultima = $this->Questions->find()
                        ->select(['order'])
                        ->where([
                            'category_id' => $categoryId,
                            'subscription_type' => $subscriptionType,
                            'section_id' => $secaoId,
                            'deleted IS' => null,
                        ])
                        ->orderBy(['order' => 'DESC'])
                        ->first();
                    $proximaOrdem[$secaoId] = ($ultima->order ?? 0) + 1;
                }

                $copia = $this->Questions->newEntity([
                    'category_id'           => $categoryId,
                    'subscription_type'     => $subscriptionType,
                    'text'                  => $q->text,
                    'type'                  => $q->type,
                    'is_required'           => (bool)$q->is_required,
                    'section_id'            => $secaoId,
                    'order'                 => $proximaOrdem[$secaoId]++,
                    'accept_generic_answer' => (bool)$q->accept_generic_answer,
                    'depends_on_option_id'  => null,
                ]);
                $this->Questions->saveOrFail($copia);
                $criadas[(int)$q->id] = $copia;

                foreach ($q->options ?? [] as $o) {
                    $nova = $this->Options->newEntity(['question_id' => $copia->id, 'text' => $o->text, 'order' => $o->order]);
                    $this->Options->saveOrFail($nova);
                    $opcaoNova[(int)$o->id] = (int)$nova->id;
                }
            }

            // Depois de todas criadas, porque a pai pode estar numa seção que vem depois.
            foreach ($lista as $q) {
                $opcaoPai = $q->depends_on_option_id === null ? null : (int)$q->depends_on_option_id;
                // Pai que não veio (excluída ou de outro formulário): a cópia fica sem condição.
                if ($opcaoPai === null || !isset($opcaoNova[$opcaoPai])) continue;
                $copia = $criadas[(int)$q->id];
                $copia->depends_on_option_id = $opcaoNova[$opcaoPai];
                $this->Questions->saveOrFail($copia);
            }

            return array_map(fn($c) => (int)$c->id, array_values($criadas));
        });

        $copiadas = $this->Questions->find()
            ->contain(['Options', 'Sections'])
            ->where(['Questions.id IN' => $novas])
            ->orderBy(['Sections.order' => 'ASC', 'Questions.order' => 'ASC'])
            ->all()
            ->toArray();

        return $this->apiResponse([
            'data' => array_map(fn($q) => $this->serializePergunta($q), $copiadas),
        ], 201);
    }

    /**
     * GET /admin/formularios/resumo
     *
     * Um item por formulário de acampamento (categoria × Campista/Servo), para a
     * lista da tela Formulários: quantas perguntas tem, que seções usa e se está
     * "em uso" — um acampamento da categoria com o prazo do formulário ainda
     * aberto. Em uso, diz quantos já enviaram do total que precisa enviar (as
     * mesmas pessoas que a regra do prazo cobra: sorteados, indicados e suplentes).
     */
    public function resumo(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao(self::LER);
        if ($identity instanceof Response) return $identity;

        $ordemDasCategorias = ['Mirim', 'FAC', 'Juvenil', 'Sênior', 'Casais', 'Discipulados'];
        $categorias = $this->fetchTable('Categories')->find()
            ->where(['type' => 'Acampamento', 'deleted IS' => null])
            ->all()
            ->toList();
        usort($categorias, function ($a, $b) use ($ordemDasCategorias) {
            $pa = array_search($a->name, $ordemDasCategorias, true);
            $pb = array_search($b->name, $ordemDasCategorias, true);

            return [$pa === false ? 99 : $pa, $a->name] <=> [$pb === false ? 99 : $pb, $b->name];
        });

        $porSecao = $this->Questions->find();
        $porSecao->select(['category_id', 'subscription_type', 'section_id', 'n' => $porSecao->func()->count('*')])
            ->where(['deleted IS' => null])
            ->groupBy(['category_id', 'subscription_type', 'section_id']);
        $usoDasSecoes = [];
        foreach ($porSecao->all() as $linha) {
            $usoDasSecoes["{$linha->category_id}|{$linha->subscription_type}"][(int)$linha->section_id] = (int)$linha->n;
        }

        // Quem já enviou, somando todas as edições: decide se a lista mostra "Respostas".
        // Mesmas pessoas que a aba Respostas mostra (sem desistentes, quem perdeu a
        // vaga ou conta desativada), para os números baterem.
        $porFormulario = $this->fetchTable('PreRegistrations')->find();
        $porFormulario->select([
                'category_id'       => 'Activities.category_id',
                'subscription_type' => 'PreRegistrations.subscription_type',
                'n'                 => $porFormulario->func()->count('*'),
            ])
            ->innerJoin(['Activities' => 'activities'], ['Activities.id = PreRegistrations.activity_id', 'Activities.deleted IS' => null])
            ->innerJoin(['Users' => 'users'], ['Users.id = PreRegistrations.user_id', 'Users.deleted IS' => null])
            ->where([
                'PreRegistrations.form_submitted'     => true,
                'PreRegistrations.deleted IS'         => null,
                'PreRegistrations.is_quitter'         => false,
                'PreRegistrations.disqualified_at IS' => null,
            ])
            ->groupBy(['Activities.category_id', 'PreRegistrations.subscription_type'])
            ->disableHydration();
        $enviaram = [];
        foreach ($porFormulario->all() as $linha) {
            $enviaram["{$linha['category_id']}|{$linha['subscription_type']}"] = (int)$linha['n'];
        }

        $agora = date('Y-m-d H:i:s');
        $formularios = [];
        foreach ($categorias as $categoria) {
            foreach (['Campista', 'Servo'] as $tipo) {
                $secoes = $usoDasSecoes["{$categoria->id}|{$tipo}"] ?? [];
                $formularios[] = [
                    'category_id'       => $categoria->id,
                    'categoria'         => $categoria->name,
                    'subscription_type' => $tipo,
                    'perguntas'         => array_sum($secoes),
                    'secoes'            => array_keys($secoes),
                    'uso'               => $this->usoDoFormulario((int)$categoria->id, $tipo, $agora),
                    'respostas'         => $enviaram["{$categoria->id}|{$tipo}"] ?? 0,
                ];
            }
        }

        return $this->apiResponse(['data' => $formularios]);
    }

    /** Acampamento da categoria com o prazo do formulário aberto (o que vence primeiro), ou null. */
    private function usoDoFormulario(int $categoryId, string $tipo, string $agora): ?array
    {
        $campoPrazo = $tipo === 'Servo' ? 'servant_form_deadline' : 'camper_form_deadline';

        $atividade = $this->fetchTable('Activities')->find()
            ->select(['Activities.id', 'Activities.name', 'prazo' => "Campings.{$campoPrazo}"])
            ->innerJoin(['Campings' => 'campings'], ['Campings.id = Activities.camping_id'])
            ->where([
                'Activities.category_id' => $categoryId,
                'Activities.deleted IS'  => null,
                "Campings.{$campoPrazo} >" => $agora,
            ])
            ->orderBy(["Campings.{$campoPrazo}" => 'ASC'])
            ->disableHydration()
            ->first();
        if (!$atividade) {
            return null;
        }

        $quemPrecisaEnviar = $this->fetchTable('PreRegistrations')->find()
            ->where([
                'activity_id'        => $atividade['id'],
                'subscription_type'  => $tipo,
                'deleted IS'         => null,
                'is_quitter'         => false,
                'disqualified_at IS' => null,
                'OR' => ['selection_method_id IS NOT' => null, 'substitute_position IS NOT' => null],
            ]);
        $total = (clone $quemPrecisaEnviar)->count();
        $enviados = $quemPrecisaEnviar->where(['form_submitted' => true])->count();

        return [
            'atividade_id' => (int)$atividade['id'],
            'nome'         => $atividade['name'],
            'prazo'        => date('Y-m-d\TH:i:s', strtotime((string)$atividade['prazo'])),
            'enviados'     => $enviados,
            'total'        => $total,
        ];
    }

    // GET /admin/secoes
    public function secoes(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao(self::LER);
        if ($identity instanceof Response) return $identity;

        $secoes = $this->Sections->find()->where(['deleted IS' => null])->orderBy(['order' => 'ASC'])->all();

        return $this->apiResponse([
            'data' => array_map(fn($s) => ['id' => $s->id, 'titulo' => $s->title, 'ordem' => $s->order], $secoes->toArray()),
        ]);
    }

    // POST /admin/secoes
    public function criarSecao(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $titulo = trim((string)($this->request->getData('titulo') ?? ''));
        if ($titulo === '') return $this->apiError('Informe o título da seção', 422);

        $existing = $this->Sections->find()->where(['title' => $titulo, 'deleted IS' => null])->first();
        if ($existing) {
            return $this->apiResponse(['data' => ['id' => $existing->id, 'titulo' => $existing->title, 'ordem' => $existing->order]]);
        }

        $maxOrd = $this->Sections->find()->select(['order'])->orderBy(['order' => 'DESC'])->first();
        $secao  = $this->Sections->newEntity(['title' => $titulo, 'order' => ($maxOrd->order ?? 0) + 1]);
        if (!$this->Sections->save($secao)) return $this->apiError('Erro ao criar seção', 500);

        return $this->apiResponse(['data' => ['id' => $secao->id, 'titulo' => $secao->title, 'ordem' => $secao->order]], 201);
    }

    // PATCH /admin/secoes/{id}
    public function editarSecao(int $id): Response
    {
        $this->request->allowMethod(['patch', 'put']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $secao = $this->Sections->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$secao) return $this->apiError('Seção não encontrada', 404);

        $data  = $this->request->getData();
        $patch = [];
        if (isset($data['titulo'])) $patch['title'] = trim((string)$data['titulo']);
        if (isset($data['ordem']))  $patch['order'] = intval($data['ordem']);

        $this->Sections->patchEntity($secao, $patch);
        if (!$this->Sections->save($secao)) return $this->apiError('Erro ao atualizar seção', 422);

        return $this->apiResponse(['data' => ['id' => $secao->id, 'titulo' => $secao->title, 'ordem' => $secao->order]]);
    }

    // DELETE /admin/secoes/{id}
    public function excluirSecao(int $id): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $count = $this->Questions->find()->where(['section_id' => $id, 'deleted IS' => null])->count();
        if ($count > 0) {
            return $this->apiError("Não é possível excluir: existem {$count} pergunta(s) nesta seção.", 422);
        }

        $this->Sections->updateAll(['deleted' => date('Y-m-d H:i:s')], ['id' => $id, 'deleted IS' => null]);

        return $this->apiResponse(['deleted' => true]);
    }

    /**
     * POST /admin/secoes/{id}/excluir-perguntas  (category_id, subscription_type)
     *
     * Exclui de uma vez as perguntas da seção em UM formulário, com as
     * condicionais delas. A seção é compartilhada e continua existindo nos
     * outros formulários. Devolve `quando` (o instante da exclusão), que o
     * "Desfazer" manda de volta para restaurar exatamente essas perguntas.
     */
    public function excluirPerguntasDaSecao(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $formulario = $this->formularioDoPedido();
        if ($formulario instanceof Response) return $formulario;
        [$categoryId, $subscriptionType] = $formulario;

        $ids = $this->Questions->find()
            ->select(['id'])
            ->where([
                'category_id'       => $categoryId,
                'subscription_type' => $subscriptionType,
                'section_id'        => $id,
                'deleted IS'        => null,
            ])
            ->all()
            ->extract('id')
            ->toList();
        if (!$ids) return $this->apiError('Esta seção não tem perguntas neste formulário', 404);

        // Condicionais das perguntas da seção saem junto, mesmo se estiverem em outra seção.
        $opcoes = $this->Options->find()
            ->select(['id'])
            ->where(['question_id IN' => $ids, 'deleted IS' => null])
            ->all()
            ->extract('id')
            ->toList();
        if ($opcoes) {
            $filhas = $this->Questions->find()
                ->select(['id'])
                ->where(['depends_on_option_id IN' => $opcoes, 'deleted IS' => null])
                ->all()
                ->extract('id')
                ->toList();
            $ids = array_values(array_unique([...$ids, ...$filhas]));
        }

        $quando = date('Y-m-d H:i:s');
        $this->Questions->updateAll(['deleted' => $quando], ['id IN' => $ids]);

        return $this->apiResponse(['excluidas' => count($ids), 'quando' => $quando]);
    }

    /**
     * POST /admin/secoes/{id}/restaurar-perguntas  (category_id, subscription_type, quando)
     *
     * O "Desfazer" de excluirPerguntasDaSecao: volta as perguntas da seção
     * excluídas naquele instante e as condicionais delas que saíram junto.
     */
    public function restaurarPerguntasDaSecao(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('formularios');
        if ($identity instanceof Response) return $identity;

        $formulario = $this->formularioDoPedido();
        if ($formulario instanceof Response) return $formulario;
        [$categoryId, $subscriptionType] = $formulario;

        $quando = (string)$this->request->getData('quando');
        $data = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $quando);
        if ($data === false || $data->format('Y-m-d H:i:s') !== $quando) {
            return $this->apiError('Informe quando as perguntas foram excluídas', 422);
        }

        $daSecao = $this->Questions->find()
            ->select(['id'])
            ->where([
                'category_id'       => $categoryId,
                'subscription_type' => $subscriptionType,
                'section_id'        => $id,
                'deleted'           => $quando,
            ])
            ->all()
            ->extract('id')
            ->toList();
        if (!$daSecao) return $this->apiError('Não há perguntas desta seção para restaurar', 404);

        $ids = $daSecao;
        $opcoes = $this->Options->find()
            ->select(['id'])
            ->where(['question_id IN' => $daSecao, 'deleted IS' => null])
            ->all()
            ->extract('id')
            ->toList();
        if ($opcoes) {
            $filhas = $this->Questions->find()
                ->select(['id'])
                ->where(['depends_on_option_id IN' => $opcoes, 'deleted' => $quando])
                ->all()
                ->extract('id')
                ->toList();
            $ids = array_values(array_unique([...$ids, ...$filhas]));
        }
        $this->Questions->updateAll(['deleted' => null], ['id IN' => $ids]);

        $restauradas = $this->Questions->find()
            ->contain(['Options', 'Sections'])
            ->where(['Questions.id IN' => $ids])
            ->all()
            ->toArray();
        $respostas = $this->contarRespostas($ids);

        return $this->apiResponse([
            'data' => array_map(fn($q) => $this->serializePergunta($q, $respostas[(int)$q->id] ?? 0), $restauradas),
        ]);
    }

    /**
     * category_id e subscription_type do corpo do pedido, ou o erro 422.
     *
     * @return array{0: int, 1: string}|\Cake\Http\Response
     */
    private function formularioDoPedido(): array|Response
    {
        $categoryId       = intval($this->request->getData('category_id') ?? 0);
        $subscriptionType = $this->request->getData('subscription_type');
        if (!$categoryId || !in_array($subscriptionType, ['Campista', 'Servo'], true)) {
            return $this->apiError('Informe category_id e subscription_type (Campista|Servo)', 422);
        }

        return [$categoryId, $subscriptionType];
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Opções como chegam da tela: `[{id, text}]` (id null = opção nova) ou, no
     * formato antigo, só os textos. Linhas em branco são descartadas.
     *
     * @return list<array{id: int|null, text: string, porPosicao: bool}>
     */
    private function lerOpcoes(array $brutas): array
    {
        $opcoes = [];
        foreach ($brutas as $item) {
            if (is_array($item)) {
                $texto = trim((string)($item['text'] ?? ''));
                $id = !empty($item['id']) ? intval($item['id']) : null;
                $porPosicao = false;
            } else {
                $texto = trim((string)$item);
                $id = null;
                $porPosicao = true;
            }
            if ($texto !== '') $opcoes[] = ['id' => $id, 'text' => $texto, 'porPosicao' => $porPosicao];
        }

        return $opcoes;
    }

    /**
     * Deixa a pergunta com exatamente estas opções, nesta ordem, sem recriar as
     * que continuam: as respostas e as perguntas condicionais apontam para o id
     * da opção, então apagar e criar de novo soltava tudo isso.
     *
     * - Opção com id → a mesma opção, com o texto novo (renomear não solta nada).
     * - Opção sem id com o mesmo texto de uma que saiu → volta a ser aquela.
     * - Só no formato antigo (só textos): linha editada no mesmo lugar
     *   ("Talvez" → "Às vezes") → mesma opção, texto novo.
     * - O resto é opção nova; as que sobraram são removidas (exclusão lógica).
     *
     * Devolve a mensagem de erro, sem gravar nada, se for remover uma opção que
     * tem pergunta condicional ligada ou se vier um id que não é desta pergunta.
     */
    private function sincronizarOpcoes(int $questionId, array $itens): ?string
    {
        $atuais = $this->Options->find()
            ->where(['question_id' => $questionId, 'deleted IS' => null])
            ->orderBy(['Options.order' => 'ASC', 'Options.id' => 'ASC'])
            ->all()
            ->toList();
        $porId = [];
        foreach ($atuais as $opcao) $porId[(int)$opcao->id] = $opcao;

        /** @var array<int, \App\Model\Entity\Option> $destino posição na lista nova => opção reaproveitada */
        $destino = [];
        $usadas = [];
        foreach ($itens as $i => $item) {
            if ($item['id'] === null) continue;
            if (!isset($porId[$item['id']]) || isset($usadas[$item['id']])) {
                return 'Uma das opções desta pergunta não existe mais. Recarregue a página e tente de novo.';
            }
            $destino[$i] = $porId[$item['id']];
            $usadas[$item['id']] = true;
        }
        foreach ($itens as $i => $item) {
            if (isset($destino[$i])) continue;
            foreach ($atuais as $opcao) {
                if (!isset($usadas[(int)$opcao->id]) && $opcao->text === $item['text']) {
                    $destino[$i] = $opcao;
                    $usadas[(int)$opcao->id] = true;
                    break;
                }
            }
        }
        foreach ($itens as $i => $item) {
            $noMesmoLugar = $atuais[$i] ?? null;
            if (isset($destino[$i]) || !$item['porPosicao'] || !$noMesmoLugar || isset($usadas[(int)$noMesmoLugar->id])) continue;
            $destino[$i] = $noMesmoLugar;
            $usadas[(int)$noMesmoLugar->id] = true;
        }

        $removidas = array_values(array_filter($atuais, fn($o) => !isset($usadas[(int)$o->id])));
        if ($removidas) {
            $idsRemovidos = array_map(fn($o) => $o->id, $removidas);
            $dependente = $this->Questions->find()
                ->where(['depends_on_option_id IN' => $idsRemovidos, 'deleted IS' => null])
                ->first();
            if ($dependente) {
                $opcao = current(array_filter($removidas, fn($o) => $o->id === $dependente->depends_on_option_id));

                return "A opção \"{$opcao->text}\" tem a pergunta \"{$dependente->text}\" ligada a ela. "
                    . 'Exclua essa pergunta ou mude a condição dela antes de tirar a opção.';
            }

            // Exclusão lógica: respostas antigas e perguntas excluídas (que o
            // "Desfazer" pode trazer de volta) continuam apontando para ela.
            $this->Options->updateAll(['deleted' => date('Y-m-d H:i:s')], ['id IN' => $idsRemovidos]);
        }

        foreach ($itens as $i => $item) {
            $opcao = $destino[$i] ?? $this->Options->newEntity(['question_id' => $questionId]);
            $this->Options->patchEntity($opcao, ['text' => $item['text'], 'order' => $i + 1]);
            $this->Options->saveOrFail($opcao);
        }

        return null;
    }

    /**
     * Confere se uma pergunta pode aparecer só quando marcarem a opção
     * $optionId. A pai precisa ser deste formulário, da mesma seção e aparecer
     * sempre (um nível só), e uma pergunta que já tem condicionais não vira
     * condicional. Devolve a mensagem de erro, ou null se puder.
     */
    private function validarCondicao(
        int $categoryId,
        string $subscriptionType,
        int $sectionId,
        int $optionId,
        ?int $questionId = null
    ): ?string {
        $opcao = $this->Options->find()->where(['id' => $optionId, 'deleted IS' => null])->first();
        $pai = $opcao ? $this->Questions->find()->where(['id' => $opcao->question_id, 'deleted IS' => null])->first() : null;
        if (!$pai) {
            return 'A opção escolhida para mostrar esta pergunta não existe mais. Recarregue a página e tente de novo.';
        }
        if ((int)$pai->id === $questionId) {
            return 'Uma pergunta não pode depender da própria resposta.';
        }
        if ((int)$pai->category_id !== $categoryId || $pai->subscription_type !== $subscriptionType) {
            return 'A pergunta de que esta depende é de outro formulário.';
        }
        if ((int)$pai->section_id !== $sectionId) {
            return 'A pergunta de que esta depende precisa estar na mesma seção.';
        }
        if ($pai->depends_on_option_id !== null) {
            return "A pergunta \"{$pai->text}\" já é condicional. Escolha uma pergunta que aparece sempre.";
        }

        if ($questionId !== null) {
            $minhasOpcoes = $this->Options->find()
                ->select(['id'])
                ->where(['question_id' => $questionId, 'deleted IS' => null])
                ->all()
                ->extract('id')
                ->toList();
            $temFilhas = $minhasOpcoes && $this->Questions->find()
                ->where(['depends_on_option_id IN' => $minhasOpcoes, 'deleted IS' => null])
                ->count() > 0;
            if ($temFilhas) {
                return 'Esta pergunta tem perguntas que dependem dela, então ela precisa aparecer sempre.';
            }
        }

        return null;
    }

    /**
     * Quantas pessoas responderam cada pergunta (inscrições não excluídas).
     *
     * @param list<int> $questionIds
     * @return array<int, int> id da pergunta => pessoas
     */
    private function contarRespostas(array $questionIds): array
    {
        if (!$questionIds) return [];

        $query = $this->fetchTable('Answers')->find();
        $query->select([
                'question_id' => 'Answers.question_id',
                'n' => $query->func()->count('DISTINCT Answers.pre_registration_id'),
            ])
            ->innerJoin(['PreRegistrations' => 'pre_registrations'], [
                'PreRegistrations.id = Answers.pre_registration_id',
                'PreRegistrations.deleted IS' => null,
            ])
            ->where(['Answers.question_id IN' => $questionIds, 'Answers.deleted IS' => null])
            ->groupBy(['Answers.question_id'])
            ->disableHydration();

        $contagem = [];
        foreach ($query->all() as $linha) {
            $contagem[(int)$linha['question_id']] = (int)$linha['n'];
        }

        return $contagem;
    }

    private function serializePergunta(object $q, int $respostas = 0): array
    {
        $opts = is_array($q->options ?? null) ? $q->options : ($q->options?->toArray() ?? []);

        return [
            'id'                   => $q->id,
            'category_id'          => $q->category_id,
            'subscription_type'    => $q->subscription_type,
            'text'                 => $q->text,
            'type'                 => $q->type,
            'is_required'          => (bool)$q->is_required,
            'order'                => $q->order,
            'depends_on_option_id' => $q->depends_on_option_id,
            'section'              => ['id' => $q->section->id ?? 0, 'titulo' => $q->section->title ?? ''],
            'options'              => array_map(fn($o) => ['id' => $o->id, 'text' => $o->text], $opts),
            // Pessoas que já responderam: o aviso antes de mexer nas opções.
            'respostas'            => $respostas,
        ];
    }
}
