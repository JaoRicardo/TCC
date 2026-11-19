<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use Cake\Datasource\EntityInterface;

/**
 * Registro de alterações da ficha da pessoa (fase 6): quem mudou o quê e
 * quando. Ficha, Meu cadastro, senha (nunca o valor) e permissões entram; o
 * CPF vai com o antigo e o novo. A ficha mostra as 10 mais recentes, terminando
 * em "Conta criada.", e "Ver tudo" traz o resto.
 */
class RegistroAlteracoesTest extends RegrasTestCase
{
    private EntityInterface $admin;
    private EntityInterface $pessoa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Antônio Carlos', idade: 50, admin: true);
        $this->pessoa = $this->criarUsuario('Ana Souza', idade: 20, sexo: 'F');
    }

    private function ficha(): array
    {
        $this->autenticarComo($this->admin);
        $this->get("/api/v1/admin/users/{$this->pessoa->id}");

        return $this->respostaJson()['data'];
    }

    private function salvarFicha(array $troca): void
    {
        $atual = $this->ficha();
        $this->autenticarComo($this->admin);
        $this->patch("/api/v1/admin/users/{$this->pessoa->id}", array_replace_recursive([
            'versao' => $atual['versao'], 'name' => $atual['name'], 'cpf' => $atual['cpf'], 'birthday' => $atual['birthday'],
            'sex' => $atual['sex'], 'marital_status_id' => $atual['marital_status_id'], 'phone' => $atual['phone'],
            'email' => $atual['email'], 'address' => $atual['address'],
        ], $troca));
        $this->assertResponseOk();
    }

    public function testFichaRegistraOQueMudouEQuemMudou(): void
    {
        $this->salvarFicha(['birthday' => '2005-03-10', 'phone' => '(66) 99714-8315', 'cpf' => '529.982.247-25']);
        $this->salvarFicha([]); // nada mudou: nada registrado

        $alteracoes = $this->ficha()['alteracoes'];
        $this->assertSame(2, $alteracoes['total']);
        $this->assertSame('Antônio Carlos', $alteracoes['lista'][0]['quem']);
        $this->assertStringStartsWith('Alterou o CPF (de ', $alteracoes['lista'][0]['texto']);
        $this->assertStringEndsWith('para 529.982.247-25), a data de nascimento e o telefone.', $alteracoes['lista'][0]['texto']);
        $this->assertSame(['quem' => 'Ana Souza', 'texto' => 'Conta criada.'], array_diff_key($alteracoes['lista'][1], ['quando' => 1]));
    }

    public function testSenhaPermissoesEMeuCadastro(): void
    {
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/users/{$this->pessoa->id}/senha-provisoria");
        $senha = $this->respostaJson()['data']['senha'];

        $this->autenticarComo($this->pessoa);
        $this->post('/api/v1/users/me/senha', ['nova_senha' => 'minhaSenha9']);
        $this->assertResponseOk();

        $this->autenticarComo($this->admin);
        $this->put("/api/v1/admin/users/{$this->pessoa->id}/permissoes", ['permissoes' => ['respostas_formularios']]);
        $this->autenticarComo($this->admin);
        $this->put("/api/v1/admin/users/{$this->pessoa->id}/permissoes", ['permissoes' => ['respostas_formularios']]);

        $this->autenticarComo($this->pessoa);
        $this->patch('/api/v1/users/me', ['email' => 'ana.nova@exemplo.com']);
        $this->assertResponseOk();

        $this->autenticarComo($this->admin);
        $this->get("/api/v1/admin/users/{$this->pessoa->id}/alteracoes");
        $textos = array_column($this->respostaJson()['data']['lista'], 'texto');
        $this->assertSame([
            'Alterou o e-mail (Meu cadastro).',
            'Alterou as permissões do painel.',
            'Criou uma senha própria no lugar da provisória.',
            'Gerou uma senha provisória.',
            'Conta criada.',
        ], $textos);
        foreach ($textos as $t) {
            $this->assertStringNotContainsString($senha, $t, 'A senha nunca vai para o registro.');
            $this->assertStringNotContainsString('minhaSenha9', $t);
        }
    }

    public function testFichaMostraAsDezMaisRecentesEVerTudoOResto(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->fetchTable('UserChangeLogs')->registrar((int)$this->pessoa->id, (int)$this->admin->id, "Alteração {$i}.");
        }

        $alteracoes = $this->ficha()['alteracoes'];
        $this->assertCount(10, $alteracoes['lista']);
        $this->assertSame(13, $alteracoes['total'], '12 gravadas + "Conta criada."');
        $this->assertNotContains('Conta criada.', array_column($alteracoes['lista'], 'texto'));

        $this->autenticarComo($this->admin);
        $this->get("/api/v1/admin/users/{$this->pessoa->id}/alteracoes");
        $this->assertCount(13, $this->respostaJson()['data']['lista']);

        $semAcesso = $this->criarUsuario('Carlos');
        $this->autenticarComo($semAcesso);
        $this->get("/api/v1/admin/users/{$this->pessoa->id}/alteracoes");
        $this->assertResponseCode(403);
    }
}
