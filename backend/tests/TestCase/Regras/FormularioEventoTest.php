<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use Cake\Datasource\EntityInterface;

/**
 * Formulários de evento.
 *
 * Evento não tem categoria, servos nem sorteio: o formulário é um modelo com
 * nome próprio que cada evento pode usar. Com formulário, a inscrição fica "em
 * andamento" e só conclui quando a pessoa envia — e a vaga só é garantida no
 * envio, por ordem de chegada. Sem formulário, a inscrição conclui na hora.
 */
class FormularioEventoTest extends RegrasTestCase
{
    private EntityInterface $admin;

    private int $secaoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Organização', idade: 40, admin: true);
        $this->secaoId = (int)$this->salvar('Sections', ['title' => 'Logística', 'order' => 1])->id;
    }

    private function criarEvento(array $dados = []): EntityInterface
    {
        return $this->salvar('Activities', $dados + [
            'name' => 'Retiro de Carnaval', 'image' => '', 'place' => 'Chácara', 'year' => 2027,
            'start_date' => date('Y-m-d 08:00:00', strtotime('+30 days')), 'duration_days' => 2,
            'total_vacancies' => 50, 'participant_fee' => 0, 'servant_fee' => 0,
            'participant_registration_end_date' => date('Y-m-d 23:59:00', strtotime('+10 days')),
        ]);
    }

    private function criarFormulario(string $nome, array $extra = []): int
    {
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/formularios-evento', ['nome' => $nome] + $extra);
        $this->assertResponseCode(201);

        return $this->respostaJson()['data']['id'];
    }

    private function perguntaNoEvento(int $formId, string $texto, string $tipo = 'texto'): array
    {
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/perguntas', [
            'event_form_id' => $formId, 'section_id' => $this->secaoId, 'text' => $texto, 'type' => $tipo,
        ]);
        $this->assertResponseCode(201);

        return $this->respostaJson()['data'];
    }

    private function participante(string $nome): EntityInterface
    {
        return $this->criarUsuario($nome, idade: 20);
    }

    private function inscreverNoEvento(EntityInterface $pessoa, EntityInterface $evento): array
    {
        $this->autenticarComo($pessoa);
        $this->post("/api/v1/activities/{$evento->id}/inscricoes", ['tipo' => 'participante']);

        return $this->respostaJson();
    }

    private function enviar(EntityInterface $pessoa, int $inscricaoId, int $perguntaId): void
    {
        // Documento desta inscrição (sem ele o formulário não é enviado; ver DocumentoIdentidadeTest).
        if (!$this->fetchTable('UserDocuments')->daInscricao($inscricaoId)) {
            $this->salvar('UserDocuments', [
                'user_id' => $pessoa->id, 'pre_registration_id' => $inscricaoId,
                'file' => 'x.png', 'original_name' => 'rg.png', 'mime' => 'image/png', 'size' => 1,
            ]);
        }
        $this->autenticarComo($pessoa);
        $this->post("/api/v1/inscricoes/{$inscricaoId}/formulario", ['answers' => [['question_id' => $perguntaId, 'answer' => 'Jardim Riva']]]);
    }

    public function testCriarFormularioCopiandoDeUmAcampamentoELigarAEventos(): void
    {
        $this->salvar('Questions', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'section_id' => $this->secaoId,
            'text' => 'Tem alergia?', 'type' => 'texto', 'is_required' => true, 'order' => 1, 'accept_generic_answer' => false,
        ]);
        $semFormulario = $this->criarEvento(['name' => 'Missa de Envio']);
        $acampamento = $this->criarAcampamento('FAC');

        $id = $this->criarFormulario('Retiro de Carnaval', [
            'copiar_de' => ['category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista'],
            'eventos' => [$semFormulario->id, $acampamento->id],
        ]);

        $this->autenticarComo($this->admin);
        $this->get("/api/v1/admin/perguntas?event_form_id={$id}");
        $perguntas = $this->respostaJson()['data'];
        $this->assertSame(['Tem alergia?'], array_column($perguntas, 'text'));
        $this->assertSame($id, $perguntas[0]['event_form_id']);
        $this->assertNull($perguntas[0]['category_id']);

        $this->assertSame($id, $this->fetchTable('Activities')->get($semFormulario->id)->event_form_id);
        $this->assertNull($this->fetchTable('Activities')->get($acampamento->id)->event_form_id, 'Acampamento não usa formulário de evento.');

        // Nome repetido não.
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/formularios-evento', ['nome' => 'Retiro de Carnaval']);
        $this->assertResponseCode(422);
    }

    public function testCondicaoNaoPodeDependerDeOutroFormulario(): void
    {
        $id = $this->criarFormulario('Básico');
        $doAcampamento = $this->salvar('Questions', [
            'category_id' => $this->categorias['FAC'], 'subscription_type' => 'Campista', 'section_id' => $this->secaoId,
            'text' => 'Carona?', 'type' => 'sim_nao', 'is_required' => true, 'order' => 1, 'accept_generic_answer' => false,
        ]);
        $sim = $this->salvar('Options', ['question_id' => $doAcampamento->id, 'text' => 'Sim', 'order' => 1]);

        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/perguntas', [
            'event_form_id' => $id, 'section_id' => $this->secaoId, 'text' => 'De onde?', 'type' => 'texto',
            'depends_on_option_id' => $sim->id,
        ]);
        $this->assertResponseCode(422);
    }

    public function testInscricaoSoConcluiAoEnviarOFormulario(): void
    {
        $id = $this->criarFormulario('Retiro');
        $pergunta = $this->perguntaNoEvento($id, 'De qual bairro?');
        $evento = $this->criarEvento(['event_form_id' => $id]);
        $ana = $this->participante('Ana');

        $inscricao = $this->inscreverNoEvento($ana, $evento)['data'];
        $this->assertSame('Inscrição em andamento', $inscricao['status']);
        $this->assertTrue($inscricao['formularioPendente']);

        $this->autenticarComo($ana);
        $this->get("/api/v1/inscricoes/{$inscricao['id']}/formulario");
        $formulario = $this->respostaJson()['data'];
        $this->assertTrue($formulario['eh_evento']);
        $this->assertTrue($formulario['editavel']);
        $this->assertSame($evento->participant_registration_end_date->format('Y-m-d\TH:i'), $formulario['prazo']['limite'], 'O prazo é o fim das inscrições.');

        $this->enviar($ana, $inscricao['id'], $pergunta['id']);
        $this->assertResponseOk();

        $this->autenticarComo($ana);
        $this->get('/api/v1/me/inscricoes');
        $this->assertSame('Inscrito', $this->respostaJson()['data'][0]['status']);
    }

    public function testVagaSoEGarantidaNoEnvioPorOrdemDeChegada(): void
    {
        $id = $this->criarFormulario('Retiro');
        $pergunta = $this->perguntaNoEvento($id, 'De qual bairro?');
        $evento = $this->criarEvento(['event_form_id' => $id, 'total_vacancies' => 1]);
        $ana = $this->participante('Ana');
        $bia = $this->participante('Bia');

        // As duas começam: começar não ocupa vaga.
        $inscricaoAna = $this->inscreverNoEvento($ana, $evento)['data']['id'];
        $inscricaoBia = $this->inscreverNoEvento($bia, $evento)['data']['id'];

        // Bia envia primeiro e fica com a vaga.
        $this->enviar($bia, $inscricaoBia, $pergunta['id']);
        $this->assertResponseOk();
        $this->enviar($ana, $inscricaoAna, $pergunta['id']);
        $this->assertResponseCode(422);
        $this->assertStringContainsString('vagas', $this->respostaJson()['error']);
        $this->assertFalse($this->fetchTable('PreRegistrations')->get($inscricaoAna)->form_submitted);

        // Corrigir as respostas depois não esbarra na vaga (já é dela).
        $this->enviar($bia, $inscricaoBia, $pergunta['id']);
        $this->assertResponseOk();

        // Evento cheio: nem começa a inscrição.
        $resposta = $this->inscreverNoEvento($this->participante('Carla'), $evento);
        $this->assertResponseCode(422);
        $this->assertStringContainsString('vagas', $resposta['error']);
    }

    public function testDepoisDoFimDasInscricoesNaoInscreveNemEnvia(): void
    {
        $id = $this->criarFormulario('Retiro');
        $pergunta = $this->perguntaNoEvento($id, 'De qual bairro?');
        $evento = $this->criarEvento(['event_form_id' => $id]);
        $ana = $this->participante('Ana');
        $inscricao = $this->inscreverNoEvento($ana, $evento)['data']['id'];

        $this->fetchTable('Activities')->updateAll(['participant_registration_end_date' => $this->ontem()], ['id' => $evento->id]);

        $this->enviar($ana, $inscricao, $pergunta['id']);
        $this->assertResponseCode(422);
        $this->assertStringContainsString('inscrições terminaram', $this->respostaJson()['error']);

        $this->inscreverNoEvento($this->participante('Bia'), $evento);
        $this->assertResponseCode(422);
    }

    public function testEventoGratuitoSemDatasFicaComInscricoesAbertasAteOInicio(): void
    {
        // Evento gratuito não tem "Início das vendas": antes ficava "Em Breve" para sempre.
        $evento = $this->criarEvento(['participant_registration_end_date' => null]);
        $this->get("/api/v1/activities/{$evento->id}");
        $this->assertSame('Inscrições Abertas', $this->respostaJson()['data']['status']);

        $passado = $this->criarEvento(['start_date' => $this->ontem(), 'participant_registration_end_date' => null]);
        $this->get("/api/v1/activities/{$passado->id}");
        $this->assertSame('Encerrado', $this->respostaJson()['data']['status']);
    }

    public function testEventoSemFormularioConcluiNaHora(): void
    {
        $evento = $this->criarEvento();
        $resposta = $this->inscreverNoEvento($this->participante('Ana'), $evento);
        $this->assertResponseCode(201);
        $this->assertSame('Inscrito', $resposta['data']['status']);
        $this->assertFalse($resposta['data']['formularioPendente']);
    }

    public function testRespostasEListaDoFormularioDeEvento(): void
    {
        $id = $this->criarFormulario('Retiro');
        $pergunta = $this->perguntaNoEvento($id, 'De qual bairro?');
        $evento = $this->criarEvento(['event_form_id' => $id]);
        $this->criarEvento(['name' => 'Noite de Louvor']);
        $ana = $this->participante('Ana');
        $bia = $this->participante('Bia');
        $inscricaoAna = $this->inscreverNoEvento($ana, $evento)['data']['id'];
        $this->inscreverNoEvento($bia, $evento);
        $this->enviar($ana, $inscricaoAna, $pergunta['id']);

        $this->autenticarComo($this->admin);
        $this->get("/api/v1/admin/formularios/respostas?event_form_id={$id}");
        $dados = $this->respostaJson()['data'];
        $this->assertSame($evento->id, $dados['atividade_id']);
        $pessoas = array_column($dados['pessoas'], null, 'nome');
        $this->assertSame('Inscrito', $pessoas['Ana']['situacao']);
        $this->assertSame('Jardim Riva', $pessoas['Ana']['respostas'][(string)$pergunta['id']]);
        $this->assertFalse($pessoas['Bia']['enviou'], 'Quem não enviou aparece em Pendentes.');
        $this->assertSame('Em andamento', $pessoas['Bia']['situacao']);

        $this->autenticarComo($this->admin);
        $this->get('/api/v1/admin/formularios/resumo');
        $eventos = $this->respostaJson()['eventos'];
        $this->assertSame(1, $eventos['formularios'][0]['perguntas']);
        $this->assertSame(1, $eventos['formularios'][0]['respostas']);
        $this->assertSame([$evento->id], array_column($eventos['formularios'][0]['eventos'], 'atividade_id'));
        $this->assertSame(['Noite de Louvor'], array_column($eventos['sem_formulario'], 'nome'));
    }
}
