<script lang="ts">
	import { onMount } from 'svelte';
	import { auth } from '$lib/stores/auth.svelte';
	import { getMinhasEquipes, type MinhasEquipes } from '$lib/services/equipeService';

	let loading = $state(true);
	let error = $state('');
	let equipes: MinhasEquipes | null = $state(null);

	$effect(() => {
		if (!auth.isLoading && !auth.isAuthenticated) {
			window.location.href = '/login';
		}
	});

	onMount(async () => {
		const res = await getMinhasEquipes();
		if (res.error) error = res.error;
		else equipes = res.data ?? null;
		loading = false;
	});

	let temAlgumPapel = $derived.by(() => {
		if (!equipes) return false;
		return equipes.servo_geral.length > 0 || equipes.lideranca.length > 0 || equipes.equipes.length > 0;
	});
</script>

<svelte:head>
	<title>Minha Equipe | Acampamento São Miguel</title>
</svelte:head>

<div class="page">
	<a href="/dashboard" class="back-link">&larr; Voltar ao painel</a>
	<h2>Minha Equipe de Servos</h2>
	<p class="subtitle">Atividades em que você tem um papel de servo.</p>

	{#if loading}
		<div class="status-state">
			<div class="spinner"></div>
			<p>Carregando...</p>
		</div>
	{:else if error}
		<div class="status-state error">{error}</div>
	{:else if !temAlgumPapel}
		<div class="status-state">Você ainda não faz parte de nenhuma equipe de servos.</div>
	{:else}
		<div class="groups">
			{#if equipes!.servo_geral.length > 0}
				<section class="group">
					<h3>Servo Geral</h3>
					{#each equipes!.servo_geral as item}
						<a class="role-card" href="/dashboard/equipes/{item.activity_id}">
							<span class="badge badge-geral">Servo Geral</span>
							<span class="role-name">{item.activity_name}</span>
						</a>
					{/each}
				</section>
			{/if}

			{#if equipes!.lideranca.length > 0}
				<section class="group">
					<h3>Líder de Setor</h3>
					{#each equipes!.lideranca as item}
						<a class="role-card" href="/dashboard/equipes/{item.activity_id}">
							<span class="badge badge-lider">Líder</span>
							<span class="role-name">{item.activity_name} — {item.sector_name}</span>
						</a>
					{/each}
				</section>
			{/if}

			{#if equipes!.equipes.length > 0}
				<section class="group">
					<h3>Minhas Equipes</h3>
					{#each equipes!.equipes as item}
						<a class="role-card" href="/dashboard/equipes/{item.activity_id}">
							<span class="badge badge-membro">Servo</span>
							<span class="role-name">{item.activity_name} — {item.sector_name}</span>
						</a>
					{/each}
				</section>
			{/if}
		</div>
	{/if}
</div>

<style>
	.page {
		max-width: 800px;
		margin: 0 auto;
		padding: 2rem 1.5rem 4rem;
	}

	.back-link {
		display: inline-block;
		margin-bottom: 1rem;
		color: #1c8b6b;
		text-decoration: none;
		font-weight: 600;
		font-size: 0.9rem;
	}

	h2 {
		margin: 0 0 0.3rem 0;
		color: #1a1a1a;
	}

	.subtitle {
		color: #777;
		margin: 0 0 2rem 0;
	}

	.status-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 1rem;
		padding: 3rem 1rem;
		color: #666;
		text-align: center;
	}

	.status-state.error {
		color: #b91c1c;
	}

	.spinner {
		width: 32px;
		height: 32px;
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

	.groups {
		display: flex;
		flex-direction: column;
		gap: 2rem;
	}

	.group h3 {
		margin: 0 0 0.8rem 0;
		font-size: 1rem;
		color: #444;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}

	.role-card {
		display: flex;
		align-items: center;
		gap: 0.8rem;
		background: #fff;
		border: 1px solid #eaeaea;
		border-radius: 10px;
		padding: 1rem 1.2rem;
		margin-bottom: 0.6rem;
		text-decoration: none;
		transition: box-shadow 0.15s;
	}

	.role-card:hover {
		box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
	}

	.role-name {
		color: #222;
		font-weight: 600;
	}

	.badge {
		font-size: 0.72rem;
		font-weight: 700;
		text-transform: uppercase;
		padding: 0.25rem 0.6rem;
		border-radius: 50px;
		letter-spacing: 0.4px;
		flex-shrink: 0;
	}

	.badge-geral {
		background: rgba(182, 158, 85, 0.18);
		color: #8a6d1f;
	}

	.badge-lider {
		background: rgba(59, 130, 246, 0.14);
		color: #1d4ed8;
	}

	.badge-membro {
		background: rgba(16, 185, 129, 0.12);
		color: #0f7a5c;
	}
</style>
