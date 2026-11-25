<script lang="ts">
	/**
	 * Modo "Mudar a ordem" (handoff design_handoff_categorias, seção 7). A nova
	 * ordem só vale depois de "Salvar ordem". Cada bloco se mexe sozinho: a
	 * escada (aqui a ordem muda quem pode servir onde) e as de fora (só a ordem
	 * de exibição). As arquivadas não aparecem; o servidor as deixa no lugar.
	 */
	import { tick } from 'svelte';
	import { escadaEmUso, faixaCurta, foraDaEscada, junta, type CategoriaDaLista } from '$lib/categorias';
	import { reordenarCategorias } from '$lib/services/categoriaService';

	let {
		categorias,
		onsair,
		onsalvou,
		onfalhou
	}: {
		categorias: CategoriaDaLista[];
		onsair: () => void;
		onsalvou: (lista: CategoriaDaLista[]) => void;
		onfalhou: () => void;
	} = $props();

	type Bloco = 'escada' | 'fora';

	// Ordem salva (para comparar) e a nova; a escada do degrau mais novo ao mais velho.
	// svelte-ignore state_referenced_locally
	const escadaAntes = escadaEmUso(categorias).map((c) => c.id);
	// svelte-ignore state_referenced_locally
	const foraAntes = foraDaEscada(categorias).map((c) => c.id);
	let escada = $state([...escadaAntes]);
	let fora = $state([...foraAntes]);
	let salvando = $state(false);
	let anuncio = $state('');
	let menuAberto: number | null = $state(null);

	const porId = $derived(new Map(categorias.map((c) => [c.id, c])));
	const nome = (id: number) => porId.get(id)?.nome ?? '';

	const mudouAlgo = $derived(escada.join() !== escadaAntes.join() || fora.join() !== foraAntes.join());

	// ─── Mover ────────────────────────────────────────────────────────────

	function anunciar(bloco: Bloco, id: number) {
		const lista = bloco === 'escada' ? escada : fora;
		const pos = lista.indexOf(id) + 1;
		anuncio =
			bloco === 'escada'
				? `${nome(id)} agora é o degrau ${pos} de ${lista.length}.`
				: `${nome(id)} agora é a ${pos}ª de ${lista.length} fora da escada.`;
	}

	function levarPara(bloco: Bloco, id: number, pos: number) {
		const lista = (bloco === 'escada' ? escada : fora).filter((x) => x !== id);
		lista.splice(Math.max(0, Math.min(lista.length, pos)), 0, id);
		if (bloco === 'escada') escada = lista;
		else fora = lista;
		anunciar(bloco, id);
	}

	/** delta na ordem do bloco: na escada, +1 = sobe um degrau; fora, +1 = desce na lista. */
	function passo(bloco: Bloco, id: number, delta: number) {
		const lista = bloco === 'escada' ? escada : fora;
		const i = lista.indexOf(id);
		const j = i + delta;
		if (j < 0 || j >= lista.length) return;
		levarPara(bloco, id, j);
	}

	async function teclaNaAlca(e: KeyboardEvent, bloco: Bloco, id: number) {
		if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
		e.preventDefault();
		const paraCima = e.key === 'ArrowUp';
		passo(bloco, id, bloco === 'escada' ? (paraCima ? 1 : -1) : paraCima ? -1 : 1);
		await tick();
		document.querySelector<HTMLElement>(`[data-alca="${id}"]`)?.focus();
	}

	// ─── Arrastar (só dentro do próprio bloco) ────────────────────────────

	let arrastando: { bloco: Bloco; id: number } | null = $state(null);
	let alvo: { id: number; abaixo: boolean } | null = $state(null);

	/** A lista como aparece na tela (a escada do topo para a base). */
	const naTela = (bloco: Bloco) => (bloco === 'escada' ? [...escada].reverse() : [...fora]);

	function soltar(e: DragEvent, bloco: Bloco, sobre: number) {
		e.preventDefault();
		const de = arrastando;
		const abaixo = alvo?.abaixo ?? false;
		arrastando = null;
		alvo = null;
		if (!de || de.bloco !== bloco || de.id === sobre) return;
		const tela = naTela(bloco).filter((x) => x !== de.id);
		tela.splice(tela.indexOf(sobre) + (abaixo ? 1 : 0), 0, de.id);
		if (bloco === 'escada') escada = tela.reverse();
		else fora = tela;
		anunciar(bloco, de.id);
	}

	// ─── O que muda ───────────────────────────────────────────────────────

	const mudancas = $derived.by(() => {
		const r: { mais: boolean; texto: string }[] = [];
		for (const id of escada) {
			const antes = new Set(escadaAntes.slice(0, escadaAntes.indexOf(id) + 1));
			const agora = new Set(escada.slice(0, escada.indexOf(id) + 1));
			const ganhou = [...agora].filter((x) => !antes.has(x)).map((x) => `no ${nome(x)}`);
			const perdeu = [...antes].filter((x) => !agora.has(x)).map((x) => `no ${nome(x)}`);
			if (ganhou.length) r.push({ mais: true, texto: `Quem foi campista no ${nome(id)} passa a poder servir ${junta(ganhou)}.` });
			if (perdeu.length) r.push({ mais: false, texto: `Quem foi campista no ${nome(id)} deixa de poder servir ${junta(perdeu)}.` });
		}
		return r;
	});

	const avisoIdade = $derived.by(() => {
		const frases: string[] = [];
		for (let i = 0; i < escada.length - 1; i++) {
			const a = porId.get(escada[i]);
			const b = porId.get(escada[i + 1]);
			if (a && b && a.idadeMinima !== null && b.idadeMinima !== null && b.idadeMinima < a.idadeMinima) {
				frases.push(`O ${b.nome} (${faixaCurta(b).toLowerCase()}) ficou acima do ${a.nome} (${faixaCurta(a).toLowerCase()}).`);
			}
		}
		return frases.join(' ');
	});

	// ─── Salvar ───────────────────────────────────────────────────────────

	async function salvar() {
		if (!mudouAlgo || salvando) return;
		salvando = true;
		const r = await reordenarCategorias([...escada, ...fora]);
		salvando = false;
		if (r.ok) onsalvou(r.data);
		else onfalhou();
	}

	function cancelar() {
		if (!salvando) onsair();
	}
