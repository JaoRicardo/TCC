<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\TableLocator;

/**
 * Categorias de acampamento (tela Gestão › Categorias, decidido com o usuário
 * em 2026-11-24). As regras de cada categoria ficam nas colunas — nunca no
 * nome — para que criar e renomear funcione:
 *
 * - position: uma ordem só para o painel inteiro (handoff
 *   design_handoff_categorias): primeiro a escada por idade (in_hierarchy), do
 *   degrau mais novo ao mais velho, depois as que ficam fora dela. Na escada,
 *   ter sido campista num degrau deixa servir nele e em todos os de baixo
 *   (position menor). Fora da escada, só na própria. A arquivada continua no
 *   lugar dela na sequência — e na regra de servir.
 * - min_age/max_age: idade do campista (contada no dia da inscrição).
 * - required_category_id: precisa ter sido campista nela antes.
 * - couples_only: só vagas de casais (o casal é sorteado junto) — e por isso
 *   só aceita casados/união estável.
 * - accepts_married: false = casados/união estável não entram como campistas.
 * - filled_by_guardian: o formulário é preenchido pelo responsável.
 * - archived_at: some da criação de acampamentos e do modelo de setores, mas
 *   formulários e respostas antigas continuam acessíveis. Só arquiva quem não
 *   tem acampamento por acontecer; só exclui quem nunca teve acampamento.
 */
class CategoriasService
{
    /**
     * As categorias de sempre, com as regras que estavam no código. Usadas pelo
     * seed e pelos testes; o banco que já existia recebeu as mesmas pela migração
     * AddRegrasNasCategorias.
     */
    public const ORIGINAIS = [
        'Mirim'        => ['min_age' => 10, 'max_age' => 12, 'in_hierarchy' => true, 'filled_by_guardian' => true],
        'FAC'          => ['min_age' => 14, 'max_age' => 17, 'in_hierarchy' => true],
        'Juvenil'      => ['min_age' => 18, 'max_age' => 24, 'in_hierarchy' => true, 'accepts_married' => false],
        'Sênior'       => ['min_age' => 25, 'max_age' => 60, 'in_hierarchy' => true],
        'Casais'       => ['couples_only' => true],
        'Discipulados' => ['requer' => 'Sênior'],
    ];

    private $Categories;
    private $Activities;

    public function __construct()
    {
        $locator = new TableLocator();
        $this->Categories = $locator->get('Categories');
        $this->Activities = $locator->get('Activities');
    }

    /** Categorias de acampamento não excluídas (arquivadas inclusive), na ordem da lista. */
    public function todas(): array
    {
        return $this->Categories->find()
            ->where(['type' => 'Acampamento', 'deleted IS' => null])
            ->orderBy(['position' => 'ASC', 'id' => 'ASC'])
            ->all()
            ->toList();
    }

    public function buscar(int $id): ?object
    {
        return $this->Categories->find()
            ->where(['id' => $id, 'type' => 'Acampamento', 'deleted IS' => null])
            ->first();
    }

    public static function soCasais(?object $categoria): bool
    {
        return (bool)($categoria->couples_only ?? false);
    }

    // ─── Elegibilidade ──────────────────────────────────────────────────────

    /**
     * Em quais categorias (ids) pode servir quem foi campista nestas. Na escada,
     * a mais alta em que foi campista libera ela e as anteriores; fora dela,
     * cada uma libera só a si mesma.
     *
     * @param int[] $foiCampistaEm
     * @return int[]
     */
    public function liberadasParaServir(array $foiCampistaEm): array
    {
        $todas = $this->todas();
        $foi = array_flip(array_map('intval', $foiCampistaEm));

        $maisAlta = null;
        foreach ($todas as $c) {
            if ($c->in_hierarchy && isset($foi[(int)$c->id])) {
                $maisAlta = (int)$c->position;
            }
        }

        $liberadas = [];
        foreach ($todas as $c) {
            $id = (int)$c->id;
            if (isset($foi[$id]) || ($c->in_hierarchy && $maisAlta !== null && (int)$c->position <= $maisAlta)) {
                $liberadas[] = $id;
            }
        }

        return $liberadas;
    }

