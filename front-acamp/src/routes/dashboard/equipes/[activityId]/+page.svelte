<script lang="ts">
	/**
	 * A equipe de um acampamento na área do participante (handoff
	 * `Desing/design_handoff_equipes_servos`): mesma estrutura do painel, só com
	 * as ações do papel. Servo geral põe, move e tira em qualquer setor; líder
	 * põe e tira servos do próprio setor; servo comum só vê a própria equipe.
	 * Quem tem a permissão "Equipes de Servos" e não está na equipe vai para o painel.
	 */
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { auth } from '$lib/stores/auth.svelte';
	import { loginUrl } from '$lib/redirect';
	import { pode } from '$lib/permissoes';
	import CabecalhoSite from '$lib/components/CabecalhoSite.svelte';
	import EquipeAcampamento from '$lib/components/painel/equipes/EquipeAcampamento.svelte';
	import '$lib/styles/formularios.css';
	import '$lib/styles/inscritos.css';
	import '$lib/styles/equipes.css';
	import { da, periodoBr } from '$lib/equipes';
	import { apiFetch } from '$lib/api';
	import { datasDaAtividade } from '$lib/faseAtividade';
	import type { EquipeDoAcampamento } from '$lib/services/equipeService';

	const atividadeId = Number($page.params.activityId as string);

	let pronto = $state(false);
	let equipe: EquipeDoAcampamento | null = $state(null);
	let datas = $state('');

	$effect(() => {
		if (auth.isLoading || pronto) return;
		if (!auth.isAuthenticated) {
			goto(loginUrl($page.url.pathname), { replaceState: true });
			return;
		}
		pronto = true;
		apiFetch(`/activities/${atividadeId}`)
			.then((r) => r.json())
			.then((d) => {
				const dt = datasDaAtividade(d.data ?? {});
				datas = periodoBr(dt.inicio, dt.fim);
			})
			.catch(() => {});
	});

	// A organização monta a equipe pelo painel.
	$effect(() => {
		if (!equipe) return;
		const m = equipe.meu_papel;
		const naEquipe = m.is_servo_geral || m.meus_setores_ids.length > 0;
		if (m.is_admin && !naEquipe && pode('equipes')) goto(`/admin/equipes?atividade=${atividadeId}`, { replaceState: true });
	});

	const nomeDoSetor = (id: number | undefined) => equipe?.setores.find((s) => s.id === id)?.sector_name ?? '';
	const papel = $derived.by(() => {
		if (!equipe) return null;
		const m = equipe.meu_papel;
		if (m.is_servo_geral) return { titulo: 'Servo geral', texto: 'Põe líderes e servos em qualquer setor.' };
		if (m.lidera_setores_ids.length) {
			const s = nomeDoSetor(m.lidera_setores_ids[0]);
			return { titulo: `Líder ${da(s)}`, texto: `Põe e tira servos ${da(s)}.` };
		}
		if (m.meus_setores_ids.length) return { titulo: `Servo ${da(nomeDoSetor(m.meus_setores_ids[0]))}`, texto: 'Você vê a sua equipe.' };
		return null;
	});
</script>

<svelte:head>
	<title>Equipe de servos | {equipe?.atividade.nome ?? 'Acampamento São Miguel'}</title>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link
		href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap"
		rel="stylesheet"
	/>
</svelte:head>

<div class="pagina eq">
	<CabecalhoSite />

	<main class="folha">
		<a href="/dashboard/equipes" class="voltar">← Minha equipe</a>
		<div class="topo">
			<div class="topo-textos">
				<h1>Equipe de servos</h1>
				{#if equipe}
					<span class="atividade"><strong>{equipe.atividade.nome}</strong>{datas ? ` · ${datas}` : ''}</span>
				{/if}
			</div>
			{#if papel}
				<span class="seu-papel">
					<span class="eq-rot">Seu papel</span>
					<strong>{papel.titulo}</strong>
					<span>{papel.texto}</span>
				</span>
			{/if}
		</div>

		{#if pronto}
			<EquipeAcampamento {atividadeId} noSite bind:equipe />
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
	.topo {
		display: flex;
		justify-content: space-between;
		align-items: flex-end;
		gap: 20px;
		flex-wrap: wrap;
	}
	.topo-textos {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.topo h1 {
		margin: 0;
		font: 600 44px/1.05 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.atividade {
		font-size: 18px;
		color: var(--tinta-2);
	}
	.seu-papel {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 4px;
		padding: 10px 16px;
		background: var(--cabecalho-tabela);
		border: 1px solid var(--ouro);
		border-radius: 12px;
	}
	.seu-papel strong {
		font-size: 18px;
		color: var(--azul);
	}
	.seu-papel span:last-child {
		font-size: 14px;
		color: var(--tinta-3);
	}
</style>
