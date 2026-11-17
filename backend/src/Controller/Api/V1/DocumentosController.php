<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use App\Model\Table\UserDocumentsTable;
use Cake\Datasource\EntityInterface;
use Cake\Http\Response;

/**
 * Documento de identidade (RG, CNH, certidão de nascimento).
 *
 * - A pessoa envia o próprio documento (/me/documento), na etapa "Seus dados"
 *   do formulário. No Mirim, o responsável envia o da criança, na conta dela.
 * - No painel, só quem tem a permissão "documentos" abre o arquivo, marca como
 *   conferido e, na ficha da pessoa, envia ou troca no lugar dela (quando ela
 *   entregou em papel). O arquivo fica fora da pasta pública e sai só por esta rota.
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

        $novo = $this->receber((int)$identity->id, $identity, false);
        if ($novo instanceof Response) return $novo;

        return $this->apiResponse(['data' => UserDocumentsTable::resumo($novo)], 201);
    }

    /**
     * POST /admin/users/{id}/documento (multipart: "arquivo" e "conferido")
     *
     * A organização envia ou troca o documento no lugar da pessoa. Quem envia
     * normalmente está com o papel na mão, então pode já marcar como conferido.
     */
    public function enviarPorPessoa(int $userId): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('documentos');
        if ($identity instanceof Response) return $identity;

        $existe = $this->fetchTable('Users')->exists(['id' => $userId, 'deleted IS' => null]);
        if (!$existe) return $this->apiError('Usuário não encontrado', 404);

        $conferido = filter_var($this->request->getData('conferido') ?? false, FILTER_VALIDATE_BOOLEAN);
        $novo = $this->receber($userId, $identity, $conferido);
        if ($novo instanceof Response) return $novo;

        return $this->apiResponse(['data' => $this->UserDocuments->resumoComNomes($novo)], 201);
    }

    /**
     * Confere e guarda o arquivo enviado como o documento de $userId. O anterior,
     * se houver, sai com exclusão lógica (fica no histórico).
     */
    private function receber(int $userId, object $enviadoPor, bool $conferido): EntityInterface|Response
    {
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

        return $this->UserDocuments->getConnection()->transactional(function () use ($userId, $enviadoPor, $conferido, $arquivo, $mime, $nomeGuardado, $agora) {
            $this->UserDocuments->updateAll(['deleted' => $agora], ['user_id' => $userId, 'deleted IS' => null]);

            return $this->UserDocuments->saveOrFail($this->UserDocuments->newEntity([
                'user_id'       => $userId,
                'file'          => $nomeGuardado,
                'original_name' => mb_substr((string)$arquivo->getClientFilename(), 0, 255) ?: 'documento',
                'mime'          => $mime,
                'size'          => (int)$arquivo->getSize(),
                'uploaded_by'   => $enviadoPor->id,
                'checked_at'    => $conferido ? $agora : null,
                'checked_by'    => $conferido ? $enviadoPor->id : null,
            ], ['accessibleFields' => ['*' => true]]));
        });
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

        return $this->apiResponse(['data' => $this->UserDocuments->resumoComNomes($this->UserDocuments->get($id))]);
    }
}
