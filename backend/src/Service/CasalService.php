<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\TableLocator;

/**
 * Casal no acampamento de Casais (decidido com o usuário em 2026-11-26).
 *
 * Quem se inscreve como campista numa categoria só de casais informa o CPF do
 * cônjuge, e o sistema inscreve os dois. A inscrição só vale quando o cônjuge
 * confirma que está ciente, numa janela que aparece para ele em qualquer tela
 * (GET /me/casal). Enquanto isso as duas inscrições ficam com
 * `awaiting_spouse` e não concorrem ao sorteio nem podem ser indicadas.
 *
 * - Dá para confirmar até a organização fazer o sorteio de campistas. Casal
 *   não confirmado até lá fica de fora ("Casal não confirmado").
 * - Ao confirmar, se o cônjuge estiver como solteiro (ou outro estado civil),
 *   o estado civil dele passa a ser o de quem inscreveu (Casado ou União estável).
 * - Se o cônjuge disser que não está ciente, as duas inscrições são canceladas
 *   e quem inscreveu vê um aviso na próxima vez que entrar.
 */
class CasalService
{
    /** Estados civis de quem vive como casal. */
    public const CASADOS = ['Casado', 'União estável'];

    private $PreRegistrations;
    private $Users;
    private $MaritalStatuses;
    private $Logs;

    public function __construct()
    {
        $locator = new TableLocator();
        $this->PreRegistrations = $locator->get('PreRegistrations');
        $this->Users = $locator->get('Users');
        $this->MaritalStatuses = $locator->get('MaritalStatuses');
        $this->Logs = $locator->get('UserChangeLogs');
    }

    /** O título do estado civil ("Casado", "Solteiro"…), ou null. */
    public function tituloDoEstadoCivil(?int $id): ?string
    {
        if (!$id) return null;

        return $this->MaritalStatuses->find()->where(['id' => $id])->first()?->title;
    }

    public function ehCasado(?int $maritalStatusId): bool
    {
        return in_array($this->tituloDoEstadoCivil($maritalStatusId), self::CASADOS, true);
    }

    /** O sorteio de campistas desta atividade já foi feito (não dá mais para confirmar)? */
    public function sorteioFeito(int $activityId): bool
    {
        return (new SorteioService())->sorteioFeitoEm($activityId, 'Campista') !== null;
    }

    /**
     * Inscreve o casal: a inscrição de quem pediu e a do cônjuge, as duas
     * aguardando a confirmação do cônjuge.
     *
     * @return object a inscrição de quem pediu
     */
    public function inscrever(int $userId, int $conjugeId, int $activityId): object
    {
        return ConnectionManager::get('default')->transactional(function () use ($userId, $conjugeId, $activityId) {
            $minha = $this->PreRegistrations->saveOrFail($this->PreRegistrations->newEntity([
                'subscription_type' => 'Campista',
                'is_fee_paid'       => false,
                'is_quitter'        => false,
                'user_id'           => $userId,
                'activity_id'       => $activityId,
                'spouse_id'         => $conjugeId,
                'awaiting_spouse'   => true,
            ]));
            $this->PreRegistrations->saveOrFail($this->PreRegistrations->newEntity([
                'subscription_type' => 'Campista',
                'is_fee_paid'       => false,
                'is_quitter'        => false,
                'user_id'           => $conjugeId,
                'activity_id'       => $activityId,
                'spouse_id'         => $userId,
                'awaiting_spouse'   => true,
                'added_by_spouse'   => true,
            ]));

            return $minha;
        });
    }

    /** A outra inscrição do casal na mesma atividade (o vínculo pode estar em qualquer um dos dois). */
    public function outraDoCasal(object $inscricao): ?object
    {
        $ou = [['spouse_id' => $inscricao->user_id]];
        if ($inscricao->spouse_id) $ou[] = ['user_id' => $inscricao->spouse_id];

        return $this->PreRegistrations->find()
            ->where([
                'activity_id' => $inscricao->activity_id,
                'id !='       => $inscricao->id,
                'deleted IS'  => null,
                'OR'          => $ou,
            ])
            ->first();
    }

    /**
     * Inscrições que esperam a confirmação desta pessoa (feitas pelo cônjuge),
     * com o sorteio ainda não feito.
     *
     * @return list<object> com contain de Activities (Categories)
     */
    public function pendentesDe(int $userId): array
    {
        $lista = $this->PreRegistrations->find()
            ->contain(['Activities' => ['Categories']])
            ->where([
                'PreRegistrations.user_id'         => $userId,
                'PreRegistrations.deleted IS'      => null,
                'PreRegistrations.awaiting_spouse' => true,
                'PreRegistrations.added_by_spouse' => true,
                'PreRegistrations.is_quitter'      => false,
                'PreRegistrations.disqualified_at IS' => null,
                'Activities.deleted IS'            => null,
            ])
            ->orderBy(['PreRegistrations.created' => 'ASC'])
            ->all()
            ->toArray();

        return array_values(array_filter($lista, fn($p) => !$this->sorteioFeito((int)$p->activity_id)));
    }

