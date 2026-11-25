import { describe, expect, it } from 'vitest';
import { atividadeDaInscricao, criarSenhaUrl, destinoSeguro, loginUrl } from './redirect';

describe('destinoSeguro (não deixa o login levar para outro site)', () => {
	it.each(['/dashboard', '/dashboard/atividades/19?inscrever=1'])('aceita "%s"', (d) => expect(destinoSeguro(d)).toBe(d));
	it.each(['https://outro-site.com', '//outro-site.com', '/\\outro-site.com', 'javascript:alert(1)', '', null])(
		'recusa %s',
		(d) => expect(destinoSeguro(d)).toBeNull()
	);
});

describe('voltar para a atividade depois do login', () => {
	it('loginUrl codifica o destino', () => {
		expect(loginUrl('/dashboard/atividades/19?inscrever=1')).toBe('/login?redirect=%2Fdashboard%2Fatividades%2F19%3Finscrever%3D1');
	});
	it.each([
		['/dashboard/atividades/19?inscrever=1', 19],
		['/dashboard/atividades/19?aba=x&inscrever=1', 19],
		['/dashboard/atividades/19', null],
		['/admin/atividades/19?inscrever=1', null],
		[null, null]
	])('%s → %s', (d, id) => expect(atividadeDaInscricao(d)).toBe(id));
});

describe('criarSenhaUrl (senha provisória)', () => {
	it('sem destino fica na tela de criar senha', () => {
		expect(criarSenhaUrl(null)).toBe('/dashboard/perfil/senha');
		expect(criarSenhaUrl('/')).toBe('/dashboard/perfil/senha');
	});
	it('guarda o destino', () => {
		expect(criarSenhaUrl('/admin/usuarios')).toBe('/dashboard/perfil/senha?redirect=%2Fadmin%2Fusuarios');
	});
	it('vindo do login, segue o destino do login', () => {
		expect(criarSenhaUrl('/login?redirect=%2Fadmin%2Fusuarios')).toBe('/dashboard/perfil/senha?redirect=%2Fadmin%2Fusuarios');
	});
	it('destino de fora do site é ignorado', () => {
		expect(criarSenhaUrl('https://outro-site.com')).toBe('/dashboard/perfil/senha');
	});
});
