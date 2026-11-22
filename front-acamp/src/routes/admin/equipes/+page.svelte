<script lang="ts">
	/**
	 * Equipes de Servos (handoff `Desing/design_handoff_equipes_servos`): une a
	 * antiga "Setores de Servos" (aba "Modelo por categoria", permissão
	 * `setores`) e a antiga "Equipe de servos" de cada acampamento (aba
	 * "Equipes dos acampamentos", permissão `equipes`). Com só uma das
	 * permissões, a tela mostra só aquela parte, sem abas.
	 *
	 * Endereço: `?atividade={id}` abre a equipe daquele acampamento;
	 * `?aba=modelo` abre o modelo.
	 */
	import { onMount } from 'svelte';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import '$lib/styles/formularios.css';
	import '$lib/styles/inscritos.css';
	import '$lib/styles/equipes.css';
	import SeloFase from '$lib/components/SeloFase.svelte';
	import EquipeAcampamento from '$lib/components/painel/equipes/EquipeAcampamento.svelte';
	import ModeloCategorias from '$lib/components/painel/equipes/ModeloCategorias.svelte';
	import { pode } from '$lib/permissoes';
	import { getActivities } from '$lib/services/activityService';
	import { faseDaAtividade, type Fase } from '$lib/faseAtividade';
	import { numerosDaEquipe, periodoBr } from '$lib/equipes';
	import type { Activity } from '$lib/types/models';
	import type { EquipeDoAcampamento } from '$lib/services/equipeService';

	type Aba = 'equipes' | 'modelo';

	const veEquipes = pode('equipes');
	const veModelo = pode('setores');
	const ambas = veEquipes && veModelo;

	const abaDaUrl = (): Aba => {
		if (!veEquipes) return 'modelo';
		if (!veModelo) return 'equipes';
		return $page.url.searchParams.get('aba') === 'modelo' ? 'modelo' : 'equipes';
	};
	let aba: Aba = $state(abaDaUrl());

	const agora = new Date();
	const ORDEM: { fase: Exclude<Fase, 'encerrado'>; nome: string }[] = [
		{ fase: 'acontecendo', nome: 'Acontecendo agora' },
		{ fase: 'aguardandoSorteio', nome: 'Aguardando sorteio' },
		{ fase: 'abertas', nome: 'Inscrições abertas' },
		{ fase: 'inscricoesEncerradas', nome: 'Inscrições encerradas' },
		{ fase: 'emBreve', nome: 'Em breve' }
	];

	let acampamentos: Activity[] = $state([]);
	let estadoLista: 'carregando' | 'erro' | 'pronto' = $state('carregando');
	let atividadeId: number | null = $state(null);
	let equipe: EquipeDoAcampamento | null = $state(null);

	const faseDe = (a: Activity) => faseDaAtividade(a.datas, agora);
	const grupos = $derived(
		ORDEM.map((g) => ({ ...g, lista: acampamentos.filter((a) => faseDe(a) === g.fase) })).filter((g) => g.lista.length > 0)
	);
	const atual = $derived(acampamentos.find((a) => a.id === atividadeId) ?? null);
	const numeros = $derived.by(() => {
		if (!equipe || equipe.atividade.id !== atividadeId) return null;
		return numerosDaEquipe(equipe);
	});

	async function carregarLista() {
		estadoLista = 'carregando';
		const r = await getActivities().catch(() => ({ activities: [], error: 'erro' }));
		if (r.error) {
			estadoLista = 'erro';
			return;
		}
		acampamentos = r.activities.filter((a) => a.activitable_type === 'Campings');
		const pedido = Number($page.url.searchParams.get('atividade'));
		const primeiro = grupos[0]?.lista[0]?.id ?? null;
		atividadeId = acampamentos.some((a) => a.id === pedido) ? pedido : primeiro;
		estadoLista = 'pronto';
	}

	onMount(() => {
		if (veEquipes) carregarLista();
	});

	function irPara(params: Record<string, string | null>) {
		const url = new URL($page.url);
		for (const [k, v] of Object.entries(params)) {
			if (v === null) url.searchParams.delete(k);
			else url.searchParams.set(k, v);
		}
		goto(url.pathname + url.search, { replaceState: true, keepFocus: true, noScroll: true });
	}

	function trocarAba(a: Aba) {
		aba = a;
		irPara({ aba: a === 'modelo' ? 'modelo' : null });
	}

	let troca: HTMLDetailsElement | undefined = $state();
	function escolher(id: number) {
		atividadeId = id;
		equipe = null;
		if (troca) troca.open = false;
		irPara({ atividade: String(id) });
	}
