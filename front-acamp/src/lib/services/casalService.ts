import { apiFetch } from '$lib/api';

/** Inscrição do casal (acampamento de Casais) que espera esta pessoa confirmar. */
export interface CasalPendente {
	id: number;
	/** Quem fez a inscrição dos dois. */
	conjuge: string;
	atividade: { id: number; nome: string; inicio: string | null; fim: string | null };
	/** Ao confirmar, o estado civil muda para `novo` (null = não muda). */
	estadoCivil: { atual: string | null; novo: string } | null;
	/** Sem cidade e CEP no cadastro: a janela pede. */
	precisaEndereco: boolean;
}

/** O cônjuge disse que não estava ciente: a inscrição feita por esta pessoa foi cancelada. */
export interface CasalRecusa {
	id: number;
	conjuge: string | null;
	atividade: { id: number; nome: string };
}

export async function getPendenciasDoCasal(): Promise<{ pendentes: CasalPendente[]; recusas: CasalRecusa[] }> {
	const res = await apiFetch('/me/casal', { redirectOnUnauthorized: false });
	if (!res.ok) return { pendentes: [], recusas: [] };
	const data = (await res.json()).data ?? {};
	return { pendentes: data.pendentes ?? [], recusas: data.recusas ?? [] };
}

export async function responderCasal(
	id: number,
	resposta: 'confirmar' | 'recusar',
	endereco?: { city: string; cep: string }
): Promise<{ ok: boolean; error?: string; errors?: Record<string, string>; estadoCivil?: string | null }> {
	const res = await apiFetch(`/inscricoes/${id}/casal`, {
		method: 'POST',
		body: JSON.stringify({ resposta, ...(endereco ?? {}) })
	});
	const data = await res.json().catch(() => ({}));
	if (res.ok) return { ok: true, estadoCivil: data.data?.estadoCivil ?? null };
	return { ok: false, error: data.error ?? 'Não foi possível responder agora. Tente de novo.', errors: data.errors };
}

export async function marcarRecusaVista(id: number): Promise<void> {
	await apiFetch(`/inscricoes/${id}/casal/visto`, { method: 'POST' });
}
