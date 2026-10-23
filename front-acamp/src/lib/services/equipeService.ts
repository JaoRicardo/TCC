import { apiFetch } from '$lib/api';

export interface PessoaBasica {
	user_id: number;
	nome: string;
	cpf: string;
}

export interface Setor {
	id: number | null;
	categories_sectors_id: number;
	sector_id: number;
	sector_name: string;
	sector_place: string;
	category_id: number;
	category_name: string;
	default_vacancies: number;
	actual_vacancies: number;
	vagas_faltando: number;
	linked: boolean;
	coordenadores: PessoaBasica[];
	membros: PessoaBasica[];
}

export interface MeuPapel {
	is_admin: boolean;
	is_servo_geral: boolean;
	lidera_setores_ids: number[];
}

export interface EquipesAtividade {
	setores: Setor[];
	servo_geral: PessoaBasica[];
	servos_inscritos: PessoaBasica[];
	meu_papel: MeuPapel;
}

export async function getEquipesAtividade(activityId: number | string): Promise<{ data?: EquipesAtividade; error?: string }> {
	const res = await apiFetch(`/activities/${activityId}/sectors`);
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao carregar equipes' };
}

export async function addSectorMember(
	activityId: number | string,
	acsId: number,
	userId: number,
	isCoordinator: boolean
): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/activities/${activityId}/sectors/${acsId}/members`, {
		method: 'POST',
		body: JSON.stringify({ user_id: userId, is_coordinator: isCoordinator })
	});
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao adicionar à equipe' };
}

export async function removeSectorMember(
	activityId: number | string,
	acsId: number,
	userId: number
): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/activities/${activityId}/sectors/${acsId}/members/${userId}`, { method: 'DELETE' });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao remover da equipe' };
}

export async function addGeneralServant(
	activityId: number | string,
	userId: number
): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/activities/${activityId}/general-servants`, {
		method: 'POST',
		body: JSON.stringify({ user_id: userId })
	});
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao designar servo geral' };
}

export async function removeGeneralServant(
	activityId: number | string,
	userId: number
): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/activities/${activityId}/general-servants/${userId}`, { method: 'DELETE' });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao remover servo geral' };
}

export async function linkActivitySector(
	activityId: number | string,
	categoriesSectorsId: number,
	actualVacancies: number
): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/activities/${activityId}/sectors`, {
		method: 'POST',
		body: JSON.stringify({
			categories_sectors_id: categoriesSectorsId,
			actual_vacancies: actualVacancies
		})
	});
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao vincular setor à atividade' };
}

export async function unlinkActivitySector(activityId: number | string, acsId: number): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/activities/${activityId}/sectors/${acsId}`, { method: 'DELETE' });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao remover setor da atividade' };
}

export interface MinhasEquipes {
	servo_geral: { activity_id: number; activity_name: string }[];
	lideranca: { activities_categories_sector_id: number; activity_id: number; activity_name: string; sector_id: number; sector_name: string }[];
	equipes: { activities_categories_sector_id: number; activity_id: number; activity_name: string; sector_id: number; sector_name: string }[];
}

export async function getMinhasEquipes(): Promise<{ data?: MinhasEquipes; error?: string }> {
	const res = await apiFetch('/me/equipes');
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao carregar suas equipes' };
}
