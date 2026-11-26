<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use Cake\Datasource\EntityInterface;

/**
 * "Meu cadastro": a própria pessoa corrige ou atualiza os dados (nome,
 * telefone, e-mail, sexo, estado civil e endereço) e troca a senha.
 * CPF (o login) e nascimento (decide a categoria) não mudam por aqui.
 */
class MeuCadastroTest extends RegrasTestCase
{
    private EntityInterface $pessoa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pessoa = $this->criarUsuario('Ana Souza', idade: 20, sexo: 'F');
    }

    private function atualizar(array $dados): array
    {
        $this->autenticarComo($this->pessoa);
        $this->patch('/api/v1/users/me', $dados);

        return $this->respostaJson();
    }

    public function testAtualizaDadosEEndereco(): void
    {
        $antes = $this->fetchTable('Users')->get($this->pessoa->id);

        $resposta = $this->atualizar([
            'name' => 'Ana Souza Lima',
            'phone' => '(66) 99876-5432',
            'email' => 'ana.lima@exemplo.com',
            'birthday' => '1990-03-15', // ignorado
            'marital_status_id' => $this->estadosCivis['Namorando'],
            'cpf' => '52998224725', // ignorado
            'address' => ['city' => 'Poxoréu', 'cep' => '78800-000'],
        ]);
        $this->assertResponseOk();
        $this->assertSame('Ana Souza Lima', $resposta['user']['name']);
        $this->assertSame('Poxoréu', $resposta['user']['address']['city']);

        $salvo = $this->fetchTable('Users')->get($this->pessoa->id);
        $this->assertSame('66998765432', $salvo->phone, 'Telefone guardado só com os números.');
        $this->assertSame($antes->birthday->format('Y-m-d'), $salvo->birthday->format('Y-m-d'), 'O nascimento não muda por esta tela.');
        $this->assertSame($antes->cpf, $salvo->cpf, 'O CPF não muda por esta tela.');

        $enderecos = $this->fetchTable('Addresses')->find()->where(['user_id' => $this->pessoa->id, 'deleted IS' => null])->all();
        $this->assertCount(1, $enderecos, 'Atualiza o endereço que já existe, não cria outro.');
        $this->assertSame('78800000', $enderecos->first()->cep);
    }

    public function testRecusaDadosInvalidosSemSalvarNada(): void
    {
        $resposta = $this->atualizar([
            'name' => 'Outro Nome',
            'email' => 'isto-nao-e-email',
            'address' => ['city' => '', 'cep' => '123'],
        ]);
        $this->assertResponseCode(422);
        $this->assertArrayHasKey('email', $resposta['errors']);
        $this->assertArrayHasKey('address.city', $resposta['errors']);
        $this->assertArrayHasKey('address.cep', $resposta['errors']);
        $this->assertSame('Ana Souza', $this->fetchTable('Users')->get($this->pessoa->id)->name);
    }

    public function testEspacosDoNomeSaoJuntados(): void
    {
        $resposta = $this->atualizar(['name' => '  Ana   Souza  Lima ']);
        $this->assertResponseOk();
        $this->assertSame('Ana Souza Lima', $resposta['user']['name']);
    }

    public function testNomeComNumeroERecusado(): void
    {
        $resposta = $this->atualizar(['name' => 'Ana Souza 2']);
        $this->assertResponseCode(422);
        $this->assertSame('O nome não pode ter números.', $resposta['errors']['name']['semNumeros']);
        $this->assertSame('Ana Souza', $this->fetchTable('Users')->get($this->pessoa->id)->name);

        // No cadastro da conta também.
        $this->post('/api/v1/users', [
            'name' => 'J0ão Pedro',
            'cpf' => '52998224725',
            'birthday' => '2000-01-01',
            'sex' => 'M',
            'marital_status_id' => $this->estadosCivis['Solteiro'],
            'email' => 'joao.novo@exemplo.com',
            'phone' => '66999998888',
            'password' => 'senha123',
            'password_confirmation' => 'senha123',
        ]);
        $this->assertResponseCode(422);
        $this->assertArrayHasKey('name', $this->respostaJson()['errors']);
        $this->assertSame(0, $this->fetchTable('Users')->find()->where(['cpf' => '52998224725'])->count());
    }

    public function testTrocaASenhaConferindoAAtual(): void
    {
        $this->autenticarComo($this->pessoa);
        $this->post('/api/v1/users/me/senha', ['senha_atual' => 'errada', 'nova_senha' => 'novasenha']);
        $this->assertResponseCode(422);

        $this->autenticarComo($this->pessoa);
        $this->post('/api/v1/users/me/senha', ['senha_atual' => 'senha123', 'nova_senha' => '123']);
        $this->assertResponseCode(422);

        $this->autenticarComo($this->pessoa);
        $this->post('/api/v1/users/me/senha', ['senha_atual' => 'senha123', 'nova_senha' => 'novasenha']);
        $this->assertResponseOk();
        $this->assertTrue(password_verify('novasenha', $this->fetchTable('Users')->get($this->pessoa->id)->password));
    }
}
