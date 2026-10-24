<?php
declare(strict_types=1);

namespace App\Controller\Api;

use AllowDynamicProperties;
use App\Controller\AppController;
use App\Service\JwtService;
use App\Service\PermissoesService;
use Cake\Http\Response;

#[AllowDynamicProperties]
class AuthController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();

        /** @var \App\Model\Table\UsersTable $Users */
        $this->Users = $this->fetchTable('Users');
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/login
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

        $accessToken = JwtService::createAccessToken((int)$user->id);
        $refreshToken = JwtService::createRefreshToken((int)$user->id);

        $user->refresh_token = $refreshToken;
        $this->Users->save($user);

        return $this->apiResponse([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => $this->serializeUser($user),
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/refresh
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

        $newAccessToken = JwtService::createAccessToken((int)$user->id);
        $newRefreshToken = JwtService::createRefreshToken((int)$user->id);

        $user->refresh_token = $newRefreshToken;
        $this->Users->save($user);

        return $this->apiResponse([
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/logout  (requer Authorization: Bearer <token>)
    // -------------------------------------------------------------------------
    public function logout(): Response
    {
        $this->request->allowMethod(['post']);

        $identity = $this->request->getAttribute('identity');

        if ($identity === null) {
            return $this->apiError('Não autenticado', 401);
        }

        $user = $this->Users->get($identity->id);
        $user->refresh_token = null;
        $this->Users->save($user);

        return $this->apiResponse(['message' => 'Logout realizado com sucesso']);
    }

    // -------------------------------------------------------------------------
    // GET /api/auth/me  (requer Authorization: Bearer <token>)
    // -------------------------------------------------------------------------
    public function me(): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->request->getAttribute('identity');

        if ($identity === null) {
            return $this->apiError('Não autenticado', 401);
        }

        return $this->apiResponse(['user' => $this->serializeUser($identity)]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function apiResponse(array $data, int $status = 200): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function apiError(string $message, int $status): Response
    {
        return $this->apiResponse(['error' => $message], $status);
    }

    private function serializeUser(object $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'cpf' => $user->cpf,
            'email' => $user->email,
            'phone' => $user->phone,
            'birthday' => $user->birthday?->format('Y-m-d'),
            'sex' => $user->sex,
            'is_admin' => $user->is_admin,
            'is_counselor' => $user->is_counselor,
            'photo' => $user->photo,
            // Telas do painel que a pessoa pode ver (o menu é montado só com elas).
            'permissoes' => (new PermissoesService())->daPessoa($user),
        ];
    }
}
