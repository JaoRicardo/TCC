<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * `salvarFormulario()`/`calcularStatus()` já dependiam de `form_submitted`, mas a
 * coluna nunca chegou a ser criada — o status "Formulário Enviado" nunca disparava
 * e salvar o formulário quebrava com "Unknown column". Corrigido aqui.
 */
class AddFormSubmittedToPreRegistrations extends BaseMigration
{
    public function change(): void
    {
        $this->table('pre_registrations')
            ->addColumn('form_submitted', 'boolean', ['null' => false, 'default' => false, 'after' => 'is_quitter'])
            ->update();
    }
}
