<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use Cake\Datasource\EntityInterface;

/**
 * Montagem dos formulários no painel (tela Formulários).
 *
 * As respostas de escolha e as perguntas condicionais ("Qual?" só aparece se
 * marcou "Sim") apontam para o id da opção. Editar uma pergunta não pode trocar
 * as opções que continuam por opções novas, nem soltar uma pergunta condicional.
 */
class FormularioPerguntasTest extends RegrasTestCase
{
    private EntityInterface $admin;

    private int $secaoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->criarUsuario('Organização', idade: 40, admin: true);
        $this->secaoId = (int)$this->salvar('Sections', ['title' => 'Seção teste', 'order' => 1])->id;
    }

    /** Cria a pergunta pela API, como a tela faz, e devolve o que a API respondeu. */
    private function criarPerguntaPelaTela(string $texto, string $tipo, array $opcoes = [], ?int $dependeDe = null): array
    {
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/perguntas', [
            'category_id' => $this->categorias['FAC'],
            'subscription_type' => 'Campista',
            'section_id' => $this->secaoId,
            'text' => $texto,
            'type' => $tipo,
            'is_required' => true,
            'options' => $opcoes,
            'depends_on_option_id' => $dependeDe,
        ]);
        $this->assertResponseCode(201);

        return $this->respostaJson()['data'];
    }

    private function editar(int $perguntaId, array $dados): array
    {
        $this->autenticarComo($this->admin);
        $this->patch("/api/v1/admin/perguntas/{$perguntaId}", $dados);

        return $this->respostaJson();
    }

    /** Opções da pergunta como a tela recebe: [texto => id], na ordem de exibição. */
    private function opcoes(int $perguntaId): array
    {
        $this->autenticarComo($this->admin);
        $this->get('/api/v1/admin/perguntas?category_id=' . $this->categorias['FAC'] . '&subscription_type=Campista');
        $pergunta = current(array_filter($this->respostaJson()['data'], fn($p) => $p['id'] === $perguntaId));

        return array_column($pergunta['options'], 'id', 'text');
    }

    public function testPerguntaQueViraSimNaoGanhaAsOpcoesSimENao(): void
    {
        $texto = $this->criarPerguntaPelaTela('Já fez acampamento?', 'texto_curto');
        $tamanho = $this->criarPerguntaPelaTela('Tamanho da camiseta', 'unica_escolha', ['P', 'M', 'G']);

        // A tela não manda opções quando o tipo escolhido é Sim/Não.
        $this->editar($texto['id'], ['text' => 'Já fez acampamento?', 'type' => 'sim_nao', 'is_required' => true]);
        $this->assertResponseOk();
        $this->editar($tamanho['id'], ['text' => 'Tamanho da camiseta', 'type' => 'sim_nao', 'is_required' => true]);
        $this->assertResponseOk();

        $this->assertSame(['Sim', 'Não'], array_keys($this->opcoes($texto['id'])));
        $this->assertSame(['Sim', 'Não'], array_keys($this->opcoes($tamanho['id'])));
    }

    public function testEditarOpcoesMantemAsQueContinuamNaOrdemEscrita(): void
    {
        $pergunta = $this->criarPerguntaPelaTela('Escolaridade', 'unica_escolha', ['Fundamental', 'Médio', 'Superior']);
        $antes = $this->opcoes($pergunta['id']);

        $this->editar($pergunta['id'], [
            'text' => 'Escolaridade', 'type' => 'unica_escolha', 'is_required' => true,
            'options' => ['Superior', 'Fundamental', 'Pós-graduação'],
        ]);
        $this->assertResponseOk();

        $depois = $this->opcoes($pergunta['id']);
        $this->assertSame(['Superior', 'Fundamental', 'Pós-graduação'], array_keys($depois), 'A ordem tem que ser a que foi escrita.');
        $this->assertSame($antes['Superior'], $depois['Superior']);
        $this->assertSame($antes['Fundamental'], $depois['Fundamental']);
        $this->assertNotContains($antes['Médio'], $depois, '"Médio" saiu da lista; "Pós-graduação" é outra opção, não ele renomeado.');
    }

    public function testLinhaEditadaNoMesmoLugarContinuaSendoAMesmaOpcao(): void
    {
        $pergunta = $this->criarPerguntaPelaTela('Vai à missa?', 'unica_escolha', ['Sempre', 'Talves', 'Nunca']);
        $antes = $this->opcoes($pergunta['id']);

        $this->editar($pergunta['id'], [
            'text' => 'Vai à missa?', 'type' => 'unica_escolha', 'is_required' => true,
            'options' => ['Sempre', 'Às vezes', 'Nunca'],
        ]);
        $this->assertResponseOk();

        $depois = $this->opcoes($pergunta['id']);
        $this->assertSame($antes['Talves'], $depois['Às vezes'], 'Corrigir o texto de uma opção não pode soltar as respostas dela.');
    }

    public function testNaoTiraOpcaoQueTemPerguntaCondicionalLigada(): void
    {
        $alergia = $this->criarPerguntaPelaTela('Tem alergia?', 'sim_nao');
        $sim = $this->opcoes($alergia['id'])['Sim'];
        $this->criarPerguntaPelaTela('Qual alergia?', 'texto_curto', dependeDe: $sim);

        $resposta = $this->editar($alergia['id'], ['text' => 'Tem alergia?', 'type' => 'texto_curto', 'is_required' => true]);
        $this->assertResponseCode(422);
        $this->assertStringContainsString('Qual alergia?', $resposta['error']);

        // Nada mudou: continua Sim/Não com a mesma opção "Sim".
        $this->assertSame('sim_nao', $this->fetchTable('Questions')->get($alergia['id'])->type);
        $this->assertSame(['Sim' => $sim, 'Não' => $this->opcoes($alergia['id'])['Não']], $this->opcoes($alergia['id']));
    }

    public function testOpcaoLigadaSoAPerguntaExcluidaPodeSair(): void
    {
        $alergia = $this->criarPerguntaPelaTela('Tem alergia?', 'sim_nao');
        $sim = $this->opcoes($alergia['id'])['Sim'];
        $qual = $this->criarPerguntaPelaTela('Qual alergia?', 'texto_curto', dependeDe: $sim);

        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/admin/perguntas/{$qual['id']}");
        $this->assertResponseOk();

        $this->editar($alergia['id'], ['text' => 'Tem alergia?', 'type' => 'texto_curto', 'is_required' => true]);
        $this->assertResponseOk();
        $this->assertSame([], $this->opcoes($alergia['id']));
    }
}
