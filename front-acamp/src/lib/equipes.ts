/**
 * Regras e textos da tela Equipes de Servos (handoff
 * `Desing/design_handoff_equipes_servos`), usados pelo painel e pela área do
 * participante. O servidor é quem decide de verdade; aqui só se espelha para a
 * tela não oferecer o que a pessoa não pode fazer.
 */
import { digitosDeCpf, cpfContem } from '$lib/busca';
import type { EquipeDoAcampamento, PessoaDaEquipe, PessoaDoBanco, SetorDaEquipe, SituacaoNoBanco } from '$lib/services/equipeService';

export const plural = (n: number, um: string, varios: string) => `${n} ${n === 1 ? um : varios}`;

/** Sem maiúsculas, acentos e espaços a mais (busca). */
export const normalizar = (s: string) =>
	s
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/\s+/g, ' ')
		.trim();

/**
 * Artigo do nome do setor: "a Cozinha", "o Louvor". Os setores são cadastrados
 * pela organização; a regra cobre os nomes comuns (feminino em -a, -ão, -ade, -gem).
 */
function feminino(nome: string): boolean {
	const n = normalizar(nome).split(' ')[0] ?? '';
	return /(a|ao|ade|gem)$/.test(n);
}
export const na = (setor: string) => `${feminino(setor) ? 'na' : 'no'} ${setor}`;
export const da = (setor: string) => `${feminino(setor) ? 'da' : 'do'} ${setor}`;
/** "a Cozinha" / "o Louvor". */
export const oA = (setor: string) => `${feminino(setor) ? 'a' : 'o'} ${setor}`;
/** "A Cozinha" / "O Louvor" (começo de frase). */
export const aDo = (setor: string) => `${feminino(setor) ? 'A' : 'O'} ${setor}`;
/** "completa" / "completo", concordando com o setor. */
export const completo = (setor: string) => (feminino(setor) ? 'completa' : 'completo');

export const primeiroNome = (nome: string) => nome.trim().split(/\s+/)[0] ?? nome;

const p2 = (n: number) => String(n).padStart(2, '0');
export function dataBr(iso: string | null | undefined): string {
	if (!iso) return 'a definir';
	const d = new Date(iso);
	return `${p2(d.getDate())}/${p2(d.getMonth() + 1)}/${d.getFullYear()}`;
}

/** "15 a 17/01/2027", como na Gestão de Atividades. */
export function periodoBr(inicio?: Date, fim?: Date): string {
	if (!inicio) return 'Data a definir';
	const f = fim ?? inicio;
	const dm = (x: Date) => `${p2(x.getDate())}/${p2(x.getMonth() + 1)}`;
	if (dm(inicio) === dm(f) && inicio.getFullYear() === f.getFullYear()) return `${dm(inicio)}/${inicio.getFullYear()}`;
	if (inicio.getMonth() === f.getMonth() && inicio.getFullYear() === f.getFullYear()) return `${p2(inicio.getDate())} a ${dm(f)}/${f.getFullYear()}`;
	return `${dm(inicio)} a ${dm(f)}/${f.getFullYear()}`;
}

// ─── Setores ──────────────────────────────────────────────────────────────

export const pessoasDo = (s: SetorDaEquipe): PessoaDaEquipe[] => [...s.coordenadores, ...s.membros];
export const ocupadas = (s: SetorDaEquipe) => s.coordenadores.length + s.membros.length;

/** Selo do cartão: "faltam 2" (âmbar), "completa" (verde) ou "2 a mais" (vermelho). */
export function seloDoSetor(s: SetorDaEquipe): { texto: string; tom: 'esp' | 'ok' | 'can' } {
	const falta = s.actual_vacancies - ocupadas(s);
	if (falta > 0) return { texto: `falta${falta > 1 ? 'm' : ''} ${falta}`, tom: 'esp' };
	if (falta === 0) return { texto: 'completa', tom: 'ok' };
	return { texto: `${-falta} a mais`, tom: 'can' };
}

/** Texto da opção de setor nas janelas: "3 de 5 · faltam 2" ou "completa (5 de 5)". */
export function vagasDaOpcao(s: SetorDaEquipe): string {
	const n = ocupadas(s);
	const v = s.actual_vacancies;
	if (n >= v) return `completa (${n} de ${v})`;
	return `${n} de ${v} · falta${v - n > 1 ? 'm' : ''} ${v - n}`;
}

