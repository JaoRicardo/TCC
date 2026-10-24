<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PermissoesService;
use Cake\Datasource\EntityInterface;

/**
 * Permissões por tela do painel: são a única regra de acesso. Entra no painel
 * quem tem pelo menos uma tela liberada, e cada pessoa só usa as telas que
 * estiverem ligadas para ela. Ninguém tira de si mesmo a permissão de gerenciar
 * permissões, e o último gestor não pode ser excluído.
 */
class PermissoesTest extends RegrasTestCase
{
    /** Pessoa com algumas telas liberadas (como um conselheiro do acampamento). */
    private function comTelas(string $nome, array $permissoes): EntityInterface
    {
        $pessoa = $this->criarUsuario($nome, idade: 60);
        (new PermissoesService())->definir((int)$pessoa->id, $permissoes);

        return $pessoa;
    }

    private function permissoesDe(EntityInterface $pessoa): array
    {
        $atual = $this->fetchTable('Users')->get($pessoa->id);

        return (new PermissoesService())->daPessoa($atual);
    }

    public function testSoAcessaAsTelasLiberadas(): void
    {
        $dona = $this->comTelas('Dona Maria', ['formularios']);
        $categoria = $this->categorias['FAC'];

        $this->autenticarComo($dona);
        $this->get("/api/v1/admin/perguntas?category_id={$categoria}&subscription_type=Campista");
        $this->assertResponseOk();

        foreach (['/api/v1/admin/sectors', '/api/v1/admin/users', '/api/v1/admin/selection-methods'] as $bloqueada) {
            $this->autenticarComo($dona);
            $this->get($bloqueada);
            $this->assertResponseCode(403, "{$bloqueada} deveria estar bloqueada.");
        }
    }

    public function testQuemNaoTemNenhumaTelaNaoAcessaOPainel(): void
    {
        $comum = $this->criarUsuario('Usuário comum', idade: 30);

        $this->autenticarComo($comum);
        $this->get('/api/v1/admin/perguntas?category_id=' . $this->categorias['FAC'] . '&subscription_type=Campista');
        $this->assertResponseCode(403);
    }

    public function testMeuCadastroTrazAsTelasLiberadas(): void
    {
        $dona = $this->comTelas('Dona Maria', ['setores', 'formularios']);
        $this->autenticarComo($dona);
        $this->get('/api/v1/users/me');
        $this->assertSame(['setores', 'formularios'], $this->respostaJson()['user']['permissoes']);

        $comum = $this->criarUsuario('Usuário comum', idade: 30);
        $this->autenticarComo($comum);
        $this->get('/api/v1/users/me');
        $this->assertSame([], $this->respostaJson()['user']['permissoes']);
    }

    public function testLoginTrazAsTelasLiberadas(): void
    {
        // A tela usa os dados que voltam do login para montar o botão do painel;
        // sem as permissões ali, o admin entrava e não via o painel.
        $admin = $this->criarUsuario('Admin', idade: 40, admin: true);

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->post('/api/v1/auth/login', ['cpf' => $admin->cpf, 'password' => 'senha123']);
        $this->assertResponseOk();
        $this->assertSame(PermissoesService::todas(), $this->respostaJson()['user']['permissoes']);
    }

    public function testLiberarTelasParaQualquerPessoaAbreOPainelParaEla(): void
    {
        // Não existe mais "tornar conselheiro": basta liberar alguma tela.
        $gestor = $this->criarUsuario('Gestor', idade: 40, admin: true);
        $novo = $this->criarUsuario('Novo conselheiro', idade: 60);

        $this->autenticarComo($gestor);
        $this->put("/api/v1/admin/users/{$novo->id}/permissoes", ['permissoes' => ['formularios']]);
        $this->assertResponseOk();
        $this->assertSame(['formularios'], $this->permissoesDe($novo));

        // "Desativar tudo" fecha o painel de novo.
        $this->autenticarComo($gestor);
        $this->put("/api/v1/admin/users/{$novo->id}/permissoes", ['permissoes' => []]);
        $this->assertResponseOk();
        $this->assertSame([], $this->permissoesDe($novo));
    }

    public function testAoLigarUmaTelaQueSoAbrePelaListaAListaVemJunto(): void
    {
        $gestor = $this->criarUsuario('Gestor', idade: 40, admin: true);
        $dona = $this->criarUsuario('Dona Maria', idade: 60);

        $this->autenticarComo($gestor);
        $this->put("/api/v1/admin/users/{$dona->id}/permissoes", ['permissoes' => ['inscricoes', 'tela-que-nao-existe']]);
        $this->assertResponseOk();
        $this->assertSame(['atividades', 'inscricoes'], $this->permissoesDe($dona));
    }

    public function testSoQuemGerenciaPermissoesMexeNelas(): void
    {
        $secretaria = $this->comTelas('Secretária', ['usuarios']);
        $outro = $this->criarUsuario('Outro', idade: 30);

        foreach (['get', 'put'] as $metodo) {
            $this->autenticarComo($secretaria);
            $this->{$metodo}("/api/v1/admin/users/{$outro->id}/permissoes", ['permissoes' => PermissoesService::todas()]);
            $this->assertResponseCode(403);
        }
        $this->assertSame([], $this->permissoesDe($outro));

        // A lista de usuários não mostra a quem não gerencia quantas telas cada um tem.
        $this->autenticarComo($secretaria);
        $this->get('/api/v1/admin/users');
        $this->assertResponseOk();
        $this->assertArrayNotHasKey('telas_liberadas', $this->respostaJson()['data'][0]);
    }

    public function testNinguemTiraDeSiMesmoAPermissaoDeGerenciar(): void
    {
        $gestor = $this->criarUsuario('Gestor', idade: 40, admin: true);

        $this->autenticarComo($gestor);
        $this->put("/api/v1/admin/users/{$gestor->id}/permissoes", ['permissoes' => ['formularios']]);
        $this->assertResponseCode(422);

        $this->assertSame(PermissoesService::todas(), $this->permissoesDe($gestor));
    }

    public function testNaoDaParaExcluirAUnicaPessoaQueGerenciaPermissoes(): void
    {
        $gestor = $this->criarUsuario('Único gestor', idade: 40, admin: true);
        $secretaria = $this->comTelas('Secretária', ['usuarios']);

        $this->autenticarComo($secretaria);
        $this->delete("/api/v1/admin/users/{$gestor->id}");
        $this->assertResponseCode(422);
        $this->assertNull($this->fetchTable('Users')->get($gestor->id)->deleted);
    }

    public function testTelaDeEquipesSoDaPoderesDeAdminComAPermissao(): void
    {
        $atividade = $this->criarAcampamento('FAC');
        $servo = $this->criarUsuario('Servo', idade: 25);
        $this->inscrever($servo, $atividade, 'Servo');
        $semEquipes = $this->comTelas('Sem equipes', ['formularios']);
        $comEquipes = $this->comTelas('Com equipes', ['equipes']);

        $this->autenticarComo($semEquipes);
        $this->post("/api/v1/activities/{$atividade->id}/general-servants", ['user_id' => $servo->id]);
        $this->assertResponseCode(403);

        $this->autenticarComo($comEquipes);
        $this->post("/api/v1/activities/{$atividade->id}/general-servants", ['user_id' => $servo->id]);
        $this->assertResponseSuccess();
    }
}
