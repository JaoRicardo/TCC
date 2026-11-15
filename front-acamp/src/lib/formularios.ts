/** Ajudas comuns à lista e ao editor da tela Formulários do painel. */

export type TipoInscricao = 'Campista' | 'Servo';

/** "Juvenil · Campista" */
export function tituloDoFormulario(categoria: string, tipo: TipoInscricao): string {
	return `${categoria} · ${tipo}`;
}

/** Endereço do editor: /admin/formularios/{categoria}/campista|servo */
export function enderecoDoEditor(categoryId: number, tipo: TipoInscricao): string {
	return `/admin/formularios/${categoryId}/${tipo.toLowerCase()}`;
}

/** "2026-11-20T23:59:00" → "20/11" */
export function diaEMes(iso: string): string {
	const [data] = iso.split('T');
	const [, mes, dia] = data.split('-');
	return `${dia}/${mes}`;
}

/** "Acampamento Juvenil 2026" → "Juvenil 2026", para os selos curtos da lista. */
export function nomeCurto(nomeDoAcampamento: string): string {
	return nomeDoAcampamento.replace(/^acampamento\s+/i, '');
}

/** Para buscar ignorando acentos e maiúsculas. */
export function normalizar(texto: string): string {
	return (texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/** Quem precisa enviar o formulário, no plural: "campistas" ou "servos". */
export function quemEnvia(tipo: TipoInscricao): string {
	return tipo === 'Servo' ? 'servos' : 'campistas';
}
