<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Registro de alterações da ficha da pessoa (fase 6 do handoff da ficha):
 * quem mudou o quê, e quando. author_id null = o sistema. Nunca registre a senha.
 */
class UserChangeLogsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('user_change_logs');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);

        $this->belongsTo('Users', ['foreignKey' => 'user_id', 'joinType' => 'INNER']);
        $this->belongsTo('Autores', ['className' => 'Users', 'foreignKey' => 'author_id', 'propertyName' => 'autor']);
    }

    /** Anota uma alteração na ficha de $userId, feita por $autorId (null = sistema). */
    public function registrar(int $userId, ?int $autorId, string $texto): void
    {
        $this->saveOrFail($this->newEntity(
            ['user_id' => $userId, 'author_id' => $autorId, 'description' => $texto],
            ['accessibleFields' => ['*' => true]]
        ));
    }

    /**
     * As alterações da ficha, da mais recente para a mais antiga.
     *
     * @return list<array{quando: string, quem: string, texto: string}>
     */
    public function daFicha(int $userId, ?int $limite = null): array
    {
        $query = $this->find()
            ->contain(['Autores'])
            ->where(['UserChangeLogs.user_id' => $userId])
            ->orderBy(['UserChangeLogs.created' => 'DESC', 'UserChangeLogs.id' => 'DESC']);
        if ($limite !== null) $query->limit($limite);

        $lista = [];
        foreach ($query->all() as $l) {
            $lista[] = [
                'quando' => $l->created->format('Y-m-d\TH:i'),
                'quem'   => $l->autor->name ?? 'Sistema',
                'texto'  => (string)$l->description,
            ];
        }

        return $lista;
    }

    /** "a data de nascimento, o telefone e o e-mail" */
    public static function juntar(array $itens): string
    {
        $itens = array_values($itens);
        if (count($itens) <= 1) return $itens[0] ?? '';

        return implode(', ', array_slice($itens, 0, -1)) . ' e ' . end($itens);
    }
}