</script>

<svelte:window
	onclick={(e) => menuAberto !== null && !(e.target as HTMLElement).closest('.cat-mover') && (menuAberto = null)}
	onkeydown={(e) => {
		if (e.key === 'Escape' && menuAberto !== null) {
			const id = menuAberto;
			menuAberto = null;
			document.querySelector<HTMLElement>(`[data-alca="${id}"]`)?.closest('li')?.querySelector<HTMLElement>('[aria-haspopup="menu"]')?.focus();
		}
	}}
/>

{#snippet linha(bloco: Bloco, id: number)}
	{@const c = porId.get(id)}
	{@const lista = bloco === 'escada' ? escada : fora}
	{@const antes = bloco === 'escada' ? escadaAntes : foraAntes}
	{@const i = lista.indexOf(id)}
	{@const k = antes.indexOf(id)}
	{@const mudou = i !== k}
	{@const naEscada = bloco === 'escada'}
	{#if c}
		<li
			class="cat-ord-li"
			class:mudou
			class:arrastando={arrastando?.id === id}
			class:alvo-antes={alvo?.id === id && !alvo.abaixo}
			class:alvo-depois={alvo?.id === id && alvo.abaixo}
			ondragover={(e) => {
				if (!arrastando || arrastando.bloco !== bloco) return;
				e.preventDefault();
				const caixa = (e.currentTarget as HTMLElement).getBoundingClientRect();
				alvo = { id, abaixo: e.clientY > caixa.top + caixa.height / 2 };
			}}
			ondragleave={(e) => {
				if (!(e.currentTarget as HTMLElement).contains(e.relatedTarget as Node) && alvo?.id === id) alvo = null;
			}}
			ondrop={(e) => soltar(e, bloco, id)}
		>
			<button
				type="button"
				class="cat-alca"
				draggable="true"
				data-alca={id}
				aria-label="Mover {c.nome}. Use as setas do teclado para mudar de lugar."
				onkeydown={(e) => teclaNaAlca(e, bloco, id)}
				ondragstart={(e) => {
					arrastando = { bloco, id };
					e.dataTransfer?.setData('text/plain', String(id));
					const li = (e.currentTarget as HTMLElement).closest('li');
					if (li && e.dataTransfer) e.dataTransfer.setDragImage(li, 24, 24);
				}}
				ondragend={() => {
					arrastando = null;
					alvo = null;
				}}>⠿</button
			>
			<span class="cat-ord-n">{naEscada ? `Degrau ${i + 1}` : `${i + 1}ª`}</span>
			<span style="flex:1;min-width:0;display:flex;flex-direction:column">
				<strong class="cat-nome">{c.nome}</strong>
				<span style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:14px;color:var(--tinta-3)">
					{faixaCurta(c)}
					{#if mudou}
						<span class="cat-mudou">
							{naEscada ? (i > k ? `Subiu (era o degrau ${k + 1})` : `Desceu (era o degrau ${k + 1})`) : `Era a ${k + 1}ª`}
						</span>
					{/if}
				</span>
			</span>
			<span class="cat-mover" style="position:relative">
				<button
					type="button"
					class="cat-seta"
					aria-haspopup="menu"
					aria-expanded={menuAberto === id}
					aria-label="Mover {c.nome} para outra posição"
					onclick={() => (menuAberto = menuAberto === id ? null : id)}>Mover para…</button
				>
				{#if menuAberto === id}
					{@const posicoes = lista.map((x, q) => ({ x, q }))}
					<div class="cat-menu" role="menu">
						{#each naEscada ? [...posicoes].reverse() : posicoes as p (p.x)}
							<button
								type="button"
								role="menuitem"
								disabled={p.x === id}
								style:font-weight={p.x === id ? 700 : 500}
								onclick={() => {
									levarPara(bloco, id, p.q);
									menuAberto = null;
								}}
							>
								<span>{naEscada ? `Degrau ${p.q + 1}` : `${p.q + 1}ª posição`}</span>
								<span>{p.x === id ? 'onde está' : `hoje: ${nome(p.x)}`}</span>
							</button>
						{/each}
					</div>
				{/if}
			</span>
			<button
				type="button"
				class="cat-seta"
				disabled={naEscada ? i === lista.length - 1 : i === 0}
				aria-label="Subir: {c.nome}"
				onclick={() => passo(bloco, id, naEscada ? 1 : -1)}><span aria-hidden="true">↑</span>Subir</button
			>
			<button
				type="button"
				class="cat-seta"
				disabled={naEscada ? i === 0 : i === lista.length - 1}
				aria-label="Descer: {c.nome}"
				onclick={() => passo(bloco, id, naEscada ? -1 : 1)}><span aria-hidden="true">↓</span>Descer</button
			>
		</li>
	{/if}
{/snippet}

<section class="fm-cartao" aria-labelledby="t-ordem" aria-busy={salvando}>
	<div class="cat-cartao-corpo" style="gap:18px">
		<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px">
			<div style="display:flex;flex-direction:column;gap:4px;max-width:860px">
				<h2 id="t-ordem" class="fm-h2">Mudar a ordem</h2>
				<p class="cat-frase">Arraste pela alça <span aria-hidden="true">⠿</span> ou use as setas. <strong>Nada muda até você salvar.</strong></p>
				<p class="ins-ajuda">
					No painel inteiro, as categorias aparecem nesta ordem: primeiro a escada, do degrau mais novo ao mais velho, depois as que ficam fora dela.
				</p>
			</div>
			{#if salvando}
				<span role="status" style="display:flex;align-items:center;gap:10px;font-size:16px;font-weight:600;color:var(--tinta-3);white-space:nowrap">
					<span class="fm-spinner fm-spinner--pequeno"></span>Salvando a ordem...
				</span>
			{/if}
		</div>

		<div style="display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:28px;align-items:start">
			<div style="display:flex;flex-direction:column;gap:22px;min-width:0">
				<div style="display:flex;flex-direction:column;gap:8px">
					<h3 class="cat-h3">Escada por idade</h3>
					<p class="ins-ajuda">Aqui a ordem muda quem pode servir onde.</p>
					{#if escada.length === 0}
						<p class="ins-ajuda">Nenhuma categoria na escada.</p>
					{:else}
						<span class="ins-rot" style="padding-top:4px">Topo · mais velhos</span>
						<ol class="cat-ord" aria-label="Escada, do topo para a base">
							{#each [...escada].reverse() as id (id)}
								{@render linha('escada', id)}
							{/each}
						</ol>
						<span class="ins-rot">Base · mais novos</span>
					{/if}
				</div>
				{#if fora.length > 0}
					<div style="display:flex;flex-direction:column;gap:8px">
						<h3 class="cat-h3">Fora da escada</h3>
						<p class="ins-ajuda">Aqui a ordem só muda como elas aparecem no painel.</p>
						<ol class="cat-ord" aria-label="Fora da escada">
							{#each fora as id (id)}
								{@render linha('fora', id)}
							{/each}
						</ol>
					</div>
				{/if}
			</div>

			<aside class="cat-muda" aria-labelledby="t-muda">
				<h3 id="t-muda" class="cat-h3">O que muda</h3>
				{#if !mudouAlgo}
					<p class="ins-ajuda">Nada mudou ainda. Quando você mexer na escada, aparece aqui quem ganha ou perde lugares para servir.</p>
				{:else if mudancas.length === 0}
					<p class="ins-ajuda">Muda só a ordem em que as categorias aparecem no painel. Nenhuma regra de servir muda.</p>
				{:else}
					<ul>
						{#each mudancas as m, i (i)}
							<li>
								<span class="cat-sinal" class:cat-sinal--mais={m.mais} class:cat-sinal--menos={!m.mais} aria-hidden="true">{m.mais ? '+' : '−'}</span>
								<span>{m.texto}</span>
							</li>
						{/each}
					</ul>
					<p class="ins-ajuda">Vale na hora, para as próximas inscrições de servo de todos os acampamentos. Quem já está inscrito continua.</p>
				{/if}
				{#if avisoIdade}
					<p class="ins-aviso amb"><span>{avisoIdade} A escada costuma seguir a idade: confira antes de salvar.</span></p>
				{/if}
			</aside>
		</div>

		<div class="fm-janela-acoes" style="padding-top:8px;border-top:1px solid var(--linha)">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={cancelar} disabled={salvando}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={salvar} disabled={!mudouAlgo || salvando}>
				{salvando ? 'Salvando a ordem...' : 'Salvar ordem'}
			</button>
		</div>
	</div>
	<p class="ins-sr" aria-live="polite">{anuncio}</p>
</section>
