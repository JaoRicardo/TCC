<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Prazo para responder o formulário e o que acontece com quem perde esse prazo.
 *
 * - `campings.camper_form_deadline` / `servant_form_deadline`: data-limite fixa,
 *   cadastrada junto das datas de sorteio. Campista e servo têm prazos separados
 *   porque os dois sorteios acontecem em datas diferentes (e os formulários também
 *   são separados por tipo de inscrição).
 * - `pre_registrations.disqualified_at` / `disqualification_reason`: quem não
 *   respondeu até o prazo perde a vaga. O `selection_method_id` é mantido de
 *   propósito — ele registra *como* a pessoa tinha sido escolhida, e é o
 *   `disqualified_at` que diz que a vaga não vale mais. Assim um novo sorteio
 *   não joga essa pessoa de volta no pool por acidente.
 * - `pre_registrations.promoted_at`: quando o suplente assumiu a vaga liberada.
 * - `pre_registrations.form_deadline_override`: prazo individual. Um suplente
 *   promovido depois do prazo geral já ter vencido precisa de um prazo próprio,
 *   senão seria desclassificado no mesmo instante em que foi promovido.
 */
class AddFormDeadlineAndDisqualification extends BaseMigration
{
    public function change(): void
    {
        $this->table('campings')
            ->addColumn('camper_form_deadline', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'raffle_camper_date',
            ])
            ->addColumn('servant_form_deadline', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'raffle_servant_date',
            ])
            ->update();

        $this->table('pre_registrations')
            ->addColumn('disqualified_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'form_submitted',
            ])
            ->addColumn('disqualification_reason', 'string', [
                'limit' => 30,
                'null' => true,
                'default' => null,
                'after' => 'disqualified_at',
            ])
            ->addColumn('promoted_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'disqualification_reason',
            ])
            ->addColumn('form_deadline_override', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'promoted_at',
            ])
            ->update();
    }
}
