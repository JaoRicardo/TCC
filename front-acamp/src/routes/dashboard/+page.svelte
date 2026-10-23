<script lang="ts">
	import { auth } from "$lib/stores/auth.svelte";
	import { getActivities } from "$lib/services/activityService";
	import { getMinhasInscricoes } from "$lib/services/inscricaoService";
	import { API_HOST } from "$lib/api";
	import type { Activity } from "$lib/types/models";
	import { onMount } from "svelte";
	import banner from "$lib/imgs/banner.png";

	let activities: Activity[] = $state([]);
	let loading = $state(true);
	let currentFilter = $state("Todos");
	let minhasInscricoesMap: Record<number, string> = $state({});
	/** Formulários que o usuário precisa responder — vira o contador do menu. */
	let formulariosPendentes = $state(0);

	$effect(() => {
		if (!auth.isLoading && !auth.isAuthenticated) {
			window.location.href = "/login";
		}
	});

	onMount(async () => {
		try {
			const [activitiesRes, inscricoesRes] = await Promise.all([
				getActivities(),
				getMinhasInscricoes(),
			]);
			if (!activitiesRes.error) {
				activities = activitiesRes.activities;
			}
			const map: Record<number, string> = {};
			for (const insc of inscricoesRes.inscricoes) {
				if (insc.activity) map[insc.activity.id] = insc.status;
			}
			minhasInscricoesMap = map;
			formulariosPendentes = inscricoesRes.inscricoes.filter(
				(i) => i.temFormulario && i.formularioPendente,
			).length;
		} catch (e) {
			console.error("Erro na API:", e);
		} finally {
			loading = false;
		}
	});

	let filteredActivities = $derived(
		currentFilter === "Todos"
			? activities
			: activities.filter(
					(a) =>
						a.activitable_type ===
						(currentFilter === "Acampamentos"
							? "Campings"
							: "Events"),
				),
	);

	function handleLogout() {
		auth.clearToken();
		window.location.href = "/login";
	}

	function statusSlug(status?: string) {
		if (status === "Inscrições Abertas") return "open";
		if (status === "Encerrado") return "closed";
		return "soon";
	}

	function inscricaoSlug(status?: string) {
		if (status === "Sorteado" || status === "Indicado" || status === "Formulário Enviado") return "confirmed";
		if (status === "Suplente") return "waiting";
		if (status === "Desistência" || status === "Prazo Perdido") return "cancelled";
		return "pending";
	}
</script>

<svelte:head>
	<title>Painel | Acampamento São Miguel</title>
</svelte:head>

