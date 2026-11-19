<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PermissoesService;
use Cake\Datasource\EntityInterface;

/**
 * Senha provisória (ficha da pessoa no painel): a organização gera uma senha
 * para quem esqueceu a sua. A senha antiga e os logins abertos deixam de valer
 * na hora, e quem entra com a provisória não consegue fazer mais nada — nem
 * pelo site, nem chamando a API direto — até criar uma senha própria.
 */
class SenhaProvisoriaTest extends RegrasTestCase
{
    private EntityInterface $admin;
    private EntityInterface $pessoa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Antônio Admin', idade: 50, admin: true);
        $this->pessoa = $this->criarUsuario('Ana Souza', idade: 20, sexo: 'F');
    }

    private function gerarSenha(EntityInterface $quem, EntityInterface $para): array
    {
        $this->autenticarComo($quem);
        $this->post("/api/v1/admin/users/{$para->id}/senha-provisoria");

        return $this->respostaJson();
    }

    /** Login de verdade (pela rota), devolvendo o token e o usuário. */
    private function entrar(string $cpf, string $senha): array
    {
        $this->post('/api/v1/auth/login', ['cpf' => $cpf, 'password' => $senha]);

        return $this->respostaJson();
    }

    private function comToken(string $token): void
    {
        $this->configRequest(['headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']]);
    }

    public function testGeraSenhaFacilDeDitarQueSubstituiAAntiga(): void
    {
        $resposta = $this->gerarSenha($this->admin, $this->pessoa);
        $this->assertResponseOk();
        $senha = $resposta['data']['senha'];
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+-[2-9]{4}$/', $senha);

        $this->entrar($this->pessoa->cpf, 'senha123');
        $this->assertResponseCode(401, 'A senha antiga deixa de funcionar na hora.');

        $login = $this->entrar($this->pessoa->cpf, $senha);
        $this->assertResponseOk();
        $this->assertTrue($login['user']['trocar_senha']);

        // A ficha mostra que há uma senha provisória pendente.
        $this->autenticarComo($this->admin);
        $this->get("/api/v1/admin/users/{$this->pessoa->id}");
        $this->assertNotNull($this->respostaJson()['data']['senha_provisoria_em']);
    }

    public function testComSenhaProvisoriaNadaFuncionaAlemDeCriarASenha(): void
    {
        $senha = $this->gerarSenha($this->admin, $this->pessoa)['data']['senha'];
        $token = $this->entrar($this->pessoa->cpf, $senha)['access_token'];

        foreach ([['get', '/api/v1/activities'], ['get', '/api/v1/me/inscricoes'], ['patch', '/api/v1/users/me'], ['get', '/api/v1/inscricoes/1/formulario']] as [$metodo, $rota]) {
            $this->comToken($token);
            $this->{$metodo}($rota);
            $this->assertResponseCode(403, "{$metodo} {$rota} deveria ser recusado.");
            $this->assertTrue($this->respostaJson()['trocar_senha']);
        }

        // Os próprios dados (a tela precisa do nome) continuam acessíveis.
        $this->comToken($token);
        $this->get('/api/v1/users/me');
        $this->assertResponseOk();

        // Não pode "trocar" pela própria provisória.
        $this->comToken($token);
        $this->post('/api/v1/users/me/senha', ['nova_senha' => $senha]);
        $this->assertResponseCode(422);

        // Cria a senha sem precisar repetir a provisória; aí tudo volta a funcionar.
        $this->comToken($token);
        $this->post('/api/v1/users/me/senha', ['nova_senha' => 'minhasenha']);
        $this->assertResponseOk();

        $this->comToken($token);
        $this->get('/api/v1/me/inscricoes');
        $this->assertResponseOk();

        $this->entrar($this->pessoa->cpf, 'minhasenha');
        $this->assertResponseOk();
        $this->assertFalse($this->respostaJson()['user']['trocar_senha']);
    }

    public function testLoginAbertoAntesDaSenhaProvisoriaDeixaDeValer(): void
    {
        $tokenAntigo = $this->entrar($this->pessoa->cpf, 'senha123')['access_token'];
        // A senha provisória é gerada um tempo depois do login antigo.
        $this->fetchTable('Users')->updateAll(['temporary_password_at' => date('Y-m-d H:i:s', time() + 5)], ['id' => $this->pessoa->id]);

        $this->comToken($tokenAntigo);
        $this->post('/api/v1/users/me/senha', ['nova_senha' => 'invasor123']);
        $this->assertResponseCode(401, 'Quem estava com a sessão antiga não cria a senha no lugar da pessoa.');
    }

    public function testQuemPodeGerar(): void
    {
        // Para si mesmo, não: usa "Meu cadastro".
        $this->gerarSenha($this->admin, $this->admin);
        $this->assertResponseCode(403);

        // Sem "Cargos e permissões": gera para participante, não para quem usa o painel.
        $voluntario = $this->criarUsuario('Bia Voluntária', idade: 30);
        (new PermissoesService())->definir((int)$voluntario->id, ['usuarios']);
        $servoDoPainel = $this->criarUsuario('Carlos Coordenador', idade: 30);
        (new PermissoesService())->definir((int)$servoDoPainel->id, ['atividades']);

        $this->gerarSenha($voluntario, $servoDoPainel);
        $this->assertResponseCode(403);
        $this->gerarSenha($voluntario, $this->pessoa);
        $this->assertResponseOk();
        $this->gerarSenha($this->admin, $servoDoPainel);
        $this->assertResponseOk();

        // Sem "Pessoas e acessos", ninguém gera.
        $this->gerarSenha($this->pessoa, $voluntario);
        $this->assertResponseCode(403);
    }
}
