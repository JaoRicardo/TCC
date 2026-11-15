<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use App\Model\Table\UserDocumentsTable;
use Cake\Http\Response;

/**
 * Documento de identidade (RG, CNH, certidão de nascimento).
 *
 * - A pessoa envia o próprio documento (/me/documento), na etapa "Seus dados"
 *   do formulário. No Mirim, o responsável envia o da criança, na conta dela.
 * - No painel, só quem tem a permissão "documentos" abre o arquivo e marca como
 *   conferido. O arquivo fica fora da pasta pública e sai só por esta rota.
 */
class DocumentosController extends ApiController
{
    private const TAMANHO_MAXIMO = 5 * 1024 * 1024;
    private const TIPOS = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    private UserDocumentsTable $UserDocuments;

    public function initialize(): void
    {
        parent::initialize();
        $this->UserDocuments = $this->fetchTable('UserDocuments');
    }

    // GET /me/documento
    public function meu(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        return $this->apiResponse(['data' => UserDocumentsTable::resumo($this->UserDocuments->daPessoa((int)$identity->id))]);
    }

    /**
     * POST /me/documento (multipart, campo "arquivo")
     *
     * Foto (JPG, PNG, WebP) ou PDF de até 5 MB. O tipo é conferido pelo
     * conteúdo do arquivo, não pelo nome. O documento anterior, se houver, sai
     * com exclusão lógica.
     */
    public function enviar(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $arquivo = $this->request->getUploadedFile('arquivo');
        if (!$arquivo || $arquivo->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->apiError('Escolha a foto ou o PDF do documento.', 422);
        }
        if ($arquivo->getError() === UPLOAD_ERR_INI_SIZE || $arquivo->getError() === UPLOAD_ERR_FORM_SIZE
            || $arquivo->getSize() > self::TAMANHO_MAXIMO) {
            return $this->apiError('O arquivo passou de 5 MB. Tire uma foto com menos resolução ou envie um PDF menor.', 422);
        }
        if ($arquivo->getError() !== UPLOAD_ERR_OK) {
            return $this->apiError('Não foi possível receber o arquivo. Tente de novo.', 422);
        }

        $temporario = $arquivo->getStream()->getMetadata('uri');
        $mime = $temporario ? (new \finfo(FILEINFO_MIME_TYPE))->file($temporario) : null;
        if (!isset(self::TIPOS[$mime])) {
            return $this->apiError('Envie uma foto (JPG, PNG ou WebP) ou um PDF do documento.', 422);
        }

        if (!is_dir(UserDocumentsTable::PASTA)) {
            mkdir(UserDocumentsTable::PASTA, 0750, true);
        }
        $nomeGuardado = bin2hex(random_bytes(16)) . '.' . self::TIPOS[$mime];
        $arquivo->moveTo(UserDocumentsTable::PASTA . DS . $nomeGuardado);

        $agora = date('Y-m-d H:i:s');
        $novo = $this->UserDocuments->getConnection()->transactional(function () use ($identity, $arquivo, $mime, $nomeGuardado, $agora) {
            $this->UserDocuments->updateAll(['deleted' => $agora], ['user_id' => $identity->id, 'deleted IS' => null]);

            return $this->UserDocuments->saveOrFail($this->UserDocuments->newEntity([
                'user_id'       => $identity->id,
                'file'          => $nomeGuardado,
                'original_name' => mb_substr((string)$arquivo->getClientFilename(), 0, 255) ?: 'documento',
                'mime'          => $mime,
                'size'          => (int)$arquivo->getSize(),
            ], ['accessibleFields' => ['*' => true]]));
        });

        return $this->apiResponse(['data' => UserDocumentsTable::resumo($novo)], 201);
    }

    // GET /admin/documentos/{id}/arquivo
    public function arquivo(int $id): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('documentos');
        if ($identity instanceof Response) return $identity;

        $doc = $this->UserDocuments->find()->where(['id' => $id])->first();
        $caminho = $doc ? UserDocumentsTable::PASTA . DS . basename((string)$doc->file) : null;
        if (!$doc || !is_file($caminho)) {
            return $this->apiError('Documento não encontrado', 404);
        }

        return $this->response
            ->withType($doc->mime)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Disposition', 'inline; filename="documento.' . self::TIPOS[$doc->mime] . '"')
            ->withFile($caminho);
    }

    // POST /admin/documentos/{id}/conferir  (conferido: true|false)
    public function conferir(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('documentos');
        if ($identity instanceof Response) return $identity;

        $doc = $this->UserDocuments->find()->where(['id' => $id, 'deleted IS' => null])->first();
        if (!$doc) return $this->apiError('Documento não encontrado', 404);

        $conferido = (bool)($this->request->getData('conferido') ?? true);
        $doc->checked_at = $conferido ? date('Y-m-d H:i:s') : null;
        $doc->checked_by = $conferido ? $identity->id : null;
        $this->UserDocuments->saveOrFail($doc);

        return $this->apiResponse(['data' => UserDocumentsTable::resumo($this->UserDocuments->get($id))]);
    }
}