export const ligados = (e: EquipeDoAcampamento) => e.setores.filter((s) => s.linked);
export const foraDoAcampamento = (e: EquipeDoAcampamento) => e.setores.filter((s) => !s.linked);

/** Internos e Externos, cada grupo em ordem alfabética (o servidor já manda assim). */
export function gruposPorLocal(setores: SetorDaEquipe[]): { nome: string; setores: SetorDaEquipe[] }[] {
	return [
		{ nome: 'Internos', setores: setores.filter((s) => s.sector_place !== 'Externo') },
		{ nome: 'Externos', setores: setores.filter((s) => s.sector_place === 'Externo') }
	].filter((g) => g.setores.length > 0);
}

/** Setores ligados com vagas diferentes das do modelo da categoria. */
export const diferentesDoModelo = (e: EquipeDoAcampamento) =>
	ligados(e).filter((s) => s.default_vacancies !== null && s.default_vacancies !== s.actual_vacancies);

export function numerosDaEquipe(e: EquipeDoAcampamento) {
	const lig = ligados(e);
	return {
		setores: lig.length,
		vagas: lig.reduce((t, s) => t + s.actual_vacancies, 0),
		naEquipe: lig.reduce((t, s) => t + ocupadas(s), 0),
		faltando: lig.reduce((t, s) => t + Math.max(0, s.actual_vacancies - ocupadas(s)), 0),
		noBanco: e.banco?.length ?? 0
	};
}

// ─── Banco de servos ──────────────────────────────────────────────────────

export const SITUACAO: Record<SituacaoNoBanco, [string, 'pen' | 'ok' | 'esp' | 'neu']> = {
	insc: ['Inscrito', 'pen'],
	sort: ['Sorteado', 'ok'],
	sup: ['Suplente', 'esp'],
	nao: ['Não sorteado', 'neu'],
	ind: ['Indicado', 'ok'],
	retirado: ['Retirado do sorteio', 'neu']
};

/** "Suplente · Cozinha 1º", "Indicado · Conselho", "Não sorteado". */
export function textoDaSituacao(p: PessoaDoBanco): string {
	const [texto] = SITUACAO[p.situacao];
	if (p.situacao === 'sup' && p.suplente_setor) return `${texto} · ${p.suplente_setor} ${p.suplente_pos ?? ''}º`.replace(' º', 'º');
	if (p.situacao === 'ind' && p.metodo) return `${texto} · ${p.metodo}`;
	return texto;
}

/** "25 anos · Primavera do Leste · CPF ***.445.123-**" (o CPF só vem para a organização). */
export function infoDaPessoa(p: PessoaDoBanco, comCpf = true): string {
	const partes = [p.idade !== null ? `${p.idade} anos` : null, p.cidade || 'sem cidade'];
	if (comCpf && p.cpf) partes.push(`CPF ${p.cpf}`);
	return partes.filter(Boolean).join(' · ');
}

export function buscaNoBanco(banco: PessoaDoBanco[], termo: string): PessoaDoBanco[] {
	const q = normalizar(termo);
	if (!q) return banco;
	const digitos = digitosDeCpf(termo);
	return banco.filter((p) => normalizar(p.nome).includes(q) || cpfContem(p.cpf, digitos));
}

// ─── Quem pode o quê (espelho do servidor) ────────────────────────────────

export interface Poderes {
	/** Permissão "Equipes de Servos": vagas, setores, servo geral, modelo. */
	organizacao: boolean;
	/** Tornar líder, mover, pôr como líder: organização ou servo geral. */
	lideres: boolean;
	porNo: (s: SetorDaEquipe) => boolean;
	tirar: (s: SetorDaEquipe, p: PessoaDaEquipe) => boolean;
}

/** `noSite`: na área do participante, vagas, setores e servo geral não aparecem (são do painel). */
export function poderesDe(e: EquipeDoAcampamento, eu: number | undefined, noSite: boolean): Poderes {
	const m = e.meu_papel;
	const organizacao = m.is_admin && !noSite;
	const lideres = m.is_admin || m.is_servo_geral;
	const lidero = (s: SetorDaEquipe) => s.id !== null && m.lidera_setores_ids.includes(s.id);
	return {
		organizacao,
		lideres,
		porNo: (s) => s.linked && (lideres || lidero(s)),
		tirar: (s, p) => {
			if (m.is_admin) return true;
			if (m.is_servo_geral) return true;
			return lidero(s) && !p.lider && p.user_id !== eu;
		}
	};
}
