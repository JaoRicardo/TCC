<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PermissoesService;
use Cake\Datasource\EntityInterface;

/**
 * Ficha da pessoa no painel (/admin/pessoas/{id}): quem tem "Pessoas e
 * acessos" vê e corrige o cadastro de alguém, inclusive CPF e nascimento.
 * O CPF de quem usa o painel só muda por quem cuida das permissões, e a ficha
 * não sobrescreve o que outra pessoa salvou depois que ela foi aberta.
 */
class FichaPessoaTest extends RegrasTestCase
{
    private EntityInterface $admin;
    private EntityInterface $pessoa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Antônio Admin', idade: 50, admin: true);
        $this->pessoa = $this->criarUsuario('Ana Souza', idade: 20, sexo: 'F');
    }

    private function ficha(EntityInterface $quem, EntityInterface $deQuem): array
    {
        $this->autenticarComo($quem);
        $this->get("/api/v1/admin/users/{$deQuem->id}");

        return $this->respostaJson();
    }

    /** A ficha como a tela manda: tudo preenchido, com a versão carregada. */
    private function dadosValidos(array $troca = [], ?EntityInterface $deQuem = null): array
    {
        $deQuem ??= $this->pessoa;
        $atual = $this->ficha($this->admin, $deQuem)['data'];

        return array_replace_recursive([
            'versao'            => $atual['versao'],
            'name'              => $atual['name'],
            'cpf'               => $atual['cpf'],
            'birthday'          => $atual['birthday'],
            'sex'               => $atual['sex'],
            'marital_status_id' => $atual['marital_status_id'],
            'phone'             => $atual['phone'],
            'email'             => $atual['email'],
            'address'           => $atual['address'],
        ], $troca);
    }

    private function salvarFicha(EntityInterface $quem, array $dados, ?EntityInterface $deQuem = null): array
    {
        $deQuem ??= $this->pessoa;
        $this->autenticarComo($quem);
        $this->patch("/api/v1/admin/users/{$deQuem->id}", $dados);

        return $this->respostaJson();
    }

    private function usuarioComPermissoes(string $nome, array $permissoes): EntityInterface
    {
        $usuario = $this->criarUsuario($nome, idade: 30);
        (new PermissoesService())->definir((int)$usuario->id, $permissoes);

        return $usuario;
    }

    public function testFichaTrazDadosEEnderecoESoQuemCuidaDasPermissoesVeOAcesso(): void
    {
        $resposta = $this->ficha($this->admin, $this->pessoa);
        $this->assertResponseOk();
        $this->assertSame('Ana Souza', $resposta['data']['name']);
        $this->assertSame('Primavera do Leste', $resposta['data']['address']['city']);
        $this->assertFalse($resposta['data']['usa_painel']);
        $this->assertSame([], $resposta['data']['telas_liberadas']);
        $this->assertNotEmpty($resposta['catalogo']);

        $voluntario = $this->usuarioComPermissoes('Bia Voluntária', ['usuarios']);
        $resposta = $this->ficha($voluntario, $this->pessoa);
        $this->assertResponseOk();
        $this->assertArrayNotHasKey('telas_liberadas', $resposta['data']);
        $this->assertArrayNotHasKey('catalogo', $resposta);

        $semAcesso = $this->criarUsuario('Carlos Participante');
        $this->ficha($semAcesso, $this->pessoa);
        $this->assertResponseCode(403);
    }

    public function testCorrigeCpfNascimentoEEndereco(): void
    {
        $resposta = $this->salvarFicha($this->admin, $this->dadosValidos([
            'cpf'      => '529.982.247-25',
            'birthday' => '2006-04-18',
            'phone'    => '(66) 3498-1234',
            'address'  => ['city' => 'Poxoréu', 'cep' => '78800-000'],
        ]));
        $this->assertResponseOk();
        $this->assertSame('529.982.247-25', $resposta['data']['cpf']);
        $this->assertSame('(66) 3498-1234', $resposta['data']['phone']);

        $salvo = $this->fetchTable('Users')->get($this->pessoa->id);
        $this->assertSame('52998224725', $salvo->cpf);
        $this->assertSame('2006-04-18', $salvo->birthday->format('Y-m-d'));
        $endereco = $this->fetchTable('Addresses')->find()->where(['user_id' => $this->pessoa->id, 'deleted IS' => null])->firstOrFail();
        $this->assertSame('Poxoréu', $endereco->city);
        $this->assertSame('78800000', $endereco->cep);

        // A pessoa passa a entrar com o CPF novo.
        $this->post('/api/v1/auth/login', ['cpf' => '52998224725', 'password' => 'senha123']);
        $this->assertResponseOk();
    }

    public function testRecusaCamposInvalidosSemSalvarNada(): void
    {
        $resposta = $this->salvarFicha($this->admin, $this->dadosValidos([
            'name'     => ' ',
            'cpf'      => '529.982.247-26',
            'birthday' => '2099-01-01',
            'phone'    => '(66) 9999',
            'email'    => 'ana@gmail',
            'address'  => ['cep' => '7880', 'city' => ''],
        ]));
        $this->assertResponseCode(422);
        $this->assertSame('Escreva o nome completo.', $resposta['errors']['name']['_']);
        $this->assertSame('CPF inválido. Confira os números.', $resposta['errors']['cpf']['_']);
        $this->assertArrayHasKey('birthday', $resposta['errors']);
        $this->assertArrayHasKey('phone', $resposta['errors']);
        $this->assertArrayHasKey('email', $resposta['errors']);
        $this->assertSame('CEP incompleto. São 8 números.', $resposta['errors']['address.cep']['_']);
        $this->assertSame('Preencha este campo.', $resposta['errors']['address.city']['_']);

        $this->assertSame('Ana Souza', $this->fetchTable('Users')->get($this->pessoa->id)->name);
    }

    public function testCpfDeOutraContaDizDeQuemE(): void
    {
        $outra = $this->criarUsuario('Maria Aparecida');

        $resposta = $this->salvarFicha($this->admin, $this->dadosValidos(['cpf' => $outra->cpf]));
        $this->assertResponseCode(422);
        $this->assertSame('Este CPF já está em outra conta.', $resposta['errors']['cpf']['_']);
        $this->assertSame(['id' => $outra->id, 'name' => 'Maria Aparecida'], $resposta['outra_conta']);
    }

    public function testNaoSobrescreveQuemSalvouDepois(): void
    {
        $dados = $this->dadosValidos(['phone' => '(66) 99111-2222']);

        // Enquanto o admin editava, a própria pessoa mudou o telefone em "Meu cadastro".
        $this->fetchTable('Users')->updateAll(['phone' => '66998887777', 'modified' => '2030-01-01 10:00:00'], ['id' => $this->pessoa->id]);

        $resposta = $this->salvarFicha($this->admin, $dados);
        $this->assertResponseCode(409);
        $this->assertTrue($resposta['conflito']);
        $this->assertSame('66998887777', $this->fetchTable('Users')->get($this->pessoa->id)->phone);
    }

    public function testCpfDeQuemUsaOPainelSoMudaPorQuemCuidaDasPermissoes(): void
    {
        $voluntario = $this->usuarioComPermissoes('Bia Voluntária', ['usuarios']);
        $servoDoPainel = $this->usuarioComPermissoes('Carlos Coordenador', ['atividades']);

        // Sem "Cargos e permissões": não troca o CPF de quem usa o painel...
        $this->salvarFicha($voluntario, $this->dadosValidos(['cpf' => '529.982.247-25'], $servoDoPainel), $servoDoPainel);
        $this->assertResponseCode(403);
        $this->assertNotSame('52998224725', $this->fetchTable('Users')->get($servoDoPainel->id)->cpf);

        // ...mas corrige os outros dados dele...
        $this->salvarFicha($voluntario, $this->dadosValidos(['email' => 'carlos@exemplo.com'], $servoDoPainel), $servoDoPainel);
        $this->assertResponseOk();

        // ...e o CPF de quem é só participante.
        $this->salvarFicha($voluntario, $this->dadosValidos(['cpf' => '529.982.247-25']));
        $this->assertResponseOk();

        // Quem cuida das permissões troca o de qualquer um.
        $this->salvarFicha($this->admin, $this->dadosValidos(['cpf' => '111.444.777-35'], $servoDoPainel), $servoDoPainel);
        $this->assertResponseOk();
    }

    public function testInscricoesDaPessoaComSituacaoEAndamento(): void
    {
        $atual = $this->criarAcampamento('Juvenil', [], ['start_date' => (new \DateTime('+20 days'))->format('Y-m-d 08:00:00')]);
        $antigo = $this->criarAcampamento('FAC', [], ['start_date' => '2023-07-01 08:00:00', 'year' => 2023]);
        $this->inscrever($this->pessoa, $atual);
        $this->inscrever($this->pessoa, $antigo, 'Campista', ['is_quitter' => true]);

        $this->autenticarComo($this->admin);
        $this->get("/api/v1/admin/users/{$this->pessoa->id}/inscricoes");
        $this->assertResponseOk();
        $lista = $this->respostaJson()['data'];

        $this->assertCount(2, $lista);
        $this->assertSame(['Inscrito', true, 'Campista'], [$lista[0]['status'], $lista[0]['andamento'], $lista[0]['como']]);
        $this->assertSame(['Desistência', false], [$lista[1]['status'], $lista[1]['andamento']]);
    }
}
