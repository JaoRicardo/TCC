import { apiFetch } from '$lib/api';

export interface OpcaoPergunta {
	id: number;
	text: string;
}

export interface PerguntaFormulario {
	id: number;
	text: string;
	type: string;
	isRequired: boolean;
	order: number;
	dependsOnOptionId: number | null;
	options: OpcaoPergunta[];
	answer: string | null;
}

export interface SecaoFormulario {
	id: number;
	title: string;
	order: number;
	questions: PerguntaFormulario[];
}

export interface PerfilConfirmacao {
	nome: string;
	cpf: string;
	telefone: string;
	email: string;
	data_nascimento: string | null;
	sexo: string;
	estado_civil: string | null;
	endereco: { rua: string; numero: string; bairro: string; cidade: string; cep: string } | null;
}

export interface PrazoFormulario {
	/** Data-limite para responder, no formato YYYY-MM-DDTHH:mm. */
	limite: string;
	vencido: boolean;
	/** Suplente responde no mesmo prazo, mas a consequência de perder é outra. */
	eh_suplente: boolean;
}

export interface PagamentoInscricao {
	necessario: boolean;
	valor: number;
	pago: boolean;
	/** Suplente responde o formulário mas não paga a taxa. */
	eh_suplente: boolean;
	/** A data de pagamento cadastrada na atividade já passou. */
	prazo_vencido: boolean;
	/** Pode pagar agora: tem taxa, formulário enviado, não é suplente e o prazo está aberto. */
	liberado: boolean;
	/** false enquanto a organização não configurar as credenciais do Mercado Pago. */
	integracao_ativa: boolean;
	status_provedor: string | null;
	url_pagamento: string | null;
	/** Link de pagamento cadastrado à mão na atividade, usado como alternativa. */
	link_manual: string | null;
	prazo: string | null;
}

export interface AtividadeFormulario {
	id: number;
	nome: string;
	categoria: string | null;
	local: string | null;
	inicio: string | null;
}

export interface FormularioData {
	status: string;
	atividade: AtividadeFormulario | null;
	/** Existe formulário para esta pessoa ver (ela foi selecionada E há perguntas). */
	formulario_disponivel: boolean;
	/** A organização já cadastrou perguntas para este acampamento. */
	formulario_montado: boolean;
	/** Ainda dá para responder/alterar (prazo aberto). */
	editavel: boolean;
	/** Explicação pronta de por que não é possível responder agora. */
	motivo_indisponivel: string | null;
	respondido: boolean;
	total_perguntas: number;
	prazo: PrazoFormulario | null;
	perfil: PerfilConfirmacao;
	sections: SecaoFormulario[];
	pagamento: PagamentoInscricao | null;
}

export async function getFormulario(
	preRegId: number | string
): Promise<{ data?: FormularioData; error?: string }> {
	const res = await apiFetch(`/inscricoes/${preRegId}/formulario`);
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao carregar formulário' };
}

export async function salvarFormulario(
	preRegId: number | string,
	answers: { question_id: number; answer: string }[]
): Promise<{ success: boolean; error?: string; pagamento?: PagamentoInscricao }> {
	const res = await apiFetch(`/inscricoes/${preRegId}/formulario`, {
		method: 'POST',
		body: JSON.stringify({ answers })
	});
	const data = await res.json();
	if (res.ok) return { success: true, pagamento: data.pagamento };
	return { success: false, error: data.error || 'Erro ao salvar formulário' };
}

/**
 * Cria a cobrança no Mercado Pago e devolve a URL da tela de pagamento
 * (Checkout Pro). Quem confirma o pagamento é o backend consultando a API do
 * Mercado Pago, não o retorno dessa navegação.
 */
export async function iniciarPagamento(
	preRegId: number | string
): Promise<{ url?: string; valor?: number; error?: string }> {
	const res = await apiFetch(`/inscricoes/${preRegId}/pagamento`, { method: 'POST' });
	const data = await res.json();
	if (res.ok) return { url: data.data?.url_pagamento, valor: data.data?.valor };
	return { error: data.error || 'Erro ao iniciar o pagamento' };
}

export interface SituacaoPagamento {
	pago: boolean;
	eh_suplente: boolean;
	valor: number;
	status_provedor: string | null;
	metodo: string | null;
	pago_em: string | null;
	url_pagamento: string | null;
}

/**
 * Na volta do checkout: pede ao backend para consultar no Mercado Pago o
 * pagamento cujo id veio na URL. Só o id é usado; o status vem da API.
 */
export async function confirmarPagamentoRetorno(
	preRegId: number | string,
	paymentId: string
): Promise<{ data?: SituacaoPagamento; error?: string }> {
	const res = await apiFetch(`/inscricoes/${preRegId}/pagamento/confirmar`, {
		method: 'POST',
		body: JSON.stringify({ payment_id: paymentId })
	});
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao confirmar o pagamento' };
}

export async function getSituacaoPagamento(
	preRegId: number | string
): Promise<{ data?: SituacaoPagamento; error?: string }> {
	const res = await apiFetch(`/inscricoes/${preRegId}/pagamento`);
	const data = await res.json();
	if (res.ok) return { data: data.data };
	return { error: data.error || 'Erro ao consultar o pagamento' };
}
