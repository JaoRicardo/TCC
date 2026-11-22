<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\PermissoesService;
use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;

/**
 * Tela Equipes de Servos (handoff design_handoff_equipes_servos): quem vê o
 * quê, o banco de servos, mover de setor numa ação só, tornar líder, aplicar o
 * modelo da categoria e os setores do modelo que só entram quando a categoria
 * do acampamento muda.
 */
class EquipesServosTest extends RegrasTestCase
{
    private EntityInterface $atividade;
    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->atividade = $this->criarAcampamento('FAC');
        $this->admin = $this->criarUsuario('Organização', idade: 40, admin: true);
    }

    /** Setor no modelo da categoria (FAC) e, se `ligar`, também no acampamento. */
    private function criarSetor(string $nome, int $vagas, bool $ligar = true, ?int $vagasNoAcampamento = null, string $categoria = 'FAC'): ?EntityInterface
    {
        $setor = $this->salvar('Sectors', ['name' => $nome, 'place' => 'Interno']);
        $cs = $this->salvar('CategoriesSectors', [
            'category_id' => $this->categorias[$categoria],
            'sector_id' => $setor->id,
            'vacancies' => $vagas,
        ]);
        if (!$ligar) return null;

        return $this->salvar('ActivitiesCategoriesSectors', [
            'categories_sectors_id' => $cs->id,
            'activity_id' => $this->atividade->id,
            'actual_vacancies' => $vagasNoAcampamento ?? $vagas,
        ]);
    }

    private function servo(string $nome, array $extra = []): EntityInterface
    {
        $usuario = $this->criarUsuario($nome, idade: 25);
        $this->inscrever($usuario, $this->atividade, 'Servo', $extra);

        return $usuario;
    }

    private function colocar(EntityInterface $setor, EntityInterface $usuario, bool $lider = false): void
    {
        $this->salvar('ActivitiesCategoriesSectorsUsers', [
            'activities_categories_sector_id' => $setor->id,
            'user_id' => $usuario->id,
            'is_coordinator' => $lider,
            'is_indicated' => true,
        ]);
    }

    private function equipe(EntityInterface $quem): array
    {
        $this->autenticarComo($quem);
        $this->get("/api/v1/activities/{$this->atividade->id}/sectors");

        return $this->respostaJson()['data'] ?? [];
    }

    private function base(): string
    {
        return "/api/v1/activities/{$this->atividade->id}";
    }

    public function testServoComumVeSoAPropriaEquipeELiderVeTodas(): void
    {
        $cozinha = $this->criarSetor('Cozinha', 3);
        $musica = $this->criarSetor('Música', 3);
        $lider = $this->servo('Líder da Cozinha');
        $comum = $this->servo('Servo da Música');
        $this->colocar($cozinha, $lider, lider: true);
        $this->colocar($musica, $comum);

        $doComum = $this->equipe($comum);
        $this->assertResponseOk();
        $this->assertSame(['Música'], array_column($doComum['setores'], 'sector_name'));
        $this->assertNull($doComum['banco'], 'O servo comum não vê o banco.');
        $this->assertTrue($doComum['meu_papel']['so_minha_equipe']);

        $doLider = $this->equipe($lider);
        $this->assertSame(['Cozinha', 'Música'], array_column($doLider['setores'], 'sector_name'));
        $this->assertIsArray($doLider['banco']);

        // Quem não está na equipe nem tem a permissão não vê nada.
        $this->equipe($this->servo('De fora'));
        $this->assertResponseCode(403);
    }

    public function testBancoTemSoQuemEstaSemLugarECpfSoParaAOrganizacao(): void
    {
        $cozinha = $this->criarSetor('Cozinha', 3);
        $naEquipe = $this->servo('Na equipe');
        $this->colocar($cozinha, $naEquipe, lider: true);
        $geral = $this->servo('Servo geral');
        $this->salvar('ActivitiesGeneralServants', ['activity_id' => $this->atividade->id, 'user_id' => $geral->id, 'created' => date('Y-m-d H:i:s')]);
        $this->servo('Desistiu', ['is_quitter' => true]);
        $this->servo('Cancelado', ['disqualified_at' => date('Y-m-d H:i:s'), 'disqualification_reason' => 'cancelada_organizacao']);
        $livre = $this->servo('Livre');

        $dados = $this->equipe($this->admin);
        $this->assertSame(['Livre'], array_column($dados['banco'], 'nome'));
        $item = $dados['banco'][0];
        $this->assertSame('insc', $item['situacao']);
        $this->assertSame(25, $item['idade']);
        $this->assertSame('Primavera do Leste', $item['cidade']);
        $this->assertMatchesRegularExpression('/^\*\*\*\.\d{3}\.\d{3}-\*\*$/', $item['cpf']);
        $this->assertSame($livre->id, $item['user_id']);

        // O líder vê o banco sem CPF.
        $this->assertArrayNotHasKey('cpf', $this->equipe($naEquipe)['banco'][0]);
    }

    public function testSuplenteApareceNoBancoComSetorEPosicao(): void
    {
        $cozinha = $this->criarSetor('Cozinha', 1);
        $this->servo('Servo 1');
        $this->servo('Servo 2');
        (new SorteioService())->realizarServos((int)$this->atividade->id);

        $dados = $this->equipe($this->admin);
        $this->assertTrue($dados['sorteio']['feito']);
        $this->assertCount(1, $dados['banco']);
        $this->assertSame('sup', $dados['banco'][0]['situacao']);
        $this->assertSame('Cozinha', $dados['banco'][0]['suplente_setor']);
        $this->assertSame(1, $dados['banco'][0]['suplente_pos']);
        $this->assertCount(1, $dados['setores'][0]['membros']);
        $this->assertTrue($dados['setores'][0]['membros'][0]['sorteado']);
        $this->assertNotNull($cozinha);
    }

    public function testMoverTrocaDeSetorNumaAcaoSoEDeixaDeSerLider(): void
    {
        $cozinha = $this->criarSetor('Cozinha', 3);
        $musica = $this->criarSetor('Música', 3);
        $pessoa = $this->servo('Ana');
        $this->colocar($cozinha, $pessoa, lider: true);

        $this->autenticarComo($this->admin);
        $this->post("{$this->base()}/sectors/{$cozinha->id}/members/{$pessoa->id}/mover", ['para' => $musica->id]);
        $this->assertResponseOk();

        $linhas = $this->fetchTable('ActivitiesCategoriesSectorsUsers')->find()->where(['user_id' => $pessoa->id])->all()->toArray();
        $this->assertCount(1, $linhas);
        $this->assertSame($musica->id, $linhas[0]->activities_categories_sector_id);
        $this->assertFalse((bool)$linhas[0]->is_coordinator);
    }

    public function testLiderNaoMoveNemTiraOutroLider(): void
    {
        $cozinha = $this->criarSetor('Cozinha', 3);
        $musica = $this->criarSetor('Música', 3);
        $lider = $this->servo('Líder');
        $outroLider = $this->servo('Outro líder');
        $servo = $this->servo('Servo');
        $this->colocar($cozinha, $lider, lider: true);
        $this->colocar($cozinha, $outroLider, lider: true);
        $this->colocar($cozinha, $servo);

        $this->autenticarComo($lider);
        $this->post("{$this->base()}/sectors/{$cozinha->id}/members/{$servo->id}/mover", ['para' => $musica->id]);
        $this->assertResponseCode(403);

        $this->autenticarComo($lider);
        $this->delete("{$this->base()}/sectors/{$cozinha->id}/members/{$outroLider->id}");
        $this->assertResponseCode(403);

        $this->autenticarComo($lider);
        $this->delete("{$this->base()}/sectors/{$cozinha->id}/members/{$servo->id}");
        $this->assertResponseOk();
    }

    public function testTornarEDeixarDeSerLider(): void
    {
        $cozinha = $this->criarSetor('Cozinha', 3);
        $pessoa = $this->servo('Bruno');
        $this->colocar($cozinha, $pessoa);

        $this->autenticarComo($this->admin);
        $this->patch("{$this->base()}/sectors/{$cozinha->id}/members/{$pessoa->id}", ['is_coordinator' => true]);
        $this->assertResponseOk();
        $this->assertSame(['Bruno'], array_column($this->equipe($this->admin)['setores'][0]['coordenadores'], 'nome'));

        $this->autenticarComo($this->admin);
        $this->patch("{$this->base()}/sectors/{$cozinha->id}/members/{$pessoa->id}", ['is_coordinator' => false]);
        $this->assertSame([], $this->equipe($this->admin)['setores'][0]['coordenadores']);
    }

    public function testQuemDesistiuNaoEntraNaEquipe(): void
    {
        $cozinha = $this->criarSetor('Cozinha', 3);
        $desistiu = $this->servo('Desistiu', ['is_quitter' => true]);

        $this->autenticarComo($this->admin);
        $this->post("{$this->base()}/sectors/{$cozinha->id}/members", ['user_id' => $desistiu->id]);
        $this->assertResponseCode(422);
    }

    public function testAplicarOModeloVoltaAsVagasELigaOQueFalta(): void
    {
        $cozinha = $this->criarSetor('Cozinha', 8, vagasNoAcampamento: 10);
        $this->criarSetor('Secretaria', 3, ligar: false);
        $pessoa = $this->servo('Fica');
        $this->colocar($cozinha, $pessoa);

        $dados = $this->equipe($this->admin);
        $this->assertSame(8, $dados['setores'][0]['default_vacancies']);
        $this->assertSame(['Secretaria'], array_column(array_filter($dados['setores'], fn($s) => !$s['linked']), 'sector_name'));

        $this->autenticarComo($this->admin);
        $this->post("{$this->base()}/sectors/aplicar-modelo");
        $this->assertResponseOk();
        $this->assertSame(['Secretaria'], $this->respostaJson()['data']['entraram']);

        $dados = $this->equipe($this->admin);
        $ligados = array_values(array_filter($dados['setores'], fn($s) => $s['linked']));
        $this->assertSame(['Cozinha', 'Secretaria'], array_column($ligados, 'sector_name'));
        $this->assertSame(8, $ligados[0]['actual_vacancies']);
        $this->assertSame(['Fica'], array_column($ligados[0]['membros'], 'nome'), 'Ninguém sai da equipe.');
    }

    public function testAplicarOModeloSoAntesDoSorteioDeServos(): void
    {
        $this->criarSetor('Cozinha', 1);
        $this->servo('Servo 1');
        (new SorteioService())->realizarServos((int)$this->atividade->id);

        $this->autenticarComo($this->admin);
        $this->post("{$this->base()}/sectors/aplicar-modelo");
        $this->assertResponseCode(409);
        $this->assertSame('sorteio_feito', $this->respostaJson()['codigo']);
    }

    public function testSoAOrganizacaoMudaVagasEAplicaOModelo(): void
    {
        $cozinha = $this->criarSetor('Cozinha', 3);
        $geral = $this->servo('Geral');
        $this->salvar('ActivitiesGeneralServants', ['activity_id' => $this->atividade->id, 'user_id' => $geral->id, 'created' => date('Y-m-d H:i:s')]);

        $this->autenticarComo($geral);
        $this->patch("{$this->base()}/sectors/{$cozinha->id}", ['actual_vacancies' => 9]);
        $this->assertResponseCode(403);

        $this->autenticarComo($geral);
        $this->post("{$this->base()}/sectors/aplicar-modelo");
        $this->assertResponseCode(403);
    }

    public function testSetorTiradoNaoVoltaAoEditarSoQuandoACategoriaMuda(): void
    {
        $organizacao = $this->criarUsuario('Org', idade: 40);
        (new PermissoesService())->definir((int)$organizacao->id, ['acampamentos', 'equipes']);
        $cozinha = $this->criarSetor('Cozinha', 3);
        $this->criarSetor('Música', 2, ligar: false, categoria: 'Juvenil');

        $this->autenticarComo($organizacao);
        $this->delete("{$this->base()}/sectors/{$cozinha->id}");
        $this->assertResponseOk();

        $editar = function (string $categoria) use ($organizacao) {
            $dia = fn(int $d, string $h = '00:00') => date('Y-m-d', strtotime("{$d} days")) . "T{$h}";
            $this->autenticarComo($organizacao);
            $this->post("{$this->base()}", ['_method' => 'PATCH',
                'activity' => [
                    'name' => 'Acampamento', 'place' => 'Sítio', 'category_id' => $this->categorias[$categoria],
                    'start_date' => $dia(30), 'duration_days' => 3,
                    'participant_registration_start_date' => $dia(0), 'participant_registration_end_date' => $dia(10, '23:59'),
                    'participant_fee' => 0, 'servant_fee' => 0,
                ],
                'camping' => [
                    'raffle_man_vacancies' => 1, 'raffle_woman_vacancies' => 1, 'raffle_couple_vacancies' => 0,
                    'raffle_camper_date' => $dia(11), 'camper_form_deadline' => $dia(20, '23:59'),
                    'raffle_servant_subscription_start_date' => $dia(0), 'raffle_servant_subscription_end_date' => $dia(5, '23:59'),
                    'raffle_servant_date' => $dia(6), 'servant_form_deadline' => $dia(15, '23:59'),
                ],
            ]);
            $this->assertResponseOk();
        };
        $ligados = fn() => $this->fetchTable('ActivitiesCategoriesSectors')->find()
            ->where(['activity_id' => $this->atividade->id, 'deleted IS' => null])->count();

        $editar('FAC');
        $this->assertSame(0, $ligados(), 'A Cozinha foi tirada e não volta só por salvar.');

        $editar('Juvenil');
        $this->assertSame(1, $ligados(), 'Mudou a categoria: entram os setores do modelo do Juvenil.');
    }

    public function testMinhaEquipeNaoContaSuplenteETrazOPapel(): void
    {
        $cozinha = $this->criarSetor('Cozinha', 1);
        $this->servo('Servo 1');
        $this->servo('Servo 2');
        (new SorteioService())->realizarServos((int)$this->atividade->id);
        $linhas = $this->fetchTable('ActivitiesCategoriesSectorsUsers')->find()->where(['activities_categories_sector_id' => $cozinha->id])->all();
        $titular = $suplente = null;
        foreach ($linhas as $l) {
            if ($l->is_substitute) $suplente = $this->fetchTable('Users')->get($l->user_id);
            else $titular = $this->fetchTable('Users')->get($l->user_id);
        }

        $this->autenticarComo($suplente);
        $this->get('/api/v1/me/equipes');
        $this->assertSame([], $this->respostaJson()['data']);

        $this->autenticarComo($titular);
        $this->get('/api/v1/me/equipes');
        $item = $this->respostaJson()['data'][0];
        $this->assertSame('servo', $item['papel']);
        $this->assertSame('Cozinha', $item['setor']);
        $this->assertSame(['setores' => 1, 'vagas' => 1, 'preenchidas' => 1, 'meu_setor' => 1], $item['resumo']);
    }

    public function testNovoSetorJaComVagasPorCategoriaENomeRepetidoRecusado(): void
    {
        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/sectors', [
            'name' => 'Lavanderia', 'place' => 'Externo',
            'vagas' => [$this->categorias['FAC'] => 4, $this->categorias['Juvenil'] => ''],
        ]);
        $this->assertResponseCode(201);
        $id = $this->respostaJson()['data']['id'];
        $vinculos = $this->fetchTable('CategoriesSectors')->find()->where(['sector_id' => $id])->all()->toArray();
        $this->assertCount(1, $vinculos);
        $this->assertSame(4, $vinculos[0]->vacancies);

        $this->autenticarComo($this->admin);
        $this->post('/api/v1/admin/sectors', ['name' => ' lavanderia ', 'place' => 'Interno']);
        $this->assertResponseCode(422);
        $this->assertSame('Já existe um setor com esse nome.', $this->respostaJson()['error']);
    }
}
