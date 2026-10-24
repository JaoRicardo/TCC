<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\TableLocator;

/**
 * Permissões por tela do painel administrativo (decidido com o usuário em
 * 2026-10-24):
 *
 * - É a única regra de acesso ao painel: não existem mais os campos de
 *   administrador/conselheiro. Entra no painel quem tem pelo menos uma tela
 *   liberada; "Ativar tudo" faz o papel do antigo "tornar administrador".
 * - Quem tem "permissoes" gerencia as permissões dos outros. Ninguém tira essa
 *   permissão de si mesmo, então o sistema nunca fica sem quem a gerencie.
 * - A pessoa com acesso limitado não deve perceber: as telas que ela não pode
 *   ver simplesmente não aparecem (isso é feito no front).
 */
class PermissoesService
{
    /** Chave => [nome, descrição], na ordem em que aparecem na tela de permissões. */
    public const TELAS = [
        'atividades'         => ['Gestão de Atividades', 'Ver a lista de acampamentos e eventos. É por ela que se abrem Inscritos e Sorteios e Equipes de Servos.'],
        'excluir_atividades' => ['Excluir atividades', 'Apagar um acampamento ou evento da lista.'],
        'inscricoes'         => ['Inscritos e Sorteios', 'Ver inscritos, indicar, fazer os sorteios e chamar suplentes.'],
        'equipes'            => ['Equipes de Servos', 'Montar as equipes de qualquer setor, escolher o servo geral e ligar setores à atividade.'],
        'acampamentos'       => ['Criar e editar acampamento', 'Cadastrar acampamentos e alterar os dados deles.'],
        'eventos'            => ['Criar Evento', 'Cadastrar eventos.'],
        'setores'            => ['Setores de Servos', 'Cadastrar setores e as vagas de cada categoria.'],
        'formularios'        => ['Formulários', 'Montar as perguntas dos formulários.'],
        'usuarios'           => ['Gestão de Usuários', 'Ver e excluir contas de usuários.'],
        'permissoes'         => ['Cargos e permissões', 'Tornar alguém administrador ou conselheiro e escolher o que cada um acessa.'],
    ];

    /** Permissão que só faz sentido junto com outra (a tela só se abre por ela). */
    public const DEPENDE_DE = [
        'excluir_atividades' => 'atividades',
        'inscricoes'         => 'atividades',
        'equipes'            => 'atividades',
        'permissoes'         => 'usuarios',
    ];

    private $Users;
    private $UserPermissions;

    /** @var array<int, string[]> Cache por pessoa, válido durante a requisição. */
    private array $cache = [];

    public function __construct()
    {
        $locator = new TableLocator();
        $this->Users           = $locator->get('Users');
        $this->UserPermissions = $locator->get('UserPermissions');
    }

    /** @return string[] */
    public static function todas(): array
    {
        return array_keys(self::TELAS);
    }

    /**
     * Permissões que valem para a pessoa agora.
     *
     * @return string[]
     */
    public function daPessoa(object $user): array
    {
        $id = (int)$user->id;
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $gravadas = $this->UserPermissions->find()
            ->select(['permission'])
            ->where(['user_id' => $id])
            ->all()
            ->extract('permission')
            ->toArray();

        // Mantém a ordem da lista oficial e ignora chaves que não existem mais.
        return $this->cache[$id] = array_values(array_intersect(self::todas(), $gravadas));
    }

    public function tem(object $user, string $chave): bool
    {
        return in_array($chave, $this->daPessoa($user), true);
    }

    /**
     * Troca as permissões da pessoa pela lista informada. Chaves desconhecidas
     * são ignoradas e as dependências entram junto (ex.: Inscritos e Sorteios
     * liga também a Gestão de Atividades).
     *
     * @param string[] $chaves
     * @return string[] Como ficou.
     */
    public function definir(int $userId, array $chaves): array
    {
        $final = array_values(array_intersect(self::todas(), $chaves));
        foreach ($final as $chave) {
            $base = self::DEPENDE_DE[$chave] ?? null;
            if ($base !== null && !in_array($base, $final, true)) {
                $final[] = $base;
            }
        }
        $final = array_values(array_intersect(self::todas(), $final));

        $this->UserPermissions->getConnection()->transactional(function () use ($userId, $final) {
            $this->UserPermissions->deleteAll(['user_id' => $userId]);
            foreach ($final as $chave) {
                $this->UserPermissions->saveOrFail($this->UserPermissions->newEntity([
                    'user_id'    => $userId,
                    'permission' => $chave,
                ]));
            }
        });
        unset($this->cache[$userId]);

        return $final;
    }

    /** Catálogo para a tela de permissões. */
    public function catalogo(): array
    {
        $itens = [];
        foreach (self::TELAS as $chave => [$nome, $descricao]) {
            $itens[] = [
                'chave'      => $chave,
                'nome'       => $nome,
                'descricao'  => $descricao,
                'depende_de' => self::DEPENDE_DE[$chave] ?? null,
            ];
        }

        return $itens;
    }

    /** A pessoa é a única (com conta ativa) que gerencia permissões? */
    public function ehUltimoGestor(int $userId): bool
    {
        $gestores = $this->UserPermissions->find()
            ->innerJoinWith('Users')
            ->where([
                'UserPermissions.permission' => 'permissoes',
                'Users.deleted IS'           => null,
            ])
            ->all()
            ->extract('user_id')
            ->toArray();

        return count($gestores) === 1 && (int)$gestores[0] === $userId;
    }
}
