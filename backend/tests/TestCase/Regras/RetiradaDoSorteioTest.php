<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\SorteioService;
use Cake\Datasource\EntityInterface;

/**
 * "Retirado do sorteio": ao corrigir na ficha a data de nascimento, o sexo ou
 * a cidade, as inscrições de acampamento que ainda aguardam sorteio e que, com
 * os dados novos, não podem mais concorrer saem do sorteio. A prévia (janela
 * "Conferir antes de salvar") mostra exatamente o que o salvar vai fazer.
 *
 * - nascimento: só sai se a idade nova não é mais da categoria do acampamento;
 * - sexo: continua pelas vagas do outro sexo; só sai se não há vaga de sorteio para ele;
 * - cidade: saiu de Primavera do Leste/Poxoréu, sai de todas (campista e servo).
 */
class RetiradaDoSorteioTest extends RegrasTestCase
{
    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('Antônio Admin', idade: 50, admin: true);
    }

    /** A ficha atual da pessoa, com o que o teste trocar (como a tela manda). */
    private function dados(EntityInterface $pessoa, array $troca = []): array
    {
        $this->autenticarComo($this->admin);
        $this->get("/api/v1/admin/users/{$pessoa->id}");
        $atual = $this->respostaJson()['data'];

        return array_replace_recursive(array_intersect_key($atual, array_flip([
            'versao', 'name', 'cpf', 'birthday', 'sex', 'marital_status_id', 'phone', 'email', 'address',
        ])), $troca);
    }

    private function previa(EntityInterface $pessoa, array $troca): array
    {
        $dados = $this->dados($pessoa, $troca);
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/users/{$pessoa->id}/previa", $dados);
        $this->assertResponseOk();

        return $this->respostaJson()['data'];
    }

    private function salvarFicha(EntityInterface $pessoa, array $troca): array
    {
        $dados = $this->dados($pessoa, $troca);
        $this->autenticarComo($this->admin);
        $this->patch("/api/v1/admin/users/{$pessoa->id}", $dados);
        $this->assertResponseOk();

        return $this->respostaJson();
    }

    /** Como jaFoiCampista(), mas num acampamento que já terminou (não está em andamento). */
    private function foiCampistaNoPassado(EntityInterface $pessoa, string $categoria): void
    {
        $this->inscrever($pessoa, $this->criarAcampamento($categoria, [], ['start_date' => '2024-01-10 08:00:00', 'year' => 2024]));
    }

    private function nascidoHa(int $anos): string
    {
        return (new \DateTime('today'))->modify("-{$anos} years -10 days")->format('Y-m-d');
    }

    private function situacao(EntityInterface $pessoa, EntityInterface $inscricao): string
    {
        $this->autenticarComo($this->admin);
        $this->get("/api/v1/admin/users/{$pessoa->id}/inscricoes");
        foreach ($this->respostaJson()['data'] as $linha) {
            if ($linha['id'] === $inscricao->id) return $linha['status'];
        }
        $this->fail('Inscrição não apareceu na ficha.');
    }

    public function testNascimentoSoTiraQuemSaiDaIdadeDaCategoria(): void
    {
        $ana = $this->criarUsuario('Ana Souza', idade: 20, sexo: 'F');
        $juvenil = $this->inscrever($ana, $this->criarAcampamento('Juvenil'));
        $this->foiCampistaNoPassado($ana, 'FAC');
        $servo = $this->inscrever($ana, $this->criarAcampamento('FAC'), 'Servo');

        // 20 → 22: continua Juvenil, nada muda.
        $previa = $this->previa($ana, ['birthday' => $this->nascidoHa(22)]);
        $this->assertSame([], $previa['afetadas']);
        $this->salvarFicha($ana, ['birthday' => $this->nascidoHa(22)]);
        $this->assertSame('Inscrito', $this->situacao($ana, $juvenil));

        // 22 → 26: passou da idade do Juvenil. Servo não depende da idade.
        $previa = $this->previa($ana, ['birthday' => $this->nascidoHa(26)]);
        $this->assertSame([$juvenil->id], array_column($previa['afetadas'], 'id'));
        $this->assertSame('idade', $previa['afetadas'][0]['motivo']);
        $this->assertNull($this->recarregar($juvenil)->removed_from_raffle_at, 'A prévia não pode gravar nada.');

        $resposta = $this->salvarFicha($ana, ['birthday' => $this->nascidoHa(26)]);
        $this->assertSame(1, $resposta['retiradas']);
        $this->assertSame('Retirado do sorteio', $this->situacao($ana, $juvenil));
        $this->assertSame('Inscrito', $this->situacao($ana, $servo));

        // A própria pessoa vê a situação nova.
        $this->autenticarComo($ana);
        $this->get('/api/v1/me/inscricoes');
        $minhas = array_column($this->respostaJson()['data'] ?? $this->respostaJson(), 'status', 'id');
        $this->assertSame('Retirado do sorteio', $minhas[$juvenil->id]);
    }

    public function testSexoContinuaPelasVagasDoOutroSexoSeElasExistem(): void
    {
        $ana = $this->criarUsuario('Ana Souza', idade: 20, sexo: 'F');
        $comVagaDeHomem = $this->inscrever($ana, $this->criarAcampamento('Juvenil', ['raffle_man_vacancies' => 5, 'raffle_woman_vacancies' => 5]));
        $soMulheres = $this->inscrever($ana, $this->criarAcampamento('Juvenil', ['raffle_man_vacancies' => 0, 'raffle_woman_vacancies' => 5]));

        $previa = $this->previa($ana, ['sex' => 'M']);
        $this->assertSame([$soMulheres->id], array_column($previa['afetadas'], 'id'));
        $this->assertSame('sexo', $previa['afetadas'][0]['motivo']);

        $this->salvarFicha($ana, ['sex' => 'M']);
        $this->assertSame('Inscrito', $this->situacao($ana, $comVagaDeHomem));
        $this->assertSame('Retirado do sorteio', $this->situacao($ana, $soMulheres));

        // Casado(a) concorre pelas vagas de casal: o sexo não muda nada.
        $bia = $this->criarUsuario('Bia Casada', idade: 30, sexo: 'F', estadoCivil: 'Casado');
        $this->inscrever($bia, $this->criarAcampamento('Casais', ['raffle_couple_vacancies' => 3]));
        $this->assertSame([], $this->previa($bia, ['sex' => 'M'])['afetadas']);
    }

    public function testCidadeForaDaAreaTiraSoQuemAindaAguardaSorteio(): void
    {
        $ana = $this->criarUsuario('Ana Souza', idade: 20, sexo: 'F');
        $aguarda = $this->inscrever($ana, $this->criarAcampamento('Juvenil'));
        $this->foiCampistaNoPassado($ana, 'FAC');
        $servo = $this->inscrever($ana, $this->criarAcampamento('FAC'), 'Servo');
        $sorteada = $this->inscrever($ana, $this->criarAcampamento('Sênior'), 'Campista', ['selection_method_id' => $this->metodos['Sorteio']]);
        $suplente = $this->inscrever($ana, $this->criarAcampamento('Juvenil'), 'Campista', ['substitute_position' => 1]);
        $passado = $this->inscrever($ana, $this->criarAcampamento('Juvenil', [], ['start_date' => '2025-01-10 08:00:00', 'year' => 2025]));
        $evento = $this->inscrever($ana, $this->salvar('Activities', [
            'name' => 'Retiro', 'image' => '', 'place' => 'Chácara', 'year' => 2027,
            'start_date' => date('Y-m-d 08:00:00', strtotime('+30 days')), 'duration_days' => 2,
            'total_vacancies' => 50, 'participant_fee' => 0, 'servant_fee' => 0,
        ]), 'Participante');

        $previa = $this->previa($ana, ['address' => ['city' => 'Cuiabá']]);
        $this->assertTrue($previa['saiu_da_area']);
        $this->assertTrue($previa['ja_sorteadas'], 'Há inscrição sorteada/suplente que fica como está.');
        $ids = array_column($previa['afetadas'], 'id');
        sort($ids);
        $this->assertSame([$aguarda->id, $servo->id], $ids);

        $this->assertSame(2, $this->salvarFicha($ana, ['address' => ['city' => 'Cuiabá']])['retiradas']);
        $this->assertSame('Sorteado', $this->situacao($ana, $sorteada));
        $this->assertSame('Suplente', $this->situacao($ana, $suplente));
        $this->assertSame('Inscrito', $this->situacao($ana, $passado));
        $this->assertSame('Inscrito', $this->situacao($ana, $evento));

        // Outra cidade de fora não muda mais nada; mudar só o telefone também não.
        $this->assertSame([], $this->previa($ana, ['address' => ['city' => 'Rondonópolis']])['afetadas']);
        $this->assertFalse($this->previa($ana, ['phone' => '(66) 99714-8315'])['ja_sorteadas']);
    }

    public function testRetiradoNaoEntraNoSorteioMasPodeSerIndicado(): void
    {
        $fac = $this->criarAcampamento('FAC', ['raffle_man_vacancies' => 5]);
        $joao = $this->criarUsuario('João Lima', idade: 15);
        $inscricao = $this->inscrever($joao, $fac);
        $this->salvarFicha($joao, ['birthday' => $this->nascidoHa(19)]);

        for ($rodada = 1; $rodada <= 5; $rodada++) {
            (new SorteioService())->realizarCampistas((int)$fac->id, 5, 0, 0);
            $this->assertNull($this->recarregar($inscricao)->selection_method_id, 'Retirado do sorteio foi sorteado.');
            $this->assertSame('Retirado do sorteio', $this->situacao($joao, $inscricao));
        }

        // A organização ainda pode indicar a pessoa à mão: aí vale a indicação.
        $this->fetchTable('PreRegistrations')->updateAll(['selection_method_id' => $this->metodos['Base']], ['id' => $inscricao->id]);
        $this->assertSame('Indicado', $this->situacao($joao, $inscricao));
    }

    public function testPreviaConfereVersaoECamposComoOSalvar(): void
    {
        $ana = $this->criarUsuario('Ana Souza', idade: 20, sexo: 'F');

        $dados = $this->dados($ana, ['email' => 'sem-arroba']);
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/users/{$ana->id}/previa", $dados);
        $this->assertResponseCode(422);
        $this->assertArrayHasKey('email', $this->respostaJson()['errors']);

        $dados = $this->dados($ana, ['versao' => '2000-01-01 00:00:00']);
        $this->autenticarComo($this->admin);
        $this->post("/api/v1/admin/users/{$ana->id}/previa", $dados);
        $this->assertResponseCode(409);

        // Sem "Pessoas e acessos", nada.
        $dados = $this->dados($ana);
        $this->autenticarComo($ana);
        $this->post("/api/v1/admin/users/{$ana->id}/previa", $dados);
        $this->assertResponseCode(403);
    }
}
