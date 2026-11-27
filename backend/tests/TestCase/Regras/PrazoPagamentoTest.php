<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PagamentoService;
use App\Service\PermissoesService;
use App\Service\PrazoFormularioService;
use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;

/**
 * Prazo do pagamento: quem tem vaga, enviou o formulário e não tem pagamento
 * registrado perde a vaga sozinho — 2 dias depois da data de pagamento (folga
 * para a equipe registrar quem pagou no último dia). A vaga fica aberta até um
 * administrador ou conselheiro chamar o próximo suplente — e quem é chamado tem
 * 3 dias, a partir de quando foi chamado, para pagar.
 */
class PrazoPagamentoTest extends RegrasTestCase
{
    private function facComTaxa(?string $dataPagamento, float $taxa = 120): EntityInterface
    {
        return $this->criarAcampamento('FAC', [], [
            'participant_fee' => $taxa,
            'participant_payment_date' => $dataPagamento,
        ]);
    }

    /** Prazo que venceu antes dos 2 dias de folga: a vaga já pode sair. */
    private function prazoSemFolga(): string
    {
        return DateTime::now()->subDays(3)->format('Y-m-d H:i:s');
    }

    private function titular(EntityInterface $atividade, string $nome, array $extra = []): EntityInterface
    {
        return $this->inscrever($this->criarUsuario($nome), $atividade, 'Campista', $extra + [
            'selection_method_id' => $this->metodos['Sorteio'],
            'form_submitted' => true,
        ]);
    }

    private function chamarProximo(EntityInterface $atividade, array $dados, ?EntityInterface $quem = null): void
    {
        $this->autenticarComo($quem ?? $this->criarUsuario('Admin', idade: 40, admin: true));
        $this->post("/api/v1/activities/{$atividade->id}/sorteio/chamar-proximo", $dados);
    }

    public function testQuemNaoPagouNoPrazoPerdeAVagaEAVagaFicaAberta(): void
    {
        $fac = $this->facComTaxa($this->prazoSemFolga());
        $pagou = $this->titular($fac, 'Pagou', ['is_fee_paid' => true]);
        $naoPagou = $this->titular($fac, 'Não pagou');
        $suplente = $this->inscrever($this->criarUsuario('Suplente'), $fac, 'Campista', ['substitute_position' => 1, 'form_submitted' => true]);

        $prazos = new PrazoFormularioService();
        $prazos->executarPrazosVencidos();

        $perdeu = $this->recarregar($naoPagou);
        $this->assertNotNull($perdeu->disqualified_at);
        $this->assertSame(PrazoFormularioService::MOTIVO_PRAZO_PAGAMENTO, $perdeu->disqualification_reason);
        $this->assertNull($this->recarregar($pagou)->disqualified_at);
        $this->assertSame(1, $this->recarregar($suplente)->substitute_position, 'O suplente não pode entrar sozinho.');
        $this->assertSame(1, $prazos->vagasAbertasCampistas((int)$fac->id)['Homem']);
    }

    public function testQuemAindaNaoEnviouOFormularioNaoCaiNaRegraDoPagamento(): void
    {
        // Sem prazo de formulário cadastrado: quem não enviou não pode perder a vaga
        // "por não pagar" — ele nem chegou na etapa do pagamento.
        $fac = $this->facComTaxa($this->prazoSemFolga());
        $semFormulario = $this->titular($fac, 'Sem formulário', ['form_submitted' => false]);

        (new PrazoFormularioService())->executarPrazosVencidos();

        $this->assertNull($this->recarregar($semFormulario)->disqualified_at);
    }

    public function testSemTaxaOuSemDataDePagamentoNinguemPerdeAVaga(): void
    {
        $semTaxa = $this->titular($this->facComTaxa($this->prazoSemFolga(), taxa: 0), 'Acampamento sem taxa');
        $semData = $this->titular($this->facComTaxa(null), 'Acampamento sem data');
        $noPrazo = $this->titular($this->facComTaxa($this->amanha()), 'Ainda no prazo');

        (new PrazoFormularioService())->executarPrazosVencidos();

        foreach ([$semTaxa, $semData, $noPrazo] as $inscricao) {
            $this->assertNull($this->recarregar($inscricao)->disqualified_at);
        }
    }

    public function testSuplenteChamadoDepoisDaDataTem3DiasParaPagar(): void
    {
        $fac = $this->facComTaxa($this->prazoSemFolga());
        $this->titular($fac, 'Não pagou');
        $suplente = $this->inscrever($this->criarUsuario('Suplente'), $fac, 'Campista', ['substitute_position' => 1, 'form_submitted' => true]);
        $prazos = new PrazoFormularioService();
        $prazos->executarPrazosVencidos();

        $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);
        $this->assertResponseOk();

