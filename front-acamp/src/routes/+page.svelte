<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';

	// A vitrine de acampamentos e eventos é pública: quem chega no site vê
	// a lista direto, logado ou não.
	onMount(() => {
		goto('/dashboard', { replaceState: true });
	});
</script>

<svelte:head>
	<title>Redirecionando... | Acampamento São Miguel</title>
</svelte:head>

<div style="display: flex; justify-content: center; align-items: center; height: 100vh;">
	<p>Carregando acampamentos e eventos...</p>
</div>
