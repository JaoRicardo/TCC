<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use Cake\Datasource\EntityInterface;

/**
 * Tela Gestão › Categorias (decidido com o usuário em 2026-11-24).
 *
 * - As regras de cada categoria ficam nas colunas, não no nome: uma categoria
 *   nova ou renomeada funciona igual às de sempre.
 * - A ordem da lista também é a escada por idade: entre as que estão na
 *   escada, ter sido campista numa deixa servir nela e nas que vêm antes. Uma
 *   ordem só (handoff design_handoff_categorias): a escada sempre antes das de
 *   fora; o degrau de uma categoria nova/editada/reativada vem no campo
 *   `depois` (a que fica logo abaixo); a arquivada fica no lugar dela.
 * - Arquivar: só sem acampamento por acontecer; some da criação de
 *   acampamentos e da lista simples, mas os formulários continuam na lista
 *   de Formulários (marcados como arquivados).
 * - Excluir de vez: só quem nunca teve acampamento.
 * - Tudo exige a permissão "categorias".
 */
class CategoriasTest extends RegrasTestCase
{
    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Admin', idade: 40, admin: true);
    }

    private function criarCategoria(array $dados): array
    {
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/categorias', $dados);
        $this->assertResponseCode(201);
        $c = $this->respostaJson()['data'];
        $this->categorias[$c['nome']] = $c['id'];

        return $c;
    }

    private function inscreverPelaApi(EntityInterface $usuario, EntityInterface $atividade, string $tipo): void
    {
        $this->autenticarComo($usuario);
        $this->post("/api/v1/activities/{$atividade->id}/inscricoes", ['tipo' => $tipo]);
    }

    private function assertRecusada(string $trecho): void
    {
        $this->assertResponseCode(422);
        $this->assertStringContainsString($trecho, $this->respostaJson()['error'] ?? '');
    }

    private function ordem(): array
    {
        $this->autenticarComo($this->admin);
        $this->get('/api/v1/admin/categorias');
        $this->assertResponseOk();

        return array_column($this->respostaJson()['data'], 'nome');
    }

    // ─── Regras vêm das colunas ─────────────────────────────────────────────

    public function testCategoriaNovaEntraNoFimDaListaComAsRegrasDela(): void
    {
        $this->criarCategoria(['nome' => 'Jovens Adultos', 'idadeMinima' => 30, 'idadeMaxima' => 40]);

        $this->assertSame(['Mirim', 'FAC', 'Juvenil', 'Sênior', 'Casais', 'Discipulados', 'Jovens Adultos'], $this->ordem());

        $novo = $this->criarUsuario('Novo demais', idade: 25);
        $this->inscreverPelaApi($novo, $this->criarAcampamento('Jovens Adultos'), 'Campista');
        $this->assertRecusada('A categoria Jovens Adultos é para campistas de 30 a 40 anos, e no primeiro dia do acampamento você terá 25 anos.');

        $certo = $this->criarUsuario('Na idade', idade: 35);
        $this->inscreverPelaApi($certo, $this->criarAcampamento('Jovens Adultos'), 'Campista');
        $this->assertResponseCode(201);
    }

    public function testRenomearNaoPerdeAsRegras(): void
    {
        $this->autenticarComo($this->admin);
        $this->patch("/api/v1/admin/categorias/{$this->categorias['Juvenil']}", ['nome' => 'Jovens']);
        $this->assertResponseOk();
        $this->categorias['Jovens'] = $this->categorias['Juvenil'];

        $casado = $this->criarUsuario('Casado', idade: 20, estadoCivil: 'Casado');
        $this->inscreverPelaApi($casado, $this->criarAcampamento('Jovens'), 'Campista');
        $this->assertRecusada('devem se inscrever na categoria Sênior, não no Jovens');
    }

    public function testCategoriaSoDeCasaisRecusaSolteiroEIgnoraVagasDeHomensEMulheres(): void
    {
        $this->criarCategoria(['nome' => 'Noivos e Casais', 'soCasais' => true]);

        $solteiro = $this->criarUsuario('Solteiro', idade: 30);
        $this->inscreverPelaApi($solteiro, $this->criarAcampamento('Noivos e Casais'), 'Campista');
        $this->assertRecusada('O acampamento Noivos e Casais é só para pessoas casadas');
    }

    public function testPreRequisitoDeOutraCategoria(): void
    {
        $this->criarCategoria(['nome' => 'Retiro FAC', 'requerCategoriaId' => $this->categorias['FAC']]);

        $semFac = $this->criarUsuario('Sem FAC', idade: 16);
        $this->inscreverPelaApi($semFac, $this->criarAcampamento('Retiro FAC'), 'Campista');
        $this->assertRecusada('Para se inscrever no Retiro FAC é necessário ter participado de um acampamento FAC antes.');

        $comFac = $this->criarUsuario('Com FAC', idade: 16);
        $this->jaFoiCampista($comFac, 'FAC');
        $this->inscreverPelaApi($comFac, $this->criarAcampamento('Retiro FAC'), 'Campista');
        $this->assertResponseCode(201);
    }

    // ─── Ordem = escada por idade ───────────────────────────────────────────

    public function testCategoriaNovaNaEscadaLiberaServirNasAnteriores(): void
    {
        $nova = $this->criarCategoria(['nome' => 'Adultos', 'naEscada' => true]);
        // Arrasta "Adultos" para logo depois do Sênior.
        $ids = array_map(fn($n) => $this->categorias[$n], ['Mirim', 'FAC', 'Juvenil', 'Sênior']);
        $ids[] = $nova['id'];
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/categorias/ordem', ['ids' => $ids]);
        $this->assertResponseOk();
        $this->assertSame(['Mirim', 'FAC', 'Juvenil', 'Sênior', 'Adultos', 'Casais', 'Discipulados'], $this->ordem());

        $pessoa = $this->criarUsuario('Ex-campista Adultos', idade: 45);
        $this->jaFoiCampista($pessoa, 'Adultos');
        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('Sênior'), 'Servo');
        $this->assertResponseCode(201);
        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('Casais'), 'Servo');
        $this->assertRecusada('Você ainda não pode servir em Casais');
    }

    public function testMudarAOrdemMudaQuemPodeServir(): void
    {
        // FAC passa para depois do Juvenil: quem foi campista no FAC agora serve no Juvenil.
        $ids = array_map(fn($n) => $this->categorias[$n], ['Mirim', 'Juvenil', 'FAC', 'Sênior', 'Casais', 'Discipulados']);
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/categorias/ordem', ['ids' => $ids]);

        $pessoa = $this->criarUsuario('Ex-campista FAC', idade: 19);
        $this->jaFoiCampista($pessoa, 'FAC');
        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('Juvenil'), 'Servo');
        $this->assertResponseCode(201);
    }

    // ─── Validação ──────────────────────────────────────────────────────────

    public function testValidacaoDosCampos(): void
    {
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/categorias', ['nome' => 'juvenil', 'idadeMinima' => 20, 'idadeMaxima' => 10]);
        $this->assertResponseCode(422);
        $erros = $this->respostaJson()['erros'];
        $this->assertSame('Já existe uma categoria com esse nome.', $erros['nome']);
        $this->assertArrayHasKey('idadeMaxima', $erros);

        // Sênior não pode pedir Discipulados, que já pede Sênior.
        $this->autenticarComo($this->admin);
        $this->patch("/api/v1/admin/categorias/{$this->categorias['Sênior']}", [
            'nome' => 'Sênior',
            'requerCategoriaId' => $this->categorias['Discipulados'],
        ]);
        $this->assertResponseCode(422);
        $this->assertArrayHasKey('requerCategoriaId', $this->respostaJson()['erros']);
    }

    public function testTipoDeVagaNaoMudaComAcampamentoPorAcontecer(): void
    {
        $this->criarAcampamento('FAC', [], ['start_date' => date('Y-m-d 08:00:00', strtotime('+30 days'))]);

        $this->autenticarComo($this->admin);
        $this->patch("/api/v1/admin/categorias/{$this->categorias['FAC']}", ['nome' => 'FAC', 'soCasais' => true]);
        $this->assertResponseCode(422);
        $this->assertArrayHasKey('soCasais', $this->respostaJson()['erros']);
    }

    // ─── Arquivar e excluir ─────────────────────────────────────────────────

    public function testArquivarSoSemAcampamentoPorAcontecer(): void
    {
        $atividade = $this->criarAcampamento('Discipulados', [], ['start_date' => date('Y-m-d 08:00:00', strtotime('+30 days'))]);

        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/categorias/{$this->categorias['Discipulados']}/arquivar");
        $this->assertResponseCode(422);
        $this->assertSame('acampamento_por_acontecer', $this->respostaJson()['codigo']);

        // Depois que o acampamento terminou, pode.
        $this->fetchTable('Activities')->updateAll(['start_date' => '2025-01-10 08:00:00'], ['id' => $atividade->id]);
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/categorias/{$this->categorias['Discipulados']}/arquivar");
        $this->assertResponseOk();
        $this->assertTrue($this->respostaJson()['data']['arquivada']);

        // Some da lista simples (criação de acampamento, modelo de setores)...
        $this->autenticarComo($this->admin);
        $this->get('/api/v1/categories');
        $this->assertNotContains('Discipulados', array_column($this->respostaJson()['data'], 'name'));

        // ...mas os formulários continuam na lista de Formulários, marcados.
        $this->autenticarComo($this->admin);
        $this->get('/api/v1/admin/formularios/resumo');
        $doDiscipulados = array_values(array_filter($this->respostaJson()['data'], fn($f) => $f['categoria'] === 'Discipulados'));
        $this->assertCount(2, $doDiscipulados);
        $this->assertTrue($doDiscipulados[0]['arquivada']);

        // Reativar devolve para a lista.
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/categorias/{$this->categorias['Discipulados']}/desarquivar");
        $this->assertResponseOk();
        $this->assertFalse($this->respostaJson()['data']['arquivada']);
    }

    public function testExcluirSoQuemNuncaTeveAcampamento(): void
    {
        $this->criarAcampamento('Mirim', [], ['start_date' => '2025-01-10 08:00:00']);
        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/admin/categorias/{$this->categorias['Mirim']}");
        $this->assertResponseCode(422);
        $this->assertSame('ja_teve_acampamento', $this->respostaJson()['codigo']);

        $nova = $this->criarCategoria(['nome' => 'Teste Excluir']);
        $this->criarPergunta('Teste Excluir');
        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/admin/categorias/{$nova['id']}");
        $this->assertResponseOk();
        $this->assertNotContains('Teste Excluir', $this->ordem());
        $this->assertSame(0, $this->fetchTable('Questions')->find()
            ->where(['category_id' => $nova['id'], 'deleted IS' => null])->count());
    }

    // ─── Degrau (`depois`), ordem normalizada, arquivada no lugar ───────────

    public function testCategoriaNovaFicaForaDaEscadaNoFimSemMudarNinguem(): void
    {
        $this->criarCategoria(['nome' => 'Noivos']);
        $this->assertSame(['Mirim', 'FAC', 'Juvenil', 'Sênior', 'Casais', 'Discipulados', 'Noivos'], $this->ordem());
    }

    public function testDegrauEscolhidoAoCriarDecideOndeServe(): void
    {
        // Logo acima do Juvenil: entre Juvenil e Sênior.
        $this->criarCategoria(['nome' => 'Universitários', 'naEscada' => true, 'depois' => $this->categorias['Juvenil']]);
        $this->assertSame(['Mirim', 'FAC', 'Juvenil', 'Universitários', 'Sênior', 'Casais', 'Discipulados'], $this->ordem());

        $pessoa = $this->criarUsuario('Ex-campista Universitários', idade: 30);
        $this->jaFoiCampista($pessoa, 'Universitários');
        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('Juvenil'), 'Servo');
        $this->assertResponseCode(201);
        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('Sênior'), 'Servo');
        $this->assertRecusada('Você ainda não pode servir em Sênior');

        // Primeiro degrau (depois vazio) e de volta para fora da escada.
        $this->autenticarComo($this->admin);
        $this->patch("/api/v1/admin/categorias/{$this->categorias['Universitários']}", ['nome' => 'Universitários', 'naEscada' => true, 'depois' => '']);
        $this->assertResponseOk();
        $this->assertSame(['Universitários', 'Mirim', 'FAC', 'Juvenil', 'Sênior', 'Casais', 'Discipulados'], $this->ordem());

        $this->autenticarComo($this->admin);
        $this->patch("/api/v1/admin/categorias/{$this->categorias['Universitários']}", ['nome' => 'Universitários', 'naEscada' => false]);
        $this->assertSame(['Mirim', 'FAC', 'Juvenil', 'Sênior', 'Casais', 'Discipulados', 'Universitários'], $this->ordem());
    }

    public function testOrdemSempreComAEscadaAntesEArquivadaNoMesmoLugar(): void
    {
        $this->criarCategoria(['nome' => 'Jovens Adultos', 'naEscada' => true, 'depois' => $this->categorias['Juvenil']]);
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/categorias/{$this->categorias['Jovens Adultos']}/arquivar");
        $this->assertResponseOk();

        // A tela manda só as em uso; Casais misturado no meio da escada vai para o bloco de fora.
        $ids = array_map(fn($n) => $this->categorias[$n], ['Mirim', 'Casais', 'Juvenil', 'FAC', 'Sênior', 'Discipulados']);
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/categorias/ordem', ['ids' => $ids]);
        $this->assertResponseOk();
        $this->assertSame(['Mirim', 'Juvenil', 'FAC', 'Jovens Adultos', 'Sênior', 'Casais', 'Discipulados'], $this->ordem());

        // Reativar sem degrau ("Desfazer"): volta no mesmo lugar.
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/categorias/{$this->categorias['Jovens Adultos']}/desarquivar");
        $this->assertSame(['Mirim', 'Juvenil', 'FAC', 'Jovens Adultos', 'Sênior', 'Casais', 'Discipulados'], $this->ordem());

        // Reativar escolhendo outro degrau.
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/categorias/{$this->categorias['Jovens Adultos']}/arquivar");
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/categorias/{$this->categorias['Jovens Adultos']}/desarquivar", ['depois' => $this->categorias['Sênior']]);
        $this->assertResponseOk();
        $this->assertSame(['Mirim', 'Juvenil', 'FAC', 'Sênior', 'Jovens Adultos', 'Casais', 'Discipulados'], $this->ordem());
    }

    public function testArquivadaDaEscadaContinuaValendoParaServir(): void
    {
        $this->criarCategoria(['nome' => 'Jovens Adultos', 'naEscada' => true, 'depois' => $this->categorias['Juvenil']]);
        $pessoa = $this->criarUsuario('Ex-campista Jovens Adultos', idade: 30);
        $this->jaFoiCampista($pessoa, 'Jovens Adultos');
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/categorias/{$this->categorias['Jovens Adultos']}/arquivar");

        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('FAC'), 'Servo');
        $this->assertResponseCode(201);
    }

    public function testListaTrazOQueFaltaConfigurarEOsPorAcontecer(): void
    {
        $this->criarPergunta('FAC', 'Servo');
        $this->criarAcampamento('FAC', [], ['name' => 'Acampamento FAC Futuro', 'start_date' => date('Y-m-d 08:00:00', strtotime('+30 days'))]);

        $this->autenticarComo($this->admin);
        $this->get('/api/v1/admin/categorias');
        $fac = array_values(array_filter($this->respostaJson()['data'], fn($c) => $c['nome'] === 'FAC'))[0];
        $this->assertSame(0, $fac['perguntasCampista']);
        $this->assertSame(1, $fac['perguntasServo']);
        $this->assertArrayHasKey('setores', $fac);
        $this->assertArrayHasKey('vagasSetores', $fac);
        $this->assertSame('Acampamento FAC Futuro', $fac['porAcontecer'][0]['nome']);
        $this->assertArrayHasKey('sorteioCampistasRealizado', $fac['porAcontecer'][0]);
    }

    public function testSemPermissaoNaoMexe(): void
    {
        $pessoa = $this->criarUsuario('Sem permissão', idade: 30);
        $this->autenticarComo($pessoa);
        $this->get('/api/v1/admin/categorias');
        $this->assertResponseCode(403);

        $this->autenticarComo($pessoa);
        $this->post('/api/v1/admin/categorias', ['nome' => 'Invasora']);
        $this->assertResponseCode(403);
    }
}
