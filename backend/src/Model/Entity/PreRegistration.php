<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * PreRegistration Entity
 *
 * @property int $id
 * @property string $subscription_type
 * @property bool $is_fee_paid
 * @property string|null $qrcode_data
 * @property bool|null $is_qrcode_used
 * @property int $user_id
 * @property int $activity_id
 * @property int|null $substitute_position
 * @property bool $is_quitter
 * @property bool $form_submitted
 * @property \Cake\I18n\DateTime|null $disqualified_at
 * @property string|null $disqualification_reason
 * @property \Cake\I18n\DateTime|null $promoted_at
 * @property int|null $selection_method_id
 * @property int|null $spouse_id
 * @property int|null $sector_id
 * @property int|null $sector2_id
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \App\Model\Entity\User $user
 * @property \App\Model\Entity\Activity $activity
 * @property \App\Model\Entity\SelectionMethod|null $selection_method
 * @property \App\Model\Entity\Answer[] $answers
 */
class PreRegistration extends Entity
{
    protected array $_accessible = [
        'subscription_type'  => true,
        'is_fee_paid'        => true,
        // Pagamento registrado à mão pela organização (dinheiro ou Pix).
        'fee_paid_on'        => true,
        'fee_payment_method' => true,
        'fee_registered_by'  => true,
        'fee_registered_at'  => true,
        'fee_note'           => true,
        // Pagamento de quem saiu da vaga, passado para o suplente chamado (PagamentoService).
        'fee_inherited_from_id' => true,
        'vacancy_sector_id'  => true,
        'qrcode_data'        => true,
        'is_qrcode_used'     => true,
        'user_id'            => true,
        'activity_id'        => true,
        'substitute_position' => true,
        'is_quitter'         => true,
        'form_submitted'     => true,
        'disqualified_at'    => true,
        'disqualification_reason' => true,
        'removed_from_raffle_at' => true,
        'removed_from_raffle_reason' => true,
        'promoted_at'        => true,
        'selection_method_id' => true,
        'spouse_id'          => true,
        // Casal no acampamento de Casais: confirmação do cônjuge (CasalService).
        'awaiting_spouse'    => true,
        'added_by_spouse'    => true,
        'spouse_confirmed_at' => true,
        'spouse_refused_at'  => true,
        'refusal_seen_at'    => true,
        'sector_id'          => true,
        'sector2_id'         => true,
        'created'            => true,
        'modified'           => true,
        'deleted'            => true,
        'user'               => true,
        'activity'           => true,
        'selection_method'   => true,
        'answers'            => true,
    ];
}
