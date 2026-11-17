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
 * Id da atividade quando a pessoa veio do "Inscrever-se"
 * (`/dashboard/atividades/19?inscrever=1`); null em qualquer outro caso.
 */
export function atividadeDaInscricao(destino: string | null): number | null {
	const partes = destino ? /^\/dashboard\/atividades\/(\d+)\?(.*&)?inscrever=1/.exec(destino) : null;
	return partes ? Number(partes[1]) : null;
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

/**
 * Tela em que fica presa quem entrou com a senha provisória gerada pela
 * organização, até criar a própria senha (o servidor recusa todo o resto).
 */
export const TELA_CRIAR_SENHA = '/dashboard/perfil/senha';

/** Leva para criar a senha e, depois, para `destino` (se for uma tela que faça sentido). */
export function criarSenhaUrl(destino: string | null): string {
	const seguro = destinoSeguro(destino);
	if (!seguro || seguro === '/' || seguro.startsWith(TELA_CRIAR_SENHA)) return TELA_CRIAR_SENHA;
	// Saindo do login ou do cadastro: segue para onde eles iam levar.
	const url = new URL(seguro, 'http://site');
	if (url.pathname === '/login' || url.pathname === '/registro') return criarSenhaUrl(url.searchParams.get('redirect'));
	return `${TELA_CRIAR_SENHA}?redirect=${encodeURIComponent(seguro)}`;
}
