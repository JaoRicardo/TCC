<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * Formulários de inscrição de Campista do FAC e do Juvenil (mesmas perguntas nos dois).
 *
 * Copiado do banco em uso em 2026-10-23. Os dados pessoais (nome, celular, nascimento,
 * sexo, endereço, estado civil) não estão aqui porque o formulário já os mostra como
 * confirmação, a partir do cadastro do usuário.
 *
 * Perguntas com 'depende_de' só aparecem quando a pergunta indicada é respondida com "Sim".
 */
class FormulariosSeed extends BaseSeed
{
    private const CATEGORIAS = ['FAC', 'Juvenil'];
    private const TIPO_INSCRICAO = 'Campista';

    private const SECOES_NOVAS = [
        'Escolaridade' => 4,
        'Relacionamentos' => 5,
        'Acampamento' => 6,
    ];

    private const PERGUNTAS = [
        'Informações Pessoais' => [
            ['texto' => 'Ponto de referência', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['texto' => 'Quem ficará na sua casa durante o acampamento? Nome e telefone.', 'tipo' => 'texto_longo', 'obrigatoria' => true],
        ],
        'Escolaridade' => [
            ['texto' => 'Onde estuda?', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['texto' => 'Ano', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['texto' => 'Qual igreja e paróquia você frequenta', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['chave' => 'sacramento', 'texto' => 'Possui algum sacramento?', 'tipo' => 'sim_nao', 'obrigatoria' => true],
            ['texto' => 'Quais sacramentos?', 'tipo' => 'texto_curto', 'obrigatoria' => false, 'depende_de' => 'sacramento'],
            ['texto' => 'Turma', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['texto' => 'Técnico', 'tipo' => 'texto_curto', 'obrigatoria' => false],
            ['texto' => 'Período', 'tipo' => 'texto_curto', 'obrigatoria' => true],
        ],
        'Relacionamentos' => [
            ['texto' => 'Nome da mãe', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['texto' => 'Endereço da mãe', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['texto' => 'Nome do pai', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['texto' => 'Endereço do pai', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['texto' => 'Tem mãe e/ou pai falecidos?', 'tipo' => 'sim_nao', 'obrigatoria' => true],
            ['texto' => 'Foi adotado?', 'tipo' => 'sim_nao', 'obrigatoria' => true],
            ['texto' => 'Nome do(a) namorado(a)', 'tipo' => 'texto_curto', 'obrigatoria' => false],
            ['texto' => 'Celular da mãe', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['texto' => 'Celular do pai', 'tipo' => 'texto_curto', 'obrigatoria' => true],
            ['texto' => 'Amigo 1 (nome e celular)', 'tipo' => 'texto_curto', 'obrigatoria' => false],
            ['texto' => 'Amigo 2 (nome e celular)', 'tipo' => 'texto_curto', 'obrigatoria' => false],
        ],
        'Acampamento' => [
            ['chave' => 'ja_fez', 'texto' => 'Você já fez algum Acampamento?', 'tipo' => 'sim_nao', 'obrigatoria' => true],
            ['texto' => 'Qual e quando?', 'tipo' => 'texto_curto', 'obrigatoria' => false, 'depende_de' => 'ja_fez'],
            ['chave' => 'conhece_campista', 'texto' => 'Você conhece algum amigo/parente que vai fazer o 6° Acampamento FAC de Primavera do Leste?', 'tipo' => 'sim_nao', 'obrigatoria' => true],
            ['texto' => 'Quem?', 'tipo' => 'texto_curto', 'obrigatoria' => false, 'depende_de' => 'conhece_campista'],
            ['chave' => 'conhece_servo', 'texto' => 'Você conhece algum amigo/parente que vai servir no 6° Acampamento FAC de Primavera do Leste?', 'tipo' => 'sim_nao', 'obrigatoria' => true],
            ['texto' => 'Quem?', 'tipo' => 'texto_curto', 'obrigatoria' => false, 'depende_de' => 'conhece_servo'],
        ],
        'Informações de Saúde' => [
            ['texto' => 'Altura (cm)', 'tipo' => 'numero', 'obrigatoria' => true],
            ['texto' => 'Peso (kg)', 'tipo' => 'numero', 'obrigatoria' => true],
            ['chave' => 'remedio', 'texto' => 'Toma algum remédio controlado?', 'tipo' => 'sim_nao', 'obrigatoria' => true],
            ['texto' => 'Qual remédio?', 'tipo' => 'texto_curto', 'obrigatoria' => false, 'depende_de' => 'remedio'],
            ['chave' => 'restricao_medica', 'texto' => 'Possui alguma restrição médica ou fez alguma cirurgia recente?', 'tipo' => 'sim_nao', 'obrigatoria' => true],
            ['texto' => 'Qual?', 'tipo' => 'texto_curto', 'obrigatoria' => false, 'depende_de' => 'restricao_medica'],
            ['chave' => 'alergia', 'texto' => 'Possui alguma alergia?', 'tipo' => 'sim_nao', 'obrigatoria' => true],
            ['texto' => 'Qual alergia?', 'tipo' => 'texto_curto', 'obrigatoria' => false, 'depende_de' => 'alergia'],
            ['chave' => 'restricao_alimentar', 'texto' => 'Possui alguma restrição alimentar?', 'tipo' => 'sim_nao', 'obrigatoria' => true],
            ['texto' => 'Qual restrição?', 'tipo' => 'texto_curto', 'obrigatoria' => false, 'depende_de' => 'restricao_alimentar'],
            ['texto' => 'Qual tamanho de camiseta que você usa?', 'tipo' => 'unica_escolha', 'obrigatoria' => true, 'opcoes' => ['PP', 'P', 'M', 'G', 'GG', 'XG']],
        ],
    ];

    public function run(): void
    {
        $conn = $this->getAdapter()->getConnection();
        $agora = date('Y-m-d H:i:s');

        foreach (self::SECOES_NOVAS as $titulo => $ordem) {
            if (!$this->buscarId('sections', 'title', $titulo)) {
                $conn->insert('sections', ['title' => $titulo, 'order' => $ordem, 'created' => $agora, 'modified' => $agora]);
            }
        }

        $secoes = [];
        foreach (array_keys(self::PERGUNTAS) as $titulo) {
            $secoes[$titulo] = $this->buscarId('sections', 'title', $titulo)
                ?: throw new RuntimeException("Seção '$titulo' não encontrada. Rode o SectionsSeed antes.");
        }

        foreach (self::CATEGORIAS as $nomeCategoria) {
            $categoriaId = $this->buscarId('categories', 'name', $nomeCategoria)
                ?: throw new RuntimeException("Categoria '$nomeCategoria' não encontrada. Rode o CategoriesSeed antes.");

            // Não duplica o formulário se ele já existir (por exemplo, criado pela tela de admin).
            $existentes = $conn->execute(
                'SELECT COUNT(*) FROM questions WHERE category_id = ? AND subscription_type = ? AND deleted IS NULL',
                [$categoriaId, self::TIPO_INSCRICAO]
            )->fetchColumn(0);
            if ($existentes > 0) {
                $this->getIo()?->out("Formulário $nomeCategoria/" . self::TIPO_INSCRICAO . ' já existe, pulando.');
                continue;
            }

            $opcaoSimPorChave = [];
            foreach (self::PERGUNTAS as $tituloSecao => $perguntas) {
                foreach ($perguntas as $i => $p) {
                    $dependeDe = isset($p['depende_de']) ? $opcaoSimPorChave[$p['depende_de']] : null;
                    $conn->insert('questions', [
                        'category_id' => $categoriaId,
                        'subscription_type' => self::TIPO_INSCRICAO,
                        'text' => $p['texto'],
                        'order' => $i + 1,
                        'type' => $p['tipo'],
                        'is_required' => (int)$p['obrigatoria'],
                        'accept_generic_answer' => 0,
                        'section_id' => $secoes[$tituloSecao],
                        'depends_on_option_id' => $dependeDe,
                        'created' => $agora,
                        'modified' => $agora,
                    ]);
                    $perguntaId = (int)$conn->getDriver()->lastInsertId();

                    $opcoes = $p['tipo'] === 'sim_nao' ? ['Sim', 'Não'] : ($p['opcoes'] ?? []);
                    foreach ($opcoes as $textoOpcao) {
                        $conn->insert('options', ['text' => $textoOpcao, 'question_id' => $perguntaId, 'created' => $agora, 'modified' => $agora]);
                        if ($textoOpcao === 'Sim' && isset($p['chave'])) {
                            $opcaoSimPorChave[$p['chave']] = (int)$conn->getDriver()->lastInsertId();
                        }
                    }
                }
            }
        }
    }

    private function buscarId(string $tabela, string $coluna, string $valor): ?int
    {
        $id = $this->getAdapter()->getConnection()->execute(
            "SELECT id FROM $tabela WHERE $coluna = ? AND deleted IS NULL ORDER BY id LIMIT 1",
            [$valor]
        )->fetchColumn(0);

        return $id === false ? null : (int)$id;
    }
}
