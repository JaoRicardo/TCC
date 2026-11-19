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
        // O sorteio automático já foi marcado como feito: a pessoa ficou de fora.
        $acampamento = $this->criarAcampamento('FAC', ['raffle_camper_date' => $this->ontem(), 'camper_raffle_auto_ran_at' => $this->ontem()]);
        $pessoa = $this->criarUsuario('Lia', sexo: 'F');
        $inscricao = $this->inscrever($pessoa, $acampamento);
        $minha = $this->minhaInscricao($pessoa, $inscricao);
        $this->assertSame('Inscrito', $minha['status']);
        $this->assertTrue($minha['naoSorteado']);

        $antes = $this->inscrever($this->criarUsuario('Ana', sexo: 'F'), $this->criarAcampamento('Juvenil'));
        $this->assertFalse($this->minhaInscricao($this->fetchTable('Users')->get($antes->user_id), $antes)['naoSorteado']);
    }
}
