<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * O documento de identidade deixa de ficar no cadastro: a pessoa envia junto
 * com o formulário de cada inscrição, todas as vezes, e a organização o vê na
 * aba Respostas. Não há mais conferência separada nem envio pela ficha.
 *
 * - user_documents.pre_registration_id: a inscrição do formulário.
 * - Saem checked_at/checked_by (conferência) e uploaded_by (envio pela ficha).
 * - Os documentos que estavam no cadastro passam para os formulários já
 *   enviados pela pessoa (uma cópia da linha por inscrição, mesmo arquivo),
 *   para a aba Respostas continuar mostrando. Depois saem do cadastro.
 * - "documentos" volta a depender de "Respostas dos formulários", que é onde o
 *   documento aparece; quem não tem essa permissão perde "documentos".
 */
class LinkUserDocumentsToPreRegistrations extends BaseMigration
{
    public function up(): void
    {
        $this->table('user_documents')
            ->addColumn('pre_registration_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'user_id'])
            ->addIndex(['pre_registration_id'])
            ->addForeignKey('pre_registration_id', 'pre_registrations', 'id', ['delete' => 'NO_ACTION', 'update' => 'NO_ACTION'])
            ->update();

        $this->execute(
            "INSERT INTO user_documents (user_id, pre_registration_id, file, original_name, mime, size, created, modified)
             SELECT d.user_id, pr.id, d.file, d.original_name, d.mime, d.size, d.created, NOW()
             FROM user_documents d
             JOIN pre_registrations pr ON pr.user_id = d.user_id AND pr.form_submitted = 1 AND pr.deleted IS NULL
             WHERE d.deleted IS NULL AND d.pre_registration_id IS NULL"
        );
        $this->execute('UPDATE user_documents SET deleted = NOW() WHERE pre_registration_id IS NULL AND deleted IS NULL');

        $this->table('user_documents')
            ->dropForeignKey('checked_by')
            ->dropForeignKey('uploaded_by')
            ->update();
        $this->table('user_documents')
            ->removeColumn('checked_at')
            ->removeColumn('checked_by')
            ->removeColumn('uploaded_by')
            ->update();

        $this->execute(
            "DELETE p FROM user_permissions p
             WHERE p.permission = 'documentos'
               AND NOT EXISTS (
                   SELECT 1 FROM (SELECT user_id FROM user_permissions WHERE permission = 'respostas_formularios') r WHERE r.user_id = p.user_id
               )"
        );
    }

    public function down(): void
    {
        $this->table('user_documents')
            ->addColumn('checked_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'size'])
            ->addColumn('checked_by', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'checked_at'])
            ->addColumn('uploaded_by', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'size'])
            ->addForeignKey('checked_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('uploaded_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->dropForeignKey('pre_registration_id')
            ->removeIndex(['pre_registration_id'])
            ->removeColumn('pre_registration_id')
            ->update();
    }
}
