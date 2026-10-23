<script lang="ts">
	import { onMount } from 'svelte';
	import { apiFetch } from '$lib/api';
	import '$lib/styles/admin-form.css';
	import {
		getPerguntas,
		criarPergunta,
		editarPergunta,
		excluirPergunta,
		getSecoes,
		criarSecao,
		excluirSecao,
		TIPOS_PERGUNTA,
		TIPOS_COM_OPCOES,
		type Pergunta,
		type Secao
	} from '$lib/services/perguntaService';

	let categorias: { id: number; name: string }[] = $state([]);
	let categoriaId: number | null = $state(null);
	let tipoInscricao: 'Campista' | 'Servo' = $state('Campista');

	let secoes: Secao[] = $state([]);
	let perguntas: Pergunta[] = $state([]);
	let loading = $state(true);
	let isBusy = $state(false);
	let error = $state('');

	// Nova seção
	let novaSecaoTitulo = $state('');

	// Nova pergunta por seção (chave: section_id)
	let novoTexto: Record<number, string> = $state({});
	let novoTipo: Record<number, string> = $state({});
	let novoObrigatoria: Record<number, boolean> = $state({});
	let novasOpcoes: Record<number, string> = $state({}); // separadas por linha

	// Edição inline (chave: question id)
	let editando: Record<number, { text: string; type: string; is_required: boolean; opcoes: string }> = $state({});

	async function carregarCategorias() {
		const res = await apiFetch('/categories');
		const data = await res.json();
		categorias = (data.data || []).filter((c: any) => c.type === 'Acampamento');
		if (categorias.length > 0 && !categoriaId) categoriaId = categorias[0].id;
	}

	async function carregarSecoes() {
		const res = await getSecoes();
		if (!res.error) secoes = res.data ?? [];
	}

	async function carregarPerguntas() {
		if (!categoriaId) return;
		loading = true;
		const res = await getPerguntas(categoriaId, tipoInscricao);
		if (res.error) error = res.error;
		else {
			perguntas = res.data ?? [];
			error = '';
		}
		loading = false;
	}

	onMount(async () => {
		await Promise.all([carregarCategorias(), carregarSecoes()]);
		await carregarPerguntas();
	});

	$effect(() => {
		if (categoriaId && tipoInscricao) carregarPerguntas();
	});

	function perguntasDaSecao(secaoId: number) {
		return perguntas.filter((p) => p.section.id === secaoId);
	}

	function secoesComPerguntas() {
		const idsComPergunta = new Set(perguntas.map((p) => p.section.id));
		return secoes.filter((s) => idsComPergunta.has(s.id) || true); // mostra todas as seções, mesmo vazias, pra permitir adicionar
	}

	async function handleCriarSecao() {
		if (!novaSecaoTitulo.trim()) return;
		isBusy = true;
		const res = await criarSecao(novaSecaoTitulo.trim());
		if (res.error) alert(res.error);
		else novaSecaoTitulo = '';
		await carregarSecoes();
		isBusy = false;
	}

	async function handleExcluirSecao(id: number, titulo: string) {
		if (!confirm(`Excluir a seção "${titulo}"? Só funciona se ela estiver vazia.`)) return;
		isBusy = true;
		const res = await excluirSecao(id);
		if (!res.success) alert(res.error);
		await carregarSecoes();
		isBusy = false;
	}

	function precisaDeOpcoes(tipo: string) {
		return TIPOS_COM_OPCOES.includes(tipo);
	}

	async function handleCriarPergunta(secaoId: number) {
		const texto = (novoTexto[secaoId] ?? '').trim();
		const tipo = novoTipo[secaoId] ?? 'texto_curto';
		if (!texto || !categoriaId) return;

		const opcoes = (novasOpcoes[secaoId] ?? '')
			.split('\n')
			.map((s) => s.trim())
			.filter((s) => s !== '');

		if (precisaDeOpcoes(tipo) && opcoes.length < 2) {
			alert('Perguntas de opção precisam de pelo menos 2 opções (uma por linha).');
			return;
		}

		isBusy = true;
		const res = await criarPergunta({
			category_id: categoriaId,
			subscription_type: tipoInscricao,
			text: texto,
			type: tipo,
			is_required: novoObrigatoria[secaoId] ?? true,
			section_id: secaoId,
			options: opcoes
		});
		if (res.error) alert(res.error);
		else {
			novoTexto[secaoId] = '';
			novoTipo[secaoId] = 'texto_curto';
			novoObrigatoria[secaoId] = true;
			novasOpcoes[secaoId] = '';
		}
		await carregarPerguntas();
		isBusy = false;
	}

	function iniciarEdicao(p: Pergunta) {
		editando[p.id] = {
			text: p.text,
			type: p.type,
			is_required: p.is_required,
			opcoes: p.options.map((o) => o.text).join('\n')
		};
	}

	async function salvarEdicao(p: Pergunta) {
		const edicao = editando[p.id];
		if (!edicao) return;

		const opcoes = edicao.opcoes
			.split('\n')
			.map((s) => s.trim())
			.filter((s) => s !== '');

		if (precisaDeOpcoes(edicao.type) && opcoes.length < 2) {
			alert('Perguntas de opção precisam de pelo menos 2 opções (uma por linha).');
			return;
		}

		isBusy = true;
		const payload: Record<string, unknown> = {
			text: edicao.text,
			type: edicao.type,
			is_required: edicao.is_required
		};
		if (edicao.type !== 'sim_nao') payload.options = opcoes;

		const res = await editarPergunta(p.id, payload);
		if (res.error) alert(res.error);
		else delete editando[p.id];
		await carregarPerguntas();
		isBusy = false;
	}

	async function handleExcluirPergunta(id: number, texto: string) {
		if (!confirm(`Excluir a pergunta "${texto}"?`)) return;
		isBusy = true;
		const res = await excluirPergunta(id);
		if (!res.success) alert(res.error);
		await carregarPerguntas();
		isBusy = false;
	}

	function nomeTipo(tipo: string) {
		return TIPOS_PERGUNTA.find((t) => t.value === tipo)?.label ?? tipo;
	}
