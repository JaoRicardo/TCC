<script lang="ts">
	import { onMount } from 'svelte';
	import { page } from '$app/stores';
	import { auth } from '$lib/stores/auth.svelte';
	import { apiFetch } from '$lib/api';
	import {
		getEquipesAtividade,
		addSectorMember,
		removeSectorMember,
		addGeneralServant,
		removeGeneralServant,
		linkActivitySector,
		unlinkActivitySector,
		type EquipesAtividade,
		type Setor
	} from '$lib/services/equipeService';

	const activityId = $page.params.activityId as string;

	let activityName = $state('');
	let equipes: EquipesAtividade | null = $state(null);
	let loading = $state(true);
	let error = $state('');
	let isBusy = $state(false);

	// user_id selecionado no <select> de cada setor (chave: acsId)
	let selecaoPorSetor: Record<number, string> = $state({});
	let selecaoServoGeral = $state('');

	// vagas escolhidas ao vincular um setor ainda não ligado a esta atividade (chave: categories_sectors_id)
	let vagasParaVincular: Record<number, number> = $state({});

	$effect(() => {
		if (!auth.isLoading && !auth.isAuthenticated) {
			window.location.href = '/login';
		}
	});

	async function carregar() {
		const [activityRes, equipesRes] = await Promise.all([
			apiFetch(`/activities/${activityId}`).then((r) => r.json()),
			getEquipesAtividade(activityId)
		]);
		activityName = activityRes?.data?.nome || `Atividade #${activityId}`;
		if (equipesRes.error) {
			error = equipesRes.error;
		} else {
			equipes = equipesRes.data ?? null;
			error = '';
		}
		loading = false;
	}

	onMount(carregar);

	let podeGerenciarGeral = $derived.by(() => {
		if (!equipes) return false;
		return equipes.meu_papel.is_admin || equipes.meu_papel.is_servo_geral;
	});

	function podeGerenciarSetor(setor: Setor): boolean {
		if (!equipes) return false;
		if (equipes.meu_papel.is_admin || equipes.meu_papel.is_servo_geral) return true;
		return setor.id !== null && equipes.meu_papel.lidera_setores_ids.includes(setor.id);
	}

	function souDesteSetor(setor: Setor): 'lider' | 'membro' | null {
		if (!auth.user) return null;
		if (setor.coordenadores.some((c) => c.user_id === auth.user.id)) return 'lider';
		if (setor.membros.some((m) => m.user_id === auth.user.id)) return 'membro';
		return null;
	}

	// Uma pessoa só pode estar em UM setor por atividade — o pool de candidatos
	// exclui quem já está alocado em qualquer setor desta atividade, não só neste.
	function candidatosDisponiveis(setor: Setor) {
		if (!equipes) return [];
		const jaAlocados = new Set<number>();
		for (const s of equipes.setores) {
			if (!s.linked) continue;
			for (const p of [...s.coordenadores, ...s.membros]) jaAlocados.add(p.user_id);
		}
		return equipes.servos_inscritos.filter((p) => !jaAlocados.has(p.user_id));
	}

	function candidatosGeralDisponiveis() {
		if (!equipes) return [];
		const jaGeral = new Set(equipes.servo_geral.map((p) => p.user_id));
		return equipes.servos_inscritos.filter((p) => !jaGeral.has(p.user_id));
	}

	async function handleAdd(setor: Setor, isCoordinator: boolean) {
		if (!setor.id) return;
		const userId = Number(selecaoPorSetor[setor.id]);
		if (!userId) return;
		isBusy = true;
		const res = await addSectorMember(activityId, setor.id, userId, isCoordinator);
		if (!res.success) alert(res.error);
		else selecaoPorSetor[setor.id] = '';
		await carregar();
		isBusy = false;
	}

	async function handleRemove(setor: Setor, userId: number, nome: string) {
		if (!setor.id) return;
		if (!confirm(`Remover ${nome} desta equipe?`)) return;
		isBusy = true;
		const res = await removeSectorMember(activityId, setor.id, userId);
		if (!res.success) alert(res.error);
		await carregar();
		isBusy = false;
	}

	async function handleAddGeral() {
		const userId = Number(selecaoServoGeral);
		if (!userId) return;
		isBusy = true;
		const res = await addGeneralServant(activityId, userId);
		if (!res.success) alert(res.error);
		else selecaoServoGeral = '';
		await carregar();
		isBusy = false;
	}

	async function handleVincularSetor(setor: Setor) {
		isBusy = true;
		const res = await linkActivitySector(
			activityId,
			setor.categories_sectors_id,
			vagasParaVincular[setor.categories_sectors_id] ?? setor.default_vacancies
		);
		if (!res.success) alert(res.error);
		await carregar();
		isBusy = false;
	}

	async function handleDesvincularSetor(setor: Setor) {
		if (!setor.id) return;
		if (!confirm(`Remover o setor "${setor.sector_name}" desta atividade? As pessoas alocadas nele também serão removidas.`)) return;
		isBusy = true;
		const res = await unlinkActivitySector(activityId, setor.id);
		if (!res.success) alert(res.error);
		await carregar();
		isBusy = false;
	}

	async function handleRemoveGeral(userId: number, nome: string) {
		if (!confirm(`Remover ${nome} como servo geral?`)) return;
		isBusy = true;
		const res = await removeGeneralServant(activityId, userId);
		if (!res.success) alert(res.error);
		await carregar();
		isBusy = false;
	}

	let meuLugarSetores = $derived.by(() => {
		if (!equipes) return [];
		return equipes.setores.filter((s) => souDesteSetor(s) !== null);
	});
