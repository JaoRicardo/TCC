import { describe, expect, it } from 'vitest';
import { avisoDeQuemSaiu, linhaCurta, linhaDoPago, naFolga, podeDesfazer, totaisDosPagamentos, type ResumoPagamento } from './pagamentos';

const resumo = (extra: Partial<ResumoPagamento> = {}): ResumoPagamento => ({
	valor: 150,
	situacao: 'nao_pago',
	precisaPagar: true,
	temVaga: true,
	prazo: '2026-12-10T23:59',
	limite: '2026-12-12T23:59', // prazo + 2 dias de folga
	pagoEm: null,
	forma: null,
	registradoPor: null,
	registradoEm: null,
	observacao: null,
	herdado: false,
	saiuEm: null,
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

describe('pagamento que fica na vaga', () => {
	const herdado = resumo({ situacao: 'pago', herdado: true, herdadoDe: 'Pedro Alves', pagoEm: '2026-12-04', registradoPor: 'Ana' });
	it('quem entrou: coluna e linha completa', () => {
		expect(linhaCurta(herdado, false, em(5))).toBe('vaga paga por Pedro');
		expect(linhaDoPago(herdado)).toBe('Vaga paga por Pedro Alves, que saiu · chamado(a) em 04/12/2026 · por Ana');
	});
	it('quem saiu: ainda sem ninguém chamado, já passou, evento', () => {
		const saiu = resumo({ situacao: 'pago', saiuEm: '2026-12-02' });
		expect(avisoDeQuemSaiu(saiu, false)).toBe('Saiu da vaga em 02/12/2026. O pagamento passa para o suplente que for chamado para a vaga.');
		expect(avisoDeQuemSaiu({ ...saiu, passouPara: 'Lucas' }, false)).toBe(
			'Saiu da vaga em 02/12/2026. O pagamento passou para Lucas, que entrou na vaga.'
		);
		expect(avisoDeQuemSaiu(saiu, true)).toBe('Saiu da vaga em 02/12/2026. O pagamento fica com a organização.');
	});
	it('quem não saiu não tem aviso', () => expect(avisoDeQuemSaiu(resumo({ situacao: 'pago' }), false)).toBeNull());
});

describe('totaisDosPagamentos', () => {
	it('recebido sem o pagamento herdado; a receber só de quem tem vaga', () => {
		const lista = [
			resumo({ situacao: 'pago', saiuEm: '2026-11-26' }), // pagou e saiu: o dinheiro fica
			resumo({ situacao: 'pago', herdado: true }), // entrou na vaga dele: não é dinheiro novo
			resumo({ situacao: 'pago' }),
			resumo({ situacao: 'nao_pago' }), // sorteado que ainda não pagou
			resumo({ situacao: 'nao_pago', precisaPagar: false }), // sorteado sem formulário: ainda conta
			resumo({ situacao: 'nao_pago', precisaPagar: false, temVaga: false }) // suplente na fila
		];
		expect(totaisDosPagamentos(lista)).toEqual({ recebido: 300, aReceber: 300 });
	});
});

describe('podeDesfazer', () => {
	it('não desfaz o pagamento que já passou para quem entrou na vaga', () => {
		expect(podeDesfazer(resumo({ situacao: 'pago' }))).toBe(true);
		expect(podeDesfazer(resumo({ situacao: 'pago', saiuEm: '2026-11-26' }))).toBe(true);
		expect(podeDesfazer(resumo({ situacao: 'pago', saiuEm: '2026-11-26', passouPara: 'Lucas' }))).toBe(false);
	});
});
