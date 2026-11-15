<script lang="ts">
	/** Aba Respostas de um formulário de acampamento (categoria × Campista/Servo). */
	import { page } from '$app/stores';
	import RespostasFormulario from '$lib/components/painel/RespostasFormulario.svelte';
	import { doAcampamento } from '$lib/formularios';

	let alvo = $derived.by(() => {
		const categoryId = Number($page.params.categoria);
		const inscricao = $page.params.tipo === 'campista' ? 'Campista' : $page.params.tipo === 'servo' ? 'Servo' : null;
		return inscricao && Number.isInteger(categoryId) ? doAcampamento(categoryId, inscricao) : null;
	});
</script>

<!-- Recomeça a tela ao trocar de formulário sem sair da rota. -->
{#key JSON.stringify(alvo)}
	<RespostasFormulario {alvo} />
{/key}
