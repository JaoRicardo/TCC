<script lang="ts">
	/**
	 * Gestão › Categorias (permissão "categorias"; handoff
	 * `design_handoff_categorias`). As regras de quem participa ficam nos
	 * campos da categoria, não no nome — por isso renomear não quebra nada.
	 *
	 * - Lista vertical: a escada por idade do topo (mais velhos) para a base
	 *   (mais novos) e, à parte, as que ficam fora dela. Clicar numa categoria
	 *   mostra onde quem foi campista nela pode servir e abre o painel à direita.
	 * - Uma ordem só no painel inteiro: a escada (do degrau mais novo ao mais
	 *   velho), depois as de fora. Mudar a ordem é um modo próprio, com prévia.
	 * - "Tirar de uso…": a janela decide entre arquivar (já teve acampamento) e
	 *   excluir (nunca teve). A arquivada da escada continua na regra de servir.
	 */
	import { onDestroy, onMount } from 'svelte';
	import '$lib/styles/formularios.css';
	import '$lib/styles/inscritos.css';
	import '$lib/styles/equipes.css';
	import '$lib/styles/categorias.css';
	import Janela from '$lib/components/painel/Janela.svelte';
	import JanelaCategoria from '$lib/components/painel/categorias/JanelaCategoria.svelte';
	import MudarOrdem from '$lib/components/painel/categorias/MudarOrdem.svelte';
	import PainelCategoria from '$lib/components/painel/categorias/PainelCategoria.svelte';
	import SeletorDegrau from '$lib/components/painel/categorias/SeletorDegrau.svelte';
	import { dataDoAcampamento, faseDoAcampamento } from '$lib/components/painel/categorias/datas';
	import { pode } from '$lib/permissoes';
	import { TEXTO_DA_FASE } from '$lib/faseAtividade';
	import {
		comNoDegrau,
		depoisDe,
		escadaCompleta,
		escadaEmUso,
		faixaCurta,
		faltaConfigurar,
		foraDaEscada,
		junta,
		lugarNaEscada,
		podeServirEm,
		textoPodeServirEm,
		type CategoriaDaLista,
		type RegrasDaCategoria
	} from '$lib/categorias';
	import { arquivarCategoria, desarquivarCategoria, excluirCategoria, listarCategorias } from '$lib/services/categoriaService';

	let categorias: CategoriaDaLista[] = $state([]);
	let estado: 'carregando' | 'erro' | 'pronto' = $state('carregando');

	const escada = $derived(escadaEmUso(categorias));
	const fora = $derived(foraDaEscada(categorias));
	const arquivadas = $derived(categorias.filter((c) => c.arquivada).sort((a, b) => a.posicao - b.posicao));
	const arquivadasNaEscada = $derived(escadaCompleta(categorias).filter((c) => c.arquivada));
	const veFormularios = pode(['formularios', 'respostas_formularios']);

	async function carregar(): Promise<boolean> {
		const r = await listarCategorias();
		if (!r.ok) {
			if (estado !== 'pronto') estado = 'erro';
			return false;
		}
		categorias = r.data;
		estado = 'pronto';
		if (sel !== null && !categorias.some((c) => c.id === sel && !c.arquivada)) sel = null;
		return true;
	}
	onMount(carregar);

	function tentarDeNovo() {
		estado = 'carregando';
		carregar();
	}

	const plural = (n: number, um: string, varios: string) => `${n} ${n === 1 ? um : varios}`;
	const dataBr = (iso: string | null | undefined) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '');
	const porId = (id: number | null) => categorias.find((c) => c.id === id) ?? null;

	// ─── Aviso no canto ───────────────────────────────────────────────────

	let aviso: { texto: string; erro?: boolean; desfazer?: () => void } | null = $state(null);
	let timerAviso: ReturnType<typeof setTimeout> | undefined;
	function avisar(texto: string, extra: { erro?: boolean; desfazer?: () => void } = {}) {
		clearTimeout(timerAviso);
		aviso = { texto, ...extra };
		timerAviso = setTimeout(() => (aviso = null), 6000);
	}
	onDestroy(() => clearTimeout(timerAviso));

	// ─── Seleção ──────────────────────────────────────────────────────────

	let sel: number | null = $state(null);
	/** A recém-criada: o painel mostra o aviso de próximos passos até selecionar outra. */
	let novo: number | null = $state(null);
	const selecionada = $derived(porId(sel));
	const idxSel = $derived(selecionada?.naEscada ? escada.findIndex((c) => c.id === sel) : -1);

	function selecionar(id: number) {
		sel = sel === id ? null : id;
		if (sel !== novo) novo = null;
	}

	const legenda = $derived.by(() => {
		const c = selecionada;
		if (!c) return 'Clique numa categoria para ver onde quem foi campista nela pode servir.';
		if (c.naEscada) return `Quem foi campista no ${c.nome} pode servir ${textoPodeServirEm(c, categorias)}.`;
		return `${c.nome} fica fora da escada: quem foi campista nela só serve nela.`;
	});

	function estadoDaLinha(c: CategoriaDaLista, i: number, naEscada: boolean): 'livre' | 'sel' | 'serve' | 'apagada' {
		if (sel === null) return 'livre';
		if (c.id === sel) return 'sel';
		if (naEscada && idxSel >= 0 && i < idxSel) return 'serve';
		return 'apagada';
	}

	function detalheDaLinha(c: CategoriaDaLista): string {
		const requer = c.requerCategoriaId !== null ? porId(c.requerCategoriaId) : null;
		const extra = requer ? `precisa ter sido campista no ${requer.nome}` : !c.aceitaCasados && !c.soCasais ? 'sem casados' : '';
		return faixaCurta(c) + (extra ? ` · ${extra}` : '');
	}

	/** Largura da faixa da escada: de 16 px no primeiro degrau até 72 px no topo. */
	const larguraDaFaixa = (i: number, n: number) => (n > 1 ? 16 + Math.round((i * 56) / (n - 1)) : 40);

	// ─── Modo "Mudar a ordem" ─────────────────────────────────────────────

	let modoOrdem = $state(false);

	// ─── Janelas ──────────────────────────────────────────────────────────

	let formulario: { c: CategoriaDaLista | null } | null = $state(null);
	let tirando: CategoriaDaLista | null = $state(null);
	let reativando: CategoriaDaLista | null = $state(null);
	let enviando = $state(false);
	let erroJanela = $state('');

	async function categoriaSalva(c: RegrasDaCategoria, nova: boolean) {
		formulario = null;
		await carregar();
		sel = c.id;
		if (nova) novo = c.id;
		else if (novo !== c.id) novo = null;
		avisar(nova ? `A categoria ${c.nome} foi criada.` : `A categoria ${c.nome} foi salva.`);
	}

	// "Tirar de uso…": a janela decide.
	let escolha: '' | 'arquivar' | 'excluir' = $state('');
	let entendo = $state(false);

	function tirarDeUso(c: CategoriaDaLista) {
		tirando = c;
		escolha = '';
		entendo = false;
		erroJanela = '';
	}

	function fecharTirar() {
		if (!enviando) tirando = null;
	}

	async function arquivar(c: CategoriaDaLista, mensagemDeFalha: string) {
		erroJanela = '';
		enviando = true;
		const r = await arquivarCategoria(c.id);
		enviando = false;
		if (!r.ok) {
			erroJanela = r.codigo === 'acampamento_por_acontecer' && r.error ? r.error : mensagemDeFalha;
			return;
		}
		tirando = null;
		if (sel === c.id) sel = null;
		await carregar();
		avisar(`A categoria ${c.nome} foi arquivada.`, { desfazer: () => desfazerArquivar(c) });
	}

	async function excluir(c: CategoriaDaLista) {
		erroJanela = '';
		enviando = true;
		const r = await excluirCategoria(c.id);
		enviando = false;
		if (!r.ok) {
			erroJanela =
				r.codigo === 'ja_teve_acampamento' && r.error
					? r.error
					: 'Não foi possível concluir. Verifique sua conexão com a internet e tente novamente.';
			return;
		}
		tirando = null;
		if (sel === c.id) sel = null;
		await carregar();
		avisar(`A categoria ${c.nome} foi excluída.`);
	}

	async function desfazerArquivar(c: CategoriaDaLista) {
		aviso = null;
		// Sem degrau: volta no mesmo lugar.
		const r = await desarquivarCategoria(c.id);
		if (!r.ok) {
			avisar('Não foi possível reativar. Verifique sua conexão com a internet e tente novamente.', { erro: true });
			return;
		}
		await carregar();
		sel = c.id;
		avisar(`A categoria ${c.nome} voltou para a lista.`);
	}

	// Reativar: na escada, confirma o degrau (o de antes já vem marcado).
	let depoisReativar: number | null = $state(null);
	const depoisDeAntes = $derived(reativando ? depoisDe(reativando, categorias) : null);

	function abrirReativar(c: CategoriaDaLista) {
		reativando = c;
		depoisReativar = depoisDe(c, categorias);
		erroJanela = '';
	}

	const efeitoReativar = $derived.by(() => {
		const c = reativando;
		if (!c || !c.naEscada) return null;
		if (depoisReativar === depoisDeAntes) return { mesmo: true, texto: 'No mesmo lugar de antes: nada muda em quem pode servir onde.' };
		const ativa = { ...c, arquivada: false };
		const lista = comNoDegrau(categorias as RegrasDaCategoria[], ativa, depoisReativar);
		const ela = lista.find((x) => x.id === c.id) ?? ativa;
		return {
			mesmo: false,
			texto: `Neste degrau, quem foi campista no ${c.nome} passa a poder servir ${textoPodeServirEm(ela, lista)}, e quem foi campista nos degraus de cima pode servir nele.`
		};
	});

	async function reativar() {
		const c = reativando;
		if (!c || enviando) return;
		erroJanela = '';
		enviando = true;
		// Mesmo degrau = não mexe na sequência; fora da escada = volta para o fim.
		const opcoes = c.naEscada ? (depoisReativar === depoisDeAntes ? {} : { depois: depoisReativar }) : { fim: true };
		const r = await desarquivarCategoria(c.id, opcoes);
		enviando = false;
		if (!r.ok) {
			erroJanela = r.erros?.depois ?? 'Não foi possível reativar. Verifique sua conexão com a internet e tente novamente.';
			return;
		}
		reativando = null;
		await carregar();
		sel = c.id;
		const agora = porId(c.id);
		avisar(
			agora?.naEscada
				? `A categoria ${c.nome} voltou para a escada, ${lugarNaEscada(agora, categorias)}.`
				: `A categoria ${c.nome} voltou para a lista.`
		);
	}

	// ─── Seção "Arquivadas" ───────────────────────────────────────────────

	let arquivadasAbertas = $state(false);

	async function verArquivadas() {
		arquivadasAbertas = true;
		await Promise.resolve();
		document.getElementById('cat-arquivadas')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	function regraDaArquivada(c: CategoriaDaLista): string {
		const serve = podeServirEm(c, categorias)
			.filter((x) => x.id !== c.id)
			.map((x) => `no ${x.nome}`);
		return `Continua na escada, ${lugarNaEscada(c, categorias)}: quem foi campista nela pode servir ${junta(serve) || 'só nela'}.`;
	}
</script>

<svelte:head>
	<title>Categorias | Painel da Comunidade</title>
</svelte:head>

{#snippet linhaDaLista(c: CategoriaDaLista, i: number, naEscada: boolean)}
	{@const st = estadoDaLinha(c, i, naEscada)}
	{@const falta = faltaConfigurar(c)}
	<li>
		<button
			type="button"
			class="cat-linha"
			class:cat-linha--fora={!naEscada}
			class:sel={st === 'sel'}
			class:apagada={st === 'apagada'}
			aria-pressed={st === 'sel'}
			onclick={() => selecionar(c.id)}
		>
			{#if naEscada}
				<span class="cat-faixa" class:sel={st === 'sel'} class:serve={st === 'serve'} class:apagada={st === 'apagada'} aria-hidden="true">
					<span style="width:{larguraDaFaixa(i, escada.length)}px"></span>
				</span>
				<span class="cat-num"><span class="ins-sr">Degrau </span>{i + 1}</span>
			{/if}
			<span class="cat-txt">
				<span class="cat-l1">
					<strong class="cat-nome">{c.nome}</strong>
					{#if st === 'sel'}<span class="cat-tag cat-tag--sel">Campista aqui</span>{/if}
					{#if st === 'serve'}<span class="cat-tag cat-tag--serve">Pode servir</span>{/if}
				</span>
				<span class="cat-detalhe">{detalheDaLinha(c)}</span>
			</span>
			<span class="cat-uso">
				<span>{c.acampamentos ? plural(c.acampamentos, 'acampamento', 'acampamentos') : 'Nenhum acampamento ainda'}</span>
				{#if c.porAcontecer.length > 0}<strong>{c.porAcontecer.length} por acontecer</strong>{/if}
				{#if falta > 0}<span class="cat-falta">{falta === 3 ? 'Falta configurar tudo' : `Falta configurar ${falta} de 3`}</span>{/if}
			</span>
		</button>
	</li>
{/snippet}

{#snippet acampamentosPorAcontecer(c: CategoriaDaLista)}
	<div style="display:flex;flex-direction:column;gap:8px">
		{#each c.porAcontecer as a (a.id)}
			{@const fase = faseDoAcampamento(a)}
			<div class="cat-acamp" style="padding:12px 16px;border-radius:12px">
				<span><strong style="font-size:17px">{a.nome}</strong><small style="font-size:15px">{dataDoAcampamento(a)}</small></span>
				<span class="cat-fase cat-fase--{fase}">{TEXTO_DA_FASE[fase]}</span>
			</div>
		{/each}
	</div>
{/snippet}

<div class="fm eq cat">
	<div class="cabeca">
		<div class="cabeca-textos">
			<h1 class="fm-h1">Categorias</h1>
			<p class="fm-sub">As categorias dos acampamentos e quem pode participar de cada uma, como campista e como servo.</p>
		</div>
		{#if estado === 'pronto' && !modoOrdem}
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={() => (formulario = { c: null })}>+ Nova categoria</button>
		{/if}
	</div>

	{#if estado === 'carregando'}
		<section class="fm-cartao" aria-busy="true" style="overflow:hidden">
			<div class="carregando"><span class="fm-spinner"></span>Carregando as categorias...</div>
			<div style="display:flex;flex-direction:column;gap:10px;padding:20px 24px 24px">
				{#each [0, 1, 2, 3, 4] as k (k)}<span class="fm-osso fm-osso--claro" style="height:52px"></span>{/each}
			</div>
		</section>
	{:else if estado === 'erro'}
		<section class="fm-cartao">
			<div class="centro" role="alert">
				<span class="erro-icone" aria-hidden="true">!</span>
				<p class="centro-titulo">Não foi possível carregar as categorias.</p>
				<p class="centro-texto">Verifique sua conexão com a internet e tente novamente.</p>
				<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={tentarDeNovo}>Tentar novamente</button>
			</div>
		</section>
	{:else if modoOrdem}
		<MudarOrdem
			{categorias}
			onsair={() => (modoOrdem = false)}
			onsalvou={(lista) => {
				categorias = lista;
				modoOrdem = false;
				avisar('A nova ordem foi salva.');
			}}
			onfalhou={() => avisar('Não foi possível salvar a ordem. Verifique sua conexão com a internet e tente novamente.', { erro: true })}
		/>
	{:else if escada.length + fora.length === 0}
		<section class="fm-cartao">
			<div class="centro">
				<div aria-hidden="true" class="vazio-desenho"><span></span><span></span><span></span></div>
				<p class="centro-titulo">Nenhuma categoria em uso.</p>
				<p class="centro-texto">Crie uma categoria para poder cadastrar acampamentos nela.</p>
				<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={() => (formulario = { c: null })}>+ Nova categoria</button>
			</div>
		</section>
	{:else}
		<div class="colunas">
			<div class="coluna-lista">
				<section class="fm-cartao" aria-labelledby="t-escada">
					<div class="cat-cartao-corpo">
						<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
							<div style="display:flex;flex-direction:column;gap:4px">
								<h2 id="t-escada" class="fm-h2">Escada por idade</h2>
								<p class="cat-frase">
									Quem foi campista num degrau pode servir <strong>nele e em todos os degraus de baixo</strong>, nunca nos de cima.
								</p>
							</div>
							<button type="button" class="ins-acao" onclick={() => (modoOrdem = true)}>
								<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 20V4M4 7l3-3 3 3M17 4v16M14 17l3 3 3-3" /></svg>Mudar a ordem
							</button>
						</div>
						<p class="ins-aviso azul" role="status"><span>{legenda}</span></p>
						{#if escada.length === 0}
							<p class="ins-ajuda" style="padding:12px 0">
								Nenhuma categoria na escada ainda. Ao criar ou editar uma categoria, escolha “Entra na escada por idade”.
							</p>
						{:else}
							<div style="display:flex;flex-direction:column;gap:6px">
								<span class="cat-ponta">Topo · mais velhos</span>
								<ul class="cat-lista" aria-label="Escada, do topo (mais velhos) para a base (mais novos)">
									{#each [...escada].reverse() as c (c.id)}
										{@render linhaDaLista(c, escada.indexOf(c), true)}
									{/each}
								</ul>
								<span class="cat-ponta">Base · mais novos</span>
							</div>
						{/if}
						{#if arquivadasNaEscada.length > 0}
							<p class="ins-ajuda">
								{arquivadasNaEscada
									.map((c) => `${c.nome} (arquivada) continua na escada, ${lugarNaEscada(c, categorias)}, para quem já foi campista nela.`)
									.join(' ')}
								<button type="button" class="ins-link" style="font-size:15px" onclick={verArquivadas}>Ver arquivadas</button>
							</p>
						{/if}
					</div>
				</section>

				{#if fora.length > 0}
					<section class="fm-cartao" aria-labelledby="t-fora">
						<div class="cat-cartao-corpo" style="gap:12px">
							<div style="display:flex;flex-direction:column;gap:4px">
								<h2 id="t-fora" class="fm-h2" style="font-size:26px">Fora da escada</h2>
								<p class="cat-frase">Quem foi campista numa delas <strong>só serve nela mesma</strong>.</p>
							</div>
							<ul class="cat-lista" aria-label="Categorias fora da escada">
								{#each fora as c, i (c.id)}
									{@render linhaDaLista(c, i, false)}
								{/each}
							</ul>
						</div>
					</section>
				{/if}
			</div>

			<div class="coluna-painel">
				{#if selecionada && !selecionada.arquivada}
					{@const c = selecionada}
					{#key c.id}
						<PainelCategoria
							{c}
							{categorias}
							novo={novo === c.id}
							oneditar={() => (formulario = { c })}
							ontirar={() => tirarDeUso(c)}
							onfechar={() => (sel = null)}
						/>
					{/key}
				{:else}
					<section class="fm-cartao" style="display:flex;flex-direction:column;gap:14px;padding:28px 26px">
						<h2 class="cat-h3" style="font-size:26px">Nenhuma categoria selecionada</h2>
						<p class="cat-frase">Clique numa categoria da lista para ver as regras, os acampamentos e o que falta configurar.</p>
						<div class="legenda-cores">
							<span><i style="background:#1b2f5e"></i>A categoria escolhida</span>
							<span><i style="background:#d6be72"></i>Degraus onde quem foi campista nela pode servir</span>
							<span><i style="background:#efe7d3"></i>Degraus de cima: não pode servir</span>
						</div>
					</section>
				{/if}
			</div>
		</div>
	{/if}

	{#if estado === 'pronto' && !modoOrdem && arquivadas.length > 0}
		<section class="fm-cartao" id="cat-arquivadas" style="overflow:hidden">
			<button
				type="button"
				class="arq-cabeca"
				aria-expanded={arquivadasAbertas}
				aria-controls="arq-lista"
				onclick={() => (arquivadasAbertas = !arquivadasAbertas)}
			>
				<span aria-hidden="true" class="arq-seta" class:aberta={arquivadasAbertas}>▸</span>
				<span class="arq-titulo">Arquivadas</span>
				<span class="arq-n">{arquivadas.length}</span>
				<span class="arq-sub">Não aparecem ao criar acampamentos. Os formulários e as respostas continuam acessíveis.</span>
			</button>
			{#if arquivadasAbertas}
				<div id="arq-lista" class="arq-lista">
					{#each arquivadas as c (c.id)}
						<div class="arq-item">
							<span style="display:flex;flex-direction:column;gap:3px">
								<strong class="arq-nome">{c.nome}</strong>
								<span style="font-size:15px;color:var(--tinta-3)">
									Arquivada em {dataBr(c.arquivadaEm)} · {c.acampamentos ? plural(c.acampamentos, 'acampamento', 'acampamentos') : '0 acampamentos'}
								</span>
								{#if c.naEscada}<span style="font-size:15px;color:var(--tinta-2)">{regraDaArquivada(c)}</span>{/if}
							</span>
							<span style="display:flex;gap:10px;flex:0 0 auto">
								{#if veFormularios}<a href="/admin/formularios" class="fm-btn">Ver formulários</a>{/if}
								<button type="button" class="fm-btn fm-btn--contorno" onclick={() => abrirReativar(c)}>Reativar…</button>
							</span>
						</div>
					{/each}
				</div>
			{/if}
		</section>
	{/if}
</div>

{#if formulario}
	<JanelaCategoria {categorias} editando={formulario.c} onfechar={() => (formulario = null)} onsalva={categoriaSalva} />
{/if}

{#if tirando}
	{@const c = tirando}
	{#if c.porAcontecer.length > 0}
		<Janela idTitulo="t-np" alerta largura={620} onfechar={fecharTirar}>
			<h2 id="t-np" class="fm-janela-titulo">{c.nome} ainda não pode ser arquivada</h2>
			<p class="ins-janela-texto">
				{c.porAcontecer.length === 1
					? 'Há um acampamento desta categoria por acontecer. Ela pode ser arquivada depois que ele terminar.'
					: `Há ${c.porAcontecer.length} acampamentos desta categoria por acontecer. Ela pode ser arquivada depois que eles terminarem.`}
			</p>
			{@render acampamentosPorAcontecer(c)}
			<div class="fm-janela-acoes">
				<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" data-foco-inicial onclick={fecharTirar}>Entendi</button>
			</div>
		</Janela>
	{:else if c.acampamentos > 0}
		<Janela idTitulo="t-arq" largura={620} travada={enviando} onfechar={fecharTirar}>
			<h2 id="t-arq" class="fm-janela-titulo">Arquivar a categoria {c.nome}?</h2>
			<p class="ins-janela-texto">Ela já teve acampamento, por isso fica arquivada, e não excluída.</p>
			<ul class="fm-janela-lista">
				<li>Deixa de aparecer ao criar acampamentos e no modelo de setores.</li>
				<li>
					{c.acampamentos === 1
						? 'O acampamento dela, os formulários e as respostas continuam acessíveis.'
						: `Os ${c.acampamentos} acampamentos dela, os formulários e as respostas continuam acessíveis.`}
				</li>
				<li>Quem foi campista nela continua podendo servir como antes.</li>
			</ul>
			{#if c.naEscada}<p class="ins-ajuda">Ela continua na escada, {lugarNaEscada(c, categorias)}, para quem já foi campista nela.</p>{/if}
			<p class="ins-ajuda">Dá para reativar quando quiser.</p>
			{#if erroJanela}<p role="alert" class="ins-aviso verm"><span>{erroJanela}</span></p>{/if}
			<div class="fm-janela-acoes">
				<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={fecharTirar} disabled={enviando}>Cancelar</button>
				<button
					type="button"
					class="fm-btn fm-btn--ouro fm-btn--grande"
					disabled={enviando}
					onclick={() => arquivar(c, 'Não foi possível arquivar. Verifique sua conexão com a internet e tente novamente.')}
				>
					{enviando ? 'Arquivando...' : 'Arquivar'}
				</button>
			</div>
		</Janela>
	{:else}
		<Janela idTitulo="t-ex" largura={640} travada={enviando} onfechar={fecharTirar}>
			<h2 id="t-ex" class="fm-janela-titulo">Tirar de uso a categoria {c.nome}</h2>
			<p class="ins-janela-texto">Ela nunca teve acampamento. Escolha o que fazer:</p>
			<div role="radiogroup" aria-labelledby="t-ex" style="display:flex;flex-direction:column;gap:8px">
				<button
					type="button"
					role="radio"
					class="cat-opcao"
					aria-checked={escolha === 'arquivar'}
					onclick={() => {
						escolha = 'arquivar';
						entendo = false;
					}}
				>
					<span class="cat-bola" aria-hidden="true"></span>
					<span class="cat-op-txt">
						<span class="cat-op-t">Arquivar</span>
						<span class="cat-op-s">Some da criação de acampamentos e do modelo de setores. Os formulários continuam guardados, e dá para reativar.</span>
					</span>
				</button>
				<button
					type="button"
					role="radio"
					class="cat-opcao"
					aria-checked={escolha === 'excluir'}
					onclick={() => {
						escolha = 'excluir';
						entendo = false;
					}}
				>
					<span class="cat-bola" aria-hidden="true"></span>
					<span class="cat-op-txt">
						<span class="cat-op-t">Excluir de vez</span>
						<span class="cat-op-s">
							Apaga a categoria, os formulários dela (perguntas de campista e de servo) e o modelo de setores. Não dá para desfazer.
						</span>
					</span>
				</button>
			</div>
			{#if escolha === 'excluir'}
				{#if c.naEscada}<p class="ins-ajuda">Ela sai da escada. Como ninguém foi campista nela, ninguém perde lugar para servir.</p>{/if}
				<label class="ins-check"><input type="checkbox" bind:checked={entendo} />Entendo que não dá para desfazer</label>
			{/if}
			{#if erroJanela}<p role="alert" class="ins-aviso verm"><span>{erroJanela}</span></p>{/if}
			<div class="fm-janela-acoes">
				<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={fecharTirar} disabled={enviando}>Cancelar</button>
				{#if escolha === ''}
					<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" disabled>Escolha uma opção</button>
				{:else if escolha === 'arquivar'}
					<button
						type="button"
						class="fm-btn fm-btn--ouro fm-btn--grande"
						disabled={enviando}
						onclick={() => arquivar(c, 'Não foi possível concluir. Verifique sua conexão com a internet e tente novamente.')}
					>
						{enviando ? 'Arquivando...' : 'Arquivar'}
					</button>
				{:else}
					<button type="button" class="fm-btn fm-btn--perigo-cheio fm-btn--grande" disabled={!entendo || enviando} onclick={() => excluir(c)}>
						{enviando ? 'Excluindo...' : 'Excluir categoria'}
					</button>
				{/if}
			</div>
		</Janela>
	{/if}
{/if}

{#if reativando}
	{@const c = reativando}
	<Janela idTitulo="t-re" largura={640} travada={enviando} onfechar={() => !enviando && (reativando = null)}>
		<h2 id="t-re" class="fm-janela-titulo">Reativar a categoria {c.nome}?</h2>
		{#if c.naEscada}
			<p class="ins-janela-texto">Ela volta para a escada. Confirme o degrau:</p>
			<div class="cat-fld">
				<span id="l-deg2" class="cat-lbl">Fica logo acima de</span>
				<SeletorDegrau {escada} bind:depois={depoisReativar} atual={depoisDeAntes} marcaAtual="(onde estava)" idRotulo="l-deg2" />
			</div>
			{#if efeitoReativar}
				<p class="ins-aviso" class:azul={efeitoReativar.mesmo} class:amb={!efeitoReativar.mesmo}><span>{efeitoReativar.texto}</span></p>
			{/if}
		{/if}
		<p class="ins-ajuda">Ela volta a aparecer ao criar acampamentos e no modelo de setores, com os formulários e os setores como estavam.</p>
		{#if erroJanela}<p role="alert" class="ins-aviso verm"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={() => (reativando = null)} disabled={enviando}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={reativar} disabled={enviando}>
				{enviando ? 'Reativando...' : 'Reativar'}
			</button>
		</div>
	</Janela>
{/if}

{#if aviso}
	<div class="eq-toast" class:eq-toast--erro={aviso.erro} role="status">
		<span aria-hidden="true">{aviso.erro ? '!' : '✓'}</span><span>{aviso.texto}</span>
		{#if aviso.desfazer}
			<button type="button" class="desfazer" onclick={aviso.desfazer}>Desfazer</button>
		{/if}
		<button type="button" aria-label="Fechar aviso" onclick={() => (aviso = null)}>✕</button>
	</div>
{/if}

<style>
	.cabeca {
		display: flex;
		justify-content: space-between;
		align-items: flex-end;
		gap: 20px;
		flex-wrap: wrap;
	}
	.cabeca-textos {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.cabeca-textos .fm-sub {
		max-width: 860px;
	}

	.colunas {
		display: grid;
		grid-template-columns: minmax(0, 1fr) 480px;
		gap: 20px;
		align-items: start;
	}
	.coluna-lista {
		display: flex;
		flex-direction: column;
		gap: 20px;
		min-width: 0;
	}
	.coluna-painel {
		position: sticky;
		top: 16px;
		min-width: 0;
	}

	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 18px 24px;
		font-size: 17px;
		color: var(--tinta-3);
		border-bottom: 1px solid var(--linha);
	}
	.centro {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 12px;
		padding: 64px 24px;
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
	.erro-icone {
		width: 56px;
		height: 56px;
		border-radius: 50%;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
		color: var(--vermelho);
		font-size: 28px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.vazio-desenho {
		display: flex;
		align-items: flex-end;
		gap: 6px;
		margin-bottom: 8px;
	}
	.vazio-desenho span {
		width: 44px;
		border-radius: 6px 6px 0 0;
	}
	.vazio-desenho span:nth-child(1) {
		height: 22px;
		background: #efe7d3;
	}
	.vazio-desenho span:nth-child(2) {
		height: 40px;
		background: #e9dfc4;
	}
	.vazio-desenho span:nth-child(3) {
		height: 58px;
		background: #e2ce8a;
	}

	.legenda-cores {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding-top: 12px;
		border-top: 1px solid var(--linha);
		font-size: 15px;
		color: var(--tinta-2);
	}
	.legenda-cores span {
		display: flex;
		align-items: center;
		gap: 10px;
	}
	.legenda-cores i {
		width: 34px;
		height: 14px;
		border-radius: 0 4px 4px 0;
	}

	.arq-cabeca {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
		width: 100%;
		padding: 18px 24px;
		text-align: left;
		border: none;
		background: transparent;
		cursor: pointer;
	}
	.arq-seta {
		display: inline-block;
		color: var(--ouro-texto);
		transition: transform 0.15s;
	}
	.arq-seta.aberta {
		transform: rotate(90deg);
	}
	.arq-titulo {
		font: 600 26px 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.arq-n {
		min-width: 26px;
		height: 26px;
		padding: 0 6px;
		box-sizing: border-box;
		border-radius: 999px;
		background: #efe7d3;
		font-size: 14px;
		font-weight: 700;
		color: var(--tinta-3);
		display: inline-flex;
		align-items: center;
		justify-content: center;
	}
	.arq-sub {
		font-size: 15px;
		color: var(--tinta-3);
	}
	.arq-lista {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 0 24px 22px;
	}
	.arq-item {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 20px;
		padding: 16px 20px;
		background: #f5f1e7;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}
	.arq-nome {
		font: 600 24px 'Cormorant Garamond', serif;
		color: #3d4152;
	}

	/* O "Desfazer" do aviso: pílula, não o círculo do "✕". */
	.eq-toast .desfazer {
		flex: 0 0 auto;
		width: auto;
		height: 34px;
		padding: 0 14px;
		border: 1px solid rgba(255, 253, 248, 0.6);
		border-radius: 999px;
		background: transparent;
		font: 700 15px 'Source Sans 3', sans-serif;
	}
</style>
