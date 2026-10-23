<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use App\Service\JwtService;
use Cake\Http\Response;

#[AllowDynamicProperties]
class AuthController extends ApiController
{
    public function initialize(): void
    {
        parent::initialize();

        /** @var \App\Model\Table\UsersTable $Users */
        $this->Users = $this->fetchTable('Users');
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/login
    // Body: { "cpf": "000.000.000-00", "password": "senha123" }
    // -------------------------------------------------------------------------
    public function login(): Response
    {
        $this->request->allowMethod(['post']);

        $cpf = (string)($this->request->getData('cpf') ?? '');
        $password = (string)($this->request->getData('password') ?? '');

        if ($cpf === '' || $password === '') {
            return $this->apiError('CPF e senha são obrigatórios', 422);
        }

        $user = $this->Users->findByCpf($cpf);

        if ($user === null || !password_verify($password, $user->password)) {
            return $this->apiError('CPF ou senha inválidos', 401);
        }

        [$accessToken, $refreshToken] = $this->issueTokens($user);

        return $this->apiResponse([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => $this->serializeUser($user),
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/refresh
    // Body: { "refresh_token": "..." }
    // -------------------------------------------------------------------------
    public function refresh(): Response
    {
        $this->request->allowMethod(['post']);

        $refreshToken = (string)($this->request->getData('refresh_token') ?? '');

        if ($refreshToken === '') {
            return $this->apiError('Refresh token é obrigatório', 422);
        }

        $payload = JwtService::decode($refreshToken);

        if ($payload === null || ($payload->type ?? '') !== 'refresh') {
            return $this->apiError('Refresh token inválido ou expirado', 401);
        }

        $user = $this->Users->find()
            ->where([
                'id' => $payload->sub,
                'refresh_token' => $refreshToken,
                'deleted IS' => null,
            ])
            ->first();

        if ($user === null) {
            return $this->apiError('Refresh token inválido', 401);
        }

        [$newAccessToken, $newRefreshToken] = $this->issueTokens($user);

        return $this->apiResponse([
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/logout  (requer Authorization: Bearer <token>)
    // -------------------------------------------------------------------------
    public function logout(): Response
    {
        $this->request->allowMethod(['post']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) {
            return $identity;
        }

        $user = $this->Users->get($identity->id);
        $user->access_token = null;
        $user->refresh_token = null;
        $this->Users->save($user);

        return $this->apiResponse(['message' => 'Logout realizado com sucesso']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/auth/me  (requer Authorization: Bearer <token>)
    // -------------------------------------------------------------------------
    public function me(): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) {
            return $identity;
        }

        return $this->apiResponse(['user' => $this->serializeUser($identity)]);
    }

    // -------------------------------------------------------------------------
    // Helper: gera e persiste novos access + refresh tokens
    // -------------------------------------------------------------------------
    private function issueTokens(object $user): array
    {
        $accessToken = JwtService::createAccessToken((int)$user->id);
        $refreshToken = JwtService::createRefreshToken((int)$user->id);

        $user->access_token = $accessToken;
        $user->refresh_token = $refreshToken;
        $this->Users->save($user);

        return [$accessToken, $refreshToken];
    }
}
