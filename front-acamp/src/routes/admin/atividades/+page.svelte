<script lang="ts">
	/**
	 * Gestão de Atividades (handoff `Desing/design_handoff_gestao_atividades`):
	 * todos os acampamentos e eventos, separados pela fase em que estão, com
	 * busca e filtro na própria tela. Cada ação só aparece para quem tem a
	 * permissão dela — sem botão desabilitado nem cadeado.
	 */
	import { onDestroy, onMount } from 'svelte';
	import '$lib/styles/formularios.css';
	import Janela from '$lib/components/painel/Janela.svelte';
	import logoAsas from '$lib/imgs/logo-asas.png';
	import {
		getActivities,
		getResumoDoPainel,
		deleteActivity,
		type ResumoDaAtividade
	} from '$lib/services/activityService';
	import type { Activity } from '$lib/types/models';
	import { pode } from '$lib/permissoes';
	import { faseDaAtividade, type Fase } from '$lib/faseAtividade';
	import { doAcampamento, doEvento, enderecoDasRespostas } from '$lib/formularios';

	type Grupo = Exclude<Fase, 'encerrado'> | 'realizada';
	type Filtro = 'todos' | 'acamp' | 'evento';

	/** Ordem de urgência para a equipe. */
	const GRUPOS: { chave: Grupo; nome: string; dica?: string }[] = [
		{ chave: 'acontecendo', nome: 'Acontecendo agora' },
		{ chave: 'aguardandoSorteio', nome: 'Aguardando sorteio', dica: 'O sorteio é feito à mão em “Inscritos e sorteio”.' },
		{ chave: 'abertas', nome: 'Inscrições abertas' },
		{ chave: 'inscricoesEncerradas', nome: 'Inscrições encerradas' },
		{ chave: 'emBreve', nome: 'Em breve' },
		{ chave: 'realizada', nome: 'Realizadas', dica: 'Já terminaram e não aparecem mais no site.' }
	];

	let atividades: Activity[] = $state([]);
	let resumo: Record<string, ResumoDaAtividade> | null = $state(null);
	let carregando = $state(true);
	let erro = $state(false);

	let busca = $state('');
	let filtro: Filtro = $state('todos');
	let realizadasAbertas = $state(false);

	const agora = new Date();

	async function carregar() {
		carregando = true;
		erro = false;
		try {
			const [lista, numeros] = await Promise.all([getActivities(), getResumoDoPainel().catch(() => null)]);
			if (lista.error) {
				erro = true;
			} else {
				atividades = lista.activities;
				resumo = numeros;
			}
		} catch {
			erro = true;
		} finally {
			carregando = false;
		}
	}

	onMount(carregar);

	// ─── Busca, filtro e grupos ───────────────────────────────────────────

	/** Sem maiúsculas, acentos e espaços a mais. */
	const normalizar = (s: string) =>
		s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

	const ehAcampamento = (a: Activity) => a.activitable_type === 'Campings';

	function grupoDa(a: Activity): Grupo {
		const fase = faseDaAtividade(a.datas, agora);
		return fase === 'encerrado' ? 'realizada' : fase;
	}

	const termo = $derived(normalizar(busca));
	const casaBusca = (a: Activity) => !termo || normalizar(a.name).includes(termo);
	const casaFiltro = (a: Activity, f: Filtro) => f === 'todos' || (f === 'acamp') === ehAcampamento(a);

	const filtros = $derived(
		([
			['todos', 'Todos'],
			['acamp', 'Acampamentos'],
			['evento', 'Eventos']
		] as [Filtro, string][]).map(([chave, nome]) => ({
			chave,
			nome,
			n: atividades.filter((a) => casaBusca(a) && casaFiltro(a, chave)).length
		}))
	);

	const inicio = (a: Activity) => a.datas.inicio?.getTime();

	const grupos = $derived.by(() => {
		const visiveis = atividades.filter((a) => casaBusca(a) && casaFiltro(a, filtro));
		return GRUPOS.map((g) => {
			const itens = visiveis.filter((a) => grupoDa(a) === g.chave);
			itens.sort((a, b) => {
				const ia = inicio(a);
				const ib = inicio(b);
				// Realizadas: da mais recente para a mais antiga.
				if (g.chave === 'realizada') return (ib ?? 0) - (ia ?? 0);
				// Sem data vai para o fim, por nome.
				if (ia !== ib) return (ia ?? Infinity) - (ib ?? Infinity);
				return a.name.localeCompare(b.name, 'pt-BR');
			});
			return { ...g, itens };
		}).filter((g) => g.itens.length > 0);
	});

	/** Com busca, "Realizadas" abre sozinho para não esconder resultados. */
	const realizadasVisiveis = $derived(realizadasAbertas || !!termo);

	function limpar() {
		busca = '';
		filtro = 'todos';
	}

	const textoSemResultado = $derived.by(() => {
		if (!termo) return 'Não há atividades deste tipo.';
		const onde = filtro === 'acamp' ? ' nos acampamentos' : filtro === 'evento' ? ' nos eventos' : '';
		return `Nenhuma atividade com “${busca.trim()}”${onde}.`;
	});

	// ─── Linha ────────────────────────────────────────────────────────────

	const p2 = (n: number) => String(n).padStart(2, '0');
	const dia = (d: Date) => `${p2(d.getDate())}/${p2(d.getMonth() + 1)}`;

	/** "29/11/2026", "05 a 07/12/2026", "30/12 a 02/01/2027" ou "A definir". */
	function quando(a: Activity): string | null {
		const ini = a.datas.inicio;
		if (!ini) return null;
		const fim = a.datas.fim ?? ini;
		if (dia(ini) === dia(fim) && ini.getFullYear() === fim.getFullYear()) return `${dia(ini)}/${ini.getFullYear()}`;
		if (ini.getMonth() === fim.getMonth() && ini.getFullYear() === fim.getFullYear()) {
			return `${p2(ini.getDate())} a ${dia(fim)}/${fim.getFullYear()}`;
		}
		return `${dia(ini)} a ${dia(fim)}/${fim.getFullYear()}`;
	}

	const plural = (n: number, um: string, varios: string) => `${n} ${n === 1 ? um : varios}`;

	/** Respostas: no acampamento, o formulário de campista da categoria; no evento, só se tiver formulário. */
	function enderecoRespostas(a: Activity): string | null {
		const alvo = ehAcampamento(a)
			? a.category?.id
				? doAcampamento(a.category.id, 'Campista')
				: null
			: a.formularioId
				? doEvento(a.formularioId)
				: null;
		return alvo ? enderecoDasRespostas(alvo, a.id) : null;
	}

	function enderecoEditar(a: Activity): string | null {
		if (ehAcampamento(a)) return pode('acampamentos') ? `/admin/acampamentos/editar/${a.id}` : null;
		return pode('eventos') ? `/admin/eventos/editar/${a.id}` : null;
	}

	// ─── Excluir ──────────────────────────────────────────────────────────

	let excluindo: Activity | null = $state(null);
	let digitado = $state('');
	let entendo = $state(false);
	let apagando = $state(false);
	let erroExcluir = $state('');

	const inscritosDe = (a: Activity) => resumo?.[String(a.id)]?.inscritos ?? 0;
	const temInscricoes = $derived.by(() => {
		if (!excluindo) return false;
		// Sem os números (o resumo não carregou), pede o nome por garantia.
		return !resumo || inscritosDe(excluindo) > 0;
	});
	const nomeConfere = $derived.by(() => {
		if (!excluindo) return false;
		return normalizar(digitado) === normalizar(excluindo.name);
	});
	const liberado = $derived(temInscricoes ? nomeConfere : entendo);

	function abrirExcluir(a: Activity) {
		excluindo = a;
		digitado = '';
		entendo = false;
		erroExcluir = '';
	}

	function fecharExcluir() {
		if (!apagando) excluindo = null;
	}

	async function confirmarExcluir() {
		const alvo = excluindo;
		if (!alvo || !liberado || apagando) return;
		apagando = true;
		erroExcluir = '';
		const res = await deleteActivity(alvo.id, temInscricoes ? digitado : '');
		apagando = false;
		if (!res.success) {
			erroExcluir = 'Não foi possível excluir. Nada foi apagado: tente de novo em alguns instantes.';
			return;
		}
		excluindo = null;
		atividades = atividades.filter((a) => a.id !== alvo.id);
		mostrarAviso(`“${alvo.name}” foi ${ehAcampamento(alvo) ? 'excluído' : 'excluída'}.`);
	}

	let aviso = $state('');
	let avisoTimer: ReturnType<typeof setTimeout>;
	function mostrarAviso(texto: string) {
		aviso = texto;
		clearTimeout(avisoTimer);
		avisoTimer = setTimeout(() => (aviso = ''), 6000);
	}
	onDestroy(() => clearTimeout(avisoTimer));
