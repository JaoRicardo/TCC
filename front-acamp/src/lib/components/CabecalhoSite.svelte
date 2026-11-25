<script lang="ts">
	/**
	 * A barra superior de todas as telas (Início, páginas do participante e
	 * Painel): banner, a pessoa logada, "Meu cadastro", atalho do Painel para
	 * quem tem acesso e "Sair". Visitante (telas públicas) vê "Entrar".
	 *
	 * - `semPainel`: no próprio Painel o atalho "Painel" não aparece.
	 * - `aoSair`: o que fazer depois de sair (padrão: voltar ao Início).
	 */
	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { loginUrl } from '$lib/redirect';
	import { auth } from '$lib/stores/auth.svelte';
	import { acessaPainel, primeiraTela } from '$lib/permissoes';
	import banner from '$lib/imgs/banner.png';

	let { semPainel = false, aoSair }: { semPainel?: boolean; aoSair?: () => void } = $props();

	function sair() {
		auth.clearToken();
		if (aoSair) aoSair();
		else goto('/dashboard');
	}
</script>

<!-- Com senha provisória, a pessoa não sai da tela de criar a senha: só "Sair". -->
<header class="cabecalho">
	{#if auth.user?.trocar_senha}
		<img src={banner} alt="Comunidade São Miguel do Arcanjo" class="banner" />
	{:else}
		<a href="/dashboard" aria-label="Início"><img src={banner} alt="Comunidade São Miguel do Arcanjo" class="banner" /></a>
	{/if}
	{#if auth.isLoading}
		<!-- conferindo a sessão salva -->
	{:else if auth.isAuthenticated && auth.user}
		<div class="usuario">
			<div class="usuario-saudacao">
				<span class="avatar" aria-hidden="true">{auth.user.name?.charAt(0).toUpperCase()}</span>
				<span class="usuario-nome">
					<span class="usuario-bemvindo">Bem-vindo(a),</span>
					<span class="usuario-primeiro-nome">{auth.user.name?.split(' ')[0]}</span>
				</span>
			</div>
			{#if !auth.user.trocar_senha}
				<a href="/dashboard/perfil" class="botao">Meu cadastro</a>
				{#if !semPainel && acessaPainel()}
					<a href={primeiraTela()} class="botao botao--painel">Painel</a>
				{/if}
			{/if}
			<button type="button" class="botao" onclick={sair}>Sair</button>
		</div>
	{:else}
		<a href={loginUrl($page.url.pathname + $page.url.search)} class="botao--entrar">Entrar</a>
	{/if}
</header>

<style>
	.cabecalho {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
		padding: 10px clamp(12px, 3vw, 48px);
		font-family: 'Source Sans 3', system-ui, sans-serif;
		background: #fffdf8;
		border-bottom: 1px solid #d9cca6;
		/* filete dourado duplo */
		box-shadow:
			0 3px 0 -1px #fffdf8,
			0 4px 0 -1px #b69e55;
		position: relative;
		z-index: 2;
	}

	.banner {
		display: block;
		height: clamp(44px, 4.2vw, 60px);
		width: auto;
	}

	.usuario {
		display: flex;
		align-items: center;
		gap: clamp(8px, 1.1vw, 16px);
	}

	.usuario-saudacao {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.avatar {
		width: 42px;
		height: 42px;
		box-sizing: border-box;
		flex: 0 0 auto;
		border-radius: 50%;
		background: #1b2f5e;
		border: 2px solid #b69e55;
		display: flex;
		align-items: center;
		justify-content: center;
		font: 700 22px 'Cormorant Garamond', serif;
		color: #e2ce8a;
	}

	.usuario-nome {
		display: flex;
		flex-direction: column;
		line-height: 1.15;
	}

	.usuario-bemvindo {
		font-size: 13px;
		color: #5a5f72;
	}

	.usuario-primeiro-nome {
		font-size: 17px;
		font-weight: 700;
		color: #1e2233;
	}

	.botao {
		font: inherit;
		font-size: 15px;
		font-weight: 600;
		color: #1b2f5e;
		background: transparent;
		border: 1px solid #c9cfde;
		border-radius: 999px;
		padding: 9px 16px;
		cursor: pointer;
		white-space: nowrap;
		text-decoration: none;
		transition: background 0.2s;
	}

	.botao:hover {
		background: #eef1f7;
	}

	.botao--painel {
		color: #6b5822;
		background: #fbf6e6;
		border-color: #b69e55;
	}

	.botao--painel:hover {
		background: #f3e9c8;
	}

	.botao--entrar {
		font-size: 16px;
		font-weight: 600;
		color: #fffdf8;
		background: #1b2f5e;
		border: 1px solid #1b2f5e;
		border-radius: 999px;
		padding: 11px 26px;
		text-decoration: none;
		box-shadow:
			inset 0 0 0 2px #1b2f5e,
			inset 0 0 0 3px rgba(182, 158, 85, 0.8);
		transition: background 0.2s;
	}

	.botao--entrar:hover {
		background: #0f1d3d;
	}

	.cabecalho :is(a, button):focus-visible {
		outline: none;
		box-shadow: 0 0 0 4px rgba(182, 158, 85, 0.45);
	}
</style>
