<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PermissoesService;
use Cake\Datasource\EntityInterface;

/**
 * Aba Respostas dos formulários.
 *
 * As respostas têm dados de saúde: só quem tem a permissão "Respostas dos
 * formulários" lê, e ela é independente de "Formulários" (montar perguntas).
 * Quem só vê respostas abre o editor para leitura, mas não muda nada.
 */
class RespostasFormularioTest extends RegrasTestCase
{
    private int $secaoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secaoId = (int)$this->salvar('Sections', ['title' => 'Saúde teste', 'order' => 1])->id;
    }

    private function comPermissoes(string $nome, array $permissoes): EntityInterface
    {
        $usuario = $this->criarUsuario($nome, idade: 40);
        (new PermissoesService())->definir((int)$usuario->id, $permissoes);

        return $usuario;
    }

    private function pergunta(string $texto, string $tipo, ?int $dependeDe = null): EntityInterface
    {
        return $this->salvar('Questions', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'section_id' => $this->secaoId,
            'text' => $texto, 'type' => $tipo, 'is_required' => true, 'order' => 1,
            'accept_generic_answer' => false, 'depends_on_option_id' => $dependeDe,
        ]);
    }

    private function url(array $extra = []): string
    {
        return '/api/v1/admin/formularios/respostas?' . http_build_query(
            ['category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista'] + $extra
        );
    }

    public function testSoQuemTemAPermissaoLeAsRespostas(): void
    {
        $this->autenticarComo($this->comPermissoes('Monta formulários', ['formularios']));
        $this->get($this->url());
        $this->assertResponseCode(403);

        $this->autenticarComo($this->comPermissoes('Equipe de saúde', ['respostas_formularios']));
        $this->get($this->url());
        $this->assertResponseOk();
    }

    public function testQuemSoVeRespostasLeOEditorMasNaoMuda(): void
    {
        $saude = $this->comPermissoes('Equipe de saúde', ['respostas_formularios']);
        $this->pergunta('Tem alergia?', 'texto_curto');

        $this->autenticarComo($saude);
        $this->get('/api/v1/admin/perguntas?category_id=' . $this->categorias['FAC'] . '&subscription_type=Campista');
        $this->assertResponseOk();
        $this->autenticarComo($saude);
        $this->get('/api/v1/admin/formularios/resumo');
        $this->assertResponseOk();

        $this->autenticarComo($saude);
        $this->post('/api/v1/admin/perguntas', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'section_id' => $this->secaoId,
            'text' => 'Nova', 'type' => 'texto_curto',
        ]);
        $this->assertResponseCode(403);
    }

    public function testRespostasDaEdicaoComQuemEnviouEQuemFalta(): void
    {
        $alergia = $this->pergunta('Tem alergia?', 'sim_nao');
        $sim = $this->salvar('Options', ['question_id' => $alergia->id, 'text' => 'Sim', 'order' => 1]);
        $this->salvar('Options', ['question_id' => $alergia->id, 'text' => 'Não', 'order' => 2]);
        $qual = $this->pergunta('Qual?', 'texto_curto', (int)$sim->id);

        $aberto = $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->amanha()], ['start_date' => '2026-12-05 08:00:00']);
        $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->ontem()], ['start_date' => '2027-01-10 08:00:00']);
        $sorteio = $this->metodos['Sorteio'];

        $enviou = $this->inscrever($this->criarUsuario('Ana'), $aberto, 'Campista', ['selection_method_id' => $sorteio, 'form_submitted' => true]);
        $this->salvar('Answers', ['pre_registration_id' => $enviou->id, 'question_id' => $alergia->id, 'answer' => (string)$sim->id]);
        $this->salvar('Answers', ['pre_registration_id' => $enviou->id, 'question_id' => $qual->id, 'answer' => 'Amendoim']);
        $this->inscrever($this->criarUsuario('Bia'), $aberto, 'Campista', ['substitute_position' => 1]);
        $this->inscrever($this->criarUsuario('Desistiu'), $aberto, 'Campista', ['selection_method_id' => $sorteio, 'is_quitter' => true]);
        // Enviou, mas perdeu a vaga depois (prazo do pagamento): sai da aba e da contagem.
        $this->inscrever($this->criarUsuario('Perdeu a vaga'), $aberto, 'Campista', [
            'selection_method_id' => $sorteio, 'form_submitted' => true, 'disqualified_at' => $this->ontem(),
        ]);
        $this->inscrever($this->criarUsuario('Não sorteado'), $aberto, 'Campista');
        $this->inscrever($this->criarUsuario('Servo'), $aberto, 'Servo', ['selection_method_id' => $sorteio, 'form_submitted' => true]);

        $this->autenticarComo($this->comPermissoes('Equipe de saúde', ['respostas_formularios']));
        $this->get($this->url());
        $this->assertResponseOk();
        $dados = $this->respostaJson()['data'];

        $this->assertSame($aberto->id, $dados['atividade_id'], 'Sem escolher, vem a edição com o prazo aberto (mesmo não sendo a mais recente).');
        $this->assertCount(2, $dados['edicoes']);
        $this->assertSame(['Tem alergia?', 'Qual?'], array_column($dados['perguntas'], 'text'));

        $pessoas = array_column($dados['pessoas'], null, 'nome');
        $this->assertSame(['Ana', 'Bia'], array_keys($pessoas), 'Só quem precisa enviar: nem desistentes, nem quem perdeu a vaga, nem não sorteados, nem servos.');
        $this->assertTrue($pessoas['Ana']['enviou']);
        $this->assertSame('Sorteado', $pessoas['Ana']['situacao']);
        $this->assertSame((string)$sim->id, $pessoas['Ana']['respostas'][(string)$alergia->id]);
        $this->assertSame('Amendoim', $pessoas['Ana']['respostas'][(string)$qual->id]);
        $this->assertFalse($pessoas['Bia']['enviou']);
        $this->assertSame('Suplente', $pessoas['Bia']['situacao']);

        // O número da lista de formulários bate com quem aparece na aba.
        $this->autenticarComo($this->comPermissoes('Organização', ['formularios']));
        $this->get('/api/v1/admin/formularios/resumo');
        $porForm = [];
        foreach ($this->respostaJson()['data'] as $f) {
            $porForm["{$f['categoria']} {$f['subscription_type']}"] = $f['respostas'];
        }
        $this->assertSame(1, $porForm['FAC Campista']);
        $this->assertSame(1, $porForm['FAC Servo']);
    }

    public function testGuardaQuandoFoiConfirmadoEQuandoEnviou(): void
    {
        $tabela = $this->fetchTable('PreRegistrations');
        $acampamento = $this->criarAcampamento('FAC');

        // Inscrição comum: ainda não precisa enviar nada.
        $inscricao = $this->inscrever($this->criarUsuario('Ana'), $acampamento);
        $this->assertNull($inscricao->selected_at);

        // Entrou na fila de suplentes: passa a precisar enviar.
        $inscricao->substitute_position = 1;
        $tabela->saveOrFail($inscricao);
        $naFila = $tabela->get($inscricao->id)->selected_at;
        $this->assertNotNull($naFila);

        // Chamada para a vaga: continua valendo a data em que entrou na fila.
        $inscricao = $tabela->get($inscricao->id);
        $inscricao->substitute_position = null;
        $inscricao->selection_method_id = $this->metodos['Sorteio'];
        $tabela->saveOrFail($inscricao);
        $this->assertEquals($naFila, $tabela->get($inscricao->id)->selected_at);

        // Enviou: guarda a data do primeiro envio; corrigir depois não muda.
        $inscricao = $tabela->get($inscricao->id);
        $inscricao->form_submitted = true;
        $tabela->saveOrFail($inscricao);
        $enviou = $tabela->get($inscricao->id)->form_submitted_at;
        $this->assertNotNull($enviou);
        $inscricao = $tabela->get($inscricao->id);
        $inscricao->is_fee_paid = true;
        $tabela->saveOrFail($inscricao);
        $this->assertEquals($enviou, $tabela->get($inscricao->id)->form_submitted_at);

        // Sorteio desfeito: deixa de estar na lista e perde a data.
        $inscricao = $tabela->get($inscricao->id);
        $inscricao->selection_method_id = null;
        $tabela->saveOrFail($inscricao);
        $this->assertNull($tabela->get($inscricao->id)->selected_at);

        // Já sorteado na criação (indicação feita direto).
        $indicado = $this->inscrever($this->criarUsuario('Bia'), $acampamento, 'Campista', ['selection_method_id' => $this->metodos['Base']]);
        $this->assertNotNull($tabela->get($indicado->id)->selected_at);
    }

    public function testEscolherOutraEdicao(): void
    {
        $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->amanha()]);
        $antigo = $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->ontem()], ['start_date' => '2025-12-05 08:00:00']);
        $this->inscrever($this->criarUsuario('Carla'), $antigo, 'Campista', ['selection_method_id' => $this->metodos['Sorteio'], 'form_submitted' => true]);

        $this->autenticarComo($this->comPermissoes('Equipe de saúde', ['respostas_formularios']));
        $this->get($this->url(['atividade_id' => $antigo->id]));
        $dados = $this->respostaJson()['data'];

        $this->assertSame($antigo->id, $dados['atividade_id']);
        $this->assertSame(['Carla'], array_column($dados['pessoas'], 'nome'));
    }
}
