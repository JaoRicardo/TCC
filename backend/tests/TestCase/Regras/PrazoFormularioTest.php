<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PrazoFormularioService;

/**
 * Prazo do formulário: quem foi selecionado e não enviou o formulário até a data
 * do acampamento perde a vaga, e o próximo suplente do mesmo grupo assume — mas
 * só suplente que já entregou o formulário. Suplente sem formulário sai da fila.
 */
class PrazoFormularioTest extends RegrasTestCase
{
    /**
     * FAC com 2 titulares (homens) e 3 suplentes, todos já sorteados. A suplente
     * mulher fica de propósito na frente do homem com formulário: a vaga liberada
     * é de homem, então o sistema tem que pular a mulher e chamar o homem.
     *
     * @return array<string, \Cake\Datasource\EntityInterface>
     */
    private function cenarioFac(?string $prazo, bool $comPerguntas = true): array
    {
        $fac = $this->criarAcampamento('FAC', ['camper_form_deadline' => $prazo]);
        if ($comPerguntas) {
            $this->criarPergunta('FAC');
        }

        $sorteio = $this->metodos['Sorteio'];

        return [
            'titularSemFormulario' => $this->inscrever($this->criarUsuario('Titular sem formulário'), $fac, 'Campista', ['selection_method_id' => $sorteio]),
            'titularComFormulario' => $this->inscrever($this->criarUsuario('Titular com formulário'), $fac, 'Campista', ['selection_method_id' => $sorteio, 'form_submitted' => true]),
            'suplente1SemFormulario' => $this->inscrever($this->criarUsuario('Suplente 1 sem formulário'), $fac, 'Campista', ['substitute_position' => 1]),
            'suplenteMulher' => $this->inscrever($this->criarUsuario('Suplente mulher', sexo: 'F'), $fac, 'Campista', ['substitute_position' => 2, 'form_submitted' => true]),
            'suplenteHomemComFormulario' => $this->inscrever($this->criarUsuario('Suplente homem com formulário'), $fac, 'Campista', ['substitute_position' => 3, 'form_submitted' => true]),
        ];
    }

    public function testQuemNaoRespondeuPerdeAVagaEOSuplenteComFormularioAssume(): void
    {
        $p = $this->cenarioFac($this->ontem());

        (new PrazoFormularioService())->executarPrazosVencidos();

        // Titular que não respondeu: perde a vaga, mas o registro de como foi escolhido fica.
        $perdeu = $this->recarregar($p['titularSemFormulario']);
        $this->assertNotNull($perdeu->disqualified_at);
        $this->assertSame(PrazoFormularioService::MOTIVO_PRAZO_FORMULARIO, $perdeu->disqualification_reason);
        $this->assertSame($this->metodos['Sorteio'], $perdeu->selection_method_id);

        // Titular que respondeu: nada muda.
        $manteve = $this->recarregar($p['titularComFormulario']);
        $this->assertNull($manteve->disqualified_at);
        $this->assertSame($this->metodos['Sorteio'], $manteve->selection_method_id);

        // 1º da fila sem formulário: sai da fila e não é chamado.
        $suplente1 = $this->recarregar($p['suplente1SemFormulario']);
        $this->assertNotNull($suplente1->disqualified_at);
        $this->assertNull($suplente1->selection_method_id);

        // Próximo homem da fila, com formulário: assume a vaga.
        $promovido = $this->recarregar($p['suplenteHomemComFormulario']);
        $this->assertSame($this->metodos['Sorteio'], $promovido->selection_method_id);
        $this->assertNull($promovido->substitute_position);
        $this->assertNotNull($promovido->promoted_at);
        $this->assertNull($promovido->disqualified_at);

        // Suplente de outro grupo (Mulher) não pega a vaga de um Homem, mesmo estando na frente.
        $mulher = $this->recarregar($p['suplenteMulher']);
        $this->assertNull($mulher->selection_method_id);
        $this->assertSame(2, $mulher->substitute_position);
    }

    public function testSemPrazoCadastradoNinguemPerdeAVaga(): void
    {
        $p = $this->cenarioFac(null);

        (new PrazoFormularioService())->executarPrazosVencidos();

        $this->assertNull($this->recarregar($p['titularSemFormulario'])->disqualified_at);
        $this->assertSame(3, $this->recarregar($p['suplenteHomemComFormulario'])->substitute_position);
    }

    public function testPrazoAindaNaoVencidoNinguemPerdeAVaga(): void
    {
        $p = $this->cenarioFac($this->amanha());

        (new PrazoFormularioService())->executarPrazosVencidos();

        $this->assertNull($this->recarregar($p['titularSemFormulario'])->disqualified_at);
        $this->assertNull($this->recarregar($p['suplente1SemFormulario'])->disqualified_at);
    }

    public function testSemFormularioMontadoNinguemPerdeAVaga(): void
    {
        // Proteção: com prazo vencido mas sem nenhuma pergunta cadastrada, não
        // havia o que responder — ninguém pode ser desclassificado.
        $p = $this->cenarioFac($this->ontem(), comPerguntas: false);

        (new PrazoFormularioService())->executarPrazosVencidos();

        $this->assertNull($this->recarregar($p['titularSemFormulario'])->disqualified_at);
        $this->assertNull($this->recarregar($p['suplente1SemFormulario'])->disqualified_at);
    }

    public function testCasalPerdeAVagaJuntoEOProximoCasalEntraCompleto(): void
    {
        $casais = $this->criarAcampamento('Casais', ['camper_form_deadline' => $this->ontem()]);
        $this->criarPergunta('Casais');
        $sorteio = $this->metodos['Sorteio'];

        // Casal titular: só a esposa deixou de responder.
        $marido = $this->criarUsuario('Marido titular', idade: 35, sexo: 'M', estadoCivil: 'Casado');
        $esposa = $this->criarUsuario('Esposa titular', idade: 33, sexo: 'F', estadoCivil: 'Casado');
        $titularMarido = $this->inscrever($marido, $casais, 'Campista', ['selection_method_id' => $sorteio, 'form_submitted' => true, 'spouse_id' => $esposa->id]);
        $titularEsposa = $this->inscrever($esposa, $casais, 'Campista', ['selection_method_id' => $sorteio]);

        // Casal suplente (os dois na posição 1), ambos com formulário.
        $supMarido = $this->criarUsuario('Marido suplente', idade: 35, sexo: 'M', estadoCivil: 'Casado');
        $supEsposa = $this->criarUsuario('Esposa suplente', idade: 33, sexo: 'F', estadoCivil: 'Casado');
        $suplenteMarido = $this->inscrever($supMarido, $casais, 'Campista', ['substitute_position' => 1, 'form_submitted' => true, 'spouse_id' => $supEsposa->id]);
        $suplenteEsposa = $this->inscrever($supEsposa, $casais, 'Campista', ['substitute_position' => 1, 'form_submitted' => true]);

        (new PrazoFormularioService())->executarPrazosVencidos();

        foreach ([$titularMarido, $titularEsposa] as $insc) {
            $this->assertNotNull($this->recarregar($insc)->disqualified_at, 'O casal titular deveria perder a vaga junto.');
        }
        foreach ([$suplenteMarido, $suplenteEsposa] as $insc) {
            $agora = $this->recarregar($insc);
            $this->assertSame($sorteio, $agora->selection_method_id, 'O casal suplente deveria assumir a vaga completo.');
            $this->assertNull($agora->substitute_position);
        }
    }
}