</script>

<svelte:head>
	<title>Formulários | Painel Admin</title>
</svelte:head>

<div class="admin-form-page">
	<div class="af-header">
		<div>
			<h2>Formulários de Inscrição</h2>
			<p>
				Monte o formulário que os inscritos sorteados/indicados preenchem depois de confirmados — um formulário
				diferente para cada categoria de acampamento e para Campista/Servo.
			</p>
		</div>
	</div>

	<section class="af-section af-section--highlight">
		<div class="af-grid-2">
			<div class="af-field">
				<label for="cat-select">Categoria do acampamento</label>
				<select id="cat-select" bind:value={categoriaId}>
					{#each categorias as cat}
						<option value={cat.id}>{cat.name}</option>
					{/each}
				</select>
			</div>
			<div class="af-field">
				<label for="tipo-tabs">Formulário para</label>
				<div class="af-tabs" id="tipo-tabs">
					<button class="af-tab {tipoInscricao === 'Campista' ? 'active' : ''}" onclick={() => (tipoInscricao = 'Campista')}>Campista</button>
					<button class="af-tab {tipoInscricao === 'Servo' ? 'active' : ''}" onclick={() => (tipoInscricao = 'Servo')}>Servo</button>
				</div>
			</div>
		</div>
	</section>

	{#if loading}
		<div class="af-section">Carregando...</div>
	{:else if error}
		<div class="af-alert af-alert--error">{error}</div>
	{:else}
		{#each secoesComPerguntas() as secao}
			<section class="af-section">
				<div class="af-section-title">
					<h3>{secao.titulo}</h3>
					{#if perguntasDaSecao(secao.id).length === 0}
						<button class="af-btn af-btn--secondary" disabled={isBusy} onclick={() => handleExcluirSecao(secao.id, secao.titulo)}>
							Excluir Seção
						</button>
					{/if}
				</div>

				<div class="perguntas-list">
					{#each perguntasDaSecao(secao.id) as p}
						<div class="pergunta-card">
							{#if editando[p.id]}
								<div class="af-field">
									<label for="edit-text-{p.id}">Texto da pergunta</label>
									<input id="edit-text-{p.id}" type="text" bind:value={editando[p.id].text} />
								</div>
								<div class="af-grid-2 af-mt">
									<div class="af-field">
										<label for="edit-type-{p.id}">Tipo</label>
										<select id="edit-type-{p.id}" bind:value={editando[p.id].type}>
											{#each TIPOS_PERGUNTA as t}
												<option value={t.value}>{t.label}</option>
											{/each}
										</select>
									</div>
									<div class="af-field">
										<label class="af-toggle">
											<input type="checkbox" bind:checked={editando[p.id].is_required} />
											Obrigatória
										</label>
									</div>
								</div>
								{#if precisaDeOpcoes(editando[p.id].type)}
									<div class="af-field af-mt">
										<label for="edit-opts-{p.id}">Opções (uma por linha)</label>
										<textarea id="edit-opts-{p.id}" bind:value={editando[p.id].opcoes} rows="4"></textarea>
									</div>
								{/if}
								<div class="af-actions af-mt">
									<button class="af-btn af-btn--secondary" disabled={isBusy} onclick={() => delete editando[p.id]}>Cancelar</button>
									<div class="af-actions-spacer"></div>
									<button class="af-btn af-btn--primary" disabled={isBusy} onclick={() => salvarEdicao(p)}>Salvar</button>
								</div>
							{:else}
								<div class="pergunta-row">
									<div>
										<strong>{p.text}</strong>
										{#if p.is_required}<span class="badge-required">obrigatória</span>{/if}
										<div class="pergunta-meta">{nomeTipo(p.type)}{#if p.options.length > 0} · {p.options.map((o) => o.text).join(', ')}{/if}</div>
									</div>
									<div class="pergunta-actions">
										<button class="link-btn" disabled={isBusy} onclick={() => iniciarEdicao(p)}>editar</button>
										<button class="link-btn danger" disabled={isBusy} onclick={() => handleExcluirPergunta(p.id, p.text)}>excluir</button>
									</div>
								</div>
							{/if}
						</div>
					{:else}
						<p class="empty-hint">Nenhuma pergunta nesta seção ainda para {tipoInscricao}.</p>
					{/each}
				</div>

				<div class="nova-pergunta">
					<p class="af-sub-title">Nova pergunta nesta seção</p>
					<div class="af-grid-2">
						<div class="af-field">
							<label for="novo-texto-{secao.id}">Texto</label>
							<input id="novo-texto-{secao.id}" type="text" bind:value={novoTexto[secao.id]} placeholder="Ex: Qual seu tamanho de camiseta?" />
						</div>
						<div class="af-field">
							<label for="novo-tipo-{secao.id}">Tipo de resposta</label>
							<select id="novo-tipo-{secao.id}" bind:value={novoTipo[secao.id]}>
								{#each TIPOS_PERGUNTA as t}
									<option value={t.value}>{t.label}</option>
								{/each}
							</select>
						</div>
					</div>
					{#if precisaDeOpcoes(novoTipo[secao.id] ?? 'texto_curto')}
						<div class="af-field af-mt">
							<label for="novo-opcoes-{secao.id}">Opções (uma por linha)</label>
							<textarea id="novo-opcoes-{secao.id}" bind:value={novasOpcoes[secao.id]} rows="3" placeholder={'P\nM\nG\nGG'}></textarea>
						</div>
					{/if}
					<div class="af-actions af-mt" style="border-top:none; padding-top:0;">
						<label class="af-toggle">
							<input type="checkbox" checked={novoObrigatoria[secao.id] ?? true} onchange={(e) => (novoObrigatoria[secao.id] = e.currentTarget.checked)} />
							Obrigatória
						</label>
						<div class="af-actions-spacer"></div>
						<button class="af-btn af-btn--primary" disabled={isBusy || !(novoTexto[secao.id] ?? '').trim()} onclick={() => handleCriarPergunta(secao.id)}>
							Adicionar Pergunta
						</button>
					</div>
				</div>
			</section>
		{/each}

		<section class="af-section af-section--highlight">
			<div class="af-section-title"><h3>Nova Seção</h3></div>
			<div class="af-grid-2">
				<div class="af-field">
					<label for="nova-secao">Título da seção</label>
					<input id="nova-secao" type="text" bind:value={novaSecaoTitulo} placeholder="Ex: Saúde" />
				</div>
				<div class="af-field">
					<span>&nbsp;</span>
					<button class="af-btn af-btn--primary" disabled={isBusy || !novaSecaoTitulo.trim()} onclick={handleCriarSecao}>Criar Seção</button>
				</div>
			</div>
		</section>
	{/if}
</div>

<style>
	.perguntas-list {
		display: flex;
		flex-direction: column;
		gap: 0.7rem;
		margin-bottom: 1.2rem;
	}

	.pergunta-card {
		background: #f9fafb;
		border-radius: 10px;
		padding: 1rem 1.2rem;
	}

	.pergunta-row {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 1rem;
	}

	.pergunta-meta {
		font-size: 0.8rem;
		color: #6b7280;
		margin-top: 0.2rem;
	}

	.badge-required {
		font-size: 0.68rem;
		font-weight: 700;
		text-transform: uppercase;
		color: #b91c1c;
		background: rgba(185, 28, 28, 0.08);
		padding: 0.15rem 0.5rem;
		border-radius: 999px;
		margin-left: 0.5rem;
	}

	.pergunta-actions {
		display: flex;
		gap: 0.8rem;
		flex-shrink: 0;
	}

	.link-btn {
		background: none;
		border: none;
		cursor: pointer;
		font-size: 0.82rem;
		font-weight: 700;
		color: #1c8b6b;
	}

	.link-btn.danger {
		color: #d32f2f;
	}

	.link-btn:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}

	.nova-pergunta {
		border-top: 1px dashed #e5e7eb;
		padding-top: 1.2rem;
	}

	.empty-hint {
		color: #9ca3af;
		font-size: 0.88rem;
		font-style: italic;
		margin: 0 0 0.8rem 0;
	}
</style>
