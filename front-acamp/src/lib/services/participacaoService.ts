import { apiFetch } from '$lib/api';

/**
 * Gestão › Participações anteriores (permissão "participacoes",
 * `ParticipacoesController` no servidor): quem já foi campista numa categoria
 * antes do sistema. Só de quem já tem conta (LGPD): o CPF digitado acha a conta
 * e não é guardado.
 */

export type Resultado<T = any> =
	| { ok: true; data: T }
	| { ok: false; error: string; erros?: Record<string, string>; status: number };

async function chamar<T = any>(caminho: string, opcoes: RequestInit = {}): Promise<Resultado<T>> {
	try {
		const res = await apiFetch(caminho, opcoes);
		const dados = await res.json().catch(() => ({}));
		if (res.ok) return { ok: true, data: dados.data ?? dados };
		return { ok: false, error: dados.error ?? '', erros: dados.erros, status: res.status };
	} catch {
		return { ok: false, error: '', status: 0 };
	}
}

export interface Participacao {
	id: number;
	/** 000.000.000-00, da conta. */
	cpf: string;
	/** Nome da conta. */
	nome: string;
	categoriaId: number;
	categoria: string;
	ano: number | null;
	/** A conta da pessoa (toda participação é de uma conta). */
	contaId: number;
	registradoPor: string | null;
	registradoEm: string | null;
}

export interface CategoriaDoCampo {
	id: number;
	nome: string;
	arquivada: boolean;
}

/** O que o sistema já sabe de um CPF. Sem conta, tudo vazio. */
export interface ConsultaCpf {
	conta: { id: number; nome: string } | null;
	sistema: { categoriaId: number; categoria: string; atividade: string; ano: number | null }[];
	cadastradas: { id: number; categoriaId: number; categoria: string; ano: number | null }[];
}

export interface NovaParticipacao {
	cpf: string;
	categoriaId: number | null;
	ano: string;
}

export const listarParticipacoes = () =>
	chamar<{ participacoes: Participacao[]; categorias: CategoriaDoCampo[] }>('/admin/participacoes');

export const consultarCpf = (cpf: string) => chamar<ConsultaCpf>(`/admin/participacoes/cpf/${cpf.replace(/\D/g, '')}`);

export const adicionarParticipacao = (dados: NovaParticipacao) =>
	chamar<Participacao>('/admin/participacoes', { method: 'POST', body: JSON.stringify(dados) });

export const removerParticipacao = (id: number) => chamar(`/admin/participacoes/${id}`, { method: 'DELETE' });
