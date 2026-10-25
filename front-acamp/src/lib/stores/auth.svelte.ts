import { apiFetch } from '$lib/api';

class AuthStore {
	user = $state<any>(null);
	token = $state<string | null>(null);
	isAuthenticated = $state(false);
	isLoading = $state(true);

	setToken(newToken: string) {
		this.token = newToken;
		this.isAuthenticated = true;
		if (typeof window !== 'undefined') {
			localStorage.setItem('token', newToken);
		}
	}

	clearToken() {
		this.user = null;
		this.token = null;
		this.isAuthenticated = false;
		if (typeof window !== 'undefined') {
			localStorage.removeItem('token');
		}
	}

	// Sai da sessão só nesta página; o token continua salvo para a próxima tentativa.
	forgetSession() {
		this.user = null;
		this.token = null;
		this.isAuthenticated = false;
	}

	// O login e o cadastro devolvem o usuário resumido; o perfil completo
	// (estado civil, endereço) vem de /users/me e a tela de inscrição depende dele.
	async refreshUser() {
		try {
			const response = await apiFetch('/users/me', { redirectOnUnauthorized: false });
			if (response.ok) {
				const data = await response.json();
				this.user = data.user;
			}
		} catch (error) {
			console.error("Erro ao carregar perfil:", error);
		}
	}

	async verifyToken() {
		this.isLoading = true;
		try {
			if (typeof window !== 'undefined') {
				const localToken = localStorage.getItem('token');
				if (!localToken) {
					this.clearToken();
					this.isLoading = false;
					return;
				}
				this.setToken(localToken);
			}

			const response = await apiFetch('/users/me', { redirectOnUnauthorized: false });
			if (response.ok) {
				const data = await response.json();
				this.user = data.user;
			} else if (response.status === 401) {
				this.clearToken();
			} else {
				this.forgetSession();
			}
		} catch (error) {
			// Falha de rede ou requisição interrompida (ex.: trocou de página no meio).
			// Não prova que o login é inválido, então o token salvo é mantido.
			console.error("Erro ao verificar token:", error);
			this.forgetSession();
		} finally {
			this.isLoading = false;
		}
	}
}

export const auth = new AuthStore();
