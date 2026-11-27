<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PermissoesService;
use App\Service\PrazoFormularioService;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;

/**
 * Pagamento registrado à mão pela organização (Inscritos e Sorteios ›
 * Pagamentos): sem pagamento online, a pessoa paga direto com a organização
 * (dinheiro ou Pix). Vale para acampamento e evento. Quem precisa pagar e não
 * tem pagamento registrado perde a vaga 2 dias depois do prazo.
 */
class PagamentoManualTest extends RegrasTestCase
{
    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Tesouraria', idade: 40, admin: true);
    }

    private function facPago(?string $prazo = null): EntityInterface
    {
        return $this->criarAcampamento('FAC', [], [
            'participant_fee' => 120,
            'participant_payment_date' => $prazo ?? date('Y-m-d 23:59:00', strtotime('+5 days')),
        ]);
    }

    private function eventoPago(string $prazo, array $dados = []): EntityInterface
    {
        return $this->salvar('Activities', $dados + [
            'name' => 'Vigília', 'image' => '', 'place' => 'Igreja', 'year' => 2026,
            'start_date' => date('Y-m-d 00:00:00', strtotime('+10 days')), 'duration_days' => 1,
            'total_vacancies' => 1, 'participant_fee' => 20, 'servant_fee' => 0,
            'participant_registration_start_date' => date('Y-m-d 00:00:00', strtotime('-10 days')),
            'participant_registration_end_date' => date('Y-m-d 23:59:00', strtotime('+9 days')),
            'participant_payment_date' => $prazo,
        ]);
    }

    private function enviouFormulario(EntityInterface $atividade, string $nome): EntityInterface
    {
        return $this->inscrever($this->criarUsuario($nome), $atividade, 'Campista', [
            'selection_method_id' => $this->metodos['Sorteio'], 'form_submitted' => true,
        ]);
    }

    private function marcar(EntityInterface $inscricao, array $dados = [], ?EntityInterface $quem = null): void
    {
        $this->autenticarComo($quem ?? $this->admin);
        $this->post("/api/v1/admin/inscricoes/{$inscricao->id}/pagamento", $dados + [
            'forma' => 'pix', 'data' => date('Y-m-d'), 'observacao' => 'Pagou na secretaria',
        ]);
    }

    private function desfazer(EntityInterface $inscricao): void
    {
        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/admin/inscricoes/{$inscricao->id}/pagamento");
    }

    /** O resumo do pagamento de uma inscrição na lista de inscritos do painel. */
    private function naLista(EntityInterface $atividade, EntityInterface $inscricao): array
    {
        $this->autenticarComo($this->admin);
        $this->get("/api/v1/activities/{$atividade->id}/subscriptions");
        foreach ($this->respostaJson()['subscriptions'] as $s) {
            if ($s['id'] === $inscricao->id) return $s;
        }
        $this->fail('A inscrição não apareceu na lista.');
    }

    public function testRegistrarPagamentoGuardaComoQuandoEQuemEVaiParaAFicha(): void
    {
        $fac = $this->facPago();
        $inscricao = $this->enviouFormulario($fac, 'Pedro');

        $this->marcar($inscricao, ['data' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertResponseOk();

        $pago = $this->recarregar($inscricao);
        $this->assertTrue((bool)$pago->is_fee_paid);
        $this->assertSame('pix', $pago->fee_payment_method);
        $this->assertSame(date('Y-m-d', strtotime('-1 day')), $pago->fee_paid_on->format('Y-m-d'));
        $this->assertSame($this->admin->id, $pago->fee_registered_by);

        $linha = $this->naLista($fac, $inscricao)['pagamento'];
        $this->assertSame('pago', $linha['situacao']);
        $this->assertSame('Tesouraria', $linha['registradoPor']);
        $this->assertSame('Pagou na secretaria', $linha['observacao']);

        $log = $this->fetchTable('UserChangeLogs')->find()->where(['user_id' => $inscricao->user_id])->firstOrFail();
        $this->assertSame($this->admin->id, $log->author_id);
        $this->assertStringContainsString('Registrou o pagamento de', $log->description);
        $this->assertStringContainsString('R$ 120,00 · Pix', $log->description);
        $this->assertStringContainsString('Observação: “Pagou na secretaria”', $log->description);
    }

    public function testFormaEDataSaoObrigatoriasEADataNaoPodeSerFutura(): void
    {
        $inscricao = $this->enviouFormulario($this->facPago(), 'Pedro');

        $this->marcar($inscricao, ['forma' => '', 'data' => date('Y-m-d', strtotime('+1 day'))]);
        $this->assertResponseCode(422);
        $erros = $this->respostaJson()['errors'];
        $this->assertSame('Escolha a forma de pagamento.', $erros['forma']);
        $this->assertSame('A data do pagamento não pode ser depois de hoje.', $erros['data']);
        $this->assertFalse((bool)$this->recarregar($inscricao)->is_fee_paid);
    }

    public function testDaParaPagarAdiantadoMasNaoEmInscricaoEncerradaOuGratuita(): void
    {
        $fac = $this->facPago();
        // Sorteada que ainda não enviou o formulário: ainda não precisa pagar, mas pode.
        $adiantada = $this->inscrever($this->criarUsuario('Ana', sexo: 'F'), $fac, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $this->assertFalse($this->naLista($fac, $adiantada)['pagamento']['precisaPagar']);
        $this->marcar($adiantada);
        $this->assertResponseOk();

        $desistiu = $this->inscrever($this->criarUsuario('Desistiu'), $fac, 'Campista', ['is_quitter' => true]);
        $this->marcar($desistiu);
        $this->assertResponseCode(422);

        $gratuito = $this->enviouFormulario($this->criarAcampamento('FAC'), 'Gratuito');
        $this->marcar($gratuito);
        $this->assertResponseCode(422);
    }

    public function testSoQuemTemInscritosESorteiosRegistra(): void
    {
        $inscricao = $this->enviouFormulario($this->facPago(), 'Pedro');
        $conselheiro = $this->criarUsuario('Conselheiro', idade: 50);
        (new PermissoesService())->definir((int)$conselheiro->id, ['formularios']);

        $this->marcar($inscricao, [], $conselheiro);
        $this->assertResponseCode(403);

        (new PermissoesService())->definir((int)$conselheiro->id, ['inscricoes']);
        $this->marcar($inscricao, [], $conselheiro);
        $this->assertResponseOk();
    }

    public function testDesfazerApagaORegistroEFicaNoHistorico(): void
    {
        $fac = $this->facPago();
        $inscricao = $this->enviouFormulario($fac, 'Pedro');
        $this->marcar($inscricao);

        $this->desfazer($inscricao);
        $this->assertResponseOk();

        $desfeito = $this->recarregar($inscricao);
        $this->assertFalse((bool)$desfeito->is_fee_paid);
        $this->assertNull($desfeito->fee_paid_on);
        $this->assertNull($desfeito->fee_payment_method);
        $this->assertNull($desfeito->fee_registered_by);
        $this->assertSame('nao_pago', $this->naLista($fac, $inscricao)['pagamento']['situacao']);
        $this->assertSame(1, $this->fetchTable('UserChangeLogs')->find()
            ->where(['user_id' => $inscricao->user_id, 'description LIKE' => 'Desfez o pagamento de%'])
            ->count());
    }

    public function testDesfazerDepoisDaFolgaTiraAVagaNaHora(): void
    {
        $fac = $this->facPago(DateTime::now()->subDays(3)->format('Y-m-d H:i:s'));
        $inscricao = $this->enviouFormulario($fac, 'Pedro');
        // Pago no prazo, registrado a tempo: a varredura não mexe.
        $this->marcar($inscricao, ['data' => date('Y-m-d', strtotime('-4 days'))]);
        (new PrazoFormularioService())->executarPrazosVencidos();
        $this->assertNull($this->recarregar($inscricao)->disqualified_at);

        $this->desfazer($inscricao);

        $this->assertSame(PrazoFormularioService::MOTIVO_PRAZO_PAGAMENTO, $this->recarregar($inscricao)->disqualification_reason);
        $this->assertSame('perdeu_vaga', $this->respostaJson()['data']['situacao']);
    }

    public function testEventoPagoTiraAVagaDepoisDaFolgaEAVagaVolta(): void
    {
        $evento = $this->eventoPago(DateTime::now()->subDays(3)->format('Y-m-d 23:59:00'));
        $pessoa = $this->criarUsuario('Participante', idade: 20);
        $this->autenticarComo($pessoa);
        $this->post("/api/v1/activities/{$evento->id}/inscricoes", ['tipo' => 'participante']);
        $this->assertResponseCode(201);

        (new PrazoFormularioService())->executarPrazosVencidos();

        $inscricao = $this->fetchTable('PreRegistrations')->find()->where(['user_id' => $pessoa->id])->firstOrFail();
        $this->assertSame(PrazoFormularioService::MOTIVO_PRAZO_PAGAMENTO, $inscricao->disqualification_reason);

        $this->autenticarComo($pessoa);
        $this->get('/api/v1/me/inscricoes');
        $dela = $this->respostaJson()['data'][0];
        $this->assertSame('Prazo Perdido', $dela['status']);
        $this->assertSame('pagamento', $dela['perdeuPor']);

        // A única vaga voltou: outra pessoa consegue se inscrever.
        $this->autenticarComo($this->criarUsuario('Outra', idade: 20));
        $this->post("/api/v1/activities/{$evento->id}/inscricoes", ['tipo' => 'participante']);
        $this->assertResponseCode(201);
    }

    public function testEventoComPrazoNoPrimeiroDiaValeAte2359(): void
    {
        $inicio = date('Y-m-d 00:00:00', strtotime('+10 days'));
        $evento = $this->eventoPago(date('Y-m-d 23:59:00', strtotime('+10 days')), ['start_date' => $inicio]);
        $inscricao = $this->inscrever($this->criarUsuario('Participante', idade: 20), $evento, 'Participante');

        $this->assertSame(
            date('Y-m-d', strtotime('+10 days')) . 'T23:59',
            $this->naLista($evento, $inscricao)['pagamento']['prazo'],
            'O prazo no primeiro dia do evento não pode ser cortado para 00h00.',
        );
    }

    public function testPagoQueDepoisFoiCanceladoContinuaComoPagoComAviso(): void
    {
        $fac = $this->facPago();
        $inscricao = $this->enviouFormulario($fac, 'Pedro');
        $this->marcar($inscricao);
        (new PrazoFormularioService())->cancelarPelaOrganizacao($this->recarregar($inscricao), 'Pediu para sair', (int)$this->admin->id);

        $linha = $this->naLista($fac, $inscricao)['pagamento'];
        $this->assertSame('pago', $linha['situacao']);
        $this->assertSame(date('Y-m-d'), $linha['saiuEm']);
    }
}
