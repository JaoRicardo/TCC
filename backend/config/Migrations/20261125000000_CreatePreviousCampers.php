<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Gestão › Participações anteriores (decidido com o usuário em 2026-11-25):
 * quem foi campista antes de o sistema existir. Cadastra-se pelo CPF, mesmo
 * que a pessoa ainda não tenha conta — a ligação com a conta é feita pelo CPF
 * na hora de conferir a inscrição (ParticipacoesService), então vale também
 * para quem criar a conta depois.
 *
 * Conta como "já foi campista na categoria" igual a um acampamento concluído
 * pelo sistema: não deixa ser campista de novo nela, libera servir nela (e nos
 * degraus de baixo da escada) e cumpre o pré-requisito de outra categoria.
 *
 * Também cria a permissão "participacoes", dada a quem tem "permissoes".
 */
class CreatePreviousCampers extends BaseMigration
{
    public function up(): void
    {
        $this->table('previous_campers', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['autoIncrement' => true, 'signed' => false])
            ->addColumn('cpf', 'string', ['limit' => 11])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('category_id', 'biginteger', ['signed' => false])
            ->addColumn('year', 'integer', ['null' => true, 'default' => null])
            ->addColumn('registered_by', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('created', 'datetime')
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('deleted', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['cpf'])
            ->addIndex(['category_id'])
            ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('registered_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->execute(
            "INSERT INTO user_permissions (user_id, permission, created)
             SELECT up.user_id, 'participacoes', NOW()
             FROM user_permissions up
             WHERE up.permission = 'permissoes'
               AND NOT EXISTS (
                   SELECT 1 FROM user_permissions ja
                   WHERE ja.user_id = up.user_id AND ja.permission = 'participacoes'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM user_permissions WHERE permission = 'participacoes'");
        $this->table('previous_campers')->drop()->save();
    }
}