</script>

<svelte:head>
	<title>Equipes de Servos | Painel da Comunidade</title>
</svelte:head>

<div class="fm eq">
	<div class="cabeca">
		<h1 class="fm-h1">Equipes de Servos</h1>
		<p class="fm-sub">A equipe de cada acampamento e o modelo de setores de cada categoria.</p>
	</div>

	<div class="painel">
		{#if ambas}
			<div class="abas" role="tablist" aria-label="Equipes de Servos">
				<button type="button" role="tab" id="aba-equipes" aria-selected={aba === 'equipes'} aria-controls="painel-aba" class="aba" class:sel={aba === 'equipes'} onclick={() => trocarAba('equipes')}>
					Equipes dos acampamentos
				</button>
				<button type="button" role="tab" id="aba-modelo" aria-selected={aba === 'modelo'} aria-controls="painel-aba" class="aba" class:sel={aba === 'modelo'} onclick={() => trocarAba('modelo')}>
					Modelo por categoria
				</button>
			</div>
		{/if}

		<section
			id="painel-aba"
			class="fm-cartao conteudo"
			class:com-abas={ambas}
			role={ambas ? 'tabpanel' : undefined}
			aria-labelledby={ambas ? `aba-${aba}` : undefined}
		>
			{#if aba === 'modelo'}
				<ModeloCategorias />
			{:else if estadoLista === 'carregando'}
				<div class="carregando" aria-busy="true"><span class="fm-spinner"></span>Carregando os acampamentos...</div>
			{:else if estadoLista === 'erro'}
				<div class="centro" role="alert">
					<p class="centro-titulo">Não foi possível carregar os acampamentos.</p>
					<p class="centro-texto">Verifique sua conexão com a internet e tente novamente.</p>
					<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregarLista}>Tentar novamente</button>
				</div>
			{:else if !atual}
				<div class="centro">
					<p class="centro-titulo">Nenhum acampamento em andamento.</p>
					<p class="centro-texto">
						Quando um acampamento for criado, a equipe dele é montada aqui. Os já realizados abrem pela Gestão de Atividades.
					</p>
					{#if pode('acampamentos')}<a href="/admin/acampamentos/criar" class="fm-btn fm-btn--contorno">Criar acampamento</a>{/if}
				</div>
			{:else}
				{@const fase = faseDe(atual)}
				<div class="topo">
					<div class="topo-textos">
						<details class="troca" bind:this={troca}>
							<summary aria-label="Trocar de acampamento">
								<span class="nome">{atual.name}</span>
								<span class="trocar">Trocar ▾</span>
							</summary>
							<div class="troca-lista">
								{#each grupos as g (g.fase)}
									<span class="eq-rot troca-grupo">{g.nome}</span>
									{#each g.lista as a (a.id)}
										<button type="button" class="troca-item" aria-current={a.id === atividadeId} onclick={() => escolher(a.id)}>{a.name}</button>
									{/each}
								{/each}
								<span class="troca-nota">Realizados: abra pela Gestão de Atividades.</span>
							</div>
						</details>
						<div class="linha-info">
							{#if atual.category?.name}<span class="categoria">{atual.category.name}</span>{/if}
							<SeloFase {fase} />
							<span>{periodoBr(atual.datas.inicio, atual.datas.fim)}</span>
						</div>
					</div>
					{#if numeros}
						<div class="numeros">
							{#each [['Setores', numeros.setores], ['Vagas', numeros.vagas], ['Na equipe', numeros.naEquipe], ['Faltando', numeros.faltando], ['No banco', numeros.noBanco]] as [rotulo, valor] (rotulo)}
								<div class="numero" class:alerta={rotulo === 'Faltando' && Number(valor) > 0}>
									<span class="numero-valor">{valor}</span>
									<span class="numero-rot">{rotulo}</span>
								</div>
							{/each}
						</div>
					{/if}
				</div>
				{#key atividadeId}
					<EquipeAcampamento atividadeId={atual.id} bind:equipe />
				{/key}
			{/if}
		</section>
	</div>
</div>

<style>
	.cabeca {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.cabeca .fm-sub {
		max-width: 860px;
	}
	.painel {
		display: flex;
		flex-direction: column;
	}
	.abas {
		display: flex;
		gap: 6px;
	}
	.aba {
		position: relative;
		z-index: 1;
		flex: 1;
		display: flex;
		align-items: center;
		justify-content: center;
		height: 56px;
		margin-bottom: -1px;
		padding: 0 20px;
		font: 600 17px 'Source Sans 3', sans-serif;
		color: var(--tinta-3);
		background: transparent;
		border: 1px solid transparent;
		border-bottom: none;
		border-radius: 12px 12px 0 0;
		cursor: pointer;
	}
	.aba.sel {
		z-index: 2;
		font-weight: 700;
		color: var(--azul);
		background: var(--pergaminho);
		border-color: var(--borda-suave);
	}
	.aba.sel::before {
		content: '';
		position: absolute;
		left: 14px;
		right: 14px;
		top: -1px;
		height: 3px;
		border-radius: 0 0 3px 3px;
		background: var(--ouro);
	}
	.conteudo {
		display: flex;
		flex-direction: column;
		gap: 20px;
		padding: 24px 28px 28px;
	}
	.conteudo.com-abas {
		border-top-left-radius: 4px;
		border-top-right-radius: 4px;
	}

	.topo {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 20px;
		flex-wrap: wrap;
	}
	.topo-textos {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.troca {
		position: relative;
	}
	.troca summary {
		display: flex;
		align-items: center;
		gap: 12px;
		cursor: pointer;
		list-style: none;
		border-radius: 10px;
	}
	.troca summary::-webkit-details-marker {
		display: none;
	}
	.troca summary:focus-visible {
		outline: none;
		box-shadow: 0 0 0 4px var(--anel-foco);
	}
	.nome {
		font: 600 32px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.trocar {
		flex: 0 0 auto;
		font-size: 15px;
		font-weight: 600;
		color: var(--azul);
		border: 1px solid var(--borda);
		border-radius: 999px;
		padding: 4px 12px;
	}
	.troca-lista {
		position: absolute;
		top: 100%;
		left: 0;
		z-index: 15;
		margin-top: 6px;
		display: flex;
		flex-direction: column;
		min-width: 340px;
		max-height: 420px;
		overflow: auto;
		padding: 8px;
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 12px;
		box-shadow: 0 20px 40px -20px rgba(15, 29, 61, 0.5);
	}
	.troca-grupo {
		padding: 8px 12px 2px;
	}
	.troca-item {
		display: block;
		padding: 8px 12px;
		text-align: left;
		border: none;
		border-radius: 8px;
		background: transparent;
		font-size: 16px;
		color: var(--tinta);
		cursor: pointer;
	}
	.troca-item:hover {
		background: var(--cabecalho-tabela);
	}
	.troca-item[aria-current='true'] {
		background: #f1e8cf;
		font-weight: 700;
	}
	.troca-nota {
		padding: 8px 12px;
		font-size: 14px;
		color: var(--tinta-3);
	}
	.linha-info {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
		font-size: 16px;
		color: var(--tinta-3);
	}
	.linha-info :global(.selo) {
		box-shadow: none;
		font-size: 11px;
		padding: 3px 9px;
	}
	.categoria {
		font-size: 13px;
		font-weight: 600;
		color: #6b5822;
		background: #f1e8cf;
		border-radius: 999px;
		padding: 2px 10px;
	}
	.numeros {
		display: flex;
		gap: 10px;
		flex-wrap: wrap;
	}
	.numero {
		display: flex;
		flex-direction: column;
		align-items: center;
		min-width: 92px;
		padding: 10px 14px;
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}
	.numero.alerta {
		background: #fbf0d6;
		border-color: #ebd39c;
	}
	.numero-valor {
		font: 600 30px/1 'Cormorant Garamond', serif;
		color: var(--azul);
		font-variant-numeric: tabular-nums;
	}
	.alerta .numero-valor {
		color: #6a480c;
	}
	.numero-rot {
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.06em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}

	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 18px 0;
		font-size: 17px;
		color: var(--tinta-3);
	}
	.centro {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 14px;
		padding: 56px 24px;
		text-align: center;
	}
	.centro-titulo {
		margin: 0;
		font: 600 30px 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.centro-texto {
		margin: 0;
		max-width: 560px;
		font-size: 17px;
		color: var(--tinta-3);
	}
</style>
