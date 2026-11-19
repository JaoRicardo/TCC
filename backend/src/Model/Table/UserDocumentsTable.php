<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Arquivos enviados nas perguntas do tipo "arquivo" (documento de identidade,
 * receita…), ligados à inscrição e à pergunta (ver as migrações
 * CreateUserDocuments, LinkUserDocumentsToPreRegistrations e AddArquivoQuestions).
 * O arquivo sobe na hora em que a pessoa escolhe; a resposta da pergunta
 * (answers.answer) guarda o id dele quando o formulário é enviado. Cada
 * inscrição envia de novo. Trocar o arquivo deixa o anterior com exclusão lógica.
 */
class UserDocumentsTable extends Table
{
    /** Pasta privada dos arquivos (fora de webroot: não há URL pública). */
    public const PASTA = ROOT . DS . 'storage' . DS . 'documentos';

    public const TAMANHO_MAXIMO = 5 * 1024 * 1024;
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
        $this->belongsTo('Questions', [
            'foreignKey' => 'question_id',
        ]);
    }

    /** O arquivo atual de uma pergunta nesta inscrição (o último enviado), ou null. */
    public function daResposta(int $preRegId, int $questionId): ?EntityInterface
    {
        return $this->find()
            ->where(['pre_registration_id' => $preRegId, 'question_id' => $questionId, 'deleted IS' => null])
            ->orderBy(['id' => 'DESC'])
            ->first();
    }

    /**
     * Os arquivos atuais de várias inscrições de uma vez.
     *
     * @param list<int> $preRegIds
     * @return array<int, array<int, EntityInterface>> id da inscrição => id da pergunta => arquivo
     */
    public function dasInscricoes(array $preRegIds): array
    {
        if (!$preRegIds) return [];
        $mapa = [];
        $docs = $this->find()
            ->where(['pre_registration_id IN' => $preRegIds, 'question_id IS NOT' => null, 'deleted IS' => null])
            ->orderBy(['id' => 'ASC'])
            ->all();
        foreach ($docs as $d) {
            $mapa[(int)$d->pre_registration_id][(int)$d->question_id] = $d;
        }

        return $mapa;
    }

    /**
     * Confere e guarda o arquivo como a resposta da pergunta nesta inscrição.
     * Foto (JPG, PNG, WebP) ou PDF de até 5 MB; o tipo é conferido pelo
     * conteúdo, não pelo nome. O anterior desta pergunta sai com exclusão lógica.
     *
     * @return \Cake\Datasource\EntityInterface|string o arquivo, ou a mensagem de erro
     */
    public function guardar(?UploadedFileInterface $arquivo, int $userId, int $preRegId, int $questionId): EntityInterface|string
    {
        if (!$arquivo || $arquivo->getError() === UPLOAD_ERR_NO_FILE) {
            return 'Escolha a foto ou o PDF.';
        }
        if ($arquivo->getError() === UPLOAD_ERR_INI_SIZE || $arquivo->getError() === UPLOAD_ERR_FORM_SIZE
            || $arquivo->getSize() > self::TAMANHO_MAXIMO) {
            return 'O arquivo passou de 5 MB. Tire outra foto ou envie um PDF menor.';
        }
        if ($arquivo->getError() !== UPLOAD_ERR_OK) {
            return 'Não foi possível receber o arquivo. Tente de novo.';
        }

        $temporario = $arquivo->getStream()->getMetadata('uri');
        $mime = $temporario ? (new \finfo(FILEINFO_MIME_TYPE))->file($temporario) : null;
        if (!isset(self::TIPOS[$mime])) {
            $nome = (string)$arquivo->getClientFilename();

            return 'Este tipo de arquivo não é aceito' . ($nome !== '' ? " ({$nome})" : '')
                . '. Envie uma foto (JPG, PNG ou WebP) ou um PDF.';
        }

        if (!is_dir(self::PASTA)) {
            mkdir(self::PASTA, 0750, true);
        }
        $nomeGuardado = bin2hex(random_bytes(16)) . '.' . self::TIPOS[$mime];
        $arquivo->moveTo(self::PASTA . DS . $nomeGuardado);

        return $this->getConnection()->transactional(function () use ($userId, $preRegId, $questionId, $arquivo, $mime, $nomeGuardado) {
            $this->updateAll(
                ['deleted' => date('Y-m-d H:i:s')],
                ['pre_registration_id' => $preRegId, 'question_id' => $questionId, 'deleted IS' => null]
            );

            return $this->saveOrFail($this->newEntity([
                'user_id'             => $userId,
                'pre_registration_id' => $preRegId,
                'question_id'         => $questionId,
                'file'                => $nomeGuardado,
                'original_name'       => mb_substr((string)$arquivo->getClientFilename(), 0, 255) ?: 'arquivo',
                'mime'                => $mime,
                'size'                => (int)$arquivo->getSize(),
            ], ['accessibleFields' => ['*' => true]]));
        });
    }

    /**
     * Apaga do disco os arquivos de formulários que nunca foram enviados, 30 dias
     * depois do início da atividade (o prazo do formulário nunca passa do início).
     * A linha fica, com exclusão lógica.
     *
     * @return int quantos arquivos foram apagados
     */
    public function limparNaoEnviados(): int
    {
        $antigos = $this->find()
            ->innerJoinWith('PreRegistrations.Activities')
            ->where([
                'UserDocuments.file !='           => '',
                'PreRegistrations.form_submitted' => false,
                'Activities.start_date <'         => date('Y-m-d H:i:s', strtotime('-30 days')),
            ])
            ->all();

        $apagados = 0;
        foreach ($antigos as $doc) {
            $caminho = self::PASTA . DS . basename((string)$doc->file);
            if (is_file($caminho)) {
                unlink($caminho);
                $apagados++;
            }
            $this->updateAll(['file' => '', 'deleted' => $doc->deleted ?? date('Y-m-d H:i:s')], ['id' => $doc->id]);
        }

        return $apagados;
    }

    /** Dados do arquivo para a tela (nunca o caminho do arquivo). */
    public static function resumo(?EntityInterface $doc): ?array
    {
        if (!$doc) return null;

        return [
            'id'         => (int)$doc->id,
            'nome'       => $doc->original_name,
            'tipo'       => $doc->mime === 'application/pdf' ? 'pdf' : 'imagem',
            'tamanho'    => (int)$doc->size,
            'enviado_em' => $doc->created?->format('Y-m-d\TH:i'),
        ];
    }
}
