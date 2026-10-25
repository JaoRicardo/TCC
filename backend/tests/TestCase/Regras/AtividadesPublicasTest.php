<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

/**
 * Qualquer visitante vê os acampamentos e eventos da igreja (lista e detalhes)
 * sem precisar de conta. Para se inscrever, precisa estar logado.
 */
class AtividadesPublicasTest extends RegrasTestCase
{
    private function semLogin(): void
    {
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
    }

    public function testVisitanteVeAListaDeAtividades(): void
    {
        $atividade = $this->criarAcampamento('FAC');

        $this->semLogin();
        $this->get('/api/v1/activities');

        $this->assertResponseOk();
        $ids = array_column($this->respostaJson()['data'] ?? [], 'id');
        $this->assertContains($atividade->id, $ids);
    }

    public function testVisitanteVeOsDetalhesDeUmaAtividade(): void
    {
        $atividade = $this->criarAcampamento('FAC');

        $this->semLogin();
        $this->get("/api/v1/activities/{$atividade->id}");

        $this->assertResponseOk();
        $this->assertSame($atividade->name, $this->respostaJson()['data']['nome'] ?? null);
    }

    public function testVisitanteNaoConsegueSeInscrever(): void
    {
        $atividade = $this->criarAcampamento('FAC');

        $this->semLogin();
        $this->post("/api/v1/activities/{$atividade->id}/inscricoes", ['tipo' => 'Campista']);

        $this->assertResponseCode(401);
        $this->assertSame(0, $this->fetchTable('PreRegistrations')->find()
            ->where(['activity_id' => $atividade->id])
            ->count());
    }
}
