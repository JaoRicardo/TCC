<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Registro de alterações da ficha da pessoa: quem mudou o quê, e quando.
 * author_id null = o próprio sistema (ex.: inscrição recusada no início do
 * acampamento). Nunca guarda senha; guarda CPF antigo e novo, que é o que
 * permite desfazer uma troca de login indevida.
 */
class CreateUserChangeLogs extends BaseMigration
{
    public function up(): void
    {
        $this->table('user_change_logs', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['autoIncrement' => true, 'signed' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('author_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('description', 'text')
            ->addColumn('created', 'datetime')
            ->addIndex(['user_id', 'created'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('author_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('user_change_logs')->drop()->save();
    }
}
