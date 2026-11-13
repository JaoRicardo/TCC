<script lang="ts">
	import { auth } from '$lib/stores/auth.svelte';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { acessaPainel, menuDaPessoa, podeAbrir, primeiraTela, telaAtual } from '$lib/permissoes';
	import logoAsas from '$lib/imgs/logo-asas.png';

	let { children } = $props();

	// A tela atual está liberada para a pessoa? Se não estiver, ela volta sem aviso
	// para a primeira tela que pode ver — ninguém recebe "sem permissão".
	let telaLiberada = $derived(!auth.isLoading && acessaPainel() && podeAbrir($page.url.pathname));

	let tela = $derived(telaAtual($page.url.pathname));

	/** "João Pedro Ricardo de Souza" → "João Pedro", como no topo do design. */
	let nomeCurto = $derived((auth.user?.name ?? '').split(' ').slice(0, 2).join(' '));

	$effect(() => {
		if (!auth.isLoading) {
			if (!auth.isAuthenticated) {
				window.location.href = '/login';
			} else if (!acessaPainel()) {
				// Sem nenhuma tela liberada, o painel não existe para a pessoa.
				window.location.href = '/dashboard';
			} else if (!podeAbrir($page.url.pathname)) {
				goto(primeiraTela() ?? '/dashboard', { replaceState: true });
			}
		}
	});

	function handleLogout() {
		auth.clearToken();
		window.location.href = '/login';
	}
</script>

<svelte:head>
	<title>Painel da Comunidade | Acampamento São Miguel</title>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link
		href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap"
		rel="stylesheet"
	/>
</svelte:head>

