import { apiFetch } from '$lib/api';

/**
 * Equipes de Servos (handoff `Desing/design_handoff_equipes_servos`): a equipe
 * de cada acampamento (painel e "Minha equipe") e o modelo de setores por
 * categoria. As regras de quem pode o quê ficam no servidor
 * (`ActivitiesController::sectors` e vizinhos, `SectorsController`).
 */

export interface PessoaDaEquipe {
	user_id: number;
	nome: string;
	lider: boolean;
	/** Entrou pelo sorteio: tirar desfaz o "Sorteado". */
	sorteado: boolean;
}

export interface SetorDaEquipe {
	/** Vínculo com o acampamento; null = setor do modelo fora do acampamento. */
	id: number | null;
	categories_sectors_id: number;
	sector_id: number;
	sector_name: string;
	sector_place: string;
	/** Vagas do modelo da categoria atual (null = o setor não está no modelo). */
	default_vacancies: number | null;
	actual_vacancies: number;
	vagas_faltando: number;
	linked: boolean;
	coordenadores: PessoaDaEquipe[];
	membros: PessoaDaEquipe[];
}

export type SituacaoNoBanco = 'insc' | 'sort' | 'sup' | 'nao' | 'ind' | 'retirado';

export interface PessoaDoBanco {
	user_id: number;
	nome: string;
	idade: number | null;
	cidade: string | null;
	situacao: SituacaoNoBanco;
	metodo: string | null;
	suplente_setor: string | null;
	suplente_pos: number | null;
	/** Só para quem tem a permissão "Equipes de Servos". */
	cpf?: string;
}

export interface EquipeDoAcampamento {
	atividade: { id: number; nome: string; category_id: number | null; categoria: string };
	sorteio: { feito: boolean; feito_em: string | null; previsto: string | null };
	setores: SetorDaEquipe[];
	servo_geral: PessoaDaEquipe[];
	/** null para o servo comum. */
	banco: PessoaDoBanco[] | null;
	meu_papel: {
		is_admin: boolean;
		is_servo_geral: boolean;
		lidera_setores_ids: number[];
		meus_setores_ids: number[];
		so_minha_equipe: boolean;
	};
}

export type Resultado<T = any> = { ok: true; data: T } | { ok: false; error: string; codigo?: string; status: number };

async function chamar<T = any>(caminho: string, opcoes: RequestInit = {}): Promise<Resultado<T>> {
	try {
		const res = await apiFetch(caminho, opcoes);
		const dados = await res.json().catch(() => ({}));
		if (res.ok) return { ok: true, data: dados.data ?? dados };
		return { ok: false, error: dados.error ?? '', codigo: dados.codigo, status: res.status };
	} catch {
		return { ok: false, error: '', status: 0 };
	}
}

const json = (metodo: string, corpo?: unknown): RequestInit => ({
	method: metodo,
	...(corpo === undefined ? {} : { body: JSON.stringify(corpo) })
});

// ─── Equipe de um acampamento ─────────────────────────────────────────────

export const lerEquipe = (atividadeId: number) => chamar<EquipeDoAcampamento>(`/activities/${atividadeId}/sectors`);

export const porNaEquipe = (atividadeId: number, acsId: number, userId: number, lider: boolean) =>
	chamar(`/activities/${atividadeId}/sectors/${acsId}/members`, json('POST', { user_id: userId, is_coordinator: lider }));

export const tirarDaEquipe = (atividadeId: number, acsId: number, userId: number) =>
	chamar(`/activities/${atividadeId}/sectors/${acsId}/members/${userId}`, json('DELETE'));

export const mudarLideranca = (atividadeId: number, acsId: number, userId: number, lider: boolean) =>
	chamar(`/activities/${atividadeId}/sectors/${acsId}/members/${userId}`, json('PATCH', { is_coordinator: lider }));

export const moverDeSetor = (atividadeId: number, deAcsId: number, userId: number, paraAcsId: number) =>
	chamar(`/activities/${atividadeId}/sectors/${deAcsId}/members/${userId}/mover`, json('POST', { para: paraAcsId }));

export const mudarVagas = (atividadeId: number, acsId: number, vagas: number) =>
	chamar(`/activities/${atividadeId}/sectors/${acsId}`, json('PATCH', { actual_vacancies: vagas }));

export const ligarSetor = (atividadeId: number, categoriesSectorsId: number, vagas: number) =>
	chamar(`/activities/${atividadeId}/sectors`, json('POST', { categories_sectors_id: categoriesSectorsId, actual_vacancies: vagas }));

export const tirarSetor = (atividadeId: number, acsId: number) => chamar(`/activities/${atividadeId}/sectors/${acsId}`, json('DELETE'));

export const aplicarModelo = (atividadeId: number) =>
	chamar<{ entraram: string[] }>(`/activities/${atividadeId}/sectors/aplicar-modelo`, json('POST'));

export const designarServoGeral = (atividadeId: number, userId: number) =>
	chamar(`/activities/${atividadeId}/general-servants`, json('POST', { user_id: userId }));

export const tirarServoGeral = (atividadeId: number, userId: number) =>
	chamar(`/activities/${atividadeId}/general-servants/${userId}`, json('DELETE'));

// ─── Minha equipe (área do participante) ──────────────────────────────────

export interface MinhaEquipe {
	activity_id: number;
	activity_name: string;
	papel: 'geral' | 'lider' | 'servo';
	setor: string | null;
	acs_id: number | null;
	resumo: { setores: number; vagas: number; preenchidas: number; meu_setor: number | null };
}

export const lerMinhasEquipes = () => chamar<MinhaEquipe[]>('/me/equipes');

// ─── Modelo por categoria ─────────────────────────────────────────────────

export interface VinculoDoModelo {
	id: number;
	category_id: number;
	category_name: string;
	vacancies: number;
}

export interface SetorDoModelo {
	id: number;
	name: string;
	place: string;
	categories: VinculoDoModelo[];
}

export const lerModelo = () => chamar<SetorDoModelo[]>('/admin/sectors');

export const criarSetor = (nome: string, local: string, vagas: Record<number, number>) =>
	chamar<{ id: number }>('/admin/sectors', json('POST', { name: nome, place: local, vagas }));

export const editarSetor = (id: number, nome: string, local: string) =>
	chamar(`/admin/sectors/${id}`, json('PATCH', { name: nome, place: local }));

export const excluirSetor = (id: number) => chamar(`/admin/sectors/${id}`, json('DELETE'));

export const ligarNaCategoria = (setorId: number, categoriaId: number, vagas: number) =>
	chamar(`/admin/sectors/${setorId}/categories`, json('POST', { category_id: categoriaId, vacancies: vagas }));

export const mudarVagasNaCategoria = (vinculoId: number, vagas: number) =>
	chamar(`/admin/categories-sectors/${vinculoId}`, json('PATCH', { vacancies: vagas }));

export const tirarDaCategoria = (vinculoId: number) => chamar(`/admin/categories-sectors/${vinculoId}`, json('DELETE'));
