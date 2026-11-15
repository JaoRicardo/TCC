<script lang="ts">
	/** Aba Respostas de um formulário de evento. */
	import { page } from '$app/stores';
	import RespostasFormulario from '$lib/components/painel/RespostasFormulario.svelte';
	import { doEvento } from '$lib/formularios';

	let alvo = $derived.by(() => {
		const id = Number($page.params.id);
		return Number.isInteger(id) && id > 0 ? doEvento(id) : null;
	});
</script>

<!-- Recomeça a tela ao trocar de formulário sem sair da rota. -->
{#key JSON.stringify(alvo)}
	<RespostasFormulario {alvo} />
{/key}
