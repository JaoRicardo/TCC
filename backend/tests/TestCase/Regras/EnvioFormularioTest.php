<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use Cake\Datasource\EntityInterface;

/**
 * Envio do formulário pelo participante (POST /inscricoes/{id}/formulario).
 *
 * Resposta que deixa de valer (a pessoa desmarcou "Tem alergia?", então o
 * "Qual?" não vale mais) sai da ficha, mas por exclusão lógica: nada é apagado
 * de verdade do banco.
 */
class EnvioFormularioTest extends RegrasTestCase
{
    private EntityInterface $campista;

    private EntityInterface $inscricao;

    private int $alergia;

    private int $sim;

    private int $nao;

    private int $qual;

    protected function setUp(): void
    {
        parent::setUp();

        $secao = (int)$this->salvar('Sections', ['title' => 'Saúde', 'order' => 1])->id;
        $pergunta = fn(string $texto, string $tipo, ?int $dependeDe = null, int $ordem = 1) => (int)$this->salvar('Questions', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'section_id' => $secao,
            'text' => $texto, 'type' => $tipo, 'is_required' => true, 'order' => $ordem,
            'accept_generic_answer' => false, 'depends_on_option_id' => $dependeDe,
        ])->id;
        $this->alergia = $pergunta('Tem alergia?', 'sim_nao');
        $this->sim = (int)$this->salvar('Options', ['question_id' => $this->alergia, 'text' => 'Sim', 'order' => 1])->id;
        $this->nao = (int)$this->salvar('Options', ['question_id' => $this->alergia, 'text' => 'Não', 'order' => 2])->id;
        $this->qual = $pergunta('Qual?', 'texto', $this->sim, 2);

        $acampamento = $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->amanha()]);
        $this->campista = $this->criarUsuario('Ana');
        $this->inscricao = $this->inscrever($this->campista, $acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
    }

    private function enviar(array $respostas): void
    {
        $answers = [];
        foreach ($respostas as $questionId => $answer) {
            $answers[] = ['question_id' => $questionId, 'answer' => $answer];
        }
        $this->autenticarComo($this->campista);
        $this->post("/api/v1/inscricoes/{$this->inscricao->id}/formulario", ['answers' => $answers]);
        $this->assertResponseOk();
    }

    public function testRespostaQueDeixaDeValerFicaExcluidaSoLogicamente(): void
    {
        $this->enviar([$this->alergia => (string)$this->sim, $this->qual => 'Amendoim']);
        $respostas = $this->fetchTable('Answers');
        $linhaDoQual = $respostas->find()->where(['question_id' => $this->qual])->firstOrFail();

        // Trocou para "Não": o "Qual?" deixa de valer.
        $this->enviar([$this->alergia => (string)$this->nao, $this->qual => 'Amendoim']);
        $excluida = $respostas->get($linhaDoQual->id);
        $this->assertNotNull($excluida->deleted, 'A resposta continua no banco, marcada como excluída.');

        $this->autenticarComo($this->campista);
        $this->get("/api/v1/inscricoes/{$this->inscricao->id}/formulario");
        $perguntas = array_column($this->respostaJson()['data']['sections'][0]['questions'], 'answer', 'id');
        $this->assertNull($perguntas[$this->qual], 'A ficha não mostra mais a resposta excluída.');

        // Voltou para "Sim": a mesma linha volta a valer, com o texto novo.
        $this->enviar([$this->alergia => (string)$this->sim, $this->qual => 'Camarão']);
        $this->assertSame(1, $respostas->find()->where(['question_id' => $this->qual])->count());
        $reaproveitada = $respostas->get($linhaDoQual->id);
        $this->assertNull($reaproveitada->deleted);
        $this->assertSame('Camarão', $reaproveitada->answer);
    }

    public function testAtividadeExcluidaSomeDaAreaDoParticipante(): void
    {
        $this->fetchTable('Activities')->updateAll(['deleted' => $this->ontem()], ['id' => $this->inscricao->activity_id]);

        $this->autenticarComo($this->campista);
        $this->get('/api/v1/me/inscricoes');
        $this->assertResponseOk();
        $this->assertSame([], $this->respostaJson()['data'], 'Não aparece em Meus Formulários.');

        $this->autenticarComo($this->campista);
        $this->get("/api/v1/inscricoes/{$this->inscricao->id}/formulario");
        $this->assertResponseCode(404);

        $this->autenticarComo($this->campista);
        $this->post("/api/v1/inscricoes/{$this->inscricao->id}/formulario", [
            'answers' => [['question_id' => $this->alergia, 'answer' => (string)$this->nao]],
        ]);
        $this->assertResponseCode(404);
        $this->assertFalse($this->fetchTable('PreRegistrations')->get($this->inscricao->id)->form_submitted);
    }

    public function testGuardaQuandoEnviouEMandaParaATela(): void
    {
        $this->enviar([$this->alergia => (string)$this->nao]);

        $this->assertNotNull($this->fetchTable('PreRegistrations')->get($this->inscricao->id)->form_submitted_at);

        $this->autenticarComo($this->campista);
        $this->get("/api/v1/inscricoes/{$this->inscricao->id}/formulario");
        $dados = $this->respostaJson()['data'];
        $this->assertTrue($dados['respondido']);
        $this->assertNotNull($dados['enviado_em']);
        $this->assertSame('2026-12-07', $dados['atividade']['fim'], 'Começa em 05/12 e dura 3 dias.');
    }
}
