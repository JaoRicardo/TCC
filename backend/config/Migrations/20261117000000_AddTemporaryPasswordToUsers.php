<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Senha provisória gerada pela organização na ficha da pessoa (quando ela
 * esquece a senha):
 *
 * - must_change_password: a pessoa entrou com a senha provisória e ainda não
 *   criou a própria. Enquanto estiver ligado, o servidor só deixa trocar a
 *   senha, ler os próprios dados e sair — nada mais (JwtAuthMiddleware).
 * - temporary_password_at: quando a última senha provisória foi gerada. Os
 *   logins feitos antes disso deixam de valer na hora, para que quem estava
 *   com a sessão antiga aberta não crie a senha no lugar da pessoa.
 */
class AddTemporaryPasswordToUsers extends BaseMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('must_change_password', 'boolean', ['null' => false, 'default' => false, 'after' => 'password'])
            ->addColumn('temporary_password_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'must_change_password'])
            ->update();
    }
}
