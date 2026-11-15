<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Documento de identidade da pessoa (RG, CNH, certidão de nascimento), para a
 * organização conferir idade e dados. Fica no cadastro — vale para todos os
 * acampamentos — e é pedido na etapa "Seus dados" do formulário a quem ainda
 * não enviou. Sem documento, o formulário não é enviado.
 *
 * O arquivo fica fora da pasta pública (storage/documentos) e só é aberto pela
 * rota do painel que exige a permissão "documentos". Trocar o documento faz
 * exclusão lógica do anterior.
 *
 * A permissão nova vai para quem gerencia as permissões (os administradores),
 * como na de "Respostas dos formulários".
 */
class CreateUserDocuments extends BaseMigration
{
    public function up(): void
    {
        $this->table('user_documents', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_520_ci',
        ])
            ->addColumn('id', 'biginteger', ['autoIncrement' => true, 'null' => false, 'signed' => false])
            ->addColumn('user_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('file', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('original_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('mime', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('size', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('checked_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('checked_by', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('deleted', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['user_id'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'NO_ACTION', 'update' => 'NO_ACTION'])
            ->addForeignKey('checked_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->execute(
            "INSERT INTO user_permissions (user_id, permission, created)
             SELECT up.user_id, 'documentos', NOW()
             FROM user_permissions up
             WHERE up.permission = 'permissoes'
               AND NOT EXISTS (
                   SELECT 1 FROM user_permissions ja WHERE ja.user_id = up.user_id AND ja.permission = 'documentos'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM user_permissions WHERE permission = 'documentos'");
        $this->table('user_documents')->drop()->save();
    }
}
