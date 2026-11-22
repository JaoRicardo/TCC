<script lang="ts">
	/**
	 * Minha equipe (handoff `Desing/design_handoff_equipes_servos`): os
	 * acampamentos em que a pessoa serve e o papel dela em cada um (servo geral,
	 * líder de um setor ou servo). Servo geral e líder montam a equipe por aqui,
	 * porque não têm acesso ao painel.
	 */
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { auth } from '$lib/stores/auth.svelte';
	import { loginUrl } from '$lib/redirect';
	import CabecalhoSite from '$lib/components/CabecalhoSite.svelte';
	import SeloFase from '$lib/components/SeloFase.svelte';
	import logoAsas from '$lib/imgs/logo-asas.png';
	import '$lib/styles/formularios.css';
	import '$lib/styles/equipes.css';
	import { lerMinhasEquipes, type MinhaEquipe } from '$lib/services/equipeService';
	import { getActivities } from '$lib/services/activityService';
	import { faseDaAtividade } from '$lib/faseAtividade';
	import { da, periodoBr, plural } from '$lib/equipes';
	import type { Activity } from '$lib/types/models';

	type Item = MinhaEquipe & { atividade: Activity | null };

	let estado: 'inicio' | 'carregando' | 'erro' | 'pronto' = $state('inicio');
	let itens: Item[] = $state([]);
	const agora = new Date();

	async function carregar() {
		estado = 'carregando';
		const [minhas, lista] = await Promise.all([lerMinhasEquipes(), getActivities().catch(() => ({ activities: [] as Activity[] }))]);
		if (!minhas.ok) {
			estado = 'erro';
			return;
		}
		const porId = new Map(lista.activities.map((a) => [a.id, a]));
		itens = minhas.data
			.map((m) => ({ ...m, atividade: porId.get(m.activity_id) ?? null }))
			// Os mais novos primeiro.
			.sort((a, b) => (b.atividade?.datas.inicio?.getTime() ?? 0) - (a.atividade?.datas.inicio?.getTime() ?? 0));
		estado = 'pronto';
	}

	$effect(() => {
		if (auth.isLoading || estado !== 'inicio') return;
		if (!auth.isAuthenticated) {
			goto(loginUrl($page.url.pathname), { replaceState: true });
			return;
		}
		carregar();
	});

	const faseDe = (i: Item) => (i.atividade ? faseDaAtividade(i.atividade.datas, agora) : null);

	function papelDe(i: Item): string {
		if (i.papel === 'geral') return 'servo geral';
		if (i.papel === 'lider') return `líder ${da(i.setor ?? '')}`;
		return `servo ${da(i.setor ?? '')}`;
	}

	function resumoDe(i: Item): string {
		if (faseDe(i) === 'encerrado') return 'Realizado.';
		if (i.papel === 'servo') return `Sua equipe tem ${plural(i.resumo.meu_setor ?? 0, 'pessoa', 'pessoas')}.`;
		return `${plural(i.resumo.setores, 'setor', 'setores')} · ${i.resumo.preenchidas} de ${plural(i.resumo.vagas, 'vaga preenchida', 'vagas preenchidas')}`;
	}
</script>

<svelte:head>
	<title>Minha equipe | Acampamento São Miguel</title>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link
		href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap"
		rel="stylesheet"
	/>
</svelte:head>

