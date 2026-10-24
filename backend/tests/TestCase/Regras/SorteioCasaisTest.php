<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\SorteioService;

/**
 * No sorteio de Casais o casal é a unidade: os dois cônjuges sempre caem juntos
 * como sorteados, juntos na mesma posição de suplente, ou juntos fora.
 *
 * O sorteio é aleatório, então ele é repetido várias vezes; um sorteio que
 * separasse casais acabaria separando algum deles em alguma rodada.
 */
class SorteioCasaisTest extends RegrasTestCase
{
    private const RODADAS = 30;

    public function testSorteioNuncaSeparaOCasal(): void
    {
        $casais = $this->criarAcampamento('Casais');

        // 6 casais. O vínculo de cônjuge é gravado em uma só das inscrições, e
        // alterna quem declarou quem — na inscrição real pode ser qualquer um dos dois.
        $pares = [];
        for ($i = 1; $i <= 6; $i++) {
            $marido = $this->criarUsuario("Marido {$i}", idade: 35, sexo: 'M', estadoCivil: 'Casado');
            $esposa = $this->criarUsuario("Esposa {$i}", idade: 33, sexo: 'F', estadoCivil: 'Casado');
            $maridoDeclarou = $i % 2 === 0;
            $pares[] = [
                $this->inscrever($marido, $casais, 'Campista', ['spouse_id' => $maridoDeclarou ? $esposa->id : null]),
                $this->inscrever($esposa, $casais, 'Campista', ['spouse_id' => $maridoDeclarou ? null : $marido->id]),
            ];
        }

        $sorteio = new SorteioService();
        for ($rodada = 1; $rodada <= self::RODADAS; $rodada++) {
            // 2 vagas de casal: 2 casais sorteados, 2 suplentes e 2 de fora.
            $sorteio->realizarCampistas((int)$casais->id, 0, 0, 2);

            $sorteados = 0;
            $posicoesDeSuplente = [];
            foreach ($pares as [$a, $b]) {
                $a = $this->recarregar($a);
                $b = $this->recarregar($b);

                $this->assertSame($a->selection_method_id, $b->selection_method_id, "Rodada {$rodada}: casal separado entre sorteado e não sorteado.");
                $this->assertSame($a->substitute_position, $b->substitute_position, "Rodada {$rodada}: casal separado na fila de suplentes.");

                if ($a->selection_method_id === $this->metodos['Sorteio']) {
                    $sorteados++;
                } elseif ($a->substitute_position !== null) {
                    $posicoesDeSuplente[] = $a->substitute_position;
                }
            }

            $this->assertSame(2, $sorteados, "Rodada {$rodada}: deveriam ser sorteados exatamente 2 casais.");
            $this->assertCount(2, array_unique($posicoesDeSuplente), "Rodada {$rodada}: cada casal suplente deveria ocupar uma posição própria na fila.");
        }
    }
}
