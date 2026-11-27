<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;

/**
 * Casal no acampamento de Casais (CasalService): quem se inscreve informa o
 * CPF do cônjuge, o sistema inscreve os dois, e só vale depois de o cônjuge
 * confirmar que está ciente (até o sorteio). Recusar cancela as duas.
 */
class ConfirmacaoDoCasalTest extends RegrasTestCase
{
    private EntityInterface $casais;
    private EntityInterface $marido;
    private EntityInterface $esposa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->casais = $this->criarAcampamento('Casais');
        $this->marido = $this->criarUsuario('Marido Silva', idade: 35, sexo: 'M', estadoCivil: 'Casado');
        // O cadastro dela ainda diz "Solteiro": a confirmação corrige.
        $this->esposa = $this->criarUsuario('Esposa Silva', idade: 33, sexo: 'F', estadoCivil: 'Solteiro');
    }

    private function inscreverCasal(?string $cpf = null): array
    {
        $this->autenticarComo($this->marido);
        $this->post("/api/v1/activities/{$this->casais->id}/inscricoes", ['tipo' => 'campista', 'spouse_cpf' => $cpf ?? $this->esposa->cpf]);

        return $this->respostaJson();
    }

    private function inscricaoDe(EntityInterface $pessoa, bool $comApagadas = false): ?EntityInterface
    {
        $where = ['user_id' => $pessoa->id, 'activity_id' => $this->casais->id];
        if (!$comApagadas) $where['deleted IS'] = null;

        return $this->fetchTable('PreRegistrations')->find()->where($where)->first();
    }

    private function pendencias(EntityInterface $pessoa): array
    {
        $this->autenticarComo($pessoa);
        $this->get('/api/v1/me/casal');
        $this->assertResponseOk();

        return $this->respostaJson()['data'];
    }

    private function responder(EntityInterface $pessoa, string $resposta, array $extra = []): array
    {
        $id = $this->inscricaoDe($pessoa)->id;
        $this->autenticarComo($pessoa);
        $this->post("/api/v1/inscricoes/{$id}/casal", ['resposta' => $resposta] + $extra);

        return $this->respostaJson();
    }

    private function situacao(EntityInterface $pessoa): string
    {
        $this->autenticarComo($pessoa);
        $this->get('/api/v1/me/inscricoes');
        foreach ($this->respostaJson()['data'] as $i) {
            if ($i['activity']['id'] === $this->casais->id) return $i['status'];
        }

        return '';
    }

    public function testInscreveOsDoisAguardandoOConjuge(): void
    {
        $this->inscreverCasal();
        $this->assertResponseCode(201);

        $dele = $this->inscricaoDe($this->marido);
        $dela = $this->inscricaoDe($this->esposa);
        $this->assertNotNull($dela, 'A inscrição do cônjuge é criada junto.');
        $this->assertTrue($dele->awaiting_spouse);
        $this->assertTrue($dela->awaiting_spouse);
        $this->assertTrue($dela->added_by_spouse);
        $this->assertSame('Aguardando cônjuge', $this->situacao($this->marido));

        $pend = $this->pendencias($this->esposa)['pendentes'];
        $this->assertCount(1, $pend);
        $this->assertSame('Marido Silva', $pend[0]['conjuge']);
        $this->assertSame(['atual' => 'Solteiro', 'novo' => 'Casado'], $pend[0]['estadoCivil']);
        $this->assertSame([], $this->pendencias($this->marido)['pendentes'], 'Quem inscreveu não confirma.');
    }

    public function testSemConfirmacaoNaoConcorreNemPodeSerIndicado(): void
    {
        $this->inscreverCasal();
        (new SorteioService())->realizarCampistas((int)$this->casais->id, 0, 0, 5);

        $this->assertNull($this->recarregar($this->inscricaoDe($this->marido))->selection_method_id);
        $this->assertSame('Casal não confirmado', $this->situacao($this->marido));
        $this->assertSame([], $this->pendencias($this->esposa)['pendentes'], 'Depois do sorteio, a janela não aparece mais.');

        $this->autenticarComo($this->criarUsuario('Admin', admin: true));
        $this->post("/api/v1/activities/{$this->casais->id}/indicate", [
            'pre_registration_id' => $this->inscricaoDe($this->marido)->id,
            'selection_method_id' => $this->metodos['Conselho'],
        ]);
        $this->assertResponseCode(422);
    }

    public function testConfirmarCorrigeOEstadoCivilEOCasalConcorre(): void
    {
        $this->inscreverCasal();
        $r = $this->responder($this->esposa, 'confirmar');
        $this->assertResponseOk();
        $this->assertSame('Casado', $r['data']['estadoCivil']);

        $esposa = $this->fetchTable('Users')->get($this->esposa->id);
        $this->assertSame($this->estadosCivis['Casado'], (int)$esposa->marital_status_id);
        $this->assertFalse($this->inscricaoDe($this->marido)->awaiting_spouse);
        $this->assertFalse($this->inscricaoDe($this->esposa)->awaiting_spouse);

        (new SorteioService())->realizarCampistas((int)$this->casais->id, 0, 0, 1);
        $this->assertSame($this->metodos['Sorteio'], $this->inscricaoDe($this->marido)->selection_method_id);
        $this->assertSame($this->metodos['Sorteio'], $this->inscricaoDe($this->esposa)->selection_method_id);
    }

    public function testRecusarCancelaAsDuasEAvisaQuemInscreveu(): void
    {
        $this->inscreverCasal();
        $this->responder($this->esposa, 'recusar');
        $this->assertResponseOk();

        $this->assertNull($this->inscricaoDe($this->marido));
        $this->assertNull($this->inscricaoDe($this->esposa));

        $recusas = $this->pendencias($this->marido)['recusas'];
        $this->assertCount(1, $recusas);
        $this->assertSame('Esposa Silva', $recusas[0]['conjuge']);

        $this->autenticarComo($this->marido);
        $this->post("/api/v1/inscricoes/{$recusas[0]['id']}/casal/visto");
        $this->assertResponseOk();
        $this->assertSame([], $this->pendencias($this->marido)['recusas']);

        // Pode se inscrever de novo.
        $this->inscreverCasal();
        $this->assertResponseCode(201);
    }

    public function testQuemInscreveuCancelaEADoConjugeSaiJunto(): void
    {
        $this->inscreverCasal();
        $id = $this->inscricaoDe($this->marido)->id;
        $this->autenticarComo($this->marido);
        $this->delete("/api/v1/inscricoes/{$id}");
        $this->assertResponseOk();

        $this->assertNull($this->inscricaoDe($this->esposa));
        $this->assertSame([], $this->pendencias($this->esposa)['pendentes']);
    }

    public function testConfirmarSemEnderecoPedeCidadeECep(): void
    {
        $this->fetchTable('Addresses')->deleteAll(['user_id' => $this->esposa->id]);
        $this->inscreverCasal();
        $this->assertTrue($this->pendencias($this->esposa)['pendentes'][0]['precisaEndereco']);

        $r = $this->responder($this->esposa, 'confirmar');
        $this->assertResponseCode(422);
        $this->assertArrayHasKey('city', $r['errors']);

        $this->responder($this->esposa, 'confirmar', ['city' => 'Primavera do Leste', 'cep' => '78850-000']);
        $this->assertResponseOk();
        $this->assertSame(1, $this->fetchTable('Addresses')->find()->where(['user_id' => $this->esposa->id])->count());
    }

    public function testRecusaCpfDoConjugeQueNaoPode(): void
    {
        $this->autenticarComo($this->marido);
        $this->post("/api/v1/activities/{$this->casais->id}/inscricoes", ['tipo' => 'campista']);
        $this->assertResponseCode(422, 'Sem o CPF do cônjuge.');

        $this->inscreverCasal($this->marido->cpf);
        $this->assertResponseCode(422, 'O próprio CPF.');

        $this->jaFoiCampista($this->esposa, 'Casais');
        $r = $this->inscreverCasal();
        $this->assertResponseCode(422);
        $this->assertStringContainsString('Esposa Silva já participou como campista', $r['error']);
        $this->assertNull($this->inscricaoDe($this->marido));
    }

    public function testSorteioNaoDaVagaDeCasalParaQuemEstaSozinho(): void
    {
        // Inscrição antiga, sem o cônjuge inscrito: não ocupa vaga de casal.
        $sozinho = $this->criarUsuario('Sozinho', idade: 40, estadoCivil: 'Casado');
        $insc = $this->inscrever($sozinho, $this->casais);
        (new SorteioService())->realizarCampistas((int)$this->casais->id, 0, 0, 3);

        $this->assertNull($this->recarregar($insc)->selection_method_id);
    }
}
