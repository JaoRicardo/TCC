import { apiFetch } from '$lib/api';
import { paramsDoAlvo, type AlvoFormulario } from '$lib/formularios';
import type { ArquivoEnviado } from '$lib/services/formularioService';

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
	/** id da pergunta → resposta gravada (escolha = id da opção; múltipla = JSON de ids; arquivo = id do arquivo). */
	respostas: Record<string, string>;
	/** id da pergunta de arquivo → o arquivo enviado. */
	arquivos: Record<string, ArquivoEnviado>;
	/** Pedido de correção aberto. */
	correcao: CorrecaoDoPainel | null;
	/** Cancelada pela organização ou recusada (correção não feita até o início). */
	encerrada: InscricaoEncerrada | null;
	/** Nome do cônjuge (casal: sai junto se a inscrição for cancelada). */
	conjuge: string | null;
}

export interface CorrecaoDoPainel {
	id: number;
	pedida_em: string | null;
	/** Quem pediu. */
	por: string | null;
	motivo: string;
	perguntas: number[];
	prazo: string | null;
	atrasada: boolean;
}

export interface InscricaoEncerrada {
	situacao: 'Cancelada pela organização' | 'Recusada';
	em: string;
	por: string | null;
	motivo: string;
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

/** Pede à pessoa que corrija o formulário (prazo: AAAA-MM-DD). */
export async function pedirCorrecao(
	inscricaoId: number,
	dados: { motivo: string; perguntas: number[]; prazo: string }
): Promise<{ correcao?: CorrecaoDoPainel; error?: string }> {
	const res = await apiFetch(`/admin/inscricoes/${inscricaoId}/correcao`, { method: 'POST', body: JSON.stringify(dados) });
	const data = await res.json().catch(() => ({}));
	if (res.ok) return { correcao: data.data };
	return { error: data.error || 'Não foi possível pedir a correção. Verifique sua conexão e tente de novo.' };
}

export async function desfazerCorrecao(inscricaoId: number): Promise<{ error?: string }> {
	const res = await apiFetch(`/admin/inscricoes/${inscricaoId}/correcao`, { method: 'DELETE' });
	const data = await res.json().catch(() => ({}));
	if (res.ok) return {};
	return { error: data.error || 'Não foi possível desfazer o pedido. Verifique sua conexão e tente de novo.' };
}

/** Cancela a inscrição pela organização (não dá para desfazer). */
export async function cancelarInscricao(
	inscricaoId: number,
	motivo: string
): Promise<{ canceladas?: string[]; evento?: boolean; error?: string }> {
	const res = await apiFetch(`/admin/inscricoes/${inscricaoId}/cancelar`, { method: 'POST', body: JSON.stringify({ motivo }) });
	const data = await res.json().catch(() => ({}));
	if (res.ok) return { canceladas: data.data.canceladas, evento: data.data.evento };
	return { error: data.error || 'Não foi possível cancelar. Verifique sua conexão e tente de novo.' };
}

/**
 * Abre o arquivo de uma resposta numa aba nova. O arquivo só sai pela rota
 * protegida (com o token), então vem como blob — um link temporário, que
 * deixa de valer em um minuto — e nunca como endereço público.
 */
export async function abrirArquivo(id: number): Promise<{ error?: string }> {
	const aba = window.open('', '_blank');
	const res = await apiFetch(`/admin/documentos/${id}/arquivo`);
	if (!res.ok) {
		aba?.close();
		return { error: 'Não foi possível abrir o arquivo.' };
	}
	const url = URL.createObjectURL(await res.blob());
	if (aba) aba.location.href = url;
	else window.location.href = url;
	setTimeout(() => URL.revokeObjectURL(url), 60_000);
	return {};
}
