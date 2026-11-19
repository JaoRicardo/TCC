<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use App\Model\Table\UserDocumentsTable;
use Cake\Http\Response;

/**
 * Documento de identidade (RG, CNH, certidão de nascimento).
 *
 * A pessoa envia o documento junto com o formulário de cada inscrição
 * (POST /inscricoes/{id}/documento, em InscricoesController). No painel, só
 * quem tem a permissão "documentos" abre o arquivo, na aba Respostas. O arquivo
 * fica fora da pasta pública e sai só por esta rota.
 */
class DocumentosController extends ApiController
{
    // GET /admin/documentos/{id}/arquivo
    public function arquivo(int $id): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('documentos');
        if ($identity instanceof Response) return $identity;

        $doc = $this->fetchTable('UserDocuments')->find()->where(['id' => $id])->first();
        $caminho = $doc ? UserDocumentsTable::PASTA . DS . basename((string)$doc->file) : null;
        if (!$doc || !is_file($caminho)) {
            return $this->apiError('Documento não encontrado', 404);
        }

        return $this->response
            ->withType($doc->mime)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Disposition', 'inline; filename="documento.' . UserDocumentsTable::TIPOS[$doc->mime] . '"')
            ->withFile($caminho);
    }
}
