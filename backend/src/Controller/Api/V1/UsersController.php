<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use App\Service\JwtService;
use Cake\Http\Response;

#[AllowDynamicProperties]
class UsersController extends ApiController
{
    public function initialize(): void
    {
        parent::initialize();

        /** @var \App\Model\Table\UsersTable $Users */
        $this->Users = $this->fetchTable('Users');
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/users
    // Cadastro de novo usuário. Campos obrigatórios:
    //   cpf, name, birthday, sex, phone, email, password, marital_status_id
    // Campos opcionais: photo
    // -------------------------------------------------------------------------
    public function add(): Response
    {
        $this->request->allowMethod(['post']);

        $data = $this->request->getData();

        // Segurança: novos usuários nunca chegam como admin ou conselheiro
        $data['is_admin'] = false;
        $data['is_counselor'] = false;
        unset($data['access_token'], $data['refresh_token']);

        $user = $this->Users->newEntity($data, [
            'fields' => [
                'cpf', 'name', 'birthday', 'sex', 'phone',
                'email', 'password', 'photo', 'marital_status_id',
                'is_admin', 'is_counselor',
            ],
        ]);

        if ($user->hasErrors()) {
            return $this->apiResponse(['errors' => $user->getErrors()], 422);
        }

        if (!$this->Users->save($user)) {
            return $this->apiResponse(['errors' => $user->getErrors()], 422);
        }

        // Auto-login após cadastro
        $accessToken = JwtService::createAccessToken((int)$user->id);
        $refreshToken = JwtService::createRefreshToken((int)$user->id);

        $user->access_token = $accessToken;
        $user->refresh_token = $refreshToken;
        $this->Users->save($user);

        return $this->apiResponse([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 7200,
            'user' => $this->serializeUser($user),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/users  (admin)
    // -------------------------------------------------------------------------
    public function adminIndex(): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $users = $this->Users->find()
            ->contain(['MaritalStatuses'])
            ->where(['Users.deleted IS' => null])
            ->orderBy(['Users.name' => 'ASC'])
            ->all()
            ->toArray();

        return $this->apiResponse(['data' => array_map([$this, 'serializeUserAdmin'], $users)]);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/users/{id}  (admin)
    // -------------------------------------------------------------------------
    public function adminEdit(int $id): Response
    {
        $this->request->allowMethod(['patch']);

        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        $user = $this->Users->find()
            ->contain(['MaritalStatuses'])
            ->where(['Users.id' => $id, 'Users.deleted IS' => null])
            ->first();

        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        $data  = $this->request->getData();
        $patch = [];

        if (isset($data['name']))             $patch['name']             = $data['name'];
        if (isset($data['email']))            $patch['email']            = $data['email'];
        if (isset($data['phone']))            $patch['phone']            = preg_replace('/\D/', '', (string)$data['phone']);
        if (isset($data['birthday']))         $patch['birthday']         = $data['birthday'];
        if (isset($data['sex']))              $patch['sex']              = $data['sex'];
        if (isset($data['marital_status_id'])) $patch['marital_status_id'] = intval($data['marital_status_id']);

        if (isset($data['is_admin']))         $patch['is_admin']         = (bool)$data['is_admin'];
        if (isset($data['is_counselor']))     $patch['is_counselor']     = (bool)$data['is_counselor'];

        $this->Users->patchEntity($user, $patch, ['validate' => false]);

        if (!$this->Users->save($user)) {
            return $this->apiResponse(['errors' => $user->getErrors()], 422);
        }

        $updated = $this->Users->find()->contain(['MaritalStatuses'])->where(['Users.id' => $id])->first();

        return $this->apiResponse(['data' => $this->serializeUserAdmin($updated)]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/users/{id}  (admin — soft delete)
    // -------------------------------------------------------------------------
    public function adminDelete(int $id): Response
    {
        $this->request->allowMethod(['delete']);

        $identity = $this->requireAdmin();
        if ($identity instanceof Response) return $identity;

        if ((int)$identity->id === $id) {
            return $this->apiError('Não é possível excluir sua própria conta', 403);
        }

        $user = $this->Users->find()
            ->where(['Users.id' => $id, 'Users.deleted IS' => null])
            ->first();

        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        $user->deleted = new \DateTime();
        $this->Users->save($user);

        return $this->apiResponse(['message' => 'Usuário removido com sucesso']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/users/me  (requer Authorization: Bearer <token>)
    // Dados completos do usuário logado
    // -------------------------------------------------------------------------
    public function me(): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) {
            return $identity;
        }

        // Recarrega do banco para dados atualizados
        $user = $this->Users->get($identity->id, contain: ['MaritalStatuses']);

        $address = $this->fetchTable('Addresses')->find()
            ->where(['user_id' => $identity->id, 'deleted IS' => null])
            ->first();

        return $this->apiResponse([
            'user' => array_merge($this->serializeUser($user), [
                'marital_status' => $user->marital_status
                    ? ['id' => $user->marital_status->id, 'title' => $user->marital_status->title]
                    : null,
                'address' => $address ? [
                    'street'       => $address->street,
                    'number'       => $address->number,
                    'neighborhood' => $address->neighborhood,
                    'city'         => $address->city,
                    'cep'          => $address->cep,
                ] : null,
                'created' => $user->created?->format('Y-m-d H:i:s'),
            ]),
        ]);
    }

    private function serializeUserAdmin(object $user): array
    {
        return [
            'id'              => $user->id,
            'name'            => $user->name,
            'cpf'             => $this->maskCpf((string)$user->cpf),
            'email'           => $user->email,
            'phone'           => $this->maskPhone((string)$user->phone),
            'birthday'        => $user->birthday?->format('Y-m-d'),
            'sex'             => $user->sex === 'M' ? 'Masculino' : 'Feminino',
            'marital_status'  => $user->marital_status?->title ?? '',
            'marital_status_id' => $user->marital_status_id,
            'is_admin'        => $user->is_admin,
            'is_counselor'    => $user->is_counselor,
            'created'         => $user->created?->format('Y-m-d H:i:s'),
        ];
    }
}