    /** A próxima categoria da escada que aceita casados (para a mensagem de quem é casado). */
    public function proximaQueAceitaCasados(object $categoria): ?object
    {
        if (!$categoria->in_hierarchy) {
            return null;
        }
        foreach ($this->todas() as $c) {
            if ($c->in_hierarchy && $c->archived_at === null && (int)$c->position > (int)$categoria->position && $c->accepts_married && !$c->couples_only) {
                return $c;
            }
        }

        return null;
    }

    // ─── Tela Categorias ────────────────────────────────────────────────────

    /**
     * Lista da tela: regras + uso (acampamentos, os por acontecer com as datas
     * para o selo da fase) + o que falta configurar (perguntas dos formulários
     * e o modelo de setores).
     */
    public function listar(): array
    {
        $uso = $this->usoDosAcampamentos();
        $perguntas = $this->perguntasPorCategoria();
        $setores = $this->setoresPorCategoria();

        return array_map(function ($c) use ($uso, $perguntas, $setores) {
            $id = (int)$c->id;
            $u = $uso[$id] ?? ['total' => 0, 'porAcontecer' => 0, 'lista' => []];

            return $this->serializar($c) + [
                'acampamentos'             => $u['total'],
                'acampamentosPorAcontecer' => $u['porAcontecer'],
                'porAcontecer'             => $u['lista'],
                'perguntasCampista'        => $perguntas["{$id}|Campista"] ?? 0,
                'perguntasServo'           => $perguntas["{$id}|Servo"] ?? 0,
                'setores'                  => $setores[$id]['setores'] ?? 0,
                'vagasSetores'             => $setores[$id]['vagas'] ?? 0,
            ];
        }, $this->todas());
    }

