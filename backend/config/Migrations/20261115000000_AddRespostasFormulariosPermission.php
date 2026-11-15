<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Permissão nova "Respostas dos formulários" (respostas_formularios).
 *
 * As respostas têm dados de saúde, então ninguém ganha a permissão por já ter
 * "Formulários". Só quem gerencia as permissões ("Cargos e permissões", os
 * administradores) recebe junto — eles poderiam se dar a permissão de todo
 * jeito, e assim o administrador continua vendo tudo. Os outros dependem de
 * alguém liberar em Pessoas e acessos.
 */
class AddRespostasFormulariosPermission extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO user_permissions (user_id, permission, created)
             SELECT up.user_id, 'respostas_formularios', NOW()
             FROM user_permissions up
             WHERE up.permission = 'permissoes'
               AND NOT EXISTS (
                   SELECT 1 FROM user_permissions ja
                   WHERE ja.user_id = up.user_id AND ja.permission = 'respostas_formularios'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM user_permissions WHERE permission = 'respostas_formularios'");
    }
}
