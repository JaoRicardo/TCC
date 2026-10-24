<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AppController;
use App\Service\PermissoesService;
use Cake\Http\Response;

abstract class ApiController extends AppController
{
    protected function apiResponse(array $data, int $status = 200): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function apiError(string $message, int $status): Response
    {
        return $this->apiResponse(['error' => $message], $status);
    }

    protected function serializeUser(object $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'cpf' => $this->maskCpf((string)$user->cpf),
            'email' => $user->email,
            'phone' => $this->maskPhone((string)$user->phone),
            'birthday' => $user->birthday?->format('Y-m-d'),
            'sex' => $user->sex,
            'is_admin' => $user->is_admin,
            'is_counselor' => $user->is_counselor,
            'photo' => $user->photo,
        ];
    }

    protected function maskCpf(string $cpf): string
    {
        $digits = preg_replace('/\D/', '', $cpf);

        if (strlen($digits) !== 11) {
            return $cpf;
        }

        return substr($digits, 0, 3) . '.'
            . substr($digits, 3, 3) . '.'
            . substr($digits, 6, 3) . '-'
            . substr($digits, 9, 2);
    }

    protected function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        $len = strlen($digits);

        if ($len === 11) {
            // celular: (XX) XXXXX-XXXX
            return '(' . substr($digits, 0, 2) . ') '
                . substr($digits, 2, 5) . '-'
                . substr($digits, 7, 4);
        }

        if ($len === 10) {
            // fixo: (XX) XXXX-XXXX
            return '(' . substr($digits, 0, 2) . ') '
                . substr($digits, 2, 4) . '-'
                . substr($digits, 6, 4);
        }

        return $phone;
    }

    protected function requireIdentity(): mixed
    {
        $identity = $this->request->getAttribute('identity');
        if ($identity === null) {
            return $this->apiError('Não autenticado', 401);
        }

        return $identity;
    }

    /**
     * Exige a permissão de uma tela do painel (ver PermissoesService::TELAS).
     * A mensagem é neutra de propósito: quem tem acesso limitado não deve
     * perceber que existe algo que ele não pode ver.
     */
    protected function requirePermissao(string $chave): mixed
    {
        $identity = $this->requireIdentity();
        if ($identity instanceof Response) {
            return $identity;
        }
        if (!$this->temPermissao($identity, $chave)) {
            return $this->apiError('Não foi possível concluir esta ação.', 403);
        }
        return $identity;
    }

    protected function temPermissao(object $identity, string $chave): bool
    {
        return $this->permissoes()->tem($identity, $chave);
    }

    protected function permissoes(): PermissoesService
    {
        return $this->permissoesService ??= new PermissoesService();
    }

    private ?PermissoesService $permissoesService = null;
}
