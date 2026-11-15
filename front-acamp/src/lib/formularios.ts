/** Ajudas comuns à lista e ao editor da tela Formulários do painel. */

export type TipoInscricao = 'Campista' | 'Servo';

/**
 * Qual formulário: de acampamento (categoria × Campista/Servo) ou de evento
 * (modelo com nome próprio que os eventos usam).
 */
export type AlvoFormulario =
	| { tipo: 'acampamento'; categoryId: number; inscricao: TipoInscricao }
	| { tipo: 'evento'; eventFormId: number };

export const doAcampamento = (categoryId: number, inscricao: TipoInscricao): AlvoFormulario => ({
	tipo: 'acampamento',
	categoryId,
	inscricao
});

export const doEvento = (eventFormId: number): AlvoFormulario => ({ tipo: 'evento', eventFormId });

/** Como o servidor identifica o formulário: category_id + subscription_type, ou event_form_id. */
export function paramsDoAlvo(alvo: AlvoFormulario): { category_id: number; subscription_type: TipoInscricao } | { event_form_id: number } {
	return alvo.tipo === 'evento'
		? { event_form_id: alvo.eventFormId }
		: { category_id: alvo.categoryId, subscription_type: alvo.inscricao };
}

export function mesmoAlvo(a: AlvoFormulario, b: AlvoFormulario): boolean {
	if (a.tipo === 'evento' || b.tipo === 'evento') {
		return a.tipo === 'evento' && b.tipo === 'evento' && a.eventFormId === b.eventFormId;
	}
	return a.categoryId === b.categoryId && a.inscricao === b.inscricao;
}

/** "Juvenil · Campista" */
export function tituloDoFormulario(categoria: string, tipo: TipoInscricao): string {
	return `${categoria} · ${tipo}`;
}

/** Endereço do editor: /admin/formularios/{categoria}/campista|servo ou /admin/formularios/evento/{id} */
export function enderecoDoEditor(alvo: AlvoFormulario): string {
	return alvo.tipo === 'evento'
		? `/admin/formularios/evento/${alvo.eventFormId}`
		: `/admin/formularios/${alvo.categoryId}/${alvo.inscricao.toLowerCase()}`;
}

/** Aba Respostas do formulário, opcionalmente já numa edição (acampamento ou evento). */
export function enderecoDasRespostas(alvo: AlvoFormulario, atividadeId?: number): string {
	const base = `${enderecoDoEditor(alvo)}/respostas`;
	return atividadeId ? `${base}?atividade=${atividadeId}` : base;
}

/** "2026-11-20T23:59:00" → "20/11" */
export function diaEMes(iso: string): string {
	const [data] = iso.split('T');
	const [, mes, dia] = data.split('-');
	return `${dia}/${mes}`;
}

/** "Acampamento Juvenil 2026" → "Juvenil 2026", para os selos curtos da lista. */
export function nomeCurto(nomeDoAcampamento: string): string {
	return nomeDoAcampamento.replace(/^acampamento\s+/i, '');
}

/** Para buscar ignorando acentos e maiúsculas. */
export function normalizar(texto: string): string {
	return (texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

let rolagemAtual = 0;

/**
 * Rola a página até o elemento com aceleração leve no começo e desaceleração
 * na chegada, no lugar do pulo seco do link âncora. A duração cresce com a
 * distância (de 350 a 900 ms). Quem pede menos movimento no sistema vai direto.
 */
export function rolarSuave(elemento: HTMLElement, respiro = 20): void {
	const inicio = window.scrollY;
	const maximo = document.documentElement.scrollHeight - window.innerHeight;
	const destino = Math.min(maximo, Math.max(0, inicio + elemento.getBoundingClientRect().top - respiro));
	const distancia = destino - inicio;
	if (Math.abs(distancia) < 2) return;
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		window.scrollTo(0, destino);
		return;
	}

	const duracao = Math.min(900, Math.max(350, Math.abs(distancia) * 0.6));
	const curva = (t: number) => (t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2);
	const esta = ++rolagemAtual;
	let comeco: number | null = null;

	const passo = (agora: number) => {
		if (esta !== rolagemAtual) return; // outra rolagem começou no meio
		comeco ??= agora;
		const t = Math.min(1, (agora - comeco) / duracao);
		window.scrollTo(0, inicio + distancia * curva(t));
		if (t < 1) requestAnimationFrame(passo);
	};
	requestAnimationFrame(passo);
}

/** Quem precisa enviar o formulário, no plural: "campistas" ou "servos". */
export function quemEnvia(tipo: TipoInscricao): string {
	return tipo === 'Servo' ? 'servos' : 'campistas';
}
