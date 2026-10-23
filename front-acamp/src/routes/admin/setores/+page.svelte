<script lang="ts">
	import { onMount } from 'svelte';
	import { apiFetch } from '$lib/api';
	import '$lib/styles/admin-form.css';
	import {
		getSectorsCatalog,
		createSector,
		updateSector,
		deleteSector,
		linkSectorToCategory,
		updateCategoryLink,
		deleteCategoryLink,
		type SetorCatalogo
	} from '$lib/services/sectorService';

	let sectors: SetorCatalogo[] = $state([]);
	let categories: { id: number; name: string }[] = $state([]);
	let loading = $state(true);
	let error = $state('');
	let isBusy = $state(false);

	// Novo setor
	let novoNome = $state('');
	let novoLocal = $state('Interno');

	// Vincular categoria por setor (chave: sectorId)
	let categoriaEscolhida: Record<number, string> = $state({});
	let vagasEscolhidas: Record<number, number> = $state({});

	// Edição inline de vínculo existente (chave: categories_sectors id)
	let editandoVinculo: Record<number, { vacancies: number }> = $state({});

	async function carregar() {
		const [sectorsRes, categoriesRes] = await Promise.all([
			getSectorsCatalog(),
			apiFetch('/categories').then((r) => r.json())
		]);
		if (sectorsRes.error) error = sectorsRes.error;
		else sectors = sectorsRes.data ?? [];
		categories = (categoriesRes.data || []).filter((c: any) => c.type === 'Acampamento');
		loading = false;
	}

	onMount(carregar);

	function categoriasDisponiveis(setor: SetorCatalogo) {
		const jaVinculadas = new Set(setor.categories.map((c) => c.category_id));
		return categories.filter((c) => !jaVinculadas.has(c.id));
	}

	async function handleCriarSetor() {
		if (!novoNome.trim()) return;
		isBusy = true;
		const res = await createSector(novoNome.trim(), novoLocal);
		if (res.error) alert(res.error);
		else {
			novoNome = '';
			novoLocal = 'Interno';
		}
		await carregar();
		isBusy = false;
	}

	async function handleExcluirSetor(id: number, nome: string) {
		if (!confirm(`Excluir o setor "${nome}"? Isso também remove seus vínculos com categorias.`)) return;
		isBusy = true;
		const res = await deleteSector(id);
		if (!res.success) alert(res.error);
		await carregar();
		isBusy = false;
	}

	async function handleVincular(setor: SetorCatalogo) {
		const categoryId = Number(categoriaEscolhida[setor.id]);
		if (!categoryId) return;
		isBusy = true;
		const res = await linkSectorToCategory(setor.id, categoryId, vagasEscolhidas[setor.id] ?? 0);
		if (!res.success) alert(res.error);
		else {
			categoriaEscolhida[setor.id] = '';
			vagasEscolhidas[setor.id] = 0;
		}
		await carregar();
		isBusy = false;
	}

	function iniciarEdicaoVinculo(vinculo: { id: number; vacancies: number }) {
		editandoVinculo[vinculo.id] = { vacancies: vinculo.vacancies };
	}

	async function salvarEdicaoVinculo(csId: number) {
		const edicao = editandoVinculo[csId];
		if (!edicao) return;
		isBusy = true;
		const res = await updateCategoryLink(csId, edicao.vacancies);
		if (!res.success) alert(res.error);
		delete editandoVinculo[csId];
		await carregar();
		isBusy = false;
	}

	async function handleRemoverVinculo(csId: number, categoryName: string, sectorName: string) {
		if (!confirm(`Remover o vínculo entre "${sectorName}" e "${categoryName}"?`)) return;
		isBusy = true;
		const res = await deleteCategoryLink(csId);
		if (!res.success) alert(res.error);
		await carregar();
		isBusy = false;
	}
</script>

<svelte:head>
	<title>Setores | Painel Admin</title>
</svelte:head>

