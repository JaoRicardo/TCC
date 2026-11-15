<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\TableLocator;

/**
 * Regras comuns aos formulários (de acampamento e de evento).
 *
 * Um formulário é representado pelos três campos da pergunta que o
 * identificam: category_id + subscription_type (acampamento: Campista/Servo)
 * ou event_form_id (evento: sem categoria, tipo "Participante").
 */
class FormulariosService
{
    private $Questions;
    private $Options;

    public function __construct()
    {
        $locator = new TableLocator();
        $this->Questions = $locator->get('Questions');
        $this->Options   = $locator->get('Options');
    }

    /** O formulário a que uma pergunta pertence. */
    public static function formularioDa(object $q): array
    {
        return [
            'category_id'       => $q->category_id === null ? null : (int)$q->category_id,
            'subscription_type' => (string)$q->subscription_type,
            'event_form_id'     => $q->event_form_id === null ? null : (int)$q->event_form_id,
        ];
    }

    /** Formulário de evento, no mesmo formato. */
    public static function deEvento(int $eventFormId): array
    {
        return ['category_id' => null, 'subscription_type' => 'Participante', 'event_form_id' => $eventFormId];
    }

    /** Condições de where para as perguntas de um formulário (prefixo = "Questions." com contain). */
    public static function condicoes(array $formulario, string $prefixo = ''): array
    {
        $onde = [];
        foreach ($formulario as $campo => $valor) {
            $onde[$prefixo . $campo . ($valor === null ? ' IS' : '')] = $valor;
        }

        return $onde;
    }

    /**
     * Copia perguntas (todas de um mesmo formulário) para o fim de cada seção do
     * destino, como cópias independentes: opções novas, e as condicionais passam
     * a depender das opções copiadas. A pergunta de que uma condicional depende
     * vem junto, mesmo que só a condicional tenha sido pedida.
     *
     * @param list<int> $ids
     * @return list<int> ids das perguntas criadas
     */
    public function copiar(array $ids, array $destino): array
    {
        $buscar = fn(array $quais) => $this->Questions->find()
            ->contain(['Options', 'Sections'])
            ->where(['Questions.id IN' => $quais, 'Questions.deleted IS' => null])
            ->all()
            ->indexBy('id')
            ->toArray();

        $origem = $ids ? $buscar($ids) : [];
        if (!$origem) return [];
        $daOrigem = self::formularioDa(current($origem));
        $mesmoFormulario = fn($q) => self::formularioDa($q) == $daOrigem;

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

        return $this->Questions->getConnection()->transactional(function () use ($lista, $destino) {
            $proximaOrdem = [];
            $opcaoNova = [];
            $criadas = [];
            foreach ($lista as $q) {
                $secaoId = (int)$q->section_id;
                if (!isset($proximaOrdem[$secaoId])) {
                    $ultima = $this->Questions->find()
                        ->select(['order'])
                        ->where(self::condicoes($destino) + ['section_id' => $secaoId, 'deleted IS' => null])
                        ->orderBy(['order' => 'DESC'])
                        ->first();
                    $proximaOrdem[$secaoId] = ($ultima->order ?? 0) + 1;
                }

                $copia = $this->Questions->newEntity($destino + [
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
    }
}
