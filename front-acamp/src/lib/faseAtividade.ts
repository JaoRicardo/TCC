/**
 * Em que fase uma atividade está, para o selo do cartão do Início e do topo da
 * página de detalhes (handoff `design_handoff_cartao_situacao`). Tudo sai das
 * datas que a API já manda; as horas são as do relógio de quem abre o site
 * (a comunidade e o computador da apresentação estão no mesmo fuso).
 *
 * As regras do período de inscrição espelham as do servidor
 * (`InscricoesController::foraDoPeriodoDeInscricao`): sem fim, não abriu; sem
 * início, vale desde já. No evento, sem fim, vale até o evento começar.
 */

export type Fase =
	| 'emBreve'
	| 'abertas'
	| 'aguardandoSorteio'
	| 'inscricoesEncerradas'
	| 'acontecendo'
	/** Já terminou: não aparece no Início, só na página aberta por link. */
	| 'encerrado';

export interface Periodo {
	de?: Date;
	ate?: Date;
}

export interface DatasDaAtividade {
	acampamento: boolean;
	inicio?: Date;
	/** Último dia (início + duração em dias). */
	fim?: Date;
	/** Inscrição de campista (acampamento) ou de participante (evento). */
	inscricao: Periodo;
	/** Inscrição de servo (só acampamento). */
	inscricaoServo?: Periodo;
	/** Data prevista do sorteio de campistas (só acampamento). */
	sorteio?: Date;
	/**
	 * O sorteio de campistas já foi feito. O sorteio é só manual: a data
	 * prevista não muda a fase, só o sorteio feito tira o "Aguardando sorteio".
	 */
	sorteioRealizado: boolean;
}

const dataOuNada = (s?: string | null) => (s ? new Date(s) : undefined);

/** Monta as datas a partir da atividade como a API manda (`/activities`, `/activities/{id}`). */
export function datasDaAtividade(api: any): DatasDaAtividade {
	const acampamento = api.tipo === 'Acampamento';
	const inicio = dataOuNada(api.inicioEvento);
	return {
		acampamento,
		inicio,
		fim: dataOuNada(api.fimEvento) ?? inicio,
		inscricao: {
			de: dataOuNada(api.inicioInscricoes),
			ate: dataOuNada(api.fimInscricoes) ?? (acampamento ? undefined : inicio),
		},
		inscricaoServo: acampamento
			? { de: dataOuNada(api.inicioInscricoesServos), ate: dataOuNada(api.fimInscricoesServos) }
			: undefined,
		sorteio: acampamento ? dataOuNada(api.inicioSorteio) : undefined,
		sorteioRealizado: !!api.sorteioCampistasRealizado,
	};
}

const inicioDoDia = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate());
const fimDoDia = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate(), 23, 59, 59, 999);

export function inscricaoAberta(p: Periodo | undefined, agora: Date): boolean {
	return !!p?.ate && (!p.de || p.de <= agora) && agora <= p.ate;
}

/** Já passou o último dia da atividade. */
export function jaTerminou(d: DatasDaAtividade, agora: Date): boolean {
	return !!d.fim && agora > fimDoDia(d.fim);
}

/** O selo segue a inscrição de campista (no evento, a de participante). */
export function faseDaAtividade(d: DatasDaAtividade, agora: Date): Fase {
	if (jaTerminou(d, agora)) return 'encerrado';
	if (d.inicio && agora >= inicioDoDia(d.inicio)) return 'acontecendo';
	const c = d.inscricao;
	if (!c.ate || (c.de && agora < c.de)) return 'emBreve';
	if (agora <= c.ate) return 'abertas';
	if (d.acampamento) {
		if (!d.sorteioRealizado) return 'aguardandoSorteio';
	}
	return 'inscricoesEncerradas';
}

/** Alguma inscrição aberta agora (campista/participante ou servo): decide o "Inscrever-se". */
export function algumaInscricaoAberta(d: DatasDaAtividade, agora: Date): boolean {
	return inscricaoAberta(d.inscricao, agora) || inscricaoAberta(d.inscricaoServo, agora);
}

/**
 * Fim das inscrições de servo, quando elas estão abertas e o selo não diz isso
 * ("Inscrições para servos abertas até dd/mm"). Null = a linha não aparece.
 */
export function servosAbertosAte(d: DatasDaAtividade, fase: Fase, agora: Date): Date | null {
	if (!d.acampamento || fase === 'abertas' || fase === 'acontecendo') return null;
	return inscricaoAberta(d.inscricaoServo, agora) ? (d.inscricaoServo!.ate as Date) : null;
}

const p2 = (x: number) => String(x).padStart(2, '0');
export const diaMes = (x: Date) => `${p2(x.getDate())}/${p2(x.getMonth() + 1)}`;
const diaMesAno = (x: Date) => `${diaMes(x)}/${x.getFullYear()}`;

/** "Acontece em 29/11", "Acontece de 12/12 a 14/12", "Acontece até 14/12", "Acontece hoje". */
export function fraseAcontece(d: DatasDaAtividade, fase: Fase, agora: Date): string {
	if (!d.inicio) return 'Data a definir';
	const fim = d.fim ?? d.inicio;
	const ano = agora.getFullYear();
	const outroAno = d.inicio.getFullYear() !== ano || fim.getFullYear() !== ano;
	const ultima = outroAno ? diaMesAno(fim) : diaMes(fim);
	const umDia = inicioDoDia(d.inicio).getTime() === inicioDoDia(fim).getTime();
	if (fase === 'acontecendo') return umDia ? 'Acontece hoje' : `Acontece até ${ultima}`;
	if (umDia) return `Acontece em ${ultima}`;
	return `Acontece de ${diaMes(d.inicio)} a ${ultima}`;
}

export const TEXTO_DA_FASE: Record<Fase, string> = {
	emBreve: 'Em breve',
	abertas: 'Inscrições abertas',
	aguardandoSorteio: 'Aguardando sorteio',
	inscricoesEncerradas: 'Inscrições encerradas',
	acontecendo: 'Acontecendo agora',
	encerrado: 'Encerrado',
};
