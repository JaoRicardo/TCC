<script lang="ts">
	/**
	 * Aba Respostas de um formulário: as respostas de uma edição (o acampamento
	 * da categoria, ou o evento que usa o formulário), em cinco visões — Resumo,
	 * Por pergunta, Por pessoa, Tabela e Pendentes — mais planilha (.csv) e
	 * impressão. As respostas têm dados de saúde e arquivos (documento…); o
	 * servidor só entrega para quem tem a permissão "Respostas dos formulários".
	 *
	 * Daqui a organização também pede correção de um formulário (a vaga
	 * continua) e cancela uma inscrição (a vaga vai para "Chamar próximo"), e
	 * depois manda o aviso pelo WhatsApp.
	 */
	import { onDestroy, onMount } from 'svelte';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import '$lib/styles/formularios.css';
	import CabecalhoFormulario from '$lib/components/painel/CabecalhoFormulario.svelte';
	import Janela from '$lib/components/painel/Janela.svelte';
	import { caminhoDoPainel } from '$lib/stores/caminho.svelte';
	import { getResumoFormularios, TIPOS_PERGUNTA } from '$lib/services/perguntaService';
	import {
		abrirArquivo,
		cancelarInscricao,
		desfazerCorrecao,
		getRespostas,
		pedirCorrecao,
		type PessoaComRespostas,
		type RespostasDoFormulario
	} from '$lib/services/respostaService';
	import type { ArquivoEnviado } from '$lib/services/formularioService';
	import {
		diaEMes,
		doAcampamento,
		enderecoDasRespostas,
		nomeCurto,
		normalizar,
		tituloDoFormulario,
		type AlvoFormulario
	} from '$lib/formularios';
	import {
		TIPOS_DE_ESCOLHA,
		arquivoDa,
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
	/** Célula das listas: o texto e, numa pergunta de arquivo, o arquivo para abrir. */
	type Celula = { v: string; arquivo?: ArquivoEnviado | null };
	type FiltroPergunta = number | 'todos' | 'enviou' | 'nao';

	let { alvo }: { alvo: AlvoFormulario | null } = $props();
	const POR_PAGINA = 15;

	/** Cabeçalho e textos que mudam entre formulário de acampamento e de evento. */
	type Cabecalho = {
		evento: boolean;
		titulo: string;
		sobretitulo: string;
		sub: string;
		perguntas: number;
		respostas: number;
		/** Rótulo do filtro: "Acampamento" ou "Evento". */
		edicao: string;
	};

	// ─── Dados ───────────────────────────────────────────────────────────
	let estado: 'carregando' | 'erro' | 'pronto' = $state('carregando');
	let trocando = $state(false);
	let cabecalho: Cabecalho | null = $state(null);
	let dados: RespostasDoFormulario | null = $state(null);

	let titulo = $derived.by(() => {
		if (!cabecalho) return 'Formulário';
		return cabecalho.titulo;
	});
	let perguntas = $derived.by(() => {
		if (!dados) return [];
		return ordenarComFilhas(dados.perguntas);
	});
	let secoes = $derived.by(() => {
		const lista: { id: number; titulo: string }[] = [];
		for (const q of perguntas) if (!lista.some((s) => s.id === q.section.id)) lista.push(q.section);
		return lista;
	});
	/** Quem ainda vale (sem as canceladas e recusadas pela organização). */
	let ativas: PessoaComRespostas[] = $derived.by(() => {
		if (!dados) return [];
		return dados.pessoas.filter((p) => !p.encerrada);
	});
	let enviaram: PessoaComRespostas[] = $derived(ativas.filter((p) => p.enviou));
	/** Por pessoa: quem enviou, inclusive quem foi cancelado depois (para ver o motivo). */
	let fichas: PessoaComRespostas[] = $derived.by(() => {
		if (!dados) return [];
		return dados.pessoas.filter((p) => p.enviou);
	});
	/** Correções (atrasadas primeiro) e depois quem ainda não enviou. */
	let pendentes: PessoaComRespostas[] = $derived([
		...ativas.filter((p) => p.correcao?.atrasada),
		...ativas.filter((p) => p.correcao && !p.correcao.atrasada),
		...ativas.filter((p) => !p.enviou)
	]);
	let semEnviar = $derived(ativas.filter((p) => !p.enviou).length);
	let comCorrecao = $derived(ativas.filter((p) => p.correcao).length);
	let atrasadas = $derived(ativas.filter((p) => p.correcao?.atrasada).length);
	let edicao = $derived.by(() => {
		if (!dados) return undefined;
		const id = dados.atividade_id;
		return dados.edicoes.find((e) => e.id === id);
	});

	// ─── Estado da tela ──────────────────────────────────────────────────
	let visao: Visao = $state('resumo');
	let secaoResumo: number | null = $state(null);
	let perguntaId: number | null = $state(null);
	let filtro: FiltroPergunta = $state('todos');
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
	const ehArquivo = (q: PerguntaOrdenada) => q.type === 'arquivo';
	const primeiroNome = (nome: string) => nome.trim().split(/\s+/)[0] ?? nome;
	const nomeDaSituacao = (p: PessoaComRespostas) =>
		p.encerrada?.situacao ?? (p.correcao ? (p.correcao.atrasada ? 'Correção atrasada' : 'Correção pedida') : p.enviou ? 'Formulário Enviado' : p.situacao);

	function avisar(texto: string) {
		aviso = texto;
		clearTimeout(avisoTimer);
		avisoTimer = setTimeout(() => (aviso = ''), 5000);
	}

	// ─── Carregar ────────────────────────────────────────────────────────
	async function carregar() {
		if (!alvo) {
			goto('/admin/formularios', { replaceState: true });
			return;
		}
		estado = 'carregando';
		try {
			const pedida = Number($page.url.searchParams.get('atividade')) || null;
			const [rResumo, rRespostas] = await Promise.all([getResumoFormularios(), getRespostas(alvo, pedida)]);
			cabecalho = cabecalhoDo(alvo, rResumo);
			if (!cabecalho || !rRespostas.data) {
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

	function cabecalhoDo(a: AlvoFormulario, r: Awaited<ReturnType<typeof getResumoFormularios>>): Cabecalho | null {
		if (a.tipo === 'evento') {
			const id = a.eventFormId;
			const f = r.eventos?.formularios.find((x) => x.id === id);
			if (!f) return null;
			return {
				evento: true,
				titulo: f.nome,
				sobretitulo: 'Formulário de evento',
				sub: 'As respostas de cada evento que usa este formulário.',
				perguntas: f.perguntas,
				respostas: f.respostas,
				edicao: 'Evento'
			};
		}
		const { categoryId, inscricao } = a;
		const f = r.data?.find((x) => x.category_id === categoryId && x.subscription_type === inscricao);
		if (!f) return null;
		return {
			evento: false,
			titulo: tituloDoFormulario(f.categoria, inscricao),
			sobretitulo: 'Formulário de acampamento',
			sub: `Vale para todos os acampamentos ${f.categoria}, ano após ano.`,
			perguntas: f.perguntas,
			respostas: f.respostas,
			edicao: 'Acampamento'
		};
	}

	/** Troca a edição sem recarregar a tela inteira. */
	async function trocarEdicao(atividadeId: number) {
		if (!alvo) return;
		trocando = true;
		try {
			const res = await getRespostas(alvo, atividadeId);
			if (!res.data) {
				avisar(`Não foi possível carregar as respostas deste ${cabecalho?.evento ? 'evento' : 'acampamento'}.`);
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

	function aplicar(novos: RespostasDoFormulario, manterInscricao: number | null = null) {
		dados = novos;
		const lista = novos.pessoas.filter((p) => p.enviou);
		pessoaIdx = manterInscricao === null ? 0 : Math.max(0, lista.findIndex((p) => p.inscricao_id === manterInscricao));
		if (manterInscricao !== null) return;
		pagina = 0;
		const ordenadas = ordenarComFilhas(novos.perguntas);
		const idsSecoes = [...new Set(ordenadas.map((q) => q.section.id))];
		// Começa pela seção de saúde (a que tem alergia, remédio…), se houver.
		const saude = ordenadas.find(ehAlertaDeSaude);
		const secaoInicial = saude?.section.id ?? idsSecoes[0] ?? null;
		if (secaoResumo === null || !idsSecoes.includes(secaoResumo)) secaoResumo = secaoInicial;
		secoesTabela = secoesTabela.filter((s) => idsSecoes.includes(s));
		if (secoesTabela.length === 0 && secaoInicial !== null) secoesTabela = [secaoInicial];
		if (perguntaId === null || !ordenadas.some((q) => q.id === perguntaId)) {
			const primeira = saude ?? ordenadas[0];
			if (primeira) escolherPergunta(primeira, ordenadas);
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

	/** No acampamento, atalho para o outro formulário da mesma categoria (Campista ⇄ Servo). */
	let outroFormulario = $derived.by(() => {
		if (!alvo || alvo.tipo !== 'acampamento') return null;
		const outro = alvo.inscricao === 'Servo' ? 'Campista' : 'Servo';
		return { alvo: doAcampamento(alvo.categoryId, outro), quem: outro === 'Servo' ? 'servos' : 'campistas' };
	});
	const ondeFalta = () => (cabecalho?.evento ? 'neste evento' : 'neste acampamento');

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
		if (ehArquivo(c)) {
			const base = enviaram.filter((p) => valePara(c, p));
			const n = base.filter((p) => arquivoDa(c, p)).length;
			return [
				{ texto: 'Enviaram', n },
				{ texto: 'Não enviaram', n: base.length - n }
			];
		}
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
		// Arquivo começa em quem enviou.
		filtro =
			q.type === 'sim_nao' ? (q.options.find((o) => o.text === 'Sim')?.id ?? 'todos') : q.type === 'arquivo' ? 'enviou' : 'todos';
		extras = extras.filter((e) => !e.startsWith('q:') || lista.some((x) => `q:${x.id}` === e && x.id !== q.id));
	}

	function verNomes(q: PerguntaOrdenada) {
		escolherPergunta(q);
		visao = 'pergunta';
	}

	let atual = $derived(perguntas.find((q) => q.id === perguntaId) ?? perguntas[0]);
	let filhasDaAtual = $derived(atual ? filhasDe(atual) : []);
	let baseDaAtual = $derived(atual ? enviaram.filter((p) => valePara(atual, p)) : []);
	let linhasDaAtual = $derived.by(() => {
		if (!atual || filtro === 'todos') return baseDaAtual;
		if (ehArquivo(atual)) return baseDaAtual.filter((p) => !!arquivoDa(atual, p) === (filtro === 'enviou'));
		if (ehEscolha(atual)) return baseDaAtual.filter((p) => marcou(p, atual.id, filtro as number));
		return baseDaAtual;
	});
	/** Chips do filtro "Mostrar quem marcou": opções da escolha, ou Enviaram/Não enviaram no arquivo. */
	let filtrosDaAtual = $derived.by(() => {
		if (!atual) return [];
		if (ehArquivo(atual)) {
			const n = baseDaAtual.filter((p) => arquivoDa(atual, p)).length;
			return [
				{ chave: 'enviou' as FiltroPergunta, texto: 'Enviaram', n },
				{ chave: 'nao' as FiltroPergunta, texto: 'Não enviaram', n: baseDaAtual.length - n },
				{ chave: 'todos' as FiltroPergunta, texto: 'Todos', n: baseDaAtual.length }
			];
		}
		if (!ehEscolha(atual)) return [];
		return [
			...atual.options.map((o) => ({
				chave: o.id as FiltroPergunta,
				texto: o.text,
				n: baseDaAtual.filter((p) => marcou(p, atual.id, o.id)).length
			})),
			{ chave: 'todos' as FiltroPergunta, texto: 'Todos', n: baseDaAtual.length }
		];
	});
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
		...(mostraResposta && atual ? [ehEscolha(atual) ? 'Marcou' : ehArquivo(atual) ? 'Arquivo' : 'Resposta'] : []),
		...filhasDaAtual.map((f) => f.text),
		...extrasEscolhidos.map((e) => e.nome)
	]);

	/** Célula de uma pergunta: no arquivo, o nome dele (para abrir) ou "Não enviado". */
	function celula(q: PerguntaOrdenada, p: PessoaComRespostas): Celula {
		if (!valePara(q, p)) return { v: '' };
		if (ehArquivo(q)) {
			const a = arquivoDa(q, p);
			return a ? { v: a.nome, arquivo: a } : { v: 'Não enviado' };
		}
		return { v: textoDaResposta(q, p) };
	}

	function celulasDaAtual(p: PessoaComRespostas): Celula[] {
		if (!atual) return [];
		return [
			...(mostraResposta ? [celula(atual, p)] : []),
			...filhasDaAtual.map((f) => celula(f, p)),
			...extrasEscolhidos.map((e) => ({ v: e.valor(p) }))
		];
	}

	function rodapeDaAtual(): string {
		if (!atual) return '';
		if (ehArquivo(atual) && filtro !== 'todos') return `de ${baseDaAtual.length} ${filtro === 'enviou' ? 'enviaram' : 'não enviaram'}`;
		if (ehEscolha(atual) && filtro !== 'todos') {
			const opcao = atual.options.find((o) => o.id === filtro)?.text ?? '';
			return `de ${baseDaAtual.length} marcaram ${opcao}`;
		}
		if (atual.pai) return `pessoas marcaram ${atual.opcaoDoPai} em “${atual.pai.text}”`;
		return linhasDaAtual.length === 1 ? 'resposta' : 'respostas';
	}

	function abrirPessoa(p: PessoaComRespostas) {
		pessoaIdx = Math.max(0, fichas.findIndex((x) => x.inscricao_id === p.inscricao_id));
		visao = 'pessoa';
	}

	// ─── Por pessoa ──────────────────────────────────────────────────────
	let pessoa = $derived(fichas[Math.min(pessoaIdx, Math.max(0, fichas.length - 1))]);

	/** Abre o arquivo numa aba nova, por um link temporário (nunca um endereço público). */
	async function verArquivo(a: ArquivoEnviado) {
		const res = await abrirArquivo(a.id);
		if (res.error) avisar(res.error);
	}

	const extensao = (a: ArquivoEnviado) => (a.tipo === 'pdf' ? 'PDF' : (a.nome.split('.').pop() ?? 'JPG').toUpperCase().slice(0, 4));

	// ─── Pedir correção, cancelar e avisar pelo WhatsApp ─────────────────
	type Acao =
		| null
		| { tipo: 'correcao'; p: PessoaComRespostas }
		| { tipo: 'cancelar'; p: PessoaComRespostas }
		| { tipo: 'zap'; p: PessoaComRespostas; de: 'correcao' | 'cancelar'; motivo: string; ate: string | null };
	let acao: Acao = $state(null);
	let motivo = $state('');
	let marcadas: number[] = $state([]);
	let corrigirAte = $state('');
	let ocupado = $state(false);
	let erroAcao = $state('');
	let zapPara: 'pessoa' | 'responsavel' = $state('pessoa');
	let zapTexto: string | null = $state(null);
	let copiou = $state(false);

	const ddmmaaaa = (d: Date) =>
		`${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
	/** "18/11/2026" → "2026-11-18" (null se a data não existe). */
	function isoDe(texto: string): string | null {
		const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(texto.trim());
		if (!m) return null;
		const d = new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]));
		if (d.getDate() !== Number(m[1]) || d.getMonth() !== Number(m[2]) - 1) return null;
		return `${m[3]}-${m[2]}-${m[1]}`;
	}
	function mascaraData(valor: string): string {
		const n = valor.replace(/\D/g, '').slice(0, 8);
		return [n.slice(0, 2), n.slice(2, 4), n.slice(4)].filter(Boolean).join('/');
	}
	const hojeIso = () => {
		const d = new Date();
		return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
	};

	/** O prazo do formulário já passou? Então "Corrigir até" é obrigatório e vem com hoje + 3 dias. */
	let prazoDoFormularioVencido = $derived.by(() => {
		if (!dados?.prazo) return false;
		return diasAte(dados.prazo) < 0;
	});
	let ateIso = $derived(isoDe(corrigirAte));
	let ateInvalida = $derived(
		!ateIso || ateIso < hojeIso() || (!!edicao && ateIso > edicao.inicio)
	);

	function abrirCorrecao(p: PessoaComRespostas) {
		motivo = '';
		marcadas = [];
		erroAcao = '';
		let padrao = new Date();
		padrao.setDate(padrao.getDate() + 3);
		if (dados?.prazo && !prazoDoFormularioVencido) padrao = new Date(dados.prazo);
		// Nunca depois do início da atividade.
		if (edicao && padrao > new Date(`${edicao.inicio}T00:00`)) padrao = new Date(`${edicao.inicio}T00:00`);
		corrigirAte = ddmmaaaa(padrao);
		acao = { tipo: 'correcao', p };
	}

	function abrirCancelar(p: PessoaComRespostas) {
		motivo = '';
		erroAcao = '';
		acao = { tipo: 'cancelar', p };
	}

	function alternarMarcada(id: number) {
		marcadas = marcadas.includes(id) ? marcadas.filter((x) => x !== id) : [...marcadas, id];
	}

	/** Recarrega as respostas sem sair da pessoa aberta. */
	async function recarregar(manter: number) {
		if (!alvo || !dados?.atividade_id) return;
		const res = await getRespostas(alvo, dados.atividade_id);
		if (res.data) aplicar(res.data, manter);
	}

	async function confirmarCorrecao() {
		if (acao?.tipo !== 'correcao' || !motivo.trim() || ateInvalida || !ateIso) return;
		const p = acao.p;
		ocupado = true;
		erroAcao = '';
		try {
			const res = await pedirCorrecao(p.inscricao_id, { motivo: motivo.trim(), perguntas: marcadas, prazo: ateIso });
			if (res.error) {
				erroAcao = res.error;
				return;
			}
			await recarregar(p.inscricao_id);
			abrirZap(p, 'correcao', motivo.trim(), ateIso);
		} catch {
			erroAcao = 'Não foi possível pedir a correção. Verifique sua conexão e tente de novo.';
		} finally {
			ocupado = false;
		}
	}

	async function confirmarCancelamento() {
		if (acao?.tipo !== 'cancelar' || !motivo.trim()) return;
		const p = acao.p;
		ocupado = true;
		erroAcao = '';
		try {
			const res = await cancelarInscricao(p.inscricao_id, motivo.trim());
			if (res.error) {
				erroAcao = res.error;
				return;
			}
			await recarregar(p.inscricao_id);
			abrirZap(p, 'cancelar', motivo.trim(), null);
		} catch {
			erroAcao = 'Não foi possível cancelar. Verifique sua conexão e tente de novo.';
		} finally {
			ocupado = false;
		}
	}

	async function desfazer(p: PessoaComRespostas) {
		try {
			const res = await desfazerCorrecao(p.inscricao_id);
			if (res.error) {
				avisar(res.error);
				return;
			}
			await recarregar(p.inscricao_id);
			avisar('Pedido de correção desfeito. A situação volta a Formulário Enviado.');
		} catch {
			avisar('Não foi possível desfazer o pedido. Verifique sua conexão e tente de novo.');
		}
	}

	function abrirZap(p: PessoaComRespostas, de: 'correcao' | 'cancelar', texto: string, ate: string | null) {
		zapPara = 'pessoa';
		zapTexto = null;
		copiou = false;
		acao = { tipo: 'zap', p, de, motivo: texto, ate };
	}

	function fecharZap() {
		const de = acao?.tipo === 'zap' ? acao.de : null;
		acao = null;
		if (de === 'correcao') avisar('Correção pedida.');
		else if (de === 'cancelar')
			avisar(cabecalho?.evento ? 'Inscrição cancelada. A vaga voltou para as inscrições.' : 'Inscrição cancelada. A vaga foi para “Chamar próximo”.');
	}

	/** Responsável (nome e telefone) pelas respostas do formulário, se ele pergunta. */
	function responsavelDe(p: PessoaComRespostas): { nome: string; telefone: string } | null {
		const tel = perguntas.find((q) => /telefone.*respons|respons.*telefone|celular.*respons/.test(normalizar(q.text)));
		const telefone = tel ? (p.respostas[String(tel.id)] ?? '').trim() : '';
		if (!telefone) return null;
		const nomeQ = perguntas.find((q) => /nome.*respons/.test(normalizar(q.text)));
		return { nome: nomeQ ? (p.respostas[String(nomeQ.id)] ?? '').trim() : '', telefone };
	}

	function mensagemZap(a: Extract<Acao, { tipo: 'zap' }>, para: 'pessoa' | 'responsavel'): string {
		const atividade = edicao?.nome ?? 'acampamento';
		const quem = primeiroNome(a.p.nome);
		const resp = responsavelDe(a.p);
		const ola = para === 'responsavel' && resp ? `Olá${resp.nome ? `, ${primeiroNome(resp.nome)}` : ''}!` : `Olá, ${quem}!`;
		const linhas = [`${ola} Aqui é da Comunidade São Miguel Arcanjo.`, ''];
		if (a.de === 'correcao') {
			linhas.push(
				para === 'responsavel'
					? `Precisamos que seja corrigido o formulário de ${quem} do ${atividade}:`
					: `Precisamos que você corrija o seu formulário do ${atividade}:`,
				`“${a.motivo}”`,
				'',
				`É só entrar no site, em Meus Formulários, e corrigir até ${a.ate ? diaEMes(a.ate) : ''}. A vaga continua garantida.`
			);
		} else {
			linhas.push(
				para === 'responsavel'
					? `A inscrição de ${quem} no ${atividade} foi cancelada pela organização:`
					: `Sua inscrição no ${atividade} foi cancelada pela organização:`,
				`“${a.motivo}”`,
				'',
				'Se tiver alguma dúvida, é só responder esta mensagem.'
			);
		}
		return linhas.join('\n');
	}

	let textoDoZap = $derived.by(() => {
		const a = acao;
		if (a?.tipo !== 'zap') return '';
		return zapTexto ?? mensagemZap(a, zapPara);
	});
	let linkDoZap = $derived.by(() => {
		const a = acao;
		if (a?.tipo !== 'zap') return '';
		const tel = zapPara === 'responsavel' ? (responsavelDe(a.p)?.telefone ?? '') : (a.p.telefone ?? '');
		return `https://wa.me/55${tel.replace(/\D/g, '')}?text=${encodeURIComponent(textoDoZap)}`;
	});

	async function copiarMensagem() {
		try {
			await navigator.clipboard.writeText(textoDoZap);
			copiou = true;
		} catch {
			avisar('Não foi possível copiar. Selecione o texto e copie.');
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
			...perguntas.map((q) => (!valePara(q, p) ? '' : ehArquivo(q) ? (arquivoDa(q, p) ? 'Enviado' : 'Não enviado') : textoDaResposta(q, p)))
		]);
		baixarArquivo(nomeDeArquivo('respostas', titulo, edicao.nome), montarCsv(colunas, linhas));
	}

	function baixarPergunta() {
		if (!atual || !edicao) return;
		const linhas = linhasDaAtual.map((p) => [p.nome, ...celulasDaAtual(p).map((c) => (c.arquivo ? 'Enviado' : c.v))]);
		baixarArquivo(nomeDeArquivo('respostas', edicao.nome, atual.text.slice(0, 40)), montarCsv(colunasDaAtual, linhas));
	}

	function baixarPendentes() {
		if (!edicao) return;
		const linhas = pendentes.map((p) => [p.nome, situacaoDoPendente(p), p.telefone ?? '', p.email ?? '', desdeDoPendente(p)]);
		baixarArquivo(
			nomeDeArquivo('pendentes', titulo, edicao.nome),
			montarCsv(['Nome', 'Situação', 'Telefone', 'E-mail', 'Desde'], linhas)
		);
	}

	function situacaoDoPendente(p: PessoaComRespostas): string {
		if (p.correcao?.atrasada) return `Correção atrasada · era até ${p.correcao.prazo ? diaEMes(p.correcao.prazo) : ''}`;
		if (p.correcao) return `Correção pedida · até ${p.correcao.prazo ? diaEMes(p.correcao.prazo) : ''}`;
		return `${p.situacao} · não enviou`;
	}

	function desdeDoPendente(p: PessoaComRespostas): string {
		if (p.correcao?.pedida_em) return `Pedida em ${diaEMes(p.correcao.pedida_em)}`;
		return p.confirmado_em ? `Confirmado em ${diaEMes(p.confirmado_em)}` : '—';
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

{#snippet selo(texto: string, tom: 'ok' | 'ambar' | 'perigo')}
	<span class="selo-situacao" class:selo-situacao--suplente={tom === 'ambar'} class:selo-situacao--perigo={tom === 'perigo'}>
		<span class="selo-ponto"></span>{texto}
	</span>
{/snippet}

{#snippet seloDaPessoa(p: PessoaComRespostas)}
	{@const nome = nomeDaSituacao(p)}
	{@render selo(nome, p.encerrada || p.correcao?.atrasada ? 'perigo' : p.correcao ? 'ambar' : 'ok')}
{/snippet}

{#snippet caixaDoArquivo(a: ArquivoEnviado)}
	<span class="arq">
		<span class="arq-icone" aria-hidden="true">{extensao(a)}</span>
		<span class="arq-textos">
			<span class="arq-nome">{a.nome}</span>
			<span class="arq-meta">{a.tipo === 'pdf' ? 'PDF' : 'Foto'}{a.enviado_em ? ` · enviado em ${diaEHora(a.enviado_em)}` : ''}</span>
		</span>
		<button type="button" class="fm-btn nao-imprimir" onclick={() => verArquivo(a)}>Ver arquivo</button>
		<span class="so-imprimir">Arquivo enviado ({a.nome})</span>
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
	{:else if estado === 'erro' || !alvo || !cabecalho || !dados}
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
				{alvo}
				sobretitulo={cabecalho.sobretitulo}
				{titulo}
				sub={cabecalho.sub}
				aba="respostas"
				perguntas={cabecalho.perguntas}
				respostas={cabecalho.respostas}
			/>
		</div>

		{#if dados.edicoes.length === 0}
			{@render vazio(
				cabecalho.evento
					? 'Nenhum evento usa este formulário ainda. Escolha o formulário em Criar/Editar Evento.'
					: 'Ainda não há nenhum acampamento desta categoria cadastrado, então não há respostas para mostrar.'
			)}
		{:else}
			<!-- ─── Filtro da edição ─── -->
			<section class="fm-cartao filtro">
				<div class="filtro-linha">
					<label class="campo-edicao">
						<span class="fm-rotulo">{cabecalho.edicao}</span>
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
						<span><strong>{enviaram.length}</strong> de {ativas.length} enviaram</span>
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
				{#if outroFormulario}
					<a class="fm-link outro-tipo nao-imprimir" href={enderecoDasRespostas(outroFormulario.alvo, dados.atividade_id ?? undefined)}>
						Ver as respostas dos {outroFormulario.quem} →
					</a>
				{/if}
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
			{:else if fichas.length === 0 && visao !== 'pendentes'}
				{@render vazio(
					ativas.length
						? `Ninguém enviou o formulário ${ondeFalta()} ainda. ${ativas.length} ${ativas.length === 1 ? 'pessoa precisa' : 'pessoas precisam'} enviar: veja em Pendentes.`
						: cabecalho.evento
							? 'Ninguém se inscreveu neste evento ainda.'
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
										{ehArquivo(q) ? 'Arquivo · foto ou PDF' : nomeDoTipo(q.type)}{q.type === 'multipla_escolha'
											? ' · dá para marcar mais de uma'
											: ''}
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
							{:else if ehArquivo(q)}
								{@const enviaramArquivo = enviaram.filter((p) => arquivoDa(q, p)).length}
								<span class="cartao-texto">
									{enviaramArquivo} de {enviaram.length} enviaram{q.is_required ? '' : ' (opcional)'}.
									{#if q.is_required && enviaramArquivo < enviaram.length}
										{enviaram.length - enviaramArquivo}
										{enviaram.length - enviaramArquivo === 1 ? 'enviou' : 'enviaram'} o formulário antes de esta pergunta existir.
									{/if}
								</span>
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
						{#if filtrosDaAtual.length}
							<div class="chips nao-imprimir">
								<span class="fm-rotulo">Mostrar quem marcou</span>
								{#each filtrosDaAtual as f (f.chave)}
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
											{#each celulasDaAtual(p) as c}
												<td class:vazio-celula={!c.v || (!c.arquivo && c.v === 'Não enviado')}>
													{c.v || '—'}
													{#if c.arquivo}
														{@const a = c.arquivo}
														<button type="button" class="fm-link link-arquivo nao-imprimir" onclick={() => verArquivo(a)}>Ver arquivo</button>
													{/if}
												</td>
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
								{#each fichas as p, i (p.inscricao_id)}
									<option value={i}>{p.nome}{p.encerrada ? ` (${p.encerrada.situacao.toLowerCase()})` : ''}</option>
								{/each}
							</select>
						</label>
						<div class="acoes-linha">
							<button type="button" class="fm-btn" disabled={pessoaIdx === 0} onclick={() => pessoaIdx--}>← Anterior</button>
							<span class="ficha-posicao"><strong>{pessoaIdx + 1}</strong> de {fichas.length}</span>
							<button type="button" class="fm-btn" disabled={pessoaIdx >= fichas.length - 1} onclick={() => pessoaIdx++}>
								Próxima →
							</button>
							<button type="button" class="fm-btn" onclick={() => window.print()}>Imprimir ficha</button>
						</div>
					</div>
					<div class="ficha-topo">
						<div class="ficha-quem">
							<h2 class="fm-h2">{pessoa.nome}</h2>
							<span class="cartao-sub">
								{[pessoa.idade !== null ? `${pessoa.idade} anos` : '', pessoa.telefone, pessoa.email].filter(Boolean).join(' · ')}
							</span>
							<span class="cartao-sub">
								{pessoa.enviado_em
									? `Enviado em ${diaEHora(pessoa.enviado_em)}`
									: pessoa.atualizado_em
										? `Atualizado em ${diaEHora(pessoa.atualizado_em)}`
										: 'Enviado'} · era {pessoa.situacao}
							</span>
							{#if pessoa.enviado_em && pessoa.atualizado_em && pessoa.atualizado_em.slice(0, 16) > pessoa.enviado_em.slice(0, 16)}
								<span class="cartao-sub">Respostas alteradas em {diaEHora(pessoa.atualizado_em)}</span>
							{/if}
						</div>
						<div class="ficha-situacao">
							{@render seloDaPessoa(pessoa)}
							{#if !pessoa.encerrada}
								<div class="acoes-linha nao-imprimir">
									{#if !pessoa.correcao}
										<button type="button" class="fm-btn fm-btn--contorno" onclick={() => abrirCorrecao(pessoa)}>Pedir correção</button>
									{/if}
									<button type="button" class="fm-btn fm-btn--perigo" onclick={() => abrirCancelar(pessoa)}>Cancelar inscrição</button>
								</div>
							{/if}
						</div>
					</div>
					{#if pessoa.correcao}
						{@const c = pessoa.correcao}
						{@const nomesMarcadas = perguntas.filter((q) => c.perguntas.includes(q.id)).map((q) => q.text)}
						<div class="faixa faixa--ambar">
							<div class="faixa-textos">
								<span>
									<strong>Correção pedida{c.pedida_em ? ` em ${diaEMes(c.pedida_em)}` : ''}{c.por ? ` por ${c.por}` : ''}:</strong>
									“{c.motivo}”
								</span>
								<span class="faixa-sub">
									{c.atrasada ? 'Era para corrigir até' : 'Pode corrigir até'}
									{c.prazo ? diaEMes(c.prazo) : ''}.{#if nomesMarcadas.length}{' '}{nomesMarcadas.length === 1 ? 'Marcada' : 'Marcadas'}: {nomesMarcadas.join(', ')}.{/if}
								</span>
							</div>
							<button type="button" class="fm-btn nao-imprimir" onclick={() => desfazer(pessoa)}>Desfazer pedido</button>
						</div>
					{/if}
					{#if pessoa.encerrada}
						{@const e = pessoa.encerrada}
						<div class="faixa faixa--perigo">
							<span>
								{#if e.situacao === 'Recusada'}
									<strong>Inscrição recusada em {diaEMes(e.em)}:</strong> {e.motivo}
								{:else}
									<strong>Inscrição cancelada pela organização em {diaEMes(e.em)}{e.por ? ` por ${e.por}` : ''}:</strong>
									“{e.motivo}”
								{/if}
							</span>
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
										{@const marcada = !!pessoa.correcao?.perguntas.includes(q.id)}
										{@const arq = arquivoDa(q, pessoa)}
										<div class="ficha-item" class:ficha-item--filha={q.pai} class:ficha-item--marcada={marcada}>
											{#if marcada}<span class="selo-marcada">Marcada para corrigir</span>{/if}
											<dt>{q.pai ? '↳ ' : ''}{q.text}</dt>
											{#if ehArquivo(q)}
												<dd class:vazio-celula={!arq}>
													{#if arq}{@render caixaDoArquivo(arq)}{:else}Não enviado{/if}
												</dd>
											{:else}
												<dd class:vazio-celula={!v} class:saude={ehAlertaDeSaude(q) && v === 'Sim'}>{v || 'Não respondeu'}</dd>
											{/if}
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
											{@const arq = valePara(q, p) ? arquivoDa(q, p) : null}
											<td
												class:vazio-celula={!v || (ehArquivo(q) && !arq)}
												class:celula-condicional={q.pai}
												class:saude={ehAlertaDeSaude(q) && v === 'Sim'}
											>
												{#if ehArquivo(q) && valePara(q, p)}
													{#if arq}
														Enviado
														<button type="button" class="fm-link link-arquivo nao-imprimir" onclick={() => verArquivo(arq)}>Abrir</button>
													{:else}
														Não enviado
													{/if}
												{:else}
													{v || '—'}
												{/if}
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
						ativas.length ? 'Todos já enviaram o formulário e não há correção pedida.' : `Ninguém precisa enviar o formulário ${ondeFalta()} ainda.`
					)}
				{:else}
					<div class="fm-aviso fm-aviso--ambar">
						<span>
							<strong>
								{#if semEnviar}{semEnviar} {semEnviar === 1 ? 'pessoa ainda não enviou' : 'pessoas ainda não enviaram'}{/if}{#if semEnviar && comCorrecao}
									e{' '}{/if}{#if comCorrecao}{comCorrecao} {semEnviar ? '' : comCorrecao === 1 ? 'pessoa ' : 'pessoas '}{comCorrecao === 1 ? 'está' : 'estão'} com correção pedida{atrasadas
										? ` (${atrasadas} ${atrasadas === 1 ? 'atrasada' : 'atrasadas'})`
										: ''}{/if}.
							</strong>
							{#if cabecalho.evento}
								{#if dados.prazo}{situacaoDoPrazo}.{/if} Sem enviar, a inscrição não é concluída e a vaga não fica garantida.
							{:else if dados.prazo && diasAte(dados.prazo) >= 0}
								Quem não enviar até {diaEMes(dados.prazo)} perde a vaga.
							{:else if dados.prazo}
								{situacaoDoPrazo}.
							{:else}
								Este acampamento ainda não tem prazo para o formulário.
							{/if}
							{#if comCorrecao}
								Quem está com correção pedida não perde a vaga no prazo da correção: aparece aqui como “Correção atrasada”. Se
								ainda não tiver corrigido em {edicao ? diaEMes(edicao.inicio) : 'o início'}, início {cabecalho.evento
									? 'do evento'
									: 'do acampamento'}, a inscrição fica <strong>Recusada</strong>{cabecalho.evento
									? ' e a vaga volta para as inscrições.'
									: ' e a vaga vai para “Chamar próximo”.'}
							{/if}
						</span>
					</div>
					<section class="fm-cartao tabela-cartao">
						<div class="tabela-rolagem">
							<table class="tabela tabela-pendentes">
								<thead>
									<tr>
										<th scope="col" class="col-nome">Pessoa</th>
										<th scope="col">Situação</th>
										<th scope="col">Contato</th>
										<th scope="col">Desde</th>
										<th scope="col" class="col-acoes">Ações</th>
									</tr>
								</thead>
								<tbody>
									{#each pendentes as p (p.inscricao_id)}
										<tr>
											<th scope="row" class="col-nome">{p.nome}</th>
											<td>
												<span class="pendente-situacao">
													{@render selo(
														situacaoDoPendente(p),
														p.correcao?.atrasada ? 'perigo' : p.correcao || p.situacao === 'Suplente' || p.situacao === 'Em andamento' ? 'ambar' : 'ok'
													)}
													{#if p.correcao}<span class="pendente-motivo">“{p.correcao.motivo}”</span>{/if}
												</span>
											</td>
											<td>
												<span class="contato">{p.telefone || '—'}</span>
												<span class="contato contato--email">{p.email || ''}</span>
											</td>
											<td>{desdeDoPendente(p)}</td>
											<td class="col-acoes">
												<span class="acoes-linha acoes-pendente nao-imprimir">
													{#if p.correcao}
														<button type="button" class="fm-btn" onclick={() => abrirPessoa(p)}>Ver respostas</button>
													{/if}
													<button
														type="button"
														class="fm-btn fm-btn--perigo"
														aria-label="Cancelar inscrição de {p.nome}"
														onclick={() => abrirCancelar(p)}
													>
														Cancelar inscrição
													</button>
												</span>
											</td>
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

<!-- ─── Janelas ─── -->
{#if acao?.tipo === 'correcao'}
	{@const p = acao.p}
	{@const quem = primeiroNome(p.nome)}
	{@const ateTexto = ateIso ? diaEMes(ateIso) : '—'}
	<Janela idTitulo="t-correcao" largura={700} faixa="linear-gradient(90deg,#c08a1e,#b69e55)" travada={ocupado} onfechar={() => (acao = null)}>
		<div class="janela-cabeca">
			<h2 id="t-correcao" class="fm-janela-titulo">Pedir correção a {p.nome}</h2>
			<p class="fm-sub">
				A inscrição continua com a vaga. O formulário volta a ficar aberto para {quem} corrigir e enviar de novo.
			</p>
		</div>
		<label class="janela-campo">
			<span class="janela-rotulo">Motivo</span>
			<textarea
				class="fm-campo janela-texto"
				rows="3"
				maxlength="1000"
				data-foco-inicial
				placeholder="Ex: A foto do documento ficou cortada. Envie de novo mostrando a frente inteira."
				bind:value={motivo}
			></textarea>
			<span class="janela-ajuda">{quem} vai ler este texto no site.</span>
		</label>
		<div class="janela-campo">
			<span class="janela-rotulo">Perguntas para corrigir <span class="janela-opcional">(opcional)</span></span>
			<div class="marcar-perguntas">
				{#each secoes as sec (sec.id)}
					{@const daSecao = perguntas.filter((q) => q.section.id === sec.id && valePara(q, p))}
					{#if daSecao.length}
						<span class="fm-rotulo marcar-secao">{sec.titulo}</span>
						{#each daSecao as q (q.id)}
							{@const ligada = marcadas.includes(q.id)}
							<button
								type="button"
								role="checkbox"
								aria-checked={ligada}
								class="marcar-item"
								class:marcar-item--filha={q.pai}
								onclick={() => alternarMarcada(q.id)}
							>
								<span class="caixa" class:caixa--on={ligada} aria-hidden="true">{ligada ? '✓' : ''}</span>
								<span>{q.text}</span>
							</button>
						{/each}
					{/if}
				{/each}
			</div>
			<span class="janela-ajuda">
				{marcadas.length === 0 ? 'Nenhuma marcada.' : marcadas.length === 1 ? '1 marcada.' : `${marcadas.length} marcadas.`}
				As marcadas aparecem em destaque para a pessoa, mas ela pode mudar qualquer resposta.
			</span>
		</div>
		<label class="janela-campo">
			<span class="janela-rotulo">Corrigir até</span>
			<input
				class="fm-campo campo-data"
				inputmode="numeric"
				placeholder="dd/mm/aaaa"
				value={corrigirAte}
				aria-invalid={ateInvalida || undefined}
				oninput={(e) => {
					corrigirAte = mascaraData(e.currentTarget.value);
					e.currentTarget.value = corrigirAte;
				}}
			/>
			{#if prazoDoFormularioVencido && dados?.prazo}
				<div class="fm-aviso fm-aviso--ambar fm-aviso--pequeno">
					<span>O prazo do formulário terminou em {diaEMes(dados.prazo)}. Escolha até quando {quem} pode corrigir.</span>
				</div>
			{:else}
				<span class="janela-ajuda">É o prazo do formulário. Se quiser, dê mais tempo.</span>
			{/if}
			{#if corrigirAte.length === 10 && ateInvalida}
				<span class="fm-erro-linha" role="alert">
					{!ateIso
						? 'Data inválida. Use dia/mês/ano.'
						: ateIso < hojeIso()
							? 'A data não pode estar no passado.'
							: `Escolha uma data até o início (${edicao ? diaEMes(edicao.inicio) : ''}).`}
				</span>
			{/if}
		</label>
		<div class="fm-aviso fm-aviso--azul fm-aviso--pequeno">
			<span>
				Se {quem} não corrigir até {ateTexto}, aparece em Pendentes como “Correção atrasada”, ainda com a vaga. Se não tiver
				corrigido em <strong>{edicao ? diaEMes(edicao.inicio) : 'o início'}</strong>, quando começa {cabecalho?.evento
					? 'o evento'
					: 'o acampamento'}, a inscrição fica <strong>Recusada</strong>{cabecalho?.evento
					? ' e a vaga volta para as inscrições.'
					: ' e a vaga vai para “Chamar próximo”.'}
			</span>
		</div>
		{#if erroAcao}<p class="fm-erro-linha" role="alert">{erroAcao}</p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" disabled={ocupado} onclick={() => (acao = null)}>Cancelar</button>
			<button
				type="button"
				class="fm-btn fm-btn--ouro fm-btn--grande"
				disabled={ocupado || !motivo.trim() || ateInvalida}
				onclick={confirmarCorrecao}
			>
				{#if ocupado}<span class="fm-spinner fm-spinner--pequeno"></span>Pedindo...{:else}Pedir correção{/if}
			</button>
		</div>
	</Janela>
{/if}

{#if acao?.tipo === 'cancelar'}
	{@const p = acao.p}
	{@const quem = primeiroNome(p.nome)}
	<Janela idTitulo="t-cancelar" largura={620} faixa="linear-gradient(90deg,#9b2c2c,#b69e55)" travada={ocupado} onfechar={() => (acao = null)}>
		<h2 id="t-cancelar" class="fm-janela-titulo">Cancelar a inscrição de {p.nome}?</h2>
		<div class="fm-janela-alvo">
			<strong>{p.nome}</strong>
			<span>
				{edicao?.nome ?? ''} · {cabecalho?.evento ? 'Participante de evento' : alvo?.tipo === 'acampamento' ? alvo.inscricao : ''} ·
				{nomeDaSituacao(p)}{p.enviou || p.correcao ? '' : ' · não enviou'}
			</span>
		</div>
		<label class="janela-campo">
			<span class="janela-rotulo">Motivo</span>
			<textarea
				class="fm-campo janela-texto"
				rows="3"
				maxlength="1000"
				data-foco-inicial
				placeholder="Ex: A família avisou que ele não vai poder ir."
				bind:value={motivo}
			></textarea>
			<span class="janela-ajuda">{quem} vai ler este texto no site.</span>
		</label>
		<ul class="fm-janela-lista">
			{#if cabecalho?.evento}
				<li>A vaga volta a ficar disponível para novas inscrições.</li>
			{:else}
				<li>A vaga fica livre e entra em “Chamar próximo”, em Inscritos e Sorteios, com o motivo “Cancelada pela organização”.</li>
			{/if}
			{#if p.conjuge && !cabecalho?.evento}
				<li>A inscrição de <strong>{p.conjuge}</strong> também é cancelada: no casal, a vaga é dos dois.</li>
			{/if}
			<li>{quem} vê no site: “Inscrição cancelada pela organização”, com o motivo.</li>
			<li><strong class="texto-perigo">Essa ação não pode ser desfeita.</strong></li>
		</ul>
		{#if erroAcao}<p class="fm-erro-linha" role="alert">{erroAcao}</p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" disabled={ocupado} onclick={() => (acao = null)}>Voltar</button>
			<button
				type="button"
				class="fm-btn fm-btn--perigo-cheio fm-btn--grande"
				disabled={ocupado || !motivo.trim()}
				onclick={confirmarCancelamento}
			>
				{#if ocupado}<span class="fm-spinner fm-spinner--pequeno"></span>Cancelando...{:else}Cancelar inscrição{/if}
			</button>
		</div>
	</Janela>
{/if}

{#if acao?.tipo === 'zap'}
	{@const a = acao}
	{@const resp = responsavelDe(a.p)}
	<Janela idTitulo="t-zap" largura={640} onfechar={fecharZap}>
		<div class="zap-titulo">
			<span class="zap-check" aria-hidden="true">✓</span>
			<h2 id="t-zap" class="fm-janela-titulo">{a.de === 'correcao' ? 'Correção pedida' : 'Inscrição cancelada'}</h2>
		</div>
		<p class="zap-texto">
			{primeiroNome(a.p.nome)} vê isso quando entrar no site. Para avisar agora, mande esta mensagem pelo WhatsApp.
		</p>
		<fieldset class="zap-para">
			<legend class="janela-rotulo">Enviar para</legend>
			{#each [{ k: 'pessoa' as const, label: a.p.nome, tel: a.p.telefone ?? 'sem telefone' }, ...(resp ? [{ k: 'responsavel' as const, label: `Responsável${resp.nome ? `: ${resp.nome}` : ''}`, tel: resp.telefone }] : [])] as o (o.k)}
				<button
					type="button"
					role="radio"
					aria-checked={zapPara === o.k}
					class="zap-opcao"
					class:zap-opcao--on={zapPara === o.k}
					onclick={() => {
						zapPara = o.k;
						zapTexto = null;
						copiou = false;
					}}
				>
					<span class="zap-radio" aria-hidden="true"></span>
					<span class="zap-opcao-textos"><strong>{o.label}</strong><span>{o.tel}</span></span>
				</button>
			{/each}
		</fieldset>
		<label class="janela-campo">
			<span class="janela-rotulo">Mensagem <span class="janela-opcional">(dá para mudar antes de mandar)</span></span>
			<textarea
				class="fm-campo janela-texto zap-mensagem"
				rows="7"
				value={textoDoZap}
				oninput={(e) => {
					zapTexto = e.currentTarget.value;
					copiou = false;
				}}
			></textarea>
		</label>
		<div class="fm-janela-acoes zap-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fecharZap}>Fechar</button>
			<div class="acoes-linha">
				{#if copiou}
					<span class="selo-situacao zap-copiada" role="status"><span class="selo-ponto"></span>Copiada</span>
				{:else}
					<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={copiarMensagem}>Copiar mensagem</button>
				{/if}
				<a class="fm-btn fm-btn--ouro fm-btn--grande" href={linkDoZap} target="_blank" rel="noopener noreferrer" data-foco-inicial>
					Abrir no WhatsApp
				</a>
			</div>
		</div>
	</Janela>
{/if}

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

	.ficha-quem {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.ficha-situacao .acoes-linha {
		margin-top: 6px;
	}

	.faixa {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 14px;
		flex-wrap: wrap;
		padding: 14px 16px;
		font-size: 16px;
		line-height: 1.45;
		border-radius: 10px;
	}

	.faixa--ambar {
		color: #6a480c;
		background: #fbf0d6;
		border: 1px solid #ebd39c;
	}

	.faixa--perigo {
		color: #842424;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
	}

	.faixa-textos {
		flex: 1 1 320px;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.faixa-sub {
		font-size: 15px;
	}

	.ficha-item--marcada {
		padding: 10px 12px;
		background: #fbf0d6;
		border: 1px solid #ebd39c;
		border-radius: 10px;
	}

	.selo-marcada {
		align-self: flex-start;
		padding: 2px 8px;
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.06em;
		text-transform: uppercase;
		color: #6a480c;
		background: #f6e3b5;
		border-radius: 999px;
	}

	/* ─── Arquivo ─── */
	.arq {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
	}

	.arq-icone {
		flex: 0 0 34px;
		height: 42px;
		display: flex;
		align-items: flex-end;
		justify-content: center;
		padding-bottom: 5px;
		box-sizing: border-box;
		font-size: 10px;
		font-weight: 700;
		color: var(--azul);
		background: #eef1f7;
		border: 1.5px solid #c4cfe6;
		border-radius: 4px 10px 4px 4px;
	}

	.arq-textos {
		flex: 1 1 160px;
		display: flex;
		flex-direction: column;
		gap: 1px;
		min-width: 0;
	}

	.arq-nome {
		font-weight: 600;
		overflow-wrap: anywhere;
	}

	.arq-meta {
		font-size: 14px;
		font-weight: 400;
		color: var(--tinta-3);
	}

	.link-arquivo {
		margin-left: 8px;
		font-size: 14px;
	}

	.so-imprimir {
		display: none;
	}

	/* ─── Pendentes ─── */
	.pendente-situacao {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 4px;
	}

	.pendente-situacao .selo-situacao {
		white-space: normal;
	}

	.pendente-motivo {
		font-size: 14px;
		line-height: 1.35;
		color: var(--tinta-3);
	}

	.tabela .col-acoes {
		min-width: 290px;
		text-align: right;
	}

	.acoes-pendente {
		justify-content: flex-end;
		flex-wrap: nowrap;
	}

	.tabela .col-acoes {
		max-width: none;
	}

	/* ─── Janelas ─── */
	.janela-cabeca {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.janela-campo {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.janela-rotulo {
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
	}

	.janela-opcional {
		font-weight: 400;
		color: var(--tinta-3);
	}

	.janela-ajuda {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.janela-texto {
		height: auto;
		min-height: 96px;
		padding: 12px 14px;
		line-height: 1.45;
		resize: vertical;
	}

	.campo-data {
		max-width: 220px;
		font-variant-numeric: tabular-nums;
	}

	.marcar-perguntas {
		max-height: 236px;
		overflow: auto;
		padding: 6px 8px;
		background: #ffffff;
		border: 1px solid var(--borda-suave);
		border-radius: 10px;
	}

	.marcar-secao {
		display: block;
		padding: 10px 6px 4px;
	}

	.marcar-item {
		display: flex;
		align-items: center;
		gap: 12px;
		width: 100%;
		padding: 8px 6px;
		font-size: 16px;
		text-align: left;
		color: var(--tinta);
		background: transparent;
		border: none;
		border-radius: 8px;
		cursor: pointer;
	}

	.marcar-item:hover {
		background: var(--cabecalho-tabela);
	}

	.marcar-item--filha {
		padding-left: 24px;
	}

	.caixa {
		flex: 0 0 22px;
		height: 22px;
		box-sizing: border-box;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 15px;
		font-weight: 700;
		color: var(--pergaminho);
		border: 2px solid var(--campo-borda, #a39363);
		border-radius: 6px;
	}

	.caixa--on {
		background: var(--azul);
		border-color: var(--azul);
	}

	.texto-perigo {
		color: #842424;
	}

	.zap-titulo {
		display: flex;
		align-items: center;
		gap: 12px;
	}

	.zap-check {
		flex: 0 0 40px;
		height: 40px;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 20px;
		font-weight: 700;
		color: #2e7d4f;
		background: #e4f1e8;
		border: 1.5px solid #b9d9c4;
		border-radius: 50%;
	}

	.zap-texto {
		margin: 0;
		font-size: 17px;
		line-height: 1.45;
		color: var(--tinta-2);
	}

	.zap-para {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding: 0;
		border: none;
	}

	.zap-para legend {
		padding: 0 0 8px;
	}

	.zap-opcao {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: 52px;
		padding: 6px 14px;
		text-align: left;
		background: #ffffff;
		border: 1.5px solid var(--borda);
		border-radius: 10px;
		cursor: pointer;
	}

	.zap-opcao--on {
		background: #eef1f7;
		border-color: var(--azul);
	}

	.zap-radio {
		flex: 0 0 22px;
		height: 22px;
		box-sizing: border-box;
		border: 2px solid var(--campo-borda, #a39363);
		border-radius: 50%;
		background: #ffffff;
	}

	.zap-opcao--on .zap-radio {
		border-color: var(--azul);
		background: var(--azul);
		box-shadow: inset 0 0 0 4px #ffffff;
	}

	.zap-opcao-textos {
		display: flex;
		flex-direction: column;
		font-size: 15px;
		color: var(--tinta-3);
		font-variant-numeric: tabular-nums;
	}

	.zap-opcao-textos strong {
		font-size: 17px;
		color: var(--tinta);
	}

	.zap-mensagem {
		min-height: 190px;
	}

	.zap-acoes {
		justify-content: space-between;
	}

	.zap-copiada {
		height: 52px;
		box-sizing: border-box;
		padding: 0 16px;
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

	.selo-situacao--perigo {
		color: #842424;
		background: #f6e2df;
		border-color: #e6bdb6;
	}

	.selo-situacao--perigo .selo-ponto {
		background: #b33a3a;
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

		.so-imprimir {
			display: inline;
		}
	}
</style>
