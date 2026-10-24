<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;

/**
 * Indicação (Base, Forania, Conselho) é uma vaga garantida fora do sorteio:
 * - sobrevive a quantos sorteios e "zerar sorteio" forem feitos;
 * - não ocupa as vagas informadas para o sorteio;
 * - em casal, indicar um dos cônjuges indica os dois.
 *
 * A indicação é feita pela API do admin, do mesmo jeito que a tela faz.
 */
class IndicacaoSorteioTest extends RegrasTestCase
{
    private function indicarPelaApi(EntityInterface $atividade, EntityInterface $inscricao, string $metodo = 'Base'): void
    {
        $this->autenticarComo($this->criarUsuario('Admin', idade: 40, admin: true));
        $this->post("/api/v1/activities/{$atividade->id}/indicate", [
            'pre_registration_id' => $inscricao->id,
            'selection_method_id' => $this->metodos[$metodo],
        ]);
        $this->assertResponseOk();
    }

    public function testIndicacaoSobreviveAoSorteioEAoZerarSorteio(): void
    {
        $fac = $this->criarAcampamento('FAC');
        $inscricoes = [];
        for ($i = 1; $i <= 6; $i++) {
            $inscricoes[] = $this->inscrever($this->criarUsuario("Campista {$i}"), $fac);
        }
        $indicado = $inscricoes[0];
        $this->indicarPelaApi($fac, $indicado);

        $sorteio = new SorteioService();
        for ($rodada = 1; $rodada <= 10; $rodada++) {
            $sorteio->realizarCampistas((int)$fac->id, 2, 0, 0);

            $agora = $this->recarregar($indicado);
            $this->assertSame($this->metodos['Base'], $agora->selection_method_id, "Rodada {$rodada}: o sorteio apagou a indicação.");
            $this->assertNull($agora->substitute_position, "Rodada {$rodada}: o indicado foi parar na fila de suplentes.");

            // As 2 vagas do sorteio vão para outras pessoas: a indicação não ocupa vaga.
            $sorteados = $this->fetchTable('PreRegistrations')->find()
                ->where(['activity_id' => $fac->id, 'selection_method_id' => $this->metodos['Sorteio']])
                ->count();
            $this->assertSame(2, $sorteados, "Rodada {$rodada}: a indicação consumiu uma vaga do sorteio.");
        }

        $sorteio->resetarCampistas((int)$fac->id);
        $this->assertSame($this->metodos['Base'], $this->recarregar($indicado)->selection_method_id, 'Zerar o sorteio apagou a indicação.');
        foreach (array_slice($inscricoes, 1) as $outro) {
            $this->assertNull($this->recarregar($outro)->selection_method_id, 'Zerar o sorteio deveria limpar os sorteados.');
        }
    }

    public function testIndicarUmConjugeIndicaOCasalEOSorteioNaoMexeNeles(): void
    {
        $casais = $this->criarAcampamento('Casais');
        $marido = $this->criarUsuario('Marido indicado', idade: 35, sexo: 'M', estadoCivil: 'Casado');
        $esposa = $this->criarUsuario('Esposa indicada', idade: 33, sexo: 'F', estadoCivil: 'Casado');
        $inscMarido = $this->inscrever($marido, $casais, 'Campista', ['spouse_id' => $esposa->id]);
        $inscEsposa = $this->inscrever($esposa, $casais);

        // Outro casal, que vai concorrer no sorteio.
        $outroMarido = $this->criarUsuario('Outro marido', idade: 35, sexo: 'M', estadoCivil: 'Casado');
        $outraEsposa = $this->criarUsuario('Outra esposa', idade: 33, sexo: 'F', estadoCivil: 'Casado');
        $this->inscrever($outroMarido, $casais, 'Campista', ['spouse_id' => $outraEsposa->id]);
        $this->inscrever($outraEsposa, $casais);

        $this->indicarPelaApi($casais, $inscEsposa, 'Conselho');
        $this->assertTrue($this->respostaJson()['conjuge_indicado'] ?? false, 'A API deveria avisar que o cônjuge foi indicado junto.');

        (new SorteioService())->realizarCampistas((int)$casais->id, 0, 0, 1);

        foreach ([$inscMarido, $inscEsposa] as $insc) {
            $agora = $this->recarregar($insc);
            $this->assertSame($this->metodos['Conselho'], $agora->selection_method_id);
            $this->assertNull($agora->substitute_position);
        }
    }
}
