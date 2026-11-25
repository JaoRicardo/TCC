<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use Cake\Datasource\EntityInterface;

/**
 * "Já foi campista nesta categoria" (ParticipacoesService, decidido com o
 * usuário em 2026-11-25):
 *
 * - Pelo sistema, só conta quem ficou na lista até o acampamento começar:
 *   sorteado ou indicado, enviou o formulário, pagou, não desistiu nem foi
 *   tirado (inclusive no próprio dia, quando entra o próximo suplente).
 *   Inscrito não sorteado e suplente que não foi chamado não contam.
 * - Gestão › Participações anteriores: cadastro pelo CPF, mesmo sem conta.
 */
class ParticipacoesAnterioresTest extends RegrasTestCase
{
    private function elegibilidade(EntityInterface $quem, EntityInterface $atividade): array
    {
        $this->autenticarComo($quem);
        $this->get("/api/v1/activities/{$atividade->id}/elegibilidade");
        $this->assertResponseOk();

        return $this->respostaJson()['data'];
    }

    private function assertNaoFoiCampista(EntityInterface $pessoa, string $categoria = 'FAC'): void
    {
        $e = $this->elegibilidade($pessoa, $this->criarAcampamento($categoria));
        $this->assertTrue($e['campista']['pode'], 'Deveria poder se inscrever como campista de novo: ' . ($e['campista']['mensagem'] ?? ''));
        $this->assertSame('nunca_campista', $e['servo']['codigo']);
    }

    private function assertFoiCampista(EntityInterface $pessoa, string $categoria = 'FAC'): void
    {
        $e = $this->elegibilidade($pessoa, $this->criarAcampamento($categoria));
        $this->assertSame('ja_campista', $e['campista']['codigo']);
        $this->assertTrue($e['servo']['pode']);
    }

    private function adicionar(EntityInterface $quem, array $dados): array
    {
        $this->autenticarComo($quem);
        $this->post('/api/v1/admin/participacoes', $dados);

        return $this->respostaJson();
    }

    // ─── Pelo sistema ───────────────────────────────────────────────────────

    public function testQuemConcluiuOAcampamentoFoiCampista(): void
    {
        $pessoa = $this->criarUsuario('Concluiu', idade: 16);
        $this->jaFoiCampista($pessoa, 'FAC');

        $this->assertFoiCampista($pessoa);
    }

    public function testInscritoNaoSorteadoNaoFoiCampista(): void
    {
        $pessoa = $this->criarUsuario('Não sorteado', idade: 16);
        $this->jaFoiCampista($pessoa, 'FAC', extra: ['selection_method_id' => null, 'form_submitted' => false, 'is_fee_paid' => false]);

        $this->assertNaoFoiCampista($pessoa);
    }

    public function testSuplenteQueNaoFoiChamadoNaoFoiCampista(): void
    {
        $pessoa = $this->criarUsuario('Suplente', idade: 16);
        $this->jaFoiCampista($pessoa, 'FAC', extra: ['selection_method_id' => null, 'substitute_position' => 1, 'form_submitted' => false, 'is_fee_paid' => false]);

        $this->assertNaoFoiCampista($pessoa);
    }

    public function testSemFormularioOuSemPagamentoNaoFoiCampista(): void
    {
        // O formulário só é exigido quando tem perguntas.
        $this->criarPergunta('FAC');
        $semFormulario = $this->criarUsuario('Sem formulário', idade: 16);
        $this->jaFoiCampista($semFormulario, 'FAC', extra: ['form_submitted' => false]);
        $this->assertNaoFoiCampista($semFormulario);

        $semPagar = $this->criarUsuario('Sem pagar', idade: 16);
        $this->jaFoiCampista($semPagar, 'FAC', extra: ['is_fee_paid' => false]);
        $this->assertNaoFoiCampista($semPagar);

        // Gratuito: não precisa ter pagado.
        $gratuito = $this->criarUsuario('Gratuito', idade: 16);
        $this->jaFoiCampista($gratuito, 'FAC', extra: ['is_fee_paid' => false], dadosAtividade: ['participant_fee' => 0]);
        $this->assertFoiCampista($gratuito);
    }

