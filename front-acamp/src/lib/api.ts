export const API_BASE_URL = import.meta.env.VITE_API_URL;
export const API_HOST = API_BASE_URL.replace('/api/v1', '');

export async function apiFetch(endpoint: string, options: RequestInit = {}) {
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
		if (window.location.pathname !== '/login') {
			window.location.href = '/login';
		}
	}

	return response;
}
