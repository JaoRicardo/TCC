<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PrazoFormularioService;
use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;

/**
 * Criar / Editar Acampamento (handoff design_handoff_painel_acampamento):
 * datas sem hora e em ordem (inscrição → sorteio previsto → prazo → primeiro
 * dia), um prazo só por trilha (formulário e pagamento), vagas do sorteio
 * (no Casais só casais; casado fora do Casais concorre como homem ou mulher),
 * sorteio só manual, remover edital e termo, e os dados de apoio da tela.
 */
class CadastroAcampamentoTest extends RegrasTestCase
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

    /** O que a tela manda ao criar um acampamento FAC válido (primeiro dia daqui a 30 dias). */
    private function dadosValidos(string $categoria = 'FAC'): array
    {
        return [
            'activity' => [
                'name' => 'Acampamento FAC 2027', 'place' => 'Sítio Recanto da Paz',
                'category_id' => $this->categorias[$categoria], 'description' => 'Chegada às 17h da sexta.',
                'start_date' => $this->dia(30), 'duration_days' => 3,
                'participant_registration_start_date' => $this->dia(0),
                'participant_registration_end_date' => $this->dia(10, '23:59'),
                'participant_fee' => 120, 'servant_fee' => 0,
            ],
            'camping' => [
                'raffle_man_vacancies' => 20, 'raffle_woman_vacancies' => 20, 'raffle_couple_vacancies' => 5,
                'raffle_camper_date' => $this->dia(11), 'camper_form_deadline' => $this->dia(20, '23:59'),
                'raffle_servant_subscription_start_date' => $this->dia(0),
                'raffle_servant_subscription_end_date' => $this->dia(5, '23:59'),
                'raffle_servant_date' => $this->dia(6), 'servant_form_deadline' => $this->dia(15, '23:59'),
            ],
        ];
    }

    private function criar(array $dados): void
    {
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/activities', ['type' => 'Camping'] + $dados);
    }

    private function editar(int $id, array $dados): void
    {
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/activities/{$id}", ['_method' => 'PATCH'] + $dados);
    }

    private function erros(): array
    {
        return array_map(fn($e) => reset($e), $this->respostaJson()['errors'] ?? []);
    }

    public function testCriaComPrazoUnicoVagasDoSorteioESemLink(): void
    {
        $this->criar($this->dadosValidos());

        $this->assertResponseCode(201);
        $id = $this->respostaJson()['data']['id'];
        $atividade = $this->fetchTable('Activities')->get($id);
        $camping = $this->fetchTable('Campings')->get($atividade->camping_id);

        // O prazo do pagamento é o mesmo do formulário, em cada trilha.
        $this->assertSame($camping->camper_form_deadline->format('Y-m-d H:i'), $atividade->participant_payment_date->format('Y-m-d H:i'));
        $this->assertSame($camping->servant_form_deadline->format('Y-m-d H:i'), $atividade->servant_payment_date->format('Y-m-d H:i'));
        // Fora do Casais não há vaga de casal; o total é a soma do sorteio.
        $this->assertSame(0, (int)$camping->raffle_couple_vacancies);
        $this->assertSame(40, (int)$atividade->total_vacancies);
        $this->assertSame(20, (int)$camping->planned_man_vacancies);
        $this->assertSame('Chegada às 17h da sexta.', $atividade->description);
        $this->assertSame((int)date('Y', strtotime('+30 days')), (int)$atividade->year);
    }

    public function testNoCasaisSoHaVagasDeCasaisEOTotalContaODobro(): void
    {
        $dados = $this->dadosValidos('Casais');
        $dados['camping']['raffle_couple_vacancies'] = 12;
        $this->criar($dados);

        $this->assertResponseCode(201);
        $atividade = $this->fetchTable('Activities')->get($this->respostaJson()['data']['id']);
        $camping = $this->fetchTable('Campings')->get($atividade->camping_id);
        $this->assertSame(0, (int)$camping->raffle_man_vacancies);
        $this->assertSame(12, (int)$camping->raffle_couple_vacancies);
        $this->assertSame(24, (int)$atividade->total_vacancies);

        $dados['camping']['raffle_couple_vacancies'] = 0;
        $this->criar($dados);
        $this->assertSame('Informe quantos casais serão sorteados.', $this->erros()['raffle_couple_vacancies']);
    }

    public function testDatasPrecisamEstarEmOrdem(): void
    {
        $dados = $this->dadosValidos();
        // Sorteio no próprio dia do fechamento; prazo antes do sorteio dos servos.
        $dados['camping']['raffle_camper_date'] = $this->dia(10);
        $dados['camping']['servant_form_deadline'] = $this->dia(5, '23:59');
        $this->criar($dados);
        $this->assertResponseCode(422);
        $amanhaDoFechamento = date('d/m/Y', strtotime('11 days'));
        $this->assertSame("O sorteio precisa ser depois do fechamento das inscrições: a partir de {$amanhaDoFechamento}.", $this->erros()['raffle_camper_date']);
        $this->assertStringStartsWith('O prazo não pode ser antes do sorteio previsto', $this->erros()['servant_form_deadline']);

        $dados = $this->dadosValidos();
        $dados['camping']['raffle_servant_date'] = $this->dia(30);
        $dados['camping']['camper_form_deadline'] = $this->dia(31, '23:59');
        $dados['camping']['raffle_man_vacancies'] = 0;
        $dados['camping']['raffle_woman_vacancies'] = 0;
        $this->criar($dados);
        $this->assertStringStartsWith('O sorteio precisa ser antes do primeiro dia', $this->erros()['raffle_servant_date']);
        $this->assertStringStartsWith('O prazo precisa ser até o primeiro dia', $this->erros()['camper_form_deadline']);
        $this->assertSame('Informe pelo menos uma vaga.', $this->erros()['raffle_man_vacancies']);
    }

    public function testCamposObrigatoriosEOsAntigosNaoSaoMaisPedidos(): void
    {
        $dados = $this->dadosValidos();
        unset($dados['activity']['category_id']);
        $dados['camping']['camper_form_deadline'] = '';
        $dados['activity']['participant_fee'] = 0;
        $dados['camping']['servant_form_deadline'] = '';
        $dados['activity']['servant_fee'] = 50;
        $this->criar($dados);

        $this->assertResponseCode(422);
        $this->assertSame('Escolha a categoria.', $this->erros()['category_id']);
        $this->assertSame('Informe o prazo do formulário.', $this->erros()['camper_form_deadline']);
        $this->assertSame('Informe o prazo do formulário e do pagamento.', $this->erros()['servant_form_deadline']);
        // Ano, idade, matrícula, confirmação e link não aparecem entre os erros.
        $this->assertSame([], array_intersect(array_keys($this->erros()), [
            'year', 'min_age', 'max_age', 'camper_registration_start_date', 'servant_registration_start_date',
        ]));
    }

    public function testEdicaoRemoveEditalETermoEMostraOSorteioFeito(): void
    {
        $fac = $this->criarAcampamento('FAC', ['notice' => 'campings/notices/x.pdf', 'term' => 'campings/terms/y.pdf', 'raffle_man_vacancies' => 1]);
        $this->inscrever($this->criarUsuario('Pedro'), $fac);
        (new SorteioService())->realizarCampistas((int)$fac->id, 1, 0, 0);

        $this->autenticarComo($this->admin);
        $this->get("/api/v1/activities/{$fac->id}/edit");
        $resumo = $this->respostaJson()['data']['resumo'];
        $this->assertSame(1, $resumo['inscricoesCampistas']);
        $this->assertSame(['feito' => true, 'em' => date('Y-m-d')], $resumo['sorteioCampistas']);
        $this->assertSame(['feito' => false, 'em' => null], $resumo['sorteioServos']);

        $dados = $this->dadosValidos();
        $dados['camping'] += ['remove_notice' => '1', 'remove_term' => '1'];
        $this->editar((int)$fac->id, $dados);
        $this->assertResponseOk();
        $camping = $this->fetchTable('Campings')->get($fac->camping_id);
        $this->assertSame('#', $camping->notice);
        $this->assertSame('#', $camping->term);

        // Zerar o sorteio apaga a data.
        (new SorteioService())->resetarCampistas((int)$fac->id);
        $this->assertNull((new SorteioService())->sorteioFeitoEm((int)$fac->id, 'Campista'));
    }

    public function testSorteioNaoRodaSozinhoNaDataPrevista(): void
    {
        $fac = $this->criarAcampamento('FAC', ['raffle_camper_date' => $this->ontem(), 'raffle_man_vacancies' => 1]);
        $inscricao = $this->inscrever($this->criarUsuario('Pedro'), $fac);

        $this->get('/api/v1/activities');
        $this->assertResponseOk();

        $this->assertNull($this->recarregar($inscricao)->selection_method_id);
        $this->assertNull((new SorteioService())->sorteioFeitoEm((int)$fac->id, 'Campista'));
        $publico = array_values(array_filter($this->respostaJson()['data'] ?? [], fn($a) => $a['id'] === $fac->id));
        if ($publico) $this->assertFalse($publico[0]['sorteioCampistasRealizado']);
    }

    public function testCasadoForaDoCasaisConcorreComoHomemOuMulher(): void
    {
        $senior = $this->criarAcampamento('Sênior');
        $ele = $this->inscrever($this->criarUsuario('Marido', idade: 35, estadoCivil: 'Casado'), $senior);
        $ela = $this->inscrever($this->criarUsuario('Esposa', idade: 33, sexo: 'F', estadoCivil: 'Casado'), $senior, 'Campista', ['spouse_id' => $ele->user_id]);

        // Uma vaga de homem e nenhuma de casal: o marido é sorteado sozinho.
        (new SorteioService())->realizarCampistas((int)$senior->id, 1, 0, 0);
        $this->assertSame($this->metodos['Sorteio'], $this->recarregar($ele)->selection_method_id);
        $this->assertNull($this->recarregar($ela)->selection_method_id);
        $this->assertSame(0, (new PrazoFormularioService())->vagasAbertasCampistas((int)$senior->id)['Casado']);
    }

    public function testApoioDizSeOFormularioExisteEMostraOsSetores(): void
    {
        $this->criarPergunta('FAC', 'Campista');
        $setor = $this->salvar('Sectors', ['name' => 'Cozinha', 'place' => 'Interno']);
        $this->salvar('CategoriesSectors', ['category_id' => $this->categorias['FAC'], 'sector_id' => $setor->id, 'vacancies' => 6]);

        $this->autenticarComo($this->admin);
        $this->get('/api/v1/admin/acampamentos/apoio');
        $this->assertResponseOk();
        $fac = array_values(array_filter($this->respostaJson()['data']['categorias'], fn($c) => $c['nome'] === 'FAC'))[0];
        $this->assertTrue($fac['formularioCampista']);
        $this->assertFalse($fac['formularioServo']);
        $this->assertSame([['nome' => 'Cozinha', 'vagas' => 6]], $fac['setores']);

        $semPermissao = $this->criarUsuario('Sem acesso', idade: 30);
        $this->autenticarComo($semPermissao);
        $this->get('/api/v1/admin/acampamentos/apoio');
        $this->assertResponseCode(403);
    }
}