    /**
     * Inscrições desta pessoa que o cônjuge recusou e cujo aviso ela ainda não viu.
     *
     * @return list<object> com contain de Activities
     */
    public function recusasNaoVistasDe(int $userId): array
    {
        return $this->PreRegistrations->find()
            ->contain(['Activities'])
            ->where([
                'PreRegistrations.user_id'               => $userId,
                'PreRegistrations.spouse_refused_at IS NOT' => null,
                'PreRegistrations.refusal_seen_at IS'    => null,
            ])
            ->orderBy(['PreRegistrations.spouse_refused_at' => 'ASC'])
            ->all()
            ->toArray();
    }

    /**
     * O cônjuge confirmou: as duas inscrições passam a valer (concorrem ao
     * sorteio). Se o estado civil dele não é de casado, passa a ser o de quem
     * inscreveu. Devolve o título do estado civil novo, ou null se não mudou.
     */
    public function confirmar(object $inscricao, object $outra, string $nomeDaAtividade): ?string
    {
        return ConnectionManager::get('default')->transactional(function () use ($inscricao, $outra, $nomeDaAtividade) {
            $agora = DateTime::now();
            foreach ([$inscricao, $outra] as $p) {
                $p->awaiting_spouse = false;
                $p->spouse_confirmed_at = $agora;
                $this->PreRegistrations->saveOrFail($p, ['associated' => false]);
            }

            $eu = $this->Users->get($inscricao->user_id);
            $quemInscreveu = $this->Users->get($outra->user_id);
            $novo = null;
            if (!$this->ehCasado($eu->marital_status_id)) {
                $idNovo = $this->ehCasado($quemInscreveu->marital_status_id)
                    ? (int)$quemInscreveu->marital_status_id
                    : (int)$this->MaritalStatuses->find()->where(['title' => 'Casado'])->firstOrFail()->id;
                $antes = $this->tituloDoEstadoCivil($eu->marital_status_id) ?? 'não informado';
                $novo = $this->tituloDoEstadoCivil($idNovo);
                $this->Users->updateAll(['marital_status_id' => $idNovo], ['id' => $eu->id]);
                $this->Logs->registrar(
                    (int)$eu->id,
                    (int)$eu->id,
                    "Estado civil: {$antes} → {$novo} (ao confirmar a inscrição do casal em {$nomeDaAtividade})."
                );
            }
            $this->Logs->registrar(
                (int)$eu->id,
                (int)$eu->id,
                "Confirmou a inscrição do casal em {$nomeDaAtividade}, feita por {$quemInscreveu->name}."
            );

            return $novo;
        });
    }

    /**
     * O cônjuge disse que não está ciente: as duas inscrições são canceladas e
     * quem inscreveu é avisado (spouse_refused_at na inscrição dele).
     */
    public function recusar(object $inscricao, ?object $outra, string $nomeDaAtividade): void
    {
        ConnectionManager::get('default')->transactional(function () use ($inscricao, $outra, $nomeDaAtividade) {
            $agora = DateTime::now();
            $this->PreRegistrations->updateAll(['deleted' => $agora], ['id' => $inscricao->id]);
            $eu = $this->Users->get($inscricao->user_id);
            if ($outra === null) return;

            $this->PreRegistrations->updateAll(['deleted' => $agora, 'spouse_refused_at' => $agora], ['id' => $outra->id]);
            $quemInscreveu = $this->Users->get($outra->user_id);
            $this->Logs->registrar(
                (int)$eu->id,
                (int)$eu->id,
                "Disse que não está ciente da inscrição do casal em {$nomeDaAtividade}, feita por {$quemInscreveu->name}. As duas inscrições foram canceladas."
            );
            $this->Logs->registrar(
                (int)$quemInscreveu->id,
                (int)$eu->id,
                "{$eu->name} disse que não está ciente da inscrição do casal em {$nomeDaAtividade}. As duas inscrições foram canceladas."
            );
        });
    }

    /** Quem inscreveu cancelou enquanto o cônjuge não confirmava: a do cônjuge sai junto. */
    public function cancelarJunto(object $inscricao): void
    {
        if (!$inscricao->awaiting_spouse) return;
        $outra = $this->outraDoCasal($inscricao);
        if ($outra && $outra->awaiting_spouse) {
            $this->PreRegistrations->updateAll(['deleted' => DateTime::now()], ['id' => $outra->id]);
        }
    }

    /** Quem inscreveu viu o aviso de que o cônjuge recusou. */
    public function marcarRecusaVista(int $inscricaoId, int $userId): bool
    {
        return $this->PreRegistrations->updateAll(
            ['refusal_seen_at' => DateTime::now()],
            ['id' => $inscricaoId, 'user_id' => $userId, 'spouse_refused_at IS NOT' => null]
        ) > 0;
    }
}