<div class="app-layout">
	{#if auth.isLoading}
		<div class="loading-state">
			<div class="spinner"></div>
			<p>Carregando painel...</p>
		</div>
	{:else if auth.isAuthenticated && auth.user}
		<!-- Header Principal -->
		<header class="top-header">
			<div class="header-container">
				<div class="brand">
					<img
						src={banner}
						alt="Banner São Miguel"
						class="banner-img"
					/>
				</div>
				<div class="user-actions">
					{#if auth.user?.is_admin}
						<a href="/admin/usuarios" class="admin-link-btn"
							>Painel Admin</a
						>
					{/if}
					<div class="user-greeting">
						<div class="avatar">
							{auth.user.name?.charAt(0).toUpperCase()}
						</div>
						<div class="user-info">
							<span class="greeting">Bem-vindo(a),</span>
							<span class="name"
								>{auth.user.name?.split(" ")[0]}</span
							>
						</div>
					</div>
					<button class="logout-btn" onclick={handleLogout}
						>Sair</button
					>
				</div>
			</div>
		</header>

		<!-- Estrutura Principal: Sidebar + Conteúdo -->
		<div class="main-container">
			<!-- Menu Lateral (Sidebar) inspirada na imagem -->
			<aside class="sidebar">
				<div class="sidebar-section">
					<h3 class="section-title">Navegação</h3>
					<ul class="nav-list">
						<li>
							<button
								class="nav-item"
								class:active={currentFilter === "Todos"}
								onclick={() => (currentFilter = "Todos")}
								>Todos</button
							>
						</li>
						<li>
							<button
								class="nav-item"
								class:active={currentFilter === "Acampamentos"}
								onclick={() => (currentFilter = "Acampamentos")}
								>Acampamentos</button
							>
						</li>
						<li>
							<button
								class="nav-item"
								class:active={currentFilter === "Eventos"}
								onclick={() => (currentFilter = "Eventos")}
								>Eventos Especiais</button
							>
						</li>
					</ul>
				</div>
				<div class="sidebar-section">
					<h3 class="section-title">Minha inscrição</h3>
					<ul class="nav-list">
						<li>
							<a class="nav-item nav-item--com-selo" href="/dashboard/formularios">
								Meus Formulários
								{#if formulariosPendentes > 0}
									<span class="nav-selo">{formulariosPendentes}</span>
								{/if}
							</a>
						</li>
					</ul>
				</div>
				<div class="sidebar-section">
					<h3 class="section-title">Servos</h3>
					<ul class="nav-list">
						<li>
							<a class="nav-item" href="/dashboard/equipes"
								>Minha Equipe</a
							>
						</li>
					</ul>
				</div>
			</aside>

			<!-- Área de Conteúdo (Grid de Cards) -->
			<main class="content-area">
				<div class="content-header">
					<div class="titles">
						<h2>Acampamentos e Eventos</h2>
						<p class="subtitle">
							Confira os próximos eventos da comunidade.
						</p>
					</div>
				</div>

				{#if loading}
					<div class="inner-status-state">
						<div class="spinner"></div>
						<p>Buscando atividades...</p>
					</div>
				{:else if filteredActivities.length === 0}
					<div class="inner-status-state">
						Nenhuma atividade encontrada.
					</div>
				{:else}
					<div class="cards-grid">
						{#each filteredActivities as item}
							<div class="card">
								<div
									class="card-image-placeholder"
									style={item.image &&
									item.image !==
										"https://via.placeholder.com/800x400"
										? `background-image: url('${item.image.startsWith("http") ? item.image : API_HOST + item.image}'); background-size: cover; background-position: center;`
										: ""}
								>
									<span
										class="tag"
										class:tag-evento={item.activitable_type ===
											"Events"}
									>
										{item.activitable_type === "Campings"
											? "Acampamento"
											: "Evento"}
									</span>
									<span class="status-badge status-{statusSlug(item.status)}">
										{item.status || "Em Breve"}
									</span>
								</div>
								<div class="card-body">
									<h3 class="card-title">{item.name}</h3>

									{#if minhasInscricoesMap[item.id]}
										<div class="subscribed-chip subscribed-{inscricaoSlug(minhasInscricoesMap[item.id])}">
											<span class="subscribed-chip-icon"></span>
											Você está inscrito(a) · {minhasInscricoesMap[item.id]}
										</div>
									{/if}

									<div class="card-details">
										<div class="detail">
											<span class="icon">📅</span>
											<span
												>{item.start_date
													? new Date(
															item.start_date,
														).toLocaleDateString(
															"pt-BR",
														)
													: "A definir"}</span
											>
										</div>
										{#if item.category}
											<div class="detail">
												<span class="icon">🏷️</span>
												<span>{item.category.name}</span>
											</div>
										{:else if item.targetAudience}
											<div class="detail">
												<span class="icon">🎯</span>
												<span>{item.targetAudience}</span>
											</div>
										{/if}
										{#if item.place}
											<div class="detail">
												<span class="icon">📍</span>
												<span>{item.place}</span>
											</div>
										{/if}
									</div>

									<div class="card-footer">
										<a
											href="/dashboard/atividades/{item.id}"
											class="action-btn"
											>Ver Detalhes</a
										>
										{#if item.status === "Inscrições Abertas" && !minhasInscricoesMap[item.id]}
											<a
												href="/dashboard/atividades/{item.id}?inscrever=1"
												class="action-btn action-btn-primary"
												>Inscrever-se</a
											>
										{/if}
									</div>
								</div>
							</div>
						{/each}
					</div>
				{/if}
			</main>
		</div>
	{/if}
</div>

<style>
	:global(body) {
		margin: 0;
		background-color: #f8f9fa;
		font-family:
			"Inter",
			system-ui,
			-apple-system,
			sans-serif;
	}

	.app-layout {
		min-height: 100vh;
		display: flex;
		flex-direction: column;
	}

	/* Loading */
	.loading-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		min-height: 100vh;
		gap: 1rem;
		color: #666;
	}

	.inner-status-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		min-height: 50vh;
		color: #666;
		text-align: center;
		width: 100%;
		font-size: 1.1rem;
	}

	.spinner {
		width: 40px;
		height: 40px;
		border: 4px solid rgba(111, 222, 194, 0.3);
		border-top-color: #6fdec2;
		border-radius: 50%;
		animation: spin 1s linear infinite;
	}

	@keyframes spin {
		to {
			transform: rotate(360deg);
		}
	}

	/* Header */
	.top-header {
		background: #ffffff;
		border-bottom: 1px solid #eaeaea;
		position: sticky;
		top: 0;
		z-index: 100;
		box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
	}

	.header-container {
		width: 100%;
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 0 2rem 0 0; /* Padding apenas na direita, banner na extrema esquerda */
	}

	.banner-img {
		height: 70px;
		object-fit: contain;
		display: block;
	}

	.user-actions {
		display: flex;
		align-items: center;
		gap: 2rem;
	}

	.user-greeting {
		display: flex;
		align-items: center;
		gap: 1rem;
	}

	.avatar {
		width: 40px;
		height: 40px;
		background: #6fdec2;
		color: white;
		border-radius: 50%;
		display: flex;
		align-items: center;
		justify-content: center;
		font-weight: bold;
		font-size: 1.2rem;
	}

	.user-info {
		display: flex;
		flex-direction: column;
	}

	.greeting {
		font-size: 0.75rem;
		color: #888;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}

	.name {
		font-weight: 600;
		color: #333;
	}

	.logout-btn {
		background: none;
		border: 1px solid #ffcdd2;
		color: #d32f2f;
		padding: 0.5rem 1.2rem;
		border-radius: 8px;
		font-weight: 600;
		cursor: pointer;
		transition: all 0.2s;
	}

	.logout-btn:hover {
		background: #ffebee;
	}

	.admin-link-btn {
		background: transparent;
		color: #b69e55;
		border: 2px solid #b69e55;
		text-decoration: none;
		padding: 0.5rem 1.2rem;
		border-radius: 8px;
		font-weight: 700;
		font-size: 0.85rem;
		transition: all 0.2s;
		display: inline-flex;
		align-items: center;
		justify-content: center;
	}

	.admin-link-btn:hover {
		background: #b69e55;
		color: #fff;
		transform: translateY(-1px);
	}

	/* Estrutura Principal */
	.main-container {
		display: flex;
		max-width: 1400px;
		margin: 0 auto;
		width: 100%;
		padding: 2rem;
		gap: 3rem;
		flex: 1;
	}

	/* Sidebar */
	.sidebar {
		width: 260px;
		flex-shrink: 0;
		display: flex;
		flex-direction: column;
		gap: 2.5rem;
	}

	.section-title {
		font-size: 1.1rem;
		font-weight: 700;
		color: #222;
		margin-bottom: 1rem;
		padding-bottom: 0.5rem;
		border-bottom: 2px solid #f0f0f0;
	}

	.nav-list {
		list-style: none;
		padding: 0;
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 0.2rem;
	}

	.nav-item {
		width: 100%;
		text-align: left;
		background: none;
		border: none;
		padding: 0.6rem 0.8rem;
		border-radius: 8px;
		color: #555;
		font-size: 0.95rem;
		cursor: pointer;
		transition: all 0.2s;
		display: flex;
		align-items: center;
	}

	.nav-item:hover {
		background: #f0f0f0;
		color: #333;
	}

	a.nav-item {
		text-decoration: none;
		box-sizing: border-box;
	}

	.nav-item--com-selo {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.5rem;
	}

	/* Quantos formulários estão esperando resposta. */
	.nav-selo {
		background: #6fdec2;
		color: #115843;
		font-size: 0.7rem;
		font-weight: 800;
		min-width: 1.25rem;
		height: 1.25rem;
		padding: 0 0.35rem;
		border-radius: 999px;
		display: inline-flex;
		align-items: center;
		justify-content: center;
	}

	.nav-item.active {
		background: rgba(111, 222, 194, 0.15);
		color: #1c8b6b;
		font-weight: 600;
	}

	.sub-list .nav-item {
		font-size: 0.9rem;
		color: #666;
		padding-left: 1.2rem;
		position: relative;
	}

	.sub-list .nav-item::before {
		content: "";
		position: absolute;
		left: 0;
		top: 50%;
		transform: translateY(-50%);
		width: 4px;
		height: 4px;
		background: #ccc;
		border-radius: 50%;
	}

	/* Content Area */
	.content-area {
		flex: 1;
		min-width: 0;
	}

	.content-header {
		display: flex;
		justify-content: flex-start;
		align-items: center;
		text-align: left;
		margin-bottom: 2.5rem;
		padding-left: 5rem; /* Empurra um pouco para a direita, mas não até o centro */
	}

	.titles h2 {
		font-size: 1.8rem;
		color: #1a1a1a;
		margin: 0 0 0.5rem 0;
	}

	.subtitle {
		color: #777;
		margin: 0;
		font-size: 1rem;
	}

	.sort-options {
		display: flex;
		align-items: center;
		gap: 0.8rem;
	}

	.sort-label {
		font-size: 0.85rem;
		font-weight: 600;
		color: #888;
		text-transform: uppercase;
	}

	.sort-select {
		padding: 0.5rem 2.5rem 0.5rem 1rem;
		border: 1px solid #ddd;
		border-radius: 8px;
		background: #fff
			url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e")
			no-repeat right 0.8rem center;
		background-size: 1em;
		appearance: none;
		font-family: inherit;
		font-size: 0.95rem;
		color: #333;
		cursor: pointer;
	}

	.sort-select:focus {
		outline: none;
		border-color: #6fdec2;
	}

	/* Grid de Cards */
	.cards-grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
		gap: 2rem;
	}

	.card {
		background: #fff;
		border-radius: 16px;
		overflow: hidden;
		box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
		transition:
			transform 0.3s ease,
			box-shadow 0.3s ease;
		border: 1px solid #f0f0f0;
		display: flex;
		flex-direction: column;
	}

	.card:hover {
		transform: translateY(-5px);
		box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
	}

	.card-image-placeholder {
		height: 180px;
		background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 100%);
		position: relative;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	/* Variação de cor baseada no tipo para o gradiente de fundo */
	.card:nth-child(even) .card-image-placeholder {
		background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
	}

	.tag {
		position: absolute;
		top: 1rem;
		left: 1rem;
		background: #b69e55;
		color: white;
		padding: 0.3rem 0.8rem;
		border-radius: 50px;
		font-size: 0.75rem;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	}

	.tag.tag-evento {
		background: #6fdec2;
		color: #115843;
	}

	.card-body {
		padding: 1.5rem;
		display: flex;
		flex-direction: column;
		flex: 1;
	}

	.card-title {
		margin: 0 0 1rem 0;
		font-size: 1.25rem;
		color: #222;
		font-weight: 700;
		line-height: 1.3;
	}

	.card-details {
		margin-bottom: 1.5rem;
		flex: 1;
	}

	.subscribed-chip {
		display: inline-flex;
		align-items: center;
		gap: 0.4rem;
		padding: 0.35rem 0.75rem;
		border-radius: 50px;
		font-size: 0.78rem;
		font-weight: 700;
		margin-bottom: 0.9rem;
		width: fit-content;
	}

	.subscribed-chip-icon {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 1.1rem;
		height: 1.1rem;
		border-radius: 50%;
		background: currentColor;
		flex-shrink: 0;
	}

	.subscribed-chip-icon::after {
		content: '✓';
		color: #fff;
		font-size: 0.65rem;
		line-height: 1;
	}

	.subscribed-chip.subscribed-confirmed {
		background: rgba(16, 185, 129, 0.12);
		color: #0f7a5c;
	}

	.subscribed-chip.subscribed-waiting {
		background: rgba(182, 158, 85, 0.15);
		color: #8a6d1f;
	}

	.subscribed-chip.subscribed-cancelled {
		background: rgba(107, 114, 128, 0.14);
		color: #4b5563;
	}

	.subscribed-chip.subscribed-pending {
		background: rgba(59, 130, 246, 0.12);
		color: #1d4ed8;
	}

	.detail {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		color: #666;
		font-size: 0.9rem;
	}

	.card-footer {
		display: flex;
		justify-content: flex-end;
		align-items: center;
		gap: 0.75rem;
		padding-top: 1.2rem;
		border-top: 1px solid #f0f0f0;
	}

	.status-badge {
		position: absolute;
		top: 1rem;
		right: 1rem;
		background: rgba(17, 24, 39, 0.75);
		color: #fff;
		padding: 0.3rem 0.8rem;
		border-radius: 50px;
		font-size: 0.7rem;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		backdrop-filter: blur(4px);
	}

	.status-badge.status-open {
		background: rgba(16, 185, 129, 0.9);
	}

	.status-badge.status-closed {
		background: rgba(107, 114, 128, 0.85);
	}

	.status-badge.status-soon {
		background: rgba(182, 158, 85, 0.9);
	}

	.price-container {
		display: flex;
		flex-direction: column;
	}

	.price {
		font-size: 1.2rem;
		font-weight: 800;
		color: #222;
	}

	.action-btn {
		background: #fff;
		border: 2px solid #6fdec2;
		color: #1c8b6b;
		padding: 0.6rem 1.2rem;
		border-radius: 50px;
		font-weight: 700;
		font-size: 0.9rem;
		cursor: pointer;
		transition: all 0.2s;
		text-decoration: none;
		text-align: center;
		display: inline-block;
	}

	.action-btn:hover {
		background: #6fdec2;
		color: #fff;
	}

	.action-btn-primary {
		background: #6fdec2;
		border-color: #6fdec2;
		color: #115843;
	}

	.action-btn-primary:hover {
		background: #5bc8ac;
		color: #115843;
	}

	@media (max-width: 900px) {
		.main-container {
			flex-direction: column;
			padding: 1.5rem;
		}

		.sidebar {
			width: 100%;
			flex-direction: row;
			gap: 1rem;
			overflow-x: auto;
			padding-bottom: 1rem;
		}

		.sidebar-section {
			min-width: 200px;
		}

		.content-header {
			flex-direction: column;
			align-items: flex-start;
			gap: 1.5rem;
			padding-left: 0; /* No celular, volta a colar na esquerda */
		}
	}
</style>
