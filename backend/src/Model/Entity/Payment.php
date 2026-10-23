<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Payment Entity
 *
 * @property int $id
 * @property int $pre_registration_id
 * @property string $provider
 * @property string|null $preference_id
 * @property string|null $provider_payment_id
 * @property string|null $external_reference
 * @property string $status
 * @property string|null $status_detail
 * @property string $amount
 * @property string|null $payment_method
 * @property string|null $init_point
 * @property string|null $raw_payload
 * @property \Cake\I18n\DateTime|null $paid_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \App\Model\Entity\PreRegistration $pre_registration
 */
class Payment extends Entity
{
    protected array $_accessible = [
        'pre_registration_id' => true,
        'provider' => true,
        'preference_id' => true,
        'provider_payment_id' => true,
        'external_reference' => true,
        'status' => true,
        'status_detail' => true,
        'amount' => true,
        'payment_method' => true,
        'init_point' => true,
        'raw_payload' => true,
        'paid_at' => true,
        'created' => true,
        'modified' => true,
        'deleted' => true,
        'pre_registration' => true,
    ];
}
