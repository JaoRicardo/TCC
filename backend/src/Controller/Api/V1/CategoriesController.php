<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use App\Service\CategoriasService;
use Cake\Http\Response;

/**
 * Categorias dos acampamentos. `GET /categories` é a lista simples (sem as
 * arquivadas) usada por outras telas; o resto é a tela Gestão › Categorias
 * (permissão `categorias`). As regras ficam em CategoriasService.
 */
class CategoriesController extends ApiController
{
    private CategoriasService $categorias;

    public function initialize(): void
    {
        parent::initialize();
        $this->categorias = new CategoriasService();
    }

    /** GET /categories — categorias ativas (acampamento na ordem da lista, depois as de evento). */
    public function index(): Response
    {
        $this->request->allowMethod(['get']);
        $cats = $this->fetchTable('Categories')->find()
            ->where(['deleted IS' => null, 'archived_at IS' => null])
            ->orderBy(['type' => 'ASC', 'position' => 'ASC', 'name' => 'ASC'])
            ->all()
            ->map(fn($c) => [
                'id'   => $c->id,
                'name' => $c->name,
                'type' => $c->type,
            ])
            ->toArray();

        return $this->apiResponse(['data' => array_values($cats)]);
    }

    /** GET /admin/categorias */
    public function lista(): Response
    {
        $this->request->allowMethod(['get']);
        $identity = $this->requirePermissao('categorias');
        if ($identity instanceof Response) return $identity;

        return $this->apiResponse(['data' => $this->categorias->listar()]);
    }

    /** POST /admin/categorias */
    public function criar(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('categorias');
        if ($identity instanceof Response) return $identity;

        [$categoria, $erros] = $this->categorias->salvar(null, (array)$this->request->getData());
        if ($erros) {
            return $this->apiResponse(['error' => 'Confira os campos destacados.', 'erros' => $erros], 422);
        }

        return $this->apiResponse(['data' => $this->categorias->serializar($categoria)], 201);
    }

    /** PATCH /admin/categorias/{id} */
    public function editar(int $id): Response
    {
        $this->request->allowMethod(['patch']);
        $identity = $this->requirePermissao('categorias');
        if ($identity instanceof Response) return $identity;

        $categoria = $this->categorias->buscar($id);
        if ($categoria === null) {
            return $this->apiError('Categoria não encontrada.', 404);
        }

        [$categoria, $erros] = $this->categorias->salvar($categoria, (array)$this->request->getData());
        if ($erros) {
            return $this->apiResponse(['error' => 'Confira os campos destacados.', 'erros' => $erros], 422);
        }

        return $this->apiResponse(['data' => $this->categorias->serializar($categoria)]);
    }

    /** POST /admin/categorias/ordem — body { ids: [...] }: as em uso na ordem nova (arquivadas ficam no lugar). */
    public function ordem(): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('categorias');
        if ($identity instanceof Response) return $identity;

        $ids = $this->request->getData('ids');
        if (!is_array($ids)) {
            return $this->apiError('Envie a lista de categorias na nova ordem.', 422);
        }
        $this->categorias->reordenar($ids);

        return $this->apiResponse(['data' => $this->categorias->listar()]);
    }

    /** POST /admin/categorias/{id}/arquivar */
    public function arquivar(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('categorias');
        if ($identity instanceof Response) return $identity;

        $categoria = $this->categorias->buscar($id);
        if ($categoria === null) {
            return $this->apiError('Categoria não encontrada.', 404);
        }
        $motivo = $this->categorias->arquivar($categoria);
        if ($motivo !== null) {
            return $this->apiResponse(['error' => $motivo, 'codigo' => 'acampamento_por_acontecer'], 422);
        }

        return $this->apiResponse(['data' => $this->categorias->serializar($categoria)]);
    }

    /** POST /admin/categorias/{id}/desarquivar */
    public function desarquivar(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $identity = $this->requirePermissao('categorias');
        if ($identity instanceof Response) return $identity;

        $categoria = $this->categorias->buscar($id);
        if ($categoria === null) {
            return $this->apiError('Categoria não encontrada.', 404);
        }
        // Body opcional: { depois: id|null } escolhe o degrau; { fim: true } manda
        // a de fora da escada para o fim. Sem nada, volta no mesmo lugar ("Desfazer").
        $erro = $this->categorias->desarquivar($categoria, (array)$this->request->getData());
        if ($erro !== null) {
            return $this->apiResponse(['error' => $erro, 'erros' => ['depois' => $erro]], 422);
        }

        return $this->apiResponse(['data' => $this->categorias->serializar($this->categorias->buscar($id))]);
    }

    /** DELETE /admin/categorias/{id} — só quem nunca teve acampamento. */
    public function excluir(int $id): Response
    {
        $this->request->allowMethod(['delete']);
        $identity = $this->requirePermissao('categorias');
        if ($identity instanceof Response) return $identity;

        $categoria = $this->categorias->buscar($id);
        if ($categoria === null) {
            return $this->apiError('Categoria não encontrada.', 404);
        }
        $motivo = $this->categorias->excluir($categoria);
        if ($motivo !== null) {
            return $this->apiResponse(['error' => $motivo, 'codigo' => 'ja_teve_acampamento'], 422);
        }

        return $this->apiResponse(['ok' => true]);
    }

}
