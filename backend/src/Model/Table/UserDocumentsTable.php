<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Documento de identidade enviado com o formulário de cada inscrição (ver as
 * migrações CreateUserDocuments e LinkUserDocumentsToPreRegistrations). Cada
 * inscrição pede o seu: o documento não fica no cadastro nem passa de um
 * formulário para outro. Vale o mais recente; os trocados ficam com exclusão lógica.
 */
class UserDocumentsTable extends Table
{
    /** Pasta privada dos arquivos (fora de webroot: não há URL pública). */
    public const PASTA = ROOT . DS . 'storage' . DS . 'documentos';

    private const TAMANHO_MAXIMO = 5 * 1024 * 1024;
    public const TIPOS = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

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
        $this->belongsTo('PreRegistrations', [
            'foreignKey' => 'pre_registration_id',
        ]);
    }

    /** O documento enviado com o formulário desta inscrição, ou null. */
    public function daInscricao(int $preRegId): ?EntityInterface
    {
        return $this->find()
            ->where(['pre_registration_id' => $preRegId, 'deleted IS' => null])
            ->orderBy(['id' => 'DESC'])
            ->first();
    }

    /**
     * Documentos de várias inscrições de uma vez.
     *
     * @param list<int> $preRegIds
     * @return array<int, EntityInterface> id da inscrição => documento
     */
    public function dasInscricoes(array $preRegIds): array
    {
        if (!$preRegIds) return [];
        $porInscricao = [];
        $docs = $this->find()
            ->where(['pre_registration_id IN' => $preRegIds, 'deleted IS' => null])
            ->orderBy(['id' => 'ASC'])
            ->all();
        foreach ($docs as $d) {
            $porInscricao[(int)$d->pre_registration_id] = $d;
        }

        return $porInscricao;
    }

    /**
     * Confere e guarda o arquivo como o documento da inscrição. Foto (JPG, PNG,
     * WebP) ou PDF de até 5 MB; o tipo é conferido pelo conteúdo, não pelo nome.
     * O anterior desta inscrição, se houver, sai com exclusão lógica.
     *
     * @return \Cake\Datasource\EntityInterface|string o documento, ou a mensagem de erro
     */
    public function guardar(?UploadedFileInterface $arquivo, int $userId, int $preRegId): EntityInterface|string
    {
        if (!$arquivo || $arquivo->getError() === UPLOAD_ERR_NO_FILE) {
            return 'Escolha a foto ou o PDF do documento.';
        }
        if ($arquivo->getError() === UPLOAD_ERR_INI_SIZE || $arquivo->getError() === UPLOAD_ERR_FORM_SIZE
            || $arquivo->getSize() > self::TAMANHO_MAXIMO) {
            return 'O arquivo passou de 5 MB. Tire uma foto com menos resolução ou envie um PDF menor.';
        }
        if ($arquivo->getError() !== UPLOAD_ERR_OK) {
            return 'Não foi possível receber o arquivo. Tente de novo.';
        }

        $temporario = $arquivo->getStream()->getMetadata('uri');
        $mime = $temporario ? (new \finfo(FILEINFO_MIME_TYPE))->file($temporario) : null;
        if (!isset(self::TIPOS[$mime])) {
            return 'Envie uma foto (JPG, PNG ou WebP) ou um PDF do documento.';
        }

        if (!is_dir(self::PASTA)) {
            mkdir(self::PASTA, 0750, true);
        }
        $nomeGuardado = bin2hex(random_bytes(16)) . '.' . self::TIPOS[$mime];
        $arquivo->moveTo(self::PASTA . DS . $nomeGuardado);

        return $this->getConnection()->transactional(function () use ($userId, $preRegId, $arquivo, $mime, $nomeGuardado) {
            $this->updateAll(['deleted' => date('Y-m-d H:i:s')], ['pre_registration_id' => $preRegId, 'deleted IS' => null]);

            return $this->saveOrFail($this->newEntity([
                'user_id'             => $userId,
                'pre_registration_id' => $preRegId,
                'file'                => $nomeGuardado,
                'original_name'       => mb_substr((string)$arquivo->getClientFilename(), 0, 255) ?: 'documento',
                'mime'                => $mime,
                'size'                => (int)$arquivo->getSize(),
            ], ['accessibleFields' => ['*' => true]]));
        });
    }

    /** Dados do documento para a tela (nunca o caminho do arquivo). */
    public static function resumo(?EntityInterface $doc): ?array
    {
        if (!$doc) return null;

        return [
            'id'         => (int)$doc->id,
            'nome'       => $doc->original_name,
            'tipo'       => $doc->mime === 'application/pdf' ? 'pdf' : 'imagem',
            'enviado_em' => $doc->created?->format('Y-m-d\TH:i'),
        ];
    }
}
