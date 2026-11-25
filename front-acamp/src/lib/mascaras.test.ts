import { describe, expect, it } from 'vitest';
import {
	cpfValido,
	dataParaIso,
	mascaraCep,
	mascaraCpf,
	mascaraData,
	mascaraTelefone,
	mascaraTelefoneOuFixo
} from './mascaras';

describe('mascaraCpf', () => {
	it.each([
		['', ''],
		['045', '045'],
		['0451', '045.1'],
		['045123', '045.123'],
		['0451234', '045.123.4'],
		['045123445', '045.123.445'],
		['0451234459', '045.123.445-9'],
		['04512344590', '045.123.445-90'],
		['045123445901234', '045.123.445-90'], // corta no 11º
		['045.123.445-90', '045.123.445-90'], // colar já formatado
		['abc045def', '045']
	])('"%s" → "%s"', (entrada, saida) => expect(mascaraCpf(entrada)).toBe(saida));
});

describe('mascaraTelefone / mascaraTelefoneOuFixo', () => {
	it('celular', () => expect(mascaraTelefone('66999998888')).toBe('(66) 99999-8888'));
	it('fixo com 10 números', () => expect(mascaraTelefoneOuFixo('6634981234')).toBe('(66) 3498-1234'));
	it('celular com 11 números', () => expect(mascaraTelefoneOuFixo('66999998888')).toBe('(66) 99999-8888'));
	it.each([
		['', ''],
		['6', '(6'],
		['66', '(66'],
		['669', '(66) 9'],
		['6699999', '(66) 9999-9']
	])('enquanto digita: "%s" → "%s"', (e, s) => expect(mascaraTelefoneOuFixo(e)).toBe(s));
});

describe('mascaraData e dataParaIso', () => {
	it('formata dd/mm/aaaa', () => expect(mascaraData('25112026')).toBe('25/11/2026'));
	it('converte para o formato da API', () => expect(dataParaIso('05/03/1990')).toBe('1990-03-05'));
	it.each(['31/02/1990', '29/02/2023', '00/01/2000', '32/01/2000', '15/13/2000', '1/1/2000', '', '05/03/90'])(
		'"%s" é inválida',
		(d) => expect(dataParaIso(d)).toBeNull()
	);
	it('29/02 em ano bissexto vale', () => expect(dataParaIso('29/02/2024')).toBe('2024-02-29'));
});

describe('mascaraCep', () => {
	it('formata', () => expect(mascaraCep('78850000')).toBe('78850-000'));
	it('incompleto', () => expect(mascaraCep('7885')).toBe('7885'));
});

describe('cpfValido', () => {
	it.each(['529.982.247-25', '52998224725', '111.444.777-35'])('"%s" é válido', (c) => expect(cpfValido(c)).toBe(true));
	it.each(['529.982.247-24', '00000000000', '11111111111', '123', '', '5299822472'])('"%s" é inválido', (c) =>
		expect(cpfValido(c)).toBe(false)
	);
});