</script>

<svelte:head>
	<title>Gestão de Atividades | Painel da Comunidade</title>
</svelte:head>

<div class="fm ga">
	<div class="cabeca">
		<div class="cabeca-textos">
			<h1 class="fm-h1">Gestão de Atividades</h1>
			<p class="fm-sub">Todos os acampamentos e eventos, separados pela fase em que estão.</p>
		</div>
		{#if pode('acampamentos') || pode('eventos')}
			<div class="criar">
				{#if pode('acampamentos')}
					<a href="/admin/acampamentos/criar" class="fm-btn fm-btn--ouro fm-btn--grande">+ Criar acampamento</a>
				{/if}
				{#if pode('eventos')}
					<a href="/admin/eventos/criar" class="fm-btn fm-btn--contorno fm-btn--grande">+ Criar evento</a>
				{/if}
			</div>
		{/if}
	</div>

	{#if carregando}
		<section class="fm-cartao carregando" aria-busy="true">
			<div class="carregando-topo"><span class="fm-spinner"></span>Carregando lista de atividades...</div>
			{#each [1, 2, 3, 4, 5] as _}
				<div class="osso-linha">
					<span class="osso"></span>
					<span class="osso-pilha"><span class="osso" style="width:70%;height:22px"></span><span class="osso claro" style="width:40%;height:16px"></span></span>
					<span class="osso claro"></span><span class="osso claro"></span><span class="osso claro"></span>
				</div>
			{/each}
		</section>
	{:else if erro}
		<section class="fm-cartao centro" role="alert">
			<span class="icone-erro" aria-hidden="true">!</span>
			<p class="centro-titulo">Não foi possível carregar as atividades.</p>
			<p class="centro-texto">Verifique sua conexão com a internet e tente novamente.</p>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Tentar novamente</button>
		</section>
	{:else if atividades.length === 0}
		<section class="fm-cartao centro">
			<img src={logoAsas} alt="" class="brasao" />
			<p class="centro-titulo">Nenhuma atividade encontrada.</p>
			<p class="centro-texto">Os acampamentos e eventos aparecem aqui assim que forem criados.</p>
		</section>
	{:else}
		<div class="barra">
			<div class="busca">
				<label for="busca" class="sr">Buscar pelo nome</label>
				<svg class="lupa" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
				<input id="busca" type="search" class="fm-campo" placeholder="Buscar pelo nome" bind:value={busca} autocomplete="off" />
			</div>
			<div class="filtro" role="radiogroup" aria-label="Tipo de atividade">
				{#each filtros as f (f.chave)}
					<button
						type="button"
						role="radio"
						aria-checked={filtro === f.chave}
						class:sel={filtro === f.chave}
						onclick={() => (filtro = f.chave)}
					>
						{f.nome} <span class="filtro-n">{f.n}</span>
					</button>
				{/each}
			</div>
		</div>

		{#if grupos.length === 0}
			<section class="fm-cartao centro centro--baixo">
				<p class="centro-titulo pequeno">Nenhuma atividade com esse filtro.</p>
				<p class="centro-texto">{textoSemResultado}</p>
				<button type="button" class="fm-link" onclick={limpar}>Limpar a busca e o filtro</button>
			</section>
		{/if}

		{#each grupos as g (g.chave)}
			{@const realizada = g.chave === 'realizada'}
			<section class="grupo" aria-labelledby="g-{g.chave}">
				<div class="grupo-cabeca">
					<h2 id="g-{g.chave}" class="grupo-titulo">
						<span class="selo selo--{g.chave}" aria-hidden="true">
							{#if g.chave === 'acontecendo' || g.chave === 'abertas'}<span class="ponto"></span>{/if}{g.itens.length}
						</span>
						{g.nome}
						<span class="sr">({plural(g.itens.length, 'atividade', 'atividades')})</span>
					</h2>
					{#if g.dica}<span class="grupo-dica">{g.dica}</span>{/if}
					{#if realizada}
						<button
							type="button"
							class="fm-btn mostrar"
							aria-expanded={realizadasVisiveis}
							onclick={() => (realizadasAbertas = !realizadasVisiveis)}
						>
							{realizadasVisiveis ? 'Esconder realizadas' : `Mostrar realizadas (${g.itens.length})`}
						</button>
					{/if}
				</div>

				{#if !realizada || realizadasVisiveis}
					<div class="fm-cartao tabela">
						<div class="colunas" aria-hidden="true">
							<span>Nº</span><span>Atividade</span><span>Quando</span><span>Local</span><span>Inscrições</span>
						</div>
						<ol class="linhas">
							{#each g.itens as a, i (a.id)}
								{@const acamp = ehAcampamento(a)}
								{@const data = quando(a)}
								{@const numeros = resumo?.[String(a.id)]}
								{@const pag = realizada ? null : numeros?.pagamento}
								{@const respostas = pode('respostas_formularios') ? enderecoRespostas(a) : null}
								{@const editar = enderecoEditar(a)}
								<li class="linha" class:linha--realizada={realizada} aria-label={a.name}>
									<div class="dados">
										<span class="ordem">{i + 1}</span>
										<span class="atividade">
											<span class="nome">{a.name}</span>
											<span class="selos">
												<span class="tipo" class:tipo--evento={!acamp}>{acamp ? 'Acampamento' : 'Evento'}</span>
												{#if acamp && a.category?.name}<span class="categoria">{a.category.name}</span>{/if}
											</span>
										</span>
										<span class="texto" class:vazio={!data}>{data ?? 'A definir'}</span>
										<span class="texto" class:vazio={!a.place}>{a.place || 'A definir'}</span>
										<span class="inscricoes">
											{#if numeros}
												<span class="total">{numeros.inscritos ? plural(numeros.inscritos, 'inscrito', 'inscritos') : 'Nenhum inscrito ainda'}</span>
												{#if pag}
													{#if pag.noPrazo > 0}
														<span class="pill pill--ambar">{plural(pag.noPrazo, 'não pago', 'não pagos')} no prazo</span>
													{/if}
													{#if pag.vencido > 0}
														<span class="pill pill--verm">{pag.vencido} com prazo vencido</span>
													{/if}
													{#if pag.noPrazo === 0 && pag.vencido === 0}
														<span class="em-dia">Pagamentos em dia</span>
													{/if}
												{/if}
											{/if}
										</span>
									</div>

									<div class="acoes">
										{#if pode('inscricoes')}
											<a href="/admin/inscricoes/{a.id}" class="acao">
												<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M19 8v6M22 11h-6" /></svg>
												Inscritos e sorteio
											</a>
										{/if}
										{#if respostas}
											<a href={respostas} class="acao">
												<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6M8 13h8M8 17h5" /></svg>
												Respostas
											</a>
										{/if}
										{#if acamp && pode('equipes')}
											<a href="/dashboard/equipes/{a.id}" class="acao">
												<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
												Equipe de servos
											</a>
										{/if}
										<span class="espaco"></span>
										<a href="/dashboard/atividades/{a.id}" target="_blank" rel="noopener" class="acao2">
											<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3" /></svg>
											Ver página<span class="sr"> (abre em outra aba)</span>
										</a>
										{#if editar}
											<a href={editar} class="acao2">
												<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
												Editar
											</a>
										{/if}
										{#if pode('excluir_atividades')}
											<button type="button" class="acao2 perigo" onclick={() => abrirExcluir(a)}>
												<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6" /></svg>
												Excluir
											</button>
										{/if}
									</div>
								</li>
							{/each}
						</ol>
					</div>
				{/if}
			</section>
		{/each}
	{/if}
</div>

{#if excluindo}
	{@const alvo = excluindo}
	<Janela idTitulo="t-excluir" largura={620} faixa="#9B2C2C" travada={apagando} onfechar={fecharExcluir}>
		<h2 id="t-excluir" class="fm-janela-titulo">Excluir “{alvo.name}”?</h2>
		<div class="exc-textos">
			<p class="exc-texto">
				A atividade some do site e do painel, junto com tudo o que está ligado a ela. <strong>Não dá para desfazer.</strong>
			</p>
			{#if temInscricoes && resumo}
				<p class="aviso-verm">
					<span>
						Ela já tem <strong>{plural(inscritosDe(alvo), 'inscrição', 'inscrições')}</strong>. Inscrições, respostas dos
						formulários e pagamentos registrados também somem junto com ela.
					</span>
				</p>
			{/if}
		</div>

		{#if temInscricoes}
			<div class="exc-campo">
				<label for="conf-nome" class="exc-rotulo">Para confirmar, digite o nome da atividade</label>
				<span class="exc-nome">{alvo.name}</span>
				<input
					id="conf-nome"
					class="fm-campo"
					bind:value={digitado}
					autocomplete="off"
					aria-describedby="conf-ajuda"
					disabled={apagando}
				/>
				<p id="conf-ajuda" class="exc-ajuda">
					{#if !digitado}
						O botão “Excluir definitivamente” libera quando o nome estiver igual.
					{:else if nomeConfere}
						Nome conferido.
					{:else}
						O nome ainda não está igual.
					{/if}
				</p>
			</div>
		{:else}
			<label class="exc-check">
				<input type="checkbox" bind:checked={entendo} disabled={apagando} />
				Entendo que não dá para desfazer
			</label>
		{/if}

		{#if erroExcluir}
			<p class="aviso-verm" role="alert"><span>{erroExcluir}</span></p>
		{/if}

		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={fecharExcluir} disabled={apagando}>
				Cancelar
			</button>
			<button
				type="button"
				class="fm-btn fm-btn--perigo-cheio fm-btn--grande"
				class:travado={!liberado}
				disabled={!liberado || apagando}
				aria-busy={apagando}
				onclick={confirmarExcluir}
			>
				{apagando ? 'Excluindo...' : erroExcluir ? 'Tentar de novo' : 'Excluir definitivamente'}
			</button>
		</div>
	</Janela>
{/if}

{#if aviso}
	<div class="aviso-ok" role="status">
		<span aria-hidden="true">✓</span><span>{aviso}</span>
		<button type="button" aria-label="Fechar aviso" onclick={() => (aviso = '')}>✕</button>
	</div>
{/if}

<style>
	.sr {
		position: absolute;
		width: 1px;
		height: 1px;
		padding: 0;
		margin: -1px;
		overflow: hidden;
		clip: rect(0, 0, 0, 0);
		white-space: nowrap;
		border: 0;
	}

	/* ─── Cabeçalho ─── */
	.cabeca {
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		gap: 20px;
		flex-wrap: wrap;
	}
	.cabeca-textos {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.criar {
		display: flex;
		gap: 10px;
	}
	.criar .fm-btn--ouro {
		padding: 0 26px;
	}

	/* ─── Estados ─── */
	.carregando {
		overflow: hidden;
	}
	.carregando-topo {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 18px 24px;
		font-size: 17px;
		color: var(--tinta-3);
		border-bottom: 1px solid var(--linha);
	}
	.osso-linha {
		display: grid;
		grid-template-columns: 36px minmax(0, 2fr) 1fr 1fr 1fr;
		gap: 20px;
		align-items: center;
		padding: 20px 24px;
		border-bottom: 1px solid var(--linha);
	}
	.osso {
		display: block;
		height: 18px;
		border-radius: 4px;
		background: #ede5d1;
	}
	.osso.claro {
		background: #f3eddf;
	}
	.osso-pilha {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.centro {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 14px;
		padding: 72px 24px;
	}
	.centro--baixo {
		gap: 10px;
		padding: 48px 24px;
	}
	.centro-titulo {
		margin: 0;
		font: 600 30px 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.centro-titulo.pequeno {
		font-size: 26px;
	}
	.centro-texto {
		margin: 0;
		max-width: 520px;
		font-size: 17px;
		color: var(--tinta-3);
	}
	.icone-erro {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 56px;
		height: 56px;
		border-radius: 50%;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
		color: var(--vermelho);
		font-size: 28px;
		font-weight: 700;
	}
	.brasao {
		width: 120px;
		opacity: 0.55;
		filter: sepia(1) saturate(0.6);
	}

	/* ─── Busca e filtro ─── */
	.barra {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
	}
	.busca {
		position: relative;
		flex: 0 1 420px;
	}
	.busca .fm-campo {
		padding-left: 44px;
	}
	.lupa {
		position: absolute;
		left: 15px;
		top: 50%;
		transform: translateY(-50%);
		width: 18px;
		height: 18px;
		fill: none;
		stroke: var(--tinta-3);
		stroke-width: 2;
		stroke-linecap: round;
	}
	.filtro {
		display: flex;
		gap: 4px;
		padding: 4px;
		background: var(--linha);
		border-radius: 999px;
	}
	.filtro button {
		height: 40px;
		padding: 0 16px;
		font: 600 15px 'Source Sans 3', sans-serif;
		color: var(--tinta-3);
		background: transparent;
		border: none;
		border-radius: 999px;
		cursor: pointer;
	}
	.filtro button.sel {
		font-weight: 700;
		color: var(--azul-noite);
		background: var(--pergaminho);
		box-shadow: 0 2px 6px -2px rgba(15, 29, 61, 0.35);
	}
	.filtro-n {
		font-weight: 700;
		color: #7c7f8e;
	}
	.filtro button.sel .filtro-n {
		color: var(--ouro-texto);
	}

	/* ─── Grupos ─── */
	.grupo {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.grupo-cabeca {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
	}
	.grupo-titulo {
		margin: 0;
		display: flex;
		align-items: center;
		gap: 10px;
		font: 600 28px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.grupo-dica {
		font-size: 15px;
		color: var(--tinta-3);
	}
	.mostrar {
		margin-left: auto;
	}
	.selo {
		display: flex;
		align-items: center;
		gap: 6px;
		padding: 5px 10px;
		font: 700 12px 'Source Sans 3', sans-serif;
		letter-spacing: 0.04em;
		border: 1px solid;
		border-radius: 999px;
	}
	.ponto {
		width: 7px;
		height: 7px;
		border-radius: 50%;
	}
	.selo--acontecendo {
		color: #ffffff;
		background: #1f6b3f;
		border-color: #a8d5b6;
	}
	.selo--acontecendo .ponto {
		background: #ffffff;
		animation: vivo 1.8s ease-out infinite;
	}
	.selo--aguardandoSorteio {
		color: #21376a;
		background: #e6ebf5;
		border-color: #afbedd;
	}
	.selo--abertas {
		color: #0f1d3d;
		background: #e2ce8a;
		border-color: #cdb878;
	}
	.selo--abertas .ponto {
		background: #1f6b3f;
		box-shadow: 0 0 0 3px rgba(31, 107, 63, 0.2);
	}
	.selo--inscricoesEncerradas {
		color: #3d4152;
		background: #f1ece0;
		border-color: #cdbe92;
	}
	.selo--emBreve {
		color: #1b2f5e;
		background: #fffdf8;
		border-color: #c9cfde;
	}
	.selo--realizada {
		color: #4f5468;
		background: #efebe1;
		border-color: #e3d8bc;
	}
	@keyframes vivo {
		0% {
			box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.75);
		}
		70% {
			box-shadow: 0 0 0 7px rgba(255, 255, 255, 0);
		}
		100% {
			box-shadow: 0 0 0 0 rgba(255, 255, 255, 0);
		}
	}
	@media (prefers-reduced-motion: reduce) {
		.selo--acontecendo .ponto {
			animation: none;
		}
	}

	/* ─── Tabela ─── */
	.tabela {
		overflow: hidden;
	}
	.colunas,
	.dados {
		display: grid;
		grid-template-columns: 40px minmax(0, 2.2fr) minmax(0, 1.1fr) minmax(0, 1.3fr) minmax(0, 1.5fr);
		gap: 20px;
	}
	.colunas {
		padding: 12px 24px;
		background: var(--cabecalho-tabela);
		border-bottom: 1px solid var(--borda-suave);
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}
	.linhas {
		margin: 0;
		padding: 0;
		list-style: none;
	}
	.linha {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 16px 24px 14px;
		border-bottom: 1px solid var(--linha);
		background: var(--pergaminho);
	}
	.linha:last-child {
		border-bottom: none;
	}
	.linha--realizada {
		background: #fcfaf4;
	}
	.dados {
		align-items: start;
	}
	.ordem {
		font: 600 22px/1.2 'Cormorant Garamond', serif;
		color: var(--ouro-texto);
		font-variant-numeric: tabular-nums;
	}
	.atividade {
		display: flex;
		flex-direction: column;
		gap: 6px;
		min-width: 0;
	}
	.nome {
		font-size: 18px;
		font-weight: 700;
		line-height: 1.25;
		color: var(--tinta);
	}
	.selos {
		display: flex;
		gap: 6px;
		flex-wrap: wrap;
	}
	.tipo {
		padding: 3px 9px;
		font: 700 11px 'Source Sans 3', sans-serif;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--pergaminho);
		background: var(--azul);
		border: 1px solid var(--ouro);
		border-radius: 999px;
	}
	.tipo--evento {
		background: var(--vermelho);
		border-color: #d9a9a0;
	}
	.categoria {
		padding: 2px 9px;
		font-size: 13px;
		font-weight: 600;
		color: #6b5822;
		background: #f1e8cf;
		border-radius: 999px;
	}
	.texto {
		font-size: 16px;
		line-height: 1.35;
		color: var(--tinta-2);
	}
	.texto.vazio {
		color: #7c7f8e;
	}
	.inscricoes {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 5px;
	}
	.total {
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
	}
	.pill {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		padding: 2px 9px;
		font-size: 14px;
		font-weight: 600;
		border: 1px solid;
		border-radius: 8px;
		white-space: nowrap;
	}
	.pill::before {
		content: '';
		flex: 0 0 8px;
		height: 8px;
		border-radius: 50%;
	}
	.pill--ambar {
		color: #6a480c;
		background: #fbf0d6;
		border-color: #ebd39c;
	}
	.pill--ambar::before {
		background: #c08a1e;
	}
	.pill--verm {
		color: #842424;
		background: #f6e2df;
		border-color: #e6bdb6;
	}
	.pill--verm::before {
		background: #b33a3a;
	}
	.em-dia {
		font-size: 14px;
		color: #1b5236;
	}

	/* ─── Ações ─── */
	.acoes {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		padding-left: 60px;
	}
	.espaco {
		flex: 1;
	}
	.acao,
	.acao2 {
		display: inline-flex;
		align-items: center;
		height: 40px;
		font: 600 15px 'Source Sans 3', sans-serif;
		color: var(--azul);
		text-decoration: none;
		white-space: nowrap;
		cursor: pointer;
	}
	.acao {
		gap: 8px;
		padding: 0 14px;
		background: var(--pergaminho);
		border: 1.5px solid var(--azul);
		border-radius: 999px;
	}
	.acao:hover {
		background: #eef1f7;
	}
	.acao2 {
		gap: 6px;
		padding: 0 10px;
		background: transparent;
		border: none;
		border-radius: 8px;
	}
	.acao2:hover {
		background: #f1e8cf;
	}
	.acao2.perigo {
		color: var(--vermelho);
	}
	.acao2.perigo:hover {
		color: #842424;
		background: #f6e2df;
	}
	.acao svg,
	.acao2 svg {
		width: 18px;
		height: 18px;
		flex: 0 0 18px;
		fill: none;
		stroke: currentColor;
		stroke-width: 2;
		stroke-linecap: round;
		stroke-linejoin: round;
	}
	.ga .acao:focus-visible,
	.ga .acao2:focus-visible {
		outline: none;
		box-shadow: 0 0 0 3px #fffdf8, 0 0 0 5px #1b2f5e;
	}

	/* ─── Janela de excluir ─── */
	.exc-textos {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.exc-texto {
		margin: 0;
		font-size: 17px;
		line-height: 1.5;
		color: var(--tinta-2);
	}
	.aviso-verm {
		display: flex;
		gap: 10px;
		align-items: flex-start;
		margin: 0;
		padding: 12px 14px;
		font-size: 16px;
		line-height: 1.45;
		color: #842424;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
		border-radius: 10px;
	}
	.aviso-verm::before {
		content: '';
		flex: 0 0 8px;
		height: 8px;
		margin-top: 8px;
		border-radius: 50%;
		background: var(--vermelho);
	}
	.exc-campo {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.exc-rotulo {
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
	}
	.exc-nome {
		margin-top: -4px;
		font-size: 16px;
		color: var(--tinta-3);
	}
	.exc-ajuda {
		margin: 0;
		font-size: 15px;
		line-height: 1.4;
		color: var(--tinta-3);
	}
	.exc-check {
		display: flex;
		align-items: center;
		gap: 12px;
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta);
		cursor: pointer;
	}
	.exc-check input {
		width: 22px;
		height: 22px;
		accent-color: #9b2c2c;
	}
	.travado {
		opacity: 0.5 !important;
	}

	/* ─── Aviso de sucesso ─── */
	.aviso-ok {
		position: fixed;
		top: 84px;
		right: 32px;
		z-index: 60;
		display: flex;
		align-items: center;
		gap: 12px;
		max-width: 520px;
		padding: 14px 18px;
		font: 600 17px 'Source Sans 3', sans-serif;
		color: #fffdf8;
		background: #1b5236;
		border-radius: 12px;
		box-shadow: 0 18px 40px -16px rgba(15, 29, 61, 0.6);
	}
	.aviso-ok button {
		flex: 0 0 30px;
		height: 30px;
		margin-left: 6px;
		font-size: 16px;
		color: #fffdf8;
		background: rgba(255, 253, 248, 0.15);
		border: none;
		border-radius: 50%;
		cursor: pointer;
	}
</style>
