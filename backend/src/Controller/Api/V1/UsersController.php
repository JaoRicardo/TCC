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

        unset($data['access_token'], $data['refresh_token']);

        $user = $this->Users->newEntity($data, [
            'fields' => [
                'cpf', 'name', 'birthday', 'sex', 'phone',
                'email', 'password', 'photo', 'marital_status_id',
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

        $identity = $this->requirePermissao('usuarios');
        if ($identity instanceof Response) return $identity;

        $users = $this->Users->find()
            ->contain(['MaritalStatuses'])
            ->where(['Users.deleted IS' => null])
            ->orderBy(['Users.name' => 'ASC'])
            ->all()
            ->toArray();

        $data = array_map([$this, 'serializeUserAdmin'], $users);

        // Quem gerencia permissões vê as telas do painel de cada pessoa e o
        // catálogo (com as áreas), para montar os selos de acesso.
        if (!$this->temPermissao($identity, 'permissoes')) {
            return $this->apiResponse(['data' => $data]);
        }

        foreach ($users as $i => $user) {
            $data[$i]['telas_liberadas'] = $this->permissoes()->daPessoa($user);
        }

        return $this->apiResponse(['data' => $data, 'catalogo' => $this->permissoes()->catalogo()]);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/users/{id}  (admin)
    // -------------------------------------------------------------------------
    public function adminEdit(int $id): Response
    {
        $this->request->allowMethod(['patch']);

        $identity = $this->requirePermissao('usuarios');
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

        $identity = $this->requirePermissao('usuarios');
        if ($identity instanceof Response) return $identity;

        if ((int)$identity->id === $id) {
            return $this->apiError('Não é possível desativar sua própria conta', 403);
        }

        $user = $this->Users->find()
            ->where(['Users.id' => $id, 'Users.deleted IS' => null])
            ->first();

        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        if ($this->permissoes()->ehUltimoGestor($id)) {
            return $this->apiError('Esta é a única pessoa que gerencia permissões. Dê essa permissão a outra pessoa antes.', 422);
        }

        $user->deleted = new \DateTime();
        $this->Users->save($user);

        return $this->apiResponse(['message' => 'Usuário removido com sucesso']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/users/{id}/permissoes  (quem gerencia permissões)
    // A lista de telas e quais estão ligadas para a pessoa.
    // -------------------------------------------------------------------------
    public function adminPermissoes(int $id): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requirePermissao('permissoes');
        if ($identity instanceof Response) return $identity;

        $user = $this->Users->find()->where(['Users.id' => $id, 'Users.deleted IS' => null])->first();
        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        return $this->apiResponse(['data' => [
            'catalogo' => $this->permissoes()->catalogo(),
            'ativas'   => $this->permissoes()->daPessoa($user),
        ]]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/admin/users/{id}/permissoes  body: { permissoes: [...] }
    // -------------------------------------------------------------------------
    public function adminSalvarPermissoes(int $id): Response
    {
        $this->request->allowMethod(['put']);

        $identity = $this->requirePermissao('permissoes');
        if ($identity instanceof Response) return $identity;

        $user = $this->Users->find()->where(['Users.id' => $id, 'Users.deleted IS' => null])->first();
        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        $chaves = array_map('strval', (array)($this->request->getData('permissoes') ?? []));

        // Ninguém tira de si mesmo a permissão de gerenciar permissões — assim o
        // sistema nunca fica sem quem possa devolvê-la.
        if ((int)$identity->id === $id && !in_array('permissoes', $chaves, true)) {
            return $this->apiError('Você não pode tirar de si mesmo a permissão de gerenciar permissões.', 422);
        }

        $ativas = $this->permissoes()->definir($id, $chaves);

        return $this->apiResponse(['data' => ['ativas' => $ativas]]);
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

        return $this->apiResponse(['user' => $this->perfilCompleto((int)$identity->id)]);
    }

    /**
     * PATCH /api/v1/users/me — a própria pessoa corrige ou atualiza o cadastro
     * (tela "Meu cadastro"): nome, telefone, e-mail, sexo, estado civil e
     * endereço. CPF e data de nascimento não mudam por aqui: o CPF é o login e
     * a idade decide a categoria do acampamento — corrigir passa pela organização.
     */
    public function atualizarMe(): Response
    {
        $this->request->allowMethod(['patch', 'put']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $data = $this->request->getData();
        // Telefone guardado só com os números, como no cadastro (a tela mostra com máscara).
        if (isset($data['phone'])) $data['phone'] = preg_replace('/\D/', '', (string)$data['phone']);
        $user = $this->Users->get($identity->id);
        $this->Users->patchEntity($user, $data, [
            'fields' => ['name', 'sex', 'phone', 'email', 'marital_status_id'],
        ]);
        $erros = $user->getErrors();

        // Endereço: os cinco campos juntos (a cidade decide quem entra no sorteio).
        $enderecoNovo = null;
        if (isset($data['address']) && is_array($data['address'])) {
            $enderecoNovo = [];
            $nomes = ['street' => 'rua', 'number' => 'número', 'neighborhood' => 'bairro', 'city' => 'cidade', 'cep' => 'CEP'];
            foreach ($nomes as $campo => $nome) {
                $valor = trim((string)($data['address'][$campo] ?? ''));
                if ($campo === 'cep') $valor = preg_replace('/\D/', '', $valor);
                if ($valor === '') {
                    $erros["address.{$campo}"] = ['_empty' => "Informe {$nome}."];
                }
                $enderecoNovo[$campo] = $valor;
            }
            if ($enderecoNovo['cep'] !== '' && strlen($enderecoNovo['cep']) !== 8) {
                $erros['address.cep'] = ['tamanho' => 'O CEP tem 8 números.'];
            }
        }

        if ($erros) {
            return $this->apiResponse(['error' => 'Confira os campos marcados.', 'errors' => $erros], 422);
        }

        $enderecos = $this->fetchTable('Addresses');
        $ok = $this->Users->getConnection()->transactional(function () use ($user, $enderecoNovo, $enderecos, $identity) {
            if (!$this->Users->save($user)) return false;
            if ($enderecoNovo !== null) {
                $endereco = $enderecos->find()->where(['user_id' => $identity->id, 'deleted IS' => null])->first()
                    ?? $enderecos->newEntity(['user_id' => $identity->id]);
                $enderecos->patchEntity($endereco, $enderecoNovo);
                if (!$enderecos->save($endereco)) return false;
            }

            return true;
        });
        if (!$ok) {
            return $this->apiResponse(['error' => 'Não foi possível salvar o cadastro.', 'errors' => $user->getErrors()], 422);
        }

        return $this->apiResponse(['user' => $this->perfilCompleto((int)$identity->id)]);
    }

    /**
     * POST /api/v1/users/me/senha  (senha_atual, nova_senha)
     * Troca a própria senha, conferindo a atual.
     */
    public function trocarSenha(): Response
    {
        $this->request->allowMethod(['post']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $atual = (string)$this->request->getData('senha_atual');
        $nova  = (string)$this->request->getData('nova_senha');
        $user  = $this->Users->get($identity->id);

        if (!password_verify($atual, (string)$user->password)) {
            return $this->apiError('A senha atual não confere.', 422);
        }
        if (mb_strlen($nova) < 6) {
            return $this->apiError('A nova senha precisa ter pelo menos 6 caracteres.', 422);
        }

        $user->password = $nova;
        $this->Users->saveOrFail($user);

        return $this->apiResponse(['ok' => true]);
    }

    /** Dados do usuário com estado civil e endereço (GET e PATCH /users/me). */
    private function perfilCompleto(int $userId): array
    {
        $user = $this->Users->get($userId, contain: ['MaritalStatuses']);

        $address = $this->fetchTable('Addresses')->find()
            ->where(['user_id' => $userId, 'deleted IS' => null])
            ->first();

        return array_merge($this->serializeUser($user), [
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
            'created'         => $user->created?->format('Y-m-d H:i:s'),
        ];
    }
}
