import { describe, expect, it } from 'vitest';
import {
	comNoDegrau,
	faixaCurta,
	faixaDeIdade,
	junta,
	lugarNaEscada,
	podeServirEm,
	proximaQueAceitaCasados,
	quemPodeServir,
	textosDaCategoria,
	type RegrasDaCategoria
} from './categorias';

// As seis categorias padrão (CategoriasService::ORIGINAIS).
const cat = (id: number, nome: string, posicao: number, extra: Partial<RegrasDaCategoria> = {}): RegrasDaCategoria => ({
	id,
	nome,
	posicao,
	idadeMinima: null,
	idadeMaxima: null,
	naEscada: false,
	requerCategoriaId: null,
	soCasais: false,
	aceitaCasados: true,
	preenchidoPeloResponsavel: false,
	arquivada: false,
	...extra
});
const mirim = cat(1, 'Mirim', 1, { idadeMinima: 10, idadeMaxima: 12, naEscada: true, preenchidoPeloResponsavel: true });
const fac = cat(2, 'FAC', 2, { idadeMinima: 14, idadeMaxima: 17, naEscada: true });
const juvenil = cat(3, 'Juvenil', 3, { idadeMinima: 18, idadeMaxima: 24, naEscada: true, aceitaCasados: false });
const senior = cat(4, 'Sênior', 4, { idadeMinima: 25, idadeMaxima: 60, naEscada: true });
const casais = cat(5, 'Casais', 5, { soCasais: true });
const discipulados = cat(6, 'Discipulados', 6, { requerCategoriaId: 4 });
const todas = [discipulados, senior, mirim, casais, juvenil, fac]; // fora de ordem de propósito

const nomes = (l: RegrasDaCategoria[]) => l.map((c) => c.nome);

describe('textos de idade', () => {
	it('faixaDeIdade', () => {
		expect(faixaDeIdade(10, 12)).toBe('de 10 a 12 anos');
		expect(faixaDeIdade(18, 18)).toBe('de 18 anos');
		expect(faixaDeIdade(30, null)).toBe('a partir de 30 anos');
		expect(faixaDeIdade(null, 17)).toBe('até 17 anos');
		expect(faixaDeIdade(null, null)).toBeNull();
	});
	it('faixaCurta', () => {
		expect(faixaCurta(juvenil)).toBe('18 a 24 anos');
		expect(faixaCurta(casais)).toBe('Só casais');
		expect(faixaCurta(discipulados)).toBe('Qualquer idade');
	});
	it('junta', () => {
		expect(junta([])).toBe('');
		expect(junta(['A'])).toBe('A');
		expect(junta(['A', 'B'])).toBe('A e B');
		expect(junta(['A', 'B', 'C'], 'ou')).toBe('A, B ou C');
	});
});

describe('servir na escada', () => {
	it('campista do Juvenil serve no Juvenil e nos degraus de baixo', () => {
		expect(nomes(podeServirEm(juvenil, todas))).toEqual(['Mirim', 'FAC', 'Juvenil']);
	});
	it('fora da escada, só nela mesma', () => {
		expect(nomes(podeServirEm(casais, todas))).toEqual(['Casais']);
	});
	it('degrau arquivado abaixo some de "pode servir em"', () => {
		const comFacArquivado = todas.map((c) => (c.id === 2 ? { ...c, arquivada: true } : c));
		expect(nomes(podeServirEm(senior, comFacArquivado))).toEqual(['Mirim', 'Juvenil', 'Sênior']);
	});
	it('para servir no FAC precisa ter sido campista no FAC ou acima', () => {
		expect(quemPodeServir(fac, todas)).toEqual(['FAC', 'Juvenil', 'Sênior']);
	});
	it('degrau arquivado acima ainda vale, marcado', () => {
		const comSeniorArquivado = todas.map((c) => (c.id === 4 ? { ...c, arquivada: true } : c));
		expect(quemPodeServir(juvenil, comSeniorArquivado)).toEqual(['Juvenil', 'Sênior (arquivada)']);
	});
});

describe('lugar na escada', () => {
	it.each([
		[fac, 'entre Mirim e Juvenil'],
		[senior, 'acima do Juvenil, no topo'],
		[mirim, 'abaixo do FAC, no primeiro degrau']
	])('%s', (c, texto) => expect(lugarNaEscada(c, todas)).toBe(texto));

	it('comNoDegrau põe a nova logo acima do degrau escolhido e renumera', () => {
		const nova = cat(7, 'Pré-Juvenil', 99, { naEscada: true });
		const r = comNoDegrau(todas, nova, fac.id);
		expect(nomes(r)).toEqual(['Mirim', 'FAC', 'Pré-Juvenil', 'Juvenil', 'Sênior', 'Casais', 'Discipulados']);
		expect(r.map((c) => c.posicao)).toEqual([1, 2, 3, 4, 5, 6, 7]);
	});
	it('comNoDegrau com depois = null vai para o primeiro degrau', () => {
		const nova = cat(7, 'Bebês', 99, { naEscada: true });
		expect(nomes(comNoDegrau(todas, nova, null))[0]).toBe('Bebês');
	});
	it('fora da escada vai para o fim', () => {
		const nova = cat(7, 'Jovens Casais', 99);
		expect(nomes(comNoDegrau(todas, nova, null)).at(-1)).toBe('Jovens Casais');
	});
});

describe('textosDaCategoria (Criar Acampamento e Categorias)', () => {
	it('Juvenil não aceita casados', () => {
		expect(textosDaCategoria(juvenil, todas)).toEqual({
			campista: 'Campistas de 18 a 24 anos, que não sejam casados, uma vez só nesta categoria.',
			servo: 'Quem já foi campista no Juvenil ou no Sênior.'
		});
	});
	it('Discipulados exige Sênior', () => {
		expect(textosDaCategoria(discipulados, todas).campista).toBe(
			'Quem já foi campista no Sênior, uma vez só nesta categoria.'
		);
	});
	it('Casais fala do cônjuge', () => {
		expect(textosDaCategoria(casais, todas).campista).toContain('CPF do cônjuge');
	});
	it('casado no Juvenil é mandado para o Sênior', () => {
		expect(proximaQueAceitaCasados(juvenil, todas)?.nome).toBe('Sênior');
		expect(proximaQueAceitaCasados(casais, todas)).toBeNull();
	});
});
