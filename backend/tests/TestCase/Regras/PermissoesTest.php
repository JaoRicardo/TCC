<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PermissoesService;
use Cake\Datasource\EntityInterface;

/**
 * Permissões por tela do painel: administrador e conselheiro são só cargos, e
 * cada pessoa acessa as telas que estiverem ligadas para ela. Virar admin liga
 * tudo; ficar sem cargo desliga tudo; ninguém tira de si mesmo a permissão de
 * gerenciar permissões.
 */
class PermissoesTest extends RegrasTestCase
{
    private function conselheiro(string $nome, array $permissoes): EntityInterface
    {
        $pessoa = $this->criarUsuario($nome, idade: 60);
        $pessoa->is_counselor = true;
        $this->fetchTable('Users')->saveOrFail($pessoa);
        (new PermissoesService())->definir((int)$pessoa->id, $permissoes);

        return $pessoa;
    }

    private function permissoesDe(EntityInterface $pessoa): array
    {
        $atual = $this->fetchTable('Users')->get($pessoa->id);

        return (new PermissoesService())->daPessoa($atual);
    }

    public function testConselheiroSoAcessaAsTelasLiberadas(): void
    {
        $dona = $this->conselheiro('Dona Maria', ['formularios']);
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

    public function testQuemFicouSemCargoNaoAcessaNadaMesmoComPermissaoGravada(): void
    {
        $ex = $this->conselheiro('Ex-conselheiro', ['formularios']);
        $ex->is_counselor = false;
        $this->fetchTable('Users')->saveOrFail($ex);

        $this->assertSame([], $this->permissoesDe($ex));
        $this->autenticarComo($ex);
        $this->get('/api/v1/admin/perguntas?category_id=' . $this->categorias['FAC'] . '&subscription_type=Campista');
        $this->assertResponseCode(403);
    }

    public function testMeuCadastroTrazAsTelasLiberadas(): void
    {
        $dona = $this->conselheiro('Dona Maria', ['setores', 'formularios']);
        $this->autenticarComo($dona);
        $this->get('/api/v1/users/me');
        $this->assertSame(['setores', 'formularios'], $this->respostaJson()['user']['permissoes']);

        $comum = $this->criarUsuario('Usuário comum', idade: 30);
        $this->autenticarComo($comum);
        $this->get('/api/v1/users/me');
        $this->assertSame([], $this->respostaJson()['user']['permissoes']);
    }

    public function testVirarAdminLigaTudoEFicarSemCargoDesligaTudo(): void
    {
        $gestor = $this->criarUsuario('Gestor', idade: 40, admin: true);
        $novo = $this->criarUsuario('Novo admin', idade: 30);

        $this->autenticarComo($gestor);
        $this->patch("/api/v1/admin/users/{$novo->id}", ['is_admin' => true]);
        $this->assertResponseOk();
        $this->assertSame(PermissoesService::todas(), $this->permissoesDe($novo));

        $this->autenticarComo($gestor);
        $this->patch("/api/v1/admin/users/{$novo->id}", ['is_admin' => false]);
        $this->assertResponseOk();
        $this->assertSame([], $this->fetchTable('UserPermissions')->find()->where(['user_id' => $novo->id])->all()->toArray());
    }

    public function testAoLigarUmaTelaQueSoAbrePelaListaAListaVemJunto(): void
    {
        $gestor = $this->criarUsuario('Gestor', idade: 40, admin: true);
        $dona = $this->conselheiro('Dona Maria', []);

        $this->autenticarComo($gestor);
        $this->put("/api/v1/admin/users/{$dona->id}/permissoes", ['permissoes' => ['inscricoes', 'tela-que-nao-existe']]);
        $this->assertResponseOk();
        $this->assertSame(['atividades', 'inscricoes'], $this->permissoesDe($dona));
    }

    public function testSoQuemGerenciaPermissoesMudaCargos(): void
    {
        $secretaria = $this->conselheiro('Secretária', ['usuarios']);
        $outro = $this->criarUsuario('Outro', idade: 30);

        // Gestão de Usuários sem "Cargos e permissões": edita dados, mas não cargo.
        $this->autenticarComo($secretaria);
        $this->patch("/api/v1/admin/users/{$outro->id}", ['is_admin' => true]);
        $this->assertResponseCode(403);
        $this->assertFalse((bool)$this->fetchTable('Users')->get($outro->id)->is_admin);

        $this->autenticarComo($secretaria);
        $this->get("/api/v1/admin/users/{$outro->id}/permissoes");
        $this->assertResponseCode(403);
    }

    public function testNinguemTiraDeSiMesmoAPermissaoDeGerenciar(): void
    {
        $gestor = $this->criarUsuario('Gestor', idade: 40, admin: true);

        $this->autenticarComo($gestor);
        $this->put("/api/v1/admin/users/{$gestor->id}/permissoes", ['permissoes' => ['formularios']]);
        $this->assertResponseCode(422);

        $this->autenticarComo($gestor);
        $this->patch("/api/v1/admin/users/{$gestor->id}", ['is_admin' => false]);
        $this->assertResponseCode(403);

        $this->assertSame(PermissoesService::todas(), $this->permissoesDe($gestor));
    }

    public function testNaoDaParaExcluirAUnicaPessoaQueGerenciaPermissoes(): void
    {
        $gestor = $this->criarUsuario('Único gestor', idade: 40, admin: true);
        $secretaria = $this->conselheiro('Secretária', ['usuarios']);

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
        $semEquipes = $this->conselheiro('Sem equipes', ['formularios']);
        $comEquipes = $this->conselheiro('Com equipes', ['equipes']);

        $this->autenticarComo($semEquipes);
        $this->post("/api/v1/activities/{$atividade->id}/general-servants", ['user_id' => $servo->id]);
        $this->assertResponseCode(403);

        $this->autenticarComo($comEquipes);
        $this->post("/api/v1/activities/{$atividade->id}/general-servants", ['user_id' => $servo->id]);
        $this->assertResponseSuccess();
    }
}