{#if !telaLiberada}
	<div class="loading-state">
		<div class="spinner"></div>
		<p>Verificando credenciais...</p>
	</div>
{:else}
	<div class="admin-layout">
		<aside class="lateral">
			<div class="marca">
				<div class="marca-arco"><img src={logoAsas} alt="" /></div>
				<span class="marca-nome">Painel da Comunidade</span>
				<span class="marca-local">São Miguel Arcanjo</span>
			</div>

			<nav class="menu" aria-label="Telas do painel">
				{#each menuDaPessoa() as grupo}
					<div class="grupo">
						<span class="grupo-titulo">{grupo.titulo}</span>
						{#each grupo.itens as item}
							<a
								href={item.caminho}
								class="item"
								class:item--ativo={tela.itemDoMenu === item.caminho}
								aria-current={tela.itemDoMenu === item.caminho ? 'page' : undefined}
							>
								<span class="losango" aria-hidden="true"></span>{item.nome}
							</a>
						{/each}
					</div>
				{/each}
			</nav>

			<a href="/dashboard" class="voltar">← Voltar ao site</a>
		</aside>

		<div class="admin-main">
			<header class="topo">
				<div class="caminho">
					<span>Painel da Comunidade</span>
					{#if tela.nome}
						<span class="caminho-seta" aria-hidden="true">›</span>
						<strong>{tela.nome}</strong>
					{/if}
				</div>
				<div class="conta">
					<div class="avatar" aria-hidden="true">{auth.user?.name?.charAt(0).toUpperCase()}</div>
					<span class="conta-nome">{nomeCurto}</span>
					<button class="sair" onclick={handleLogout}>Sair do sistema</button>
				</div>
			</header>

			<main class="admin-content">
				{@render children()}
			</main>
		</div>
	</div>
{/if}

<style>
	:global(body) {
		margin: 0;
		background-color: #f7f2e7;
		font-family: 'Inter', system-ui, -apple-system, sans-serif;
	}

	.loading-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		min-height: 100vh;
		gap: 1rem;
		color: #4f5468;
		font-family: 'Source Sans 3', system-ui, sans-serif;
	}

	.spinner {
		width: 40px;
		height: 40px;
		border: 4px solid rgba(182, 158, 85, 0.3);
		border-top-color: #b69e55;
		border-radius: 50%;
		animation: spin 1s linear infinite;
	}

	@keyframes spin {
		to { transform: rotate(360deg); }
	}

	.admin-layout {
		display: grid;
		grid-template-columns: 248px minmax(0, 1fr);
		min-height: 100vh;
		background: #f7f2e7;
	}

	/* ─── Menu lateral ────────────────────────────────────────────── */
	/* O vitral (radiais + "chumbo" a ±45°) fica no próprio fundo, para acompanhar a rolagem em telas baixas. */
	.lateral {
		position: sticky;
		top: 0;
		height: 100vh;
		overflow-y: auto;
		box-sizing: border-box;
		display: flex;
		flex-direction: column;
		gap: 28px;
		padding: 24px 16px;
		background:
			radial-gradient(circle at 10% 0%, rgba(214, 186, 104, 0.22), transparent 35%),
			radial-gradient(circle at 100% 100%, rgba(31, 107, 110, 0.45), transparent 45%),
			repeating-linear-gradient(45deg, transparent 0 46px, rgba(8, 14, 32, 0.28) 46px 48px),
			repeating-linear-gradient(-45deg, transparent 0 46px, rgba(8, 14, 32, 0.28) 46px 48px),
			linear-gradient(180deg, #1b2f5e 0%, #14244b 60%, #0f1d3d 100%);
		background-attachment: local;
		box-shadow: inset -1px 0 0 rgba(182, 158, 85, 0.6);
		font-family: 'Source Sans 3', system-ui, sans-serif;
	}

	.marca {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 6px;
		padding: 4px 0 18px;
		border-bottom: 1px solid rgba(226, 206, 138, 0.35);
	}

	.marca-arco {
		width: 118px;
		height: 96px;
		box-sizing: border-box;
		border: 1px solid rgba(226, 206, 138, 0.7);
		border-bottom: none;
		border-radius: 50% 50% 0 0 / 46% 46% 0 0;
		display: flex;
		align-items: flex-end;
		justify-content: center;
		background: radial-gradient(circle at 50% 70%, rgba(226, 206, 138, 0.2), transparent 70%);
	}

	.marca-arco img {
		width: 110px;
		display: block;
		margin-bottom: 4px;
		filter: brightness(1.2);
	}

	.marca-nome {
		font: 600 24px/1.1 'Cormorant Garamond', serif;
		color: #fffdf8;
		text-align: center;
	}

	.marca-local {
		font-size: 13px;
		font-weight: 600;
		letter-spacing: 0.14em;
		text-transform: uppercase;
		color: #e2ce8a;
	}

	.menu {
		display: flex;
		flex-direction: column;
		gap: 22px;
	}

	.grupo {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.grupo-titulo {
		padding: 0 12px 4px;
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.12em;
		text-transform: uppercase;
		color: #e2ce8a;
	}

	.item {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 11px 12px;
		border-radius: 10px;
		font-size: 16px;
		font-weight: 500;
		color: #e6e9f2;
		text-decoration: none;
	}

	.item:hover {
		background: rgba(255, 253, 248, 0.1);
		color: #fffdf8;
	}

	.losango {
		flex-shrink: 0;
		width: 7px;
		height: 7px;
		box-sizing: border-box;
		border: 1px solid rgba(226, 206, 138, 0.7);
		transform: rotate(45deg);
	}

	.item--ativo,
	.item--ativo:hover {
		font-weight: 700;
		color: #0f1d3d;
		background: #fffdf8;
		box-shadow: inset 0 0 0 1px #b69e55;
	}

	.item--ativo .losango {
		border: none;
		background: #b69e55;
	}

	.voltar {
		margin-top: auto;
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 12px;
		border-radius: 10px;
		border: 1px solid rgba(226, 206, 138, 0.45);
		font-size: 16px;
		font-weight: 600;
		color: #fffdf8;
		text-decoration: none;
	}

	.voltar:hover {
		background: rgba(255, 253, 248, 0.1);
	}

	.lateral a:focus-visible,
	.sair:focus-visible {
		outline: 4px solid rgba(182, 158, 85, 0.45);
		outline-offset: 0;
	}

	/* ─── Barra superior ──────────────────────────────────────────── */
	.admin-main {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	.topo {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		padding: 12px 32px;
		background: #fffdf8;
		border-bottom: 1px solid #d9cca6;
		box-shadow: 0 3px 0 -1px #fffdf8, 0 4px 0 -1px #b69e55;
		font-family: 'Source Sans 3', system-ui, sans-serif;
		color: #1e2233;
		position: relative;
		z-index: 1;
	}

	.caminho {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 16px;
		color: #4f5468;
	}

	.caminho strong {
		color: #1e2233;
	}

	.caminho-seta {
		color: #b69e55;
	}

	.conta {
		display: flex;
		align-items: center;
		gap: 14px;
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

	.conta-nome {
		font-size: 16px;
		font-weight: 600;
	}

	/* Sair não é uma ação perigosa: contorno azul, não vermelho. */
	.sair {
		font: 600 15px 'Source Sans 3', system-ui, sans-serif;
		color: #1b2f5e;
		background: transparent;
		border: 1px solid #c9cfde;
		border-radius: 999px;
		padding: 9px 16px;
		cursor: pointer;
	}

	.sair:hover {
		background: #eef1f7;
	}

	.admin-content {
		padding: 2.5rem;
		flex: 1;
	}
</style>
