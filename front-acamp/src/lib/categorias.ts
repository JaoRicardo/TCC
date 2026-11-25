/**
 * Categorias de acampamento (tela Gestão › Categorias, handoff
 * `design_handoff_categorias`). As regras ficam nos campos da categoria, nunca
 * no nome — o servidor (CategoriasService e
 * InscricoesController::checkAcampamentoEligibility) é quem decide de verdade;
 * aqui ficam só os textos que a organização lê.
 *
 * Uma ordem só para o painel inteiro: primeiro a escada por idade, do degrau
 * mais novo (posição menor) ao mais velho, depois as que ficam fora dela.
 * Quem foi campista num degrau pode servir nele e em todos os de baixo. A
 * arquivada que estava na escada continua no lugar dela na regra de servir.
 */

export type RegrasDaCategoria = {
	id: number;
	nome: string;
	/** Ordem do painel. Na escada, também é o degrau (menor = mais novo). */
	posicao: number;
	idadeMinima: number | null;
	idadeMaxima: number | null;
	/** Na escada por idade: ter sido campista nela libera servir nela e nos degraus de baixo. */
	naEscada: boolean;
	/** Precisa ter sido campista nesta outra categoria antes. */
	requerCategoriaId: number | null;
	/** Só vagas de casais (o casal é sorteado junto). */
	soCasais: boolean;
	aceitaCasados: boolean;
	preenchidoPeloResponsavel: boolean;
	arquivada: boolean;
	arquivadaEm?: string | null;
};

/** Acampamento por acontecer (as datas no formato de /activities, para faseDaAtividade). */
export type AcampamentoPorAcontecer = {
	id: number;
	nome: string;
	tipo: 'Acampamento';
	inicioEvento: string | null;
	fimEvento: string | null;
	inicioInscricoes: string | null;
	fimInscricoes: string | null;
	sorteioCampistasRealizado: boolean;
};

export type CategoriaDaLista = RegrasDaCategoria & {
	acampamentos: number;
	acampamentosPorAcontecer: number;
	porAcontecer: AcampamentoPorAcontecer[];
	perguntasCampista: number;
	perguntasServo: number;
	/** Modelo de setores: setores com vaga e a soma das vagas. */
	setores: number;
	vagasSetores: number;
};

// ─── Textos ─────────────────────────────────────────────────────────────

/** "de 10 a 12 anos", "a partir de 30 anos", "até 17 anos" ou null (sem limite). */
export function faixaDeIdade(min: number | null, max: number | null): string | null {
	if (min !== null && max !== null) return min === max ? `de ${min} anos` : `de ${min} a ${max} anos`;
	if (min !== null) return `a partir de ${min} anos`;
	if (max !== null) return `até ${max} anos`;
	return null;
}

/** Para as linhas da lista: "18 a 24 anos", "A partir de 30 anos", "Qualquer idade" ou "Só casais". */
export function faixaCurta(c: Pick<RegrasDaCategoria, 'idadeMinima' | 'idadeMaxima' | 'soCasais'>): string {
	if (c.soCasais) return 'Só casais';
	const { idadeMinima: min, idadeMaxima: max } = c;
	if (min !== null && max !== null) return min === max ? `${min} anos` : `${min} a ${max} anos`;
	if (min !== null) return `A partir de ${min} anos`;
	if (max !== null) return `Até ${max} anos`;
	return 'Qualquer idade';
}

/** "A", "A e B", "A, B e C" (ou com "ou"). */
export function junta(itens: string[], conjuncao: 'e' | 'ou' = 'e'): string {
	if (itens.length <= 1) return itens[0] ?? '';
	return `${itens.slice(0, -1).join(', ')} ${conjuncao} ${itens[itens.length - 1]}`;
}

const comNo = (nomes: string[], conjuncao: 'e' | 'ou') => junta(nomes.map((n) => `no ${n}`), conjuncao);

// ─── Escada ─────────────────────────────────────────────────────────────

const porPosicao = <T extends RegrasDaCategoria>(lista: T[]) => [...lista].sort((a, b) => a.posicao - b.posicao || a.id - b.id);

/** A escada inteira, arquivadas inclusive, do degrau mais novo ao mais velho. */
export const escadaCompleta = <T extends RegrasDaCategoria>(todas: T[]) => porPosicao(todas.filter((c) => c.naEscada));
/** Os degraus em uso, do mais novo ao mais velho. */
export const escadaEmUso = <T extends RegrasDaCategoria>(todas: T[]) => escadaCompleta(todas).filter((c) => !c.arquivada);
/** As em uso fora da escada, na ordem do painel. */
export const foraDaEscada = <T extends RegrasDaCategoria>(todas: T[]) => porPosicao(todas.filter((c) => !c.naEscada && !c.arquivada));

/**
 * Onde pode servir quem foi campista nesta: na escada, ela e os degraus em
 * uso de baixo; fora da escada, só nela.
 */
export function podeServirEm<T extends RegrasDaCategoria>(c: T, todas: T[]): T[] {
	if (!c.naEscada) return [c];
	const escada = escadaCompleta(todas);
	const i = escada.findIndex((x) => x.id === c.id);
	return escada.slice(0, i + 1).filter((x) => !x.arquivada || x.id === c.id);
}

/** "no Mirim, no FAC e no Juvenil" */
export const textoPodeServirEm = (c: RegrasDaCategoria, todas: RegrasDaCategoria[]) =>
	comNo(podeServirEm(c, todas).map((x) => x.nome), 'e');

