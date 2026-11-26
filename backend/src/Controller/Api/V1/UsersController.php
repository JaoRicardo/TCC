<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use AllowDynamicProperties;
use App\Controller\Api\ApiController;
use App\Model\Table\UserChangeLogsTable;
use App\Service\JwtService;
use App\Service\RetiradaDoSorteioService;
use Cake\Http\Response;

#[AllowDynamicProperties]
class UsersController extends ApiController
{
    public function initialize(): void
    {
        parent::initialize();

        /** @var \App\Model\Table\UsersTable $Users */
        $this->Users = $this->fetchTable('Users');
        /** @var \App\Model\Table\UserChangeLogsTable $Alteracoes */
        $this->Alteracoes = $this->fetchTable('UserChangeLogs');
    }

    /** Quantas alterações a ficha mostra antes de "Ver tudo". */
    private const ALTERACOES_NA_FICHA = 10;

    /** Nome de cada dado da ficha no registro de alterações. */
    private const NOMES_DOS_DADOS = [
        'name'              => 'o nome',
        'cpf'               => 'o CPF',
        'birthday'          => 'a data de nascimento',
        'sex'               => 'o sexo',
        'marital_status_id' => 'o estado civil',
        'phone'             => 'o telefone',
        'email'             => 'o e-mail',
        'address'           => 'o endereço',
    ];

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

    /**
     * GET /api/v1/admin/users/{id} — a ficha da pessoa no painel: dados,
     * endereço e o que a tela precisa para decidir o que mostrar (se a pessoa
     * usa o painel, se é a última que cuida das permissões). As telas liberadas
     * e o catálogo só vão para quem tem "Cargos e permissões".
     */
    public function adminFicha(int $id): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requirePermissao('usuarios');
        if ($identity instanceof Response) return $identity;

