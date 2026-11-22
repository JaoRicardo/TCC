/**
 * Tela Inscritos e sorteio (handoff `Desing/design_handoff_inscritos_sorteio`):
 * tipos do que o servidor manda e as regras de texto da tela (situação, grupo,
 * casal como unidade, filtros). O servidor continua sendo quem decide.
 */
import { naFolga, type ResumoPagamento } from '$lib/pagamentos';

/** Uma inscrição como `GET /activities/{id}/subscriptions` manda. */
export interface Inscricao {
	id: number;
	subscription_type: 'Campista' | 'Servo' | 'Participante' | string;
	status: string;
	motivo: string | null;
	selection_method_id: number | null;
	substitute_position: number | null;
	created: string | null;
	pagamento: ResumoPagamento;
	metodo: string | null;
	formularioEnviado: boolean;
	chamadoEm: string | null;
	saiuEm: string | null;
	motivoSaida: string | null;
	conjuge: { id: number; user_id: number; nome: string } | null;
	setor: { nome: string; lider: boolean; suplente: boolean } | null;
	servoGeral: boolean;
	cidade: string | null;
	daArea: boolean;
	user: { id: number; name: string; cpf: string; sex: string; birthday: string | null; marital_status: string };
}

/** Pessoa nas listas do sorteio (`GET /activities/{id}/sorteio`). */
export interface PessoaDoSorteio {
	user_id: number;
	nome: string;
	categoria?: string;
	posicao: number;
	promovido?: boolean;
	formularioEnviado?: boolean;
	foraDaArea?: boolean;
	metodo?: string;
	pre_registration_id?: number;
	setor?: string;
	lider?: boolean;
}

export type Grupo = 'Homem' | 'Mulher' | 'Casado';

/** Quem "Chamar próximo" vai chamar: a janela mostra antes de confirmar. */
export interface UnidadeChamada {
	tipo: 'Campista' | 'Servo';
	/** Campista: o grupo da fila. Servo: o id do setor (activities_categories_sectors). */
	grupo?: Grupo;
	setor?: number;
	/** "Casais", "Homens" ou o nome do setor. */
	fila: string;
	nome: string;
	pessoas: number;
	formulario: boolean;
}

export interface SorteioCampistas {
	realizado: boolean;
	feito_em: string | null;
	previsto: string | null;
	vagas: Record<Grupo, number>;
	casais: boolean;
	concorrem: Record<Grupo, number>;
	nao_concorrem: { user_id: number; nome: string; categoria: string; cidade: string | null }[];
	bloqueio: { formularios: number; pagos: number };
	indicados: PessoaDoSorteio[];
	sorteados: PessoaDoSorteio[];
	suplentes: PessoaDoSorteio[];
	nao_sorteados: PessoaDoSorteio[];
	vagas_abertas: Record<Grupo, number>;
}

export interface SetorDoSorteio {
	id: number;
	sector_name: string;
	vacancies: number;
	vagas_total: number;
	na_equipe: number;
	sorteados: PessoaDoSorteio[];
	suplentes: PessoaDoSorteio[];
	vagas_abertas: number;
}

export interface SorteioServos {
	realizado: boolean;
	feito_em: string | null;
	previsto: string | null;
	concorrem: number;
	nao_concorrem: { user_id: number; nome: string; cidade: string | null }[];
	bloqueio: { formularios: number; pagos: number };
	setores: SetorDoSorteio[];
	indicados: PessoaDoSorteio[];
	alocados_manualmente: PessoaDoSorteio[];
	servo_geral: PessoaDoSorteio[];
	nao_sorteados: PessoaDoSorteio[];
}

// ─── Situação ─────────────────────────────────────────────────────────────

export type Sit =
	| 'insc'
	| 'sort'
	| 'ind'
	| 'env'
	| 'sup'
	| 'nao'
	| 'corr'
	| 'prazo'
	| 'desist'
	| 'cancel'
	| 'recus'
	| 'retirado'
	| 'andam'
	| 'evInsc'
	| 'equipe'
	| 'geral';

export type Tom = 'ok' | 'esp' | 'can' | 'pen' | 'neu';

