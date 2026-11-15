<script lang="ts">
	/** Editor de um formulário de evento. */
	import { page } from '$app/stores';
	import EditorFormulario from '$lib/components/painel/EditorFormulario.svelte';
	import { doEvento } from '$lib/formularios';

	let alvo = $derived.by(() => {
		const id = Number($page.params.id);
		return Number.isInteger(id) && id > 0 ? doEvento(id) : null;
	});
</script>

<!-- Recomeça a tela ao trocar de formulário sem sair da rota. -->
{#key JSON.stringify(alvo)}
	<EditorFormulario {alvo} />
{/key}
