import { apiFetch, API_BASE_URL } from '$lib/api';

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
	/** Escolha: id da opção (múltipla: JSON de ids). Arquivo: id do arquivo. */
	answer: string | null;
	/** Pergunta de arquivo: o arquivo atual (já sobe ao ser escolhido, antes do envio). */
	arquivo: ArquivoEnviado | null;
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

/** Arquivo enviado numa pergunta do tipo "arquivo" (sem o arquivo em si: ele só abre no painel). */
export interface ArquivoEnviado {
	id: number;
	nome: string;
	tipo: 'imagem' | 'pdf';
	/** Em bytes. */
	tamanho: number;
	enviado_em: string | null;
}

/** Pedido de correção aberto (a pessoa não vê o nome de quem pediu). */
export interface CorrecaoPedida {
	id: number;
	pedida_em: string | null;
	motivo: string;
	/** Perguntas que a organização marcou para corrigir. */
	perguntas: number[];
	/** Corrigir até (AAAA-MM-DDTHH:mm). */
	prazo: string | null;
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
	/** Último dia (YYYY-MM-DD). */
	fim: string | null;
}

export interface FormularioData {
	status: string;
	/** Inscrição em evento: o formulário faz parte da inscrição, que só conclui no envio. */
	eh_evento: boolean;
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
	/** Primeiro envio (YYYY-MM-DDTHH:mm); null em envios de antes de o sistema guardar a data. */
	enviado_em: string | null;
	total_perguntas: number;
	prazo: PrazoFormulario | null;
	perfil: PerfilConfirmacao;
	/** A organização pediu correção: o formulário reabre até o prazo da correção. */
	correcao: CorrecaoPedida | null;
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
): Promise<{ success: boolean; error?: string; pagamento?: PagamentoInscricao; correcaoEnviada?: boolean }> {
	const res = await apiFetch(`/inscricoes/${preRegId}/formulario`, {
		method: 'POST',
		body: JSON.stringify({ answers })
	});
	const data = await res.json();
	if (res.ok) return { success: true, pagamento: data.pagamento, correcaoEnviada: !!data.correcao_enviada };
	return { success: false, error: data.error || 'Erro ao salvar formulário' };
}

/** Foto ou PDF, até 5 MB (o servidor confere de novo pelo conteúdo). */
export const TAMANHO_MAXIMO_ARQUIVO = 5 * 1024 * 1024;
export const TIPOS_DE_ARQUIVO = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

/** Envio em andamento: `cancelar()` interrompe; `resultado` diz como terminou. */
export interface EnvioDeArquivo {
	cancelar: () => void;
	resultado: Promise<{ data?: ArquivoEnviado; error?: string; conexao?: boolean; cancelado?: boolean }>;
}

/**
 * Envia o arquivo de uma pergunta na hora em que a pessoa escolhe. Usa
 * XMLHttpRequest (e não fetch) para mostrar o progresso do envio.
 */
export function enviarArquivo(
	preRegId: number | string,
	questionId: number,
	arquivo: File,
	progresso: (pct: number) => void
): EnvioDeArquivo {
	const xhr = new XMLHttpRequest();
	const resultado = new Promise<Awaited<EnvioDeArquivo['resultado']>>((resolve) => {
		const corpo = new FormData();
		corpo.append('question_id', String(questionId));
		corpo.append('arquivo', arquivo);
		xhr.open('POST', `${API_BASE_URL}/inscricoes/${preRegId}/arquivo`);
		xhr.setRequestHeader('Accept', 'application/json');
		const token = localStorage.getItem('token');
		if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`);
		xhr.upload.onprogress = (e) => {
			if (e.lengthComputable) progresso(Math.min(99, Math.round((e.loaded * 100) / e.total)));
		};
		xhr.onload = () => {
			let data: { data?: ArquivoEnviado; error?: string } = {};
			try {
				data = JSON.parse(xhr.responseText);
			} catch {
				/* resposta sem JSON (ex.: limite do servidor) */
			}
			if (xhr.status >= 200 && xhr.status < 300 && data.data) {
				progresso(100);
				resolve({ data: data.data });
			} else if (xhr.status === 413) {
				resolve({ error: 'O arquivo é grande demais. Envie uma foto ou PDF de até 5 MB.' });
			} else {
				resolve({ error: data.error || 'Não foi possível enviar o arquivo. Tente de novo.' });
			}
		};
		xhr.onerror = () => resolve({ conexao: true });
		xhr.onabort = () => resolve({ cancelado: true });
		xhr.send(corpo);
	});
	return { cancelar: () => xhr.abort(), resultado };
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
