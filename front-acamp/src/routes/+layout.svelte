<script lang="ts">
	import favicon from '$lib/imgs/favicon-16x16.png';
	import '../app.css';
	import { onMount } from 'svelte';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { auth } from '$lib/stores/auth.svelte';
	import { TELA_CRIAR_SENHA, criarSenhaUrl } from '$lib/redirect';

	let { children } = $props();

	onMount(() => {
		auth.verifyToken();
	});

	// Quem entrou com a senha provisória só vê a tela de criar a senha: qualquer
	// outra página do site ou do painel leva de volta para ela.
	let presoNaSenha = $derived(!!auth.user?.trocar_senha && $page.url.pathname !== TELA_CRIAR_SENHA);

	$effect(() => {
		if (presoNaSenha) goto(criarSenhaUrl($page.url.pathname + $page.url.search), { replaceState: true });
	});
</script>

<svelte:head>
	<link rel="icon" href={favicon} />
</svelte:head>

{#if !presoNaSenha}
	{@render children()}
{/if}
