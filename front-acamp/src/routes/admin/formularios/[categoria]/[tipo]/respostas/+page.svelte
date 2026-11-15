<script lang="ts">
	/**
	 * Aba Respostas de um formulário de acampamento: as respostas de uma edição
	 * (filtro "Acampamento"), em cinco visões — Resumo, Por pergunta, Por
	 * pessoa, Tabela e Pendentes — mais planilha (.csv) e impressão. As
	 * respostas têm dados de saúde; o servidor só entrega para quem tem a
	 * permissão "Respostas dos formulários".
	 */
	import { onDestroy, onMount } from 'svelte';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import '$lib/styles/formularios.css';
	import CabecalhoFormulario from '$lib/components/painel/CabecalhoFormulario.svelte';
	import { caminhoDoPainel } from '$lib/stores/caminho.svelte';
	import { getResumoFormularios, TIPOS_PERGUNTA, type ResumoFormulario } from '$lib/services/perguntaService';
	import {
		abrirDocumento,
		conferirDocumento,
		getRespostas,
		type PessoaComRespostas,
		type RespostasDoFormulario
	} from '$lib/services/respostaService';
	import {
		diaEMes,
		enderecoDasRespostas,
		nomeCurto,
		normalizar,
		tituloDoFormulario,
		type TipoInscricao
	} from '$lib/formularios';
	import {
		TIPOS_DE_ESCOLHA,
		baixarArquivo,
		diaEHora,
		diasAte,
		ehAlertaDeSaude,
		intervaloDeDias,
		marcou,
		montarCsv,
		nomeDeArquivo,
		opcoesMarcadas,
		ordenarComFilhas,
		textoDaResposta,
		valePara,
		type PerguntaOrdenada
	} from '$lib/respostas';

	type Visao = 'resumo' | 'pergunta' | 'pessoa' | 'tabela' | 'pendentes';
	type ColunaExtra = { chave: string; nome: string; valor: (p: PessoaComRespostas) => string };

	const categoryId = Number($page.params.categoria);
	const tipo: TipoInscricao | null =
		$page.params.tipo === 'campista' ? 'Campista' : $page.params.tipo === 'servo' ? 'Servo' : null;
	const POR_PAGINA = 15;

	// ─── Dados ───────────────────────────────────────────────────────────
	let estado: 'carregando' | 'erro' | 'pronto' = $state('carregando');
	let trocando = $state(false);
	let resumo: ResumoFormulario | undefined = $state();
	let dados: RespostasDoFormulario | null = $state(null);

	let titulo = $derived(resumo && tipo ? tituloDoFormulario(resumo.categoria, tipo) : 'Formulário');
	let perguntas = $derived.by(() => {
		if (!dados) return [];
		return ordenarComFilhas(dados.perguntas);
	});
	let secoes = $derived.by(() => {
		const lista: { id: number; titulo: string }[] = [];
		for (const q of perguntas) if (!lista.some((s) => s.id === q.section.id)) lista.push(q.section);
		return lista;
	});
	let enviaram: PessoaComRespostas[] = $derived.by(() => {
		if (!dados) return [];
		return dados.pessoas.filter((p) => p.enviou);
	});
	let pendentes: PessoaComRespostas[] = $derived.by(() => {
		if (!dados) return [];
		return dados.pessoas.filter((p) => !p.enviou);
	});
	let edicao = $derived.by(() => {
		if (!dados) return undefined;
		const id = dados.atividade_id;
		return dados.edicoes.find((e) => e.id === id);
	});

	// ─── Estado da tela ──────────────────────────────────────────────────
	let visao: Visao = $state('resumo');
	let secaoResumo: number | null = $state(null);
	let perguntaId: number | null = $state(null);
	let filtro: number | 'todos' = $state('todos');
	let extras: string[] = $state([]);
	let pessoaIdx = $state(0);
	let secoesTabela: number[] = $state([]);
	let buscaTabela = $state('');
	let pagina = $state(0);
	let aviso = $state('');
	let avisoTimer: ReturnType<typeof setTimeout> | undefined;

	const nomeDoTipo = (t: string) => TIPOS_PERGUNTA.find((x) => x.value === t)?.label ?? t;
	const filhasDe = (q: PerguntaOrdenada) => perguntas.filter((c) => c.pai?.id === q.id);
	const ehEscolha = (q: PerguntaOrdenada) => TIPOS_DE_ESCOLHA.includes(q.type);

	function avisar(texto: string) {
		aviso = texto;
		clearTimeout(avisoTimer);
		avisoTimer = setTimeout(() => (aviso = ''), 5000);
	}

	// ─── Carregar ────────────────────────────────────────────────────────
	async function carregar() {
		if (!tipo || !Number.isInteger(categoryId)) {
			goto('/admin/formularios', { replaceState: true });
			return;
		}
		estado = 'carregando';
		try {
			const pedida = Number($page.url.searchParams.get('atividade')) || null;
			const [rResumo, rRespostas] = await Promise.all([getResumoFormularios(), getRespostas(categoryId, tipo, pedida)]);
			resumo = rResumo.data?.find((f) => f.category_id === categoryId && f.subscription_type === tipo);
			if (!resumo || !rRespostas.data) {
				estado = 'erro';
				return;
			}
			aplicar(rRespostas.data);
			caminhoDoPainel.subtela = titulo;
			estado = 'pronto';
		} catch {
			estado = 'erro';
		}
	}

	/** Troca a edição sem recarregar a tela inteira. */
	async function trocarEdicao(atividadeId: number) {
		if (!tipo) return;
		trocando = true;
		try {
			const res = await getRespostas(categoryId, tipo, atividadeId);
			if (!res.data) {
				avisar('Não foi possível carregar as respostas deste acampamento.');
				return;
			}
			aplicar(res.data);
			goto(`?atividade=${atividadeId}`, { replaceState: true, noScroll: true, keepFocus: true });
		} catch {
			avisar('Não foi possível carregar as respostas. Verifique sua conexão.');
		} finally {
			trocando = false;
		}
	}

	function aplicar(novos: RespostasDoFormulario) {
		dados = novos;
		pessoaIdx = 0;
		pagina = 0;
		const lista = ordenarComFilhas(novos.perguntas);
		const idsSecoes = [...new Set(lista.map((q) => q.section.id))];
		// Começa pela seção de saúde (a que tem alergia, remédio…), se houver.
		const saude = lista.find(ehAlertaDeSaude);
		const secaoInicial = saude?.section.id ?? idsSecoes[0] ?? null;
		if (secaoResumo === null || !idsSecoes.includes(secaoResumo)) secaoResumo = secaoInicial;
		secoesTabela = secoesTabela.filter((s) => idsSecoes.includes(s));
		if (secoesTabela.length === 0 && secaoInicial !== null) secoesTabela = [secaoInicial];
		if (perguntaId === null || !lista.some((q) => q.id === perguntaId)) {
			const primeira = saude ?? lista[0];
			if (primeira) escolherPergunta(primeira, lista);
		}
	}

	onMount(carregar);
	onDestroy(() => {
		caminhoDoPainel.subtela = null;
		clearTimeout(avisoTimer);
	});

	// ─── Edição (cabeçalho do filtro) ───────────────────────────────────
	function rotuloDaEdicao(e: { nome: string; inicio: string; fim: string; aberta: boolean }): string {
		return `${nomeCurto(e.nome)} · ${intervaloDeDias(e.inicio, e.fim)}${e.aberta ? ' (aberto)' : ''}`;
	}

	let situacaoDoPrazo = $derived.by(() => {
		if (!dados?.prazo) return 'Sem prazo cadastrado';
		const dias = diasAte(dados.prazo);
		const dia = diaEMes(dados.prazo);
		if (dias < 0) return `Prazo encerrado em ${dia}`;
		if (dias === 0) return `Prazo ${dia} · termina hoje`;
		return `Prazo ${dia} · ${dias === 1 ? 'falta 1 dia' : `faltam ${dias} dias`}`;
	});

	let outroTipo: TipoInscricao = $derived(tipo === 'Servo' ? 'Campista' : 'Servo');

	// ─── Resumo ──────────────────────────────────────────────────────────
	type Barra = { texto: string; n: number; largura: number; pct: string };
	type Grupo = { texto: string; n: number };

	function barras(q: PerguntaOrdenada, base: PessoaComRespostas[]): Barra[] {
		const contagens = q.options.map((o) => ({ texto: o.text, n: base.filter((p) => marcou(p, q.id, o.id)).length }));
		const maior = Math.max(1, ...contagens.map((c) => c.n));
		return contagens.map((c) => ({
			...c,
			largura: c.n ? Math.max(2, (c.n / maior) * 100) : 0,
			pct: base.length ? `${Math.round((c.n * 100) / base.length)}%` : '0%'
		}));
	}

	/** Respostas da condicional agrupadas e contadas ("Dipirona 4 · Amendoim 3…"). */
	function agrupar(c: PerguntaOrdenada): Grupo[] {
		const contagem = new Map<string, Grupo>();
		for (const p of enviaram) {
			if (!valePara(c, p)) continue;
			const valores = ehEscolha(c)
				? c.options.filter((o) => opcoesMarcadas(p.respostas[String(c.id)]).includes(o.id)).map((o) => o.text)
				: [textoDaResposta(c, p).trim()].filter(Boolean);
			for (const v of valores) {
				const chave = normalizar(v);
				const g = contagem.get(chave) ?? { texto: v, n: 0 };
				g.n++;
				contagem.set(chave, g);
			}
		}
		return [...contagem.values()].sort((a, b) => b.n - a.n || a.texto.localeCompare(b.texto, 'pt-BR'));
	}

	let cartoesDoResumo = $derived(perguntas.filter((q) => q.section.id === secaoResumo && !q.filha));

	// ─── Por pergunta ────────────────────────────────────────────────────
	function escolherPergunta(q: PerguntaOrdenada, lista: PerguntaOrdenada[] = perguntas) {
		perguntaId = q.id;
		// Sim/Não já começa em "Sim": é quase sempre o que se quer ver (quem tem alergia).
		filtro = q.type === 'sim_nao' ? (q.options.find((o) => o.text === 'Sim')?.id ?? 'todos') : 'todos';
		extras = extras.filter((e) => !e.startsWith('q:') || lista.some((x) => `q:${x.id}` === e && x.id !== q.id));
	}

	function verNomes(q: PerguntaOrdenada) {
		escolherPergunta(q);
		visao = 'pergunta';
	}

	let atual = $derived(perguntas.find((q) => q.id === perguntaId) ?? perguntas[0]);
	let filhasDaAtual = $derived(atual ? filhasDe(atual) : []);
	let baseDaAtual = $derived(atual ? enviaram.filter((p) => valePara(atual, p)) : []);
	let linhasDaAtual = $derived(
		atual && ehEscolha(atual) && filtro !== 'todos' ? baseDaAtual.filter((p) => marcou(p, atual.id, filtro as number)) : baseDaAtual
	);
	/** Com "Sim" escolhido numa pergunta que tem condicionais, a coluna "Resposta" seria só "Sim": some. */
	let mostraResposta = $derived(!(atual?.type === 'sim_nao' && filtro !== 'todos' && filhasDaAtual.length > 0));

	/** Colunas extras: dados do cadastro e perguntas úteis para a lista (telefone, tipo sanguíneo…). */
	let extrasPossiveis: ColunaExtra[] = $derived.by(() => {
		if (!atual) return [];
		const doCadastro: ColunaExtra[] = [
			{ chave: 'cadastro:telefone', nome: 'Telefone', valor: (p) => p.telefone ?? '' },
			{ chave: 'cadastro:idade', nome: 'Idade', valor: (p) => (p.idade === null ? '' : `${p.idade} anos`) }
		];
		const sugeridas = perguntas
			.filter(
				(x) =>
					x.id !== atual.id &&
					!filhasDaAtual.includes(x) &&
					/(telefone|contato|emergencia|responsavel|sangu|camiseta)/.test(normalizar(x.text))
			)
			.slice(0, 5)
			.map((x) => ({ chave: `q:${x.id}`, nome: x.text, valor: (p: PessoaComRespostas) => (valePara(x, p) ? textoDaResposta(x, p) : '') }));
		return [...doCadastro, ...sugeridas];
	});
	let extrasEscolhidos = $derived(extrasPossiveis.filter((e) => extras.includes(e.chave)));

	function alternarExtra(chave: string) {
		extras = extras.includes(chave) ? extras.filter((e) => e !== chave) : [...extras, chave];
	}

	let colunasDaAtual = $derived([
		'Nome',
		...(mostraResposta && atual ? [ehEscolha(atual) ? 'Marcou' : 'Resposta'] : []),
		...filhasDaAtual.map((f) => f.text),
		...extrasEscolhidos.map((e) => e.nome)
	]);

	function celulasDaAtual(p: PessoaComRespostas): string[] {
		if (!atual) return [];
		return [
			...(mostraResposta ? [textoDaResposta(atual, p)] : []),
			...filhasDaAtual.map((f) => (valePara(f, p) ? textoDaResposta(f, p) : '')),
			...extrasEscolhidos.map((e) => e.valor(p))
		];
	}

	function rodapeDaAtual(): string {
		if (!atual) return '';
		if (ehEscolha(atual) && filtro !== 'todos') {
			const opcao = atual.options.find((o) => o.id === filtro)?.text ?? '';
			return `de ${baseDaAtual.length} marcaram ${opcao}`;
		}
		if (atual.pai) return `pessoas marcaram ${atual.opcaoDoPai} em “${atual.pai.text}”`;
		return linhasDaAtual.length === 1 ? 'resposta' : 'respostas';
	}

	function abrirPessoa(p: PessoaComRespostas) {
		pessoaIdx = Math.max(0, enviaram.indexOf(p));
		visao = 'pessoa';
	}

	// ─── Por pessoa ──────────────────────────────────────────────────────
	let pessoa = $derived(enviaram[Math.min(pessoaIdx, Math.max(0, enviaram.length - 1))]);

	// ─── Documento (só com a permissão "documentos") ──────────────────────
	let conferindo = $state(false);

	async function verDocumento(id: number) {
		const res = await abrirDocumento(id);
		if (res.error) avisar(res.error);
	}

	async function marcarConferido(p: PessoaComRespostas, conferido: boolean) {
		if (!p.documento) return;
		conferindo = true;
		try {
			const res = await conferirDocumento(p.documento.id, conferido);
			if (res.data) {
				p.documento = res.data;
				avisar(conferido ? 'Documento marcado como conferido.' : 'Documento desmarcado.');
			} else {
				avisar(res.error || 'Não foi possível marcar o documento.');
			}
		} catch {
			avisar('Não foi possível marcar o documento. Verifique sua conexão.');
		} finally {
			conferindo = false;
		}
	}

	// ─── Tabela ──────────────────────────────────────────────────────────
	let colunasDaTabela = $derived(perguntas.filter((q) => secoesTabela.includes(q.section.id)));
	let linhasDaTabela = $derived.by(() => {
		const termo = normalizar(buscaTabela.trim());
		return termo ? enviaram.filter((p) => normalizar(p.nome).includes(termo)) : enviaram;
	});
	let paginas = $derived(Math.max(1, Math.ceil(linhasDaTabela.length / POR_PAGINA)));
	let paginaAtual = $derived(Math.min(pagina, paginas - 1));
	let fatia = $derived(linhasDaTabela.slice(paginaAtual * POR_PAGINA, (paginaAtual + 1) * POR_PAGINA));

	function alternarSecaoDaTabela(id: number) {
		const marcadas = secoesTabela.includes(id) ? secoesTabela.filter((s) => s !== id) : [...secoesTabela, id];
		// Mantém a ordem do formulário.
		secoesTabela = secoes.map((s) => s.id).filter((s) => marcadas.includes(s));
		pagina = 0;
	}

	// ─── Planilhas e cópia ───────────────────────────────────────────────
	function baixarTudo() {
		if (!dados || !edicao) return;
		const colunas = ['Nome', 'Situação', 'Idade', 'Telefone', 'E-mail', 'Enviado em', 'Atualizado em', ...perguntas.map((q) => q.text)];
		const linhas = enviaram.map((p) => [
			p.nome,
			p.situacao,
			p.idade === null ? '' : String(p.idade),
			p.telefone ?? '',
			p.email ?? '',
			p.enviado_em ? diaEHora(p.enviado_em) : '',
			p.atualizado_em ? diaEHora(p.atualizado_em) : '',
			...perguntas.map((q) => (valePara(q, p) ? textoDaResposta(q, p) : ''))
		]);
		baixarArquivo(nomeDeArquivo('respostas', titulo, edicao.nome), montarCsv(colunas, linhas));
	}

	function baixarPergunta() {
		if (!atual || !edicao) return;
		const linhas = linhasDaAtual.map((p) => [p.nome, ...celulasDaAtual(p)]);
		baixarArquivo(nomeDeArquivo('respostas', edicao.nome, atual.text.slice(0, 40)), montarCsv(colunasDaAtual, linhas));
	}

	function baixarPendentes() {
		if (!edicao) return;
		const linhas = pendentes.map((p) => [p.nome, p.situacao, p.telefone ?? '', p.email ?? '', p.confirmado_em ? diaEMes(p.confirmado_em) : '']);
		baixarArquivo(
			nomeDeArquivo('pendentes', titulo, edicao.nome),
			montarCsv(['Nome', 'Situação', 'Telefone', 'E-mail', 'Confirmado em'], linhas)
		);
	}

	async function copiarTelefones() {
		const texto = pendentes.map((p) => `${p.nome}: ${p.telefone ?? 'sem telefone'}`).join('\n');
		try {
			await navigator.clipboard.writeText(texto);
			avisar(`${pendentes.length} ${pendentes.length === 1 ? 'telefone copiado' : 'telefones copiados'}.`);
		} catch {
			avisar('Não foi possível copiar. Use "Baixar lista".');
		}
	}

	const VISOES: [Visao, string][] = [
		['resumo', 'Resumo'],
		['pergunta', 'Por pergunta'],
		['pessoa', 'Por pessoa'],
		['tabela', 'Tabela'],
		['pendentes', 'Pendentes']
	];
