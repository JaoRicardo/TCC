<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Model\Table\UserDocumentsTable;
use App\Service\PermissoesService;
use Cake\Datasource\EntityInterface;
use Laminas\Diactoros\UploadedFile;

/**
 * Pergunta do tipo "arquivo" (documento de identidade, receita…): o arquivo
 * sobe na hora em que a pessoa escolhe, fica ligado à inscrição e à pergunta,
 * cada inscrição envia de novo, e quem vê as respostas abre o arquivo pelo painel.
 */
class PerguntaArquivoTest extends RegrasTestCase
{
    /** PNG de 1×1 pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private EntityInterface $pessoa;
    private EntityInterface $documento;
    private EntityInterface $apelido;
    private EntityInterface $inscricao;
    private int $secao;

    /** @var list<string> Arquivos criados pelo teste, apagados no fim. */
    private array $criados = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pessoa = $this->criarUsuario('Ana');
        $this->secao = (int)$this->salvar('Sections', ['title' => 'Geral', 'order' => 1])->id;
        $this->documento = $this->pergunta('Documento de identidade', 'arquivo', 1);
        $this->apelido = $this->pergunta('Apelido', 'texto', 2);
        $this->inscricao = $this->inscreverNoAcampamento($this->pessoa);
    }

    protected function tearDown(): void
    {
        foreach ($this->fetchTable('UserDocuments')->find()->all() as $doc) {
            if ($doc->file !== '') $this->criados[] = UserDocumentsTable::PASTA . DS . $doc->file;
        }
        parent::tearDown();
        foreach ($this->criados as $arquivo) {
            if (is_file($arquivo)) unlink($arquivo);
        }
    }

    private function pergunta(string $texto, string $tipo, int $ordem, bool $obrigatoria = true): EntityInterface
    {
        return $this->salvar('Questions', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'section_id' => $this->secao,
            'text' => $texto, 'type' => $tipo, 'is_required' => $obrigatoria, 'order' => $ordem, 'accept_generic_answer' => false,
        ]);
    }

    private function inscreverNoAcampamento(EntityInterface $pessoa, ?string $prazo = null): EntityInterface
    {
        $acampamento = $this->criarAcampamento('FAC', ['camper_form_deadline' => $prazo ?? $this->amanha()]);

        return $this->inscrever($pessoa, $acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
    }

    private function enviarArquivo(
        string $conteudo,
        string $nome,
        ?EntityInterface $inscricao = null,
        ?EntityInterface $quem = null,
        ?EntityInterface $pergunta = null
    ): ?array {
        $temporario = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($temporario, $conteudo);
        $this->criados[] = $temporario;

        $this->autenticarComo($quem ?? $this->pessoa);
        // configRequest acumula (só os cabeçalhos são limpos a cada requisição): sem isto o
        // arquivo do envio anterior iria junto.
        unset($this->_request['files']);
        $this->configRequest([
            'files' => ['arquivo' => new UploadedFile($temporario, strlen($conteudo), UPLOAD_ERR_OK, $nome, 'image/png')],
        ]);
        $this->post('/api/v1/inscricoes/' . ($inscricao ?? $this->inscricao)->id . '/arquivo', [
            'question_id' => ($pergunta ?? $this->documento)->id,
        ]);

        return $this->respostaJson()['data'] ?? null;
    }

    private function enviarFormulario(EntityInterface $inscricao, ?string $arquivoId): void
    {
        $this->autenticarComo($this->pessoa);
        $this->post("/api/v1/inscricoes/{$inscricao->id}/formulario", ['answers' => [
            ['question_id' => $this->documento->id, 'answer' => (string)$arquivoId],
            ['question_id' => $this->apelido->id, 'answer' => 'Aninha'],
        ]]);
    }

    /** O arquivo que o formulário mostra na pergunta de arquivo. */
    private function arquivoNoFormulario(EntityInterface $inscricao): ?array
    {
        $this->autenticarComo($this->pessoa);
        $this->get("/api/v1/inscricoes/{$inscricao->id}/formulario");
        foreach ($this->respostaJson()['data']['sections'] as $secao) {
            foreach ($secao['questions'] as $q) {
                if ($q['id'] === $this->documento->id) return $q['arquivo'];
            }
        }
        $this->fail('Pergunta de arquivo não veio no formulário.');
    }

    public function testEnviaNaHoraETrocaGuardandoForaDaPastaPublica(): void
    {
        $primeiro = $this->enviarArquivo(base64_decode(self::PNG), 'rg-frente.png');
        $this->assertResponseCode(201);
        $this->assertSame('imagem', $primeiro['tipo']);
        $this->assertSame(strlen(base64_decode(self::PNG)), $primeiro['tamanho']);

        $doc = $this->fetchTable('UserDocuments')->get($primeiro['id']);
        $this->assertSame($this->inscricao->id, $doc->pre_registration_id);
        $this->assertSame($this->documento->id, $doc->question_id);
        $this->assertFileExists(UserDocumentsTable::PASTA . DS . $doc->file);
        $this->assertStringNotContainsString('webroot', UserDocumentsTable::PASTA, 'Arquivo não pode ter URL pública.');

        // Antes de enviar o formulário, o arquivo já aparece guardado ("Ao voltar").
        $this->assertSame('rg-frente.png', $this->arquivoNoFormulario($this->inscricao)['nome']);

        // Trocou o arquivo: o anterior sai com exclusão lógica.
        $this->enviarArquivo(base64_decode(self::PNG), 'rg-nova-foto.png');
        $this->assertResponseCode(201);
        $this->assertNotNull($this->fetchTable('UserDocuments')->get($primeiro['id'])->deleted);
        $this->assertSame('rg-nova-foto.png', $this->arquivoNoFormulario($this->inscricao)['nome']);
    }

    public function testRespostaGuardaOArquivoAtualECadaInscricaoEnviaDeNovo(): void
    {
        $antigo = $this->enviarArquivo(base64_decode(self::PNG), 'rg.png');
        $atual = $this->enviarArquivo(base64_decode(self::PNG), 'rg-2.png');
        // A tela pode ter guardado o id antigo no rascunho: vale o arquivo atual.
        $this->enviarFormulario($this->inscricao, (string)$antigo['id']);
        $this->assertResponseOk();
        $resposta = $this->fetchTable('Answers')->find()
            ->where(['pre_registration_id' => $this->inscricao->id, 'question_id' => $this->documento->id, 'deleted IS' => null])
            ->firstOrFail();
        $this->assertSame((string)$atual['id'], $resposta->answer);

        // Outra inscrição: o arquivo do formulário anterior não vale.
        $outra = $this->inscreverNoAcampamento($this->pessoa);
        $this->assertNull($this->arquivoNoFormulario($outra));
        $this->enviarFormulario($outra, '');
        $this->assertResponseCode(422);
        $this->assertStringContainsString('Envie o arquivo', $this->respostaJson()['error']);
        $this->enviarFormulario($outra, (string)$atual['id']);
        $this->assertResponseCode(422, 'O id de um arquivo de outra inscrição não serve.');

        $deNovo = $this->enviarArquivo(base64_decode(self::PNG), 'rg-de-novo.png', $outra);
        $this->enviarFormulario($outra, (string)$deNovo['id']);
        $this->assertResponseOk();
        $this->assertSame('rg-2.png', $this->arquivoNoFormulario($this->inscricao)['nome'], 'O da primeira continua com ela.');
    }

    public function testSoADonaEnviaComOFormularioAbertoENaPerguntaDeArquivo(): void
    {
        $this->enviarArquivo(base64_decode(self::PNG), 'rg.png', quem: $this->criarUsuario('Bia'));
        $this->assertResponseCode(404);

        $prazoFechado = $this->inscreverNoAcampamento($this->pessoa, $this->ontem());
        $this->enviarArquivo(base64_decode(self::PNG), 'rg.png', $prazoFechado);
        $this->assertResponseCode(422);

        $this->enviarArquivo(base64_decode(self::PNG), 'rg.png', pergunta: $this->apelido);
        $this->assertResponseCode(422, 'Pergunta de texto não recebe arquivo.');

        $this->assertSame(0, $this->fetchTable('UserDocuments')->find()->count());
    }

    public function testRecusaArquivoQueNaoEFotoNemPdf(): void
    {
        // Nome de imagem, conteúdo de texto: o tipo é conferido pelo conteúdo.
        $this->enviarArquivo('isto não é uma imagem', 'documento.docx');
        $this->assertResponseCode(422);
        $this->assertStringContainsString('documento.docx', $this->respostaJson()['error']);
        $this->assertSame(0, $this->fetchTable('UserDocuments')->find()->count());
    }

    public function testArquivoOpcionalRemovidoSaiDaResposta(): void
    {
        $this->documento = $this->fetchTable('Questions')->patchEntity($this->documento, ['is_required' => false]);
        $this->fetchTable('Questions')->saveOrFail($this->documento);

        $doc = $this->enviarArquivo(base64_decode(self::PNG), 'receita.png');
        $this->enviarFormulario($this->inscricao, (string)$doc['id']);
        $this->assertResponseOk();

        // Clicou em "Remover" e enviou de novo.
        $this->enviarFormulario($this->inscricao, '');
        $this->assertResponseOk();
        $this->assertNotNull($this->fetchTable('UserDocuments')->get($doc['id'])->deleted);
        $this->assertNull($this->arquivoNoFormulario($this->inscricao));
    }

    public function testQuemVeAsRespostasVeEAbreOArquivo(): void
    {
        $doc = $this->enviarArquivo(base64_decode(self::PNG), 'rg.png');
        $this->enviarFormulario($this->inscricao, (string)$doc['id']);
        $respostas = '/api/v1/admin/formularios/respostas?' . http_build_query([
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'atividade_id' => $this->inscricao->activity_id,
        ]);

        $saude = $this->criarUsuario('Equipe de saúde', idade: 40);
        (new PermissoesService())->definir((int)$saude->id, ['respostas_formularios']);
        $this->autenticarComo($saude);
        $this->get($respostas);
        $pessoa = $this->respostaJson()['data']['pessoas'][0];
        $this->assertSame((string)$doc['id'], $pessoa['respostas'][$this->documento->id]);
        $this->assertSame('rg.png', $pessoa['arquivos'][$this->documento->id]['nome']);
        $this->autenticarComo($saude);
        $this->get("/api/v1/admin/documentos/{$doc['id']}/arquivo");
        $this->assertResponseOk();
        $this->assertContentType('image/png');

        // Quem só monta as perguntas não abre arquivo de ninguém.
        $editor = $this->criarUsuario('Editor', idade: 40);
        (new PermissoesService())->definir((int)$editor->id, ['formularios']);
        $this->autenticarComo($editor);
        $this->get("/api/v1/admin/documentos/{$doc['id']}/arquivo");
        $this->assertResponseCode(403);
        $this->assertArrayNotHasKey('documentos', PermissoesService::TELAS, 'Não há mais permissão separada para documentos.');
    }

    public function testTrocarOTipoParaArquivoEscondeRespostasESoltaCondicionais(): void
    {
        $remedio = $this->pergunta('Toma remédio?', 'sim_nao', 3);
        $sim = $this->salvar('Options', ['question_id' => $remedio->id, 'text' => 'Sim', 'order' => 1]);
        $this->salvar('Options', ['question_id' => $remedio->id, 'text' => 'Não', 'order' => 2]);
        $qual = $this->salvar('Questions', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'section_id' => $this->secao,
            'text' => 'Qual?', 'type' => 'texto', 'is_required' => false, 'order' => 4, 'accept_generic_answer' => false,
            'depends_on_option_id' => $sim->id,
        ]);
        $resposta = $this->salvar('Answers', ['pre_registration_id' => $this->inscricao->id, 'question_id' => $remedio->id, 'answer' => (string)$sim->id]);

        $admin = $this->criarUsuario('Admin', idade: 40, admin: true);
        $this->autenticarComo($admin);
        $this->patch("/api/v1/admin/perguntas/{$remedio->id}", ['type' => 'arquivo']);
        $this->assertResponseOk();
        $this->assertSame('arquivo', $this->respostaJson()['data']['type']);
        $this->assertSame([], $this->respostaJson()['data']['options']);
        $this->assertNotNull($this->fetchTable('Answers')->get($resposta->id)->deleted, 'A resposta antiga fica guardada, mas escondida.');
        $this->assertNull($this->fetchTable('Questions')->get($qual->id)->depends_on_option_id, 'A condicional passa a aparecer sempre.');
    }

    public function testArquivosDeFormularioNuncaEnviadoSaoApagadosDepoisDe30Dias(): void
    {
        $antigo = $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->amanha()]);
        $inscricao = $this->inscrever($this->pessoa, $antigo, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $doc = $this->enviarArquivo(base64_decode(self::PNG), 'rg.png', $inscricao);
        $enviado = $this->enviarArquivo(base64_decode(self::PNG), 'rg.png');
        $this->enviarFormulario($this->inscricao, (string)$enviado['id']);
        $caminho = UserDocumentsTable::PASTA . DS . $this->fetchTable('UserDocuments')->get($doc['id'])->file;

        $this->fetchTable('Activities')->updateAll(['start_date' => date('Y-m-d H:i:s', strtotime('-31 days'))], ['id' => $antigo->id]);
        $this->fetchTable('Activities')->updateAll(['start_date' => date('Y-m-d H:i:s', strtotime('-31 days'))], ['id' => $this->inscricao->activity_id]);

        $this->assertSame(1, $this->fetchTable('UserDocuments')->limparNaoEnviados());
        $this->assertFileDoesNotExist($caminho);
        $this->assertNotNull($this->fetchTable('UserDocuments')->get($doc['id'])->deleted);
        $this->assertNull($this->fetchTable('UserDocuments')->get($enviado['id'])->deleted, 'Formulário enviado guarda o arquivo.');
    }
}
