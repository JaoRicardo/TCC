import { describe, expect, it } from 'vitest';
import { semAcento, digitosDeCpf, cpfContem } from './busca';
import { casaBusca } from './inscritos';

describe('semAcento', () => {
	it('tira acentos e maiúsculas', () => {
		expect(semAcento('JOÃO Conceição')).toBe('joao conceicao');
		expect(semAcento('Sênior')).toBe('senior');
	});
});

describe('digitosDeCpf', () => {
	it.each([
		['045.123.445-90', '04512344590'],
		['04512344590', '04512344590'],
		['123', '123'],
		['123.456', '123456'],
		[' 445 123 ', '445123']
	])('"%s" é busca por CPF', (termo, esperado) => {
		expect(digitosDeCpf(termo)).toBe(esperado);
	});

	it.each([
		'joao2@gmail.com', // o bug encontrado: o "2" do e-mail virava busca por CPF
		'joao1990@gmail.com',
		'Maria 2',
		'Maria 445',
		'ana123',
		'12', // poucos números: traria quase todo mundo
		'1',
		'',
		'   '
	])('"%s" não é busca por CPF', (termo) => {
		expect(digitosDeCpf(termo)).toBe('');
	});
});

describe('cpfContem', () => {
	it('compara só os dígitos do CPF', () => {
		expect(cpfContem('045.123.445-90', '123445')).toBe(true);
		expect(cpfContem('045.123.445-90', '999')).toBe(false);
	});
	it('sem dígitos buscados ou sem CPF, não casa', () => {
		expect(cpfContem('045.123.445-90', '')).toBe(false);
		expect(cpfContem(null, '123')).toBe(false);
		expect(cpfContem(undefined, '123')).toBe(false);
	});
});

describe('casaBusca (Inscritos e Sorteios)', () => {
	const nome = 'Natália Conceição Santos';
	const cpf = '045.123.445-90';

	it('termo vazio mostra todos', () => expect(casaBusca(nome, cpf, '  ')).toBe(true));
	it('nome sem acento e sem maiúsculas', () => expect(casaBusca(nome, cpf, 'natalia conceicao')).toBe(true));
	it('espaços a mais no termo', () => expect(casaBusca(nome, cpf, '  natália   conceição ')).toBe(true));
	it('CPF com ou sem pontuação', () => {
		expect(casaBusca(nome, cpf, '123.445')).toBe(true);
		expect(casaBusca(nome, cpf, '123445')).toBe(true);
	});
	it('nome + número não vira busca por CPF', () => {
		// 445 está no CPF da Natália, mas quem busca "Maria 445" não quer a Natália.
		expect(casaBusca(nome, cpf, 'Maria 445')).toBe(false);
		expect(casaBusca('Pedro', cpf, 'pedro123@gmail.com')).toBe(false);
	});
});
