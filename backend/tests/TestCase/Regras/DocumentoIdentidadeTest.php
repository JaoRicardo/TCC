<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Model\Table\UserDocumentsTable;
use App\Service\PermissoesService;
use Cake\Datasource\EntityInterface;
use Laminas\Diactoros\UploadedFile;

/**
 * Documento de identidade: a pessoa envia no formulário, fica no cadastro, e
 * só quem tem a permissão "documentos" abre o arquivo no painel.
 */
class DocumentoIdentidadeTest extends RegrasTestCase
{
    /** PNG de 1×1 pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private EntityInterface $pessoa;

    /** @var list<string> Arquivos criados pelo teste, apagados no fim. */
    private array $criados = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pessoa = $this->criarUsuario('Ana');
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

    private function enviarArquivo(string $conteudo, string $nome): void
    {
        $temporario = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($temporario, $conteudo);
        $this->criados[] = $temporario;

        $this->autenticarComo($this->pessoa);
        // configRequest acumula (só os cabeçalhos são limpos a cada requisição): sem isto o
        // arquivo do envio anterior iria junto.
        unset($this->_request['files']);
        $this->configRequest([
            'files' => ['arquivo' => new UploadedFile($temporario, strlen($conteudo), UPLOAD_ERR_OK, $nome, 'image/png')],
        ]);
        $this->post('/api/v1/me/documento');
    }

    public function testEnviaTrocaEGuardaForaDaPastaPublica(): void
    {
        $this->enviarArquivo(base64_decode(self::PNG), 'rg-frente.png');
        $this->assertResponseCode(201);
        $primeiro = $this->respostaJson()['data'];
        $this->assertSame('imagem', $primeiro['tipo']);

        $doc = $this->fetchTable('UserDocuments')->get($primeiro['id']);
        $this->assertFileExists(UserDocumentsTable::PASTA . DS . $doc->file);
        $this->assertStringNotContainsString('webroot', UserDocumentsTable::PASTA, 'Documento não pode ter URL pública.');

        // Trocou o documento: o anterior sai com exclusão lógica.
        $this->enviarArquivo(base64_decode(self::PNG), 'rg-nova-foto.png');
        $this->assertResponseCode(201);
        $this->assertNotNull($this->fetchTable('UserDocuments')->get($primeiro['id'])->deleted);

        $this->autenticarComo($this->pessoa);
        $this->get('/api/v1/me/documento');
        $this->assertSame('rg-nova-foto.png', $this->respostaJson()['data']['nome']);
    }

    public function testRecusaArquivoQueNaoEFotoNemPdf(): void
    {
        // Nome de imagem, conteúdo de texto: o tipo é conferido pelo conteúdo.
        $this->enviarArquivo('isto não é uma imagem', 'documento.png');
        $this->assertResponseCode(422);
        $this->assertSame(0, $this->fetchTable('UserDocuments')->find()->count());
    }

    public function testSoQuemTemAPermissaoAbreEConfereODocumento(): void
    {
        $this->enviarArquivo(base64_decode(self::PNG), 'rg.png');
        $id = $this->respostaJson()['data']['id'];

        $semPermissao = $this->criarUsuario('Equipe de saúde', idade: 40);
        (new PermissoesService())->definir((int)$semPermissao->id, ['respostas_formularios']);
        $this->autenticarComo($semPermissao);
        $this->get("/api/v1/admin/documentos/{$id}/arquivo");
        $this->assertResponseCode(403);

        $conferente = $this->criarUsuario('Secretaria', idade: 40);
        (new PermissoesService())->definir((int)$conferente->id, ['documentos']);
        $this->assertSame(
            ['respostas_formularios', 'documentos'],
            (new PermissoesService())->daPessoa($conferente),
            'Documentos depende de Respostas: é na ficha da aba Respostas que ele aparece.'
        );
        $this->autenticarComo($conferente);
        $this->get("/api/v1/admin/documentos/{$id}/arquivo");
        $this->assertResponseOk();
        $this->assertContentType('image/png');

        $this->autenticarComo($conferente);
        $this->post("/api/v1/admin/documentos/{$id}/conferir", ['conferido' => true]);
        $this->assertResponseOk();
        $this->assertNotNull($this->respostaJson()['data']['conferido_em']);
    }

    public function testFormularioNaoEEnviadoSemDocumento(): void
    {
        $secao = (int)$this->salvar('Sections', ['title' => 'Geral', 'order' => 1])->id;
        $pergunta = $this->salvar('Questions', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'section_id' => $secao,
            'text' => 'Apelido', 'type' => 'texto_curto', 'is_required' => true, 'order' => 1, 'accept_generic_answer' => false,
        ]);
        $acampamento = $this->criarAcampamento('FAC', ['camper_form_deadline' => $this->amanha()]);
        $inscricao = $this->inscrever($this->pessoa, $acampamento, 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $enviar = function () use ($inscricao, $pergunta) {
            $this->autenticarComo($this->pessoa);
            $this->post("/api/v1/inscricoes/{$inscricao->id}/formulario", ['answers' => [['question_id' => $pergunta->id, 'answer' => 'Aninha']]]);
        };

        $enviar();
        $this->assertResponseCode(422);
        $this->assertStringContainsString('documento', $this->respostaJson()['error']);
        $this->assertFalse($this->fetchTable('PreRegistrations')->get($inscricao->id)->form_submitted);

        $this->enviarArquivo(base64_decode(self::PNG), 'rg.png');
        $enviar();
        $this->assertResponseOk();
        $this->assertTrue($this->fetchTable('PreRegistrations')->get($inscricao->id)->form_submitted);
    }
}