    public function testTiradoNoDiaDoAcampamentoNaoFoiCampista(): void
    {
        $cancelado = $this->criarUsuario('Não apareceu', idade: 16);
        $this->jaFoiCampista($cancelado, 'FAC', extra: [
            'disqualified_at' => '2025-01-10 09:00:00',
            'disqualification_reason' => 'cancelada_organizacao',
        ]);
        $this->assertNaoFoiCampista($cancelado);

        $desistiu = $this->criarUsuario('Desistiu', idade: 16);
        $this->jaFoiCampista($desistiu, 'FAC', desistiu: true);
        $this->assertNaoFoiCampista($desistiu);
    }

    public function testAcampamentoQueAindaNaoComecouNaoConta(): void
    {
        $pessoa = $this->criarUsuario('Sorteado no próximo', idade: 16);
        $this->jaFoiCampista($pessoa, 'FAC', dadosAtividade: ['start_date' => '2099-01-10 08:00:00']);

        $e = $this->elegibilidade($pessoa, $this->criarAcampamento('FAC'));
        $this->assertSame('nunca_campista', $e['servo']['codigo'], 'Ainda não acampou: não pode servir.');
        // Mas não se inscreve como campista em outro FAC enquanto está neste.
        $this->assertSame('inscrito_em_outro', $e['campista']['codigo']);
    }

    public function testConcorrendoEmOutroDaMesmaCategoriaSoAteNaoSerSorteado(): void
    {
        $pessoa = $this->criarUsuario('Concorrendo', idade: 16);
        $primeiro = $this->criarAcampamento('FAC');
        $this->inscrever($pessoa, $primeiro);

        $segundo = $this->criarAcampamento('FAC');
        $this->assertSame('inscrito_em_outro', $this->elegibilidade($pessoa, $segundo)['campista']['codigo']);

        // Sorteio do primeiro feito e ela não saiu: livre para tentar no outro.
        $this->fetchTable('Campings')->updateAll(['camper_raffle_done_at' => '2026-11-20 19:00:00'], ['id' => $primeiro->camping_id]);
        $this->assertTrue($this->elegibilidade($pessoa, $segundo)['campista']['pode']);
    }

    // ─── Cadastro à mão ─────────────────────────────────────────────────────

    public function testCadastradaPeloCpfContaComoCampista(): void
    {
        $admin = $this->criarUsuario('Admin', admin: true);
        $pessoa = $this->criarUsuario('Fez em 2015', idade: 30);

        $r = $this->adicionar($admin, ['cpf' => $pessoa->cpf, 'nome' => '', 'categoriaId' => $this->categorias['Juvenil'], 'ano' => '2015']);
        $this->assertResponseCode(201);
        $this->assertSame('Fez em 2015', $r['data']['nome'], 'Sem nome e com conta, usa o nome da conta.');
        $this->assertSame((int)$pessoa->id, $r['data']['contaId']);

        $this->assertFoiCampista($pessoa, 'Juvenil');
        // Na escada, serve também nos degraus de baixo.
        $this->assertTrue($this->elegibilidade($pessoa, $this->criarAcampamento('FAC'))['servo']['pode']);

        $log = $this->fetchTable('UserChangeLogs')->find()->where(['user_id' => $pessoa->id])->all()->extract('description')->toList();
        $this->assertContains('Participação anterior cadastrada: campista em Juvenil (2015).', $log);
    }

    public function testSemContaValeQuandoAPessoaCriaAContaDepois(): void
    {
        $admin = $this->criarUsuario('Admin', admin: true);
        $cpf = $this->cpfValido(900);
        $formatado = substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);

        $r = $this->adicionar($admin, ['cpf' => $formatado, 'nome' => '  Maria   da Silva ', 'categoriaId' => $this->categorias['Sênior'], 'ano' => '']);
        $this->assertResponseCode(201);
        $this->assertSame('Maria da Silva', $r['data']['nome']);
        $this->assertNull($r['data']['contaId']);
        $this->assertNull($r['data']['ano']);