        $user = $this->Users->find()->where(['Users.id' => $id, 'Users.deleted IS' => null])->first();
        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        return $this->apiResponse($this->ficha($user, $identity));
    }

    /**
     * PATCH /api/v1/admin/users/{id} — a organização corrige o cadastro de uma
     * pessoa, inclusive CPF e nascimento (que a própria pessoa não muda em "Meu
     * cadastro"). Vem a ficha inteira, com a `versao` que a tela carregou: se
     * alguém salvou depois disso (a própria pessoa, outro voluntário), recusa em
     * vez de sobrescrever.
     *
     * Trocar o CPF de quem usa o painel dá acesso à conta dessa pessoa, então só
     * quem cuida das permissões pode.
     */
    public function adminEdit(int $id): Response
    {
        $this->request->allowMethod(['patch']);

        return $this->editarFicha($id, previa: false);
    }

    /**
     * POST /api/v1/admin/users/{id}/previa — o mesmo corpo do salvar, sem gravar:
     * confere a versão e os campos e diz quais inscrições sairiam do sorteio,
     * para a janela "Conferir antes de salvar" mostrar exatamente o que acontece.
     */
    public function adminPrevia(int $id): Response
    {
        $this->request->allowMethod(['post']);

        return $this->editarFicha($id, previa: true);
    }

    private function editarFicha(int $id, bool $previa): Response
    {
        $identity = $this->requirePermissao('usuarios');
        if ($identity instanceof Response) return $identity;

        $user = $this->Users->find()->where(['Users.id' => $id, 'Users.deleted IS' => null])->first();
        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        $enderecos = $this->fetchTable('Addresses');
        $endereco = $enderecos->find()->where(['user_id' => $id, 'deleted IS' => null])->first();

        $data = $this->request->getData();
        if ((string)($data['versao'] ?? '') !== $this->versaoDaFicha($user, $endereco)) {
            return $this->apiResponse([
                'error'  => 'Esta ficha foi alterada por outra pessoa. Recarregue para ver a versão nova antes de salvar.',
                'conflito' => true,
            ], 409);
        }

        [$dados, $novoEndereco, $erros, $outraConta] = $this->validarFicha($data, $id);

        $cpfMudou = $dados['cpf'] !== (string)$user->cpf;
        if ($cpfMudou && !isset($erros['cpf']) && $this->permissoes()->daPessoa($user)
            && !$this->temPermissao($identity, 'permissoes')) {
            return $this->apiError('Não foi possível concluir esta ação.', 403);
        }

        if ($erros) {
            return $this->apiResponse([
                'error'       => 'Confira os campos marcados.',
                'errors'      => $erros,
                'outra_conta' => $outraConta,
            ], 422);
        }

        // Nascimento, sexo ou cidade corrigidos podem tirar inscrições do sorteio.
        $retirada = new RetiradaDoSorteioService();
        $sorteio = $retirada->prever($id, [
            'birthday'          => $user->birthday?->format('Y-m-d'),
            'sex'               => $user->sex,
            'marital_status_id' => $user->marital_status_id,
            'city'              => $endereco?->city,
        ], $dados + ['city' => $novoEndereco['city']]);

        if ($previa) {
            return $this->apiResponse(['data' => $this->sorteioParaTela($sorteio)]);
        }

        $this->Users->patchEntity($user, $dados, [
            'validate' => false,
            'fields'   => ['name', 'cpf', 'birthday', 'sex', 'marital_status_id', 'phone', 'email'],
        ]);
        $endereco ??= $enderecos->newEntity(['user_id' => $id]);
        $enderecos->patchEntity($endereco, $novoEndereco);

        $mudancas = $this->textoDasMudancas($user, $endereco);

        $retiradas = $this->Users->getConnection()->transactional(function () use ($user, $endereco, $enderecos, $retirada, $sorteio, $mudancas, $identity, $id) {
            $this->Users->saveOrFail($user);
            $enderecos->saveOrFail($endereco);
            if ($mudancas !== null) {
                $this->Alteracoes->registrar($id, (int)$identity->id, $mudancas);
            }
            $this->registrarRetiradas($id, (int)$identity->id, $sorteio['afetadas']);

            return $retirada->aplicar($sorteio['afetadas']);
        });

        return $this->apiResponse($this->ficha($this->Users->get($id), $identity) + ['retiradas' => $retiradas]);
    }

    /**
     * POST /api/v1/admin/users/{id}/senha-provisoria — a pessoa esqueceu a
     * senha: gera uma provisória, fácil de ditar ("Cedro-4827"), que vai só
     * nesta resposta. A senha antiga e os logins abertos deixam de valer na
     * hora, e no próximo acesso a pessoa tem que criar uma senha própria.
     *
     * Ninguém gera para si mesmo (usa "Meu cadastro"), e a senha de quem usa o
     * painel só é gerada por quem cuida das permissões — dá acesso à conta.
     */
    public function adminSenhaProvisoria(int $id): Response
    {
        $this->request->allowMethod(['post']);

        $identity = $this->requirePermissao('usuarios');
        if ($identity instanceof Response) return $identity;

        $user = $this->Users->find()->where(['Users.id' => $id, 'Users.deleted IS' => null])->first();
        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        if ((int)$identity->id === $id
            || ($this->permissoes()->daPessoa($user) && !$this->temPermissao($identity, 'permissoes'))) {
            return $this->apiError('Não foi possível concluir esta ação.', 403);
        }

        $senha = self::senhaFacilDeDitar();
        $user->password = $senha;
        $user->must_change_password = true;
        $user->temporary_password_at = new \DateTime();
        $user->access_token = null;
        $user->refresh_token = null;
        $this->Users->saveOrFail($user);
        $this->Alteracoes->registrar($id, (int)$identity->id, 'Gerou uma senha provisória.');

        return $this->apiResponse(['data' => [
            'senha'               => $senha,
            'senha_provisoria_em' => $user->temporary_password_at->format('Y-m-d H:i:s'),
        ]]);
    }

    /** O resultado de RetiradaDoSorteioService::prever() no formato das telas. */
    private function sorteioParaTela(array $sorteio): array
    {
        return [
            'afetadas' => array_map(fn(array $a) => [
                'id'        => $a['inscricao']->id,
                'atividade' => $a['inscricao']->activity->name,
                'ano'       => $a['inscricao']->activity->year ?? $a['inscricao']->activity->start_date?->format('Y'),
                'como'      => $a['inscricao']->subscription_type,
                'motivo'    => $a['motivo'],
            ], $sorteio['afetadas']),
            'ja_sorteadas' => $sorteio['ja_sorteadas'],
            'saiu_da_area' => $sorteio['saiu_da_area'],
        ];
    }

    /** Palavra comum + hífen + 4 números, sem letras ou números que se confundem (l/I, 0/O, 1). */
    private static function senhaFacilDeDitar(): string
    {
        $palavras = [
            'Cedro', 'Barco', 'Ponte', 'Trigo', 'Manga', 'Serra', 'Cravo', 'Rocha', 'Prata', 'Campo',
            'Verde', 'Pomba', 'Jarra', 'Tempo', 'Fruta', 'Nuvem', 'Mesa', 'Sapato', 'Porta', 'Vento',
            'Arara', 'Banco', 'Cama', 'Dente', 'Feno', 'Gato', 'Morro', 'Neve', 'Pera', 'Ramo',
        ];
        $numeros = '';
        for ($i = 0; $i < 4; $i++) {
            $numeros .= (string)random_int(2, 9);
        }

        return $palavras[random_int(0, count($palavras) - 1)] . '-' . $numeros;
    }

    /**
     * "Alterou a data de nascimento e o telefone." a partir do que mudou na
     * pessoa e no endereço (entidades já com os dados novos, ainda não salvas).
     * O CPF vai com o antigo e o novo: é o que permite desfazer uma troca de
     * login indevida. Sem mudança, null.
     */
    private function textoDasMudancas(object $user, ?object $endereco, string $sufixo = ''): ?string
    {
        $mudou = [];
        foreach (array_keys(self::NOMES_DOS_DADOS) as $campo) {
            if ($campo === 'address') {
                $enderecoMudou = $endereco !== null && ($endereco->isNew() || array_filter(
                    ['cep', 'city'],
                    fn($c) => (string)$endereco->getOriginal($c) !== (string)$endereco->get($c)
                ));
                if ($enderecoMudou) $mudou[] = self::NOMES_DOS_DADOS['address'];
                continue;
            }
            $antes = $user->getOriginal($campo);
            $depois = $user->get($campo);
            if ($antes instanceof \DateTimeInterface) $antes = $antes->format('Y-m-d');
            if ($depois instanceof \DateTimeInterface) $depois = $depois->format('Y-m-d');
            if ((string)$antes === (string)$depois) continue;

            $mudou[] = $campo === 'cpf'
                ? 'o CPF (de ' . $this->maskCpf((string)$antes) . ' para ' . $this->maskCpf((string)$depois) . ')'
                : self::NOMES_DOS_DADOS[$campo];
        }

        return $mudou ? 'Alterou ' . UserChangeLogsTable::juntar($mudou) . $sufixo . '.' : null;
    }

    /** "Saiu do sorteio: Acampamento Sênior 2026." para cada inscrição que saiu ao salvar. */
    private function registrarRetiradas(int $userId, int $autorId, array $afetadas): void
    {
        foreach ($afetadas as $a) {
            $this->Alteracoes->registrar($userId, $autorId, "Saiu do sorteio: {$a['inscricao']->activity->name}.");
        }
    }

    /**
     * GET /api/v1/admin/users/{id}/alteracoes — o registro inteiro ("Ver tudo").
     * A ficha já traz as mais recentes.
     */
    public function adminAlteracoes(int $id): Response
    {
        $this->request->allowMethod(['get']);

        $identity = $this->requirePermissao('usuarios');
        if ($identity instanceof Response) return $identity;

        $user = $this->Users->find()->where(['Users.id' => $id, 'Users.deleted IS' => null])->first();
        if (!$user) return $this->apiError('Usuário não encontrado', 404);

        return $this->apiResponse(['data' => $this->alteracoes($user, null)]);
    }

    /**
     * O registro da ficha, do mais recente para o mais antigo, terminando em
     * "Conta criada." (vem da data de cadastro, não de uma linha gravada).
     *
     * @return array{lista: list<array>, total: int}
     */
    private function alteracoes(object $user, ?int $limite): array
    {
        $lista = $this->Alteracoes->daFicha((int)$user->id, $limite);
        $total = $this->Alteracoes->find()->where(['user_id' => $user->id])->count() + 1;
        if ($limite === null || count($lista) < $limite) {
            $lista[] = [
                'quando' => $user->created?->format('Y-m-d\TH:i'),
                'quem'   => $user->name,
                'texto'  => 'Conta criada.',
            ];
        }

        return ['lista' => $lista, 'total' => $total];
    }

    /** Payload da ficha (GET e PATCH /admin/users/{id}). */
    private function ficha(object $user, object $identity): array
    {
        $endereco = $this->fetchTable('Addresses')->find()
            ->where(['user_id' => $user->id, 'deleted IS' => null])
            ->first();
        $telas = $this->permissoes()->daPessoa($user);

        $dados = [
            'id'                => $user->id,
            'name'              => $user->name,
            'cpf'               => $this->maskCpf((string)$user->cpf),
            'birthday'          => $user->birthday?->format('Y-m-d'),
            'sex'               => $user->sex,
            'marital_status_id' => $user->marital_status_id,
            'phone'             => $this->maskPhone((string)$user->phone),
            'email'             => $user->email,
            'address'           => $endereco ? [
                'cep'  => $endereco->cep,
                'city' => $endereco->city,
            ] : null,
            'created'           => $user->created?->format('Y-m-d H:i:s'),
            'versao'            => $this->versaoDaFicha($user, $endereco),
            // Quem usa o painel tem o CPF e a senha protegidos: só quem cuida das
            // permissões mexe neles.
            'usa_painel'        => $telas !== [],
            // Senha provisória gerada e ainda não trocada pela pessoa.
            'senha_provisoria_em' => $user->must_change_password ? $user->temporary_password_at?->format('Y-m-d H:i:s') : null,
            'ultimo_gestor'     => $this->permissoes()->ehUltimoGestor((int)$user->id),
            // Registro de alterações: as mais recentes; "Ver tudo" busca o resto.
            'alteracoes'        => $this->alteracoes($user, self::ALTERACOES_NA_FICHA),
        ];

        if (!$this->temPermissao($identity, 'permissoes')) {
            return ['data' => $dados];
        }
        $dados['telas_liberadas'] = $telas;

        return ['data' => $dados, 'catalogo' => $this->permissoes()->catalogo()];
    }

    /** Muda sempre que a pessoa ou o endereço dela são salvos. */
    private function versaoDaFicha(object $user, ?object $endereco): string
    {
        $datas = array_filter([$user->modified?->format('Y-m-d H:i:s'), $endereco?->modified?->format('Y-m-d H:i:s')]);

        return $datas ? max($datas) : '';
    }

    /**
     * Confere a ficha enviada pelo painel. Todos os campos são obrigatórios; as
     * mensagens são as da tela, para aparecerem embaixo de cada campo.
     *
     * @return array{0: array, 1: array, 2: array, 3: ?array} dados da pessoa, endereço, erros e a conta que já usa o CPF
     */
    private function validarFicha(array $data, int $id): array
    {
        $texto = fn(string $campo, ?array $de = null) => trim((string)(($de ?? $data)[$campo] ?? ''));
        $digitos = fn(string $valor) => preg_replace('/\D/', '', $valor);

        $dados = [
            'name'              => (string)preg_replace('/\s+/u', ' ', $texto('name')),
            'cpf'               => $digitos($texto('cpf')),
            'birthday'          => $texto('birthday'),
            'sex'               => $texto('sex'),
            'marital_status_id' => (int)($data['marital_status_id'] ?? 0),
            'phone'             => $digitos($texto('phone')),
            'email'             => $texto('email'),
        ];
        $endereco = is_array($data['address'] ?? null) ? $data['address'] : [];
        $novoEndereco = [
            'cep'  => $digitos($texto('cep', $endereco)),
            'city' => $texto('city', $endereco),
        ];

        $erros = [];
        $erro = function (string $campo, string $mensagem) use (&$erros) {
            $erros[$campo] ??= ['_' => $mensagem];
        };

        foreach ($dados as $campo => $valor) {
            if ($valor === '' || $valor === 0) $erro($campo, 'Preencha este campo.');
        }
        foreach ($novoEndereco as $campo => $valor) {
            if ($valor === '') $erro("address.{$campo}", 'Preencha este campo.');
        }
        if ($dados['name'] === '') $erros['name'] = ['_' => 'Escreva o nome completo.'];
        elseif ($this->Users->nomeTemNumero($dados['name'])) $erros['name'] = ['_' => 'O nome não pode ter números.'];

        $outraConta = null;
        // Confere o que foi digitado, não só os números (letras no meio = inválido).
        if ($dados['cpf'] !== '' && !$this->Users->isValidCpf((string)($data['cpf'] ?? ''))) {
            $erro('cpf', 'CPF inválido. Confira os números.');
        } elseif ($dados['cpf'] !== '') {
            $dono = $this->Users->find()
                ->select(['id', 'name'])
                ->where(['cpf' => $dados['cpf'], 'id !=' => $id, 'deleted IS' => null])
                ->first();
            if ($dono) {
                $erro('cpf', 'Este CPF já está em outra conta.');
                $outraConta = ['id' => $dono->id, 'name' => $dono->name];
            }
        }

        if ($dados['birthday'] !== '') {
            $nascimento = \DateTime::createFromFormat('!Y-m-d', $dados['birthday']);
            $valida = $nascimento && $nascimento->format('Y-m-d') === $dados['birthday']
                && (int)$nascimento->format('Y') >= 1900 && $nascimento <= new \DateTime('today');
            if (!$valida) $erro('birthday', 'Data inválida. Use dia/mês/ano, por exemplo 18/11/2005.');
        }
        if ($dados['sex'] !== '' && !in_array($dados['sex'], ['M', 'F'], true)) {
            $erro('sex', 'Preencha este campo.');
        }
        if ($dados['marital_status_id'] > 0
            && !$this->fetchTable('MaritalStatuses')->exists(['id' => $dados['marital_status_id']])) {
            $erro('marital_status_id', 'Preencha este campo.');
        }
        if ($dados['phone'] !== '' && (strlen($dados['phone']) < 10 || strlen($dados['phone']) > 11)) {
            $erro('phone', 'Telefone incompleto. Use o DDD e o número, por exemplo (66) 99714-8315.');
        }
        if ($dados['email'] !== '' && !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $dados['email'])) {
            $erro('email', 'E-mail inválido. Confira se tem @ e o final, como nome@gmail.com.');
        }
        if ($novoEndereco['cep'] !== '' && strlen($novoEndereco['cep']) !== 8) {
            $erro('address.cep', 'CEP incompleto. São 8 números.');
        }

        return [$dados, $novoEndereco, $erros, $outraConta];
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
        $this->Alteracoes->registrar($id, (int)$identity->id, 'Desativou a conta.');

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

        $antes = $this->permissoes()->daPessoa($user);
        $ativas = $this->permissoes()->definir($id, $chaves);
        if ($antes != $ativas) {
            $this->Alteracoes->registrar($id, (int)$identity->id, 'Alterou as permissões do painel.');
        }

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
        $enderecos = $this->fetchTable('Addresses');
        $enderecoAtual = $enderecos->find()->where(['user_id' => $identity->id, 'deleted IS' => null])->first();
        $antes = [
            'birthday'          => $user->birthday?->format('Y-m-d'),
            'sex'               => $user->sex,
            'marital_status_id' => $user->marital_status_id,
            'city'              => $enderecoAtual?->city,
        ];
        $this->Users->patchEntity($user, $data, [
            'fields' => ['name', 'sex', 'phone', 'email', 'marital_status_id'],
        ]);
        $erros = $user->getErrors();

        // Endereço: cidade e CEP juntos (a cidade decide quem entra no sorteio).
        $enderecoNovo = null;
        if (isset($data['address']) && is_array($data['address'])) {
            $enderecoNovo = [];
            $nomes = ['city' => 'a cidade', 'cep' => 'o CEP'];
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

        // Mesma regra da ficha no painel: mudar o sexo ou a cidade pode tirar do
        // sorteio as inscrições que ainda aguardam sorteio. A pessoa vê quais
        // antes, e só grava quando confirma.
        $retirada = new RetiradaDoSorteioService();
        $sorteio = $retirada->prever((int)$identity->id, $antes, [
            'birthday'          => $antes['birthday'],
            'sex'               => $user->sex,
            'marital_status_id' => $user->marital_status_id,
            'city'              => $enderecoNovo['city'] ?? $antes['city'],
        ]);
        if ($sorteio['afetadas'] && !filter_var($data['confirmar_saida_do_sorteio'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $this->apiResponse([
                'error'             => 'Com estes dados, você sai do sorteio de algumas inscrições.',
                'confirmar_sorteio' => true,
                'sorteio'           => $this->sorteioParaTela($sorteio),
            ], 409);
        }

        $endereco = null;
        if ($enderecoNovo !== null) {
            $endereco = $enderecoAtual ?? $enderecos->newEntity(['user_id' => $identity->id]);
            $enderecos->patchEntity($endereco, $enderecoNovo);
        }
        $mudancas = $this->textoDasMudancas($user, $endereco, ' (Meu cadastro)');

        $ok = $this->Users->getConnection()->transactional(function () use ($user, $endereco, $enderecos, $identity, $retirada, $sorteio, $mudancas) {
            if (!$this->Users->save($user)) return false;
            if ($endereco !== null && !$enderecos->save($endereco)) return false;
            if ($mudancas !== null) {
                $this->Alteracoes->registrar((int)$identity->id, (int)$identity->id, $mudancas);
            }
            $this->registrarRetiradas((int)$identity->id, (int)$identity->id, $sorteio['afetadas']);
            $retirada->aplicar($sorteio['afetadas']);

            return true;
        });
        if (!$ok) {
            return $this->apiResponse(['error' => 'Não foi possível salvar o cadastro.', 'errors' => $user->getErrors()], 422);
        }

        return $this->apiResponse([
            'user'      => $this->perfilCompleto((int)$identity->id),
            'retiradas' => count($sorteio['afetadas']),
        ]);
    }

    /**
     * POST /api/v1/users/me/senha  (senha_atual, nova_senha)
     * Troca a própria senha, conferindo a atual. Quem entrou com a senha
     * provisória acabou de digitá-la no login, então não precisa repetir: só
     * cria a nova, que não pode ser a provisória.
     */
    public function trocarSenha(): Response
    {
        $this->request->allowMethod(['post']);

        $identity = $this->requireIdentity();
        if ($identity instanceof Response) return $identity;

        $atual = (string)$this->request->getData('senha_atual');
        $nova  = (string)$this->request->getData('nova_senha');
        $user  = $this->Users->get($identity->id);

        $provisoria = (bool)$user->must_change_password;

        if (!$provisoria && !password_verify($atual, (string)$user->password)) {
            return $this->apiError('A senha atual não confere.', 422);
        }
        if (mb_strlen($nova) < 6) {
            return $this->apiError('A nova senha precisa ter pelo menos 6 caracteres.', 422);
        }
        if ($provisoria && password_verify($nova, (string)$user->password)) {
            return $this->apiError('Escolha uma senha diferente da senha provisória.', 422);
        }

        $user->password = $nova;
        $user->must_change_password = false;
        $this->Users->saveOrFail($user);
        // Nunca a senha: só que ela foi trocada.
        $this->Alteracoes->registrar(
            (int)$identity->id,
            (int)$identity->id,
            $provisoria ? 'Criou uma senha própria no lugar da provisória.' : 'Trocou a senha (Meu cadastro).'
        );

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
                'city' => $address->city,
                'cep'  => $address->cep,
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
