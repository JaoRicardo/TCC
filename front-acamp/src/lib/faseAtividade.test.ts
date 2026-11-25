import { describe, expect, it } from 'vitest';
import {
	algumaInscricaoAberta,
	datasDaAtividade,
	faseDaAtividade,
	fraseAcontece,
	servosAbertosAte,
	type DatasDaAtividade
} from './faseAtividade';

// Datas no horário local, como as telas usam. Mês começando em 1 para ler fácil.
const dia = (d: number, m: number, a = 2026, h = 12, min = 0) => new Date(a, m - 1, d, h, min);

/** Acampamento de 15 a 17/01/2027: inscrição 01/12–10/12, sorteio previsto 15/12. */
const acampamento = (sorteioRealizado = false): DatasDaAtividade => ({
	acampamento: true,
	inicio: dia(15, 1, 2027, 0),
	fim: dia(17, 1, 2027, 0),
	inscricao: { de: dia(1, 12, 2026, 0), ate: dia(10, 12, 2026, 23, 59) },
	inscricaoServo: { de: dia(1, 12, 2026, 0), ate: dia(20, 12, 2026, 23, 59) },
	sorteio: dia(15, 12, 2026, 0),
	sorteioRealizado
});

describe('faseDaAtividade — acampamento', () => {
	it.each([
		['antes da inscrição abrir', dia(25, 11), false, 'emBreve'],
		['no primeiro minuto da inscrição', dia(1, 12, 2026, 0), false, 'abertas'],
		['no último minuto da inscrição', dia(10, 12, 2026, 23, 59), false, 'abertas'],
		['inscrição fechou, sorteio não feito', dia(11, 12), false, 'aguardandoSorteio'],
		['passou a data prevista e o sorteio ainda não foi feito', dia(20, 12), false, 'aguardandoSorteio'],
		['sorteio feito', dia(11, 12), true, 'inscricoesEncerradas'],
		['no primeiro dia (00h00)', dia(15, 1, 2027, 0), false, 'acontecendo'],
		['no último dia à noite', dia(17, 1, 2027, 23, 0), true, 'acontecendo'],
		['no dia seguinte ao último', dia(18, 1, 2027, 0, 1), true, 'encerrado']
	] as const)('%s → %s', (_, agora, feito, fase) => {
		expect(faseDaAtividade(acampamento(feito), agora)).toBe(fase);
	});

	it('sem data de fim da inscrição, ainda não abriu (igual ao servidor)', () => {
		const d = { ...acampamento(), inscricao: { de: dia(1, 12) } };
		expect(faseDaAtividade(d, dia(5, 12))).toBe('emBreve');
	});
});

describe('faseDaAtividade — evento', () => {
	const evento: DatasDaAtividade = {
		acampamento: false,
		inicio: dia(20, 12, 2026, 0),
		fim: dia(20, 12, 2026, 0),
		inscricao: { de: dia(1, 12, 2026, 0), ate: dia(18, 12, 2026, 23, 59) },
		sorteioRealizado: false
	};
	it('evento nunca fica "Aguardando sorteio"', () => {
		expect(faseDaAtividade(evento, dia(19, 12))).toBe('inscricoesEncerradas');
	});
	it('evento de um dia: acontecendo no dia, encerrado depois', () => {
		expect(faseDaAtividade(evento, dia(20, 12, 2026, 22))).toBe('acontecendo');
		expect(faseDaAtividade(evento, dia(21, 12, 2026, 0, 1))).toBe('encerrado');
	});
});

describe('datasDaAtividade (formato da API)', () => {
	it('evento sem fim de inscrição: vale até começar', () => {
		const d = datasDaAtividade({ tipo: 'Evento', inicioEvento: '2026-12-20T00:00', inicioInscricoes: '2026-12-01T00:00' });
		expect(d.inscricao.ate).toEqual(new Date('2026-12-20T00:00'));
		expect(d.fim).toEqual(d.inicio);
	});
	it('acampamento sem fim de inscrição: fica sem fim', () => {
		const d = datasDaAtividade({ tipo: 'Acampamento', inicioEvento: '2027-01-15T00:00', sorteioCampistasRealizado: 1 });
		expect(d.inscricao.ate).toBeUndefined();
		expect(d.sorteioRealizado).toBe(true);
	});
});

describe('inscrições de servo', () => {
	it('servo ainda pode se inscrever depois da de campista fechar', () => {
		const agora = dia(15, 12);
		expect(algumaInscricaoAberta(acampamento(), agora)).toBe(true);
		const fase = faseDaAtividade(acampamento(), agora);
		expect(servosAbertosAte(acampamento(), fase, agora)).toEqual(dia(20, 12, 2026, 23, 59));
	});
	it('enquanto a de campista está aberta, a linha de servo não aparece', () => {
		expect(servosAbertosAte(acampamento(), 'abertas', dia(5, 12))).toBeNull();
	});
	it('depois de tudo fechar, ninguém se inscreve', () => {
		expect(algumaInscricaoAberta(acampamento(), dia(21, 12))).toBe(false);
	});
});

describe('fraseAcontece', () => {
	const agora = dia(25, 11);
	it('vários dias no mesmo mês', () => {
		const d = { ...acampamento(), inicio: dia(12, 12, 2026, 0), fim: dia(14, 12, 2026, 0) };
		expect(fraseAcontece(d, 'emBreve', agora)).toBe('Acontece de 12/12 a 14/12');
	});
	it('em outro ano mostra o ano', () => {
		expect(fraseAcontece(acampamento(), 'emBreve', agora)).toBe('Acontece de 15/01 a 17/01/2027');
	});
	it('um dia só', () => {
		const d = { ...acampamento(), inicio: dia(12, 12, 2026, 0), fim: dia(12, 12, 2026, 0) };
		expect(fraseAcontece(d, 'emBreve', agora)).toBe('Acontece em 12/12');
		expect(fraseAcontece(d, 'acontecendo', agora)).toBe('Acontece hoje');
	});
	it('sem data', () => {
		expect(fraseAcontece({ ...acampamento(), inicio: undefined }, 'emBreve', agora)).toBe('Data a definir');
	});
});