</script>

<svelte:head>
	<title>Respostas · {titulo} | Formulários | Painel da Comunidade</title>
</svelte:head>

{#snippet seloSituacao(situacao: string, enviado: boolean)}
	<span class="selo-situacao" class:selo-situacao--suplente={situacao === 'Suplente' && !enviado}>
		<span class="selo-ponto"></span>{enviado ? `Formulário Enviado · era ${situacao}` : situacao}
	</span>
{/snippet}

{#snippet vazio(texto: string)}
	<section class="fm-cartao fm-centro">
		<p class="fm-sub">{texto}</p>
	</section>
{/snippet}

<div class="fm">
	{#if estado === 'carregando'}
		<section class="fm-cartao fm-centro" role="status">
			<span class="fm-spinner"></span>
			<p class="fm-sub">Carregando as respostas...</p>
		</section>
	{:else if estado === 'erro' || !tipo || !resumo || !dados}
		<section class="fm-cartao fm-centro" role="alert">
			<span class="fm-erro-icone">!</span>
			<p class="fm-centro-titulo">Não foi possível carregar as respostas.</p>
			<p class="fm-sub">Verifique sua conexão com a internet e tente novamente.</p>
			<div class="acoes-linha">
				<a class="fm-btn fm-btn--contorno fm-btn--grande" href="/admin/formularios">Voltar para a lista</a>
				<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Tentar novamente</button>
			</div>
		</section>
	{:else}
		<div class="nao-imprimir">
			<CabecalhoFormulario
				{categoryId}
				categoria={resumo.categoria}
				{tipo}
				{titulo}
				aba="respostas"
				perguntas={resumo.perguntas}
				respostas={resumo.respostas}
			/>
		</div>

		{#if dados.edicoes.length === 0}
			{@render vazio(`Ainda não há nenhum acampamento ${resumo.categoria} cadastrado, então não há respostas para mostrar.`)}
		{:else}
			<!-- ─── Filtro da edição ─── -->
			<section class="fm-cartao filtro">
				<div class="filtro-linha">
					<label class="campo-edicao">
						<span class="fm-rotulo">Acampamento</span>
						<select
							class="fm-campo"
							value={dados.atividade_id}
							disabled={trocando}
							onchange={(e) => trocarEdicao(Number(e.currentTarget.value))}
						>
							{#each dados.edicoes as e (e.id)}
								<option value={e.id}>{rotuloDaEdicao(e)}</option>
							{/each}
						</select>
					</label>
					<div class="filtro-numeros">
						<span><strong>{enviaram.length}</strong> de {dados.pessoas.length} enviaram</span>
						<span class="filtro-prazo">{situacaoDoPrazo}</span>
					</div>
					{#if trocando}<span class="fm-spinner fm-spinner--pequeno" role="status" aria-label="Carregando"></span>{/if}
					<div class="filtro-acoes nao-imprimir">
						<button type="button" class="fm-btn" onclick={() => window.print()}>Imprimir</button>
						<button type="button" class="fm-btn fm-btn--ouro" disabled={enviaram.length === 0} onclick={baixarTudo}>
							Baixar planilha (.csv)
						</button>
					</div>
				</div>
				<p class="aviso-saude">
					Estas respostas têm dados de saúde. Use só para cuidar dos participantes e não repasse para fora da equipe.
				</p>
				<a
					class="fm-link outro-tipo nao-imprimir"
					href={enderecoDasRespostas(categoryId, outroTipo, dados.atividade_id ?? undefined)}
					data-sveltekit-reload
				>
					Ver as respostas dos {outroTipo === 'Servo' ? 'servos' : 'campistas'} →
				</a>
			</section>

			<!-- ─── Visões ─── -->
			<div class="visoes nao-imprimir" role="tablist" aria-label="Como ver as respostas">
				{#each VISOES as [chave, nome] (chave)}
					<button
						type="button"
						role="tab"
						class="chip"
						class:chip--on={visao === chave}
						aria-selected={visao === chave}
						onclick={() => (visao = chave)}
					>
						{nome}{#if chave === 'pendentes'}<span class="chip-n">{pendentes.length}</span>{/if}
					</button>
				{/each}
			</div>

			{#if perguntas.length === 0 && visao !== 'pendentes'}
				{@render vazio('Este formulário não tem perguntas. Monte as perguntas na aba Perguntas.')}
			{:else if enviaram.length === 0 && visao !== 'pendentes'}
				{@render vazio(
					dados.pessoas.length
						? `Ninguém enviou o formulário neste acampamento ainda. ${dados.pessoas.length} ${dados.pessoas.length === 1 ? 'pessoa precisa' : 'pessoas precisam'} enviar: veja em Pendentes.`
						: 'Ninguém foi sorteado, indicado ou chamado como suplente neste acampamento ainda.'
				)}
			{:else if visao === 'resumo'}
				<!-- ─── Resumo ─── -->
				<div class="chips nao-imprimir">
					<span class="fm-rotulo">Seção</span>
					{#each secoes as s (s.id)}
						<button
							type="button"
							class="chip chip--pequeno"
							class:chip--on={secaoResumo === s.id}
							aria-pressed={secaoResumo === s.id}
							onclick={() => (secaoResumo = s.id)}
						>
							{s.titulo}
						</button>
					{/each}
				</div>
				<div class="cartoes">
					{#each cartoesDoResumo as q (q.id)}
						<article class="fm-cartao cartao-resumo">
							<div class="cartao-topo">
								<div>
									<h3 class="cartao-pergunta">{q.text}</h3>
									<span class="cartao-sub">
										{nomeDoTipo(q.type)}{q.type === 'multipla_escolha' ? ' · dá para marcar mais de uma' : ''}
									</span>
								</div>
								<button type="button" class="fm-link nao-imprimir" onclick={() => verNomes(q)}>Ver nomes</button>
							</div>
							{#if ehEscolha(q)}
								<div class="barras">
									{#each barras(q, enviaram) as b}
										<div class="barra-linha">
											<span class="barra-rotulo">{b.texto}</span>
											<span class="barra-trilho"><span class="barra" style="width:{b.largura}%"></span></span>
											<span class="barra-n"><strong>{b.n}</strong> · {b.pct}</span>
										</div>
									{/each}
								</div>
							{:else}
								{@const responderam = enviaram.filter((p) => textoDaResposta(q, p) !== '').length}
								<span class="cartao-texto">
									{responderam} de {enviaram.length} responderam{q.is_required ? '' : ' (opcional)'}.
								</span>
							{/if}
							{#each filhasDe(q) as c (c.id)}
								{@const grupos = agrupar(c)}
								<div class="filha-resumo">
									<span class="filha-titulo">↳ {c.text} <span>· de quem marcou {c.opcaoDoPai}</span></span>
									<div class="grupos">
										{#each grupos.slice(0, 12) as g}
											<span class="grupo">{g.texto} <strong>{g.n}</strong></span>
										{:else}
											<span class="cartao-sub">Ninguém respondeu.</span>
										{/each}
										{#if grupos.length > 12}
											<button type="button" class="fm-link" onclick={() => verNomes(q)}>e mais {grupos.length - 12}</button>
										{/if}
									</div>
								</div>
							{/each}
						</article>
					{/each}
				</div>
			{:else if visao === 'pergunta' && atual}
				<!-- ─── Por pergunta ─── -->
				<div class="por-pergunta">
					<nav class="lista-perguntas fm-cartao nao-imprimir" aria-label="Perguntas">
						{#each secoes as s (s.id)}
							<span class="fm-rotulo lista-secao">{s.titulo}</span>
							{#each perguntas.filter((q) => q.section.id === s.id) as q (q.id)}
								<button
									type="button"
									class="lista-item"
									class:lista-item--filha={q.filha}
									class:lista-item--on={q.id === atual.id}
									aria-current={q.id === atual.id ? 'true' : undefined}
									onclick={() => escolherPergunta(q)}
								>
									{q.filha ? '↳ ' : ''}{q.text}
								</button>
							{/each}
						{/each}
					</nav>
					<section class="fm-cartao detalhe">
						<div class="detalhe-topo">
							<div class="detalhe-textos">
								<span class="fm-rotulo">{atual.section.titulo} · {nomeDoTipo(atual.type)}</span>
								<h2 class="fm-h2">{atual.text}</h2>
								{#if atual.pai}
									<span class="cartao-sub">Só aparece para quem marcou {atual.opcaoDoPai} em “{atual.pai.text}”.</span>
								{/if}
							</div>
							<div class="acoes-linha nao-imprimir">
								<button type="button" class="fm-btn" onclick={() => window.print()}>Imprimir esta lista</button>
								<button type="button" class="fm-btn" onclick={baixarPergunta}>Baixar</button>
							</div>
						</div>
						{#if ehEscolha(atual)}
							<div class="chips nao-imprimir">
								<span class="fm-rotulo">Mostrar quem marcou</span>
								{#each [...atual.options.map((o) => ({ chave: o.id as number | 'todos', texto: o.text, n: baseDaAtual.filter((p) => marcou(p, atual.id, o.id)).length })), { chave: 'todos' as const, texto: 'Todos', n: baseDaAtual.length }] as f (f.chave)}
									<button
										type="button"
										class="chip chip--pequeno"
										class:chip--on={filtro === f.chave}
										aria-pressed={filtro === f.chave}
										onclick={() => (filtro = f.chave)}
									>
										{f.texto}<span class="chip-n">{f.n}</span>
									</button>
								{/each}
							</div>
						{/if}
						<div class="chips nao-imprimir">
							<span class="fm-rotulo">Colunas extras</span>
							{#each extrasPossiveis as e (e.chave)}
								<button
									type="button"
									class="chip chip--pequeno"
									class:chip--on={extras.includes(e.chave)}
									aria-pressed={extras.includes(e.chave)}
									onclick={() => alternarExtra(e.chave)}
								>
									{extras.includes(e.chave) ? '✓' : '+'} {e.nome}
								</button>
							{/each}
						</div>
						<div class="tabela-rolagem">
							<table class="tabela">
								<thead>
									<tr>
										{#each colunasDaAtual as c, i}
											<th scope="col" class:col-nome={i === 0}>{c}</th>
										{/each}
									</tr>
								</thead>
								<tbody>
									{#each linhasDaAtual as p (p.inscricao_id)}
										<tr>
											<th scope="row" class="col-nome">
												<button type="button" class="link-pessoa" onclick={() => abrirPessoa(p)}>{p.nome}</button>
											</th>
											{#each celulasDaAtual(p) as v}
												<td class:vazio-celula={!v}>{v || '—'}</td>
											{/each}
										</tr>
									{:else}
										<tr><td colspan={colunasDaAtual.length} class="vazio-celula">Ninguém nesta lista.</td></tr>
									{/each}
								</tbody>
							</table>
						</div>
						<div class="rodape-tabela"><strong>{linhasDaAtual.length}</strong> {rodapeDaAtual()}</div>
					</section>
				</div>
			{:else if visao === 'pessoa' && pessoa}
				<!-- ─── Por pessoa ─── -->
				<section class="fm-cartao ficha">
					<div class="ficha-barra nao-imprimir">
						<label class="campo-edicao">
							<span class="fm-rotulo">Pessoa</span>
							<select class="fm-campo" value={pessoaIdx} onchange={(e) => (pessoaIdx = Number(e.currentTarget.value))}>
								{#each enviaram as p, i (p.inscricao_id)}
									<option value={i}>{p.nome}</option>
								{/each}
							</select>
						</label>
						<div class="acoes-linha">
							<button type="button" class="fm-btn" disabled={pessoaIdx === 0} onclick={() => pessoaIdx--}>← Anterior</button>
							<span class="ficha-posicao"><strong>{pessoaIdx + 1}</strong> de {enviaram.length}</span>
							<button type="button" class="fm-btn" disabled={pessoaIdx >= enviaram.length - 1} onclick={() => pessoaIdx++}>
								Próxima →
							</button>
							<button type="button" class="fm-btn" onclick={() => window.print()}>Imprimir ficha</button>
						</div>
					</div>
					<div class="ficha-topo">
						<div>
							<h2 class="fm-h2">{pessoa.nome}</h2>
							<span class="cartao-sub">
								{[pessoa.idade !== null ? `${pessoa.idade} anos` : '', pessoa.telefone, pessoa.email].filter(Boolean).join(' · ')}
							</span>
						</div>
						<div class="ficha-situacao">
							{@render seloSituacao(pessoa.situacao, true)}
							{#if pessoa.enviado_em}
								<span class="cartao-sub">Enviado em {diaEHora(pessoa.enviado_em)}</span>
								{#if pessoa.atualizado_em && pessoa.atualizado_em.slice(0, 16) > pessoa.enviado_em.slice(0, 16)}
									<span class="cartao-sub">Respostas alteradas em {diaEHora(pessoa.atualizado_em)}</span>
								{/if}
							{:else if pessoa.atualizado_em}
								<!-- Envio de antes de o sistema guardar a data: a última alteração é o mais perto. -->
								<span class="cartao-sub">Atualizado em {diaEHora(pessoa.atualizado_em)}</span>
							{/if}
						</div>
					</div>
					{#if pessoa.documento !== undefined}
						<!-- Só vem para quem tem a permissão "Documentos de identidade". -->
						{@const doc = pessoa.documento}
						<div class="ficha-documento nao-imprimir" class:ficha-documento--ok={doc?.conferido_em}>
							<div class="ficha-documento-textos">
								<span class="fm-rotulo">Documento de identidade</span>
								{#if doc}
									<span>
										{doc.tipo === 'pdf' ? 'PDF' : 'Foto'} enviado{doc.enviado_em ? ` em ${diaEHora(doc.enviado_em)}` : ''}{doc.conferido_em
											? ` · conferido em ${diaEHora(doc.conferido_em)}`
											: ' · ainda não conferido'}
									</span>
								{:else}
									<span>Ainda não enviou. Quem enviou o formulário antes de o documento ser pedido fica sem ele.</span>
								{/if}
							</div>
							{#if doc}
								<div class="acoes-linha">
									<button type="button" class="fm-btn" onclick={() => verDocumento(doc.id)}>Ver documento</button>
									<button
										type="button"
										class="fm-btn"
										class:fm-btn--ouro={!doc.conferido_em}
										disabled={conferindo}
										onclick={() => marcarConferido(pessoa, !doc.conferido_em)}
									>
										{doc.conferido_em ? 'Desmarcar conferido' : 'Marcar como conferido'}
									</button>
								</div>
							{/if}
						</div>
					{/if}
					{#each secoes as s (s.id)}
						{@const daSecao = perguntas.filter((q) => q.section.id === s.id && valePara(q, pessoa))}
						{#if daSecao.length}
							<div class="ficha-secao">
								<h3 class="ficha-secao-titulo">{s.titulo}</h3>
								<dl class="ficha-lista">
									{#each daSecao as q (q.id)}
										{@const v = textoDaResposta(q, pessoa)}
										<div class="ficha-item" class:ficha-item--filha={q.pai}>
											<dt>{q.pai ? '↳ ' : ''}{q.text}</dt>
											<dd class:vazio-celula={!v} class:saude={ehAlertaDeSaude(q) && v === 'Sim'}>{v || 'Não respondeu'}</dd>
										</div>
									{/each}
								</dl>
							</div>
						{/if}
					{/each}
				</section>
			{:else if visao === 'tabela'}
				<!-- ─── Tabela ─── -->
				<div class="tabela-filtros nao-imprimir">
					<div class="chips">
						<span class="fm-rotulo">Colunas: escolha as seções</span>
						{#each secoes as s (s.id)}
							<button
								type="button"
								class="chip chip--pequeno"
								class:chip--on={secoesTabela.includes(s.id)}
								aria-pressed={secoesTabela.includes(s.id)}
								onclick={() => alternarSecaoDaTabela(s.id)}
							>
								{secoesTabela.includes(s.id) ? '✓' : '+'} {s.titulo}
							</button>
						{/each}
					</div>
					<label class="campo-edicao">
						<span class="fm-rotulo">Buscar pessoa</span>
						<input class="fm-campo" type="search" placeholder="Nome" bind:value={buscaTabela} oninput={() => (pagina = 0)} />
					</label>
				</div>
				<section class="fm-cartao tabela-cartao">
					<div class="tabela-rolagem">
						<table class="tabela">
							<thead>
								<tr>
									<th scope="col" class="col-nome">Nome</th>
									{#each colunasDaTabela as q (q.id)}
										<th scope="col" class:col-condicional={q.pai}>{q.pai ? '↳ ' : ''}{q.text}</th>
									{/each}
								</tr>
							</thead>
							<tbody>
								{#each fatia as p (p.inscricao_id)}
									<tr>
										<th scope="row" class="col-nome">
											<button type="button" class="link-pessoa" onclick={() => abrirPessoa(p)}>{p.nome}</button>
										</th>
										{#each colunasDaTabela as q (q.id)}
											{@const v = valePara(q, p) ? textoDaResposta(q, p) : ''}
											<td
												class:vazio-celula={!v}
												class:celula-condicional={q.pai}
												class:saude={ehAlertaDeSaude(q) && v === 'Sim'}
											>
												{v || '—'}
											</td>
										{/each}
									</tr>
								{:else}
									<tr><td colspan={colunasDaTabela.length + 1} class="vazio-celula">Ninguém com “{buscaTabela.trim()}”.</td></tr>
								{/each}
							</tbody>
						</table>
					</div>
					<div class="rodape-tabela rodape-paginas">
						<span>
							Mostrando <strong>{fatia.length ? `${paginaAtual * POR_PAGINA + 1}–${paginaAtual * POR_PAGINA + fatia.length}` : '0'}</strong>
							de <strong>{linhasDaTabela.length}</strong> respostas · {colunasDaTabela.length}
							{colunasDaTabela.length === 1 ? 'coluna' : 'colunas'}
						</span>
						<div class="acoes-linha nao-imprimir">
							<button type="button" class="fm-btn" disabled={paginaAtual === 0} onclick={() => (pagina = paginaAtual - 1)}>← Anterior</button>
							<button type="button" class="fm-btn" disabled={paginaAtual >= paginas - 1} onclick={() => (pagina = paginaAtual + 1)}>
								Próxima →
							</button>
						</div>
					</div>
				</section>
			{:else if visao === 'pendentes'}
				<!-- ─── Pendentes ─── -->
				{#if pendentes.length === 0}
					{@render vazio(
						dados.pessoas.length ? 'Todos já enviaram o formulário.' : 'Ninguém precisa enviar o formulário neste acampamento ainda.'
					)}
				{:else}
					<div class="fm-aviso fm-aviso--ambar">
						<span>
							<strong>{pendentes.length} {pendentes.length === 1 ? 'pessoa ainda não enviou' : 'pessoas ainda não enviaram'}.</strong>
							{#if dados.prazo && diasAte(dados.prazo) >= 0}
								{situacaoDoPrazo}. Quem não enviar até lá perde a vaga, e o próximo suplente pode ser chamado em Inscritos e
								Sorteios.
							{:else if dados.prazo}
								{situacaoDoPrazo}.
							{:else}
								Este acampamento ainda não tem prazo para o formulário.
							{/if}
						</span>
					</div>
					<section class="fm-cartao tabela-cartao">
						<div class="tabela-rolagem">
							<table class="tabela">
								<thead>
									<tr>
										<th scope="col" class="col-nome">Pessoa</th>
										<th scope="col">Situação</th>
										<th scope="col">Contato</th>
										<th scope="col">Confirmado em</th>
									</tr>
								</thead>
								<tbody>
									{#each pendentes as p (p.inscricao_id)}
										<tr>
											<th scope="row" class="col-nome">{p.nome}</th>
											<td>{@render seloSituacao(p.situacao, false)}</td>
											<td>
												<span class="contato">{p.telefone || '—'}</span>
												<span class="contato contato--email">{p.email || ''}</span>
											</td>
											<td>{p.confirmado_em ? diaEMes(p.confirmado_em) : '—'}</td>
										</tr>
									{/each}
								</tbody>
							</table>
						</div>
						<div class="rodape-tabela acoes-linha nao-imprimir">
							<button type="button" class="fm-btn" onclick={copiarTelefones}>Copiar telefones</button>
							<button type="button" class="fm-btn" onclick={baixarPendentes}>Baixar lista</button>
						</div>
					</section>
				{/if}
			{/if}
		{/if}
	{/if}
</div>

{#if aviso}
	<div class="fm-toast" role="status">
		<span>{aviso}</span>
		<button type="button" class="fm-toast-fechar" aria-label="Fechar aviso" onclick={() => (aviso = '')}>×</button>
	</div>
{/if}

<style>
	.acoes-linha {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
	}

	/* ─── Filtro ─── */
	.filtro {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 18px 22px;
	}

	.filtro-linha {
		display: flex;
		align-items: flex-end;
		gap: 22px;
		flex-wrap: wrap;
	}

	.campo-edicao {
		display: flex;
		flex-direction: column;
		gap: 6px;
		min-width: 320px;
	}

	.filtro-numeros {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding-bottom: 4px;
		font-size: 17px;
		color: var(--tinta-2);
	}

	.filtro-prazo {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.filtro-acoes {
		display: flex;
		gap: 10px;
		margin-left: auto;
	}

	.aviso-saude {
		margin: 0;
		padding: 10px 14px;
		font-size: 15px;
		line-height: 1.4;
		color: var(--destaque-saude-tx, #842424);
		background: #fbedea;
		border: 1px solid #f0cdc6;
		border-radius: 10px;
	}

	.outro-tipo {
		align-self: flex-start;
		font-size: 15px;
	}

	/* ─── Chips ─── */
	.visoes,
	.chips {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}

	.chips .fm-rotulo {
		margin-right: 4px;
	}

	.chip {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		height: 44px;
		padding: 0 18px;
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta-2);
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 999px;
		cursor: pointer;
	}

	.chip:hover:not(.chip--on) {
		border-color: var(--ouro);
		background: var(--cabecalho-tabela);
	}

	.chip--pequeno {
		height: 38px;
		padding: 0 14px;
		font-size: 15px;
	}

	.chip--on {
		color: var(--pergaminho);
		background: var(--azul);
		border-color: var(--azul);
	}

	.chip-n {
		font-size: 13px;
		font-weight: 700;
		color: var(--icone-tipo-tx);
		background: var(--icone-tipo-bg);
		border-radius: 999px;
		padding: 1px 8px;
	}

	.chip--on .chip-n {
		color: var(--azul);
		background: var(--ouro-claro);
	}

	/* ─── Resumo ─── */
	.cartoes {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
		gap: 16px;
		align-items: start;
	}

	.cartao-resumo {
		display: flex;
		flex-direction: column;
		gap: 14px;
		padding: 18px 20px;
	}

	.cartao-topo {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 12px;
	}

	.cartao-pergunta {
		margin: 0 0 2px;
		font-size: 17px;
		font-weight: 700;
		color: var(--tinta);
	}

	.cartao-sub {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.cartao-texto {
		font-size: 16px;
		color: var(--tinta-2);
	}

	.barras {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.barra-linha {
		display: grid;
		grid-template-columns: minmax(80px, 160px) 1fr 92px;
		align-items: center;
		gap: 12px;
		font-size: 15px;
	}

	.barra-rotulo {
		color: var(--tinta-2);
		overflow-wrap: anywhere;
	}

	.barra-trilho {
		height: 12px;
		background: var(--linha);
		border-radius: 999px;
		overflow: hidden;
	}

	.barra {
		display: block;
		height: 100%;
		background: var(--azul);
		border-radius: 999px;
	}

	.barra-n {
		text-align: right;
		color: var(--tinta-3);
	}

	.barra-n strong {
		color: var(--tinta);
	}

	.filha-resumo {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 12px 14px;
		background: var(--cabecalho-tabela);
		border: 1px solid var(--linha);
		border-radius: 10px;
	}

	.filha-titulo {
		font-size: 15px;
		font-weight: 700;
		color: var(--tinta);
	}

	.filha-titulo span {
		font-weight: 400;
		color: var(--tinta-3);
	}

	.grupos {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	.grupo {
		padding: 3px 10px;
		font-size: 14px;
		color: var(--tinta-2);
		background: #ffffff;
		border: 1px solid var(--borda-suave);
		border-radius: 999px;
	}

	/* ─── Por pergunta ─── */
	.por-pergunta {
		display: grid;
		grid-template-columns: 300px minmax(0, 1fr);
		gap: 20px;
		align-items: start;
	}

	.lista-perguntas {
		position: sticky;
		top: 20px;
		display: flex;
		flex-direction: column;
		gap: 2px;
		max-height: calc(100vh - 40px);
		overflow: auto;
		padding: 14px;
	}

	.lista-secao {
		padding: 12px 8px 4px;
	}

	.lista-item {
		padding: 8px 10px;
		font-size: 15px;
		font-weight: 500;
		line-height: 1.3;
		text-align: left;
		color: var(--tinta);
		background: transparent;
		border: none;
		border-radius: 8px;
		cursor: pointer;
	}

	.lista-item:hover:not(.lista-item--on) {
		background: var(--cabecalho-tabela);
	}

	.lista-item--filha {
		margin-left: 18px;
	}

	.lista-item--on {
		font-weight: 700;
		color: var(--pergaminho);
		background: var(--azul);
	}

	.detalhe {
		display: flex;
		flex-direction: column;
		gap: 16px;
		padding: 20px 22px;
		min-width: 0;
	}

	.detalhe-topo {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
	}

	.detalhe-textos {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	/* ─── Tabelas ─── */
	.tabela-cartao {
		padding: 0;
		overflow: hidden;
	}

	.tabela-rolagem {
		overflow-x: auto;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}

	.tabela-cartao .tabela-rolagem {
		border: none;
		border-radius: 0;
	}

	.tabela {
		width: 100%;
		border-collapse: separate;
		border-spacing: 0;
		font-size: 15px;
	}

	.tabela th,
	.tabela td {
		padding: 10px 14px;
		text-align: left;
		vertical-align: top;
		border-bottom: 1px solid var(--linha);
		min-width: 160px;
		max-width: 260px;
		overflow-wrap: anywhere;
	}

	.tabela thead th {
		font-size: 14px;
		font-weight: 700;
		color: var(--tinta-2);
		background: var(--cabecalho-tabela);
	}

	.tabela tbody tr:hover td,
	.tabela tbody tr:hover th {
		background: #fdfaf1;
	}

	.col-nome {
		position: sticky;
		left: 0;
		z-index: 1;
		min-width: 220px;
		background: #ffffff;
		border-right: 1px solid var(--linha);
	}

	.tabela thead .col-nome {
		z-index: 2;
		background: var(--cabecalho-tabela);
	}

	.col-condicional {
		background: var(--col-cond, #f6efdc) !important;
	}

	.celula-condicional {
		background: #fcfaf3;
	}

	.vazio-celula {
		color: #6e7180;
	}

	.saude {
		font-weight: 700;
		color: #842424;
		background: #fbedea !important;
	}

	.link-pessoa {
		padding: 0;
		font: inherit;
		font-weight: 600;
		text-align: left;
		color: var(--azul);
		background: none;
		border: none;
		text-decoration: underline;
		text-decoration-color: var(--borda);
		text-underline-offset: 3px;
		cursor: pointer;
	}

	.rodape-tabela {
		padding: 12px 16px;
		font-size: 15px;
		color: var(--tinta-3);
	}

	.rodape-paginas {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		flex-wrap: wrap;
	}

	.tabela-filtros {
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
	}

	.tabela-filtros .campo-edicao {
		min-width: 260px;
	}

	/* ─── Por pessoa ─── */
	.ficha {
		display: flex;
		flex-direction: column;
		gap: 20px;
		padding: 20px 24px 26px;
	}

	.ficha-barra {
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
		padding-bottom: 16px;
		border-bottom: 1px solid var(--linha);
	}

	.ficha-posicao {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.ficha-topo {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
	}

	.ficha-situacao {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 4px;
	}

	.ficha-documento {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 14px;
		flex-wrap: wrap;
		padding: 14px 16px;
		font-size: 15px;
		color: var(--tinta-2);
		background: #fbf0d6;
		border: 1px solid #ebd39c;
		border-radius: 10px;
	}

	.ficha-documento--ok {
		background: #e4f1e8;
		border-color: #b9d9c4;
	}

	.ficha-documento-textos {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.ficha-secao-titulo {
		margin: 0 0 10px;
		font: 600 24px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.ficha-lista {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 10px 28px;
		margin: 0;
	}

	.ficha-item {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding-bottom: 8px;
		border-bottom: 1px solid var(--linha);
	}

	.ficha-item--filha {
		padding-left: 18px;
	}

	.ficha-item dt {
		font-size: 14px;
		color: var(--tinta-3);
	}

	.ficha-item dd {
		margin: 0;
		font-size: 16px;
		font-weight: 500;
		color: var(--tinta);
	}

	.ficha-item dd.saude {
		align-self: flex-start;
		padding: 0 6px;
		border-radius: 4px;
	}

	/* ─── Selo de situação ─── */
	.selo-situacao {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 3px 10px;
		font-size: 14px;
		font-weight: 700;
		color: #1b5236;
		background: #e4f1e8;
		border: 1px solid #b9d9c4;
		border-radius: 999px;
		white-space: nowrap;
	}

	.selo-ponto {
		width: 8px;
		height: 8px;
		border-radius: 50%;
		background: #2e7d4f;
	}

	.selo-situacao--suplente {
		color: #6a480c;
		background: #fbf0d6;
		border-color: #ebd39c;
	}

	.selo-situacao--suplente .selo-ponto {
		background: #c08a1e;
	}

	.contato {
		display: block;
	}

	.contato--email {
		font-size: 14px;
		color: var(--tinta-3);
	}

	/* ─── Impressão: só a lista/ficha, sem o menu do painel ─── */
	@media print {
		:global(.admin-layout > .lateral),
		:global(.admin-main > .topo),
		.nao-imprimir {
			display: none !important;
		}

		:global(.fm) {
			margin: 0 !important;
			padding: 0 !important;
			background: #ffffff !important;
		}

		.por-pergunta {
			display: block;
		}

		.tabela-rolagem {
			overflow: visible;
		}

		.col-nome {
			position: static;
		}
	}
</style>
