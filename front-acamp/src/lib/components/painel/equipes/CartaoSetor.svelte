<script lang="ts">
	/**
	 * Cartão de um setor na equipe do acampamento (painel e "Minha equipe"):
	 * nome, local, selo de vagas, vagas (− e +, só a organização no painel), as
	 * pessoas com o menu "⋯" e o rodapé com "+ Pôr pessoa" e "Tirar setor".
	 * Só aparece o que a pessoa pode fazer.
	 */
	import { tick } from 'svelte';
	import type { PessoaDaEquipe, SetorDaEquipe } from '$lib/services/equipeService';
	import { da, na, ocupadas, pessoasDo, seloDoSetor, type Poderes } from '$lib/equipes';

	let {
		setor,
		poderes,
		categoria,
		eu,
		noSite = false,
		meu = false,
		onvagas,
		onpor,
		ontirarSetor,
		onlider,
		onmover,
		ontirar
	}: {
		setor: SetorDaEquipe;
		poderes: Poderes;
		categoria: string;
		eu: number | undefined;
		noSite?: boolean;
		meu?: boolean;
		onvagas: (s: SetorDaEquipe, vagas: number) => void;
		onpor: (s: SetorDaEquipe) => void;
		ontirarSetor: (s: SetorDaEquipe) => void;
		onlider: (s: SetorDaEquipe, p: PessoaDaEquipe, lider: boolean) => void;
		onmover: (s: SetorDaEquipe, p: PessoaDaEquipe) => void;
		ontirar: (s: SetorDaEquipe, p: PessoaDaEquipe) => void;
	} = $props();

	const selo = $derived(seloDoSetor(setor));
	const pessoas = $derived(pessoasDo(setor));
	const difModelo = $derived(setor.default_vacancies !== null && setor.default_vacancies !== setor.actual_vacancies);

	let aberto: number | null = $state(null);
	let cartao: HTMLElement;

	/** Itens do menu "⋯" para esta pessoa (vazio = sem botão). */
	function itensDe(p: PessoaDaEquipe) {
		const itens: { texto: string; perigo?: boolean; acao: () => void }[] = [];
		if (poderes.lideres) {
			itens.push(
				p.lider
					? { texto: 'Deixar de ser líder', acao: () => onlider(setor, p, false) }
					: { texto: 'Tornar líder', acao: () => onlider(setor, p, true) }
			);
			itens.push({ texto: 'Mover para outro setor…', acao: () => onmover(setor, p) });
		}
		if (poderes.tirar(setor, p)) itens.push({ texto: 'Tirar da equipe…', perigo: true, acao: () => ontirar(setor, p) });
		return itens;
	}

	async function alternar(userId: number) {
		aberto = aberto === userId ? null : userId;
		if (aberto !== null) {
			await tick();
			cartao.querySelector<HTMLElement>(`[data-menu="${userId}"] button`)?.focus();
		}
	}

	function fecharMenu(devolverFoco = true) {
		const era = aberto;
		aberto = null;
		if (devolverFoco && era !== null) cartao.querySelector<HTMLElement>(`[data-mais="${era}"]`)?.focus();
	}

	function teclaNoMenu(e: KeyboardEvent) {
		if (e.key === 'Escape') {
			e.stopPropagation();
			fecharMenu();
			return;
		}
		if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
		e.preventDefault();
		const itens = [...(e.currentTarget as HTMLElement).querySelectorAll<HTMLElement>('button')];
		const i = itens.indexOf(document.activeElement as HTMLElement);
		const prox = e.key === 'ArrowDown' ? (i + 1) % itens.length : (i - 1 + itens.length) % itens.length;
		itens[prox]?.focus();
	}
</script>

<svelte:window
	onclick={(e) => {
		if (aberto !== null && !(e.target as HTMLElement).closest(`[data-pessoa="${aberto}"]`)) fecharMenu(false);
	}}
/>

<article bind:this={cartao} class="eq-cartao" class:eq-cartao--meu={meu} aria-label="Setor {setor.sector_name}">
	<div class="eq-cartao-cabeca">
		<span class="eq-cartao-nome">
			<strong>{setor.sector_name}</strong>
			<span>{noSite ? `${ocupadas(setor)} de ${setor.actual_vacancies} vagas` : setor.sector_place || 'Interno'}</span>
		</span>
		{#if meu}
			<span class="eq-seu-setor">Seu setor</span>
		{:else if !noSite}
			<span class="ins-pill ins-pill--mini {selo.tom}">{selo.texto}</span>
		{/if}
	</div>

	{#if !noSite}
		<div class="eq-vagas">
			<span>Vagas</span>
			{#if poderes.organizacao}
				<span class="eq-passo">
					<button
						type="button"
						aria-label="Menos uma vaga {na(setor.sector_name)}"
						disabled={setor.actual_vacancies <= 0}
						onclick={() => onvagas(setor, setor.actual_vacancies - 1)}>−</button
					>
					<strong aria-live="polite">{setor.actual_vacancies}</strong>
					<button
						type="button"
						aria-label="Mais uma vaga {na(setor.sector_name)}"
						onclick={() => onvagas(setor, setor.actual_vacancies + 1)}>+</button
					>
				</span>
			{/if}
			<span>{ocupadas(setor)} de {setor.actual_vacancies}</span>
		</div>
		{#if difModelo}
			<span class="eq-dif">Diferente do modelo {da(categoria)}: {setor.default_vacancies}</span>
		{/if}
	{/if}

	{#if pessoas.length}
		<ul class="eq-pessoas">
			{#each pessoas as p (p.user_id)}
				{@const itens = itensDe(p)}
				<li class="eq-pessoa" data-pessoa={p.user_id}>
					{#if p.lider}<span class="eq-lider">Líder</span>{/if}
					<span class="eq-pessoa-nome" class:eq-pessoa-nome--lider={p.lider} title={p.nome}>
						{p.nome}{#if p.user_id === eu}&nbsp;<span class="eq-voce">(você)</span>{/if}
					</span>
					{#if itens.length}
						<button
							type="button"
							class="eq-mais"
							data-mais={p.user_id}
							aria-haspopup="menu"
							aria-expanded={aberto === p.user_id}
							aria-label="Ações para {p.nome}"
							onclick={() => alternar(p.user_id)}>⋯</button
						>
						{#if aberto === p.user_id}
							<!-- svelte-ignore a11y_interactive_supports_focus -->
							<div class="eq-menu" role="menu" aria-label={p.nome} data-menu={p.user_id} onkeydown={teclaNoMenu}>
								{#each itens as item (item.texto)}
									<button
										type="button"
										role="menuitem"
										class:perigo={item.perigo}
										onclick={() => {
											fecharMenu(false);
											item.acao();
										}}>{item.texto}</button
									>
								{/each}
							</div>
						{/if}
					{/if}
				</li>
			{/each}
		</ul>
	{:else}
		<span class="eq-cartao-vazio">Ninguém neste setor ainda.</span>
	{/if}

	{#if poderes.porNo(setor) || poderes.organizacao}
		<div class="eq-cartao-rodape">
			{#if poderes.porNo(setor)}
				<button type="button" class="ins-acao2" onclick={() => onpor(setor)}>+ Pôr pessoa</button>
			{/if}
			{#if poderes.organizacao}
				<button type="button" class="ins-acao2 perigo" onclick={() => ontirarSetor(setor)}>Tirar setor</button>
			{/if}
		</div>
	{/if}
</article>
