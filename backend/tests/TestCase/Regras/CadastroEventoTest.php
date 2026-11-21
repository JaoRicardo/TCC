<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use Cake\Datasource\EntityInterface;

/**
 * Criar / Editar Evento (handoff design_handoff_painel_evento): datas sem hora
 * (abertura e fechamento obrigatórios, fechamento até o primeiro dia), prazo do
 * pagamento até o primeiro dia, vagas nunca abaixo das ocupadas, "Sobre o
 * evento", remover a imagem e a faixa etária que recusa a inscrição.
 */
class CadastroEventoTest extends RegrasTestCase
{
    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Organização', idade: 40, admin: true);
    }

    private function dia(int $dias, string $hora = '00:00'): string
    {
        return date('Y-m-d', strtotime("{$dias} days")) . "T{$hora}";
    }

    /** O que a tela manda ao criar um evento válido (primeiro dia daqui a 10 dias). */
    private function dadosValidos(array $extra = []): array
    {
        return $extra + [
            'name' => 'Vigília de São Miguel', 'place' => 'Igreja São Miguel Arcanjo',
            'target_audience' => 'Geral', 'description' => "Começa às 19h.\nTraga um terço.",
            'start_date' => $this->dia(10), 'duration_days' => 1, 'total_vacancies' => 80,
            'participant_registration_start_date' => $this->dia(0),
            'participant_registration_end_date' => $this->dia(10, '23:59'),
            'participant_fee' => 0, 'event_form_id' => '',
        ];
    }

    private function criar(array $activity): void
    {
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/activities', ['type' => 'Event', 'activity' => $activity]);
    }

    private function editar(int $id, array $activity): void
    {
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/activities/{$id}", ['_method' => 'PATCH', 'activity' => $activity]);
    }

    private function erros(): array
    {
        return array_map(fn($e) => reset($e), $this->respostaJson()['errors'] ?? []);
    }

    public function testCriaEventoComSobreOEventoEFechamentoNoPrimeiroDia(): void
    {
        $this->criar($this->dadosValidos());

        $this->assertResponseCode(201);
        $evento = $this->respostaJson()['data'];
        $this->assertSame("Começa às 19h.\nTraga um terço.", $evento['sobre']);
        $this->assertSame($this->dia(10, '23:59'), $evento['fimInscricoes']);
    }

    public function testAberturaEFechamentoSaoObrigatoriosEEmOrdem(): void
    {
        $this->criar($this->dadosValidos([
            'participant_registration_start_date' => '', 'participant_registration_end_date' => '',
        ]));
        $this->assertResponseCode(422);
        $this->assertSame('Informe quando as inscrições abrem.', $this->erros()['participant_registration_start_date']);
        $this->assertSame('Informe quando as inscrições fecham.', $this->erros()['participant_registration_end_date']);

        $this->criar($this->dadosValidos([
            'participant_registration_start_date' => $this->dia(5),
            'participant_registration_end_date' => $this->dia(4, '23:59'),
        ]));
        $this->assertSame('O fechamento não pode ser antes da abertura.', $this->erros()['participant_registration_end_date']);

        $this->criar($this->dadosValidos(['participant_registration_end_date' => $this->dia(11, '23:59')]));
        $this->assertStringStartsWith('As inscrições precisam fechar até o primeiro dia do evento', $this->erros()['participant_registration_end_date']);
    }

    public function testPrimeiroDiaNoPassadoSoNaCriacao(): void
    {
        $this->criar($this->dadosValidos(['start_date' => $this->dia(-1), 'participant_registration_start_date' => $this->dia(-5), 'participant_registration_end_date' => $this->dia(-1, '23:59')]));
        $this->assertSame('O primeiro dia já passou. Escolha hoje ou uma data futura.', $this->erros()['start_date']);
    }

    public function testEventoPagoPedePrazoAteOPrimeiroDia(): void
    {
        $this->criar($this->dadosValidos(['participant_fee' => 20]));
        $this->assertSame('Informe o prazo do pagamento.', $this->erros()['participant_payment_date']);

        $this->criar($this->dadosValidos(['participant_fee' => 20, 'participant_payment_date' => $this->dia(11, '23:59')]));
        $this->assertStringStartsWith('O prazo do pagamento precisa ser até o primeiro dia do evento', $this->erros()['participant_payment_date']);

        $this->criar($this->dadosValidos(['participant_fee' => 20, 'participant_payment_date' => $this->dia(10, '23:59')]));
        $this->assertResponseCode(201);
    }

    public function testFaixaEtariaInvalida(): void
    {
        $this->criar($this->dadosValidos(['min_age' => 30, 'max_age' => 18]));
        $this->assertSame('A idade mínima não pode ser maior que a máxima.', $this->erros()['min_age']);

        $this->criar($this->dadosValidos(['max_age' => 130]));
        $this->assertSame('Use idades entre 0 e 120 anos.', $this->erros()['max_age']);
    }

    public function testEdicaoNaoDeixaAsVagasAbaixoDasOcupadasERemoveAImagem(): void
    {
        $this->criar($this->dadosValidos(['total_vacancies' => 3]));
        $id = $this->respostaJson()['data']['id'];
        $evento = $this->fetchTable('Activities')->get($id);
        $evento->image = 'uploads/activities/capa.jpg';
        $this->fetchTable('Activities')->saveOrFail($evento);
        foreach (['Ana', 'Bia'] as $nome) {
            $this->inscrever($this->criarUsuario($nome, idade: 20, sexo: 'F'), $evento, 'Participante');
        }

        $this->editar($id, $this->dadosValidos(['total_vacancies' => 1]));
        $this->assertResponseCode(422);
        $this->assertSame('Já há 2 vagas ocupadas: o total não pode ser menor que isso.', $this->erros()['total_vacancies']);

        $this->editar($id, $this->dadosValidos(['total_vacancies' => 2, 'remove_image' => '1']));
        $this->assertResponseOk();
        $this->assertSame('', (string)$this->fetchTable('Activities')->get($id)->image);

        $this->autenticarComo($this->admin);
        $this->get("/api/v1/activities/{$id}/edit");
        $this->assertSame(2, $this->respostaJson()['data']['resumo']['inscricoes']);
    }

    public function testFaixaEtariaRecusaQuemEstaForaComAIdadeDoPrimeiroDia(): void
    {
        $this->criar($this->dadosValidos(['min_age' => 18, 'max_age' => 35]));
        $id = $this->respostaJson()['data']['id'];

        $jovem = $this->criarUsuario('Jovem', idade: 16);
        $this->autenticarComo($jovem);
        $this->get("/api/v1/activities/{$id}/elegibilidade");
        $participante = $this->respostaJson()['data']['participante'];
        $this->assertFalse($participante['pode']);
        $this->assertSame('fora_faixa_etaria', $participante['codigo']);
        $this->assertSame('de 18 a 35 anos', $participante['faixa']);

        $this->autenticarComo($jovem);
        $this->post("/api/v1/activities/{$id}/inscricoes", ['tipo' => 'participante']);
        $this->assertResponseCode(422);
        $this->assertSame(
            'Não foi possível fazer a inscrição: este evento é para pessoas de 18 a 35 anos, e no dia do evento você terá 16 anos.',
            $this->respostaJson()['error'],
        );

        $this->autenticarComo($this->criarUsuario('Adulto', idade: 20));
        $this->post("/api/v1/activities/{$id}/inscricoes", ['tipo' => 'participante']);
        $this->assertResponseCode(201);
    }

    public function testIdadeContadaNoPrimeiroDiaDoEvento(): void
    {
        // Faz 18 anos daqui a 5 dias; o evento é daqui a 10: já conta como 18.
        $this->criar($this->dadosValidos(['min_age' => 18]));
        $id = $this->respostaJson()['data']['id'];
        $quaseAdulto = $this->criarUsuario('Quase adulto', idade: 18);
        $quaseAdulto->birthday = date('Y-m-d', strtotime('-18 years +5 days'));
        $this->fetchTable('Users')->saveOrFail($quaseAdulto);

        $this->autenticarComo($quaseAdulto);
        $this->post("/api/v1/activities/{$id}/inscricoes", ['tipo' => 'participante']);
        $this->assertResponseCode(201);
    }
}
