<script lang="ts">
	/**
	 * Janela do painel (no lugar de confirm/alert do navegador). Fecha com Esc e
	 * com clique fora, prende o foco dentro dela e devolve o foco a quem a abriu.
	 * O botão com `data-foco-inicial` recebe o foco ao abrir (ex.: "Cancelar").
	 */
	import { onMount, type Snippet } from 'svelte';

	let {
		idTitulo,
		largura = 600,
		faixa = 'var(--vitral)',
		travada = false,
		lateral = false,
		onfechar,
		children
	}: {
		idTitulo: string;
		largura?: number;
		/** Cor da faixa de 6px no topo. */
		faixa?: string;
		/** true enquanto salva: não fecha. */
		travada?: boolean;
		/** Painel que entra pela direita, com a altura toda. O conteúdo monta o próprio miolo. */
		lateral?: boolean;
		onfechar: () => void;
		children: Snippet;
	} = $props();

	let janela: HTMLDivElement;

	const FOCAVEIS = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	onMount(() => {
		const antes = document.activeElement as HTMLElement | null;
		const inicial = janela.querySelector<HTMLElement>('[data-foco-inicial]') ?? janela.querySelector<HTMLElement>(FOCAVEIS);
		(inicial ?? janela).focus();
		return () => antes?.focus?.();
	});

	function tecla(e: KeyboardEvent) {
		if (e.key === 'Escape') {
			e.stopPropagation();
			if (!travada) onfechar();
			return;
		}
		if (e.key !== 'Tab') return;
		const itens = [...janela.querySelectorAll<HTMLElement>(FOCAVEIS)];
		if (itens.length === 0) return;
		const primeiro = itens[0];
		const ultimo = itens[itens.length - 1];
		if (e.shiftKey && document.activeElement === primeiro) {
			e.preventDefault();
			ultimo.focus();
		} else if (!e.shiftKey && document.activeElement === ultimo) {
			e.preventDefault();
			primeiro.focus();
		}
	}
</script>

<!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
<div
	class="fm-fundo"
	class:fm-fundo--lateral={lateral}
	onclick={(e) => e.target === e.currentTarget && !travada && onfechar()}
	onkeydown={tecla}
>
	<div
		bind:this={janela}
		class="fm-janela"
		class:fm-janela--lateral={lateral}
		style="max-width:{largura}px"
		role="dialog"
		aria-modal="true"
		aria-labelledby={idTitulo}
		tabindex="-1"
	>
		<div class="fm-janela-faixa" style="background:{faixa}"></div>
		{#if lateral}
			{@render children()}
		{:else}
			<div class="fm-janela-corpo">
				{@render children()}
			</div>
		{/if}
	</div>
</div>
