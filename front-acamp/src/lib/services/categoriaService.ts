import { apiFetch } from '$lib/api';
import type { CategoriaDaLista, RegrasDaCategoria } from '$lib/categorias';

/** Tela Gestão › Categorias (permissão "categorias", `CategoriesController` no servidor). */

export type Resultado<T = any> =
	| { ok: true; data: T }
	| { ok: false; error: string; codigo?: string; erros?: Record<string, string>; status: number };

async function chamar<T = any>(caminho: string, opcoes: RequestInit = {}): Promise<Resultado<T>> {
	try {
		const res = await apiFetch(caminho, opcoes);
		const dados = await res.json().catch(() => ({}));
		if (res.ok) return { ok: true, data: dados.data ?? dados };
		return { ok: false, error: dados.error ?? '', codigo: dados.codigo, erros: dados.erros, status: res.status };
	} catch {
		return { ok: false, error: '', status: 0 };
	}
}

const json = (metodo: string, corpo?: unknown): RequestInit => ({
	method: metodo,
	...(corpo === undefined ? {} : { body: JSON.stringify(corpo) })
});

/**
 * O que a janela de criar/editar manda. `depois` (só na escada) = a categoria
 * que fica logo abaixo; null = primeiro degrau.
 */
export type DadosDaCategoria = Pick<
	RegrasDaCategoria,
	'nome' | 'idadeMinima' | 'idadeMaxima' | 'naEscada' | 'requerCategoriaId' | 'soCasais' | 'aceitaCasados' | 'preenchidoPeloResponsavel'
> & { depois?: number | null };

export const listarCategorias = () => chamar<CategoriaDaLista[]>('/admin/categorias');

export const criarCategoria = (dados: DadosDaCategoria) => chamar<RegrasDaCategoria>('/admin/categorias', json('POST', dados));

export const editarCategoria = (id: number, dados: DadosDaCategoria) =>
	chamar<RegrasDaCategoria>(`/admin/categorias/${id}`, json('PATCH', dados));

/** As em uso na ordem nova (escada do degrau mais novo ao mais velho, depois as de fora). */
export const reordenarCategorias = (ids: number[]) => chamar<CategoriaDaLista[]>('/admin/categorias/ordem', json('POST', { ids }));

export const arquivarCategoria = (id: number) => chamar<RegrasDaCategoria>(`/admin/categorias/${id}/arquivar`, json('POST'));

/**
 * Reativa. Na escada, `depois` escolhe o degrau; `fim` manda a de fora da
 * escada para o fim. Sem nada, volta no mesmo lugar ("Desfazer").
 */
export const desarquivarCategoria = (id: number, opcoes: { depois?: number | null; fim?: boolean } = {}) =>
	chamar<RegrasDaCategoria>(`/admin/categorias/${id}/desarquivar`, json('POST', opcoes));

export const excluirCategoria = (id: number) => chamar(`/admin/categorias/${id}`, json('DELETE'));
