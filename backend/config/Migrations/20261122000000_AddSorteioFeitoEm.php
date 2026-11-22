<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Sorteio só manual (handoff design_handoff_painel_acampamento).
 *
 * Não existe mais sorteio automático: a data do sorteio no acampamento passa a
 * ser só a data prevista. Estas colunas guardam quando a organização fez o
 * sorteio (em Inscritos e Sorteios) — é isso que tira o selo "Aguardando
 * sorteio" e o que a tela Editar Acampamento mostra ("feito em {data}").
 * Zerar o sorteio limpa a data.
 *
 * Sorteios feitos antes desta migração ficam sem data: a tela usa o próprio
 * resultado (sorteados/suplentes) para saber que já foi feito. Os que rodaram
 * sozinhos herdam a data da execução automática.
 *
 * A "matrícula" do campista (camper_registration_*) saiu da tela e não é usada
 * em lugar nenhum: as colunas passam a aceitar vazio.
 */
class AddSorteioFeitoEm extends BaseMigration
{
    public function up(): void
    {
        $this->table('campings')
            ->addColumn('camper_raffle_done_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('servant_raffle_done_at', 'datetime', ['null' => true, 'default' => null])
            ->changeColumn('camper_registration_start_date', 'datetime', ['null' => true, 'default' => null])
            ->changeColumn('camper_registration_end_date', 'datetime', ['null' => true, 'default' => null])
            ->update();

        $this->execute('UPDATE campings SET camper_raffle_done_at = camper_raffle_auto_ran_at WHERE camper_raffle_auto_ran_at IS NOT NULL');
        $this->execute('UPDATE campings SET servant_raffle_done_at = servant_raffle_auto_ran_at WHERE servant_raffle_auto_ran_at IS NOT NULL');
    }

    public function down(): void
    {
        $this->table('campings')
            ->removeColumn('camper_raffle_done_at')
            ->removeColumn('servant_raffle_done_at')
            ->update();
    }
}
