<script lang="ts">
	// Moldura das telas de acesso (login e criar conta): cabeçalho com o link de
	// volta para a lista, e o card com o formulário ao lado do painel de vitral.
	import type { Snippet } from "svelte";
	import "$lib/styles/acesso.css";
	import banner from "$lib/imgs/banner.png";
	import logoAsas from "$lib/imgs/logo-asas.png";

	let { variante, children }: { variante: "login" | "cadastro"; children: Snippet } = $props();
</script>

<svelte:head>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link
		href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap"
		rel="stylesheet"
	/>
</svelte:head>

<div class="ac-pagina">
	<header class="ac-cabecalho">
		<img src={banner} alt="Comunidade São Miguel do Arcanjo" class="ac-banner" />
		<a href="/dashboard" class="ac-voltar">← Voltar para acampamentos e eventos</a>
	</header>

	<main class="ac-conteudo">
		<div class="ac-card ac-card--{variante}">
			{@render children()}

			<div class="ac-vitral">
				<div class="ac-arco">
					<img src={logoAsas} alt="Comunidade São Miguel Arcanjo — Primavera do Leste" />
				</div>
			</div>
		</div>
	</main>
</div>

<style>
	:global(body) {
		margin: 0;
		background: #f7f2e7;
	}
</style>
