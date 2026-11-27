<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\TableLocator;

/**
 * "Já foi campista nesta categoria" (decidido com o usuário em 2026-11-25).
 * É a base das regras de inscrição de acampamento: não ser campista de novo
 * na mesma categoria, poder servir (nela e nos degraus de baixo da escada) e
 * o pré-requisito de outra categoria.
 *
 * Conta de duas formas:
 *
 * - Pelo sistema: só quem ficou na lista do acampamento até ele começar —
 *   titular (sorteado ou indicado, não suplente na fila), enviou o formulário
 *   (quando há perguntas), pagou (quando há taxa) e não desistiu nem foi
 *   tirado (prazo, recusa, cancelamento — inclusive o cancelamento feito no
 *   próprio dia, quando a pessoa não aparece e entra o próximo suplente). É
 *   calculado na hora, então tirar alguém depois do início desfaz a contagem.
 *   Inscrito não sorteado, suplente que não foi chamado e quem perdeu a vaga
 *   não contam.
 * - Cadastro à mão (Gestão › Participações anteriores, tabela
 *   previous_campers): para quem fez acampamentos antes do sistema. **Só de
 *   quem já tem conta** (decisão do usuário em 2026-11-26, LGPD: não se guarda
 *   nem se usa o CPF de quem não se cadastrou). O CPF digitado só serve para
 *   achar a conta; o registro guarda o `user_id`.
 */
class ParticipacoesService
{
    private $PreRegistrations;
    private $PreviousCampers;
    private $Questions;
    private $Users;
    private $Categories;
    private $UserChangeLogs;

    /** @var array<int, bool> categoria => o formulário de campista tem perguntas */
    private array $temFormulario = [];

    public function __construct()
    {
        $locator = new TableLocator();
        $this->PreRegistrations = $locator->get('PreRegistrations');
        $this->PreviousCampers  = $locator->get('PreviousCampers');
        $this->Questions        = $locator->get('Questions');
        $this->Users            = $locator->get('Users');
        $this->Categories       = $locator->get('Categories');
        $this->UserChangeLogs   = $locator->get('UserChangeLogs');
    }

    public static function digitos(mixed $cpf): string
    {
        return preg_replace('/\D/', '', (string)$cpf) ?? '';
    }

