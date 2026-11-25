// Regras das barras de pesquisa das listas do painel.

/** Ignora acentos e maiúsculas. */
export const semAcento = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * Dígitos do termo quando ele parece um CPF (só números, pontos, hífens e espaços,
 * com pelo menos 3 números); senão ''. Assim "joao2@gmail.com" ou "Maria 2" não
 * viram busca por CPF e não trazem todo mundo que tem um 2 no CPF.
 */
export function digitosDeCpf(termo: string): string {
	const t = termo.trim();
	if (!/^[\d.\-\s]+$/.test(t)) return '';
	const d = t.replace(/\D/g, '');
	return d.length >= 3 ? d : '';
}

/** O CPF contém os dígitos buscados (vindos de `digitosDeCpf`). */
export const cpfContem = (cpf: string | null | undefined, digitos: string) =>
	digitos !== '' && (cpf ?? '').replace(/\D/g, '').includes(digitos);
