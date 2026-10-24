<?php
declare(strict_types=1);

namespace App\Test\TestCase\Regras;

use App\Service\JwtService;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Base dos testes das regras de negócio.
 *
 * Cada teste roda dentro de uma transação que é desfeita no tearDown, então o
 * banco de teste volta vazio ao fim de cada um e um teste nunca enxerga os dados
 * do outro. Os cadastros básicos (categorias, estados civis, formas de seleção)
 * são criados a cada teste com os mesmos nomes dos seeders, porque o código
 * procura esses registros pelo nome, nunca por id.
 */
abstract class RegrasTestCase extends TestCase
{
    use IntegrationTestTrait;

    protected Connection $conn;

    /** @var array<string, int> nome da categoria => id */
    protected array $categorias = [];

    /** @var array<string, int> título do estado civil => id */
    protected array $estadosCivis = [];

    /** @var array<string, int> forma de seleção (Sorteio, Base...) => id */
    protected array $metodos = [];

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = ConnectionManager::get('test');
        $this->conn->enableSavePoints(true);
        $this->conn->begin();

        $this->criarCadastrosBasicos();
    }

    protected function tearDown(): void
    {
        if ($this->conn->inTransaction()) {
            $this->conn->rollback(true);
        }

        parent::tearDown();
    }

    private function criarCadastrosBasicos(): void
    {
        foreach (['Mirim', 'FAC', 'Juvenil', 'Sênior', 'Casais', 'Discipulados'] as $nome) {
            $this->categorias[$nome] = $this->salvar('Categories', ['name' => $nome, 'type' => 'Acampamento'])->id;
        }
        foreach (['Solteiro', 'Namorando', 'União estável', 'Casado'] as $titulo) {
            $this->estadosCivis[$titulo] = $this->salvar('MaritalStatuses', ['title' => $titulo])->id;
        }
        foreach (['Sorteio', 'Forania', 'Conselho', 'Base'] as $metodo) {
            $this->metodos[$metodo] = $this->salvar('SelectionMethods', [
                'method' => $metodo,
                'description' => $metodo,
                'type' => 'Acampamento',
            ])->id;
        }
    }

    // ─── Fábricas de dados ──────────────────────────────────────────────────

    protected function salvar(string $tabela, array $dados): EntityInterface
    {
        $table = $this->fetchTable($tabela);

        return $table->saveOrFail($table->newEntity($dados, ['accessibleFields' => ['*' => true]]));
    }

    /**
     * Usuário já com endereço. Por padrão mora em Primavera do Leste, que é uma
     * das cidades aceitas no sorteio.
     */
    protected function criarUsuario(
        string $nome,
        int $idade = 15,
        string $sexo = 'M',
        string $estadoCivil = 'Solteiro',
        string $cidade = 'Primavera do Leste',
        bool $admin = false,
    ): EntityInterface {
        $n = ++$this->sequencia;
        $usuario = $this->salvar('Users', [
            'cpf' => $this->cpfValido($n),
            'name' => $nome,
            'birthday' => (new \DateTime('today'))->modify("-{$idade} years -10 days")->format('Y-m-d'),
            'sex' => $sexo,
            'phone' => '6599' . str_pad((string)$n, 7, '0', STR_PAD_LEFT),
            'email' => "teste{$n}@exemplo.com",
            'password' => 'senha123',
            'is_admin' => $admin,
            'is_counselor' => false,
            'marital_status_id' => $this->estadosCivis[$estadoCivil],
        ]);

        $this->salvar('Addresses', [
            'user_id' => $usuario->id,
            'street' => 'Rua Teste',
            'number' => '1',
            'neighborhood' => 'Centro',
            'city' => $cidade,
            'cep' => '78850000',
        ]);

        return $usuario;
    }

    /** Acampamento (atividade + dados do acampamento) de uma categoria. */
    protected function criarAcampamento(string $categoria, array $dadosAcampamento = []): EntityInterface
    {
        // Valores neutros para as colunas obrigatórias; cada teste sobrescreve o que importa.
        $camping = $this->salvar('Campings', $dadosAcampamento + [
            'notice' => '#',
            'term' => '#',
            'planned_man_vacancies' => 0,
            'planned_woman_vacancies' => 0,
            'planned_couple_vacancies' => 0,
            'raffle_man_vacancies' => 0,
            'raffle_woman_vacancies' => 0,
            'raffle_couple_vacancies' => 0,
            'raffle_camper_date' => '2026-11-25 19:00:00',
            'raffle_servant_date' => '2026-11-25 19:00:00',
            'raffle_servant_subscription_start_date' => '2026-11-01 08:00:00',
            'raffle_servant_subscription_end_date' => '2026-11-20 23:59:00',
            'camper_registration_start_date' => '2026-11-26 08:00:00',
            'camper_registration_end_date' => '2026-12-01 23:59:00',
        ]);
        $n = ++$this->sequencia;

        return $this->salvar('Activities', [
            'name' => "Acampamento {$categoria} teste {$n}",
            'image' => '',
            'place' => 'Sítio Teste',
            'year' => 2026,
            'start_date' => '2026-12-05 08:00:00',
            'duration_days' => 3,
            'total_vacancies' => 100,
            'participant_fee' => 0,
            'servant_fee' => 0,
            'category_id' => $this->categorias[$categoria],
            'camping_id' => $camping->id,
        ]);
    }

    protected function inscrever(
        EntityInterface $usuario,
        EntityInterface $atividade,
        string $tipo = 'Campista',
        array $extra = [],
    ): EntityInterface {
        return $this->salvar('PreRegistrations', $extra + [
            'subscription_type' => $tipo,
            'is_fee_paid' => false,
            'is_quitter' => false,
            'form_submitted' => false,
            'user_id' => $usuario->id,
            'activity_id' => $atividade->id,
        ]);
    }

    /** Histórico: a pessoa já foi campista num acampamento anterior desta categoria. */
    protected function jaFoiCampista(EntityInterface $usuario, string $categoria, bool $desistiu = false): void
    {
        $anterior = $this->criarAcampamento($categoria);
        $this->inscrever($usuario, $anterior, 'Campista', ['is_quitter' => $desistiu]);
    }

    /** Pergunta no formulário da categoria — sem nenhuma, a regra do prazo não age. */
    protected function criarPergunta(string $categoria, string $tipo = 'Campista'): void
    {
        $secao = $this->salvar('Sections', ['title' => 'Seção teste', 'order' => 1]);
        $this->salvar('Questions', [
            'text' => 'Pergunta teste',
            'order' => 1,
            'type' => 'texto_curto',
            'is_required' => true,
            'accept_generic_answer' => false,
            'subscription_type' => $tipo,
            'category_id' => $this->categorias[$categoria],
            'section_id' => $secao->id,
        ]);
    }

    /** Recarrega a inscrição do banco, para ver o que o código gravou. */
    protected function recarregar(EntityInterface $inscricao): EntityInterface
    {
        return $this->fetchTable('PreRegistrations')->get($inscricao->id);
    }

    protected function ontem(): string
    {
        return DateTime::now()->subDays(1)->format('Y-m-d H:i:s');
    }

    protected function amanha(): string
    {
        return DateTime::now()->addDays(1)->format('Y-m-d H:i:s');
    }

    // ─── Requisições à API ──────────────────────────────────────────────────

    /** Próximas requisições da API vão autenticadas como este usuário. */
    protected function autenticarComo(EntityInterface $usuario): void
    {
        $this->configRequest(['headers' => [
            'Authorization' => 'Bearer ' . JwtService::createAccessToken((int)$usuario->id),
            'Accept' => 'application/json',
        ]]);
    }

    protected function respostaJson(): array
    {
        return json_decode((string)$this->_response->getBody(), true) ?? [];
    }

    /** CPF com dígitos verificadores válidos (o cadastro de usuário recusa CPF inválido). */
    private function cpfValido(int $n): string
    {
        $base = str_pad((string)(100000000 + $n), 9, '0', STR_PAD_LEFT);
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int)$base[$i] * ($t + 1 - $i);
            }
            $base .= (string)((10 * $soma) % 11 % 10);
        }

        return $base;
    }
}