        // Criou a conta depois, com o mesmo CPF.
        $maria = $this->criarUsuario('Maria da Silva', idade: 40, sexo: 'F');
        $this->fetchTable('Users')->updateAll(['cpf' => $cpf], ['id' => $maria->id]);
        $maria = $this->fetchTable('Users')->get($maria->id);

        $this->assertFoiCampista($maria, 'Sênior');
        // Cumpre o pré-requisito do Discipulados (precisa ter feito Sênior).
        $this->assertTrue($this->elegibilidade($maria, $this->criarAcampamento('Discipulados'))['campista']['pode']);
    }

    public function testCamposObrigatoriosERepetidos(): void
    {
        $admin = $this->criarUsuario('Admin', admin: true);

        $r = $this->adicionar($admin, ['cpf' => '111.111.111-11', 'nome' => '', 'categoriaId' => 0, 'ano' => '1890']);
        $this->assertResponseCode(422);
        $this->assertSame(['cpf', 'nome', 'categoriaId', 'ano'], array_keys($r['erros']));

        $dados = ['cpf' => $this->cpfValido(901), 'nome' => 'João', 'categoriaId' => $this->categorias['FAC'], 'ano' => '2018'];
        $this->adicionar($admin, $dados);
        $this->assertResponseCode(201);
        $r = $this->adicionar($admin, $dados);
        $this->assertResponseCode(422);
        $this->assertStringContainsString('já está cadastrado como campista em FAC', $r['erros']['categoriaId']);

        // Já concluiu pelo sistema: não precisa cadastrar.
        $pessoa = $this->criarUsuario('Pelo sistema', idade: 16);
        $this->jaFoiCampista($pessoa, 'FAC');
        $r = $this->adicionar($admin, ['cpf' => $pessoa->cpf, 'nome' => '', 'categoriaId' => $this->categorias['FAC'], 'ano' => '']);
        $this->assertResponseCode(422);
        $this->assertStringContainsString('Pelo sistema', $r['erros']['categoriaId']);
    }

    public function testRemoverDeixaDeContar(): void
    {
        $admin = $this->criarUsuario('Admin', admin: true);
        $pessoa = $this->criarUsuario('Cadastrada errado', idade: 16);
        $id = $this->adicionar($admin, ['cpf' => $pessoa->cpf, 'nome' => '', 'categoriaId' => $this->categorias['FAC'], 'ano' => ''])['data']['id'];
        $this->assertFoiCampista($pessoa);

        $this->autenticarComo($admin);
        $this->delete("/api/v1/admin/participacoes/{$id}");
        $this->assertResponseOk();
        $this->assertNaoFoiCampista($pessoa);

        $this->autenticarComo($admin);
        $this->get('/api/v1/admin/participacoes');
        $this->assertSame([], $this->respostaJson()['data']['participacoes']);
    }

    public function testConsultaDoCpfMostraOQueJaConta(): void
    {
        $admin = $this->criarUsuario('Admin', admin: true);
        $pessoa = $this->criarUsuario('Com histórico', idade: 20);
        $this->jaFoiCampista($pessoa, 'FAC');
        $this->adicionar($admin, ['cpf' => $pessoa->cpf, 'nome' => '', 'categoriaId' => $this->categorias['Mirim'], 'ano' => '2016']);

        $this->autenticarComo($admin);
        $this->get('/api/v1/admin/participacoes/cpf/' . $pessoa->cpf);
        $d = $this->respostaJson()['data'];
        $this->assertSame('Com histórico', $d['conta']['nome']);
        $this->assertSame(['FAC'], array_column($d['sistema'], 'categoria'));
        $this->assertSame(['Mirim'], array_column($d['cadastradas'], 'categoria'));
    }

    public function testSemPermissaoNaoAcessa(): void
    {
        $pessoa = $this->criarUsuario('Sem permissão');
        $this->autenticarComo($pessoa);
        $this->get('/api/v1/admin/participacoes');
        $this->assertResponseCode(403);

        $this->adicionar($pessoa, ['cpf' => $this->cpfValido(902), 'nome' => 'X', 'categoriaId' => $this->categorias['FAC']]);
        $this->assertResponseCode(403);
    }
}
