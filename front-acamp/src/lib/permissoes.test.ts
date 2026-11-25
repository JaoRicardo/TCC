import { afterEach, describe, expect, it } from 'vitest';
import { auth } from './stores/auth.svelte';
import { acessaPainel, menuDaPessoa, podeAbrir, primeiraTela, telaAtual } from './permissoes';

/** Entra como alguém com estas permissões. */
const comPermissoes = (...permissoes: string[]) => {
	auth.user = { id: 1, name: 'Teste', permissoes };
};
afterEach(() => {
	auth.user = null;
});

const TODAS = ['usuarios', 'atividades', 'acampamentos', 'eventos', 'inscricoes', 'equipes', 'setores', 'formularios', 'respostas_formularios', 'categorias', 'participacoes'];

describe('quem entra no painel', () => {
	it('sem nenhuma permissão, não entra', () => {
		comPermissoes();
		expect(acessaPainel()).toBe(false);
		expect(primeiraTela()).toBeNull();
		expect(menuDaPessoa()).toEqual([]);
	});
	it('deslogado, não entra', () => {
		expect(acessaPainel()).toBe(false);
	});
	it('com todas, começa em Pessoas e acessos', () => {
		comPermissoes(...TODAS);
		expect(primeiraTela()).toBe('/admin/usuarios');
	});
});

describe('acesso limitado não deve aparecer', () => {
	it('só Categorias: menu só com Gestão › Categorias', () => {
		comPermissoes('categorias');
		expect(menuDaPessoa()).toEqual([{ titulo: 'Gestão', itens: [expect.objectContaining({ caminho: '/admin/categorias' })] }]);
		expect(primeiraTela()).toBe('/admin/categorias');
	});
	it('só respostas: entra em Formulários, mas não no editor de outra coisa', () => {
		comPermissoes('respostas_formularios');
		expect(podeAbrir('/admin/formularios')).toBe(true);
		expect(podeAbrir('/admin/formularios/3/campista/respostas')).toBe(true);
		expect(podeAbrir('/admin/usuarios')).toBe(false);
	});
	it('só modelo de setores: entra em Equipes de Servos e no endereço antigo', () => {
		comPermissoes('setores');
		expect(podeAbrir('/admin/equipes')).toBe(true);
		expect(podeAbrir('/admin/setores')).toBe(true);
		expect(podeAbrir('/admin/inscricoes/5')).toBe(false);
	});
	it.each([
		['/admin/usuarios', 'usuarios'],
		['/admin/pessoas/12', 'usuarios'],
		['/admin/acampamentos/editar/4', 'acampamentos'],
		['/admin/eventos/criar', 'eventos'],
		['/admin/inscricoes/7', 'inscricoes'],
		['/admin/participacoes', 'participacoes']
	])('%s exige "%s"', (caminho, chave) => {
		comPermissoes(...TODAS.filter((p) => p !== chave));
		expect(podeAbrir(caminho)).toBe(false);
		comPermissoes(chave);
		expect(podeAbrir(caminho)).toBe(true);
	});
	it('cada item do menu abre para quem o vê no menu', () => {
		for (const chave of TODAS) {
			comPermissoes(chave);
			for (const grupo of menuDaPessoa()) for (const item of grupo.itens) expect(podeAbrir(item.caminho), `${chave} → ${item.caminho}`).toBe(true);
		}
	});
});

describe('telaAtual (barra superior)', () => {
	it.each([
		['/admin/pessoas/12', 'Pessoas e acessos', '/admin/usuarios'],
		['/admin/inscricoes/7', 'Inscritos e sorteio', '/admin/atividades'],
		['/admin/acampamentos/criar', 'Criar Acampamento', '/admin/acampamentos/criar'],
		['/admin/categorias', 'Categorias', '/admin/categorias']
	])('%s', (caminho, nome, item) => expect(telaAtual(caminho)).toEqual({ nome, itemDoMenu: item }));
});
