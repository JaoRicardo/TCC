import { getPendenciasDoCasal, type CasalPendente, type CasalRecusa } from '$lib/services/casalService';

/**
 * Janela do casal (acampamento de Casais), montada no layout raiz: aparece em
 * qualquer tela para quem precisa confirmar a inscrição feita pelo cônjuge, e
 * para quem inscreveu quando o cônjuge recusou. Não há "responder depois":
 * a janela fica aberta até a pessoa responder.
 */
class CasalStore {
	pendentes = $state<CasalPendente[]>([]);
	recusas = $state<CasalRecusa[]>([]);
	/** Sobe a cada resposta: a página da atividade recarrega a inscrição. */
	respostas = $state(0);

	async carregar() {
		const r = await getPendenciasDoCasal();
		this.pendentes = r.pendentes;
		this.recusas = r.recusas;
	}

	limpar() {
		this.pendentes = [];
		this.recusas = [];
	}
}

export const casal = new CasalStore();
