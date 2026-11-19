<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;

/**
 * Pedidos de correção de formulário (ver a migração
 * CreateFormCorrectionsAndCancellation). Um pedido fica aberto (resolved_at
 * null) até a pessoa reenviar, a organização desfazer ou a atividade começar
 * sem a correção.
 */
class FormCorrectionsTable extends Table
{
    public const REENVIADO = 'reenviado';
    public const DESFEITO  = 'desfeito';
    public const RECUSADO  = 'recusado';
    public const CANCELADO = 'cancelado';

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('form_corrections');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('PreRegistrations', ['foreignKey' => 'pre_registration_id', 'joinType' => 'INNER']);
        $this->belongsTo('Solicitantes', ['className' => 'Users', 'foreignKey' => 'requested_by', 'propertyName' => 'solicitante']);
    }

    /** O pedido aberto desta inscrição, ou null. */
    public function aberta(int $preRegId): ?EntityInterface
    {
        return $this->abertas([$preRegId])[$preRegId] ?? null;
    }

    /**
     * Pedidos abertos de várias inscrições, com quem pediu.
     *
     * @param list<int> $preRegIds
     * @return array<int, EntityInterface> id da inscrição => pedido
     */
    public function abertas(array $preRegIds): array
    {
        if (!$preRegIds) return [];
        $mapa = [];
        $pedidos = $this->find()
            ->contain(['Solicitantes'])
            ->where(['FormCorrections.pre_registration_id IN' => $preRegIds, 'FormCorrections.resolved_at IS' => null])
            ->orderBy(['FormCorrections.id' => 'ASC'])
            ->all();
        foreach ($pedidos as $p) {
            $mapa[(int)$p->pre_registration_id] = $p;
        }

        return $mapa;
    }

    /** Fecha o pedido aberto da inscrição, se houver. */
    public function resolver(int $preRegId, string $como, ?int $quem): void
    {
        $this->updateAll(
            ['resolved_at' => date('Y-m-d H:i:s'), 'resolution' => $como, 'resolved_by' => $quem],
            ['pre_registration_id' => $preRegId, 'resolved_at IS' => null]
        );
    }

    /** O pedido no formato das telas (painel e participante). */
    public static function resumo(?EntityInterface $pedido): ?array
    {
        if (!$pedido) return null;

        return [
            'id'        => (int)$pedido->id,
            'pedida_em' => $pedido->created?->format('Y-m-d\TH:i'),
            'por'       => $pedido->solicitante->name ?? null,
            'motivo'    => (string)$pedido->reason,
            'perguntas' => array_map('intval', json_decode((string)$pedido->question_ids, true) ?: []),
            'prazo'     => $pedido->deadline?->format('Y-m-d\TH:i'),
            'atrasada'  => $pedido->deadline !== null && $pedido->deadline->getTimestamp() < time(),
        ];
    }
}
