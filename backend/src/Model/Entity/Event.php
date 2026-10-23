<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Event Entity
 *
 * @property int $id
 * @property int $minimal_age
 * @property bool $is_paid_festival
 * @property int $ticket_price
 * @property \Cake\I18n\DateTime $sale_start_date
 * @property int $payment_link
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $deleted
 */
class Event extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'minimal_age' => true,
        'is_paid_festival' => true,
        'ticket_price' => true,
        'sale_start_date' => true,
        'payment_link' => true,
        'servant_registration_start_date' => true,
        'servant_registration_end_date' => true,
        'servant_vacancies' => true,
        'created' => true,
        'modified' => true,
        'deleted' => true,
    ];
}
