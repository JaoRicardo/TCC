<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PrazoFormularioService;
use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;

/**
 * Tela Inscritos e sorteio (handoff design_handoff_inscritos_sorteio):
 * - as vagas do sorteio vêm do cadastro do acampamento (a tela só mostra);
 * - "Sortear de novo" e "Apagar resultado" ficam bloqueados quando alguém com
 *   vaga pelo sorteio ou na fila já enviou o formulário ou já pagou;
 * - a leitura manda quem concorre, quem não concorre (cidade), o cônjuge, o
 *   setor do servo e o novo prazo de quem é chamado da fila.
 */
class InscritosSorteioTest extends RegrasTestCase
{
    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Coordenação', idade: 40, admin: true);
    }

    private function sorteio(EntityInterface $atividade): array
    {
        $this->autenticarComo($this->admin);
        $this->get("/api/v1/activities/{$atividade->id}/sorteio");
        $this->assertResponseOk();

        return $this->respostaJson();
    }

    private function sortear(EntityInterface $atividade): void
    {
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/activities/{$atividade->id}/sorteio/campistas");
    }

    public function testSorteioUsaAsVagasDoCadastro(): void
    {
        $fac = $this->criarAcampamento('FAC', ['raffle_man_vacancies' => 1, 'raffle_woman_vacancies' => 0]);
        foreach (['Ana', 'Bia'] as $nome) $this->inscrever($this->criarUsuario($nome, sexo: 'F'), $fac);
        foreach (['Caio', 'Davi', 'Edu'] as $nome) $this->inscrever($this->criarUsuario($nome), $fac);

        // O que a tela mandar de vagas é ignorado: valem as do cadastro.
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/activities/{$fac->id}/sorteio/campistas", ['homem' => 5, 'mulher' => 5]);
        $this->assertResponseOk();

        $campistas = $this->sorteio($fac)['campistas'];
        $this->assertCount(1, $campistas['sorteados']);
        $this->assertSame('Homem', $campistas['sorteados'][0]['categoria']);
        $this->assertSame(['Homem' => 1, 'Mulher' => 0, 'Casado' => 0], $campistas['vagas']);
        $this->assertNotNull($campistas['feito_em']);
    }

    public function testAntesDoSorteioDizQuemConcorreEQuemNao(): void
    {
        $fac = $this->criarAcampamento('FAC', ['raffle_man_vacancies' => 2]);
        $this->inscrever($this->criarUsuario('Caio'), $fac);
        $this->inscrever($this->criarUsuario('Bia', sexo: 'F'), $fac);
        $this->inscrever($this->criarUsuario('Beto de Cuiabá', cidade: 'Cuiabá'), $fac);

        $campistas = $this->sorteio($fac)['campistas'];
        $this->assertFalse($campistas['realizado']);
        $this->assertSame(['Homem' => 1, 'Mulher' => 1, 'Casado' => 0], $campistas['concorrem']);
        $this->assertCount(1, $campistas['nao_concorrem']);
        $this->assertSame('Beto de Cuiabá', $campistas['nao_concorrem'][0]['nome']);
        $this->assertSame('Cuiabá', $campistas['nao_concorrem'][0]['cidade']);
    }

    public function testRefazerEApagarFicamBloqueadosDepoisDoFormularioOuDoPagamento(): void
    {
        $fac = $this->criarAcampamento('FAC', ['raffle_man_vacancies' => 1]);
        $this->inscrever($this->criarUsuario('Caio'), $fac);
        $this->inscrever($this->criarUsuario('Davi'), $fac);
        $this->sortear($fac);
        $this->assertResponseOk();

        // Ninguém enviou nem pagou: dá para sortear de novo.
        $this->assertSame(['formularios' => 0, 'pagos' => 0], $this->sorteio($fac)['campistas']['bloqueio']);
        $this->sortear($fac);
        $this->assertResponseOk();

        // O suplente adiantou o pagamento: bloqueia.
        $suplente = $this->fetchTable('PreRegistrations')->find()
            ->where(['activity_id' => $fac->id, 'substitute_position IS NOT' => null])
            ->firstOrFail();
        $this->fetchTable('PreRegistrations')->updateAll(['is_fee_paid' => true], ['id' => $suplente->id]);
        $this->assertSame(['formularios' => 0, 'pagos' => 1], $this->sorteio($fac)['campistas']['bloqueio']);

        $this->sortear($fac);
        $this->assertResponseCode(422);
        $this->assertSame('sorteio_bloqueado', $this->respostaJson()['codigo']);

        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/activities/{$fac->id}/sorteio/campistas");
        $this->assertResponseCode(422);
        $this->assertTrue((new SorteioService())->campistasJaRealizado((int)$fac->id), 'O resultado não podia ter sido apagado.');
    }

    public function testListaMandaConjugeCidadeEFormulario(): void
    {
        $casais = $this->criarAcampamento('Casais');
        $marido = $this->criarUsuario('Pedro', idade: 35, estadoCivil: 'Casado');
        $esposa = $this->criarUsuario('Ana', idade: 33, sexo: 'F', estadoCivil: 'Casado', cidade: 'Cuiabá');
        $insMarido = $this->inscrever($marido, $casais, 'Campista', ['spouse_id' => $esposa->id, 'form_submitted' => true]);
        $this->inscrever($esposa, $casais);

        $this->autenticarComo($this->admin);
        $this->get("/api/v1/activities/{$casais->id}/subscriptions");
        $lista = array_column($this->respostaJson()['subscriptions'], null, 'id');

        $doMarido = $lista[$insMarido->id];
        $this->assertSame('Ana', $doMarido['conjuge']['nome']);
        $this->assertTrue($doMarido['daArea']);
        $this->assertTrue($doMarido['formularioEnviado']);

        $daEsposa = array_values(array_filter($lista, fn($s) => $s['user']['name'] === 'Ana'))[0];
        $this->assertSame('Pedro', $daEsposa['conjuge']['nome'], 'O vínculo vale nos dois sentidos.');
        $this->assertFalse($daEsposa['daArea']);
        $this->assertSame('Cuiabá', $daEsposa['cidade']);
    }

    public function testChamarProximoDevolveONovoPrazo(): void
    {
        $fac = $this->criarAcampamento('FAC', ['raffle_man_vacancies' => 1], [
            'start_date' => date('Y-m-d 00:00:00', strtotime('+20 days')),
            'participant_payment_date' => date('Y-m-d 23:59:00', strtotime('-1 day')),
        ]);
        $this->inscrever($this->criarUsuario('Caio'), $fac);
        $this->inscrever($this->criarUsuario('Davi'), $fac);
        (new SorteioService())->realizarCampistas((int)$fac->id, 1, 0, 0);

        // O sorteado perdeu a vaga por prazo: abre uma vaga de homem.
        $this->fetchTable('PreRegistrations')->updateAll(
            ['disqualified_at' => $this->ontem(), 'disqualification_reason' => PrazoFormularioService::MOTIVO_PRAZO_FORMULARIO],
            ['activity_id' => $fac->id, 'selection_method_id IS NOT' => null]
        );

        $this->autenticarComo($this->admin);
        $this->post("/api/v1/activities/{$fac->id}/sorteio/chamar-proximo", ['tipo' => 'Campista', 'grupo' => 'Homem']);
        $this->assertResponseOk();
        $resposta = $this->respostaJson();
        $this->assertCount(1, $resposta['chamados']);
        // Prazo do acampamento já passou: valem os 3 dias a partir de hoje.
        $this->assertSame(date('Y-m-d', strtotime('+3 days')), substr((string)$resposta['prazo'], 0, 10));
    }
}
