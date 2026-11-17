// Máscaras dos campos de login e cadastro (formatam enquanto a pessoa digita).

const digitos = (valor: string, max: number) => valor.replace(/\D/g, '').slice(0, max);

/** 000.000.000-00 */
export function mascaraCpf(valor: string): string {
	return digitos(valor, 11)
		.replace(/(\d{3})(\d)/, '$1.$2')
		.replace(/(\d{3})(\d)/, '$1.$2')
		.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
}

/** (00) 00000-0000 */
export function mascaraTelefone(valor: string): string {
	return digitos(valor, 11)
		.replace(/^(\d{2})(\d)/, '($1) $2')
		.replace(/(\d{5})(\d{1,4})$/, '$1-$2');
}

/** dd/mm/aaaa */
export function mascaraData(valor: string): string {
	return digitos(valor, 8)
		.replace(/(\d{2})(\d)/, '$1/$2')
		.replace(/(\d{2})(\d)/, '$1/$2');
}

/**
 * "dd/mm/aaaa" → "aaaa-mm-dd", o formato que a API espera.
 * null se a data não existe (ex.: 31/02/1990) ou está incompleta.
 */
export function dataParaIso(valor: string): string | null {
	const partes = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(valor);
	if (!partes) return null;
	const [, dia, mes, ano] = partes.map(Number);
	const data = new Date(ano, mes - 1, dia);
	if (data.getFullYear() !== ano || data.getMonth() !== mes - 1 || data.getDate() !== dia) {
		return null;
	}
	return `${partes[3]}-${partes[2]}-${partes[1]}`;
}

/** 00000-000 */
export function mascaraCep(valor: string): string {
	return digitos(valor, 8).replace(/(\d{5})(\d{1,3})$/, '$1-$2');
}

/** (00) 0000-0000 enquanto tem até 10 números; (00) 00000-0000 com 11 (fixo ou celular). */
export function mascaraTelefoneOuFixo(valor: string): string {
	const d = digitos(valor, 11);
	if (d.length <= 2) return d ? `(${d}` : '';
	if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
	if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
	return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
}

/** CPF com os dígitos verificadores certos (e não todos iguais). */
export function cpfValido(valor: string): boolean {
	const d = valor.replace(/\D/g, '');
	if (d.length !== 11 || /^(\d)\1{10}$/.test(d)) return false;
	const dv = (n: number) => {
		let soma = 0;
		for (let i = 0; i < n; i++) soma += Number(d[i]) * (n + 1 - i);
		return ((soma * 10) % 11) % 10;
	};
	return dv(9) === Number(d[9]) && dv(10) === Number(d[10]);
}
