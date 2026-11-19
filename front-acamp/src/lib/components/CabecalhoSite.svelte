<script lang="ts">
	/**
	 * Cabeçalho das telas da área do participante (formulário, Meu cadastro):
	 * banner, a pessoa logada, "Meu cadastro", atalho do Painel para quem tem
	 * acesso e "Sair". Visitante (telas públicas, como a da atividade) vê "Entrar".
	 */
	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { loginUrl } from '$lib/redirect';
	import { auth } from '$lib/stores/auth.svelte';
	import { acessaPainel, primeiraTela } from '$lib/permissoes';
	import banner from '$lib/imgs/banner.png';

	function sair() {
		auth.clearToken();
		goto('/');
	}
</script>

<!-- Com senha provisória, a pessoa não sai da tela de criar a senha: só "Sair". -->
<header class="cabecalho">
	{#if auth.user?.trocar_senha}
		<img src={banner} alt="Comunidade São Miguel do Arcanjo" class="banner" />
	{:else}
		<a href="/dashboard" aria-label="Início"><img src={banner} alt="Comunidade São Miguel do Arcanjo" class="banner" /></a>
	{/if}
	{#if auth.user}
		<div class="usuario">
			<span class="avatar" aria-hidden="true">{auth.user.name?.charAt(0).toUpperCase()}</span>
			<span class="usuario-nome"><span>Bem-vindo(a),</span><strong>{auth.user.name?.split(' ')[0]}</strong></span>
			{#if !auth.user.trocar_senha}
				<a href="/dashboard/perfil" class="botao">Meu cadastro</a>
				{#if acessaPainel()}
					<a href={primeiraTela()} class="botao">Painel</a>
				{/if}
			{/if}
			<button type="button" class="botao" onclick={sair}>Sair</button>
		</div>
	{:else if !auth.isLoading}
		<a href={loginUrl($page.url.pathname + $page.url.search)} class="botao botao--entrar">Entrar</a>
	{/if}
</header>

<style>
	.cabecalho {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding: 10px clamp(16px, 4vw, 48px);
		font-family: 'Source Sans 3', system-ui, sans-serif;
		background: #fffdf8;
		border-bottom: 1px solid #d9cca6;
		box-shadow:
			0 3px 0 -1px #fffdf8,
			0 4px 0 -1px #b69e55;
	}

	.banner {
		display: block;
		height: clamp(40px, 9vw, 56px);
		width: auto;
	}

	.usuario {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.avatar {
		width: 40px;
		height: 40px;
		box-sizing: border-box;
		border-radius: 50%;
		background: #1b2f5e;
		border: 2px solid #b69e55;
		display: flex;
		align-items: center;
		justify-content: center;
		font: 700 21px 'Cormorant Garamond', serif;
		color: #e2ce8a;
	}

	.usuario-nome {
		display: none;
		flex-direction: column;
		line-height: 1.15;
		font-size: 13px;
		color: #5a5f72;
	}

	.usuario-nome strong {
		font-size: 17px;
		color: #1e2233;
	}

	.botao {
		height: 44px;
		padding: 0 16px;
		display: inline-flex;
		align-items: center;
		font: inherit;
		font-size: 15px;
		font-weight: 600;
		color: #1b2f5e;
		text-decoration: none;
		background: #fffdf8;
		border: 1px solid #d9cca6;
		border-radius: 999px;
		cursor: pointer;
	}

	.botao:hover {
		background: #fbf6e6;
	}

	.botao--entrar {
		padding: 0 26px;
		font-size: 16px;
		color: #fffdf8;
		background: #1b2f5e;
		border-color: #1b2f5e;
		box-shadow:
			inset 0 0 0 2px #1b2f5e,
			inset 0 0 0 3px rgba(182, 158, 85, 0.8);
	}

	.botao--entrar:hover {
		background: #0f1d3d;
	}

	.cabecalho :is(a, button):focus-visible {
		outline: none;
		box-shadow: 0 0 0 4px rgba(182, 158, 85, 0.45);
	}

	@media (min-width: 720px) {
		.usuario-nome {
			display: flex;
		}
	}
</style>
