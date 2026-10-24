<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Remove `users.is_admin` e `users.is_counselor`.
 *
 * Desde a lista de permissões por tela (`user_permissions`), os dois campos só
 * duplicavam informação: quem entra no painel e o que vê nele é decidido pela
 * lista. Agora entra no painel quem tem pelo menos uma tela liberada, e "ativar
 * tudo" substitui o antigo "tornar administrador".
 */
class DropRoleColumnsFromUsers extends BaseMigration
{
    public function up(): void
    {
        $this->table('users')
            ->removeColumn('is_admin')
            ->removeColumn('is_counselor')
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->addColumn('is_counselor', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('is_admin', 'boolean', ['null' => false, 'default' => false])
            ->update();

        // Melhor aproximação do que existia: quem tem alguma tela liberada volta
        // como administrador.
        $this->execute('UPDATE users SET is_admin = 1 WHERE id IN (SELECT DISTINCT user_id FROM user_permissions)');
    }
}
