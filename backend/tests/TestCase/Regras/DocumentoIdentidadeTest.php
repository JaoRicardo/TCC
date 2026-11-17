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

    private function enviarArquivo(string $conteudo, string $nome, ?EntityInterface $quem = null, ?string $rota = null, array $dados = []): void
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
        $this->post($rota ?? '/api/v1/me/documento', $dados);
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

    public function testOrganizacaoEnviaNoLugarDaPessoaPelaFicha(): void
    {
        $secretaria = $this->criarUsuario('Secretaria', idade: 40);
        (new PermissoesService())->definir((int)$secretaria->id, ['documentos']);
        $soPessoas = $this->criarUsuario('Voluntário', idade: 40);
        (new PermissoesService())->definir((int)$soPessoas->id, ['usuarios']);
        $rota = "/api/v1/admin/users/{$this->pessoa->id}/documento";

        // A pessoa já tinha enviado um; a secretaria troca pelo que recebeu em papel.
        $this->enviarArquivo(base64_decode(self::PNG), 'foto-borrada.png');
        $antigo = $this->respostaJson()['data']['id'];

        $this->enviarArquivo(base64_decode(self::PNG), 'rg-escaneado.png', $secretaria, $rota, ['conferido' => 'true']);
        $this->assertResponseCode(201);
        $novo = $this->respostaJson()['data'];
        $this->assertSame('rg-escaneado.png', $novo['nome']);
        $this->assertSame('Secretaria', $novo['enviado_por']);
        $this->assertFalse($novo['pela_pessoa']);
        $this->assertSame('Secretaria', $novo['conferido_por']);
        $this->assertNotNull($novo['conferido_em']);
        $this->assertNotNull($this->fetchTable('UserDocuments')->get($antigo)->deleted, 'O antigo vai para o histórico.');

        // Sem marcar "Já conferi", fica para conferir.
        $this->enviarArquivo(base64_decode(self::PNG), 'rg-2.png', $secretaria, $rota, ['conferido' => 'false']);
        $this->assertNull($this->respostaJson()['data']['conferido_em']);

        // Na ficha, o documento só vem para quem tem "Documentos".
        $this->autenticarComo($secretaria);
        $this->get("/api/v1/admin/users/{$this->pessoa->id}");
        $this->assertSame('rg-2.png', $this->respostaJson()['data']['documento']['nome']);
        $this->autenticarComo($soPessoas);
        $this->get("/api/v1/admin/users/{$this->pessoa->id}");
        $this->assertArrayNotHasKey('documento', $this->respostaJson()['data']);

        // E só quem tem "Documentos" envia.
        $this->enviarArquivo(base64_decode(self::PNG), 'rg-3.png', $soPessoas, $rota);
        $this->assertResponseCode(403);
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
            ['usuarios', 'documentos'],
            (new PermissoesService())->daPessoa($conferente),
            'Documentos depende de Pessoas e acessos: é na ficha da pessoa que ele é visto e trocado.'
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
