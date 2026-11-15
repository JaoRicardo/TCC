import { apiFetch } from '$lib/api';

/** Tipos de resposta: nome na tela e o ícone do cartão da pergunta. */
export const TIPOS_PERGUNTA = [
	{ value: 'texto_curto', label: 'Texto curto', icone: 'Aa' },
	{ value: 'texto_longo', label: 'Texto longo', icone: '¶' },
	{ value: 'numero', label: 'Número', icone: '12' },
	{ value: 'data', label: 'Data', icone: '31' },
	{ value: 'sim_nao', label: 'Sim / Não', icone: 'S/N' },
	{ value: 'unica_escolha', label: 'Escolha única', icone: '◉' },
	{ value: 'multipla_escolha', label: 'Múltipla escolha', icone: '✓' },
	{ value: 'lista_suspensa', label: 'Lista suspensa', icone: '▾' }
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
	/** Quantas pessoas já responderam esta pergunta. */
	respostas: number;
}

/** Opção como vai para o servidor ao salvar: id null = opção nova. */
export interface OpcaoParaSalvar {
	id: number | null;
	text: string;
}

export interface Secao {
	id: number;
	titulo: string;
	ordem: number;
}

/** Acampamento da categoria com o prazo do formulário ainda aberto. */
export interface UsoDoFormulario {
	atividade_id: number;
	nome: string;
	prazo: string;
	enviados: number;
	total: number;
}

/** Um formulário de acampamento (categoria × Campista/Servo) na lista da tela Formulários. */
export interface ResumoFormulario {
	category_id: number;
	categoria: string;
	subscription_type: 'Campista' | 'Servo';
	perguntas: number;
	/** Seções que têm pergunta neste formulário. */
	secoes: number[];
	uso: UsoDoFormulario | null;
}

export async function getResumoFormularios(): Promise<{ data?: ResumoFormulario[]; error?: string }> {
	const res = await apiFetch('/admin/formularios/resumo');
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao carregar os formulários' };
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

/** "Desfazer" depois de excluir: volta a pergunta e as condicionais que saíram junto. */
export async function restaurarPergunta(id: number): Promise<{ data?: Pergunta[]; error?: string }> {
	const res = await apiFetch(`/admin/perguntas/${id}/restaurar`, { method: 'POST' });
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao desfazer a exclusão' };
}

/** Copia perguntas de outro formulário para o fim das seções deste. */
export async function importarPerguntas(payload: {
	category_id: number;
	subscription_type: 'Campista' | 'Servo';
	question_ids: number[];
}): Promise<{ data?: Pergunta[]; error?: string }> {
	const res = await apiFetch('/admin/formularios/importar', { method: 'POST', body: JSON.stringify(payload) });
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao importar as perguntas' };
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

export async function editarSecao(id: number, dados: { titulo?: string; ordem?: number }): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/admin/secoes/${id}`, { method: 'PATCH', body: JSON.stringify(dados) });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao atualizar seção' };
}

/** Exclui as perguntas da seção só neste formulário. `quando` volta no "Desfazer". */
export async function excluirPerguntasDaSecao(
	secaoId: number,
	formulario: { category_id: number; subscription_type: 'Campista' | 'Servo' }
): Promise<{ data?: { excluidas: number; quando: string }; error?: string }> {
	const res = await apiFetch(`/admin/secoes/${secaoId}/excluir-perguntas`, { method: 'POST', body: JSON.stringify(formulario) });
	const data = await res.json();
	if (res.ok) return { data: { excluidas: data.excluidas, quando: data.quando } };
	return { error: data.error || 'Erro ao excluir as perguntas da seção' };
}

export async function restaurarPerguntasDaSecao(
	secaoId: number,
	formulario: { category_id: number; subscription_type: 'Campista' | 'Servo'; quando: string }
): Promise<{ data?: Pergunta[]; error?: string }> {
	const res = await apiFetch(`/admin/secoes/${secaoId}/restaurar-perguntas`, { method: 'POST', body: JSON.stringify(formulario) });
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao desfazer a exclusão' };
}

export async function excluirSecao(id: number): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/admin/secoes/${id}`, { method: 'DELETE' });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao excluir seção' };
}
