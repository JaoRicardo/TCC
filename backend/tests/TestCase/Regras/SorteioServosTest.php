<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;

/**
 * Sorteio de servos: a organização monta as equipes à mão (líder, servo geral ou
 * admin colocam as pessoas), e o sorteio só completa as vagas que ainda faltam
 * em cada setor — o mesmo número que a tela da equipe mostra como "faltam N vagas".
 *
 * Quem já foi colocado à mão, quem é servo geral e quem foi indicado
 * (Base/Forania/Conselho) não entra no sorteio e nunca é mexido por ele.
 */
class SorteioServosTest extends RegrasTestCase
{
    private EntityInterface $atividade;
    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->atividade = $this->criarAcampamento('FAC');
        $this->admin = $this->criarUsuario('Admin', idade: 40, admin: true);
    }

    /** Setor ligado ao acampamento, com o número de vagas da equipe. */
    private function criarSetor(string $nome, int $vagas): EntityInterface
    {
        $setor = $this->salvar('Sectors', ['name' => $nome, 'place' => 'Interno']);
        $categoriaSetor = $this->salvar('CategoriesSectors', [
            'category_id' => $this->atividade->category_id,
            'sector_id' => $setor->id,
            'vacancies' => $vagas,
        ]);

        return $this->salvar('ActivitiesCategoriesSectors', [
            'categories_sectors_id' => $categoriaSetor->id,
            'activity_id' => $this->atividade->id,
            'actual_vacancies' => $vagas,
        ]);
    }

    private function servoInscrito(string $nome, array $extra = []): EntityInterface
    {
        $usuario = $this->criarUsuario($nome, idade: 25);
        $this->inscrever($usuario, $this->atividade, 'Servo', $extra);

        return $usuario;
    }

    /** Coloca na equipe pela API, como a tela "Minha Equipe" faz. */
    private function colocarNaEquipe(EntityInterface $setor, EntityInterface $usuario, bool $lider = false): void
    {
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/activities/{$this->atividade->id}/sectors/{$setor->id}/members", [
            'user_id' => $usuario->id,
            'is_coordinator' => $lider ? '1' : '0',
        ]);
        $this->assertResponseSuccess();
    }

    /** O que a tela da equipe mostra para cada setor (GET /activities/{id}/sectors). */
    private function telaDaEquipe(): array
    {
        $this->autenticarComo($this->admin);
        $this->get("/api/v1/activities/{$this->atividade->id}/sectors");
        $this->assertResponseOk();

        $porSetor = [];
        foreach ($this->respostaJson()['data']['setores'] as $s) {
            $porSetor[$s['id']] = $s;
        }

        return $porSetor;
    }

    /** Linhas que o sorteio criou num setor: [titulares, suplentes]. */
    private function resultadoDoSorteio(EntityInterface $setor): array
    {
        $linhas = $this->fetchTable('ActivitiesCategoriesSectorsUsers')->find()
            ->where(['activities_categories_sector_id' => $setor->id, 'is_indicated' => false])
            ->all()
            ->toArray();
        $titulares = array_filter($linhas, fn($l) => !$l->is_substitute);
        $suplentes = array_filter($linhas, fn($l) => (bool)$l->is_substitute);

        return [count($titulares), count($suplentes)];
    }

    public function testSorteioPreencheExatamenteAsVagasQueATelaMostraComoFaltando(): void
    {
        // Louvor: 5 vagas, já tem 1 líder e 1 membro colocados à mão → faltam 3.
        // Recepção: 2 vagas, ninguém colocado → faltam 2.
        $louvor = $this->criarSetor('Louvor', 5);
        $recepcao = $this->criarSetor('Recepção', 2);
        $this->colocarNaEquipe($louvor, $this->servoInscrito('Líder do louvor'), lider: true);
        $this->colocarNaEquipe($louvor, $this->servoInscrito('Membro escolhido'));
        for ($i = 1; $i <= 20; $i++) {
            $this->servoInscrito("Servo {$i}");
        }

        $antes = $this->telaDaEquipe();
        $this->assertSame(3, $antes[$louvor->id]['vagas_faltando']);
        $this->assertSame(2, $antes[$recepcao->id]['vagas_faltando']);

        (new SorteioService())->realizarServos((int)$this->atividade->id);

        // Mesmo número de titulares que faltava, e o mesmo número de suplentes.
        $this->assertSame([3, 3], $this->resultadoDoSorteio($louvor));
        $this->assertSame([2, 2], $this->resultadoDoSorteio($recepcao));

        // E a tela passa a mostrar as equipes completas, sem gente a mais.
        $depois = $this->telaDaEquipe();
        $this->assertSame(0, $depois[$louvor->id]['vagas_faltando']);
        $this->assertSame(0, $depois[$recepcao->id]['vagas_faltando']);
        $this->assertCount(5, array_merge($depois[$louvor->id]['coordenadores'], $depois[$louvor->id]['membros']));
        $this->assertCount(2, array_merge($depois[$recepcao->id]['coordenadores'], $depois[$recepcao->id]['membros']));
    }

    public function testQuemJaTemLugarNaoEntraNoSorteioEContinuaOndeEstava(): void
    {
        $louvor = $this->criarSetor('Louvor', 4);
        $lider = $this->servoInscrito('Líder');
        $membro = $this->servoInscrito('Membro escolhido');
        $indicado = $this->servoInscrito('Indicado pela Base', ['selection_method_id' => $this->metodos['Base']]);
        $geral = $this->servoInscrito('Servo geral');
        $this->colocarNaEquipe($louvor, $lider, lider: true);
        $this->colocarNaEquipe($louvor, $membro);
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/activities/{$this->atividade->id}/general-servants", ['user_id' => $geral->id]);
        $this->assertResponseSuccess();
        for ($i = 1; $i <= 10; $i++) {
            $this->servoInscrito("Servo {$i}");
        }

        $sorteio = new SorteioService();
        $equipe = $this->fetchTable('ActivitiesCategoriesSectorsUsers');
        for ($rodada = 1; $rodada <= 10; $rodada++) {
            $sorteio->realizarServos((int)$this->atividade->id);

            foreach ([$indicado, $geral] as $foraDoSorteio) {
                $this->assertSame(0, $equipe->find()->where(['user_id' => $foraDoSorteio->id])->count(), "Rodada {$rodada}: {$foraDoSorteio->name} foi sorteado.");
            }
            foreach ([$lider, $membro] as $colocado) {
                $linhas = $equipe->find()->where(['user_id' => $colocado->id])->all()->toArray();
                $this->assertCount(1, $linhas, "Rodada {$rodada}: {$colocado->name} foi sorteado de novo ou saiu da equipe.");
                $this->assertTrue($linhas[0]->is_indicated, "Rodada {$rodada}: a colocação manual de {$colocado->name} foi trocada pelo sorteio.");
            }
            $this->assertSame([2, 2], $this->resultadoDoSorteio($louvor), "Rodada {$rodada}: o sorteio refeito não substituiu o resultado anterior.");
        }

        $this->assertSame($this->metodos['Base'], $this->fetchTable('PreRegistrations')->find()
            ->where(['user_id' => $indicado->id])->firstOrFail()->selection_method_id, 'O sorteio apagou a indicação.');
    }

    public function testComPoucosInscritosOSorteioPreencheOQueDerSemPassarDasVagas(): void
    {
        $louvor = $this->criarSetor('Louvor', 5);
        $recepcao = $this->criarSetor('Recepção', 5);
        for ($i = 1; $i <= 3; $i++) {
            $this->servoInscrito("Servo {$i}");
        }

        (new SorteioService())->realizarServos((int)$this->atividade->id);

        [$titularesLouvor] = $this->resultadoDoSorteio($louvor);
        [$titularesRecepcao] = $this->resultadoDoSorteio($recepcao);
        $this->assertSame(3, $titularesLouvor + $titularesRecepcao, 'Os 3 inscritos deveriam ser sorteados.');
        $this->assertSame(3, $this->fetchTable('ActivitiesCategoriesSectorsUsers')->find()->count(), 'Ninguém pode cair em duas equipes.');
    }

    public function testQuemJaEstaNumSetorNaoPodeSerColocadoEmOutro(): void
    {
        $louvor = $this->criarSetor('Louvor', 5);
        $recepcao = $this->criarSetor('Recepção', 5);
        $servo = $this->servoInscrito('Servo');
        $this->colocarNaEquipe($louvor, $servo);

        foreach ([false, true] as $comoLider) {
            $this->autenticarComo($this->admin);
            $this->post("/api/v1/activities/{$this->atividade->id}/sectors/{$recepcao->id}/members", [
                'user_id' => $servo->id,
                'is_coordinator' => $comoLider ? '1' : '0',
            ]);
            $this->assertResponseCode(409);
        }

        $this->assertSame(1, $this->fetchTable('ActivitiesCategoriesSectorsUsers')->find()
            ->where(['user_id' => $servo->id])->count());
    }

    public function testSuplenteColocadoEmOutroSetorSaiDaFilaDeSuplentes(): void
    {
        // Louvor: 1 vaga e 2 inscritos → 1 titular e 1 suplente.
        $louvor = $this->criarSetor('Louvor', 1);
        $this->servoInscrito('Servo 1');
        $this->servoInscrito('Servo 2');
        (new SorteioService())->realizarServos((int)$this->atividade->id);

        $equipe = $this->fetchTable('ActivitiesCategoriesSectorsUsers');
        $suplente = $this->fetchTable('Users')->get(
            $equipe->find()->where(['activities_categories_sector_id' => $louvor->id, 'is_substitute' => true])->firstOrFail()->user_id
        );

        $recepcao = $this->criarSetor('Recepção', 3);
        $this->colocarNaEquipe($recepcao, $suplente);

        $linhas = $equipe->find()->where(['user_id' => $suplente->id])->all()->toArray();
        $this->assertCount(1, $linhas, 'Ficou na Recepção e continuou na fila de suplentes do Louvor.');
        $this->assertSame($recepcao->id, $linhas[0]->activities_categories_sector_id);
        $this->assertNull($this->fetchTable('PreRegistrations')->find()
            ->where(['user_id' => $suplente->id])->firstOrFail()->substitute_position, 'A inscrição continua marcada como suplente.');
    }

    public function testSuplenteQueViraServoGeralSaiDaFilaDeSuplentes(): void
    {
        $louvor = $this->criarSetor('Louvor', 1);
        $this->servoInscrito('Servo 1');
        $this->servoInscrito('Servo 2');
        (new SorteioService())->realizarServos((int)$this->atividade->id);

        $equipe = $this->fetchTable('ActivitiesCategoriesSectorsUsers');
        $suplenteId = $equipe->find()->where(['activities_categories_sector_id' => $louvor->id, 'is_substitute' => true])->firstOrFail()->user_id;

        $this->autenticarComo($this->admin);
        $this->post("/api/v1/activities/{$this->atividade->id}/general-servants", ['user_id' => $suplenteId]);
        $this->assertResponseSuccess();

        $this->assertSame(0, $equipe->find()->where(['user_id' => $suplenteId])->count(), 'Virou servo geral e continuou suplente do Louvor.');
    }
}
