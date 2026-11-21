<script lang="ts">
	/**
	 * Selo da fase da atividade (cartão do Início e topo da página de detalhes).
	 * Fundo sólido, borda e sombra: legível em foto clara, foto escura e no vitral.
	 */
	import { TEXTO_DA_FASE, type Fase } from '$lib/faseAtividade';

	let { fase }: { fase: Fase } = $props();
</script>

<span class="selo selo-{fase}">
	{#if fase === 'abertas' || fase === 'acontecendo'}<span class="ponto" aria-hidden="true"></span>{/if}
	{TEXTO_DA_FASE[fase]}
</span>

<style>
	.selo {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font: 700 12px 'Source Sans 3', system-ui, sans-serif;
		letter-spacing: 0.04em;
		text-transform: uppercase;
		border: 1px solid;
		border-radius: 999px;
		padding: 5px 10px;
		white-space: nowrap;
		box-shadow: 0 2px 8px rgba(15, 29, 61, 0.35);
	}

	.ponto {
		flex: 0 0 7px;
		width: 7px;
		height: 7px;
		border-radius: 50%;
	}

	.selo-emBreve {
		background: #fffdf8;
		color: #1b2f5e;
		border-color: #c9cfde;
	}

	.selo-abertas {
		background: #e2ce8a;
		color: #0f1d3d;
		border-color: #fff3c8;
	}

	.selo-abertas .ponto {
		background: #1f6b3f;
		box-shadow: 0 0 0 3px rgba(31, 107, 63, 0.2);
	}

	.selo-aguardandoSorteio {
		background: #e6ebf5;
		color: #21376a;
		border-color: #afbedd;
	}

	.selo-inscricoesEncerradas,
	.selo-encerrado {
		background: #f1ece0;
		color: #3d4152;
		border-color: #cdbe92;
	}

	.selo-acontecendo {
		background: #1f6b3f;
		color: #ffffff;
		border-color: #a8d5b6;
	}

	.selo-acontecendo .ponto {
		background: #ffffff;
		animation: ao-vivo 1.8s ease-out infinite;
	}

	@keyframes ao-vivo {
		from {
			box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.75);
		}
		to {
			box-shadow: 0 0 0 7px rgba(255, 255, 255, 0);
		}
	}

	@media (prefers-reduced-motion: reduce) {
		.selo-acontecendo .ponto {
			animation: none;
		}
	}
</style>
