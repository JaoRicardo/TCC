<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tela Categorias (área Gestão do painel): as regras de cada categoria de
 * acampamento saem do código (onde dependiam do nome — "Juvenil", "Casais"…)
 * e viram colunas, para que criar e renomear categorias funcione de verdade.
 *
 * - position: ordem da lista (arrastar na tela). Também é a escada por idade:
 *   entre as que estão na escada (in_hierarchy), ter sido campista numa deixa
 *   servir nela e nas que vêm antes. Fora da escada, só na própria.
 * - min_age/max_age (já existiam, mas eram ignorados): idade do campista.
 * - required_category_id: precisa ter sido campista nela antes (Discipulados → Sênior).
 * - couples_only: só vagas de casais, sorteados juntos (Casais).
 * - accepts_married: false = casados/união estável não entram como campistas (Juvenil).
 * - filled_by_guardian: o formulário é preenchido pelo responsável (Mirim).
 * - archived_at: arquivada some da criação de acampamentos, mas os
 *   formulários e respostas continuam acessíveis.
 *
 * Também cria a permissão "categorias" (tela nova da área Gestão).
 *
 * As seis categorias existentes recebem as regras que estavam no código.
 */
class AddRegrasNasCategorias extends BaseMigration
{
    public function up(): void
    {
        $this->table('categories')
            ->addColumn('position', 'integer', ['null' => false, 'default' => 0, 'after' => 'type'])
            ->addColumn('in_hierarchy', 'boolean', ['null' => false, 'default' => false, 'after' => 'max_age'])
            ->addColumn('required_category_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'in_hierarchy'])
            ->addColumn('couples_only', 'boolean', ['null' => false, 'default' => false, 'after' => 'required_category_id'])
            ->addColumn('accepts_married', 'boolean', ['null' => false, 'default' => true, 'after' => 'couples_only'])
            ->addColumn('filled_by_guardian', 'boolean', ['null' => false, 'default' => false, 'after' => 'accepts_married'])
            ->addColumn('archived_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'filled_by_guardian'])
            ->addForeignKey('required_category_id', 'categories', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();

        $regras = [
            'Mirim'        => ['position' => 1, 'min_age' => 10, 'max_age' => 12, 'in_hierarchy' => 1, 'filled_by_guardian' => 1],
            'FAC'          => ['position' => 2, 'min_age' => 14, 'max_age' => 17, 'in_hierarchy' => 1],
            'Juvenil'      => ['position' => 3, 'min_age' => 18, 'max_age' => 24, 'in_hierarchy' => 1, 'accepts_married' => 0],
            'Sênior'       => ['position' => 4, 'min_age' => 25, 'max_age' => 60, 'in_hierarchy' => 1],
            'Casais'       => ['position' => 5, 'min_age' => null, 'max_age' => null, 'couples_only' => 1],
            'Discipulados' => ['position' => 6, 'min_age' => null, 'max_age' => null],
        ];
        foreach ($regras as $nome => $campos) {
            $sets = [];
            $valores = [];
            foreach ($campos as $coluna => $valor) {
                $sets[] = "{$coluna} = ?";
                $valores[] = $valor;
            }
            $valores[] = $nome;
            $this->execute(
                'UPDATE categories SET ' . implode(', ', $sets) . " WHERE name = ? AND type = 'Acampamento'",
                $valores,
            );
        }

        // Outras categorias de acampamento que existam: depois das conhecidas, por id.
        $outras = $this->fetchAll(
            "SELECT id FROM categories WHERE type = 'Acampamento' AND name NOT IN ('Mirim','FAC','Juvenil','Sênior','Casais','Discipulados') ORDER BY id",
        );
        $posicao = 7;
        foreach ($outras as $linha) {
            $this->execute('UPDATE categories SET position = ? WHERE id = ?', [$posicao++, $linha['id']]);
        }

        $this->execute(
            "UPDATE categories d JOIN categories s ON s.name = 'Sênior' AND s.type = 'Acampamento' AND s.deleted IS NULL
             SET d.required_category_id = s.id
             WHERE d.name = 'Discipulados' AND d.type = 'Acampamento'",
        );

        // Permissão nova "categorias" (área Gestão): como em
        // AddRespostasFormulariosPermission, quem gerencia permissões recebe junto.
        $this->execute(
            "INSERT INTO user_permissions (user_id, permission, created)
             SELECT up.user_id, 'categorias', NOW()
             FROM user_permissions up
             WHERE up.permission = 'permissoes'
               AND NOT EXISTS (
                   SELECT 1 FROM user_permissions ja
                   WHERE ja.user_id = up.user_id AND ja.permission = 'categorias'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM user_permissions WHERE permission = 'categorias'");
        $this->table('categories')
            ->dropForeignKey('required_category_id')
            ->update();
        $this->table('categories')
            ->removeColumn('position')
            ->removeColumn('in_hierarchy')
            ->removeColumn('required_category_id')
            ->removeColumn('couples_only')
            ->removeColumn('accepts_married')
            ->removeColumn('filled_by_guardian')
            ->removeColumn('archived_at')
            ->update();
    }
}