export const SIT: Record<Sit, [string, Tom]> = {
	insc: ['Aguardando sorteio', 'pen'],
	sort: ['Sorteado', 'ok'],
	ind: ['Indicado', 'ok'],
	env: ['Formulário enviado', 'ok'],
	sup: ['Suplente', 'esp'],
	nao: ['Não sorteado', 'neu'],
	corr: ['Correção pedida', 'esp'],
	prazo: ['Prazo perdido', 'can'],
	desist: ['Desistência', 'can'],
	cancel: ['Cancelada pela organização', 'can'],
	recus: ['Recusada', 'can'],
	retirado: ['Retirado do sorteio', 'can'],
	andam: ['Inscrição em andamento', 'esp'],
	evInsc: ['Inscrito', 'ok'],
	equipe: ['Na equipe', 'ok'],
	geral: ['Servo geral', 'ok']
};

export const COM_VAGA: Sit[] = ['sort', 'ind', 'env', 'corr', 'equipe', 'geral', 'evInsc'];
export const SAIU: Sit[] = ['prazo', 'desist', 'cancel', 'recus', 'retirado'];

export const ehIndicado = (i: Inscricao) => !!i.metodo && i.metodo !== 'Sorteio';

/**
 * Situação da inscrição na tela. "Inscrito" no acampamento vira "Aguardando
 * sorteio" ou, com o sorteio daquele tipo já feito, "Não sorteado"; servo
 * colocado à mão numa equipe fica "Na equipe".
 */
export function situacaoDe(i: Inscricao, evento: boolean, feito: { Campista: boolean; Servo: boolean }): Sit {
	switch (i.status) {
		case 'Sorteado':
			return 'sort';
		case 'Indicado':
			return 'ind';
		case 'Formulário Enviado':
			return 'env';
		case 'Suplente':
			return 'sup';
		case 'Correção pedida':
			return 'corr';
		case 'Prazo Perdido':
			return 'prazo';
		case 'Desistência':
			return 'desist';
		case 'Cancelada pela organização':
			return 'cancel';
		case 'Recusada':
			return 'recus';
		case 'Retirado do sorteio':
			return 'retirado';
		case 'Inscrição em andamento':
			return 'andam';
	}
	if (evento) return 'evInsc';
	if (i.subscription_type === 'Servo') {
		if (i.servoGeral) return 'geral';
		if (i.setor && !i.setor.suplente) return 'equipe';
	}
	const tipo = i.subscription_type === 'Servo' ? 'Servo' : 'Campista';
	return feito[tipo] ? 'nao' : 'insc';
}

/** "Suplente · 2º da fila", "Indicado · Conselho", "Formulário enviado · chamada da fila". */
export function textoDaSituacao(i: Inscricao, sit: Sit): string {
	const [texto] = SIT[sit];
	if (sit === 'sup' && i.substitute_position) return `${texto} · ${i.substitute_position}º da fila`;
	if (sit === 'ind' || ((sit === 'env' || sit === 'corr') && ehIndicado(i))) return `${texto} · ${i.metodo}`;
	if ((sit === 'sort' || sit === 'env') && i.chamadoEm) return `${texto} · chamada da fila`;
	return texto;
}

/** O que explica a situação: motivo da organização, saída por prazo ou a cidade fora da área. */
export function motivoDe(i: Inscricao, sit: Sit): string {
	if (sit === 'prazo') {
		if (i.motivoSaida === 'prazo_pagamento') return 'Não pagou até o prazo.';
		return 'Não enviou o formulário até o prazo.';
	}
	if (i.motivo) return sit === 'corr' ? `Correção: ${i.motivo}` : i.motivo;
	if (sit === 'recus') return 'Correção não feita até o prazo.';
	if ((sit === 'insc' || sit === 'nao') && !i.daArea) {
		return i.cidade ? `Não concorre: mora em ${i.cidade}.` : 'Não concorre: sem cidade no cadastro.';
	}
	return '';
}

// ─── Grupo, casal e idade ─────────────────────────────────────────────────

