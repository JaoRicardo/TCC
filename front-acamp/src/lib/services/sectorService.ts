import { apiFetch } from '$lib/api';

export interface CategoriaVinculada {
	id: number;
	category_id: number;
	category_name: string;
	category_type: string;
	vacancies: number;
}

export interface SetorCatalogo {
	id: number;
	name: string;
	place: string;
	categories: CategoriaVinculada[];
}

export async function getSectorsCatalog(): Promise<{ data?: SetorCatalogo[]; error?: string }> {
	const res = await apiFetch('/admin/sectors');
	const data = await res.json();
	if (res.ok) return { data: data.data || [] };
	return { error: data.error || 'Erro ao carregar setores' };
}

export async function createSector(name: string, place: string): Promise<{ data?: SetorCatalogo; error?: string }> {
	const res = await apiFetch('/admin/sectors', { method: 'POST', body: JSON.stringify({ name, place }) });
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao criar setor' };
}

export async function updateSector(id: number, name: string, place: string): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/admin/sectors/${id}`, { method: 'PATCH', body: JSON.stringify({ name, place }) });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao atualizar setor' };
}

export async function deleteSector(id: number): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/admin/sectors/${id}`, { method: 'DELETE' });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao excluir setor' };
}

export async function linkSectorToCategory(
	sectorId: number,
	categoryId: number,
	vacancies: number
): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/admin/sectors/${sectorId}/categories`, {
		method: 'POST',
		body: JSON.stringify({ category_id: categoryId, vacancies })
	});
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao vincular categoria' };
}

export async function updateCategoryLink(csId: number, vacancies: number): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/admin/categories-sectors/${csId}`, {
		method: 'PATCH',
		body: JSON.stringify({ vacancies })
	});
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao atualizar vínculo' };
}

export async function deleteCategoryLink(csId: number): Promise<{ success: boolean; error?: string }> {
	const res = await apiFetch(`/admin/categories-sectors/${csId}`, { method: 'DELETE' });
	const data = await res.json();
	if (res.ok) return { success: true };
	return { success: false, error: data.error || 'Erro ao remover vínculo' };
}