<div class="admin-form-page">
	<div class="af-header">
		<div>
			<h2>Setores de Servos</h2>
			<p>
				Cadastre os setores (Cozinha, Limpeza, etc.) e defina em quais categorias de acampamento cada um existe,
				com as vagas padrão. Depois, em cada acampamento específico, você vincula esses setores à edição daquele
				ano em "Gerenciar Equipe de Servos".
			</p>
		</div>
	</div>

	{#if loading}
		<div class="af-section">Carregando...</div>
	{:else if error}
		<div class="af-alert af-alert--error">{error}</div>
	{:else}
		<section class="af-section af-section--highlight">
			<div class="af-section-title"><h3>Novo Setor</h3></div>
			<div class="af-grid-3">
				<div class="af-field">
					<label for="novo-nome">Nome</label>
					<input id="novo-nome" type="text" placeholder="Ex: Cozinha" bind:value={novoNome} />
				</div>
				<div class="af-field">
					<label for="novo-local">Local</label>
					<select id="novo-local" bind:value={novoLocal}>
						<option value="Interno">Interno</option>
						<option value="Externo">Externo</option>
					</select>
				</div>
				<div class="af-field">
					<span class="af-field-spacer">&nbsp;</span>
					<button class="af-btn af-btn--primary" disabled={isBusy || !novoNome.trim()} onclick={handleCriarSetor}>
						Criar Setor
					</button>
				</div>
			</div>
		</section>

		{#each sectors as setor}
			<section class="af-section">
				<div class="af-section-title">
					<h3>{setor.name} <span class="af-optional">({setor.place})</span></h3>
					<button class="af-btn af-btn--secondary" disabled={isBusy} onclick={() => handleExcluirSetor(setor.id, setor.name)}>
						Excluir Setor
					</button>
				</div>

				<p class="af-sub-title">Categorias vinculadas</p>
				{#if setor.categories.length === 0}
					<p class="empty-hint">Nenhuma categoria vinculada ainda — este setor não aparecerá em nenhum acampamento.</p>
				{:else}
					<div class="vinculos-list">
						{#each setor.categories as vinculo}
							<div class="vinculo-row">
								{#if editandoVinculo[vinculo.id]}
									<span class="vinculo-nome">{vinculo.category_name}</span>
									<label class="mini-field">Vagas <input type="number" min="0" bind:value={editandoVinculo[vinculo.id].vacancies} /></label>
									<button class="link-btn" disabled={isBusy} onclick={() => salvarEdicaoVinculo(vinculo.id)}>salvar</button>
									<button class="link-btn" disabled={isBusy} onclick={() => delete editandoVinculo[vinculo.id]}>cancelar</button>
								{:else}
									<span class="vinculo-nome">{vinculo.category_name}</span>
									<span class="vagas-tag">{vinculo.vacancies} {vinculo.vacancies === 1 ? 'vaga' : 'vagas'}</span>
									<button class="link-btn" disabled={isBusy} onclick={() => iniciarEdicaoVinculo(vinculo)}>editar</button>
									<button class="link-btn danger" disabled={isBusy} onclick={() => handleRemoverVinculo(vinculo.id, vinculo.category_name, setor.name)}>remover</button>
								{/if}
							</div>
						{/each}
					</div>
				{/if}

				{#if categoriasDisponiveis(setor).length > 0}
					<div class="af-grid-3 af-mt">
						<div class="af-field">
							<label for="cat-{setor.id}">Vincular categoria</label>
							<select id="cat-{setor.id}" bind:value={categoriaEscolhida[setor.id]}>
								<option value="">Selecione...</option>
								{#each categoriasDisponiveis(setor) as cat}
									<option value={cat.id}>{cat.name}</option>
								{/each}
							</select>
						</div>
						<div class="af-field">
							<label for="vagas-{setor.id}">Vagas</label>
							<input id="vagas-{setor.id}" type="number" min="0" bind:value={vagasEscolhidas[setor.id]} placeholder="0" />
						</div>
						<div class="af-field">
							<span class="af-field-spacer">&nbsp;</span>
							<button class="af-btn af-btn--secondary" disabled={isBusy || !categoriaEscolhida[setor.id]} onclick={() => handleVincular(setor)}>
								Vincular
							</button>
						</div>
					</div>
				{/if}
			</section>
		{/each}
	{/if}
</div>

<style>
	.af-field-spacer {
		display: block;
		font-size: 0.78rem;
		line-height: 1;
	}

	.empty-hint {
		color: #9ca3af;
		font-size: 0.88rem;
		font-style: italic;
		margin: 0 0 0.5rem 0;
	}

	.vinculos-list {
		display: flex;
		flex-direction: column;
		gap: 0.5rem;
		margin-bottom: 0.5rem;
	}

	.vinculo-row {
		display: flex;
		align-items: center;
		gap: 0.8rem;
		background: #f9fafb;
		border-radius: 8px;
		padding: 0.6rem 1rem;
		flex-wrap: wrap;
	}

	.vinculo-nome {
		font-weight: 700;
		color: #111827;
		min-width: 90px;
	}

	.vagas-tag {
		font-size: 0.8rem;
		color: #6b7280;
		background: #fff;
		border: 1px solid #e5e7eb;
		padding: 0.2rem 0.6rem;
		border-radius: 999px;
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

	.link-btn {
		background: none;
		border: none;
		cursor: pointer;
		font-size: 0.82rem;
		font-weight: 700;
		color: #1c8b6b;
		margin-left: auto;
	}

	.link-btn.danger {
		color: #d32f2f;
	}

	.link-btn:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
</style>