</script>

<svelte:head>
	<title>Equipe de Servos | {activityName}</title>
</svelte:head>

<div class="page">
	<a href="/dashboard/equipes" class="back-link">&larr; Minha Equipe</a>
	<h2>Equipe de Servos</h2>
	<p class="subtitle">{activityName}</p>

	{#if loading}
		<div class="status-state">
			<div class="spinner"></div>
			<p>Carregando...</p>
		</div>
	{:else if error}
		<div class="status-state error">{error}</div>
	{:else if equipes}
		{#if podeGerenciarGeral}
			<section class="card">
				<h3>Servo Geral</h3>
				<p class="hint">Gerencia todos os setores desta atividade: pode indicar líderes e membros em qualquer equipe.</p>
				<div class="pessoa-list">
					{#each equipes.servo_geral as pessoa}
						<div class="pessoa-row">
							<span>{pessoa.nome} <small>({pessoa.cpf})</small></span>
							{#if equipes.meu_papel.is_admin}
								<button class="link-btn danger" disabled={isBusy} onclick={() => handleRemoveGeral(pessoa.user_id, pessoa.nome)}>remover</button>
							{/if}
						</div>
					{:else}
						<p class="empty">Nenhum servo geral designado ainda.</p>
					{/each}
				</div>

				{#if equipes.meu_papel.is_admin}
					<div class="add-row">
						<select bind:value={selecaoServoGeral}>
							<option value="">Selecione um servo inscrito...</option>
							{#each candidatosGeralDisponiveis() as pessoa}
								<option value={pessoa.user_id}>{pessoa.nome} ({pessoa.cpf})</option>
							{/each}
						</select>
						<button class="btn-primary" disabled={isBusy || !selecaoServoGeral} onclick={handleAddGeral}>Designar</button>
					</div>
				{/if}
			</section>
		{/if}

		{#if !podeGerenciarGeral && meuLugarSetores.length > 0}
			<section class="card">
				<h3>Você está na equipe</h3>
				{#each meuLugarSetores as setor}
					<div class="pessoa-row">
						<span>
							<strong>{setor.sector_name}</strong>
							{#if souDesteSetor(setor) === 'lider'}
								<span class="badge badge-lider">Líder</span>
							{:else}
								<span class="badge badge-membro">Servo</span>
							{/if}
						</span>
					</div>
				{/each}
			</section>
		{/if}

		<section class="card">
			<h3>Setores</h3>
			{#each equipes.setores.filter((s) => s.linked) as setor}
				<div class="setor-block">
					<div class="setor-header">
						<h4>{setor.sector_name} <small>({setor.category_name})</small></h4>
						<div class="setor-header-right">
							<span class="vagas-info">
								{setor.membros.length + setor.coordenadores.length} pessoa(s) ·
								{#if setor.vagas_faltando > 0}
									faltam {setor.vagas_faltando} {setor.vagas_faltando === 1 ? 'vaga' : 'vagas'}
								{:else}
									equipe completa
								{/if}
							</span>
							{#if equipes.meu_papel.is_admin}
								<button class="link-btn danger" disabled={isBusy} onclick={() => handleDesvincularSetor(setor)}>remover setor</button>
							{/if}
						</div>
					</div>

					<div class="pessoa-list">
						{#each setor.coordenadores as pessoa}
							<div class="pessoa-row">
								<span><span class="badge badge-lider">Líder</span> {pessoa.nome} <small>({pessoa.cpf})</small></span>
								{#if podeGerenciarSetor(setor) && (equipes.meu_papel.is_admin || pessoa.user_id !== auth.user?.id)}
									<button class="link-btn danger" disabled={isBusy} onclick={() => handleRemove(setor, pessoa.user_id, pessoa.nome)}>remover</button>
								{/if}
							</div>
						{/each}
						{#each setor.membros as pessoa}
							<div class="pessoa-row">
								<span><span class="badge badge-membro">Servo</span> {pessoa.nome} <small>({pessoa.cpf})</small></span>
								{#if podeGerenciarSetor(setor) && (equipes.meu_papel.is_admin || pessoa.user_id !== auth.user?.id)}
									<button class="link-btn danger" disabled={isBusy} onclick={() => handleRemove(setor, pessoa.user_id, pessoa.nome)}>remover</button>
								{/if}
							</div>
						{/each}
						{#if setor.coordenadores.length === 0 && setor.membros.length === 0}
							<p class="empty">Ninguém alocado ainda.</p>
						{/if}
					</div>

					{#if podeGerenciarSetor(setor) && setor.id !== null}
						<div class="add-row">
							<select bind:value={selecaoPorSetor[setor.id]}>
								<option value="">Selecione um servo inscrito...</option>
								{#each candidatosDisponiveis(setor) as pessoa}
									<option value={pessoa.user_id}>{pessoa.nome} ({pessoa.cpf})</option>
								{/each}
							</select>
							<button class="btn-secondary" disabled={isBusy || !selecaoPorSetor[setor.id]} onclick={() => handleAdd(setor, false)}>Adicionar como servo</button>
							{#if podeGerenciarGeral}
								<button class="btn-primary" disabled={isBusy || !selecaoPorSetor[setor.id]} onclick={() => handleAdd(setor, true)}>Tornar líder</button>
							{/if}
						</div>
					{/if}
				</div>
			{:else}
				<p class="empty">Nenhum setor vinculado a esta atividade ainda.</p>
			{/each}
		</section>

		{#if equipes.meu_papel.is_admin && equipes.setores.some((s) => !s.linked)}
			<section class="card">
				<h3>Vincular setor a esta atividade</h3>
				<p class="hint">
					Setores já cadastrados para a categoria desta atividade, mas ainda não usados nesta edição. Cadastre novos
					setores ou vincule-os a categorias em <a href="/admin/setores">Setores de Servos</a>.
				</p>
				{#each equipes.setores.filter((s) => !s.linked) as setor}
					<div class="add-row disponivel-row">
						<span class="disponivel-nome">{setor.sector_name}</span>
						<label class="mini-field">Vagas <input type="number" min="0" bind:value={vagasParaVincular[setor.categories_sectors_id]} placeholder={String(setor.default_vacancies)} /></label>
						<button class="btn-secondary" disabled={isBusy} onclick={() => handleVincularSetor(setor)}>Vincular</button>
					</div>
				{/each}
			</section>
		{/if}
	{/if}
</div>

<style>
	.page {
		max-width: 900px;
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

	.card {
		background: #fff;
		border: 1px solid #eaeaea;
		border-radius: 12px;
		padding: 1.5rem;
		margin-bottom: 1.5rem;
	}

	.card h3 {
		margin: 0 0 0.3rem 0;
		color: #222;
	}

	.hint {
		color: #888;
		font-size: 0.88rem;
		margin: 0 0 1rem 0;
	}

	.setor-block {
		border-top: 1px solid #f0f0f0;
		padding: 1.2rem 0;
	}

	.setor-block:first-of-type {
		border-top: none;
		padding-top: 0.5rem;
	}

	.setor-header {
		display: flex;
		justify-content: space-between;
		align-items: baseline;
		margin-bottom: 0.6rem;
	}

	.setor-header h4 {
		margin: 0;
		color: #222;
	}

	.setor-header small {
		color: #999;
		font-weight: 400;
	}

	.vagas-info {
		font-size: 0.82rem;
		color: #999;
	}

	.setor-header-right {
		display: flex;
		align-items: center;
		gap: 0.8rem;
	}

	.hint {
		color: #888;
		font-size: 0.88rem;
		margin: 0 0 1rem 0;
	}

	.hint a {
		color: #1c8b6b;
		font-weight: 600;
	}

	.disponivel-row {
		background: #fafafa;
		border-radius: 8px;
		padding: 0.7rem 0.9rem;
		margin-bottom: 0.5rem;
	}

	.disponivel-nome {
		font-weight: 700;
		color: #222;
		min-width: 100px;
	}

	.mini-field {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		font-size: 0.8rem;
		color: #6b7280;
		font-weight: 600;
	}

	.mini-field input {
		width: 64px;
		padding: 0.3rem 0.5rem;
		border: 1px solid #d1d5db;
		border-radius: 6px;
	}

	.pessoa-list {
		display: flex;
		flex-direction: column;
		gap: 0.4rem;
		margin-bottom: 0.8rem;
	}

	.pessoa-row {
		display: flex;
		justify-content: space-between;
		align-items: center;
		background: #fafafa;
		border-radius: 8px;
		padding: 0.5rem 0.8rem;
		font-size: 0.92rem;
	}

	.pessoa-row small {
		color: #999;
	}

	.empty {
		color: #999;
		font-size: 0.9rem;
		font-style: italic;
		margin: 0.3rem 0;
	}

	.link-btn {
		background: none;
		border: none;
		cursor: pointer;
		font-size: 0.82rem;
		font-weight: 600;
	}

	.link-btn.danger {
		color: #d32f2f;
	}

	.link-btn:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}

	.add-row {
		display: flex;
		flex-wrap: wrap;
		gap: 0.6rem;
		align-items: center;
	}

	select {
		padding: 0.5rem 0.8rem;
		border: 1px solid #ddd;
		border-radius: 8px;
		font-family: inherit;
		font-size: 0.9rem;
		flex: 1;
		min-width: 200px;
	}

	.btn-primary,
	.btn-secondary {
		padding: 0.5rem 1rem;
		border-radius: 8px;
		font-weight: 700;
		font-size: 0.85rem;
		cursor: pointer;
		border: 2px solid #6fdec2;
		white-space: nowrap;
	}

	.btn-secondary {
		background: #fff;
		color: #1c8b6b;
	}

	.btn-secondary:hover:not(:disabled) {
		background: #6fdec2;
		color: #fff;
	}

	.btn-primary {
		background: #b69e55;
		border-color: #b69e55;
		color: #fff;
	}

	.btn-primary:hover:not(:disabled) {
		background: #a08847;
	}

	.btn-primary:disabled,
	.btn-secondary:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}

	.badge {
		font-size: 0.68rem;
		font-weight: 700;
		text-transform: uppercase;
		padding: 0.2rem 0.5rem;
		border-radius: 50px;
		letter-spacing: 0.4px;
		margin-right: 0.3rem;
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
