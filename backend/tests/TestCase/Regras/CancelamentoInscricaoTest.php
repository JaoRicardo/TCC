<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PermissoesService;
use App\Service\PrazoFormularioService;
use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;

/**
 * "Cancelar inscrição" pela organização (aba Respostas). Não dá para desfazer.
 * Acampamento: a vaga entra em "Chamar próximo" (casal sai junto, suplente sai
 * da fila) e um resorteio não devolve a pessoa ao sorteio. Evento: a vaga
 * volta para as inscrições.
 */
class CancelamentoInscricaoTest extends RegrasTestCase
{
    private EntityInterface $admin;
    private EntityInterface $acampamento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Antônio Carlos', idade: 50, admin: true);
        $this->acampamento = $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->amanha()]);
    }

    private function cancelar(EntityInterface $inscricao, string $motivo = 'A família avisou que não vai poder ir.', ?EntityInterface $quem = null): array
    {
        $this->autenticarComo($quem ?? $this->admin);
        $this->post("/api/v1/admin/inscricoes/{$inscricao->id}/cancelar", ['motivo' => $motivo]);

        return $this->respostaJson();
    }

    private function situacaoNoSite(EntityInterface $pessoa, EntityInterface $inscricao): array
    {
        $this->autenticarComo($pessoa);
        $this->get('/api/v1/me/inscricoes');
        foreach ($this->respostaJson()['data'] as $i) {
            if ($i['id'] === $inscricao->id) return $i;
        }
        $this->fail('Inscrição não apareceu para a pessoa.');
    }

    public function testCancelarAbreVagaParaChamarProximoComOMotivo(): void
    {
        $pedro = $this->criarUsuario('Pedro');
        $titular = $this->inscrever($pedro, $this->acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $suplente = $this->inscrever($this->criarUsuario('Lucas'), $this->acampamento, 'Campista', ['substitute_position' => 1]);

        $this->cancelar($titular);
        $this->assertResponseOk();
        $this->assertSame(['Pedro'], $this->respostaJson()['data']['canceladas']);

        $cancelada = $this->recarregar($titular);
        $this->assertSame(PrazoFormularioService::MOTIVO_CANCELADA, $cancelada->disqualification_reason);
        $this->assertSame($this->admin->id, $cancelada->disqualified_by);
        $no = $this->situacaoNoSite($pedro, $titular);
        $this->assertSame('Cancelada pela organização', $no['status']);
        $this->assertSame('A família avisou que não vai poder ir.', $no['motivoOrganizacao']);

        $prazos = new PrazoFormularioService();
        $this->assertSame(1, $prazos->vagasAbertasCampistas((int)$this->acampamento->id)['Homem']);
        $this->assertTrue($prazos->chamarProximoCampista((int)$this->acampamento->id, 'Homem')['ok']);
        $this->assertNull($this->recarregar($suplente)->substitute_position);

        $this->assertSame(
            "Inscrição em {$this->acampamento->name} cancelada pela organização: “A família avisou que não vai poder ir.”",
            $this->fetchTable('UserChangeLogs')->daFicha((int)$pedro->id)[0]['texto']
        );

        $this->cancelar($titular);
        $this->assertResponseCode(422, 'Já cancelada.');
    }

    public function testCasalSaiJuntoESuplenteSaiDaFila(): void
    {
        // Casal só existe no Casais: lá o casal sai junto.
        $casais = $this->criarAcampamento('Casais', ['camper_form_deadline' => $this->amanha()]);
        $ele = $this->criarUsuario('Marcos', idade: 30, estadoCivil: 'Casado');
        $ela = $this->criarUsuario('Paula', idade: 30, sexo: 'F', estadoCivil: 'Casado');
        $dele = $this->inscrever($ele, $casais, 'Campista', ['selection_method_id' => $this->metodos['Sorteio'], 'spouse_id' => $ela->id]);
        $dela = $this->inscrever($ela, $casais, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $this->cancelar($dele);
        $this->assertSame(['Marcos', 'Paula'], $this->respostaJson()['data']['canceladas']);
        $this->assertNotNull($this->recarregar($dela)->disqualified_at);

        $suplente = $this->inscrever($this->criarUsuario('Lucas'), $this->acampamento, 'Campista', ['substitute_position' => 1]);
        $this->cancelar($suplente);
        $this->assertResponseOk();
        $this->assertSame(0, (new PrazoFormularioService())->vagasAbertasCampistas((int)$this->acampamento->id)['Homem'], 'Suplente não tinha vaga.');
        $this->assertSame('Cancelada pela organização', $this->situacaoNoSite($this->fetchTable('Users')->get($suplente->user_id), $suplente)['status']);

        // Fora do Casais, quem é casado concorre como homem ou mulher: sai sozinho.
        $joao = $this->criarUsuario('João', idade: 30, estadoCivil: 'Casado');
        $rita = $this->criarUsuario('Rita', idade: 30, sexo: 'F', estadoCivil: 'Casado');
        $deJoao = $this->inscrever($joao, $this->acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio'], 'spouse_id' => $rita->id]);
        $deRita = $this->inscrever($rita, $this->acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $this->cancelar($deJoao);
        $this->assertSame(['João'], $this->respostaJson()['data']['canceladas']);
        $this->assertNull($this->recarregar($deRita)->disqualified_at);
    }

    public function testResorteioNaoDevolveQuemFoiCancelado(): void
    {
        $pedro = $this->criarUsuario('Pedro');
        $inscricao = $this->inscrever($pedro, $this->acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $this->cancelar($inscricao);

        (new SorteioService())->realizarCampistas((int)$this->acampamento->id, 5, 5, 0);

        $depois = $this->recarregar($inscricao);
        $this->assertNull($depois->selection_method_id, 'Não foi sorteado de novo.');
        $this->assertSame(PrazoFormularioService::MOTIVO_CANCELADA, $depois->disqualification_reason);
        $this->assertSame('Cancelada pela organização', $this->situacaoNoSite($pedro, $inscricao)['status']);
    }

    public function testEventoDevolveAVagaParaAsInscricoes(): void
    {
        $secao = (int)$this->salvar('Sections', ['title' => 'Geral', 'order' => 1])->id;
        $form = $this->salvar('EventForms', ['name' => 'Retiro']);
        $pergunta = $this->salvar('Questions', [
            'event_form_id' => $form->id, 'subscription_type' => 'Participante', 'section_id' => $secao,
            'text' => 'Bairro', 'type' => 'texto', 'is_required' => true, 'order' => 1, 'accept_generic_answer' => false,
        ]);
        $evento = $this->salvar('Activities', [
            'name' => 'Retiro teste', 'image' => '', 'place' => 'Igreja', 'year' => 2026,
            'start_date' => '2026-12-10 08:00:00', 'duration_days' => 1, 'total_vacancies' => 1,
            'participant_fee' => 0, 'servant_fee' => 0, 'event_form_id' => $form->id,
        ]);
        $ana = $this->criarUsuario('Ana', sexo: 'F');
        $primeira = $this->inscrever($ana, $evento, 'Participante', ['form_submitted' => true]);
        $bia = $this->criarUsuario('Bia', sexo: 'F');
        $segunda = $this->inscrever($bia, $evento, 'Participante');

        $enviar = function () use ($bia, $segunda, $pergunta) {
            $this->autenticarComo($bia);
            $this->post("/api/v1/inscricoes/{$segunda->id}/formulario", ['answers' => [['question_id' => $pergunta->id, 'answer' => 'Centro']]]);
        };
        $enviar();
        $this->assertResponseCode(422, 'Sem vaga.');

        $this->cancelar($primeira);
        $this->assertResponseOk();
        $this->assertTrue($this->respostaJson()['data']['evento']);
        $enviar();
        $this->assertResponseOk('A vaga voltou para as inscrições.');
        $this->assertSame('Cancelada pela organização', $this->situacaoNoSite($ana, $primeira)['status']);
    }

    public function testPrecisaDeMotivoEDaPermissaoDasRespostas(): void
    {
        $inscricao = $this->inscrever($this->criarUsuario('Pedro'), $this->acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $this->cancelar($inscricao, '   ');
        $this->assertResponseCode(422);

        $editor = $this->criarUsuario('Editor', idade: 40);
        (new PermissoesService())->definir((int)$editor->id, ['formularios', 'inscricoes', 'atividades']);
        $this->cancelar($inscricao, quem: $editor);
        $this->assertResponseCode(403);

        $soInscrito = $this->inscrever($this->criarUsuario('Caio'), $this->acampamento);
        $this->cancelar($soInscrito);
        $this->assertResponseCode(422, 'Quem ainda aguarda sorteio não aparece nas Respostas.');
        $this->assertNull($this->recarregar($inscricao)->disqualified_at);
    }
}
