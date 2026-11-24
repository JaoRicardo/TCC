import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

// O front repassa /api e /uploads para o backend (php -S em localhost:8765).
// Assim quem abre o site por outro aparelho da rede (npm run dev:rede) fala
// só com o front, e o backend continua só no localhost, sem mudar nenhum .env.
const backend = 'http://localhost:8765';

export default defineConfig({
	plugins: [sveltekit()],
	server: {
		port: 5173,
		proxy: {
			'/api': backend,
			'/uploads': backend
		}
	}
});