        // Chamado depois da data de pagamento, mas não perde a vaga na hora.
        $prazos->executarPrazosVencidos();
        $chamado = $this->recarregar($suplente);
        $this->assertNull($chamado->disqualified_at, 'Quem foi chamado não pode entrar com o prazo de pagamento já vencido.');
        $prazo = (new PagamentoService())->prazo($chamado, $fac);
        // Até 23h59 do 3º dia depois da chamada (vale também para o formulário).
        $this->assertSame($chamado->promoted_at->addDays(3)->format('Y-m-d') . ' 23:59', $prazo->format('Y-m-d H:i'));

        // Passados os 3 dias (e os 2 de folga) sem pagar, perde a vaga e ela abre de novo.
        $chamado->promoted_at = DateTime::now()->subDays(6);
        $this->fetchTable('PreRegistrations')->saveOrFail($chamado);
        $prazos->executarPrazosVencidos();
        $this->assertSame(PrazoFormularioService::MOTIVO_PRAZO_PAGAMENTO, $this->recarregar($suplente)->disqualification_reason);
        $this->assertSame(1, $prazos->vagasAbertasCampistas((int)$fac->id)['Homem']);
    }

    public function testPrazoDeQuemFoiChamadoNuncaPassaDoInicioDoAcampamento(): void
    {
        // Começa daqui a 1 dia: os 3 dias do chamado passariam do início.
        $inicio = DateTime::now()->addDays(1)->format('Y-m-d H:i:s');
        $fac = $this->criarAcampamento('FAC', [], [
            'participant_fee' => 120,
            'participant_payment_date' => $this->prazoSemFolga(),
            'start_date' => $inicio,
        ]);
        $this->titular($fac, 'Não pagou');
        $suplente = $this->inscrever($this->criarUsuario('Suplente'), $fac, 'Campista', ['substitute_position' => 1, 'form_submitted' => true]);
        (new PrazoFormularioService())->executarPrazosVencidos();

        $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);
        $this->assertResponseOk();

        $prazo = (new PagamentoService())->prazo($this->recarregar($suplente), $fac);
        // As datas não têm hora: o limite é o fim (23h59) do primeiro dia.
        $this->assertSame(substr($inicio, 0, 10) . ' 23:59:00', $prazo->format('Y-m-d H:i:s'));
    }

    public function testDepoisDoInicioNaoDaMaisParaChamarSuplente(): void
    {
        $fac = $this->criarAcampamento('FAC', [], [
            'participant_fee' => 120,
            'participant_payment_date' => DateTime::now()->subDays(4)->format('Y-m-d H:i:s'),
            'start_date' => $this->ontem(),
        ]);
        $this->titular($fac, 'Não pagou');
        $suplente = $this->inscrever($this->criarUsuario('Suplente'), $fac, 'Campista', ['substitute_position' => 1, 'form_submitted' => true]);
        (new PrazoFormularioService())->executarPrazosVencidos();

        $this->chamarProximo($fac, ['tipo' => 'Campista', 'grupo' => 'Homem']);
        $this->assertResponseCode(422);
        $this->assertSame(1, $this->recarregar($suplente)->substitute_position);
    }

    public function testCadastroRecusaDataDePagamentoDepoisDoInicio(): void
    {
        $atividades = $this->fetchTable('Activities');
        $fac = $this->facComTaxa(null);

        foreach (['participant_payment_date', 'servant_payment_date'] as $campo) {
            $depois = $atividades->patchEntity($atividades->get($fac->id), [$campo => '2026-12-06 08:00:00']);
            $this->assertFalse((bool)$atividades->save($depois), "{$campo} depois do início foi aceito.");
            $this->assertContains('O prazo de pagamento não pode ser depois do início do acampamento.', $depois->getError($campo));

            $antes = $atividades->patchEntity($atividades->get($fac->id), [$campo => '2026-12-01 23:59:00']);
            $this->assertNotFalse($atividades->save($antes), "{$campo} antes do início foi recusado.");
        }
    }

    public function testServoQueNaoPagouSaiDaEquipeEOProximoDoSetorEChamado(): void
    {
        $atividade = $this->criarAcampamento('FAC', [], [
            'servant_fee' => 50,
            'servant_payment_date' => $this->prazoSemFolga(),
        ]);
        $setor = $this->salvar('Sectors', ['name' => 'Louvor', 'place' => 'Interno']);
        $categoriaSetor = $this->salvar('CategoriesSectors', [
            'category_id' => $atividade->category_id,
            'sector_id' => $setor->id,
            'vacancies' => 1,
        ]);
        $louvor = $this->salvar('ActivitiesCategoriesSectors', [
            'categories_sectors_id' => $categoriaSetor->id,
            'activity_id' => $atividade->id,
            'actual_vacancies' => 1,
        ]);
        foreach (['Servo 1', 'Servo 2'] as $nome) {
            $this->inscrever($this->criarUsuario($nome, idade: 25), $atividade, 'Servo', ['form_submitted' => true]);
        }
        (new SorteioService())->realizarServos((int)$atividade->id);

        $equipe = $this->fetchTable('ActivitiesCategoriesSectorsUsers');
        $titularId = $equipe->find()->where(['activities_categories_sector_id' => $louvor->id, 'is_substitute' => false])->firstOrFail()->user_id;
        $suplenteId = $equipe->find()->where(['activities_categories_sector_id' => $louvor->id, 'is_substitute' => true])->firstOrFail()->user_id;

        $prazos = new PrazoFormularioService();
        $prazos->executarPrazosVencidos();

        // O titular não pagou: sai da equipe e o setor fica com a vaga aberta.
        $this->assertSame(0, $equipe->find()->where(['user_id' => $titularId])->count());
        $this->assertSame(1, $prazos->vagasAbertasSetor($louvor));
        $this->assertSame(1, (int)$equipe->find()->where(['user_id' => $suplenteId])->firstOrFail()->is_substitute, 'O suplente não pode entrar sozinho.');

        $this->chamarProximo($atividade, ['tipo' => 'Servo', 'setor' => $louvor->id]);
        $this->assertResponseOk();

        $this->assertSame(0, (int)$equipe->find()->where(['user_id' => $suplenteId])->firstOrFail()->is_substitute);
        $chamado = $this->fetchTable('PreRegistrations')->find()->where(['user_id' => $suplenteId])->firstOrFail();
        $this->assertNotNull($chamado->promoted_at);
        $this->assertNull($chamado->substitute_position);
        $this->assertSame(0, $prazos->vagasAbertasSetor($louvor));

        // Quem perdeu a vaga aparece só em "perderam a vaga", não em "não sorteados".
        $quadro = (new SorteioService())->resultado((int)$atividade->id)['servos'];
        $this->assertNotContains($titularId, array_column($quadro['nao_sorteados'], 'user_id'));
        $this->assertContains($titularId, array_column($quadro['desclassificados'], 'user_id'));
    }

    public function testSoQuemTemAcessoAInscritosESorteiosChamaOProximo(): void
    {
        $fac = $this->facComTaxa($this->prazoSemFolga());
        $this->titular($fac, 'Não pagou');
        $this->inscrever($this->criarUsuario('Suplente'), $fac, 'Campista', ['substitute_position' => 1, 'form_submitted' => true]);
        (new PrazoFormularioService())->executarPrazosVencidos();
        $chamar = ['tipo' => 'Campista', 'grupo' => 'Homem'];

        $this->chamarProximo($fac, $chamar, $this->criarUsuario('Usuário comum', idade: 30));
        $this->assertResponseCode(403);

        // Conselheiro sem a tela Inscritos e Sorteios liberada também não chama.
        $conselheiro = $this->criarUsuario('Conselheiro', idade: 50);
        (new PermissoesService())->definir((int)$conselheiro->id, ['formularios']);
        $this->chamarProximo($fac, $chamar, $conselheiro);
        $this->assertResponseCode(403);

        (new PermissoesService())->definir((int)$conselheiro->id, ['inscricoes']);
        $this->chamarProximo($fac, $chamar, $conselheiro);
        $this->assertResponseOk();
    }

    public function testAVagaSoSaiDepoisDosDoisDiasDeFolga(): void
    {
        $prazos = new PrazoFormularioService();
        // 1º e 2º dia de folga: ainda com a vaga. No 3º dia, perde.
        foreach ([1 => false, 2 => false, 3 => true] as $diasDepois => $perde) {
            $prazo = DateTime::now()->subDays($diasDepois)->addMinutes(1)->format('Y-m-d H:i:s');
            $inscricao = $this->titular($this->facComTaxa($prazo), "Prazo há {$diasDepois} dia(s)");
            $prazos->executarPrazosVencidos();

            $this->assertSame(
                $perde,
                $this->recarregar($inscricao)->disqualified_at !== null,
                "Prazo vencido há {$diasDepois} dia(s): " . ($perde ? 'devia perder a vaga.' : 'ainda está na folga.'),
            );
        }
    }

    public function testPerderAVagaPorPagamentoFicaNoHistoricoComoSistema(): void
    {
        $fac = $this->facComTaxa($this->prazoSemFolga());
        $naoPagou = $this->titular($fac, 'Não pagou');

        (new PrazoFormularioService())->executarPrazosVencidos();

        $log = $this->fetchTable('UserChangeLogs')->find()->where(['user_id' => $naoPagou->user_id])->firstOrFail();
        $this->assertNull($log->author_id);
        $this->assertStringContainsString('perdeu a vaga: pagamento não registrado até', $log->description);
    }
}
