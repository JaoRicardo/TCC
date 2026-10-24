<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Permissões por tela do painel administrativo.
 *
 * Cada linha libera uma tela (ou ação, como "excluir atividades") para uma
 * pessoa. Ser administrador ou conselheiro passa a ser só o cargo: o que a
 * pessoa vê no painel é decidido por esta lista (ver PermissoesService).
 *
 * Quem já é administrador ganha todas as permissões, para nada mudar para ele.
 */
class CreateUserPermissions extends BaseMigration
{
    /** Mesma lista de App\Service\PermissoesService::TELAS no dia da migration. */
    private const TODAS = [
        'usuarios', 'permissoes', 'eventos', 'acampamentos', 'atividades',
        'excluir_atividades', 'inscricoes', 'equipes', 'setores', 'formularios',
    ];

    public function up(): void
    {
        $this->table('user_permissions')
            ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('permission', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'permission'], ['unique' => true])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $agora = date('Y-m-d H:i:s');
        $linhas = [];
        foreach ($this->fetchAll('SELECT id FROM users WHERE is_admin = 1 AND deleted IS NULL') as $admin) {
            foreach (self::TODAS as $permissao) {
                $linhas[] = ['user_id' => $admin['id'], 'permission' => $permissao, 'created' => $agora];
            }
        }
        if ($linhas) {
            $this->table('user_permissions')->insert($linhas)->saveData();
        }
    }

    public function down(): void
    {
        $this->table('user_permissions')->drop()->save();
    }
}
