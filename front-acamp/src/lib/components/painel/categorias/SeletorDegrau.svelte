<script lang="ts">
	/**
	 * Campo "Fica logo acima de" (janela de editar e de reativar): um campo de
	 * escolha que abre a escada do topo (mais velhos) para a base, com
	 * "Nenhuma: fica no primeiro degrau" no fim. Não cresce com o número de
	 * categorias (lista com rolagem).
	 */
	import { faixaCurta, type RegrasDaCategoria } from '$lib/categorias';

	let {
		escada,
		depois = $bindable(),
		atual,
		marcaAtual,
		idRotulo
	}: {
		/** Os degraus em uso, sem a categoria que está sendo posicionada, do mais novo ao mais velho. */
		escada: RegrasDaCategoria[];
		/** A categoria que fica logo abaixo; null = primeiro degrau. */
		depois: number | null;
		/** Onde ela está (ou estava) hoje: ganha a marca. undefined = sem marca. */
		atual?: number | null;
		marcaAtual?: string;
		idRotulo: string;
	} = $props();

	let aberto = $state(false);
	let caixa: HTMLDivElement;

	const nome = (id: number | null) => escada.find((c) => c.id === id)?.nome ?? '';

	const opcoes = $derived([
		...[...escada].reverse().map((c) => ({ v: c.id as number | null, t: c.nome, s: faixaCurta(c) })),
		{ v: null, t: 'Nenhuma: fica no primeiro degrau', s: escada.length ? `Abaixo do ${escada[0].nome}` : '' }
	]);

	const valido = $derived(depois === null || escada.some((c) => c.id === depois));
	const botao = $derived(depois !== null && valido ? `Logo acima do ${nome(depois)}` : 'No primeiro degrau');

	const contexto = $derived.by(() => {
		if (depois === null || !valido) {
			return escada.length ? `Fica abaixo do ${escada[0].nome}, no primeiro degrau.` : 'Fica sozinha na escada.';
		}
		const k = escada.findIndex((c) => c.id === depois);
		const acima = escada[k + 1];
		return acima ? `Fica entre ${nome(depois)} e ${acima.nome}.` : `Fica no topo, acima do ${nome(depois)}.`;
	});

	async function abrir() {
		aberto = !aberto;
		if (aberto) {
			await Promise.resolve();
			caixa?.querySelector<HTMLElement>('[aria-selected="true"]')?.focus();
		}
	}

	function escolher(v: number | null) {
		depois = v;
		aberto = false;
		caixa?.querySelector<HTMLElement>('.cat-seletor')?.focus();
	}

	function tecla(e: KeyboardEvent) {
		if (!aberto) return;
		if (e.key === 'Escape') {
			e.stopPropagation();
			aberto = false;
			caixa.querySelector<HTMLElement>('.cat-seletor')?.focus();
			return;
		}
		if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
		e.preventDefault();
		const itens = [...caixa.querySelectorAll<HTMLElement>('[role="option"]')];
		const i = itens.indexOf(document.activeElement as HTMLElement);
		const j = Math.max(0, Math.min(itens.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)));
		itens[j]?.focus();
	}
</script>

<svelte:window onclick={(e) => aberto && !caixa?.contains(e.target as Node) && (aberto = false)} />

<!-- svelte-ignore a11y_no_static_element_interactions -->
<div class="cat-seletor-caixa" bind:this={caixa} onkeydown={tecla}>
	<button type="button" class="fm-campo cat-seletor" aria-haspopup="listbox" aria-expanded={aberto} aria-labelledby={idRotulo} onclick={abrir}>
		<span>{botao}</span><span aria-hidden="true">▾</span>
	</button>
	{#if aberto}
		<div class="cat-lista-sel" role="listbox" aria-labelledby={idRotulo}>
			<span class="cat-ponta" style="padding:8px 12px 4px">Topo · mais velhos</span>
			{#each opcoes as o (o.v ?? 'nenhuma')}
				<button type="button" role="option" aria-selected={o.v === depois} class="cat-op-lista" onclick={() => escolher(o.v)}>
					<span class="cat-op-t">{o.t}{#if atual !== undefined && o.v === atual}&nbsp;{marcaAtual}{/if}</span>
					<span class="cat-op-s">{o.s}</span>
				</button>
			{/each}
		</div>
	{/if}
</div>
<p class="ins-ajuda" style="color:var(--tinta);font-weight:600">{contexto}</p>
