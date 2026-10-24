<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\SorteioService;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;

/**
 * Sorteio automático: quando a data do sorteio chega, ele roda sozinho na próxima
 * visita ao site — uma vez só por data, e nunca duas execuções ao mesmo tempo.
 */
class SorteioAutomaticoTest extends RegrasTestCase
{
    /** FAC com sorteio de campistas ontem, 1 vaga de homem e 3 inscritos. */
    private function facComSorteioVencido(): EntityInterface
    {
        $fac = $this->criarAcampamento('FAC', [
            'raffle_camper_date' => $this->ontem(),
            'raffle_man_vacancies' => 1,
        ]);
        for ($i = 1; $i <= 3; $i++) {
            $this->inscrever($this->criarUsuario("Campista {$i}"), $fac, 'Campista');
        }

        return $fac;
    }

    private function camping(EntityInterface $atividade): EntityInterface
    {
        return $this->fetchTable('Campings')->get($atividade->camping_id);
    }

    public function testSemNinguemParaSortearNaoSorteiaACadaVisita(): void
    {
        // Sorteio de servos vencido, mas sem setor nem servo inscrito.
        $this->criarAcampamento('FAC', ['raffle_servant_date' => $this->ontem()]);
        $sorteio = new SorteioService();

        $this->assertCount(1, $sorteio->executarSorteiosPendentes());
        $this->assertSame([], $sorteio->executarSorteiosPendentes(), 'O sorteio rodou de novo na segunda visita.');
    }

    public function testResetarDepoisDaDataNaoFazOSorteioDispararSozinho(): void
    {
        $fac = $this->facComSorteioVencido();
        $sorteio = new SorteioService();

        $sorteio->executarSorteiosPendentes();
        $this->assertTrue($sorteio->campistasJaRealizado((int)$fac->id));

        $sorteio->resetarCampistas((int)$fac->id);
        $sorteio->executarSorteiosPendentes();
        $this->assertFalse($sorteio->campistasJaRealizado((int)$fac->id), 'O sorteio resetado disparou sozinho de novo.');
    }

    public function testDataDoSorteioAdiadaRodaDeNovoNaNovaData(): void
    {
        $fac = $this->facComSorteioVencido();
        $sorteio = new SorteioService();
        $sorteio->executarSorteiosPendentes();
        $sorteio->resetarCampistas((int)$fac->id);

        // A organização remarcou o sorteio: a nova data é depois da última execução.
        $camping = $this->camping($fac);
        $camping->camper_raffle_auto_ran_at = DateTime::now()->subDays(3);
        $camping->raffle_camper_date = DateTime::now()->subDays(1);
        $this->fetchTable('Campings')->saveOrFail($camping);

        $sorteio->executarSorteiosPendentes();
        $this->assertTrue($sorteio->campistasJaRealizado((int)$fac->id));
    }

    public function testNaoRodaDuasVezesAoMesmoTempo(): void
    {
        $fac = $this->facComSorteioVencido();

        // Outra conexão (como outra aba abrindo o painel) segura a trava.
        $config = ConnectionManager::getConfig('test');
        ConnectionManager::setConfig('outra_aba', $config);
        $outra = ConnectionManager::get('outra_aba');
        try {
            $nome = $outra->execute('SELECT CONCAT(DATABASE(), ?) AS nome', [':sorteios_pendentes'])->fetch('assoc')['nome'];
            $outra->execute('SELECT GET_LOCK(?, 0)', [$nome]);

            $this->assertSame([], (new SorteioService())->executarSorteiosPendentes());
            $this->assertFalse((new SorteioService())->campistasJaRealizado((int)$fac->id), 'Rodou mesmo com outra execução em andamento.');

            $outra->execute('SELECT RELEASE_LOCK(?)', [$nome]);
            $this->assertCount(1, (new SorteioService())->executarSorteiosPendentes(), 'Liberada a trava, o sorteio tinha que rodar.');
        } finally {
            $outra->getDriver()->disconnect();
            ConnectionManager::drop('outra_aba');
        }
    }
}
