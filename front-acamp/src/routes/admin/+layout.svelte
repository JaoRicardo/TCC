<script lang="ts">
	import { auth } from '$lib/stores/auth.svelte';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { MENU_DO_PAINEL, acessaPainel, pode, podeAbrir, primeiraTela } from '$lib/permissoes';
	
	let { children } = $props();

	// A tela atual está liberada para a pessoa? Se não estiver, ela volta sem aviso
	// para a primeira tela que pode ver — ninguém recebe "sem permissão".
	let telaLiberada = $derived(!auth.isLoading && acessaPainel() && podeAbrir($page.url.pathname));

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
	<title>Administração | Acampamento São Miguel</title>
</svelte:head>

{#if !telaLiberada}
	<div class="loading-state">
		<div class="spinner"></div>
		<p>Verificando credenciais...</p>
	</div>
{:else}
	<div class="admin-layout">
		<!-- Sidebar Admin -->
		<aside class="admin-sidebar">
			<div class="brand">
				<h2>Painel Admin</h2>
			</div>
			<nav class="admin-nav">
				<ul class="nav-list">
					<li><a href="/dashboard" class="nav-item">← Voltar ao site</a></li>
					<li class="divider"></li>
					{#each MENU_DO_PAINEL.filter((item) => pode(item.chave)) as item}
						<li>
							<a href={item.caminho} class="nav-item {$page.url.pathname.startsWith(item.caminho.replace('/criar', '')) ? 'active' : ''}">{item.nome}</a>
						</li>
					{/each}
				</ul>
			</nav>
			<div class="sidebar-footer">
				<div class="admin-user-info">
					<div class="avatar">{auth.user.name?.charAt(0).toUpperCase()}</div>
					<div class="details">
						<span class="name">{auth.user.name?.split(' ')[0]}</span>
					</div>
				</div>
			</div>
		</aside>

		<!-- Conteúdo Principal -->
		<div class="admin-main">
			<header class="admin-header">
				<div class="header-actions">
					<button class="logout-btn" onclick={handleLogout}>Sair do Sistema</button>
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
		background-color: #f4f6f8;
		font-family: 'Inter', system-ui, -apple-system, sans-serif;
	}

	.loading-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		min-height: 100vh;
		gap: 1rem;
		color: #666;
	}

	.spinner {
		width: 40px;
		height: 40px;
		border: 4px solid rgba(28, 139, 107, 0.3);
		border-top-color: #1c8b6b;
		border-radius: 50%;
		animation: spin 1s linear infinite;
	}

	@keyframes spin {
		to { transform: rotate(360deg); }
	}

	.admin-layout {
		display: flex;
		min-height: 100vh;
	}

	/* Sidebar Admin */
	.admin-sidebar {
		width: 260px;
		background: #111827;
		color: #fff;
		display: flex;
		flex-direction: column;
		flex-shrink: 0;
	}

	.brand {
		padding: 2rem 1.5rem;
		border-bottom: 1px solid rgba(255, 255, 255, 0.1);
	}

	.brand h2 {
		margin: 0;
		font-size: 1.25rem;
		font-weight: 700;
		color: #6fdec2;
	}

	.admin-nav {
		flex: 1;
		padding: 1.5rem 1rem;
	}

	.nav-list {
		list-style: none;
		padding: 0;
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 0.5rem;
	}

	.nav-item {
		display: block;
		text-decoration: none;
		color: #9ca3af;
		padding: 0.75rem 1rem;
		border-radius: 8px;
		font-size: 0.95rem;
		font-weight: 500;
		transition: all 0.2s;
	}

	.nav-item:hover {
		background: rgba(255, 255, 255, 0.05);
		color: #fff;
	}

	.nav-item.active {
		background: rgba(111, 222, 194, 0.1);
		color: #6fdec2;
		font-weight: 600;
	}

	.divider {
		height: 1px;
		background: rgba(255, 255, 255, 0.1);
		margin: 1rem 0;
	}

	.sidebar-footer {
		padding: 1.5rem;
		border-top: 1px solid rgba(255, 255, 255, 0.1);
	}

	.admin-user-info {
		display: flex;
		align-items: center;
		gap: 0.75rem;
	}

	.avatar {
		width: 36px;
		height: 36px;
		background: #6fdec2;
		color: #111827;
		border-radius: 50%;
		display: flex;
		align-items: center;
		justify-content: center;
		font-weight: 700;
	}

	.details {
		display: flex;
		flex-direction: column;
	}

	.details .name {
		font-size: 0.9rem;
		font-weight: 600;
		color: #fff;
	}

	/* Main Content */
	.admin-main {
		flex: 1;
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	.admin-header {
		background: #fff;
		padding: 1rem 2rem;
		border-bottom: 1px solid #e5e7eb;
		display: flex;
		justify-content: flex-end;
		align-items: center;
	}

	.logout-btn {
		background: none;
		border: 1px solid #fee2e2;
		color: #ef4444;
		padding: 0.5rem 1rem;
		border-radius: 6px;
		font-size: 0.85rem;
		font-weight: 600;
		cursor: pointer;
		transition: all 0.2s;
	}

	.logout-btn:hover {
		background: #fef2f2;
	}

	.admin-content {
		padding: 2.5rem;
		flex: 1;
		overflow-y: auto;
	}
</style>