/**
 * Em quais categorias a pessoa precisa ter sido campista para servir nesta:
 * ela mesma e, na escada, os degraus de cima (as arquivadas também valem).
 */
export function quemPodeServir(c: RegrasDaCategoria, todas: RegrasDaCategoria[]): string[] {
	if (!c.naEscada) return [c.nome];
	const escada = escadaCompleta(todas);
	const i = escada.findIndex((x) => x.id === c.id);
	return escada.slice(i).map((x) => (x.arquivada && x.id !== c.id ? `${x.nome} (arquivada)` : x.nome));
}

/** Os degraus em uso logo abaixo e logo acima (para dizer onde a categoria fica). */
export function vizinhos(c: RegrasDaCategoria, todas: RegrasDaCategoria[]): [RegrasDaCategoria | null, RegrasDaCategoria | null] {
	const escada = escadaCompleta(todas);
	const i = escada.findIndex((x) => x.id === c.id);
	if (i < 0) return [null, null];
	const abaixo = escada.slice(0, i).filter((x) => !x.arquivada).pop() ?? null;
	const acima = escada.slice(i + 1).find((x) => !x.arquivada) ?? null;
	return [abaixo, acima];
}

/** "entre Juvenil e Sênior", "acima do Sênior, no topo", "abaixo do Mirim, no primeiro degrau" */
export function lugarNaEscada(c: RegrasDaCategoria, todas: RegrasDaCategoria[]): string {
	const [a, b] = vizinhos(c, todas);
	if (a && b) return `entre ${a.nome} e ${b.nome}`;
	if (a) return `acima do ${a.nome}, no topo`;
	if (b) return `abaixo do ${b.nome}, no primeiro degrau`;
	return 'sozinha na escada';
}

/**
 * Lista com a categoria `c` (nova ou editada) colocada no degrau escolhido:
 * logo acima de `depois` (null = primeiro degrau). Espelha
 * CategoriasService::posicionar, para a prévia.
 */
export function comNoDegrau<T extends RegrasDaCategoria>(todas: T[], c: T, depois: number | null): T[] {
	const outras = porPosicao(todas.filter((x) => x.id !== c.id));
	const seq: T[] = [...outras];
	if (c.naEscada) {
		if (depois === null) {
			const k = seq.findIndex((x) => x.naEscada);
			seq.splice(k < 0 ? 0 : k, 0, c);
		} else {
			const k = seq.findIndex((x) => x.id === depois);
			seq.splice(k < 0 ? seq.length : k + 1, 0, c);
		}
	} else {
		seq.push(c);
	}
	const ordenada = [...seq.filter((x) => x.naEscada), ...seq.filter((x) => !x.naEscada)];
	return ordenada.map((x, i) => ({ ...x, posicao: i + 1 }));
}

/** O degrau em uso logo abaixo (o `depois` de hoje); null = primeiro degrau. */
export function depoisDe(c: RegrasDaCategoria, todas: RegrasDaCategoria[]): number | null {
	return vizinhos(c, todas)[0]?.id ?? null;
}

// ─── Frases "quem pode participar" ──────────────────────────────────────

/** As duas linhas de "quem pode participar" (campistas e servos), como na tela Criar Acampamento. */
export function textosDaCategoria(c: RegrasDaCategoria, todas: RegrasDaCategoria[]): { campista: string; servo: string } {
	const faixa = faixaDeIdade(c.idadeMinima, c.idadeMaxima);
	const requer = c.requerCategoriaId !== null ? (todas.find((o) => o.id === c.requerCategoriaId)?.nome ?? null) : null;

	let campista: string;
	if (c.soCasais) {
		campista =
			`Só pessoas casadas ou em união estável${faixa ? `, ${faixa}` : ''}. ` +
			'Cada um se inscreve na própria conta, com o CPF do cônjuge, e o casal é sorteado junto.' +
			(requer ? ` Precisa ter sido campista no ${requer}.` : '') +
			' Uma vez só nesta categoria.';
	} else {
		let inicio = requer ? `Quem já foi campista no ${requer}` : faixa ? 'Campistas' : 'Qualquer pessoa';
		if (faixa) inicio += requer ? `, ${faixa}` : ` ${faixa}`;
		const partes = [inicio];
		if (!c.aceitaCasados) partes.push('que não sejam casados');
		partes.push('uma vez só nesta categoria.');
		campista = partes.join(', ');
	}

	return { campista, servo: `Quem já foi campista ${comNo(quemPodeServir(c, todas), 'ou')}.` };
}

/**
 * A próxima da escada (para cima) que aceita casados: vai na mensagem que o
 * casado recebe. Espelha CategoriasService::proximaQueAceitaCasados.
 */
export function proximaQueAceitaCasados(c: RegrasDaCategoria, todas: RegrasDaCategoria[]): RegrasDaCategoria | null {
	if (!c.naEscada) return null;
	const escada = escadaCompleta(todas);
	const i = escada.findIndex((x) => x.id === c.id);
	return escada.slice(i + 1).find((x) => !x.arquivada && x.aceitaCasados && !x.soCasais) ?? null;
}

/** Quantas das 3 coisas ainda faltam configurar (formulário de campista, de servo, modelo de setores). */
export const faltaConfigurar = (c: CategoriaDaLista) =>
	[c.perguntasCampista > 0, c.perguntasServo > 0, c.setores > 0].filter((feito) => !feito).length;

/** Sem maiúsculas, acentos e espaços a mais (nome repetido). */
export const normalizarNome = (s: string) =>
	s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
