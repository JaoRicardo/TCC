<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;

/**
 * Documento de identidade de cada pessoa (ver a migração CreateUserDocuments).
 * Vale o documento ativo mais recente; os trocados ficam com exclusão lógica.
 */
class UserDocumentsTable extends Table
{
    /** Pasta privada dos arquivos (fora de webroot: não há URL pública). */
    public const PASTA = ROOT . DS . 'storage' . DS . 'documentos';

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('user_documents');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType'   => 'INNER',
        ]);
    }

    /** O documento que vale para a pessoa agora, ou null. */
    public function daPessoa(int $userId): ?EntityInterface
    {
        return $this->find()
            ->where(['user_id' => $userId, 'deleted IS' => null])
            ->orderBy(['id' => 'DESC'])
            ->first();
    }

    /**
     * Documentos que valem para várias pessoas de uma vez.
     *
     * @param list<int> $userIds
     * @return array<int, EntityInterface> id da pessoa => documento
     */
    public function dasPessoas(array $userIds): array
    {
        if (!$userIds) return [];
        $porPessoa = [];
        $docs = $this->find()
            ->where(['user_id IN' => $userIds, 'deleted IS' => null])
            ->orderBy(['id' => 'ASC'])
            ->all();
        foreach ($docs as $d) {
            $porPessoa[(int)$d->user_id] = $d;
        }

        return $porPessoa;
    }

    /** Dados do documento para a tela (nunca o caminho do arquivo). */
    public static function resumo(?EntityInterface $doc): ?array
    {
        if (!$doc) return null;

        return [
            'id'           => (int)$doc->id,
            'nome'         => $doc->original_name,
            'tipo'         => $doc->mime === 'application/pdf' ? 'pdf' : 'imagem',
            'enviado_em'   => $doc->created?->format('Y-m-d\TH:i'),
            'conferido_em' => $doc->checked_at?->format('Y-m-d\TH:i'),
        ];
    }
}
