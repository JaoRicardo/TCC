import { auth } from '$lib/stores/auth.svelte';

/**
 * Permissões por tela do painel (a lista oficial fica no backend, em
 * PermissoesService). Quem tem acesso limitado não deve perceber: o menu só
 * mostra as telas liberadas e, se a pessoa abrir uma tela bloqueada pelo
 * endereço, volta sem aviso para a primeira tela que pode ver.
 */

type ItemDoMenu = { chave: string; caminho: string; nome: string };

/**
 * Menu lateral do painel: grupos e itens, na ordem em que aparecem. A primeira
 * tela liberada desta lista é onde a pessoa entra no painel (Pessoas e acessos
 * vem primeiro a pedido do usuário).
 */
export const MENU_DO_PAINEL: { titulo: string; itens: ItemDoMenu[] }[] = [
	{
		titulo: 'Pessoas',
		itens: [{ chave: 'usuarios', caminho: '/admin/usuarios', nome: 'Pessoas e acessos' }]
	},
	{
		titulo: 'Atividades',
		itens: [
			{ chave: 'atividades', caminho: '/admin/atividades', nome: 'Gestão de Atividades' },
			{ chave: 'acampamentos', caminho: '/admin/acampamentos/criar', nome: 'Criar Acampamento' },
			{ chave: 'eventos', caminho: '/admin/eventos/criar', nome: 'Criar Evento' }
		]
	},
	{
		titulo: 'Servos e formulários',
		itens: [
			{ chave: 'setores', caminho: '/admin/setores', nome: 'Setores de Servos' },
			{ chave: 'formularios', caminho: '/admin/formularios', nome: 'Formulários' }
		]
	}
];

const ITENS_DO_MENU: ItemDoMenu[] = MENU_DO_PAINEL.flatMap((grupo) => grupo.itens);

/** Telas que não estão no menu: nome no caminho da barra superior e item do menu que fica marcado. */
const TELAS_FORA_DO_MENU: [string, string, string][] = [
	['/admin/inscricoes', 'Inscritos e Sorteios', '/admin/atividades'],
	['/admin/acampamentos/editar', 'Editar acampamento', '/admin/acampamentos/criar']
];

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
	return ITENS_DO_MENU.find((item) => pode(item.chave))?.caminho ?? null;
}

/** Grupos do menu só com as telas liberadas; grupo que fica vazio some. */
export function menuDaPessoa() {
	return MENU_DO_PAINEL.map((grupo) => ({
		titulo: grupo.titulo,
		itens: grupo.itens.filter((item) => pode(item.chave))
	})).filter((grupo) => grupo.itens.length > 0);
}

/** Nome da tela aberta e o item do menu que fica marcado (null fora do menu). */
export function telaAtual(caminho: string): { nome: string; itemDoMenu: string | null } {
	const fora = TELAS_FORA_DO_MENU.find(([inicio]) => caminho.startsWith(inicio));
	if (fora) return { nome: fora[1], itemDoMenu: fora[2] };

	const item = ITENS_DO_MENU.find((i) => caminho.startsWith(i.caminho.replace('/criar', '')));
	return item ? { nome: item.nome, itemDoMenu: item.caminho } : { nome: '', itemDoMenu: null };
}
