// Volta para a tela de onde a pessoa saiu depois de entrar ou criar a conta
// (ex.: clicou em "Inscrever-se" sem estar logada).

/** Link para o login que, depois de entrar, leva a pessoa para `destino`. */
export function loginUrl(destino: string): string {
	return `/login?redirect=${encodeURIComponent(destino)}`;
}

export function registroUrl(destino: string | null): string {
	return destino ? `/registro?redirect=${encodeURIComponent(destino)}` : '/registro';
}

/**
 * Só aceita caminhos deste próprio site — um `?redirect=https://outro-site`
 * não pode levar a pessoa para fora depois do login.
 */
export function destinoSeguro(destino: string | null): string | null {
	if (!destino || !destino.startsWith('/') || destino.startsWith('//') || destino.startsWith('/\\')) {
		return null;
	}
	return destino;
}
