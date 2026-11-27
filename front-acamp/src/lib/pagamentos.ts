/**
 * Pagamento registrado à mão pela organização (handoff design_handoff_painel_evento).
 * O servidor manda o resumo em cada inscrição (PagamentoService::resumo); aqui
 * ficam só os textos e as contas de data das telas.
 */

export type SituacaoPagamento = 'nao_pago' | 'pago' | 'perdeu_vaga';

export interface ResumoPagamento {
	valor: number;
	/** null = a inscrição não entra no pagamento (gratuita ou encerrada sem pagamento). */
	situacao: SituacaoPagamento | null;
	/** Já chegou a hora de pagar (o prazo vale para ela). */
	precisaPagar: boolean;
	/** Tem vaga (sorteado, indicado, chamado; evento concluído): conta no "A receber". */
	temVaga: boolean;
	prazo: string | null;
	/** Prazo + 2 dias de folga: quando a vaga sai, se não pagar. */
	limite: string | null;
	pagoEm: string | null;
	forma: 'dinheiro' | 'pix' | null;
	registradoPor: string | null;
	registradoEm: string | null;
	observacao: string | null;
	/** Entrou numa vaga já paga: o pagamento é o de quem saiu (não é dinheiro novo). */
	herdado: boolean;
	/** Nome de quem saiu e deixou o pagamento (só no painel). */
	herdadoDe?: string | null;
	/** Pagou e depois saiu da vaga (qualquer motivo): o pagamento fica na vaga. */
	saiuEm: string | null;
	/** Para quem o pagamento passou (null = ninguém chamado ainda; só no painel). */
	passouPara?: string | null;
	perdeuEm: string | null;
}

export const FORMAS: Record<string, string> = { dinheiro: 'Dinheiro', pix: 'Pix' };

export const SITUACAO: Record<SituacaoPagamento, { texto: string; tom: 'esp' | 'ok' | 'can' }> = {
	nao_pago: { texto: 'Não pago', tom: 'esp' },
	pago: { texto: 'Pago', tom: 'ok' },
	perdeu_vaga: { texto: 'Perdeu a vaga', tom: 'can' }
};

const p2 = (n: number) => String(n).padStart(2, '0');
/** "2026-11-20" ou "2026-11-20T23:59" → Date local. */
export const paraData = (s: string) => new Date(s.length <= 10 ? `${s}T00:00` : s);
export const dm = (s: string) => {
	const x = paraData(s);
	return `${p2(x.getDate())}/${p2(x.getMonth() + 1)}`;
};
export const dma = (s: string) => {
	const x = paraData(s);
	return `${p2(x.getDate())}/${p2(x.getMonth() + 1)}/${x.getFullYear()}`;
};
export const reais = (v: number) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

/** O prazo já passou, mas a vaga ainda não saiu (os 2 dias de folga). */
export function naFolga(p: ResumoPagamento, agora: Date): boolean {
	return (
		p.situacao === 'nao_pago' &&
		p.precisaPagar &&
		!!p.prazo &&
		!!p.limite &&
		agora > paraData(p.prazo) &&
		agora <= paraData(p.limite)
	);
}

/** A linha curta da coluna "Pagamento" de Inscritos e Sorteios. */
export function linhaCurta(p: ResumoPagamento, evento: boolean, agora: Date): string {
	if (p.situacao === 'pago' && p.herdado) return p.herdadoDe ? `vaga paga por ${p.herdadoDe.split(' ')[0]}` : 'vaga já paga';
	if (p.situacao === 'pago') {
		const forma = p.forma ? ` · ${FORMAS[p.forma]}` : '';
		return p.pagoEm ? `em ${dm(p.pagoEm)}${forma}` : 'pago';
	}
	if (p.situacao === 'perdeu_vaga') return p.perdeuEm ? `vaga saiu em ${dm(p.perdeuEm)}` : 'vaga saiu';
	if (!p.precisaPagar) return evento ? 'depois de concluir a inscrição' : 'depois do formulário';
	if (!p.prazo) return 'sem prazo';
	if (naFolga(p, agora) && p.limite) return `prazo era ${dm(p.prazo)} · sai em ${dm(p.limite)}`;
	return `até ${dm(p.prazo)}`;
}

/** "Pago em 18/11/2026 · Pix · registrado por Ana". */
export function linhaDoPago(p: ResumoPagamento): string {
	if (p.herdado) {
		const herdou = [p.herdadoDe ? `Vaga paga por ${p.herdadoDe}, que saiu` : 'Vaga já paga por quem saiu'];
		if (p.pagoEm) herdou.push(`chamado(a) em ${dma(p.pagoEm)}`);
		if (p.registradoPor) herdou.push(`por ${p.registradoPor}`);
		return herdou.join(' · ');
	}
	const partes = [p.pagoEm ? `Pago em ${dma(p.pagoEm)}` : 'Pago'];
	if (p.forma) partes.push(FORMAS[p.forma]);
	if (p.registradoPor) partes.push(`registrado por ${p.registradoPor}`);
	return partes.join(' · ');
}

/**
 * Aviso de quem pagou e depois saiu: o pagamento fica na vaga e passa para o
 * suplente chamado no lugar (não há devolução). Evento não tem suplente: a vaga
 * volta para as inscrições.
 */
export function avisoDeQuemSaiu(p: ResumoPagamento, evento: boolean): string | null {
	if (p.situacao !== 'pago' || !p.saiuEm) return null;
	const saiu = `Saiu da vaga em ${dma(p.saiuEm)}.`;
	if (evento) return `${saiu} O pagamento fica com a organização.`;
	if (p.passouPara) return `${saiu} O pagamento passou para ${p.passouPara}, que entrou na vaga.`;
	return `${saiu} O pagamento passa para o suplente que for chamado para a vaga.`;
}

/**
 * Os números da janela Pagamentos. "Recebido": todo pagamento registrado, menos
 * o de quem entrou numa vaga já paga (não é dinheiro novo; o de quem saiu fica,
 * sem devolução). "A receber": só de quem tem vaga e não pagou — suplente na
 * fila e inscrito antes do sorteio ainda não devem nada.
 */
export function totaisDosPagamentos(lista: ResumoPagamento[]): { recebido: number; aReceber: number } {
	return {
		recebido: lista.filter((p) => p.situacao === 'pago' && !p.herdado).reduce((s, p) => s + p.valor, 0),
		aReceber: lista.filter((p) => p.situacao === 'nao_pago' && p.temVaga).reduce((s, p) => s + p.valor, 0)
	};
}

/** Pagamento que já passou para quem entrou na vaga não se desfaz antes do dessa pessoa. */
export function podeDesfazer(p: ResumoPagamento): boolean {
	return p.situacao === 'pago' && !p.passouPara;
}