    public static function formatarCpf(string $cpf): string
    {
        return strlen($cpf) === 11
            ? substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2)
            : $cpf;
    }

    // ─── Regra ──────────────────────────────────────────────────────────────

    /**
     * Ids das categorias em que a pessoa já foi campista (pelo sistema ou
     * cadastro à mão na conta dela).
     *
     * @return int[]
     */
    public function categoriasEmQueFoiCampista(object $usuario): array
    {
        $ids = array_column($this->peloSistema((int)$usuario->id), 'categoria_id');

        foreach ($this->cadastradasDa((int)$usuario->id) as $p) {
            $ids[] = (int)$p->category_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Acampamentos que a pessoa concluiu como campista pelo sistema.
     *
     * @return list<array{categoria_id: int, atividade: string, ano: ?int}>
     */
    public function peloSistema(int $userId): array
    {
        $inscricoes = $this->PreRegistrations->find()
            ->contain(['Activities'])
            ->where([
                'PreRegistrations.user_id'              => $userId,
                'PreRegistrations.subscription_type IN' => ['Campista', 'Participante'],
                'PreRegistrations.is_quitter'           => false,
                'PreRegistrations.disqualified_at IS'   => null,
                'PreRegistrations.deleted IS'           => null,
                'PreRegistrations.selection_method_id IS NOT' => null,
                'PreRegistrations.substitute_position IS'     => null,
                'Activities.camping_id IS NOT'          => null,
                'Activities.category_id IS NOT'         => null,
                'Activities.deleted IS'                 => null,
                'Activities.start_date <='              => DateTime::now(),
            ])
            ->orderBy(['Activities.start_date' => 'ASC'])
            ->all();

        $concluidos = [];
        foreach ($inscricoes as $i) {
            if (!$this->concluiu($i, $i->activity)) continue;
            $concluidos[] = [
                'categoria_id' => (int)$i->activity->category_id,
                'atividade'    => (string)$i->activity->name,
                'ano'          => $i->activity->start_date ? (int)$i->activity->start_date->format('Y') : null,
            ];
        }

        return $concluidos;
    }

    /**
     * Titular de acampamento que já começou: enviou o formulário (se tem
     * perguntas) e pagou (se tem taxa). Desistência e saídas já ficam de fora
     * da consulta de peloSistema().
     */
    private function concluiu(object $inscricao, object $atividade): bool
    {
        $categoriaId = (int)$atividade->category_id;
        if (!$inscricao->form_submitted && $this->temFormularioDeCampista($categoriaId)) {
            return false;
        }

        return (float)($atividade->participant_fee ?? 0) <= 0 || (bool)$inscricao->is_fee_paid;
    }

    private function temFormularioDeCampista(int $categoriaId): bool
    {
        if (!array_key_exists($categoriaId, $this->temFormulario)) {
            $this->temFormulario[$categoriaId] = $this->Questions->find()
                ->where(FormulariosService::condicoes(['category_id' => $categoriaId, 'subscription_type' => 'Campista', 'event_form_id' => null]) + ['deleted IS' => null])
                ->count() > 0;
        }

        return $this->temFormulario[$categoriaId];
    }

    /**
     * Inscrição de campista que ainda vale em outro acampamento da mesma
     * categoria que não começou: quem está concorrendo, foi sorteado/indicado
     * ou está na fila de suplentes não se inscreve como campista em dois ao
     * mesmo tempo. Inscrito não sorteado (sorteio já feito) está livre.
     */
    public function concorrendoEmOutro(int $userId, int $categoriaId, int $menosAtividadeId): ?object
    {
        $inscricoes = $this->PreRegistrations->find()
            ->contain(['Activities'])
            ->where([
                'PreRegistrations.user_id'              => $userId,
                'PreRegistrations.subscription_type IN' => ['Campista', 'Participante'],
                'PreRegistrations.is_quitter'           => false,
                'PreRegistrations.disqualified_at IS'   => null,
                'PreRegistrations.deleted IS'           => null,
                'PreRegistrations.activity_id !='       => $menosAtividadeId,
                'Activities.category_id'                => $categoriaId,
                'Activities.camping_id IS NOT'          => null,
                'Activities.deleted IS'                 => null,
                'Activities.start_date >'               => DateTime::now(),
            ])
            ->all();

        $sorteio = new SorteioService();
        foreach ($inscricoes as $i) {
            $escolhido = $i->selection_method_id !== null || $i->substitute_position !== null;
            if ($escolhido) return $i->activity;
            // Retirado do sorteio sem indicação não concorre a nada.
            if ($i->removed_from_raffle_at !== null) continue;
            if ($sorteio->sorteioFeitoEm((int)$i->activity_id, 'Campista') === null) return $i->activity;
        }

        return null;
    }

    // ─── Cadastro à mão ─────────────────────────────────────────────────────

    /** @return list<object> */
    private function cadastradasDa(int $userId): array
    {
        return $this->PreviousCampers->find()
            ->where(['user_id' => $userId, 'deleted IS' => null])
            ->all()
            ->toList();
    }

    private function contaDoCpf(string $cpf): ?object
    {
        if ($cpf === '') return null;

        return $this->Users->find()
            ->select(['id', 'name', 'cpf'])
            ->where(['cpf IN' => [$cpf, self::formatarCpf($cpf)], 'deleted IS' => null])
            ->first();
    }

    /** Categorias de acampamento (arquivadas inclusive: continuam valendo para servir). */
    public function categorias(): array
    {
        return array_map(fn($c) => [
            'id'        => (int)$c->id,
            'nome'      => (string)$c->name,
            'arquivada' => $c->archived_at !== null,
        ], (new CategoriasService())->todas());
    }

    /** Todas as cadastradas, da mais recente para a mais antiga. */
    public function listar(): array
    {
        $linhas = $this->PreviousCampers->find()
            ->contain(['Users', 'Categories', 'Registradores'])
            ->where(['PreviousCampers.deleted IS' => null, 'Users.deleted IS' => null])
            ->orderBy(['PreviousCampers.created' => 'DESC', 'PreviousCampers.id' => 'DESC'])
            ->all()
            ->toList();

        return array_map(fn($l) => $this->serializar($l), $linhas);
    }

    /** Nome e CPF vêm da conta (a participação não guarda nenhum dos dois). */
    private function serializar(object $l): array
    {
        return [
            'id'           => (int)$l->id,
            'cpf'          => self::formatarCpf(self::digitos($l->user->cpf ?? '')),
            'nome'         => (string)($l->user->name ?? ''),
            'categoriaId'  => (int)$l->category_id,
            'categoria'    => (string)($l->category->name ?? ''),
            'ano'          => $l->year !== null ? (int)$l->year : null,
            'contaId'      => (int)$l->user_id,
            'registradoPor' => $l->registrador->name ?? null,
            'registradoEm' => $l->created?->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * O que já se sabe de um CPF, para a tela avisar enquanto se digita: a conta
     * e as categorias em que ela já conta como campista. Sem conta, nada — e
     * nada se cadastra (LGPD).
     */
    public function consultar(string $cpf): array
    {
        $conta = $this->contaDoCpf($cpf);
        if ($conta === null) {
            return ['conta' => null, 'sistema' => [], 'cadastradas' => []];
        }
        $nomes = [];
        foreach ($this->categorias() as $c) $nomes[$c['id']] = $c['nome'];

        $sistema = array_map(fn($p) => [
            'categoriaId' => $p['categoria_id'],
            'categoria'   => $nomes[$p['categoria_id']] ?? '',
            'atividade'   => $p['atividade'],
            'ano'         => $p['ano'],
        ], $this->peloSistema((int)$conta->id));

        $cadastradas = array_map(fn($p) => [
            'id'          => (int)$p->id,
            'categoriaId' => (int)$p->category_id,
            'categoria'   => $nomes[(int)$p->category_id] ?? '',
            'ano'         => $p->year !== null ? (int)$p->year : null,
        ], $this->cadastradasDa((int)$conta->id));

        return [
            'conta'       => ['id' => (int)$conta->id, 'nome' => (string)$conta->name],
            'sistema'     => $sistema,
            'cadastradas' => $cadastradas,
        ];
    }

    /** Erro do CPF de quem ainda não tem conta (o mesmo texto que a tela mostra). */
    public const SEM_CONTA = 'Este CPF não tem conta no sistema. Pela LGPD, só dá para cadastrar a participação '
        . 'depois que a pessoa criar a conta no site.';

    /**
     * Cadastra uma participação na conta do CPF informado (o CPF não é guardado).
     *
     * @return array{0: ?array, 1: array<string, string>}
     */
    public function adicionar(array $dados, int $por): array
    {
        $erros = [];
        $cpf = self::digitos($dados['cpf'] ?? '');
        $categoriaId = (int)($dados['categoriaId'] ?? 0);
        $anoTexto = trim((string)($dados['ano'] ?? ''));
        $ano = $anoTexto === '' ? null : (int)$anoTexto;

        $conta = null;
        if ($cpf === '') {
            $erros['cpf'] = 'Informe o CPF.';
        } elseif (!$this->Users->isValidCpf((string)($dados['cpf'] ?? ''))) {
            $erros['cpf'] = 'CPF inválido. Confira os números.';
        } else {
            $conta = $this->contaDoCpf($cpf);
            if ($conta === null) $erros['cpf'] = self::SEM_CONTA;
        }

        $categoria = $categoriaId > 0 ? (new CategoriasService())->buscar($categoriaId) : null;
        if ($categoria === null) $erros['categoriaId'] = 'Escolha a categoria.';

        $anoAtual = (int)date('Y');
        if ($anoTexto !== '' && (!ctype_digit($anoTexto) || $ano < 1950 || $ano > $anoAtual)) {
            $erros['ano'] = "Ano entre 1950 e {$anoAtual}, ou deixe em branco.";
        }

        if ($conta !== null && $categoria !== null) {
            foreach ($this->cadastradasDa((int)$conta->id) as $p) {
                if ((int)$p->category_id === (int)$categoria->id) {
                    $erros['categoriaId'] = "Essa pessoa já está cadastrada como campista em {$categoria->name}.";
                }
            }
            if (!isset($erros['categoriaId'])) {
                foreach ($this->peloSistema((int)$conta->id) as $p) {
                    if ($p['categoria_id'] === (int)$categoria->id) {
                        $erros['categoriaId'] = "Pelo sistema, essa pessoa já foi campista em {$categoria->name} ({$p['atividade']}). Não precisa cadastrar.";
                        break;
                    }
                }
            }
        }

        if ($erros) return [null, $erros];

        $registro = $this->PreviousCampers->saveOrFail($this->PreviousCampers->newEntity([
            'user_id'       => (int)$conta->id,
            'category_id'   => (int)$categoria->id,
            'year'          => $ano,
            'registered_by' => $por,
        ], ['accessibleFields' => ['*' => true]]));

        $this->UserChangeLogs->registrar((int)$conta->id, $por, 'Participação anterior cadastrada: campista em ' . $categoria->name . ($ano ? " ({$ano})" : '') . '.');

        $registro = $this->PreviousCampers->find()
            ->contain(['Users', 'Categories', 'Registradores'])
            ->where(['PreviousCampers.id' => $registro->id])
            ->firstOrFail();

        return [$this->serializar($registro), []];
    }

    /** Remove (soft delete). False se não existe. */
    public function remover(int $id, int $por): bool
    {
        $registro = $this->PreviousCampers->find()
            ->contain(['Categories'])
            ->where(['PreviousCampers.id' => $id, 'PreviousCampers.deleted IS' => null])
            ->first();
        if ($registro === null) return false;

        $this->PreviousCampers->updateAll(['deleted' => DateTime::now()], ['id' => $id]);

        $this->UserChangeLogs->registrar((int)$registro->user_id, $por, 'Participação anterior removida: campista em ' . ($registro->category->name ?? '') . ($registro->year ? " ({$registro->year})" : '') . '.');

        return true;
    }
}
