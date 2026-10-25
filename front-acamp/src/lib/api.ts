import { loginUrl } from '$lib/redirect';

export const API_BASE_URL = import.meta.env.VITE_API_URL;
export const API_HOST = API_BASE_URL.replace('/api/v1', '');

type ApiFetchOptions = RequestInit & {
	/**
	 * false = um 401 não manda para o login. Usado ao conferir a sessão salva:
	 * nas telas públicas, um token vencido só deixa a pessoa como visitante.
	 */
	redirectOnUnauthorized?: boolean;
};

export async function apiFetch(endpoint: string, { redirectOnUnauthorized = true, ...options }: ApiFetchOptions = {}) {
	const token = typeof window !== 'undefined' ? localStorage.getItem('token') : null;

	const headers = new Headers(options.headers);
	headers.set('Accept', 'application/json');

	if (!(options.body instanceof FormData)) {
		headers.set('Content-Type', 'application/json');
	}

	if (token) {
		headers.set('Authorization', `Bearer ${token}`);
	}

	const response = await fetch(`${API_BASE_URL}${endpoint}`, {
		...options,
		headers
	});

	if (response.status === 401 && typeof window !== 'undefined') {
		localStorage.removeItem('token');
		if (redirectOnUnauthorized && window.location.pathname !== '/login') {
			window.location.href = loginUrl(window.location.pathname + window.location.search);
		}
	}

	return response;
}
