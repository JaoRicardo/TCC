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

			const response = await apiFetch('/users/me');
			if (response.ok) {
				const data = await response.json();
				this.user = data.user;
			} else {
				this.clearToken();
			}
		} catch (error) {
			console.error("Erro ao verificar token:", error);
			this.clearToken();
		} finally {
			this.isLoading = false;
		}
	}
}

export const auth = new AuthStore();
