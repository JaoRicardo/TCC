import { auth } from '$lib/stores/auth.svelte';

/**
 * Permissões por tela do painel (a lista oficial fica no backend, em
 * PermissoesService). Quem tem acesso limitado não deve perceber: o menu só
 * mostra as telas liberadas e, se a pessoa abrir uma tela bloqueada pelo
 * endereço, volta sem aviso para a primeira tela que pode ver.
 */

/** Itens do menu do painel, na ordem em que aparecem. */
export const MENU_DO_PAINEL = [
	{ chave: 'usuarios', caminho: '/admin/usuarios', nome: 'Gestão de Usuários' },
	{ chave: 'eventos', caminho: '/admin/eventos/criar', nome: 'Criar Evento' },
	{ chave: 'acampamentos', caminho: '/admin/acampamentos/criar', nome: 'Criar Acampamento' },
	{ chave: 'atividades', caminho: '/admin/atividades', nome: 'Gestão de Atividades' },
	{ chave: 'setores', caminho: '/admin/setores', nome: 'Setores de Servos' },
	{ chave: 'formularios', caminho: '/admin/formularios', nome: 'Formulários' }
] as const;

/** Começo do endereço → permissão que a tela exige. */
const TELA_POR_CAMINHO: [string, string][] = [
	['/admin/usuarios', 'usuarios'],
	['/admin/eventos', 'eventos'],
	['/admin/acampamentos', 'acampamentos'],
	['/admin/atividades', 'atividades'],
	['/admin/inscricoes', 'inscricoes'],
	['/admin/setores', 'setores'],
	['/admin/formularios', 'formularios']
];

export function pode(chave: string): boolean {
	return (auth.user?.permissoes ?? []).includes(chave);
}

/** Entra no painel quem tem pelo menos uma tela liberada. */
export function acessaPainel(): boolean {
	return (auth.user?.permissoes ?? []).length > 0;
}

export function podeAbrir(caminho: string): boolean {
	const tela = TELA_POR_CAMINHO.find(([inicio]) => caminho.startsWith(inicio));
	return tela ? pode(tela[1]) : true;
}

/** Primeira tela do menu que a pessoa pode ver — onde ela "começa" no painel. */
export function primeiraTela(): string | null {
	return MENU_DO_PAINEL.find((item) => pode(item.chave))?.caminho ?? null;
}
