<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use Cake\Datasource\EntityInterface;

/**
 * O que a página de detalhes da atividade precisa do servidor: se a pessoa pode
 * se inscrever como campista e como servo (com o código do motivo, para a tela
 * escolher o texto) e, na inscrição dela, as datas da situação, o prazo da
 * correção, por que perdeu o prazo e se ficou sem ser sorteada.
 */
class PaginaAtividadeTest extends RegrasTestCase
{
    private function elegibilidade(EntityInterface $quem, EntityInterface $atividade): array
    {
        $this->autenticarComo($quem);
        $this->get("/api/v1/activities/{$atividade->id}/elegibilidade");
        $this->assertResponseOk();

        return $this->respostaJson()['data'];
    }

    private function minhaInscricao(EntityInterface $quem, EntityInterface $inscricao): array
    {
        $this->autenticarComo($quem);
        $this->get('/api/v1/me/inscricoes');
        foreach ($this->respostaJson()['data'] as $i) {
            if ($i['id'] === $inscricao->id) return $i;
        }
        $this->fail('Inscrição não apareceu para a pessoa.');
    }

    public function testElegibilidadeTrazOCodigoDoMotivo(): void
    {
        $juvenil = $this->criarAcampamento('Juvenil');

        $novato = $this->criarUsuario('Novato', idade: 19);
        $this->assertSame(['pode' => true, 'codigo' => null, 'mensagem' => null], $this->elegibilidade($novato, $juvenil)['campista']);
        $this->assertSame('nunca_campista', $this->elegibilidade($novato, $juvenil)['servo']['codigo']);

        $veterano = $this->criarUsuario('Veterano', idade: 20);
        $this->jaFoiCampista($veterano, 'Juvenil');
        $dele = $this->elegibilidade($veterano, $juvenil);
        $this->assertSame('ja_campista', $dele['campista']['codigo']);
        $this->assertTrue($dele['servo']['pode']);

        $fac = $this->criarUsuario('Do FAC', idade: 16);
        $this->jaFoiCampista($fac, 'FAC');
        $this->assertSame('nao_pode_servir', $this->elegibilidade($fac, $this->criarAcampamento('Sênior'))['servo']['codigo']);
    }

    public function testInscricaoTrazDatasDaSituacaoEPrazoDaCorrecao(): void
    {
        $admin = $this->criarUsuario('Admin', idade: 40, admin: true);
        $pessoa = $this->criarUsuario('Júlia', sexo: 'F');
        $acampamento = $this->criarAcampamento('FAC');
        $inscricao = $this->inscrever($pessoa, $acampamento, 'Campista', [
            'selection_method_id' => $this->metodos['Sorteio'], 'form_submitted' => true,
        ]);
        $prazo = date('Y-m-d', strtotime('+3 days'));
        $this->autenticarComo($admin);
        $this->post("/api/v1/admin/inscricoes/{$inscricao->id}/correcao", ['motivo' => 'Foto cortada.', 'prazo' => $prazo]);
        $this->assertResponseCode(201);

        $minha = $this->minhaInscricao($pessoa, $inscricao);
        $this->assertSame('Correção pedida', $minha['status']);
        $this->assertSame('Foto cortada.', $minha['motivoOrganizacao']);
        $this->assertSame("{$prazo}T23:59", $minha['prazoCorrecao']);
        $this->assertStringStartsWith(date('Y-m-d'), $minha['motivoEm']);
        $this->assertNull($minha['perdeuPor']);
        $this->assertFalse($minha['naoSorteado']);
    }

    public function testInscritoDepoisDoSorteioFicaComoNaoSorteado(): void
    {
        // O sorteio é só manual: a data prevista já passou, mas sem sorteio feito
        // ninguém é "não sorteado".
        $acampamento = $this->criarAcampamento('FAC', ['raffle_camper_date' => $this->ontem()]);
        $pessoa = $this->criarUsuario('Lia', sexo: 'F');
        $inscricao = $this->inscrever($pessoa, $acampamento);
        $this->assertFalse($this->minhaInscricao($pessoa, $inscricao)['naoSorteado']);

        // Feito o sorteio (outra pessoa sorteada), quem ficou só "Inscrito" ficou de fora.
        $this->inscrever($this->criarUsuario('Bia', sexo: 'F'), $acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $minha = $this->minhaInscricao($pessoa, $inscricao);
        $this->assertSame('Inscrito', $minha['status']);
        $this->assertTrue($minha['naoSorteado']);
    }

    /**
     * Sem pagamento online (decisão de 2026-11-21): nem a atividade nem a inscrição
     * trazem link. A inscrição diz o valor, o prazo e se já precisa pagar.
     */
    public function testSemLinkDePagamentoEComOPrazoSoParaQuemJaPrecisaPagar(): void
    {
        $acampamento = $this->criarAcampamento('FAC', dadosAtividade: [
            'participant_fee' => 120,
            'participant_payment_date' => date('Y-m-d 23:59:00', strtotime('+5 days')),
        ]);

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get("/api/v1/activities/{$acampamento->id}");
        $this->assertArrayNotHasKey('linkPagamentoParticipante', $this->respostaJson()['data']);

        $enviou = $this->inscrever($this->criarUsuario('Enviou', idade: 16), $acampamento, 'Campista', [
            'selection_method_id' => $this->metodos['Sorteio'], 'form_submitted' => true,
        ]);
        $sorteada = $this->inscrever($this->criarUsuario('Sorteada', idade: 16, sexo: 'F'), $acampamento, 'Campista', [
            'selection_method_id' => $this->metodos['Sorteio'],
        ]);
        $suplente = $this->inscrever($this->criarUsuario('Suplente', idade: 16), $acampamento, 'Campista', [
            'substitute_position' => 1, 'form_submitted' => true,
        ]);
        $pagamento = fn(EntityInterface $i) => $this->minhaInscricao($this->fetchTable('Users')->get($i->user_id), $i);

        $dele = $pagamento($enviou);
        $this->assertArrayNotHasKey('linkPagamento', $dele);
        $this->assertTrue($dele['pagamento']['precisaPagar']);
        $this->assertEquals(120, $dele['pagamento']['valor']);
        $this->assertSame('nao_pago', $dele['pagamento']['situacao']);
        $this->assertFalse($pagamento($sorteada)['pagamento']['precisaPagar'], 'Sem o formulário enviado, ainda não precisa pagar.');
        $this->assertFalse($pagamento($suplente)['pagamento']['precisaPagar'], 'Suplente não paga.');
    }
}
