import { describe, expect, it } from 'vitest';
import { linhaCurta, linhaDoPago, naFolga, type ResumoPagamento } from './pagamentos';

const resumo = (extra: Partial<ResumoPagamento> = {}): ResumoPagamento => ({
	valor: 150,
	situacao: 'nao_pago',
	precisaPagar: true,
	prazo: '2026-12-10T23:59',
	limite: '2026-12-12T23:59', // prazo + 2 dias de folga
	pagoEm: null,
	forma: null,
	registradoPor: null,
	registradoEm: null,
	observacao: null,
	canceladaEm: null,
	perdeuEm: null,
	...extra
});
const em = (d: number, h = 12) => new Date(2026, 11, d, h);

describe('naFolga (os 2 dias depois do prazo)', () => {
	it.each([
		[em(10), false], // ainda no prazo
		[em(11), true],
		[em(12, 23), true],
		[em(13, 0), false] // passou do limite: a vaga sai
	])('%s → %s', (agora, esperado) => expect(naFolga(resumo(), agora)).toBe(esperado));

	it('quem já pagou nunca está na folga', () => {
		expect(naFolga(resumo({ situacao: 'pago' }), em(11))).toBe(false);
	});
	it('quem ainda não precisa pagar também não', () => {
		expect(naFolga(resumo({ precisaPagar: false }), em(11))).toBe(false);
	});
});

describe('linhaCurta (coluna Pagamento)', () => {
	it('no prazo', () => expect(linhaCurta(resumo(), false, em(5))).toBe('até 10/12'));
	it('na folga', () => expect(linhaCurta(resumo(), false, em(11))).toBe('prazo era 10/12 · sai em 12/12'));
	it('pago', () => expect(linhaCurta(resumo({ situacao: 'pago', pagoEm: '2026-12-03', forma: 'pix' }), false, em(5))).toBe('em 03/12 · Pix'));
	it('perdeu a vaga', () => expect(linhaCurta(resumo({ situacao: 'perdeu_vaga', perdeuEm: '2026-12-13' }), false, em(14))).toBe('vaga saiu em 13/12'));
	it('ainda não precisa pagar: acampamento × evento', () => {
		expect(linhaCurta(resumo({ precisaPagar: false }), false, em(5))).toBe('depois do formulário');
		expect(linhaCurta(resumo({ precisaPagar: false }), true, em(5))).toBe('depois de concluir a inscrição');
	});
	it('sem prazo', () => expect(linhaCurta(resumo({ prazo: null, limite: null }), false, em(5))).toBe('sem prazo'));
});

describe('linhaDoPago', () => {
	it('completa', () => {
		expect(linhaDoPago(resumo({ situacao: 'pago', pagoEm: '2026-11-18', forma: 'dinheiro', registradoPor: 'Ana' }))).toBe(
			'Pago em 18/11/2026 · Dinheiro · registrado por Ana'
		);
	});
	it('sem detalhes', () => expect(linhaDoPago(resumo({ situacao: 'pago' }))).toBe('Pago'));
});
