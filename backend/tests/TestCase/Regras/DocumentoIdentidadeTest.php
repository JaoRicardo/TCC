<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Model\Table\UserDocumentsTable;
use App\Service\PermissoesService;
use Cake\Datasource\EntityInterface;
use Laminas\Diactoros\UploadedFile;

/**
 * Documento de identidade: a pessoa envia junto com o formulário de cada
 * inscrição, todas as vezes (não fica no cadastro), e só quem tem a permissão
 * "documentos" abre o arquivo, na aba Respostas.
 */
class DocumentoIdentidadeTest extends RegrasTestCase
{
    /** PNG de 1×1 pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private EntityInterface $pessoa;
    private EntityInterface $pergunta;
    private EntityInterface $inscricao;

    /** @var list<string> Arquivos criados pelo teste, apagados no fim. */
    private array $criados = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pessoa = $this->criarUsuario('Ana');
        $secao = (int)$this->salvar('Sections', ['title' => 'Geral', 'order' => 1])->id;
        $this->pergunta = $this->salvar('Questions', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'section_id' => $secao,
            'text' => 'Apelido', 'type' => 'texto', 'is_required' => true, 'order' => 1, 'accept_generic_answer' => false,
        ]);
        $this->inscricao = $this->inscreverNoAcampamento($this->pessoa);
    }

    protected function tearDown(): void
    {
        foreach ($this->fetchTable('UserDocuments')->find()->all() as $doc) {
            $this->criados[] = UserDocumentsTable::PASTA . DS . $doc->file;
        }
        parent::tearDown();
        foreach ($this->criados as $arquivo) {
            if (is_file($arquivo)) unlink($arquivo);
        }
    }

    private function inscreverNoAcampamento(EntityInterface $pessoa, ?string $prazo = null): EntityInterface
    {
        $acampamento = $this->criarAcampamento('FAC', ['camper_form_deadline' => $prazo ?? $this->amanha()]);

        return $this->inscrever($pessoa, $acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
    }

    private function enviarArquivo(string $conteudo, string $nome, ?EntityInterface $inscricao = null, ?EntityInterface $quem = null): void
    {
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
        $this->post('/api/v1/inscricoes/' . ($inscricao ?? $this->inscricao)->id . '/documento');
    }

    private function enviarFormulario(EntityInterface $inscricao): void
    {
        $this->autenticarComo($this->pessoa);
        $this->post("/api/v1/inscricoes/{$inscricao->id}/formulario", [
            'answers' => [['question_id' => $this->pergunta->id, 'answer' => 'Aninha']],
        ]);
    }

    private function documentoNoFormulario(EntityInterface $inscricao): ?array
    {
        $this->autenticarComo($this->pessoa);
        $this->get("/api/v1/inscricoes/{$inscricao->id}/formulario");

        return $this->respostaJson()['data']['documento'];
    }

    public function testEnviaComOFormularioETrocaGuardandoForaDaPastaPublica(): void
    {
        $this->enviarArquivo(base64_decode(self::PNG), 'rg-frente.png');
        $this->assertResponseCode(201);
        $primeiro = $this->respostaJson()['data'];
        $this->assertSame('imagem', $primeiro['tipo']);
        $this->assertArrayNotHasKey('conferido_em', $primeiro, 'Não há mais conferência separada.');

        $doc = $this->fetchTable('UserDocuments')->get($primeiro['id']);
        $this->assertSame($this->inscricao->id, $doc->pre_registration_id);
        $this->assertFileExists(UserDocumentsTable::PASTA . DS . $doc->file);
        $this->assertStringNotContainsString('webroot', UserDocumentsTable::PASTA, 'Documento não pode ter URL pública.');

        // Trocou o documento: o anterior sai com exclusão lógica.
        $this->enviarArquivo(base64_decode(self::PNG), 'rg-nova-foto.png');
        $this->assertResponseCode(201);
        $this->assertNotNull($this->fetchTable('UserDocuments')->get($primeiro['id'])->deleted);
        $this->assertSame('rg-nova-foto.png', $this->documentoNoFormulario($this->inscricao)['nome']);
    }

    public function testCadaInscricaoPedeODocumentoDeNovo(): void
    {
        $this->enviarArquivo(base64_decode(self::PNG), 'rg.png');
        $this->enviarFormulario($this->inscricao);
        $this->assertResponseOk();

        // Outra inscrição: o documento do formulário anterior não vale.
        $outra = $this->inscreverNoAcampamento($this->pessoa);
        $this->assertNull($this->documentoNoFormulario($outra));
        $this->enviarFormulario($outra);
        $this->assertResponseCode(422);
        $this->assertStringContainsString('documento', $this->respostaJson()['error']);

        $this->enviarArquivo(base64_decode(self::PNG), 'rg-de-novo.png', $outra);
        $this->enviarFormulario($outra);
        $this->assertResponseOk();
        $this->assertSame('rg.png', $this->documentoNoFormulario($this->inscricao)['nome'], 'O da primeira continua com ela.');
    }

    public function testSoADonaEnviaEComOFormularioAberto(): void
    {
        $this->enviarArquivo(base64_decode(self::PNG), 'rg.png', quem: $this->criarUsuario('Bia'));
        $this->assertResponseCode(404);

        $prazoFechado = $this->inscreverNoAcampamento($this->pessoa, $this->ontem());
        $this->enviarArquivo(base64_decode(self::PNG), 'rg.png', $prazoFechado);
        $this->assertResponseCode(422);

        $this->assertSame(0, $this->fetchTable('UserDocuments')->find()->count());
    }

    public function testRecusaArquivoQueNaoEFotoNemPdf(): void
    {
        // Nome de imagem, conteúdo de texto: o tipo é conferido pelo conteúdo.
        $this->enviarArquivo('isto não é uma imagem', 'documento.png');
        $this->assertResponseCode(422);
        $this->assertSame(0, $this->fetchTable('UserDocuments')->find()->count());
    }

    public function testSoQuemTemAPermissaoVeODocumentoNasRespostas(): void
    {
        $this->enviarArquivo(base64_decode(self::PNG), 'rg.png');
        $id = $this->respostaJson()['data']['id'];
        $this->enviarFormulario($this->inscricao);
        $respostas = '/api/v1/admin/formularios/respostas?' . http_build_query([
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'atividade_id' => $this->inscricao->activity_id,
        ]);

        $saude = $this->criarUsuario('Equipe de saúde', idade: 40);
        (new PermissoesService())->definir((int)$saude->id, ['respostas_formularios']);
        $this->autenticarComo($saude);
        $this->get("/api/v1/admin/documentos/{$id}/arquivo");
        $this->assertResponseCode(403);
        $this->autenticarComo($saude);
        $this->get($respostas);
        $this->assertArrayNotHasKey('documento', $this->respostaJson()['data']['pessoas'][0]);

        $secretaria = $this->criarUsuario('Secretaria', idade: 40);
        (new PermissoesService())->definir((int)$secretaria->id, ['documentos']);
        $this->assertSame(
            ['respostas_formularios', 'documentos'],
            (new PermissoesService())->daPessoa($secretaria),
            'Documentos depende de Respostas dos formulários: é lá que o documento aparece.'
        );
        $this->autenticarComo($secretaria);
        $this->get($respostas);
        $this->assertSame($id, $this->respostaJson()['data']['pessoas'][0]['documento']['id']);
        $this->autenticarComo($secretaria);
        $this->get("/api/v1/admin/documentos/{$id}/arquivo");
        $this->assertResponseOk();
        $this->assertContentType('image/png');
    }

    public function testFormularioNaoEEnviadoSemDocumento(): void
    {
        $this->enviarFormulario($this->inscricao);
        $this->assertResponseCode(422);
        $this->assertStringContainsString('documento', $this->respostaJson()['error']);
        $this->assertFalse($this->fetchTable('PreRegistrations')->get($this->inscricao->id)->form_submitted);

        $this->enviarArquivo(base64_decode(self::PNG), 'rg.png');
        $this->enviarFormulario($this->inscricao);
        $this->assertResponseOk();
        $this->assertTrue($this->fetchTable('PreRegistrations')->get($this->inscricao->id)->form_submitted);
    }
}
