<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PermissoesService;
use Cake\Datasource\EntityInterface;

/**
 * Gestão de Atividades (handoff design_handoff_gestao_atividades): a linha de
 * cada atividade mostra os inscritos e quem ainda deve pagamento (no prazo ou
 * com o prazo vencido), e excluir uma atividade com inscrições exige digitar o
 * nome dela.
 */
class GestaoAtividadesTest extends RegrasTestCase
{
    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Coordenação', idade: 40, admin: true);
    }

    private function resumo(): array
    {
        $this->autenticarComo($this->admin);
        $this->get('/api/v1/admin/atividades/resumo');
        $this->assertResponseOk();

        return $this->respostaJson()['data'];
    }

    private function sorteado(EntityInterface $atividade, string $nome, array $extra = []): EntityInterface
    {
        return $this->inscrever($this->criarUsuario($nome), $atividade, 'Campista', $extra + [
            'selection_method_id' => $this->metodos['Sorteio'], 'form_submitted' => true,
        ]);
    }

    public function testContaSoInscricoesAtivas(): void
    {
        $fac = $this->criarAcampamento('FAC');
        $this->inscrever($this->criarUsuario('Ana'), $fac);
        $this->inscrever($this->criarUsuario('Bia'), $fac, 'Servo');
        $this->inscrever($this->criarUsuario('Caio'), $fac, 'Campista', ['is_quitter' => true]);
        $this->inscrever($this->criarUsuario('Davi'), $fac, 'Campista', ['disqualified_at' => $this->ontem()]);

        $linha = $this->resumo()[(string)$fac->id];
        $this->assertSame(2, $linha['inscritos']);
        // Gratuito: não há cobrança para mostrar.
        $this->assertNull($linha['pagamento']);
    }

    public function testAntesDoSorteioNaoHaCobranca(): void
    {
        $fac = $this->criarAcampamento('FAC', [], ['participant_fee' => 120, 'participant_payment_date' => $this->amanha()]);
        $this->inscrever($this->criarUsuario('Ana'), $fac);

        $this->assertNull($this->resumo()[(string)$fac->id]['pagamento']);
    }

    public function testSeparaNaoPagosNoPrazoEComPrazoVencido(): void
    {
        $noPrazo = $this->criarAcampamento('FAC', [], ['participant_fee' => 120, 'participant_payment_date' => $this->amanha()]);
        $this->sorteado($noPrazo, 'Ana');
        $this->sorteado($noPrazo, 'Bia', ['is_fee_paid' => true]);
        // Suplente e quem não enviou o formulário ainda não devem nada.
        $this->sorteado($noPrazo, 'Caio', ['substitute_position' => 1]);
        $this->sorteado($noPrazo, 'Davi', ['form_submitted' => false]);

        $vencido = $this->criarAcampamento('Juvenil', [], ['participant_fee' => 120, 'participant_payment_date' => $this->ontem()]);
        $this->sorteado($vencido, 'Edu');

        $emDia = $this->criarAcampamento('Sênior', [], ['participant_fee' => 120, 'participant_payment_date' => $this->ontem()]);
        $this->sorteado($emDia, 'Fabi', ['is_fee_paid' => true]);

        $resumo = $this->resumo();
        $this->assertSame(['noPrazo' => 1, 'vencido' => 0], $resumo[(string)$noPrazo->id]['pagamento']);
        $this->assertSame(4, $resumo[(string)$noPrazo->id]['inscritos']);
        $this->assertSame(['noPrazo' => 0, 'vencido' => 1], $resumo[(string)$vencido->id]['pagamento']);
        $this->assertSame(['noPrazo' => 0, 'vencido' => 0], $resumo[(string)$emDia->id]['pagamento']);
    }

    public function testResumoPedePermissaoDeAtividades(): void
    {
        $semTela = $this->criarUsuario('Sem tela', idade: 30);
        (new PermissoesService())->definir((int)$semTela->id, ['setores']);
        $this->autenticarComo($semTela);
        $this->get('/api/v1/admin/atividades/resumo');
        $this->assertResponseCode(403);
    }

    public function testExcluirSemInscricoesNaoPedeONome(): void
    {
        $fac = $this->criarAcampamento('FAC');
        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/activities/{$fac->id}");
        $this->assertResponseOk();
        $this->assertNotNull($this->fetchTable('Activities')->get($fac->id)->deleted);
    }

    public function testExcluirComInscricoesExigeONomeDigitado(): void
    {
        $fac = $this->criarAcampamento('FAC', [], ['name' => 'Acampamento São José 2026']);
        $this->inscrever($this->criarUsuario('Ana'), $fac);

        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/activities/{$fac->id}");
        $this->assertResponseCode(422);
        $this->assertSame('confirmar_nome', $this->respostaJson()['codigo']);

        $this->autenticarComo($this->admin);
        $this->_sendRequest("/api/v1/activities/{$fac->id}", 'DELETE', ['confirmacao' => 'Acampamento São Pedro 2026']);
        $this->assertResponseCode(422);
        $this->assertNull($this->fetchTable('Activities')->get($fac->id)->deleted);

        // Sem diferenciar maiúsculas, acentos e espaços a mais.
        $this->autenticarComo($this->admin);
        $this->_sendRequest("/api/v1/activities/{$fac->id}", 'DELETE', ['confirmacao' => '  acampamento  SAO jose 2026 ']);
        $this->assertResponseOk();
        $this->assertNotNull($this->fetchTable('Activities')->get($fac->id)->deleted);
    }
}
