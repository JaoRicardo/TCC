<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use Cake\Datasource\EntityInterface;

/**
 * Quem pode se inscrever como campista ou servo em cada categoria de acampamento.
 *
 * Cadeia: Mirim → FAC → Juvenil → Sênior. Casais e Discipulados ficam fora dela.
 * - Campista: uma vez só por categoria, dentro da faixa de idade da categoria.
 * - Servo: só em categoria onde já foi campista ou em uma abaixo dela na cadeia.
 *
 * Os testes passam pela API de inscrição, do mesmo jeito que a tela faz.
 */
class ElegibilidadeInscricaoTest extends RegrasTestCase
{
    private function inscreverPelaApi(EntityInterface $usuario, EntityInterface $atividade, string $tipo): void
    {
        $this->autenticarComo($usuario);
        $this->post("/api/v1/activities/{$atividade->id}/inscricoes", ['tipo' => $tipo]);
    }

    private function assertRecusada(string $trechoDaMensagem): void
    {
        $this->assertResponseCode(422);
        $this->assertStringContainsString($trechoDaMensagem, $this->respostaJson()['error'] ?? '');
    }

    // ─── Servo ──────────────────────────────────────────────────────────────

    public function testSemTerSidoCampistaNaoPodeSerServo(): void
    {
        $pessoa = $this->criarUsuario('Sem histórico', idade: 20);

        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('FAC'), 'Servo');

        $this->assertRecusada('é preciso ter participado como campista');
    }

    public function testQuemFoiCampistaPodeServirNaMesmaCategoriaENasDeBaixo(): void
    {
        $pessoa = $this->criarUsuario('Ex-campista FAC', idade: 19);
        $this->jaFoiCampista($pessoa, 'FAC');

        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('FAC'), 'Servo');
        $this->assertResponseCode(201);

        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('Mirim'), 'Servo');
        $this->assertResponseCode(201);
    }

    public function testNaoPodeServirEmCategoriaAcimaDaQueFezComoCampista(): void
    {
        $pessoa = $this->criarUsuario('Ex-campista FAC', idade: 19);
        $this->jaFoiCampista($pessoa, 'FAC');

        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('Juvenil'), 'Servo');

        $this->assertRecusada('Você ainda não pode servir em Juvenil');
    }

    public function testCasaisEDiscipuladosFicamForaDaCadeia(): void
    {
        // Ter feito o topo da cadeia (Sênior) não libera servir em Casais...
        $senior = $this->criarUsuario('Ex-campista Sênior', idade: 40);
        $this->jaFoiCampista($senior, 'Sênior');
        $this->inscreverPelaApi($senior, $this->criarAcampamento('Casais'), 'Servo');
        $this->assertRecusada('Você ainda não pode servir em Casais');

        // ...e ter feito Casais libera servir em Casais, mas não na cadeia.
        $casais = $this->criarUsuario('Ex-campista Casais', idade: 40);
        $this->jaFoiCampista($casais, 'Casais');
        $this->inscreverPelaApi($casais, $this->criarAcampamento('Casais'), 'Servo');
        $this->assertResponseCode(201);
        $this->inscreverPelaApi($casais, $this->criarAcampamento('Mirim'), 'Servo');
        $this->assertRecusada('Você ainda não pode servir em Mirim');
    }

    public function testDesistenciaNaoContaComoTerSidoCampista(): void
    {
        $pessoa = $this->criarUsuario('Desistiu do FAC', idade: 19);
        $this->jaFoiCampista($pessoa, 'FAC', desistiu: true);

        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('FAC'), 'Servo');

        $this->assertRecusada('é preciso ter participado como campista');
    }

    // ─── Campista ───────────────────────────────────────────────────────────

    public function testCampistaSoUmaVezPorCategoria(): void
    {
        $pessoa = $this->criarUsuario('Já fez FAC', idade: 16);
        $this->jaFoiCampista($pessoa, 'FAC');

        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('FAC'), 'Campista');

        $this->assertRecusada('Você já participou como campista em FAC');
    }

    public function testCampistaPrecisaEstarNaFaixaDeIdadeDaCategoria(): void
    {
        $fac = $this->criarAcampamento('FAC');

        $this->inscreverPelaApi($this->criarUsuario('Adulto', idade: 30), $fac, 'Campista');
        $this->assertRecusada('A categoria FAC é para campistas de 14 a 17 anos');

        $this->inscreverPelaApi($this->criarUsuario('Adolescente', idade: 15), $fac, 'Campista');
        $this->assertResponseCode(201);
    }

    public function testCasadoNaoPodeSerCampistaNoJuvenil(): void
    {
        $pessoa = $this->criarUsuario('Casado jovem', idade: 20, estadoCivil: 'Casado');

        $this->inscreverPelaApi($pessoa, $this->criarAcampamento('Juvenil'), 'Campista');

        $this->assertRecusada('devem se inscrever na categoria Sênior');
    }

    public function testDiscipuladosExigeTerSidoCampistaNoSenior(): void
    {
        $semSenior = $this->criarUsuario('Nunca fez Sênior', idade: 30);
        $this->inscreverPelaApi($semSenior, $this->criarAcampamento('Discipulados'), 'Campista');
        $this->assertRecusada('ter participado de um acampamento Sênior antes');

        $comSenior = $this->criarUsuario('Fez Sênior', idade: 30);
        $this->jaFoiCampista($comSenior, 'Sênior');
        $this->inscreverPelaApi($comSenior, $this->criarAcampamento('Discipulados'), 'Campista');
        $this->assertResponseCode(201);
    }
}
