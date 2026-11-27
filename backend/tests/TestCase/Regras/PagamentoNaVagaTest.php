<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PagamentoService;
use App\Service\PrazoFormularioService;
use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;

/**
 * O pagamento fica na vaga (decidido com o usuário em 2026-11-26): quem tinha
 * vaga, pagou e saiu — por qualquer motivo — deixa o pagamento para o suplente
 * chamado no lugar, que entra já como pago. Não há devolução de dinheiro. O
 * pagamento só passa dentro da mesma vaga: grupo do campista (Homem, Mulher,
 * Casal) ou setor do servo.
 */
class PagamentoNaVagaTest extends RegrasTestCase
{
    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Tesouraria', idade: 40, admin: true);
    }

    private function acampamento(string $categoria = 'FAC', array $dados = []): EntityInterface
    {
        return $this->criarAcampamento($categoria, [], $dados + [
            'participant_fee' => 120,
            'participant_payment_date' => date('Y-m-d 23:59:00', strtotime('+5 days')),
        ]);
    }

    private function titular(EntityInterface $atividade, string $nome, bool $pagou, array $usuario = [], array $extra = []): EntityInterface
    {
        return $this->inscrever($this->criarUsuario($nome, ...$usuario), $atividade, 'Campista', $extra + [
            'selection_method_id' => $this->metodos['Sorteio'],
            'form_submitted' => true,
            'is_fee_paid' => $pagou,
        ]);
    }

    private function suplente(EntityInterface $atividade, string $nome, int $posicao = 1, array $usuario = [], array $extra = []): EntityInterface
    {
        return $this->inscrever($this->criarUsuario($nome, ...$usuario), $atividade, 'Campista', $extra + [
            'substitute_position' => $posicao,
            'form_submitted' => true,
        ]);
    }

    private function cancelar(EntityInterface $inscricao): void
    {
        (new PrazoFormularioService())->cancelarPelaOrganizacao($this->recarregar($inscricao), 'Pediu para sair', (int)$this->admin->id);
    }

    private function chamarProximo(EntityInterface $atividade, array $dados): array
    {
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/activities/{$atividade->id}/sorteio/chamar-proximo", $dados);
        $this->assertResponseOk();

        return $this->respostaJson();
    }

    /** O resumo do pagamento de uma inscrição na lista do painel. */
    private function pagamentoNaLista(EntityInterface $atividade, EntityInterface $inscricao): array
    {
        $this->autenticarComo($this->admin);
        $this->get("/api/v1/activities/{$atividade->id}/subscriptions");
        foreach ($this->respostaJson()['subscriptions'] as $s) {
            if ($s['id'] === $inscricao->id) {
                return $s['pagamento'];
            }
        }
        $this->fail('A inscrição não apareceu na lista.');
    }

    public function testSorteadoQuePagouESaiuDeixaOPagamentoParaOSuplente(): void
    {
        $fac = $this->acampamento();
        $saiu = $this->titular($fac, 'Pedro', pagou: true);
        $suplente = $this->suplente($fac, 'Lucas');

        $this->cancelar($saiu);
        $resposta = $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);

        $this->assertSame(['Lucas'], $resposta['semPagar']);
        $chamado = $this->recarregar($suplente);
        $this->assertTrue((bool)$chamado->is_fee_paid, 'Quem entra na vaga de quem pagou não precisa pagar.');
        $this->assertSame($saiu->id, $chamado->fee_inherited_from_id);

        // Não perde a vaga por "não pagar", mesmo depois do prazo.
        $chamado->promoted_at = DateTime::now()->subDays(10);
        $this->fetchTable('PreRegistrations')->saveOrFail($chamado);
        $this->fetchTable('Activities')->updateAll(['participant_payment_date' => DateTime::now()->subDays(5)->format('Y-m-d H:i:s')], ['id' => $fac->id]);
        (new PrazoFormularioService())->executarPrazosVencidos();
        $this->assertNull($this->recarregar($suplente)->disqualified_at);

        $doChamado = $this->pagamentoNaLista($fac, $suplente);
        $this->assertSame('pago', $doChamado['situacao']);
        $this->assertTrue($doChamado['herdado']);
        $this->assertSame('Pedro', $doChamado['herdadoDe']);
        $deQuemSaiu = $this->pagamentoNaLista($fac, $saiu);
        $this->assertSame(date('Y-m-d'), $deQuemSaiu['saiuEm']);
        $this->assertSame('Lucas', $deQuemSaiu['passouPara']);
    }

    public function testQuemPagouAdiantadoEPerdeuOPrazoDoFormularioTambemDeixaOPagamento(): void
    {
        $fac = $this->acampamento();
        $this->criarPergunta('FAC');
        $this->fetchTable('Campings')->updateAll(['camper_form_deadline' => DateTime::now()->subDays(1)->format('Y-m-d H:i:s')], ['id' => $fac->camping_id]);
        $saiu = $this->titular($fac, 'Pedro', pagou: true, extra: ['form_submitted' => false]);
        $suplente = $this->suplente($fac, 'Lucas');

        (new PrazoFormularioService())->executarPrazosVencidos();
        $this->assertSame(PrazoFormularioService::MOTIVO_PRAZO_FORMULARIO, $this->recarregar($saiu)->disqualification_reason);

        $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);
        $this->assertTrue((bool)$this->recarregar($suplente)->is_fee_paid);
    }

    public function testQuemSaiuSemPagarNaoDeixaPagamentoNenhum(): void
    {
        $fac = $this->acampamento();
        $saiu = $this->titular($fac, 'Pedro', pagou: false);
        $suplente = $this->suplente($fac, 'Lucas');

        $this->cancelar($saiu);
        $resposta = $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);

        $this->assertSame([], $resposta['semPagar']);
        $this->assertFalse((bool)$this->recarregar($suplente)->is_fee_paid);
        $this->assertTrue($this->pagamentoNaLista($fac, $suplente)['precisaPagar']);
    }

    public function testOPagamentoSoPassaDentroDoMesmoGrupo(): void
    {
        $fac = $this->acampamento();
        $mulherPagou = $this->titular($fac, 'Ana', pagou: true, usuario: ['sexo' => 'F']);
        $homemNaoPagou = $this->titular($fac, 'Pedro', pagou: false);
        $suplenteHomem = $this->suplente($fac, 'Lucas', 1);
        $suplenteMulher = $this->suplente($fac, 'Maria', 2, ['sexo' => 'F']);

        $this->cancelar($mulherPagou);
        $this->cancelar($homemNaoPagou);
        $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);
        $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Mulher']);

        $this->assertFalse((bool)$this->recarregar($suplenteHomem)->is_fee_paid, 'A vaga de homem era de quem não pagou.');
        $this->assertTrue((bool)$this->recarregar($suplenteMulher)->is_fee_paid);
    }

    public function testCasalQuePagouESaiuDeixaOPagamentoParaOCasalChamado(): void
    {
        $casais = $this->acampamento('Casais');
        $casado = ['idade' => 35, 'estadoCivil' => 'Casado'];
        $ele = $this->titular($casais, 'João', true, $casado);
        $ela = $this->titular($casais, 'Joana', true, $casado + ['sexo' => 'F'], ['spouse_id' => $ele->user_id]);
        $this->fetchTable('PreRegistrations')->updateAll(['spouse_id' => $ela->user_id], ['id' => $ele->id]);
        $sup1 = $this->suplente($casais, 'Paulo', 1, $casado);
        $sup2 = $this->suplente($casais, 'Paula', 1, $casado + ['sexo' => 'F'], ['spouse_id' => $sup1->user_id]);
        $this->fetchTable('PreRegistrations')->updateAll(['spouse_id' => $sup2->user_id], ['id' => $sup1->id]);

        $this->cancelar($ele);
        $this->assertNotNull($this->recarregar($ela)->disqualified_at, 'O casal sai junto.');
        $resposta = $this->chamarProximo($casais, ['tipo' => 'Campista', 'grupo' => 'Casado']);

        $this->assertEqualsCanonicalizing(['Paulo', 'Paula'], $resposta['semPagar']);
        $herdados = [$this->recarregar($sup1)->fee_inherited_from_id, $this->recarregar($sup2)->fee_inherited_from_id];
        $this->assertEqualsCanonicalizing([$ele->id, $ela->id], $herdados, 'Cada um do casal chamado recebe um dos dois pagamentos.');
    }

    public function testSuplenteQueJaTinhaPagadoNaoGastaOPagamentoDeQuemSaiu(): void
    {
        $fac = $this->acampamento();
        $saiu1 = $this->titular($fac, 'Pedro', pagou: true);
        $saiu2 = $this->titular($fac, 'Tiago', pagou: false);
        $adiantado = $this->suplente($fac, 'Lucas', 1, extra: ['is_fee_paid' => true]);
        $segundo = $this->suplente($fac, 'Mateus', 2);

        $this->cancelar($saiu1);
        $this->cancelar($saiu2);
        $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);
        $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);

        $this->assertNull($this->recarregar($adiantado)->fee_inherited_from_id);
        $this->assertSame($saiu1->id, $this->recarregar($segundo)->fee_inherited_from_id);
    }

    public function testServoQuePagouESaiuDeixaOPagamentoParaQuemEntraNoSetor(): void
    {
        $atividade = $this->criarAcampamento('FAC', [], [
            'servant_fee' => 50,
            'servant_payment_date' => date('Y-m-d 23:59:00', strtotime('+5 days')),
        ]);
        $setor = $this->salvar('Sectors', ['name' => 'Louvor', 'place' => 'Interno']);
        $categoriaSetor = $this->salvar('CategoriesSectors', ['category_id' => $atividade->category_id, 'sector_id' => $setor->id, 'vacancies' => 1]);
        $louvor = $this->salvar('ActivitiesCategoriesSectors', [
            'categories_sectors_id' => $categoriaSetor->id, 'activity_id' => $atividade->id, 'actual_vacancies' => 1,
        ]);
        foreach (['Servo A', 'Servo B'] as $nome) {
            $this->inscrever($this->criarUsuario($nome, idade: 25), $atividade, 'Servo', ['form_submitted' => true]);
        }
        (new SorteioService())->realizarServos((int)$atividade->id);
        $equipe = $this->fetchTable('ActivitiesCategoriesSectorsUsers');
        $titularId = $equipe->find()->where(['activities_categories_sector_id' => $louvor->id, 'is_substitute' => false])->firstOrFail()->user_id;
        $suplenteId = $equipe->find()->where(['activities_categories_sector_id' => $louvor->id, 'is_substitute' => true])->firstOrFail()->user_id;
        $inscricoes = $this->fetchTable('PreRegistrations');
        $titular = $inscricoes->find()->where(['user_id' => $titularId, 'activity_id' => $atividade->id])->firstOrFail();
        $titular->is_fee_paid = true;
        $inscricoes->saveOrFail($titular);

        $this->cancelar($titular);
        $this->assertSame($louvor->id, $this->recarregar($titular)->vacancy_sector_id);
        $resposta = $this->chamarProximo($atividade, ['tipo' => 'Servo', 'setor' => $louvor->id]);

        $this->assertCount(1, $resposta['semPagar']);
        $chamado = $inscricoes->find()->where(['user_id' => $suplenteId, 'activity_id' => $atividade->id])->firstOrFail();
        $this->assertTrue((bool)$chamado->is_fee_paid);
        $this->assertSame($titular->id, $chamado->fee_inherited_from_id);
    }

    public function testDesfazerOPagamentoQueJaPassouSoDepoisDeDesfazerODeQuemEntrou(): void
    {
        $fac = $this->acampamento();
        $saiu = $this->titular($fac, 'Pedro', pagou: true);
        $suplente = $this->suplente($fac, 'Lucas');
        $this->cancelar($saiu);
        $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);

        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/admin/inscricoes/{$saiu->id}/pagamento");
        $this->assertResponseCode(422);
        $this->assertTrue((bool)$this->recarregar($saiu)->is_fee_paid);

        // Desfazendo o de quem entrou, ele volta a precisar pagar e o pagamento fica livre de novo.
        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/admin/inscricoes/{$suplente->id}/pagamento");
        $this->assertResponseOk();
        $chamado = $this->recarregar($suplente);
        $this->assertFalse((bool)$chamado->is_fee_paid);
        $this->assertNull($chamado->fee_inherited_from_id);
        $this->assertCount(1, (new PagamentoService())->creditosLivres((int)$fac->id, 'Campista'));
    }

    public function testSoQuemTemVagaEntraNoAReceber(): void
    {
        $fac = $this->acampamento();
        $semFormulario = $this->titular($fac, 'Pedro', pagou: false, extra: ['form_submitted' => false]);
        $naFila = $this->suplente($fac, 'Lucas');

        $this->assertTrue($this->pagamentoNaLista($fac, $semFormulario)['temVaga'], 'Sorteado deve, mesmo antes do formulário.');
        $this->assertFalse($this->pagamentoNaLista($fac, $naFila)['temVaga'], 'Suplente na fila ainda não deve nada.');
    }

    public function testAPessoaNaoVeONomeDeQuemSaiuDaVaga(): void
    {
        $fac = $this->acampamento();
        $saiu = $this->titular($fac, 'Pedro', pagou: true);
        $suplente = $this->suplente($fac, 'Lucas');
        $this->cancelar($saiu);
        $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);

        $this->autenticarComo($this->fetchTable('Users')->get($suplente->user_id));
        $this->get('/api/v1/me/inscricoes');
        $minha = array_values(array_filter($this->respostaJson()['data'], fn($i) => $i['id'] === $suplente->id))[0];
        $this->assertTrue($minha['pagamento']['herdado']);
        $this->assertArrayNotHasKey('herdadoDe', $minha['pagamento']);
    }
}