    public function serializar(object $c): array
    {
        return [
            'id'                => (int)$c->id,
            'nome'              => $c->name,
            'posicao'           => (int)$c->position,
            'idadeMinima'       => $c->min_age === null ? null : (int)$c->min_age,
            'idadeMaxima'       => $c->max_age === null ? null : (int)$c->max_age,
            'naEscada'          => (bool)$c->in_hierarchy,
            'requerCategoriaId' => $c->required_category_id === null ? null : (int)$c->required_category_id,
            'soCasais'          => (bool)$c->couples_only,
            'aceitaCasados'     => (bool)$c->accepts_married,
            'preenchidoPeloResponsavel' => (bool)$c->filled_by_guardian,
            'arquivada'         => $c->archived_at !== null,
            'arquivadaEm'       => $c->archived_at?->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * Cria ou altera. Devolve a categoria ou os erros por campo
     * (nome, idadeMinima, idadeMaxima, requerCategoriaId, soCasais, geral).
     *
     * @return array{0: object|null, 1: array<string, string>}
     */
    public function salvar(?object $categoria, array $dados): array
    {
        $erros = [];
        $nome = trim(preg_replace('/\s+/u', ' ', (string)($dados['nome'] ?? '')));
        if ($nome === '') {
            $erros['nome'] = 'Informe o nome da categoria.';
        } elseif (mb_strlen($nome) > 100) {
            $erros['nome'] = 'Use no máximo 100 caracteres.';
        } elseif ($this->nomeEmUso($nome, $categoria?->id)) {
            $erros['nome'] = 'Já existe uma categoria com esse nome.';
        }

        $idade = function (string $campo) use ($dados, &$erros): ?int {
            $v = $dados[$campo] ?? null;
            if ($v === null || $v === '') return null;
            if (!is_numeric($v) || (int)$v != $v || (int)$v < 0 || (int)$v > 120) {
                $erros[$campo] = 'Use uma idade entre 0 e 120.';

                return null;
            }

            return (int)$v;
        };
        $min = $idade('idadeMinima');
        $max = $idade('idadeMaxima');
        if ($min !== null && $max !== null && $min > $max) {
            $erros['idadeMaxima'] = 'A idade máxima não pode ser menor que a mínima.';
        }

        $bool = fn(string $campo, bool $padrao) => array_key_exists($campo, $dados)
            ? filter_var($dados[$campo], FILTER_VALIDATE_BOOLEAN)
            : $padrao;
        $naEscada = $bool('naEscada', (bool)($categoria->in_hierarchy ?? false));
        $soCasais = $bool('soCasais', (bool)($categoria->couples_only ?? false));
        $aceitaCasados = $soCasais ? true : $bool('aceitaCasados', (bool)($categoria->accepts_married ?? true));
        $responsavel = $bool('preenchidoPeloResponsavel', (bool)($categoria->filled_by_guardian ?? false));

        $requer = $dados['requerCategoriaId'] ?? null;
        $requer = ($requer === null || $requer === '' || (int)$requer <= 0) ? null : (int)$requer;
        if ($requer !== null) {
            $alvo = $this->buscar($requer);
            if ($alvo === null) {
                $erros['requerCategoriaId'] = 'Escolha uma categoria da lista.';
            } elseif ($categoria !== null && $this->formaCiclo((int)$categoria->id, $requer)) {
                $erros['requerCategoriaId'] = $requer === (int)$categoria->id
                    ? 'A categoria não pode pedir ela mesma.'
                    : "{$alvo->name} já pede esta categoria antes; as duas não podem pedir uma à outra.";
            }
        }

        if ($categoria !== null && $soCasais !== (bool)$categoria->couples_only && $this->temAcampamentoPorAcontecer((int)$categoria->id)) {
            $erros['soCasais'] = 'Há acampamento desta categoria por acontecer: o tipo de vaga só muda depois que ele terminar.';
        }

        if ($erros) {
            return [null, $erros];
        }

        $campos = [
            'name'                 => $nome,
            'min_age'              => $min,
            'max_age'              => $max,
            'in_hierarchy'         => $naEscada,
            'required_category_id' => $requer,
            'couples_only'         => $soCasais,
            'accepts_married'      => $aceitaCasados,
            'filled_by_guardian'   => $responsavel,
        ];
        $depois = $this->lerDepois($dados);
        if ($depois !== false && $depois !== null && ($this->buscar($depois) === null || ($categoria !== null && $depois === (int)$categoria->id))) {
            $erros['depois'] = 'Escolha um degrau da lista.';

            return [null, $erros];
        }

        $nova = $categoria === null;
        $estavaNaEscada = !$nova && (bool)$categoria->in_hierarchy;
        if ($nova) {
            $campos['type'] = 'Acampamento';
            $campos['position'] = 999999; // o lugar certo vem logo abaixo
            $categoria = $this->Categories->newEntity($campos);
        } else {
            $this->Categories->patchEntity($categoria, $campos);
        }

        $this->Categories->getConnection()->transactional(function () use ($categoria, $nova, $estavaNaEscada, $naEscada, $depois) {
            $this->Categories->saveOrFail($categoria);
            $manter = !$nova && $estavaNaEscada === $naEscada;
            $this->posicionar((int)$categoria->id, $naEscada, $depois, $manter);
        });

        return [$this->buscar((int)$categoria->id), []];
    }

    /**
     * Nova ordem (handoff: modo "Mudar a ordem"). Recebe as categorias em uso na
     * ordem nova — a escada do degrau mais novo ao mais velho, depois as de
     * fora. Cada arquivada fica no mesmo lugar da sequência e as em uso se
     * rearranjam em volta dela; ids que faltarem ficam na ordem de antes. No
     * fim, a escada sempre vem antes das de fora.
     *
     * @param int[] $ids
     */
    public function reordenar(array $ids): void
    {
        $todas = $this->todas();
        $porId = [];
        foreach ($todas as $c) $porId[(int)$c->id] = $c;

        $pedidas = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if (isset($porId[$id]) && $porId[$id]->archived_at === null && !in_array($id, $pedidas, true)) $pedidas[] = $id;
        }

        // Em cada bloco (escada / fora), as arquivadas ficam no mesmo índice e as
        // em uso ocupam os outros lugares na ordem pedida.
        $seq = [];
        foreach ([true, false] as $bloco) {
            $doBloco = array_values(array_filter($todas, fn($c) => (bool)$c->in_hierarchy === $bloco));
            $fila = array_values(array_filter($pedidas, fn($id) => (bool)$porId[$id]->in_hierarchy === $bloco));
            foreach ($doBloco as $c) {
                if ($c->archived_at === null && !in_array((int)$c->id, $fila, true)) $fila[] = (int)$c->id;
            }
            foreach ($doBloco as $c) {
                $seq[] = $c->archived_at === null ? array_shift($fila) : (int)$c->id;
            }
        }

        $this->Categories->getConnection()->transactional(fn() => $this->gravarOrdem($seq));
    }

    /** Null = arquivou; senão, por que não. */
    public function arquivar(object $categoria): ?string
    {
        $n = $this->acampamentosPorAcontecer((int)$categoria->id);
        if ($n > 0) {
            return $n === 1
                ? 'Há um acampamento desta categoria por acontecer. Ela pode ser arquivada depois que ele terminar.'
                : "Há {$n} acampamentos desta categoria por acontecer. Ela pode ser arquivada depois que eles terminarem.";
        }
        $this->Categories->patchEntity($categoria, ['archived_at' => DateTime::now()]);
        $this->Categories->saveOrFail($categoria);

        return null;
    }

    /**
     * Reativa. Na escada, `depois` (presente nos dados) escolhe o degrau; sem
     * ele, fica no mesmo lugar ("Desfazer"). Fora da escada, `fim` = volta para
     * o fim do bloco de fora.
     */
    public function desarquivar(object $categoria, array $dados = []): ?string
    {
        $depois = $this->lerDepois($dados);
        if ($depois !== false && $depois !== null && ($this->buscar($depois) === null || $depois === (int)$categoria->id)) {
            return 'Escolha um degrau da lista.';
        }
        $fim = filter_var($dados['fim'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $this->Categories->getConnection()->transactional(function () use ($categoria, $depois, $fim) {
            $this->Categories->patchEntity($categoria, ['archived_at' => null]);
            $this->Categories->saveOrFail($categoria);
            $naEscada = (bool)$categoria->in_hierarchy;
            if ($naEscada && $depois !== false) {
                $this->posicionar((int)$categoria->id, true, $depois, false);
            } elseif (!$naEscada && $fim) {
                $this->posicionar((int)$categoria->id, false, false, false);
            }
        });

        return null;
    }

    /**
     * Exclui de vez — só quem nunca teve acampamento. Leva junto as perguntas
     * dos formulários dela e o modelo de setores. Null = excluiu.
     */
    public function excluir(object $categoria): ?string
    {
        $teve = $this->Activities->find()
            ->where(['category_id' => $categoria->id, 'deleted IS' => null])
            ->count();
        if ($teve > 0) {
            return 'Esta categoria já teve acampamentos. Para tirá-la da lista sem perder os formulários e as respostas, arquive.';
        }

        $locator = new TableLocator();
        $agora = DateTime::now();
        $this->Categories->getConnection()->transactional(function () use ($categoria, $agora, $locator) {
            $locator->get('Questions')->updateAll(['deleted' => $agora], ['category_id' => $categoria->id, 'deleted IS' => null]);
            $locator->get('CategoriesSectors')->updateAll(['deleted' => $agora], ['category_id' => $categoria->id, 'deleted IS' => null]);
            $this->Categories->updateAll(['required_category_id' => null], ['required_category_id' => $categoria->id]);
            $this->Categories->updateAll(['deleted' => $agora], ['id' => $categoria->id]);
            // Sem buraco na numeração.
            $this->gravarOrdem(array_map(fn($c) => (int)$c->id, $this->todas()));
        });

        return null;
    }

    public function temAcampamentoPorAcontecer(int $categoriaId): bool
    {
        return $this->acampamentosPorAcontecer($categoriaId) > 0;
    }

    // ─── Ordem ──────────────────────────────────────────────────────────────

    /**
     * `depois` dos dados: false = não veio; null = primeiro degrau; int = o id
     * da categoria que fica logo abaixo.
     */
    private function lerDepois(array $dados): int|false|null
    {
        if (!array_key_exists('depois', $dados)) return false;
        $v = $dados['depois'];

        return ($v === null || $v === '' || (int)$v <= 0) ? null : (int)$v;
    }

    /**
     * Põe a categoria no lugar certo da sequência e regrava a ordem.
     * - $manter: continua onde está (salvo se `depois` pedir outro degrau);
     * - na escada: logo acima de `depois` (null = primeiro degrau; não veio =
     *   no topo);
     * - fora da escada: no fim da lista.
     */
    private function posicionar(int $id, bool $naEscada, int|false|null $depois, bool $manter): void
    {
        $todas = $this->todas();
        $seq = array_map(fn($c) => (int)$c->id, $todas);
        $porId = [];
        foreach ($todas as $c) $porId[(int)$c->id] = $c;

        if ($manter && $naEscada && $depois !== false && $this->ativaLogoAbaixo($seq, $porId, $id) === $depois) {
            $depois = false; // pediu o mesmo degrau: não mexe
        }
        if ($manter && ($depois === false || !$naEscada)) {
            $this->gravarOrdem($seq);

            return;
        }

        $seq = array_values(array_filter($seq, fn($x) => $x !== $id));
        if (!$naEscada) {
            $seq[] = $id;
        } elseif ($depois === false) {
            // Entrou na escada sem dizer onde: no topo.
            $ultima = -1;
            foreach ($seq as $i => $x) if ($porId[$x]->in_hierarchy) $ultima = $i;
            array_splice($seq, $ultima + 1, 0, [$id]);
        } elseif ($depois === null) {
            $primeira = 0;
            foreach ($seq as $i => $x) {
                if ($porId[$x]->in_hierarchy) { $primeira = $i; break; }
            }
            array_splice($seq, $primeira, 0, [$id]);
        } else {
            $k = array_search($depois, $seq, true);
            array_splice($seq, $k === false ? count($seq) : $k + 1, 0, [$id]);
        }
        $this->gravarOrdem($seq);
    }

    /** A categoria em uso da escada que fica logo abaixo desta na sequência (null = nenhuma). */
    private function ativaLogoAbaixo(array $seq, array $porId, int $id): ?int
    {
        $achou = null;
        foreach ($seq as $x) {
            if ($x === $id) return $achou;
            if ($porId[$x]->in_hierarchy && $porId[$x]->archived_at === null) $achou = $x;
        }

        return $achou;
    }

    /** Regrava position 1..n: a escada primeiro, depois as de fora, cada bloco na ordem de $seq. */
    private function gravarOrdem(array $seq): void
    {
        $naEscada = [];
        foreach ($this->todas() as $c) $naEscada[(int)$c->id] = (bool)$c->in_hierarchy;
        $escada = array_values(array_filter($seq, fn($x) => $naEscada[$x] ?? false));
        $fora = array_values(array_filter($seq, fn($x) => isset($naEscada[$x]) && !$naEscada[$x]));
        foreach (array_merge($escada, $fora) as $i => $id) {
            $this->Categories->updateAll(['position' => $i + 1], ['id' => $id]);
        }
    }

    // ─── Apoio ──────────────────────────────────────────────────────────────

    private function nomeEmUso(string $nome, mixed $ignorarId): bool
    {
        // A collation da tabela (utf8mb4_unicode_520_ci) já ignora maiúsculas e acentos.
        $q = $this->Categories->find()->where(['name' => $nome, 'type' => 'Acampamento', 'deleted IS' => null]);
        if ($ignorarId !== null) {
            $q->where(['id !=' => (int)$ignorarId]);
        }

        return $q->count() > 0;
    }

    /** Pedir $requer em $id fecharia um círculo (A pede B, B pede A…)? */
    private function formaCiclo(int $id, int $requer): bool
    {
        $vistos = [];
        $atual = $requer;
        while ($atual !== null && !isset($vistos[$atual])) {
            if ($atual === $id) return true;
            $vistos[$atual] = true;
            $atual = $this->buscar($atual)?->required_category_id;
            $atual = $atual === null ? null : (int)$atual;
        }

        return false;
    }

    /**
     * @return array<int, array{total: int, porAcontecer: int, lista: array}>
     *   `lista` = os por acontecer, com as datas que o selo da fase usa
     *   (as mesmas chaves de /activities, para faseDaAtividade no site).
     */
    private function usoDosAcampamentos(?int $categoriaId = null): array
    {
        $q = $this->Activities->find()
            ->select([
                'Activities.id', 'Activities.name', 'Activities.category_id', 'Activities.start_date', 'Activities.duration_days',
                'Activities.participant_registration_start_date', 'Activities.participant_registration_end_date',
            ])
            ->where(['Activities.camping_id IS NOT' => null, 'Activities.category_id IS NOT' => null, 'Activities.deleted IS' => null])
            ->orderBy(['Activities.start_date' => 'ASC', 'Activities.id' => 'ASC']);
        if ($categoriaId !== null) {
            $q->where(['Activities.category_id' => $categoriaId]);
        }

        $hoje = date('Y-m-d');
        $sorteio = null;
        $uso = [];
        foreach ($q->all() as $a) {
            $id = (int)$a->category_id;
            $uso[$id] ??= ['total' => 0, 'porAcontecer' => 0, 'lista' => []];
            $uso[$id]['total']++;
            $ultimoDia = $a->start_date
                ? $a->start_date->modify('+' . max(0, (int)$a->duration_days - 1) . ' days')
                : null;
            // Sem data ainda conta como por acontecer.
            if ($ultimoDia === null || $ultimoDia->format('Y-m-d') >= $hoje) {
                $uso[$id]['porAcontecer']++;
                $sorteio ??= new SorteioService();
                $uso[$id]['lista'][] = [
                    'id'               => (int)$a->id,
                    'nome'             => $a->name,
                    'tipo'             => 'Acampamento',
                    'inicioEvento'     => $a->start_date?->format('Y-m-d\TH:i'),
                    'fimEvento'        => $ultimoDia?->format('Y-m-d\TH:i'),
                    'inicioInscricoes' => $a->participant_registration_start_date?->format('Y-m-d\TH:i'),
                    'fimInscricoes'    => $a->participant_registration_end_date?->format('Y-m-d\TH:i'),
                    'sorteioCampistasRealizado' => $sorteio->campistasJaRealizado((int)$a->id),
                ];
            }
        }

        return $uso;
    }

    /** @return array<string, int> "{categoria}|{Campista|Servo}" => perguntas */
    private function perguntasPorCategoria(): array
    {
        $q = (new TableLocator())->get('Questions')->find();
        $q->select(['category_id', 'subscription_type', 'n' => $q->func()->count('*')])
            ->where(['deleted IS' => null, 'event_form_id IS' => null, 'category_id IS NOT' => null])
            ->groupBy(['category_id', 'subscription_type'])
            ->disableHydration();
        $r = [];
        foreach ($q->all() as $l) {
            $r["{$l['category_id']}|{$l['subscription_type']}"] = (int)$l['n'];
        }

        return $r;
    }

    /** @return array<int, array{setores: int, vagas: int}> modelo de setores (só setores com vaga) */
    private function setoresPorCategoria(): array
    {
        $q = (new TableLocator())->get('CategoriesSectors')->find();
        $q->select([
                'category_id' => 'CategoriesSectors.category_id',
                'setores'     => $q->func()->count('*'),
                'vagas'       => $q->func()->sum('CategoriesSectors.vacancies'),
            ])
            ->innerJoin(['Sectors' => 'sectors'], ['Sectors.id = CategoriesSectors.sector_id', 'Sectors.deleted IS' => null])
            ->where(['CategoriesSectors.deleted IS' => null, 'CategoriesSectors.vacancies >' => 0])
            ->groupBy(['CategoriesSectors.category_id'])
            ->disableHydration();
        $r = [];
        foreach ($q->all() as $l) {
            $r[(int)$l['category_id']] = ['setores' => (int)$l['setores'], 'vagas' => (int)$l['vagas']];
        }

        return $r;
    }

    private function acampamentosPorAcontecer(int $categoriaId): int
    {
        return $this->usoDosAcampamentos($categoriaId)[$categoriaId]['porAcontecer'] ?? 0;
    }
}
