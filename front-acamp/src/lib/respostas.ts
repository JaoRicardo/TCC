/** Ajudas da aba Respostas: ler as respostas gravadas, ordenar as perguntas e montar planilhas. */
import { normalizar } from '$lib/formularios';
import type { PerguntaDoFormulario, PessoaComRespostas } from '$lib/services/respostaService';

export const TIPOS_DE_ESCOLHA = ['sim_nao', 'unica_escolha', 'multipla_escolha', 'lista_suspensa'];

/** Pergunta na ordem da tela: cada pergunta seguida das condicionais dela (na mesma seção). */
export interface PerguntaOrdenada extends PerguntaDoFormulario {
	pai: PerguntaDoFormulario | null;
	/** Texto da opção da pai que faz esta aparecer. */
	opcaoDoPai: string;
	/** Condicional que fica recuada logo abaixo da pai. */
	filha: boolean;
}

export function ordenarComFilhas(perguntas: PerguntaDoFormulario[]): PerguntaOrdenada[] {
	const donoDaOpcao = new Map<number, PerguntaDoFormulario>();
	for (const p of perguntas) for (const o of p.options) donoDaOpcao.set(o.id, p);
	const comPai = perguntas.map((p) => {
		const pai = p.depends_on_option_id !== null ? (donoDaOpcao.get(p.depends_on_option_id) ?? null) : null;
		return {
			...p,
			pai,
			opcaoDoPai: pai?.options.find((o) => o.id === p.depends_on_option_id)?.text ?? '',
			filha: !!pai && pai.section.id === p.section.id
		};
	});

	const resultado: PerguntaOrdenada[] = [];
	const secoes = [...new Set(comPai.map((p) => p.section.id))];
	for (const secaoId of secoes) {
		const daSecao = comPai.filter((p) => p.section.id === secaoId);
		for (const topo of daSecao.filter((p) => !p.filha)) {
			resultado.push(topo);
			resultado.push(...daSecao.filter((c) => c.filha && c.pai?.id === topo.id));
		}
	}
	return resultado;
}

/** Ids das opções marcadas (escolha única = um id; múltipla = JSON de ids). */
export function opcoesMarcadas(bruta: string | undefined): number[] {
	if (!bruta) return [];
	if (/^\d+$/.test(bruta)) return [Number(bruta)];
	try {
		const lista = JSON.parse(bruta);
		return Array.isArray(lista) ? lista.map(Number) : [];
	} catch {
		return [];
	}
}

export function marcou(p: PessoaComRespostas, perguntaId: number, opcaoId: number): boolean {
	return opcoesMarcadas(p.respostas[String(perguntaId)]).includes(opcaoId);
}

/** A resposta como texto para a tela e a planilha; '' quando não respondeu. */
export function textoDaResposta(pergunta: PerguntaDoFormulario, pessoa: PessoaComRespostas): string {
	const bruta = pessoa.respostas[String(pergunta.id)];
	if (bruta === undefined || bruta === null || bruta === '') return '';
	// Arquivo: a planilha e a tabela dizem só se foi enviado (o arquivo abre pelo painel).
	if (pergunta.type === 'arquivo') return pessoa.arquivos[String(pergunta.id)] ? 'Enviado' : '';
	if (TIPOS_DE_ESCOLHA.includes(pergunta.type)) {
		const ids = opcoesMarcadas(bruta);
		// Opção removida do formulário: a escolha deixa de aparecer.
		return pergunta.options
			.filter((o) => ids.includes(o.id))
			.map((o) => o.text)
			.join(', ');
	}
	if (pergunta.type === 'data' && /^\d{4}-\d{2}-\d{2}$/.test(bruta)) {
		const [a, m, d] = bruta.split('-');
		return `${d}/${m}/${a}`;
	}
	return bruta;
}

/** O arquivo enviado na pergunta de arquivo, se houver. */
export function arquivoDa(pergunta: PerguntaDoFormulario, pessoa: PessoaComRespostas) {
	if (pergunta.type !== 'arquivo' || !pessoa.respostas[String(pergunta.id)]) return null;
	return pessoa.arquivos[String(pergunta.id)] ?? null;
}

/** A pergunta vale para a pessoa? Condicional só vale se ela marcou a opção da pai. */
export function valePara(pergunta: PerguntaOrdenada, pessoa: PessoaComRespostas): boolean {
	return !pergunta.pai || marcou(pessoa, pergunta.pai.id, pergunta.depends_on_option_id as number);
}

/** Perguntas de saúde em que "Sim" pede atenção (remédio, restrição médica, alergia, restrição alimentar). */
export function ehAlertaDeSaude(pergunta: PerguntaDoFormulario): boolean {
	if (pergunta.type !== 'sim_nao') return false;
	return /(remedio|medicament|alergi|restric|cirurgi|doenca|diabet|asma|epileps)/.test(normalizar(pergunta.text));
}

// ─── Planilhas ──────────────────────────────────────────────────────────

/** CSV com ";" (o Excel em português abre direto) e BOM para os acentos. */
export function montarCsv(colunas: string[], linhas: string[][]): string {
	const celula = (v: string) => (/[;"\n\r]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);
	return '﻿' + [colunas, ...linhas].map((l) => l.map((v) => celula(v ?? '')).join(';')).join('\r\n');
}

export function baixarArquivo(nome: string, conteudo: string): void {
	const url = URL.createObjectURL(new Blob([conteudo], { type: 'text/csv;charset=utf-8' }));
	const link = document.createElement('a');
	link.href = url;
	link.download = nome;
	document.body.appendChild(link);
	link.click();
	link.remove();
	setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** "Juvenil · Campista" + "Acampamento Juvenil 2026" → "respostas-juvenil-campista-juvenil-2026.csv" */
export function nomeDeArquivo(...partes: string[]): string {
	const slug = partes
		.map((p) => normalizar(p).replace(/^acampamento\s+/, ''))
		.join(' ')
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-|-$/g, '');
	return `${slug}.csv`;
}

// ─── Datas ──────────────────────────────────────────────────────────────

/** "2026-11-27" + "2026-11-29" → "27 a 29/11"; meses diferentes → "30/11 a 02/12". */
export function intervaloDeDias(inicio: string, fim: string): string {
	const [, mi, di] = inicio.split('-');
	const [, mf, df] = fim.split('-');
	if (inicio === fim) return `${di}/${mi}`;
	return mi === mf ? `${di} a ${df}/${mf}` : `${di}/${mi} a ${df}/${mf}`;
}

/** "2026-11-08T19:42:10" → "08/11 às 19:42" */
export function diaEHora(iso: string): string {
	const [data, hora = ''] = iso.split('T');
	const [, m, d] = data.split('-');
	return hora ? `${d}/${m} às ${hora.slice(0, 5)}` : `${d}/${m}`;
}

/** Dias inteiros até o prazo (0 = vence hoje; negativo = já passou). */
export function diasAte(iso: string): number {
	const fim = new Date(iso);
	const hoje = new Date();
	fim.setHours(0, 0, 0, 0);
	hoje.setHours(0, 0, 0, 0);
	return Math.round((fim.getTime() - hoje.getTime()) / 86_400_000);
}
