import { describe, expect, it } from 'vitest';
import { grupoDe, motivoDe, nomeDoCasal, situacaoDe, textoDaSituacao, textoDasVagas, unidades, type Inscricao } from './inscritos';

const inscricao = (extra: Partial<Inscricao> = {}): Inscricao => ({
	id: 1,
	subscription_type: 'Campista',
	status: 'Inscrito',
	motivo: null,
	selection_method_id: null,
	substitute_position: null,
	created: null,
	pagamento: {} as Inscricao['pagamento'],
	metodo: null,
	formularioEnviado: false,
	chamadoEm: null,
	saiuEm: null,
	motivoSaida: null,
	conjuge: null,
	setor: null,
	servoGeral: false,
	cidade: 'Primavera do Leste',
	daArea: true,
	user: { id: 10, name: 'Mateus Santos', cpf: '045.123.445-90', sex: 'M', birthday: null, marital_status: 'Solteiro' },
	...extra
});
const nada = { Campista: false, Servo: false };

describe('situacaoDe', () => {
	it('"Inscrito" antes do sorteio é "Aguardando sorteio"', () => {
		expect(situacaoDe(inscricao(), false, nada)).toBe('insc');
	});
	it('"Inscrito" depois do sorteio feito é "Não sorteado" (depende do sorteio, não da data)', () => {
		expect(situacaoDe(inscricao(), false, { Campista: true, Servo: false })).toBe('nao');
	});
	it('o sorteio de servos não muda a situação do campista', () => {
		expect(situacaoDe(inscricao(), false, { Campista: false, Servo: true })).toBe('insc');
	});
	it('no evento, "Inscrito" é só "Inscrito"', () => {
		expect(situacaoDe(inscricao({ subscription_type: 'Participante' }), true, nada)).toBe('evInsc');
	});
	it('servo posto numa equipe fica "Na equipe"; suplente de setor não', () => {
		const servo = { subscription_type: 'Servo' as const };
		expect(situacaoDe(inscricao({ ...servo, setor: { nome: 'Cozinha', lider: false, suplente: false } }), false, nada)).toBe('equipe');
		expect(situacaoDe(inscricao({ ...servo, setor: { nome: 'Cozinha', lider: false, suplente: true } }), false, nada)).toBe('insc');
		expect(situacaoDe(inscricao({ ...servo, servoGeral: true }), false, nada)).toBe('geral');
	});
	it.each([
		['Sorteado', 'sort'],
		['Suplente', 'sup'],
		['Formulário Enviado', 'env'],
		['Correção pedida', 'corr'],
		['Prazo Perdido', 'prazo'],
		['Cancelada pela organização', 'cancel'],
		['Recusada', 'recus'],
		['Inscrição em andamento', 'andam'],
		['Aguardando cônjuge', 'conj'],
		['Casal não confirmado', 'naoConf']
	])('status "%s" → %s', (status, sit) => {
		expect(situacaoDe(inscricao({ status }), false, nada)).toBe(sit);
	});
});

describe('textos da situação', () => {
	it('suplente mostra a posição na fila', () => {
		expect(textoDaSituacao(inscricao({ substitute_position: 2 }), 'sup')).toBe('Suplente · 2º da fila');
	});
	it('indicado mostra por quem', () => {
		expect(textoDaSituacao(inscricao({ metodo: 'Conselho' }), 'ind')).toBe('Indicado · Conselho');
		expect(textoDaSituacao(inscricao({ metodo: 'Conselho' }), 'env')).toBe('Formulário enviado · Conselho');
	});
	it('chamado da fila', () => {
		expect(textoDaSituacao(inscricao({ metodo: 'Sorteio', chamadoEm: '2026-11-20' }), 'sort')).toBe('Sorteado · chamada da fila');
	});
	it('motivo do prazo perdido', () => {
		expect(motivoDe(inscricao({ motivoSaida: 'prazo_pagamento' }), 'prazo')).toBe('Não pagou até o prazo.');
		expect(motivoDe(inscricao(), 'prazo')).toBe('Não enviou o formulário até o prazo.');
	});
	it('fora da área não concorre', () => {
		expect(motivoDe(inscricao({ daArea: false, cidade: 'Cuiabá' }), 'insc')).toBe('Não concorre: mora em Cuiabá.');
		expect(motivoDe(inscricao({ daArea: false, cidade: null }), 'insc')).toBe('Não concorre: sem cidade no cadastro.');
	});
});

describe('grupo e casal', () => {
	it('grupoDe', () => {
		expect(grupoDe(inscricao(), false)).toBe('Homem');
		expect(grupoDe(inscricao({ conjuge: { id: 2, user_id: 11, nome: 'Natália Santos' } }), true)).toBe('Casal · com Natália Santos');
		expect(grupoDe(inscricao({ subscription_type: 'Servo', setor: { nome: 'Louvor', lider: true, suplente: false } }), false)).toBe('Líder · Louvor');
	});
	it('nomeDoCasal', () => {
		expect(nomeDoCasal('Mateus Santos', 'Natália Santos')).toBe('Mateus e Natália Santos');
		expect(nomeDoCasal('Mateus Santos', null)).toBe('Mateus Santos');
	});
	it('textoDasVagas', () => {
		expect(textoDasVagas({ Homem: 0, Mulher: 0, Casado: 3 }, true)).toBe('3 casais (6 pessoas)');
		expect(textoDasVagas({ Homem: 1, Mulher: 4, Casado: 0 }, false)).toBe('1 homem e 4 mulheres (5 pessoas)');
	});
});

describe('unidades (casal é uma linha só no sorteio)', () => {
	const lista = [
		{ user_id: 2, nome: 'Natália Santos' },
		{ user_id: 3, nome: 'Ana Lima' },
		{ user_id: 1, nome: 'Mateus Santos' }
	];
	const conjuge: Record<number, number> = { 1: 2, 2: 1 };
	const homem = (id: number) => id === 1;

	it('junta o casal com o homem primeiro, mesmo vindo depois na lista', () => {
		const u = unidades(lista, true, (id) => conjuge[id] ?? null, homem);
		expect(u.map((x) => x.nome)).toEqual(['Mateus e Natália Santos', 'Ana Lima']);
		expect(u[0].pessoas.map((p) => p.user_id)).toEqual([1, 2]);
	});
	it('fora do Casais, cada pessoa é uma linha', () => {
		expect(unidades(lista, false, (id) => conjuge[id] ?? null, homem)).toHaveLength(3);
	});
	it('cônjuge que não está na lista: a pessoa fica sozinha', () => {
		const u = unidades([lista[2]], true, (id) => conjuge[id] ?? null, homem);
		expect(u.map((x) => x.nome)).toEqual(['Mateus Santos']);
	});
});
