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

    public function testResumoDaListaContaPerguntasEMostraOAcampamentoEmUso(): void
    {
        $this->criarPerguntaPelaTela('Tem alergia?', 'sim_nao');
        $this->criarPerguntaPelaTela('Tamanho da camiseta', 'unica_escolha', ['P', 'M']);

        $aberto = $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->amanha()]);
        $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->ontem()]); // prazo já passou: não está em uso
        $sorteio = $this->metodos['Sorteio'];
        $this->inscrever($this->criarUsuario('Enviou'), $aberto, 'Campista', ['selection_method_id' => $sorteio, 'form_submitted' => true]);
        $this->inscrever($this->criarUsuario('Falta enviar'), $aberto, 'Campista', ['selection_method_id' => $sorteio]);
        $this->inscrever($this->criarUsuario('Suplente'), $aberto, 'Campista', ['substitute_position' => 1]);
        $this->inscrever($this->criarUsuario('Não sorteado'), $aberto, 'Campista');

        $this->autenticarComo($this->admin);
        $this->get('/api/v1/admin/formularios/resumo');
        $this->assertResponseOk();
        $lista = $this->respostaJson()['data'];

        $this->assertSame(
            ['Mirim', 'FAC', 'Juvenil', 'Sênior', 'Casais', 'Discipulados'],
            array_values(array_unique(array_column($lista, 'categoria'))),
        );
        $porForm = [];
        foreach ($lista as $f) {
            $porForm["{$f['categoria']} {$f['subscription_type']}"] = $f;
        }

        $fac = $porForm['FAC Campista'];
        $this->assertSame(2, $fac['perguntas']);
        $this->assertSame([$this->secaoId], $fac['secoes']);
        $this->assertSame($aberto->id, $fac['uso']['atividade_id']);
        $this->assertSame(1, $fac['uso']['enviados']);
        $this->assertSame(3, $fac['uso']['total'], 'Sorteados, indicados e suplentes precisam enviar; quem não foi sorteado, não.');

        $this->assertSame(0, $porForm['FAC Servo']['perguntas']);
        $this->assertNull($porForm['FAC Servo']['uso'], 'Sem prazo de servo cadastrado, o formulário de servo não está em uso.');
        $this->assertNull($porForm['Juvenil Campista']['uso']);
    }

    // ─── Opções salvas por id ────────────────────────────────────────────

    public function testRenomearOpcaoPorIdMantemACondicionalLigada(): void
    {
        $remedio = $this->criarPerguntaPelaTela('Toma remédio?', 'unica_escolha', ['Sim, todo dia', 'Não']);
        $antes = $this->opcoes($remedio['id']);
        $qual = $this->criarPerguntaPelaTela('Qual remédio?', 'texto_curto', dependeDe: $antes['Sim, todo dia']);

        // A tela manda cada opção com o id dela; o texto mudou por completo.
        $this->editar($remedio['id'], [
            'text' => 'Toma remédio?', 'type' => 'unica_escolha', 'is_required' => true,
            'options' => [['id' => $antes['Não'], 'text' => 'Não'], ['id' => $antes['Sim, todo dia'], 'text' => 'Sim']],
        ]);
        $this->assertResponseOk();

        $depois = $this->opcoes($remedio['id']);
        $this->assertSame(['Não', 'Sim'], array_keys($depois));
        $this->assertSame($antes['Sim, todo dia'], $depois['Sim'], 'Renomear não pode trocar a opção por outra.');
        $this->assertSame($depois['Sim'], $this->fetchTable('Questions')->get($qual['id'])->depends_on_option_id);
    }

    public function testOpcaoNovaSemIdNaoTomaOLugarDeOutra(): void
    {
        $pergunta = $this->criarPerguntaPelaTela('Camiseta', 'unica_escolha', ['P', 'M', 'G']);
        $antes = $this->opcoes($pergunta['id']);

        // Tirou "M" e escreveu "GG" numa linha nova, no mesmo lugar.
        $this->editar($pergunta['id'], [
            'text' => 'Camiseta', 'type' => 'unica_escolha', 'is_required' => true,
            'options' => [['id' => $antes['P'], 'text' => 'P'], ['id' => null, 'text' => 'GG'], ['id' => $antes['G'], 'text' => 'G']],
        ]);
        $this->assertResponseOk();

        $depois = $this->opcoes($pergunta['id']);
        $this->assertNotSame($antes['M'], $depois['GG'], 'Com ids, opção nova é nova: as respostas "M" não podem virar "GG".');
    }

    public function testOpcaoRemovidaFicaNoBancoComoExcluida(): void
    {
        $pergunta = $this->criarPerguntaPelaTela('Camiseta', 'unica_escolha', ['P', 'M', 'G']);
        $antes = $this->opcoes($pergunta['id']);

        $this->editar($pergunta['id'], [
            'text' => 'Camiseta', 'type' => 'unica_escolha', 'is_required' => true,
            'options' => [['id' => $antes['P'], 'text' => 'P'], ['id' => $antes['G'], 'text' => 'G']],
        ]);
        $this->assertResponseOk();

        $this->assertSame(['P', 'G'], array_keys($this->opcoes($pergunta['id'])));
        $removida = $this->fetchTable('Options')->get($antes['M']);
        $this->assertNotNull($removida->deleted, 'Exclusão lógica: a opção continua no banco, marcada como excluída.');
    }

    public function testOpcaoDeOutraPerguntaEhRecusada(): void
    {
        $camiseta = $this->criarPerguntaPelaTela('Camiseta', 'unica_escolha', ['P', 'M']);
        $outra = $this->criarPerguntaPelaTela('Turno', 'unica_escolha', ['Manhã', 'Tarde']);
        $manha = $this->opcoes($outra['id'])['Manhã'];

        $this->editar($camiseta['id'], [
            'text' => 'Camiseta', 'type' => 'unica_escolha', 'is_required' => true,
            'options' => [['id' => $manha, 'text' => 'P'], ['id' => null, 'text' => 'M']],
        ]);
        $this->assertResponseCode(422);
        $this->assertSame(['Manhã', 'Tarde'], array_keys($this->opcoes($outra['id'])));
    }

    public function testListaDizQuantasPessoasResponderamCadaPergunta(): void
    {
        $alergia = $this->criarPerguntaPelaTela('Tem alergia?', 'sim_nao');
        $vazia = $this->criarPerguntaPelaTela('Apelido', 'texto_curto');
        $sim = $this->opcoes($alergia['id'])['Sim'];

        $acampamento = $this->criarAcampamento('FAC');
        foreach (['Ana', 'Bia'] as $nome) {
            $inscricao = $this->inscrever($this->criarUsuario($nome), $acampamento, 'Campista');
            $this->salvar('Answers', ['pre_registration_id' => $inscricao->id, 'question_id' => $alergia['id'], 'answer' => (string)$sim]);
        }
        $excluida = $this->inscrever($this->criarUsuario('Saiu'), $acampamento, 'Campista', ['deleted' => $this->ontem()]);
        $this->salvar('Answers', ['pre_registration_id' => $excluida->id, 'question_id' => $alergia['id'], 'answer' => (string)$sim]);

        $this->autenticarComo($this->admin);
        $this->get('/api/v1/admin/perguntas?category_id=' . $this->categorias['FAC'] . '&subscription_type=Campista');
        $porId = array_column($this->respostaJson()['data'], 'respostas', 'id');

        $this->assertSame(2, $porId[$alergia['id']], 'Inscrição excluída não conta.');
        $this->assertSame(0, $porId[$vazia['id']]);
    }

    // ─── Condição ────────────────────────────────────────────────────────

    public function testCriarTrocarETirarACondicao(): void
    {
        $alergia = $this->criarPerguntaPelaTela('Tem alergia?', 'sim_nao');
        $remedio = $this->criarPerguntaPelaTela('Toma remédio?', 'sim_nao');
        $qual = $this->criarPerguntaPelaTela('Qual?', 'texto_curto');
        $simAlergia = $this->opcoes($alergia['id'])['Sim'];
        $simRemedio = $this->opcoes($remedio['id'])['Sim'];

        $this->editar($qual['id'], ['depends_on_option_id' => $simAlergia]);
        $this->assertResponseOk();
        $this->assertSame($simAlergia, $this->fetchTable('Questions')->get($qual['id'])->depends_on_option_id);

        $this->editar($qual['id'], ['depends_on_option_id' => $simRemedio]);
        $this->assertResponseOk();
        $this->assertSame($simRemedio, $this->fetchTable('Questions')->get($qual['id'])->depends_on_option_id);

        // "Mostrar sempre"
        $this->editar($qual['id'], ['depends_on_option_id' => null]);
        $this->assertResponseOk();
        $this->assertNull($this->fetchTable('Questions')->get($qual['id'])->depends_on_option_id);
    }

    public function testCondicaoQueNaoFazSentidoEhRecusada(): void
    {
        $alergia = $this->criarPerguntaPelaTela('Tem alergia?', 'sim_nao');
        $sim = $this->opcoes($alergia['id'])['Sim'];
        $qual = $this->criarPerguntaPelaTela('Qual?', 'unica_escolha', ['Comida', 'Remédio'], dependeDe: $sim);
        $comida = $this->opcoes($qual['id'])['Comida'];
        $solta = $this->criarPerguntaPelaTela('Observações', 'texto_longo');

        // Depender de uma condicional (dois níveis).
        $this->editar($solta['id'], ['depends_on_option_id' => $comida]);
        $this->assertResponseCode(422);

        // A própria pergunta.
        $this->editar($alergia['id'], ['depends_on_option_id' => $this->opcoes($alergia['id'])['Não']]);
        $this->assertResponseCode(422);

        // Pergunta que tem condicionais não vira condicional.
        $outra = $this->criarPerguntaPelaTela('Toma remédio?', 'sim_nao');
        $this->editar($alergia['id'], ['depends_on_option_id' => $this->opcoes($outra['id'])['Sim']]);
        $this->assertResponseCode(422);

        // Pai de outra seção.
        $outraSecao = (int)$this->salvar('Sections', ['title' => 'Outra seção', 'order' => 2])->id;
        $this->editar($solta['id'], ['section_id' => $outraSecao]);
        $this->editar($solta['id'], ['depends_on_option_id' => $sim]);
        $this->assertResponseCode(422);

        // Pai de outro formulário.
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/perguntas', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Servo', 'section_id' => $this->secaoId,
            'text' => 'Pergunta de servo', 'type' => 'texto_curto', 'depends_on_option_id' => $sim,
        ]);
        $this->assertResponseCode(422);

        $this->assertNull($this->fetchTable('Questions')->get($solta['id'])->depends_on_option_id);
        $this->assertNull($this->fetchTable('Questions')->get($alergia['id'])->depends_on_option_id);
    }

    // ─── Desfazer exclusão ───────────────────────────────────────────────

    public function testDesfazerExclusaoTrazAPerguntaEAsCondicionaisQueSairamJunto(): void
    {
        $alergia = $this->criarPerguntaPelaTela('Tem alergia?', 'sim_nao');
        $sim = $this->opcoes($alergia['id'])['Sim'];
        $qual = $this->criarPerguntaPelaTela('Qual?', 'texto_curto', dependeDe: $sim);
        $antiga = $this->criarPerguntaPelaTela('Desde quando?', 'texto_curto', dependeDe: $sim);

        // "Desde quando?" foi excluída sozinha, antes.
        $this->fetchTable('Questions')->updateAll(['deleted' => $this->ontem()], ['id' => $antiga['id']]);

        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/admin/perguntas/{$alergia['id']}");
        $this->assertResponseOk();
        $this->assertNotNull($this->fetchTable('Questions')->get($qual['id'])->deleted);

        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/perguntas/{$alergia['id']}/restaurar");
        $this->assertResponseOk();
        $this->assertEqualsCanonicalizing([$alergia['id'], $qual['id']], array_column($this->respostaJson()['data'], 'id'));

        $perguntas = $this->fetchTable('Questions');
        $this->assertNull($perguntas->get($alergia['id'])->deleted);
        $this->assertNull($perguntas->get($qual['id'])->deleted);
        $this->assertSame($sim, $perguntas->get($qual['id'])->depends_on_option_id, 'Volta ligada à mesma opção.');
        $this->assertNotNull($perguntas->get($antiga['id'])->deleted, 'O que foi excluído antes, sozinho, não volta.');
    }

    public function testCondicionalNaoVoltaSemAPerguntaDeQueDepende(): void
    {
        $alergia = $this->criarPerguntaPelaTela('Tem alergia?', 'sim_nao');
        $qual = $this->criarPerguntaPelaTela('Qual?', 'texto_curto', dependeDe: $this->opcoes($alergia['id'])['Sim']);

        $this->autenticarComo($this->admin);
        $this->delete("/api/v1/admin/perguntas/{$alergia['id']}");

        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/perguntas/{$qual['id']}/restaurar");
        $this->assertResponseCode(422);
        $this->assertNotNull($this->fetchTable('Questions')->get($qual['id'])->deleted);
    }

    // ─── Importar ────────────────────────────────────────────────────────

    public function testImportarCopiaAsPerguntasERefazAsCondicionaisComOsIdsNovos(): void
    {
        $alergia = $this->criarPerguntaPelaTela('Tem alergia?', 'sim_nao');
        $simOrigem = $this->opcoes($alergia['id'])['Sim'];
        $qual = $this->criarPerguntaPelaTela('Qual?', 'texto_curto', dependeDe: $simOrigem);
        $this->criarPerguntaPelaTela('Camiseta', 'unica_escolha', ['P', 'M']);

        // O destino já tem uma pergunta na seção: as cópias entram depois dela.
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/perguntas', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Servo', 'section_id' => $this->secaoId,
            'text' => 'Já existia', 'type' => 'texto_curto',
        ]);

        // Marcou só a condicional: a pergunta de que ela depende vem junto.
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/formularios/importar', [
            'category_id' => $this->categorias['FAC'],
            'subscription_type' => 'Servo',
            'question_ids' => [$qual['id']],
        ]);
        $this->assertResponseCode(201);
        $copias = array_column($this->respostaJson()['data'], null, 'text');

        $this->assertSame(['Tem alergia?', 'Qual?'], array_keys($copias));
        $this->assertSame([2, 3], [$copias['Tem alergia?']['order'], $copias['Qual?']['order']]);
        $simCopia = array_column($copias['Tem alergia?']['options'], 'id', 'text')['Sim'];
        $this->assertNotSame($simOrigem, $simCopia, 'Cópia independente: opções novas.');
        $this->assertSame($simCopia, $copias['Qual?']['depends_on_option_id'], 'A condicional depende da opção copiada.');
        $this->assertSame('Servo', $copias['Qual?']['subscription_type']);

        // A origem não muda.
        $this->assertSame($simOrigem, $this->fetchTable('Questions')->get($qual['id'])->depends_on_option_id);
    }

    public function testNaoImportaDoProprioFormulario(): void
    {
        $alergia = $this->criarPerguntaPelaTela('Tem alergia?', 'sim_nao');

        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/formularios/importar', [
            'category_id' => $this->categorias['FAC'],
            'subscription_type' => 'Campista',
            'question_ids' => [$alergia['id']],
        ]);
        $this->assertResponseCode(422);
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