export function grupoDe(i: Inscricao, casais: boolean): string {
	if (i.subscription_type === 'Servo') {
		if (i.servoGeral) return 'Servo geral';
		if (!i.setor) return 'Sem setor';
		return i.setor.lider ? `Líder · ${i.setor.nome}` : `Setor: ${i.setor.nome}`;
	}
	if (casais && i.subscription_type === 'Campista') return i.conjuge ? `Casal · com ${i.conjuge.nome}` : 'Casal';
	return i.user.sex === 'M' ? 'Homem' : 'Mulher';
}

/** "Mateus e Natália Santos": o primeiro nome dele, e o nome inteiro dela (o homem primeiro). */
export function nomeDoCasal(a: string, b: string | null | undefined): string {
	if (!b) return a;
	return `${a.split(' ')[0]} e ${b}`;
}

export function idade(nascimento: string | null): string {
	if (!nascimento) return '';
	const [d, m, a] = nascimento.split('/').map(Number);
	if (!a) return '';
	const hoje = new Date();
	let anos = hoje.getFullYear() - a;
	if (hoje.getMonth() + 1 < m || (hoje.getMonth() + 1 === m && hoje.getDate() < d)) anos--;
	return `${anos} anos`;
}

export const somenteDigitos = (s: string) => s.replace(/\D/g, '');
export const normalizar = (s: string) =>
	s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

/** Nome contém o texto (sem maiúsculas/acentos) ou o CPF contém os dígitos (a partir de 3). */
export function casaBusca(nome: string, cpf: string, busca: string): boolean {
	const q = normalizar(busca);
	if (!q) return true;
	const digitos = somenteDigitos(busca);
	return normalizar(nome).includes(q) || (digitos.length >= 3 && somenteDigitos(cpf).includes(digitos));
}

export const plural = (n: number, um: string, varios: string) => `${n} ${n === 1 ? um : varios}`;

/** Quem já precisa pagar e não pagou; `vencido` = nos 2 dias de folga. */
export function naoPago(i: Inscricao): boolean {
	return i.pagamento?.situacao === 'nao_pago' && i.pagamento.precisaPagar;
}
export function vencido(i: Inscricao, agora: Date): boolean {
	return naoPago(i) && naFolga(i.pagamento, agora);
}

/** Texto das vagas do cadastro: "3 casais (6 pessoas)" ou "4 homens e 4 mulheres (8 pessoas)". */
export function textoDasVagas(v: Record<Grupo, number>, casais: boolean): string {
	if (casais) return `${plural(v.Casado, 'casal', 'casais')} (${plural(v.Casado * 2, 'pessoa', 'pessoas')})`;
	return `${plural(v.Homem, 'homem', 'homens')} e ${plural(v.Mulher, 'mulher', 'mulheres')} (${plural(v.Homem + v.Mulher, 'pessoa', 'pessoas')})`;
}

export const NOME_DO_GRUPO: Record<Grupo, string> = { Homem: 'Homens', Mulher: 'Mulheres', Casado: 'Casais' };

/**
 * Junta o casal numa unidade só (as listas do sorteio mostram "Mateus e
 * Natália Santos" numa linha). `conjugeDe` diz o user_id do cônjuge.
 */
export function unidades<T extends { user_id: number; nome: string }>(
	lista: T[],
	casais: boolean,
	conjugeDe: (userId: number) => number | null,
	ehHomem: (userId: number) => boolean
): { nome: string; pessoas: T[] }[] {
	if (!casais) return lista.map((p) => ({ nome: p.nome, pessoas: [p] }));
	const vistos = new Set<number>();
	const saida: { nome: string; pessoas: T[] }[] = [];
	for (const p of lista) {
		if (vistos.has(p.user_id)) continue;
		vistos.add(p.user_id);
		const outroId = conjugeDe(p.user_id);
		const outro = outroId ? lista.find((x) => x.user_id === outroId) : undefined;
		if (outro) {
			vistos.add(outro.user_id);
			const [a, b] = ehHomem(p.user_id) ? [p, outro] : [outro, p];
			saida.push({ nome: nomeDoCasal(a.nome, b.nome), pessoas: [a, b] });
		} else {
			saida.push({ nome: p.nome, pessoas: [p] });
		}
	}
	return saida;
}
