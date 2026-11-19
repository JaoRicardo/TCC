<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Model\Table\FormCorrectionsTable;
use App\Service\PermissoesService;
use App\Service\PrazoFormularioService;
use Cake\Datasource\EntityInterface;

/**
 * "Pedir correção" na aba Respostas: a organização pede que a pessoa corrija o
 * formulário já enviado. A inscrição continua com a vaga e o formulário reabre,
 * com um prazo só desta inscrição. Se não corrigir até esse prazo, a inscrição
 * fica "Recusada" e a vaga vai para "Chamar próximo".
 */
class CorrecaoFormularioTest extends RegrasTestCase
{
    private EntityInterface $admin;
    private EntityInterface $pessoa;
    private EntityInterface $acampamento;
    private EntityInterface $inscricao;
    private EntityInterface $pergunta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Antônio Carlos', idade: 50, admin: true);
        $this->pessoa = $this->criarUsuario('Júlia Martins', idade: 15, sexo: 'F');
        $secao = (int)$this->salvar('Sections', ['title' => 'Geral', 'order' => 1])->id;
        $this->pergunta = $this->salvar('Questions', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'section_id' => $secao,
            'text' => 'Apelido', 'type' => 'texto', 'is_required' => true, 'order' => 1, 'accept_generic_answer' => false,
        ]);
        // O prazo geral do formulário já passou: só a correção reabre.
        $this->acampamento = $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->ontem()]);
        $this->inscricao = $this->inscrever($this->pessoa, $this->acampamento, 'Campista', [
            'selection_method_id' => $this->metodos['Sorteio'], 'form_submitted' => true,
        ]);
        $this->salvar('Answers', ['pre_registration_id' => $this->inscricao->id, 'question_id' => $this->pergunta->id, 'answer' => 'Juju']);
    }

    private function pedir(array $dados = [], ?EntityInterface $quem = null): array
    {
        $this->autenticarComo($quem ?? $this->admin);
        $this->post("/api/v1/admin/inscricoes/{$this->inscricao->id}/correcao", $dados + [
            'motivo'    => 'A foto do documento ficou cortada.',
            'perguntas' => [$this->pergunta->id],
            'prazo'     => date('Y-m-d', strtotime('+2 days')),
        ]);

        return $this->respostaJson();
    }

    private function formulario(): array
    {
        $this->autenticarComo($this->pessoa);
        $this->get("/api/v1/inscricoes/{$this->inscricao->id}/formulario");

        return $this->respostaJson()['data'];
    }

    private function situacao(): string
    {
        return $this->formulario()['status'];
    }

    private function reenviar(): void
    {
        $this->autenticarComo($this->pessoa);
        $this->post("/api/v1/inscricoes/{$this->inscricao->id}/formulario", [
            'answers' => [['question_id' => $this->pergunta->id, 'answer' => 'Júlia']],
        ]);
    }

    private function registro(EntityInterface $deQuem): array
    {
        return array_column($this->fetchTable('UserChangeLogs')->daFicha((int)$deQuem->id), 'texto');
    }

    public function testPedirCorrecaoMantemAVagaEReabreOFormularioAteReenviar(): void
    {
        $this->assertFalse($this->formulario()['editavel'], 'Prazo geral vencido: sem correção, não edita.');

        $pedido = $this->pedir();
        $this->assertResponseCode(201);
        $this->assertSame('Antônio Carlos', $pedido['data']['por']);
        $this->assertSame([$this->pergunta->id], $pedido['data']['perguntas']);

        $form = $this->formulario();
        $this->assertSame('Correção pedida', $form['status']);
        $this->assertTrue($form['editavel']);
        $this->assertSame('A foto do documento ficou cortada.', $form['correcao']['motivo']);
        $this->assertArrayNotHasKey('por', $form['correcao'], 'A pessoa não vê o nome de quem pediu.');
        $this->assertSame(date('Y-m-d', strtotime('+2 days')) . 'T23:59', $form['prazo']['limite']);

        $this->reenviar();
        $this->assertResponseOk();
        $this->assertTrue($this->respostaJson()['correcao_enviada']);
        $this->assertSame('Formulário Enviado', $this->situacao());
        $this->assertNull($this->fetchTable('FormCorrections')->aberta((int)$this->inscricao->id));
        $this->assertSame([
            "Formulário de {$this->acampamento->name} reenviado depois da correção.",
            "Correção pedida no formulário de {$this->acampamento->name}: “A foto do documento ficou cortada.”",
            'Conta criada.',
        ], array_merge($this->registro($this->pessoa), ['Conta criada.']));
    }

    public function testDesfazerPedidoVoltaAFormularioEnviado(): void
    {
        $this->pedir();
        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/admin/inscricoes/{$this->inscricao->id}/correcao");
        $this->assertResponseOk();
        $this->assertSame('Formulário Enviado', $this->situacao());
        $this->assertSame(FormCorrectionsTable::DESFEITO, $this->fetchTable('FormCorrections')->find()->firstOrFail()->resolution);
        $this->assertContains("Pedido de correção desfeito no formulário de {$this->acampamento->name}.", $this->registro($this->pessoa));
    }

    public function testCorrecaoNaoFeitaNoPrazoFicaRecusadaELiberaAVaga(): void
    {
        $this->pedir();
        $this->fetchTable('FormCorrections')->updateAll(['deadline' => $this->ontem()], ['pre_registration_id' => $this->inscricao->id]);
        (new PrazoFormularioService())->executarPrazosVencidos();

        $recusada = $this->recarregar($this->inscricao);
        $this->assertSame(PrazoFormularioService::MOTIVO_CORRECAO, $recusada->disqualification_reason);
        $form = $this->formulario();
        $this->assertSame('Recusada', $form['status']);
        $this->assertFalse($form['editavel']);
        $this->reenviar();
        $this->assertResponseCode(422, 'Depois do prazo da correção não dá mais para reenviar.');

        $this->assertSame(1, (new PrazoFormularioService())->vagasAbertasCampistas((int)$this->acampamento->id)['Mulher']);
        $this->assertSame(FormCorrectionsTable::RECUSADO, $this->fetchTable('FormCorrections')->find()->firstOrFail()->resolution);
        $ultima = $this->fetchTable('UserChangeLogs')->daFicha((int)$this->pessoa->id, 1)[0];
        $this->assertSame('Sistema', $ultima['quem']);
        $this->assertSame(
            "Inscrição em {$this->acampamento->name} recusada: correção não feita até " . date('d/m/Y', strtotime('-1 day')) . '.',
            $ultima['texto']
        );
    }

    public function testDentroDoPrazoDaCorrecaoAVagaContinua(): void
    {
        $this->pedir();
        (new PrazoFormularioService())->executarPrazosVencidos();
        $this->assertNull($this->recarregar($this->inscricao)->disqualified_at);
        $this->assertSame('Correção pedida', $this->situacao());
    }

    public function testPedidoPrecisaDeMotivoDataValidaEFormularioEnviado(): void
    {
        $this->pedir(['motivo' => '  ']);
        $this->assertResponseCode(422);
        $this->pedir(['prazo' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertResponseCode(422);
        $this->pedir(['prazo' => '2026-12-31']);
        $this->assertResponseCode(422, 'Depois do início do acampamento não serve.');

        $this->pedir();
        $this->assertResponseCode(201);
        $this->pedir();
        $this->assertResponseCode(422, 'Só um pedido aberto por vez.');

        $naoEnviou = $this->inscrever($this->criarUsuario('Bia', sexo: 'F'), $this->acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/inscricoes/{$naoEnviou->id}/correcao", ['motivo' => 'x', 'prazo' => date('Y-m-d', strtotime('+1 day'))]);
        $this->assertResponseCode(422);

        $editor = $this->criarUsuario('Editor', idade: 40);
        (new PermissoesService())->definir((int)$editor->id, ['formularios']);
        $this->autenticarComo($editor);
        $this->delete("/api/v1/admin/inscricoes/{$this->inscricao->id}/correcao");
        $this->assertResponseCode(403);
    }

    public function testRespostasMostramOPedidoAberto(): void
    {
        $this->pedir();
        $this->autenticarComo($this->admin);
        $this->get('/api/v1/admin/formularios/respostas?' . http_build_query([
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'atividade_id' => $this->acampamento->id,
        ]));
        $pessoa = $this->respostaJson()['data']['pessoas'][0];
        $this->assertTrue($pessoa['enviou']);
        $this->assertSame('A foto do documento ficou cortada.', $pessoa['correcao']['motivo']);
        $this->assertNull($pessoa['encerrada']);
    }
}
