<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Pagamento que passa para o suplente (decidido com o usuário em 2026-11-26):
 * quem tinha vaga, pagou e saiu (por qualquer motivo) deixa o pagamento na
 * vaga, e o suplente chamado para ela entra sem precisar pagar. Não há
 * devolução de dinheiro.
 *
 * - fee_inherited_from_id: na inscrição de quem foi chamado, a inscrição de
 *   quem saiu e cujo pagamento passou para ela.
 * - vacancy_sector_id: na inscrição do servo que perdeu a vaga, o setor da
 *   equipe de onde ele saiu (a vaga de servo é do setor).
 */
class AddPagamentoHerdado extends BaseMigration
{
    public function change(): void
    {
        $this->table('pre_registrations')
            ->addColumn('fee_inherited_from_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('vacancy_sector_id', 'integer', ['null' => true, 'default' => null])
            ->addIndex(['fee_inherited_from_id'])
            ->update();
    }
}
