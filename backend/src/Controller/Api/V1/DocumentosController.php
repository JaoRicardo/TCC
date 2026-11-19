<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use App\Model\Table\UserDocumentsTable;
use Cake\Http\Response;

/**
 * Arquivos das perguntas do tipo "arquivo" (documento de identidade, receita…).
 *
 * A pessoa envia o arquivo ao responder o formulário (POST
 * /inscricoes/{id}/arquivo, em InscricoesController). No painel, quem vê as
 * respostas ("respostas_formularios") abre o arquivo, numa aba nova. O arquivo
 * fica fora da pasta pública e sai só por esta rota, com o token.
 */
class DocumentosController extends ApiController
{
    // GET /admin/documentos/{id}/arquivo
    public function arquivo(int $id): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('respostas_formularios');
        if ($identity instanceof Response) return $identity;

        $doc = $this->fetchTable('UserDocuments')->find()->where(['id' => $id])->first();
        $caminho = $doc ? UserDocumentsTable::PASTA . DS . basename((string)$doc->file) : null;
        if (!$doc || !is_file($caminho)) {
            return $this->apiError('Arquivo não encontrado', 404);
        }

        return $this->response
            ->withType($doc->mime)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Disposition', 'inline; filename="arquivo.' . UserDocumentsTable::TIPOS[$doc->mime] . '"')
            ->withFile($caminho);
    }
}
