<script lang="ts">
	import { onMount } from 'svelte';

	onMount(() => {
		window.location.href = '/login';
	});
</script>

<svelte:head>
	<title>Redirecionando... | Acampamento São Miguel</title>
</svelte:head>

<div style="display: flex; justify-content: center; align-items: center; height: 100vh;">
	<p>Redirecionando para o login...</p>
</div>
