<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Documento enviado pela organização na ficha da pessoa (quando ela entregou
 * em papel):
 *
 * - user_documents.uploaded_by: quem enviou o arquivo. Vazio nos antigos, que
 *   foram todos enviados pela própria pessoa.
 *
 * E a permissão "documentos" passa a depender de "Pessoas e acessos" (é na
 * ficha da pessoa que o documento é visto, conferido e trocado). Quem tinha
 * "documentos" sem "usuarios" perde "documentos" — o contrário daria acesso
 * a todas as contas sem ninguém ter escolhido isso.
 */
class AddUploadedByToUserDocuments extends BaseMigration
{
    public function up(): void
    {
        $this->table('user_documents')
            ->addColumn('uploaded_by', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'size'])
            ->addForeignKey('uploaded_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();

        $this->execute(
            "DELETE p FROM user_permissions p
             WHERE p.permission = 'documentos'
               AND NOT EXISTS (
                   SELECT 1 FROM (SELECT user_id FROM user_permissions WHERE permission = 'usuarios') u WHERE u.user_id = p.user_id
               )"
        );
    }

    public function down(): void
    {
        $this->table('user_documents')
            ->dropForeignKey('uploaded_by')
            ->removeColumn('uploaded_by')
            ->update();
    }
}