<div class="pagina eq">
	<CabecalhoSite />

	<main class="folha">
		<a href="/dashboard" class="voltar">← Voltar para o Início</a>
		<div class="titulo">
			<h1>Minha equipe</h1>
			<p>Os acampamentos em que você serve, e o seu papel em cada um.</p>
		</div>

		{#if estado === 'inicio' || estado === 'carregando'}
			<div class="carregando" aria-busy="true"><span class="fm-spinner"></span>Carregando suas equipes...</div>
		{:else if estado === 'erro'}
			<section class="cartao vazio" role="alert">
				<p class="vazio-titulo">Não foi possível carregar suas equipes.</p>
				<p class="vazio-texto">Verifique sua conexão com a internet e tente novamente.</p>
				<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Tentar novamente</button>
			</section>
		{:else if itens.length === 0}
			<section class="cartao vazio">
				<img src={logoAsas} alt="" class="brasao" />
				<p class="vazio-titulo">Você ainda não está em nenhuma equipe.</p>
				<p class="vazio-texto">Quando a organização puser você num setor de um acampamento, ele aparece aqui.</p>
			</section>
		{:else}
			<div class="lista">
				{#each itens as i (i.activity_id)}
					{@const fase = faseDe(i)}
					{@const realizado = fase === 'encerrado'}
					{@const monta = !realizado && i.papel !== 'servo'}
					<article class="cartao item" class:monta class:realizado>
						<div class="item-textos">
							<div class="item-nome">
								<strong>{i.activity_name}</strong>
								{#if fase}<SeloFase {fase} />{/if}
							</div>
							{#if i.atividade}<span class="item-datas">{periodoBr(i.atividade.datas.inicio, i.atividade.datas.fim)}</span>{/if}
							<span class="item-papel">Você é <strong>{papelDe(i)}</strong></span>
							<span class="item-resumo">{resumoDe(i)}</span>
						</div>
						<a
							href="/dashboard/equipes/{i.activity_id}"
							class="fm-btn fm-btn--grande {monta ? 'fm-btn--ouro' : realizado ? '' : 'fm-btn--contorno'}"
						>
							{monta ? 'Montar a equipe' : realizado ? 'Ver a equipe' : 'Ver minha equipe'}
						</a>
					</article>
				{/each}
			</div>
		{/if}
	</main>
</div>

<style>
	.pagina {
		min-height: 100vh;
		background: var(--marfim);
	}
	.folha {
		display: flex;
		flex-direction: column;
		gap: 22px;
		width: 100%;
		max-width: 1200px;
		box-sizing: border-box;
		margin: 0 auto;
		padding: 22px 48px 64px;
	}
	.voltar {
		align-self: flex-start;
		font-size: 16px;
		font-weight: 600;
		color: var(--azul);
		text-decoration: none;
	}
	.titulo {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.titulo h1 {
		margin: 0;
		font: 600 48px/1.05 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.titulo p {
		margin: 0;
		font-size: 17px;
		color: var(--tinta-3);
	}
	.cartao {
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 14px;
		box-shadow: 0 14px 28px -24px rgba(15, 29, 61, 0.45);
	}
	.lista {
		display: flex;
		flex-direction: column;
		gap: 14px;
	}
	.item {
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto;
		gap: 20px;
		align-items: center;
		padding: 20px 24px;
		border-left: 5px solid var(--azul);
	}
	.item.monta {
		border-left-color: var(--ouro);
	}
	.item.realizado {
		border-left-color: var(--borda);
	}
	.item-textos {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.item-nome {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
	}
	.item-nome strong {
		font: 600 28px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.item-nome :global(.selo) {
		box-shadow: none;
		font-size: 11px;
		padding: 3px 9px;
	}
	.item-datas,
	.item-resumo {
		font-size: 16px;
		color: var(--tinta-3);
	}
	.item-resumo {
		font-size: 15px;
	}
	.item-papel {
		font-size: 18px;
		color: var(--tinta);
	}
	.vazio {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 10px;
		padding: 56px 24px;
	}
	.vazio-titulo {
		margin: 0;
		font: 600 28px 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.vazio-texto {
		margin: 0;
		max-width: 520px;
		font-size: 17px;
		color: var(--tinta-3);
	}
	.brasao {
		width: 110px;
		opacity: 0.55;
		filter: sepia(1) saturate(0.6);
	}
	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		font-size: 17px;
		color: var(--tinta-3);
	}
</style>
