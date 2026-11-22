<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\TableLocator;

/**
 * "Retirado do sorteio": quando a organização corrige na ficha da pessoa a data
 * de nascimento, o sexo ou a cidade, as inscrições de acampamento que ainda
 * aguardam sorteio (situação "Inscrito", atividade em andamento) e deixam de
 * poder concorrer com os dados novos saem do sorteio. Decisões do usuário:
 *
 * - nascimento: só sai quem, com a idade nova, não tem mais a idade da
 *   categoria do acampamento (Campista; servo não depende da idade);
 * - sexo: continua concorrendo pelas vagas do outro sexo (o sorteio lê o sexo
 *   na hora); só sai se o acampamento não tem vaga de sorteio para esse sexo.
 *   Casado(a) concorre pelas vagas de casal, então o sexo não muda nada;
 * - cidade: mudou para fora de Primavera do Leste/Poxoréu, sai de todas
 *   (Campista e Servo).
 *
 * Sorteado, Indicado, Suplente, Formulário Enviado e evento não mudam. A mesma
 * conta serve para a prévia (janela "Conferir antes de salvar") e para o salvar.
 */
class RetiradaDoSorteioService
{
    /** Faixa de idade de Campista por categoria (Casais e Discipulados não têm). */
    public const FAIXAS_DE_IDADE = [
        'Mirim'   => [10, 12],
        'FAC'     => [14, 17],
        'Juvenil' => [18, 24],
        'Sênior'  => [25, 60],
    ];

    private $PreRegistrations;

    public function __construct()
    {
        $locator = new TableLocator();
        $this->PreRegistrations = $locator->get('PreRegistrations');
    }

    /**
     * O que acontece com as inscrições da pessoa se os dados mudarem de $antes
     * para $depois (chaves birthday 'Y-m-d', sex, marital_status_id e city).
     *
     * @return array{afetadas: list<array{inscricao: object, motivo: string}>, ja_sorteadas: bool, saiu_da_area: bool}
     */
    public function prever(int $userId, array $antes, array $depois): array
    {
        $mudouNascimento = (string)$antes['birthday'] !== (string)$depois['birthday'];
        $mudouSexo = (string)$antes['sex'] !== (string)$depois['sex'];
        $saiuDaArea = SorteioService::moraNaArea($antes['city']) && !SorteioService::moraNaArea($depois['city']);

        $resultado = ['afetadas' => [], 'ja_sorteadas' => false, 'saiu_da_area' => $saiuDaArea];
        if (!$mudouNascimento && !$mudouSexo && !$saiuDaArea) {
            return $resultado;
        }

        $idade = self::idade($depois['birthday']);
        $hoje = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $inscricoes = $this->PreRegistrations->find()
            ->contain(['Activities' => ['Categories', 'Campings']])
            ->where([
                'PreRegistrations.user_id'    => $userId,
                'PreRegistrations.deleted IS' => null,
                'PreRegistrations.is_quitter' => false,
                'Activities.deleted IS'       => null,
                'Activities.camping_id IS NOT' => null,
            ])
            ->orderBy(['Activities.start_date' => 'ASC'])
            ->all();

        foreach ($inscricoes as $inscricao) {
            $atividade = $inscricao->activity;
            $fim = $atividade->start_date?->modify('+' . max(0, (int)$atividade->duration_days - 1) . ' days');
            if ($fim !== null && $fim->format('Y-m-d') < $hoje) continue; // já passou

            $aguardaSorteio = $inscricao->selection_method_id === null
                && $inscricao->substitute_position === null
                && $inscricao->disqualified_at === null;
            if (!$aguardaSorteio) {
                // Prazo perdido já não concorre; o resto (sorteado, suplente,
                // indicado, formulário enviado) fica como está.
                $resultado['ja_sorteadas'] = $resultado['ja_sorteadas'] || $inscricao->disqualified_at === null;
                continue;
            }
            if ($inscricao->removed_from_raffle_at !== null) continue;

            $motivo = null;
            if ($saiuDaArea) {
                $motivo = 'cidade';
            } elseif ($inscricao->subscription_type === 'Campista') {
                $faixa = self::FAIXAS_DE_IDADE[$atividade->category->name ?? ''] ?? null;
                if ($mudouNascimento && $faixa !== null && $idade !== null && ($idade < $faixa[0] || $idade > $faixa[1])) {
                    $motivo = 'idade';
                } elseif ($mudouSexo && ($atividade->category->name ?? '') !== 'Casais' && $this->semVagaParaOSexo($atividade->camping, (string)$depois['sex'])) {
                    // No Casais todos concorrem como casal; fora dele, quem é
                    // casado concorre como homem ou mulher, então o sexo conta.
                    $motivo = 'sexo';
                }
            }

            if ($motivo !== null) {
                $resultado['afetadas'][] = ['inscricao' => $inscricao, 'motivo' => $motivo];
            }
        }

        return $resultado;
    }

    /** Tira do sorteio as inscrições que prever() apontou. Devolve quantas. */
    public function aplicar(array $afetadas): int
    {
        $agora = DateTime::now();
        foreach ($afetadas as ['inscricao' => $inscricao, 'motivo' => $motivo]) {
            $inscricao->removed_from_raffle_at = $agora;
            $inscricao->removed_from_raffle_reason = $motivo;
            $this->PreRegistrations->saveOrFail($inscricao, ['associated' => false]);
        }

        return count($afetadas);
    }

    private function semVagaParaOSexo(?object $acampamento, string $sexo): bool
    {
        if ($acampamento === null) return false;
        $vagas = $sexo === 'M' ? $acampamento->raffle_man_vacancies : $acampamento->raffle_woman_vacancies;

        return (int)$vagas <= 0;
    }

    /** Idade hoje, a partir de 'Y-m-d'. */
    public static function idade(mixed $nascimento): ?int
    {
        if (!$nascimento) return null;
        try {
            $data = new \DateTimeImmutable(is_object($nascimento) ? $nascimento->format('Y-m-d') : (string)$nascimento);

            return $data->diff(new \DateTimeImmutable('today'))->y;
        } catch (\Exception) {
            return null;
        }
    }
}
