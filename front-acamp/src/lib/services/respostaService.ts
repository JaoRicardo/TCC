import { apiFetch } from '$lib/api';
import { paramsDoAlvo, type AlvoFormulario } from '$lib/formularios';
import type { DocumentoResumo } from '$lib/services/formularioService';

/** Um acampamento da categoria, para o filtro "Acampamento" da aba Respostas. */
export interface Edicao {
	id: number;
	nome: string;
	inicio: string;
	fim: string;
	/** Prazo do formulário deste tipo (Campista ou Servo). */
	prazo: string | null;
	/** Prazo ainda aberto. */
	aberta: boolean;
}

export interface PerguntaDoFormulario {
	id: number;
	text: string;
	type: string;
	is_required: boolean;
	depends_on_option_id: number | null;
	section: { id: number; titulo: string };
	options: { id: number; text: string }[];
}

export interface PessoaComRespostas {
	inscricao_id: number;
	nome: string;
	idade: number | null;
	telefone: string | null;
	email: string | null;
	/** Acampamento: Sorteado/Indicado/Suplente. Evento (sem sorteio): Inscrito/Em andamento. */
	situacao: 'Sorteado' | 'Indicado' | 'Suplente' | 'Inscrito' | 'Em andamento';
	enviou: boolean;
	/** Quando foi sorteada, indicada ou entrou na fila de suplentes (null em inscrições antigas). */
	confirmado_em: string | null;
	/** Primeiro envio do formulário (null em envios antigos). */
	enviado_em: string | null;
	/** Última vez que mexeu nas respostas. */
	atualizado_em: string | null;
	/** id da pergunta → resposta gravada (escolha = id da opção; múltipla = JSON de ids). */
	respostas: Record<string, string>;
	/** Só vem para quem tem a permissão "documentos" (null = não enviou). */
	documento?: DocumentoResumo | null;
}

export interface RespostasDoFormulario {
	edicoes: Edicao[];
	atividade_id: number | null;
	prazo: string | null;
	perguntas: PerguntaDoFormulario[];
	pessoas: PessoaComRespostas[];
}

export async function getRespostas(
	alvo: AlvoFormulario,
	atividadeId?: number | null
): Promise<{ data?: RespostasDoFormulario; error?: string }> {
	const params = new URLSearchParams(Object.entries(paramsDoAlvo(alvo)).map(([k, v]) => [k, String(v)]));
	if (atividadeId) params.set('atividade_id', String(atividadeId));
	const res = await apiFetch(`/admin/formularios/respostas?${params}`);
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao carregar as respostas' };
}

/**
 * Abre o arquivo do documento numa aba nova. O arquivo só sai pela rota
 * protegida (com o token), então vem como blob e não como link direto.
 */
export async function abrirDocumento(id: number): Promise<{ error?: string }> {
	const aba = window.open('', '_blank');
	const res = await apiFetch(`/admin/documentos/${id}/arquivo`);
	if (!res.ok) {
		aba?.close();
		return { error: 'Não foi possível abrir o documento.' };
	}
	const url = URL.createObjectURL(await res.blob());
	if (aba) aba.location.href = url;
	else window.location.href = url;
	setTimeout(() => URL.revokeObjectURL(url), 60_000);
	return {};
}
