import { apiFetch } from '$lib/api';

export const TIPOS_PERGUNTA = [
	{ value: 'texto_curto', label: 'Texto curto' },
	{ value: 'texto_longo', label: 'Texto longo' },
	{ value: 'numero', label: 'Número' },
	{ value: 'data', label: 'Data' },
	{ value: 'sim_nao', label: 'Sim / Não' },
	{ value: 'unica_escolha', label: 'Escolha única (rádio)' },
	{ value: 'multipla_escolha', label: 'Múltipla escolha (caixas)' },
	{ value: 'lista_suspensa', label: 'Lista suspensa' }
];

export const TIPOS_COM_OPCOES = ['unica_escolha', 'multipla_escolha', 'lista_suspensa'];

export interface Opcao {
	id: number;
	text: string;
}

export interface Pergunta {
	id: number;
	category_id: number;
	subscription_type: 'Campista' | 'Servo';
	text: string;
	type: string;
	is_required: boolean;
	order: number;
	depends_on_option_id: number | null;
	section: { id: number; titulo: string };
	options: Opcao[];
}

export interface Secao {
	id: number;
	titulo: string;
	ordem: number;
}

export async function getPerguntas(categoryId: number, subscriptionType: string): Promise<{ data?: Pergunta[]; error?: string }> {
	const res = await apiFetch(`/admin/perguntas?category_id=${categoryId}&subscription_type=${subscriptionType}`);
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao carregar perguntas' };
}

export async function criarPergunta(payload: Record<string, unknown>): Promise<{ data?: Pergunta; error?: string }> {
	const res = await apiFetch('/admin/perguntas', { method: 'POST', body: JSON.stringify(payload) });
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao criar pergunta' };
}

export async function editarPergunta(id: number, payload: Record<string, unknown>): Promise<{ data?: Pergunta; error?: string }> {
	const res = await apiFetch(`/admin/perguntas/${id}`, { method: 'PATCH', body: JSON.stringify(payload) });
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao atualizar pergunta' };
}

export async function excluirPergunta(id: number): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/admin/perguntas/${id}`, { method: 'DELETE' });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao excluir pergunta' };
}

export async function getSecoes(): Promise<{ data?: Secao[]; error?: string }> {
	const res = await apiFetch('/admin/secoes');
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao carregar seções' };
}

export async function criarSecao(titulo: string): Promise<{ data?: Secao; error?: string }> {
	const res = await apiFetch('/admin/secoes', { method: 'POST', body: JSON.stringify({ titulo }) });
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao criar seção' };
}

export async function editarSecao(id: number, titulo: string): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/admin/secoes/${id}`, { method: 'PATCH', body: JSON.stringify({ titulo }) });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao atualizar seção' };
}

export async function excluirSecao(id: number): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/admin/secoes/${id}`, { method: 'DELETE' });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao excluir seção' };
}
