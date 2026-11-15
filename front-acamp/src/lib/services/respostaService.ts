import { apiFetch } from '$lib/api';
import type { TipoInscricao } from '$lib/formularios';

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
	situacao: 'Sorteado' | 'Indicado' | 'Suplente';
	enviou: boolean;
	/** Quando foi sorteada, indicada ou entrou na fila de suplentes (null em inscrições antigas). */
	confirmado_em: string | null;
	/** Primeiro envio do formulário (null em envios antigos). */
	enviado_em: string | null;
	/** Última vez que mexeu nas respostas. */
	atualizado_em: string | null;
	/** id da pergunta → resposta gravada (escolha = id da opção; múltipla = JSON de ids). */
	respostas: Record<string, string>;
}

export interface RespostasDoFormulario {
	edicoes: Edicao[];
	atividade_id: number | null;
	prazo: string | null;
	perguntas: PerguntaDoFormulario[];
	pessoas: PessoaComRespostas[];
}

export async function getRespostas(
	categoryId: number,
	tipo: TipoInscricao,
	atividadeId?: number | null
): Promise<{ data?: RespostasDoFormulario; error?: string }> {
	const params = new URLSearchParams({ category_id: String(categoryId), subscription_type: tipo });
	if (atividadeId) params.set('atividade_id', String(atividadeId));
	const res = await apiFetch(`/admin/formularios/respostas?${params}`);
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao carregar as respostas' };
}
