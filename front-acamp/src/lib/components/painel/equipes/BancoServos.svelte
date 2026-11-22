<script lang="ts">
	/**
	 * Banco de servos: inscritos como servo que não estão em nenhum setor nem são
	 * servo geral (o servidor já tira recusados, cancelados e desistentes). Busca
	 * pelo nome e, depois do sorteio, filtros por situação. O CPF mascarado só
	 * vem para a organização.
	 */
	import type { PessoaDoBanco } from '$lib/services/equipeService';
	import { buscaNoBanco, infoDaPessoa, SITUACAO, textoDaSituacao } from '$lib/equipes';

	let {
		banco,
		sorteioFeito,
		textoBotao,
		subtitulo,
		comFiltros = true,
		semInscritos = false,
		onpor
	}: {
		banco: PessoaDoBanco[];
		sorteioFeito: boolean;
		/** "Pôr em…" (painel, servo geral) ou "Pôr na Cozinha" (líder); null = sem botão. */
		textoBotao: string | null;
		subtitulo?: string;
		comFiltros?: boolean;
		/** Ninguém se inscreveu como servo ainda. */
		semInscritos?: boolean;
		onpor: (p: PessoaDoBanco) => void;
	} = $props();

	type Filtro = 'todos' | 'sort' | 'sup' | 'nao';
	let busca = $state('');
	let filtro: Filtro = $state('todos');

	const FILTROS: [Filtro, string][] = [
		['todos', 'Todos'],
		['sort', 'Sorteados'],
		['sup', 'Suplentes'],
		['nao', 'Não sorteados']
	];
	const filtros = $derived(sorteioFeito && comFiltros ? FILTROS : []);
	const doFiltro = (f: Filtro) => (f === 'todos' ? banco : banco.filter((p) => p.situacao === f));
	const lista = $derived(buscaNoBanco(doFiltro(sorteioFeito ? filtro : 'todos'), busca));

	const texto = $derived(
		subtitulo ??
			(sorteioFeito
				? 'Inscritos como servo que não estão em nenhum setor: suplentes, não sorteados e quem desistiu de um lugar.'
				: 'Inscritos como servo que ainda não estão em nenhum setor. Antes do sorteio, são quase todos.')
	);
</script>

<aside class="eq-banco" aria-labelledby="t-banco">
	<div>
		<h2 id="t-banco" class="eq-banco-titulo">Banco de servos <span>{banco.length}</span></h2>
		<p class="eq-banco-sub">{texto}</p>
	</div>
	{#if banco.length}
		<div class="eq-busca">
			<label for="busca-banco" class="ins-sr">Buscar no banco</label>
			<input id="busca-banco" type="search" class="fm-campo" placeholder="Buscar pelo nome" bind:value={busca} />
		</div>
		{#if filtros.length}
			<div class="eq-filtros" role="radiogroup" aria-label="Situação">
				{#each filtros as [k, nome] (k)}
					<button type="button" role="radio" aria-checked={filtro === k} class="eq-filtro" onclick={() => (filtro = k)}>
						{nome} {doFiltro(k).length}
					</button>
				{/each}
			</div>
		{/if}
	{/if}

	{#if lista.length}
		<ul class="eq-banco-lista">
			{#each lista as p (p.user_id)}
				<li class="eq-banco-item">
					<div class="eq-banco-item-topo">
						<strong>{p.nome}</strong>
						<span class="ins-pill {SITUACAO[p.situacao][1]}">{textoDaSituacao(p)}</span>
					</div>
					<div class="eq-banco-item-pe">
						<span class="eq-banco-info">{infoDaPessoa(p)}</span>
						{#if textoBotao}
							<button type="button" class="ins-acao" aria-label="{textoBotao} — {p.nome}" onclick={() => onpor(p)}>{textoBotao}</button>
						{/if}
					</div>
				</li>
			{/each}
		</ul>
	{:else}
		<p class="eq-banco-vazio">
			{#if semInscritos}
				Ninguém se inscreveu como servo neste acampamento ainda.
			{:else if busca.trim()}
				Ninguém com “{busca.trim()}” no banco.
			{:else if banco.length}
				Ninguém nesta situação.
			{:else}
				Todos os inscritos como servo já estão numa equipe.
			{/if}
		</p>
	{/if}
</aside>
