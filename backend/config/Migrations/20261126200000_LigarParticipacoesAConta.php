<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Participações anteriores só de quem tem conta (decidido com o usuário em
 * 2026-11-26, por causa da LGPD): a organização não pode guardar nem usar o
 * CPF de quem ainda não se cadastrou no sistema. A participação passa a ser
 * ligada à conta (`user_id`) e a tabela deixa de guardar CPF e nome — os dois
 * vêm da conta. O CPF digitado na tela só serve para achar a conta.
 *
 * Cadastros antigos: os que têm conta com o mesmo CPF são ligados a ela; os
 * de quem não tem conta são apagados de verdade (não podem ficar guardados).
 */
class LigarParticipacoesAConta extends BaseMigration
{
    public function up(): void
    {
        $this->table('previous_campers')
            ->addColumn('user_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'id'])
            ->update();

        // users.cpf pode estar com pontuação; previous_campers.cpf só tem números.
        $this->execute(
            "UPDATE previous_campers p
             JOIN users u
               ON u.deleted IS NULL
              AND REPLACE(REPLACE(u.cpf, '.', ''), '-', '') COLLATE utf8mb4_unicode_ci = p.cpf COLLATE utf8mb4_unicode_ci
             SET p.user_id = u.id"
        );
        $this->execute('DELETE FROM previous_campers WHERE user_id IS NULL');

        $this->table('previous_campers')
            ->removeIndex(['cpf'])
            ->removeColumn('cpf')
            ->removeColumn('name')
            ->changeColumn('user_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addIndex(['user_id'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('previous_campers')
            ->addColumn('cpf', 'string', ['limit' => 11, 'null' => true, 'default' => null, 'after' => 'id'])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'cpf'])
            ->update();

        $this->execute(
            "UPDATE previous_campers p JOIN users u ON u.id = p.user_id
             SET p.cpf = LEFT(REPLACE(REPLACE(u.cpf, '.', ''), '-', ''), 11), p.name = u.name"
        );

        $this->table('previous_campers')
            ->dropForeignKey('user_id')
            ->removeIndex(['user_id'])
            ->removeColumn('user_id')
            ->addIndex(['cpf'])
            ->update();
    }
}
