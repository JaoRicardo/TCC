<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;

/**
 * Um sorteio feito continua feito mesmo quando ninguém pôde ser sorteado (só
 * concorre quem mora em Primavera do Leste ou Poxoréu). Antes, a tela voltava
 * para "sorteio não feito" e parecia que o botão não tinha funcionado.
 */
class SorteioFeitoTest extends RegrasTestCase
{
    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Coordenação', idade: 40, admin: true);
    }

    private function resultado(EntityInterface $atividade): array
    {
        $this->autenticarComo($this->admin);
        $this->get("/api/v1/activities/{$atividade->id}/sorteio");
        $this->assertResponseOk();

        return $this->respostaJson();
    }

    public function testSorteioSemNinguemDaAreaContaComoFeitoEMostraOMotivo(): void
    {
        $fac = $this->criarAcampamento('FAC');
        $this->inscrever($this->criarUsuario('Ana de Cuiabá', sexo: 'F', cidade: 'Cuiabá'), $fac);
        $this->inscrever($this->criarUsuario('Beto de Cuiabá', cidade: 'Cuiabá'), $fac);

        $this->autenticarComo($this->admin);
        $this->post("/api/v1/activities/{$fac->id}/sorteio/campistas", ['homem' => 1, 'mulher' => 1, 'casado' => 0]);
        $this->assertResponseOk();

        $campistas = $this->resultado($fac)['campistas'];
        $this->assertTrue($campistas['realizado']);
        $this->assertSame([], $campistas['sorteados']);
        $this->assertCount(2, $campistas['nao_sorteados']);
        foreach ($campistas['nao_sorteados'] as $p) {
            $this->assertTrue($p['foraDaArea']);
        }
        // O site também deixa de mostrar "Aguardando sorteio".
        $this->assertTrue((new SorteioService())->campistasJaRealizado((int)$fac->id));
    }

    public function testQuemMoraNaAreaENaoFoiSorteadoNaoTemOMotivo(): void
    {
        $fac = $this->criarAcampamento('FAC');
        $this->inscrever($this->criarUsuario('Caio'), $fac);
        $this->inscrever($this->criarUsuario('Davi'), $fac);
        $this->inscrever($this->criarUsuario('Edu'), $fac);

        // 1 vaga: 1 sorteado, 1 suplente, 1 de fora (que mora na área).
        (new SorteioService())->realizarCampistas((int)$fac->id, 1, 0, 0);

        $campistas = $this->resultado($fac)['campistas'];
        $this->assertCount(1, $campistas['nao_sorteados']);
        $this->assertFalse($campistas['nao_sorteados'][0]['foraDaArea']);
    }

    public function testResetarVoltaParaNaoFeito(): void
    {
        $fac = $this->criarAcampamento('FAC');
        $this->inscrever($this->criarUsuario('Ana de Cuiabá', sexo: 'F', cidade: 'Cuiabá'), $fac);
        $sorteio = new SorteioService();
        $sorteio->realizarCampistas((int)$fac->id, 0, 1, 0);
        $this->assertTrue($sorteio->campistasJaRealizado((int)$fac->id));

        $sorteio->resetarCampistas((int)$fac->id);
        $this->assertFalse($sorteio->campistasJaRealizado((int)$fac->id));
        $this->assertFalse($this->resultado($fac)['campistas']['realizado']);
    }
}
