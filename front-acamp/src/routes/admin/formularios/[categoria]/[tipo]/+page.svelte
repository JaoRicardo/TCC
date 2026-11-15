<script lang="ts">
	/**
	 * Editor de um formulário de acampamento (categoria × Campista/Servo), no
	 * modelo do Google Forms: um cartão aberto por vez, que salva sozinho ao
	 * fechar. Pergunta nova é rascunho até ser concluída. Só aparecem as seções
	 * que o formulário usa (as seções são compartilhadas entre os formulários).
	 */
	import { onDestroy, onMount, tick } from 'svelte';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import '$lib/styles/formularios.css';
	import Janela from '$lib/components/painel/Janela.svelte';
	import ImportarPerguntas from '$lib/components/painel/ImportarPerguntas.svelte';
	import CabecalhoFormulario from '$lib/components/painel/CabecalhoFormulario.svelte';
	import { pode } from '$lib/permissoes';
	import { caminhoDoPainel } from '$lib/stores/caminho.svelte';
	import {
		TIPOS_PERGUNTA,
		TIPOS_COM_OPCOES,
		getResumoFormularios,
		getPerguntas,
		getSecoes,
		criarPergunta,
		editarPergunta,
		excluirPergunta,
		restaurarPergunta,
		excluirPerguntasDaSecao,
		restaurarPerguntasDaSecao,
		criarSecao,
		editarSecao,
		type OpcaoParaSalvar,
		type Pergunta,
		type ResumoFormulario,
		type Secao
	} from '$lib/services/perguntaService';
	import { diaEMes, normalizar, quemEnvia, rolarSuave, tituloDoFormulario, type TipoInscricao } from '$lib/formularios';
	import logoAsas from '$lib/imgs/logo-asas.png';

	type OpcaoLocal = { id: number | null; text: string };
	type PerguntaLocal = {
		/** Número = já salva; texto = rascunho ainda não salvo. */
		id: number | string;
		rascunho: boolean;
		secaoId: number;
		text: string;
		type: string;
		is_required: boolean;
		order: number;
		/** Opção da pergunta pai que faz esta aparecer (pergunta condicional). */
		dependsOn: number | null;
		opcoes: OpcaoLocal[];
		/** Quantas pessoas já responderam (0 no rascunho). */
		respostas: number;
	};
	type Modal =
		| null
		| { tipo: 'excluir'; id: number | string }
		| { tipo: 'excluirSecao'; secaoId: number }
		| { tipo: 'primeira' }
		| { tipo: 'secao' }
		| { tipo: 'importar' }
		| { tipo: 'renomear'; secaoId: number; nome: string };

	const categoryId = Number($page.params.categoria);
	const tipo: TipoInscricao | null =
		$page.params.tipo === 'campista' ? 'Campista' : $page.params.tipo === 'servo' ? 'Servo' : null;

	// ─── Dados ───────────────────────────────────────────────────────────
	let estado: 'carregando' | 'erro' | 'pronto' = $state('carregando');
	let resumo: ResumoFormulario[] = $state([]);
	let secoes: Secao[] = $state([]);
	/** Perguntas na ordem em que aparecem: em cada seção, cada pergunta seguida das condicionais dela. */
	let perguntas: PerguntaLocal[] = $state([]);
	/** Seções acrescentadas aqui que ainda não têm pergunta salva (só da tela). */
	let extras: number[] = $state([]);

	let esteFormulario = $derived(resumo.find((f) => f.category_id === categoryId && f.subscription_type === tipo));
	let titulo = $derived(esteFormulario && tipo ? tituloDoFormulario(esteFormulario.categoria, tipo) : 'Formulário');
	let uso = $derived(esteFormulario?.uso ?? null);
	let salvas = $derived(perguntas.filter((p) => !p.rascunho));
	/** Sem "Formulários" (só "Respostas dos formulários"), o editor fica só para leitura. */
	let podeEditar = $derived(pode('formularios'));

	// ─── Estado da tela ──────────────────────────────────────────────────
	let aberta: number | string | null = $state(null);
	/** Cópia de trabalho do cartão aberto; vai para `perguntas` ao salvar. */
	let ed: PerguntaLocal | null = $state(null);
	let erroCartao = $state('');
	let salvandoCartao = $state(false);
	let operacoes = $state(0);
	let jaSalvou = $state(false);
	let busca = $state('');
	let menuSecao: number | null = $state(null);
	let menuMover = $state(false);
	let modal: Modal = $state(null);
	let erroModal = $state('');
	let ocupadoModal = $state(false);
	let novaSecaoNome = $state('');
	let toast = $state('');
	/** Ação do botão "Desfazer" do aviso (só depois de excluir). */
	let toastDesfazer: (() => void) | null = $state(null);
	let toastTimer: ReturnType<typeof setTimeout> | undefined;
	/** Opção escolhida em "+ Pergunta que aparece se marcar [opção]" no cartão aberto. */
	let opcaoDaFilha: number | null = $state(null);
	let arrastando: number | string | null = $state(null);
	let alvoArraste: number | string | null = $state(null);
	let contadorRascunho = 0;

	const ehEscolha = (t: string) => TIPOS_COM_OPCOES.includes(t);
	const podeSerPai = (q: PerguntaLocal) => q.type === 'sim_nao' || ehEscolha(q.type);
	const nomeDoTipo = (t: string) => TIPOS_PERGUNTA.find((x) => x.value === t)?.label ?? t;
	const iconeDoTipo = (t: string) => TIPOS_PERGUNTA.find((x) => x.value === t)?.icone ?? '?';
	const nomeDaSecao = (id: number) => secoes.find((s) => s.id === id)?.titulo ?? '';
	const copia = (q: PerguntaLocal): PerguntaLocal => ({ ...q, opcoes: q.opcoes.map((o) => ({ ...o })) });

	function daApi(p: Pergunta): PerguntaLocal {
		return {
			id: p.id,
			rascunho: false,
			secaoId: p.section.id,
			text: p.text,
			type: p.type,
			is_required: p.is_required,
			order: p.order,
			dependsOn: p.depends_on_option_id,
			opcoes: p.options.map((o) => ({ id: o.id, text: o.text })),
			respostas: p.respostas ?? 0
		};
	}

	// ─── Condicionais ────────────────────────────────────────────────────
	/** Opção → pergunta dona dela, para achar a pai de uma condicional. */
	let donoDaOpcao = $derived.by(() => {
		const mapa = new Map<number, PerguntaLocal>();
		for (const q of perguntas) for (const o of q.opcoes) if (o.id !== null) mapa.set(o.id, q);
		return mapa;
	});

	function paiDe(q: PerguntaLocal): PerguntaLocal | undefined {
		return q.dependsOn !== null ? donoDaOpcao.get(q.dependsOn) : undefined;
	}

	function opcaoQueMostra(q: PerguntaLocal): string {
		const pai = paiDe(q);
		return pai?.opcoes.find((o) => o.id === q.dependsOn)?.text ?? '';
	}

	function filhosDe(q: PerguntaLocal): PerguntaLocal[] {
		const ids = new Set(q.opcoes.map((o) => o.id).filter((id) => id !== null));
		return perguntas.filter((x) => x.dependsOn !== null && ids.has(x.dependsOn));
	}

	/** Condicional que fica recuada logo abaixo da pai (a pai está na mesma seção). */
	function ehFilhaNaSecao(q: PerguntaLocal): boolean {
		const pai = paiDe(q);
		return !!pai && pai.secaoId === q.secaoId;
	}

	/** Perguntas de que `q` pode depender: salvas, sem condição, de Sim/Não ou de escolha, da mesma seção. */
	function paisPossiveis(q: PerguntaLocal): PerguntaLocal[] {
		const atual = paiDe(q);
		return perguntas.filter(
			(p) =>
				p.id === atual?.id ||
				(!p.rascunho &&
					p.id !== q.id &&
					p.dependsOn === null &&
					p.secaoId === q.secaoId &&
					podeSerPai(p) &&
					p.opcoes.some((o) => o.id !== null))
		);
	}

	function mudarPai(paiId: number) {
		if (!ed) return;
		const novo = perguntas.find((x) => x.id === paiId);
		ed.dependsOn = novo?.opcoes.find((o) => o.id !== null)?.id ?? null;
	}

	/** Em cada seção: as perguntas na ordem salva, cada uma seguida das condicionais dela. */
	function ordenarEmBlocos(lista: PerguntaLocal[], ordemSecoes: Secao[]): PerguntaLocal[] {
		const dono = new Map<number, PerguntaLocal>();
		for (const q of lista) for (const o of q.opcoes) if (o.id !== null) dono.set(o.id, q);
		const filhaDe = (q: PerguntaLocal) => (q.dependsOn !== null ? dono.get(q.dependsOn) : undefined);

		const resultado: PerguntaLocal[] = [];
		const idsSecoes = [...ordemSecoes.map((s) => s.id), ...lista.map((q) => q.secaoId)];
		for (const secaoId of [...new Set(idsSecoes)]) {
			const ps = lista.filter((q) => q.secaoId === secaoId).sort((a, b) => a.order - b.order);
			const colocadas = new Set<PerguntaLocal>();
			for (const q of ps) {
				const pai = filhaDe(q);
				if (pai && pai.secaoId === secaoId) continue;
				resultado.push(q);
				colocadas.add(q);
				for (const f of ps) {
					if (filhaDe(f) === q) {
						resultado.push(f);
						colocadas.add(f);
					}
				}
			}
			for (const q of ps) if (!colocadas.has(q)) resultado.push(q);
		}
		return resultado;
	}

	// ─── Carregar ────────────────────────────────────────────────────────
	async function carregar() {
		if (!tipo || !Number.isInteger(categoryId)) {
			goto('/admin/formularios', { replaceState: true });
			return;
		}
		estado = 'carregando';
		try {
			const [rResumo, rSecoes, rPerguntas] = await Promise.all([
				getResumoFormularios(),
				getSecoes(),
				getPerguntas(categoryId, tipo)
			]);
			if (rResumo.error || rSecoes.error || rPerguntas.error) {
				estado = 'erro';
				return;
			}
			resumo = rResumo.data ?? [];
			secoes = (rSecoes.data ?? []).slice().sort((a, b) => a.ordem - b.ordem || a.id - b.id);
			perguntas = ordenarEmBlocos((rPerguntas.data ?? []).map(daApi), secoes);
			if (!esteFormulario) {
				estado = 'erro';
				return;
			}
			caminhoDoPainel.subtela = titulo;
			estado = 'pronto';
		} catch {
			estado = 'erro';
		}
	}

	onMount(carregar);
	onDestroy(() => {
		caminhoDoPainel.subtela = null;
		clearTimeout(toastTimer);
	});

	// ─── Avisos e salvamento ─────────────────────────────────────────────
	function avisar(texto: string, desfazer: (() => void) | null = null) {
		toast = texto;
		toastDesfazer = desfazer;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(fecharAviso, desfazer ? 8000 : 6000);
	}

	function fecharAviso() {
		toast = '';
		toastDesfazer = null;
	}

	/** Roda uma chamada ao servidor marcando "Salvando..." no topo. */
	async function salvando<T>(fn: () => Promise<T>): Promise<T> {
		operacoes++;
		try {
			return await fn();
		} finally {
			operacoes--;
			jaSalvou = true;
		}
	}

	function textosDasOpcoes(q: PerguntaLocal): string[] {
		return q.opcoes.map((o) => o.text.trim()).filter((t) => t !== '');
	}

	/** Opções com o id de cada uma: renomear mantém a opção (e as condicionais e respostas ligadas a ela). */
	function opcoesParaSalvar(q: PerguntaLocal): OpcaoParaSalvar[] {
		return q.opcoes.map((o) => ({ id: o.id, text: o.text.trim() })).filter((o) => o.text !== '');
	}

	function mudou(q: PerguntaLocal): boolean {
		const original = perguntas.find((x) => x.id === q.id);
		if (!original) return true;
		return (
			original.text !== q.text ||
			original.type !== q.type ||
			original.is_required !== q.is_required ||
			original.dependsOn !== q.dependsOn ||
			JSON.stringify(opcoesParaSalvar(original)) !== JSON.stringify(opcoesParaSalvar(q))
		);
	}

	function substituir(id: number | string, nova: PerguntaLocal) {
		perguntas = perguntas.map((x) => (x.id === id ? nova : x));
	}

	/** Salva o cartão aberto. Em caso de erro, ele continua aberto com a mensagem. */
	async function salvarAberta(): Promise<boolean> {
		if (!ed || !tipo) return true;
		const q = ed;
		erroCartao = '';
		if (!q.rascunho && !mudou(q)) return true;
		const condicaoMudou = !q.rascunho && perguntas.find((x) => x.id === q.id)?.dependsOn !== q.dependsOn;

		salvandoCartao = true;
		try {
			const res = await salvando(() =>
				q.rascunho
					? criarPergunta({
							category_id: categoryId,
							subscription_type: tipo,
							section_id: q.secaoId,
							text: q.text.trim(),
							type: q.type,
							is_required: q.is_required,
							options: ehEscolha(q.type) ? opcoesParaSalvar(q) : [],
							depends_on_option_id: q.dependsOn
						})
					: editarPergunta(q.id as number, {
							text: q.text.trim(),
							type: q.type,
							is_required: q.is_required,
							depends_on_option_id: q.dependsOn,
							...(q.type !== 'sim_nao' ? { options: opcoesParaSalvar(q) } : {})
						})
			);
			if (res.error || !res.data) {
				erroCartao = res.error || 'Não foi possível salvar a pergunta. Tente de novo.';
				return false;
			}
			const salva = daApi(res.data);
			substituir(q.id, salva);
			if (aberta === q.id) {
				aberta = salva.id;
				ed = copia(salva);
			}
			// A pergunta nova entra no fim da seção no servidor; se ela foi movida
			// ainda como rascunho, grava a posição em que está na tela. Com a
			// condição trocada, ela passa para junto da pai nova (ou sai da família).
			if (condicaoMudou) aplicarOrdemDaSecao(salva.secaoId, blocosDaSecao(salva.secaoId).flat());
			if (q.rascunho || condicaoMudou) await renumerarSecao(salva.secaoId);
			return true;
		} catch {
			erroCartao = 'Não foi possível salvar a pergunta. Verifique sua conexão e tente de novo.';
			return false;
		} finally {
			salvandoCartao = false;
		}
	}

	/** Fecha o cartão aberto (salvando). Rascunho sem texto some; com texto, fica como "Não salva". */
	async function fecharAberta(): Promise<boolean> {
		if (!ed) return true;
		if (ed.rascunho) {
			if (!ed.text.trim()) perguntas = perguntas.filter((x) => x.id !== ed!.id);
			else substituir(ed.id, copia(ed));
		} else if (!(await salvarAberta())) {
			return false;
		}
		aberta = null;
		ed = null;
		menuMover = false;
		erroCartao = '';
		return true;
	}

	async function abrir(id: number | string) {
		if (aberta === id || !podeEditar) return;
		if (!(await fecharAberta())) return;
		const q = perguntas.find((x) => x.id === id);
		if (!q) return;
		aberta = id;
		ed = copia(q);
		menuSecao = null;
		opcaoDaFilha = null;
	}

	async function concluir() {
		if (!ed) return;
		if (!ed.text.trim()) {
			if (ed.rascunho) {
				perguntas = perguntas.filter((x) => x.id !== ed!.id);
				aberta = null;
				ed = null;
			} else {
				erroCartao = 'Escreva a pergunta.';
			}
			return;
		}
		if (ehEscolha(ed.type) && textosDasOpcoes(ed).length < 2) return;
		if (ed.rascunho && salvas.length === 0 && uso) {
			erroModal = '';
			modal = { tipo: 'primeira' };
			return;
		}
		if (ed.rascunho) {
			if (!(await salvarAberta())) return;
			aberta = null;
			ed = null;
			return;
		}
		await fecharAberta();
	}

	async function confirmarPrimeira() {
		ocupadoModal = true;
		const ok = await salvarAberta();
		ocupadoModal = false;
		modal = null;
		if (!ok) return;
		aberta = null;
		ed = null;
		avisar('Pergunta salva. O prazo já vale para os inscritos.');
	}

	// ─── Nova pergunta ───────────────────────────────────────────────────
	let secoesUsadas = $derived(
		secoes.filter((s) => perguntas.some((q) => q.secaoId === s.id) || extras.includes(s.id))
	);

	function secaoAtual(): number {
		const q = aberta !== null ? perguntas.find((x) => x.id === aberta) : undefined;
		if (q) return q.secaoId;
		return secoesUsadas.at(-1)?.id ?? secoes[0]?.id;
	}

	/** Rascunho no fim da seção; condicional (dependsOn) entra logo depois da última filha da pai. */
	async function novaPergunta(secaoId: number, dependsOn: number | null = null) {
		if (!(await fecharAberta())) return;
		const q: PerguntaLocal = {
			id: `rascunho-${++contadorRascunho}`,
			rascunho: true,
			secaoId,
			text: '',
			type: 'texto_curto',
			is_required: true,
			order: 0,
			dependsOn,
			opcoes: [],
			respostas: 0
		};
		const pai = dependsOn !== null ? donoDaOpcao.get(dependsOn) : undefined;
		const ultima = pai
			? perguntas.findLastIndex((x) => x.id === pai.id || (x.secaoId === secaoId && paiDe(x)?.id === pai.id))
			: perguntas.findLastIndex((x) => x.secaoId === secaoId);
		const lista = perguntas.slice();
		lista.splice(ultima >= 0 ? ultima + 1 : lista.length, 0, q);
		perguntas = lista;
		if (!extras.includes(secaoId)) extras = [...extras, secaoId];
		aberta = q.id;
		ed = copia(q);
		await tick();
		const campo = document.getElementById(`texto-${q.id}`);
		campo?.scrollIntoView({ block: 'center', behavior: 'smooth' });
		campo?.focus({ preventScroll: true });
	}

	/** "+ Pergunta que aparece se marcar [opção]": salva a pai e abre a condicional nova. */
	async function novaFilha() {
		if (!ed || ed.rascunho) return;
		const paiId = ed.id;
		const opcaoId = opcaoDaFilha ?? ed.opcoes.find((o) => o.id !== null && o.text.trim())?.id ?? null;
		if (opcaoId === null) return;
		if (!(await fecharAberta())) return;
		const pai = perguntas.find((x) => x.id === paiId);
		if (!pai || !pai.opcoes.some((o) => o.id === opcaoId)) {
			avisar('A opção escolhida não existe mais. Abra a pergunta e escolha outra.');
			return;
		}
		await novaPergunta(pai.secaoId, opcaoId);
	}

	// ─── Cartão aberto: tipo e opções ────────────────────────────────────
	function mudarTipo(novo: string) {
		if (!ed) return;
		let opcoes: OpcaoLocal[];
		if (novo === 'sim_nao') opcoes = [{ id: null, text: 'Sim' }, { id: null, text: 'Não' }];
		else if (ehEscolha(novo)) opcoes = ehEscolha(ed.type) ? ed.opcoes : [{ id: null, text: '' }, { id: null, text: '' }];
		else opcoes = [];
		ed = { ...ed, type: novo, opcoes };
	}

	async function adicionarOpcao() {
		if (!ed) return;
		ed.opcoes = [...ed.opcoes, { id: null, text: '' }];
		await tick();
		document.getElementById(`opcao-${ed.id}-${ed.opcoes.length - 1}`)?.focus();
	}

	function removerOpcao(i: number) {
		if (!ed) return;
		ed.opcoes = ed.opcoes.filter((_, j) => j !== i);
	}

	// ─── Mover ───────────────────────────────────────────────────────────
	/** Blocos da seção: cada pergunta com as condicionais dela, na ordem da tela. */
	function blocosDaSecao(secaoId: number): PerguntaLocal[][] {
		const ps = perguntas.filter((x) => x.secaoId === secaoId);
		return ps
			.filter((x) => !ehFilhaNaSecao(x))
			.map((topo) => [topo, ...ps.filter((c) => ehFilhaNaSecao(c) && paiDe(c) === topo)]);
	}

	function aplicarOrdemDaSecao(secaoId: number, novaOrdem: PerguntaLocal[]) {
		let k = 0;
		perguntas = perguntas.map((x) => (x.secaoId === secaoId ? novaOrdem[k++] : x));
	}

	/** Grava no servidor a ordem que está na tela (1, 2, 3...) para as perguntas salvas da seção. */
	async function renumerarSecao(secaoId: number) {
		const mudancas: { id: number; order: number }[] = [];
		let n = 1;
		for (const q of perguntas.filter((x) => x.secaoId === secaoId && !x.rascunho)) {
			if (q.order !== n) mudancas.push({ id: q.id as number, order: n });
			n++;
		}
		if (mudancas.length === 0) return;
		const novaOrdem = new Map(mudancas.map((m) => [m.id, m.order]));
		perguntas = perguntas.map((x) => (typeof x.id === 'number' && novaOrdem.has(x.id) ? { ...x, order: novaOrdem.get(x.id)! } : x));
		try {
			const resultados = await salvando(() =>
				Promise.all(mudancas.map((m) => editarPergunta(m.id, { order: m.order })))
			);
			if (resultados.some((r) => r.error)) throw new Error();
		} catch {
			avisar('Não foi possível salvar a nova ordem. A tela foi recarregada com a ordem salva.');
			await recarregarPerguntas();
		}
	}

	async function recarregarPerguntas() {
		if (!tipo) return;
		const res = await getPerguntas(categoryId, tipo);
		if (res.data) {
			const rascunhos = perguntas.filter((x) => x.rascunho);
			perguntas = [...ordenarEmBlocos(res.data.map(daApi), secoes), ...rascunhos];
		}
	}

	/** Leva a pergunta (com as condicionais dela) para a posição de outro bloco da mesma seção. */
	async function moverPara(id: number | string, alvoId: number | string) {
		const q = perguntas.find((x) => x.id === id);
		const alvo = perguntas.find((x) => x.id === alvoId);
		if (!q || !alvo || q === alvo || q.secaoId !== alvo.secaoId) return;

		if (ehFilhaNaSecao(q)) {
			// Condicional só troca de lugar com as irmãs (mesma pergunta pai).
			if (paiDe(alvo) !== paiDe(q)) return;
			const lista = perguntas.slice();
			const a = lista.indexOf(q);
			const b = lista.indexOf(alvo);
			lista.splice(a, 1);
			lista.splice(b, 0, q);
			perguntas = lista;
		} else {
			const blocos = blocosDaSecao(q.secaoId);
			const de = blocos.findIndex((b) => b[0] === q);
			const para = blocos.findIndex((b) => b.includes(alvo));
			if (de < 0 || para < 0 || de === para) return;
			const [bloco] = blocos.splice(de, 1);
			blocos.splice(para, 0, bloco);
			aplicarOrdemDaSecao(q.secaoId, blocos.flat());
		}
		menuMover = false;
		await renumerarSecao(q.secaoId);
	}

	/** Subir (-1) ou descer (+1) uma posição. */
	async function moverUmaPosicao(id: number | string, direcao: -1 | 1) {
		const q = perguntas.find((x) => x.id === id);
		if (!q) return;
		let alvo: PerguntaLocal | undefined;
		if (ehFilhaNaSecao(q)) {
			const irmas = perguntas.filter((x) => x.secaoId === q.secaoId && paiDe(x) === paiDe(q));
			alvo = irmas[irmas.indexOf(q) + direcao];
		} else {
			const blocos = blocosDaSecao(q.secaoId);
			alvo = blocos[blocos.findIndex((b) => b[0] === q) + direcao]?.[0];
		}
		if (alvo) await moverPara(id, alvo.id);
		else menuMover = false;
	}

	async function moverParaSecao(id: number | string, destino: number) {
		const q = perguntas.find((x) => x.id === id);
		if (!q) return;
		const familia = [q, ...perguntas.filter((x) => ehFilhaNaSecao(x) && paiDe(x) === q)];
		const ultimaOrdem = Math.max(0, ...perguntas.filter((x) => x.secaoId === destino && !x.rascunho).map((x) => x.order));
		const movidas = familia.map((f, i) => ({ ...f, secaoId: destino, order: f.rascunho ? 0 : ultimaOrdem + i + 1 }));

		const resto = perguntas.filter((x) => !familia.includes(x));
		const ultima = resto.findLastIndex((x) => x.secaoId === destino);
		resto.splice(ultima >= 0 ? ultima + 1 : resto.length, 0, ...movidas);
		perguntas = resto;
		if (ed && aberta === q.id) ed = { ...ed, secaoId: destino };
		menuMover = false;

		const salvasDaFamilia = movidas.filter((m) => !m.rascunho);
		try {
			const resultados = await salvando(() =>
				Promise.all(salvasDaFamilia.map((m) => editarPergunta(m.id as number, { section_id: destino, order: m.order })))
			);
			if (resultados.some((r) => r.error)) throw new Error();
			avisar(`Pergunta movida para ${nomeDaSecao(destino)}.`);
		} catch {
			avisar('Não foi possível mover a pergunta. A tela foi recarregada.');
			await recarregarPerguntas();
		}
	}

	function teclaNaAlca(e: KeyboardEvent, id: number | string) {
		if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
		e.preventDefault();
		moverUmaPosicao(id, e.key === 'ArrowUp' ? -1 : 1).then(async () => {
			await tick();
			document.getElementById(`alca-${id}`)?.focus();
		});
	}

	function podeSoltarEm(alvo: PerguntaLocal): boolean {
		const q = perguntas.find((x) => x.id === arrastando);
		if (!q || q === alvo || q.secaoId !== alvo.secaoId) return false;
		if (ehFilhaNaSecao(q)) return ehFilhaNaSecao(alvo) && paiDe(alvo) === paiDe(q);
		return true;
	}

	// ─── Duplicar e excluir ──────────────────────────────────────────────
	async function duplicar() {
		if (!ed || ed.rascunho || !tipo) return;
		if (!(await salvarAberta())) return;
		const q = perguntas.find((x) => x.id === aberta);
		if (!q) return;
		try {
			const res = await salvando(() =>
				criarPergunta({
					category_id: categoryId,
					subscription_type: tipo,
					section_id: q.secaoId,
					text: `${q.text} (cópia)`,
					type: q.type,
					is_required: q.is_required,
					options: ehEscolha(q.type) ? opcoesParaSalvar(q).map((o) => ({ id: null, text: o.text })) : [],
					depends_on_option_id: q.dependsOn
				})
			);
			if (res.error || !res.data) {
				erroCartao = res.error || 'Não foi possível duplicar a pergunta.';
				return;
			}
			const nova = daApi(res.data);
			// A cópia entra logo depois da pergunta (e das condicionais dela).
			const familia = ehFilhaNaSecao(q) ? [q] : [q, ...perguntas.filter((x) => ehFilhaNaSecao(x) && paiDe(x) === q)];
			const lista = perguntas.slice();
			lista.splice(lista.indexOf(familia[familia.length - 1]) + 1, 0, nova);
			perguntas = lista;
			aberta = nova.id;
			ed = copia(nova);
			avisar('Pergunta duplicada.');
			await renumerarSecao(nova.secaoId);
		} catch {
			erroCartao = 'Não foi possível duplicar a pergunta. Verifique sua conexão.';
		}
	}

	function pedirExclusao() {
		if (!ed) return;
		if (ed.rascunho) {
			perguntas = perguntas.filter((x) => x.id !== ed!.id);
			aberta = null;
			ed = null;
			return;
		}
		erroModal = '';
		modal = { tipo: 'excluir', id: ed.id };
	}

	async function confirmarExclusao() {
		if (modal?.tipo !== 'excluir') return;
		const alvoId = modal.id;
		const q = perguntas.find((x) => x.id === alvoId);
		if (!q) return;
		const saem = new Set([q, ...filhosDe(q)]);
		ocupadoModal = true;
		erroModal = '';
		try {
			const res = await salvando(() => excluirPergunta(q.id as number));
			if (!res.success) {
				erroModal = res.error || 'Não foi possível excluir a pergunta.';
				return;
			}
			perguntas = perguntas.filter((x) => !saem.has(x));
			aberta = null;
			ed = null;
			modal = null;
			avisar(saem.size > 1 ? `${saem.size} perguntas excluídas.` : 'Pergunta excluída.', () =>
				desfazerExclusao(q.id as number)
			);
		} catch {
			erroModal = 'Não foi possível excluir a pergunta. Verifique sua conexão e tente de novo.';
		} finally {
			ocupadoModal = false;
		}
	}

	async function desfazerExclusao(id: number) {
		fecharAviso();
		try {
			const res = await salvando(() => restaurarPergunta(id));
			if (res.error || !res.data) {
				avisar(res.error || 'Não foi possível desfazer a exclusão.');
				return;
			}
			await recarregarPerguntas();
			avisar(res.data.length > 1 ? `${res.data.length} perguntas restauradas.` : 'Pergunta restaurada.');
		} catch {
			avisar('Não foi possível desfazer a exclusão. Verifique sua conexão.');
		}
	}

	// ─── Importar ────────────────────────────────────────────────────────
	/** Formulários de onde dá para copiar: os que têm perguntas, menos este. */
	let fontesDeImportacao = $derived(
		resumo.filter((f) => f.perguntas > 0 && !(f.category_id === categoryId && f.subscription_type === tipo))
	);

	async function abrirImportar() {
		if (!(await fecharAberta())) return;
		modal = { tipo: 'importar' };
	}

	async function importado(copiadas: Pergunta[], deQual: string) {
		modal = null;
		await recarregarPerguntas();
		const n = copiadas.length;
		avisar(`${n} ${n === 1 ? 'pergunta importada' : 'perguntas importadas'} de ${deQual}.`);
	}

	// ─── Seções ──────────────────────────────────────────────────────────
	/** Formulários que usam a seção (este pela tela, os outros pelo resumo do servidor). */
	function formulariosQueUsam(secaoId: number): string[] {
		return resumo
			.filter((f) =>
				f.category_id === categoryId && f.subscription_type === tipo
					? perguntas.some((q) => q.secaoId === secaoId && !q.rascunho)
					: f.secoes.includes(secaoId)
			)
			.map((f) => tituloDoFormulario(f.categoria, f.subscription_type));
	}

	/** Sobe/desce a seção em relação à vizinha usada por este formulário. A ordem vale para todos. */
	async function moverSecao(secaoId: number, direcao: -1 | 1) {
		menuSecao = null;
		const i = secoesUsadas.findIndex((s) => s.id === secaoId);
		const vizinha = secoesUsadas[i + direcao];
		if (!vizinha) return;
		const global = secoes.filter((s) => s.id !== secaoId);
		const posVizinha = global.findIndex((s) => s.id === vizinha.id);
		const movida = secoes.find((s) => s.id === secaoId)!;
		global.splice(direcao === -1 ? posVizinha : posVizinha + 1, 0, movida);

		const mudancas = global
			.map((s, k) => ({ s, ordem: k + 1 }))
			.filter(({ s, ordem }) => s.ordem !== ordem);
		secoes = global.map((s, k) => ({ ...s, ordem: k + 1 }));
		try {
			const resultados = await salvando(() => Promise.all(mudancas.map(({ s, ordem }) => editarSecao(s.id, { ordem }))));
			if (resultados.some((r) => !r.success)) throw new Error();
		} catch {
			avisar('Não foi possível mudar a ordem da seção. Recarregue a página.');
		}
	}

	function tirarDoFormulario(secaoId: number) {
		menuSecao = null;
		extras = extras.filter((e) => e !== secaoId);
	}

	/** Perguntas salvas que saem ao excluir a seção: as dela e as condicionais delas (mesmo em outra seção). */
	function saemComASecao(secaoId: number): PerguntaLocal[] {
		const daSecao = perguntas.filter((q) => q.secaoId === secaoId && !q.rascunho);
		const filhas = daSecao.flatMap((q) => filhosDe(q)).filter((f) => !daSecao.includes(f) && !f.rascunho);
		return [...daSecao, ...new Set(filhas)];
	}

	async function confirmarExclusaoDaSecao() {
		if (modal?.tipo !== 'excluirSecao' || !tipo) return;
		const { secaoId } = modal;
		const saem = new Set(saemComASecao(secaoId));
		ocupadoModal = true;
		erroModal = '';
		try {
			const res = await salvando(() => excluirPerguntasDaSecao(secaoId, { category_id: categoryId, subscription_type: tipo }));
			if (res.error || !res.data) {
				erroModal = res.error || 'Não foi possível excluir as perguntas da seção.';
				return;
			}
			const { quando, excluidas } = res.data;
			// Rascunhos da seção e o cartão aberto nela também saem.
			const abertaSai = ed !== null && (ed.secaoId === secaoId || [...saem].some((x) => x.id === ed!.id));
			if (abertaSai) {
				aberta = null;
				ed = null;
			}
			perguntas = perguntas.filter((x) => !saem.has(x) && x.secaoId !== secaoId);
			extras = extras.filter((e) => e !== secaoId);
			modal = null;
			const nome = nomeDaSecao(secaoId);
			avisar(`${excluidas} ${excluidas === 1 ? 'pergunta excluída' : 'perguntas excluídas'} de ${nome}.`, () =>
				desfazerExclusaoDaSecao(secaoId, quando)
			);
		} catch {
			erroModal = 'Não foi possível excluir as perguntas. Verifique sua conexão e tente de novo.';
		} finally {
			ocupadoModal = false;
		}
	}

	async function desfazerExclusaoDaSecao(secaoId: number, quando: string) {
		if (!tipo) return;
		fecharAviso();
		try {
			const res = await salvando(() =>
				restaurarPerguntasDaSecao(secaoId, { category_id: categoryId, subscription_type: tipo, quando })
			);
			if (res.error || !res.data) {
				avisar(res.error || 'Não foi possível desfazer a exclusão.');
				return;
			}
			await recarregarPerguntas();
			avisar(`${res.data.length} ${res.data.length === 1 ? 'pergunta restaurada' : 'perguntas restauradas'}.`);
			mostrarSecao(secaoId);
		} catch {
			avisar('Não foi possível desfazer a exclusão. Verifique sua conexão.');
		}
	}

	async function salvarRenomear() {
		if (modal?.tipo !== 'renomear') return;
		const { secaoId, nome } = modal;
		if (!nome.trim()) return;
		ocupadoModal = true;
		erroModal = '';
		try {
			const res = await salvando(() => editarSecao(secaoId, { titulo: nome.trim() }));
			if (!res.success) {
				erroModal = res.error || 'Não foi possível renomear a seção.';
				return;
			}
			secoes = secoes.map((s) => (s.id === secaoId ? { ...s, titulo: nome.trim() } : s));
			modal = null;
			avisar('Seção renomeada em todos os formulários.');
		} catch {
			erroModal = 'Não foi possível renomear a seção. Verifique sua conexão.';
		} finally {
			ocupadoModal = false;
		}
	}

	async function mostrarSecao(secaoId: number) {
		await tick();
		const secao = document.getElementById(`sec-${secaoId}`);
		if (secao) rolarSuave(secao);
	}

	function adicionarSecaoExistente(secao: Secao) {
		extras = [...extras, secao.id];
		modal = null;
		avisar(`Seção “${secao.titulo}” adicionada.`);
		mostrarSecao(secao.id);
	}

	async function criarSecaoNova() {
		const nome = novaSecaoNome.trim();
		if (!nome) return;
		ocupadoModal = true;
		erroModal = '';
		try {
			const res = await salvando(() => criarSecao(nome));
			if (res.error || !res.data) {
				erroModal = res.error || 'Não foi possível criar a seção.';
				return;
			}
			const secao = res.data;
			const jaExistia = secoes.some((s) => s.id === secao.id);
			if (!jaExistia) secoes = [...secoes, secao].sort((a, b) => a.ordem - b.ordem || a.id - b.id);
			if (!extras.includes(secao.id)) extras = [...extras, secao.id];
			modal = null;
			avisar(jaExistia ? `A seção “${secao.titulo}” já existia e foi adicionada.` : `Seção “${secao.titulo}” criada.`);
			mostrarSecao(secao.id);
		} catch {
			erroModal = 'Não foi possível criar a seção. Verifique sua conexão.';
		} finally {
			ocupadoModal = false;
		}
	}

	// ─── Busca e montagem da tela ────────────────────────────────────────
	let termo = $derived(normalizar(busca.trim()));
	const combina = (q: PerguntaLocal | undefined) => !!q && (!termo || normalizar(q.text).includes(termo));
	let encontradas = $derived(termo ? perguntas.filter((q) => combina(q)).length : 0);

	let secoesNaTela = $derived(
		secoesUsadas
			.map((s, i) => {
				const ps = perguntas.filter((q) => q.secaoId === s.id);
				const visiveis = ps.filter((q) => combina(q) || q.id === aberta || (ehFilhaNaSecao(q) && combina(paiDe(q))));
				return {
					secao: s,
					indice: i + 1,
					perguntas: visiveis,
					total: ps.length,
					condicionais: ps.filter((q) => q.dependsOn !== null).length,
					salvasAqui: ps.filter((q) => !q.rascunho).length
				};
			})
			.filter((s) => !termo || s.perguntas.length > 0)
	);

	let formularioVazio = $derived(perguntas.length === 0 && extras.length === 0);
	let secoesLivres = $derived(secoes.filter((s) => !secoesUsadas.includes(s)));
	let statusSalvo = $derived(operacoes > 0 ? 'salvando' : jaSalvou ? 'Salvo agora' : 'Todas as alterações salvas');

	function linhaDois(q: PerguntaLocal): string {
		const opcoes = q.type === 'sim_nao' ? [] : textosDasOpcoes(q);
		return opcoes.length ? `${nomeDoTipo(q.type)} · ${opcoes.join(' · ')}` : nomeDoTipo(q.type);
	}

	/** Clique fora do cartão aberto vale como "Concluir". Botões e campos de fora já cuidam do cartão sozinhos. */
	const NAO_CONCLUI = '.cartao-aberto, .fm-fundo, .fm-toast, [data-menu], button, a, input, select, textarea, label';

	function cliqueFora(e: MouseEvent) {
		// O caminho do clique é guardado na hora: o botão clicado pode sumir da tela
		// antes deste aviso chegar (ex.: "Desanexar pergunta", "×" de uma opção).
		const caminho = e.composedPath().filter((el): el is Element => el instanceof Element);
		if (!caminho.some((el) => el.matches('[data-menu]'))) {
			menuSecao = null;
			menuMover = false;
		}
		if (ed && !modal && !salvandoCartao && !caminho.some((el) => el.matches(NAO_CONCLUI))) concluir();
	}

	function teclaGlobal(e: KeyboardEvent) {
		if (e.key === 'Escape' && !modal) {
			menuSecao = null;
			menuMover = false;
		}
	}

	let alvoExclusao = $derived.by(() => {
		const m = modal;
		if (m?.tipo !== 'excluir') return undefined;
		return perguntas.find((x) => x.id === m.id);
	});
</script>

<svelte:head>
	<title>{titulo} | Formulários | Painel da Comunidade</title>
</svelte:head>

<svelte:window onclick={cliqueFora} onkeydown={teclaGlobal} />

<div class="fm">
	{#if estado === 'carregando'}
		<section class="fm-cartao fm-centro" role="status">
			<span class="fm-spinner"></span>
			<p class="fm-sub">Carregando o formulário...</p>
		</section>
	{:else if estado === 'erro'}
		<section class="fm-cartao fm-centro" role="alert">
			<span class="fm-erro-icone">!</span>
			<p class="fm-centro-titulo">Não foi possível carregar o formulário.</p>
			<p class="fm-sub">Verifique sua conexão com a internet e tente novamente.</p>
			<div class="acoes-linha">
				<a class="fm-btn fm-btn--contorno fm-btn--grande" href="/admin/formularios">Voltar para a lista</a>
				<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Tentar novamente</button>
			</div>
		</section>
	{:else if tipo && esteFormulario}
		<CabecalhoFormulario
			{categoryId}
			categoria={esteFormulario.categoria}
			{tipo}
			{titulo}
			aba="perguntas"
			perguntas={salvas.length}
			respostas={pode('respostas_formularios') ? esteFormulario.respostas : null}
		>
			{#snippet lado()}
				<span class="status" role="status">
					{#if !podeEditar}
						<span class="selo-leitura">Só leitura</span>
					{:else if statusSalvo === 'salvando'}
						<span class="fm-spinner fm-spinner--pequeno"></span>Salvando...
					{:else}
						<span class="status-ok">✓</span>{statusSalvo}
					{/if}
				</span>
			{/snippet}
		</CabecalhoFormulario>

		{#if uso && salvas.length > 0}
			<div class="fm-aviso fm-aviso--azul">
				<span>
					<strong>Em uso no {uso.nome}.</strong> Prazo dos {quemEnvia(tipo)}: {diaEMes(uso.prazo)} · {uso.enviados} de
					{uso.total} já enviaram. As mudanças valem na hora: uma pergunta nova aparece em branco para quem já enviou.
				</span>
			</div>
		{:else if uso}
			<div class="fm-aviso fm-aviso--ambar">
				<span>
					<strong>O {uso.nome} está aberto e este formulário está vazio,</strong> então nenhum dos {uso.total}
					{quemEnvia(tipo)} perde a vaga pelo prazo. Ao salvar a primeira pergunta, o prazo de {diaEMes(uso.prazo)} passa a valer.
				</span>
			</div>
		{/if}

		{#if formularioVazio}
			<section class="fm-cartao fm-centro vazio">
				<div class="vazio-arco"><img src={logoAsas} alt="" /></div>
				<p class="vazio-titulo">Este formulário ainda não tem perguntas.</p>
				<p class="fm-sub vazio-texto">
					Enquanto estiver vazio, ninguém perde a vaga pelo prazo.{#if fontesDeImportacao.length && podeEditar}
						O jeito mais rápido é copiar as perguntas de outro formulário e ajustar.{/if}
				</p>
				{#if podeEditar}
				<div class="acoes-linha">
					{#if fontesDeImportacao.length}
						<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={abrirImportar}>
							Importar de outro formulário
						</button>
					{/if}
					<button
						type="button"
						class="fm-btn fm-btn--grande"
						class:fm-btn--ouro={!fontesDeImportacao.length}
						class:fm-btn--contorno={fontesDeImportacao.length > 0}
						onclick={() => secoes[0] && novaPergunta(secoes[0].id)}
					>
						Começar do zero
					</button>
				</div>
				{/if}
			</section>
		{:else}
			<div class="grade">
				<!-- ─── Esquerda: busca e seções ─── -->
				<nav class="lateral-esq" aria-label="Seções deste formulário">
					<label class="busca">
						<span>Buscar pergunta</span>
						<input class="fm-campo busca-campo" type="search" bind:value={busca} placeholder="Ex: alergia" />
					</label>
					{#if termo}
						<div class="busca-resultado">
							<span><strong>{encontradas}</strong> {encontradas === 1 ? 'encontrada' : 'encontradas'}</span>
							<button type="button" class="fm-link" onclick={() => (busca = '')}>Limpar</button>
						</div>
					{/if}
					<div class="indice">
						<span class="fm-rotulo indice-titulo">Seções</span>
						{#each secoesNaTela as s (s.secao.id)}
							<a
								class="indice-item"
								href="#sec-{s.secao.id}"
								onclick={(e) => {
									e.preventDefault();
									mostrarSecao(s.secao.id);
								}}
							>
								<span>{s.secao.titulo}</span><span class="contador">{s.salvasAqui}</span>
							</a>
						{/each}
					</div>
					<p class="nota-obrigatoria">
						Todas as perguntas são obrigatórias, menos as marcadas <strong>Opcional</strong>.
					</p>
				</nav>

				<!-- ─── Centro: seções e perguntas ─── -->
				<div class="centro">
					{#each secoesNaTela as s (s.secao.id)}
						{@const usos = formulariosQueUsam(s.secao.id).length}
						<div class="secao" id="sec-{s.secao.id}">
							<div class="secao-cabecalho">
								<div class="secao-faixa"></div>
								<div class="secao-conteudo">
									<div class="secao-textos">
										<span class="fm-rotulo">Seção {s.indice} de {secoesUsadas.length}</span>
										<h2 class="fm-h2">{s.secao.titulo}</h2>
										<span class="secao-info">
											{#if s.total > 0}
												{s.total} {s.total === 1 ? 'pergunta' : 'perguntas'}{#if s.condicionais > 0}{` · ${s.condicionais} ${
														s.condicionais === 1 ? 'condicional' : 'condicionais'
													}`}{/if}
											{:else}
												Sem perguntas
											{/if}
										</span>
									</div>
									<div class="ancora" data-menu hidden={!podeEditar}>
										<button
											type="button"
											class="fm-btn"
											aria-haspopup="menu"
											aria-expanded={menuSecao === s.secao.id}
											onclick={() => {
												menuSecao = menuSecao === s.secao.id ? null : s.secao.id;
												menuMover = false;
											}}
										>
											Opções da seção ▾
										</button>
										{#if menuSecao === s.secao.id}
											<div class="fm-menu menu-secao" role="menu">
												<button
													type="button"
													role="menuitem"
													class="fm-menu-item"
													onclick={() => {
														menuSecao = null;
														erroModal = '';
														modal = { tipo: 'renomear', secaoId: s.secao.id, nome: s.secao.titulo };
													}}
												>
													Renomear…
													<span class="fm-menu-nota">
														Muda o nome {usos > 1 ? `nos ${usos} formulários que usam` : 'em todos os formulários que usarem'} esta seção.
													</span>
												</button>
												<button
													type="button"
													role="menuitem"
													class="fm-menu-item"
													disabled={s.indice === 1}
													onclick={() => moverSecao(s.secao.id, -1)}
												>
													Subir seção
													<span class="fm-menu-nota">A ordem das seções vale para todos os formulários.</span>
												</button>
												<button
													type="button"
													role="menuitem"
													class="fm-menu-item"
													disabled={s.indice === secoesUsadas.length}
													onclick={() => moverSecao(s.secao.id, 1)}
												>
													Descer seção
												</button>
												<button
													type="button"
													role="menuitem"
													class="fm-menu-item fm-menu-item--perigo"
													disabled={s.salvasAqui === 0}
													onclick={async () => {
														menuSecao = null;
														if (!(await fecharAberta())) return;
														erroModal = '';
														modal = { tipo: 'excluirSecao', secaoId: s.secao.id };
													}}
												>
													Excluir as perguntas desta seção…
													<span class="fm-menu-nota">Só neste formulário. Dá para desfazer logo depois.</span>
												</button>
												<button
													type="button"
													role="menuitem"
													class="fm-menu-item fm-menu-item--perigo"
													disabled={s.total > 0}
													onclick={() => tirarDoFormulario(s.secao.id)}
												>
													Tirar deste formulário
													<span class="fm-menu-nota">
														{s.total > 0
															? 'Só dá para tirar quando a seção estiver sem perguntas aqui.'
															: 'A seção continua existindo para os outros formulários.'}
													</span>
												</button>
											</div>
										{/if}
									</div>
								</div>
							</div>

							{#each s.perguntas as q (q.id)}
								{@const pai = paiDe(q)}
								{@const recuada = ehFilhaNaSecao(q)}
								{#if aberta === q.id && ed}
									<!-- ─── Cartão aberto ─── -->
									<div class="cartao-aberto" class:recuada>
										{#if ed.dependsOn !== null}
											{@const paiEd = paiDe(ed)}
											<div class="condicao">
												<strong>Aparece só se</strong>
												<select
													class="condicao-campo condicao-pai"
													aria-label="Pergunta de que esta depende"
													value={paiEd?.id}
													onchange={(e) => mudarPai(Number(e.currentTarget.value))}
												>
													{#each paisPossiveis(ed) as p (p.id)}
														<option value={p.id}>{p.text}</option>
													{/each}
												</select>
												<span>for</span>
												<select
													class="condicao-campo"
													aria-label="Opção que mostra esta pergunta"
													value={ed.dependsOn}
													onchange={(e) => ed && (ed.dependsOn = Number(e.currentTarget.value))}
												>
													{#each (paiEd?.opcoes ?? []).filter((o) => o.id !== null) as o (o.id)}
														<option value={o.id}>{o.text}</option>
													{/each}
												</select>
												<button
													type="button"
													class="desanexar"
													title="A pergunta deixa de depender da resposta e passa a aparecer sempre."
													onclick={() => ed && (ed.dependsOn = null)}
												>
													Desanexar pergunta
												</button>
											</div>
										{/if}

										<div class="aberto-linha">
											<label class="campo-rotulado">
												<span class="fm-rotulo">Pergunta</span>
												<input
													id="texto-{q.id}"
													class="fm-campo campo-pergunta"
													bind:value={ed.text}
													placeholder="Escreva a pergunta"
												/>
											</label>
											<label class="campo-rotulado">
												<span class="fm-rotulo">Tipo de resposta</span>
												<select class="fm-campo" value={ed.type} onchange={(e) => mudarTipo(e.currentTarget.value)}>
													{#each TIPOS_PERGUNTA as t}
														<option value={t.value}>{t.label}</option>
													{/each}
												</select>
											</label>
										</div>

										{#if ehEscolha(ed.type)}
											<div class="opcoes">
												<span class="fm-rotulo">Opções</span>
												{#each ed.opcoes as opcao, i}
													<div class="opcao">
														<span class="opcao-marca" aria-hidden="true">
															{ed.type === 'lista_suspensa' ? `${i + 1}.` : ed.type === 'multipla_escolha' ? '□' : '○'}
														</span>
														<input
															id="opcao-{q.id}-{i}"
															class="opcao-campo"
															bind:value={opcao.text}
															placeholder="Opção {i + 1}"
															aria-label="Opção {i + 1}"
														/>
														<button type="button" class="opcao-remover" aria-label="Remover Opção {i + 1}" onclick={() => removerOpcao(i)}>×</button>
													</div>
												{/each}
												<button type="button" class="fm-link recuo-opcao" onclick={adicionarOpcao}>+ Adicionar opção</button>
												{#if textosDasOpcoes(ed).length < 2}
													<span class="fm-erro-linha recuo-opcao" role="alert">Perguntas de escolha precisam de pelo menos 2 opções.</span>
												{/if}
											</div>
										{:else if ed.type === 'sim_nao'}
											<div class="sim-nao">
												<span class="fm-rotulo">Opções</span>
												<span class="pilula">○ Sim</span>
												<span class="pilula">○ Não</span>
												<span class="sim-nao-nota">Criadas pelo sistema, não dá para editar.</span>
											</div>
										{/if}

										{#if ed.respostas > 0 && ehEscolha(ed.type) && !ed.rascunho}
											<div class="fm-aviso fm-aviso--ambar fm-aviso--pequeno">
												<span>
													<strong>{ed.respostas} {ed.respostas === 1 ? 'pessoa já respondeu' : 'pessoas já responderam'}.</strong>
													Corrigir o texto de uma opção atualiza as respostas antigas. Remover uma opção apaga essa escolha de quem a marcou.
												</span>
											</div>
										{/if}

										{#if podeSerPai(ed) && !ed.rascunho && ed.dependsOn === null}
											{@const filhas = filhosDe(q)}
											{@const opcoesSalvas = ed.opcoes.filter((o) => o.id !== null && o.text.trim())}
											<div class="dependentes">
												<span class="fm-rotulo">Perguntas que dependem desta resposta</span>
												{#each filhas as filha (filha.id)}
													<span>↳ <strong>{filha.text}</strong> · aparece se marcar <strong>{opcaoQueMostra(filha)}</strong></span>
												{:else}
													<span class="dependentes-nota">
														Nenhuma. Use para pedir detalhes só de quem marcar uma opção, como “Qual?” depois de “Tem alergia?”.
													</span>
												{/each}
												{#if opcoesSalvas.length}
													<div class="dependentes-acao">
														<button type="button" class="fm-btn botao-filha" disabled={salvandoCartao} onclick={novaFilha}>
															+ Pergunta que aparece se marcar
														</button>
														<select
															class="condicao-campo"
															aria-label="Opção que mostra a nova pergunta"
															value={opcaoDaFilha ?? opcoesSalvas[0].id}
															onchange={(e) => (opcaoDaFilha = Number(e.currentTarget.value))}
														>
															{#each opcoesSalvas as o (o.id)}
																<option value={o.id}>{o.text}</option>
															{/each}
														</select>
													</div>
												{/if}
											</div>
										{/if}

										{#if erroCartao}
											<p class="fm-erro-linha" role="alert">{erroCartao}</p>
										{/if}

										<div class="aberto-rodape">
											<div class="rodape-esq">
												<button type="button" class="fm-btn" disabled={ed.rascunho || salvandoCartao} onclick={duplicar}>Duplicar</button>
												<div class="ancora" data-menu>
													<button
														type="button"
														class="fm-btn"
														aria-haspopup="menu"
														aria-expanded={menuMover}
														onclick={() => {
															menuMover = !menuMover;
															menuSecao = null;
														}}
													>
														Mover ▾
													</button>
													{#if menuMover}
														<div class="fm-menu menu-mover" role="menu">
															<button type="button" role="menuitem" class="fm-menu-item" onclick={() => moverUmaPosicao(q.id, -1)}>
																Subir uma posição
															</button>
															<button type="button" role="menuitem" class="fm-menu-item" onclick={() => moverUmaPosicao(q.id, 1)}>
																Descer uma posição
															</button>
															{#if recuada}
																<span class="fm-menu-nota menu-nota-solta">
																	Uma pergunta condicional fica sempre junto da pergunta de que depende.
																</span>
															{:else}
																<span class="fm-rotulo fm-menu-titulo">Mover para a seção</span>
																{#each secoes.filter((x) => x.id !== q.secaoId) as destino}
																	<button
																		type="button"
																		role="menuitem"
																		class="fm-menu-item"
																		onclick={() => moverParaSecao(q.id, destino.id)}
																	>
																		{destino.titulo}
																	</button>
																{/each}
																{#if filhosDe(q).length > 0}
																	<span class="fm-menu-nota menu-nota-solta">
																		{filhosDe(q).length === 1
																			? 'A pergunta condicional vai junto.'
																			: `As ${filhosDe(q).length} perguntas condicionais vão junto.`}
																	</span>
																{/if}
															{/if}
														</div>
													{/if}
												</div>
												<button type="button" class="fm-btn fm-btn--perigo" disabled={salvandoCartao} onclick={pedirExclusao}>Excluir</button>
											</div>
											<div class="rodape-dir">
												<button
													type="button"
													role="switch"
													aria-checked={ed.is_required}
													class="chave"
													onclick={() => ed && (ed.is_required = !ed.is_required)}
												>
													<span class="chave-trilho" class:chave-trilho--on={ed.is_required}><span class="chave-bola"></span></span>
													Obrigatória
												</button>
												<button type="button" class="fm-btn fm-btn--ouro concluir" disabled={salvandoCartao} onclick={concluir}>
													{#if salvandoCartao}<span class="fm-spinner fm-spinner--pequeno"></span>Salvando...{:else}Concluir{/if}
												</button>
											</div>
										</div>
									</div>
								{:else}
									<!-- ─── Cartão fechado ─── -->
									<div class="fechada" class:recuada>
										{#if pai}
											<span class="selo-condicao">↳ Aparece se “{pai.text}” = {opcaoQueMostra(q)}</span>
										{/if}
										<!-- svelte-ignore a11y_no_static_element_interactions -->
										<div
											class="cartao-fechado"
											class:cartao-fechado--alvo={alvoArraste === q.id}
											class:cartao-fechado--arrastando={arrastando === q.id}
											ondragover={(e) => {
												if (arrastando !== null && podeSoltarEm(q)) {
													e.preventDefault();
													alvoArraste = q.id;
												}
											}}
											ondragleave={() => alvoArraste === q.id && (alvoArraste = null)}
											ondrop={(e) => {
												e.preventDefault();
												const de = arrastando;
												arrastando = null;
												alvoArraste = null;
												if (de !== null) moverPara(de, q.id);
											}}
										>
											{#if podeEditar}
											<button
												type="button"
												id="alca-{q.id}"
												class="alca"
												draggable="true"
												aria-label="Arrastar para reordenar: {q.text || 'Pergunta sem texto'}"
												title="Arraste para reordenar (ou use Alt+↑ e Alt+↓)"
												ondragstart={(e) => {
													arrastando = q.id;
													e.dataTransfer?.setData('text/plain', String(q.id));
													const cartao = (e.currentTarget as HTMLElement).parentElement;
													if (cartao) e.dataTransfer?.setDragImage(cartao, 20, 20);
												}}
												ondragend={() => {
													arrastando = null;
													alvoArraste = null;
												}}
												onkeydown={(e) => teclaNaAlca(e, q.id)}
											>
												⋮⋮
											</button>
											{/if}
											<button type="button" class="abrir" aria-disabled={!podeEditar} onclick={() => abrir(q.id)}>
												<span class="icone-tipo" aria-hidden="true">{iconeDoTipo(q.type)}</span>
												<span class="fechado-textos">
													<span class="fechado-pergunta">{q.text || 'Pergunta sem texto'}</span>
													<span class="fechado-linha2">{linhaDois(q)}</span>
												</span>
												{#if !q.is_required}<span class="selo-opcional">Opcional</span>{/if}
												{#if q.rascunho}<span class="selo-rascunho">Não salva</span>{/if}
											</button>
										</div>
									</div>
								{/if}
							{/each}

							{#if s.total === 0}
								<p class="secao-vazia">Nenhuma pergunta nesta seção ainda.</p>
							{/if}
							{#if !termo && podeEditar}
								<button type="button" class="fm-btn fm-btn--tracejado mais-pergunta" onclick={() => novaPergunta(s.secao.id)}>
									+ Pergunta em {s.secao.titulo}
								</button>
							{/if}
						</div>
					{/each}
					{#if termo && secoesNaTela.length === 0}
						<p class="secao-vazia">Nenhuma pergunta com “{busca.trim()}”.</p>
					{/if}
				</div>

				<!-- ─── Direita: adicionar ─── -->
				{#if !podeEditar}
					<div class="lateral-dir">
						<span class="fm-rotulo">Só leitura</span>
						<span class="lateral-nota">
							Você pode ver as perguntas e as respostas, mas só quem tem a permissão “Formulários” muda as perguntas.
						</span>
					</div>
				{:else}
				<div class="lateral-dir">
					<span class="fm-rotulo">Adicionar</span>
					<button type="button" class="fm-btn fm-btn--ouro" onclick={() => novaPergunta(secaoAtual())}>+ Pergunta</button>
					<button
						type="button"
						class="fm-btn"
						onclick={() => {
							novaSecaoNome = '';
							erroModal = '';
							modal = { tipo: 'secao' };
						}}
					>
						+ Seção
					</button>
					<button
						type="button"
						class="fm-btn"
						disabled={!fontesDeImportacao.length}
						title={fontesDeImportacao.length ? undefined : 'Nenhum outro formulário tem perguntas ainda.'}
						onclick={abrirImportar}
					>
						Importar perguntas
					</button>
					<span class="lateral-nota">“+ Pergunta” entra na seção da pergunta aberta.</span>
				</div>
				{/if}
			</div>
		{/if}
	{/if}
</div>

<!-- ─── Janelas ─── -->
{#if modal?.tipo === 'excluir' && alvoExclusao}
	{@const filhas = filhosDe(alvoExclusao)}
	<Janela idTitulo="t-excluir" faixa="linear-gradient(90deg,#9b2c2c,#b69e55)" travada={ocupadoModal} onfechar={() => (modal = null)}>
		<h2 id="t-excluir" class="fm-janela-titulo">Excluir a pergunta “{alvoExclusao.text}”?</h2>
		<div class="fm-janela-alvo">
			<strong>{alvoExclusao.text}</strong>
			<span>{nomeDoTipo(alvoExclusao.type)} · {nomeDaSecao(alvoExclusao.secaoId)} · {titulo}</span>
		</div>
		<ul class="fm-janela-lista">
			{#each filhas as filha}
				<li>A pergunta condicional <strong>“{filha.text}”</strong> sai junto.</li>
			{/each}
			{#if alvoExclusao.respostas > 0}
				<li>
					As respostas de {alvoExclusao.respostas}
					{alvoExclusao.respostas === 1 ? 'pessoa' : 'pessoas'} a esta pergunta deixam de aparecer.
				</li>
			{/if}
			<li>Quem ainda não enviou não vai mais ver esta pergunta.</li>
			<li>Logo depois de excluir, dá para desfazer.</li>
		</ul>
		{#if erroModal}<p class="fm-erro-linha" role="alert">{erroModal}</p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial disabled={ocupadoModal} onclick={() => (modal = null)}>
				Cancelar
			</button>
			<button type="button" class="fm-btn fm-btn--perigo-cheio fm-btn--grande" disabled={ocupadoModal} onclick={confirmarExclusao}>
				{#if ocupadoModal}<span class="fm-spinner fm-spinner--pequeno"></span>Excluindo...{:else}{filhas.length
						? `Excluir as ${filhas.length + 1} perguntas`
						: 'Excluir pergunta'}{/if}
			</button>
		</div>
	</Janela>
{/if}

{#if modal?.tipo === 'excluirSecao'}
	{@const saem = saemComASecao(modal.secaoId)}
	{@const condicionais = saem.filter((q) => q.dependsOn !== null).length}
	{@const responderam = Math.max(0, ...saem.map((q) => q.respostas))}
	{@const nomeSecao = nomeDaSecao(modal.secaoId)}
	{@const outros = formulariosQueUsam(modal.secaoId).filter((f) => f !== titulo)}
	<Janela idTitulo="t-excluir-secao" faixa="linear-gradient(90deg,#9b2c2c,#b69e55)" travada={ocupadoModal} onfechar={() => (modal = null)}>
		<h2 id="t-excluir-secao" class="fm-janela-titulo">Excluir as perguntas de “{nomeSecao}”?</h2>
		<div class="fm-janela-alvo">
			<strong>{nomeSecao}</strong>
			<span>
				{saem.length} {saem.length === 1 ? 'pergunta' : 'perguntas'}{condicionais > 0
					? ` (${condicionais} ${condicionais === 1 ? 'condicional' : 'condicionais'})`
					: ''} · {titulo}
			</span>
		</div>
		<ul class="fm-janela-lista">
			<li>Todas as perguntas desta seção saem <strong>só deste formulário</strong>.</li>
			{#if responderam > 0}
				<li>As respostas de até {responderam} {responderam === 1 ? 'pessoa' : 'pessoas'} a estas perguntas deixam de aparecer.</li>
			{/if}
			{#if uso && saem.length === salvas.length}
				<li>
					O formulário fica vazio: enquanto estiver assim, <strong>ninguém perde a vaga pelo prazo</strong> do {uso.nome}.
				</li>
			{/if}
			<li>
				A seção continua existindo{outros.length ? ` em ${outros.join(', ')}` : ' para os outros formulários'}.
			</li>
			<li>Logo depois de excluir, dá para desfazer.</li>
		</ul>
		{#if erroModal}<p class="fm-erro-linha" role="alert">{erroModal}</p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial disabled={ocupadoModal} onclick={() => (modal = null)}>
				Cancelar
			</button>
			<button type="button" class="fm-btn fm-btn--perigo-cheio fm-btn--grande" disabled={ocupadoModal} onclick={confirmarExclusaoDaSecao}>
				{#if ocupadoModal}<span class="fm-spinner fm-spinner--pequeno"></span>Excluindo...{:else}{saem.length === 1
						? 'Excluir a pergunta'
						: `Excluir as ${saem.length} perguntas`}{/if}
			</button>
		</div>
	</Janela>
{/if}

{#if modal?.tipo === 'primeira' && uso && tipo}
	<Janela idTitulo="t-primeira" largura={620} faixa="linear-gradient(90deg,#c08a1e,#b69e55)" travada={ocupadoModal} onfechar={() => (modal = null)}>
		<h2 id="t-primeira" class="fm-janela-titulo">Ativar o prazo deste formulário?</h2>
		<div class="fm-janela-alvo">
			<strong>{titulo}</strong>
			<span>Em uso no {uso.nome} · {uso.total} {quemEnvia(tipo)} confirmados</span>
		</div>
		<ul class="fm-janela-lista">
			<li>Hoje o formulário está vazio, então ninguém perde a vaga pelo prazo.</li>
			<li>Ao salvar esta primeira pergunta, os {uso.total} {quemEnvia(tipo)} passam a ter que enviar até <strong>{diaEMes(uso.prazo)}</strong>.</li>
			<li><strong class="texto-perigo">Quem não enviar até lá perde a vaga.</strong></li>
		</ul>
		<p class="fm-sub janela-nota">
			Eles vão ver o formulário do jeito que estiver na hora. O ideal é montar todas as perguntas antes de abrir o acampamento.
		</p>
		{#if erroCartao}<p class="fm-erro-linha" role="alert">{erroCartao}</p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial disabled={ocupadoModal} onclick={() => (modal = null)}>
				Voltar sem salvar
			</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" disabled={ocupadoModal} onclick={confirmarPrimeira}>
				{#if ocupadoModal}<span class="fm-spinner fm-spinner--pequeno"></span>Salvando...{:else}Salvar e ativar o prazo{/if}
			</button>
		</div>
	</Janela>
{/if}

{#if modal?.tipo === 'secao'}
	<Janela idTitulo="t-secao" largura={560} travada={ocupadoModal} onfechar={() => (modal = null)}>
		<h2 id="t-secao" class="fm-janela-titulo">Adicionar seção</h2>
		<div class="janela-bloco">
			<span class="fm-rotulo">Seções que este formulário ainda não usa</span>
			{#each secoesLivres as secao}
				<button type="button" class="secao-livre" onclick={() => adicionarSecaoExistente(secao)}>
					{secao.titulo}<span>Adicionar</span>
				</button>
			{:else}
				<span class="fm-sub">Este formulário já usa todas as seções.</span>
			{/each}
		</div>
		<form
			class="janela-bloco janela-bloco--separado"
			onsubmit={(e) => {
				e.preventDefault();
				criarSecaoNova();
			}}
		>
			<label class="janela-bloco" for="nova-secao">
				<span class="fm-rotulo">Ou crie uma seção nova</span>
			</label>
			<div class="nova-secao">
				<input id="nova-secao" class="fm-campo" bind:value={novaSecaoNome} placeholder="Ex: Transporte" />
				<button type="submit" class="fm-btn fm-btn--ouro fm-btn--grande" disabled={!novaSecaoNome.trim() || ocupadoModal}>Criar seção</button>
			</div>
			<span class="janela-nota-pequena">
				A seção nova fica disponível para todos os formulários. Se já existir uma com o mesmo nome, ela é reaproveitada.
			</span>
		</form>
		{#if erroModal}<p class="fm-erro-linha" role="alert">{erroModal}</p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno" data-foco-inicial onclick={() => (modal = null)}>Fechar</button>
		</div>
	</Janela>
{/if}

{#if modal?.tipo === 'renomear'}
	{@const usos = formulariosQueUsam(modal.secaoId)}
	<Janela idTitulo="t-renomear" largura={580} travada={ocupadoModal} onfechar={() => (modal = null)}>
		<h2 id="t-renomear" class="fm-janela-titulo">Renomear seção</h2>
		<form
			class="janela-bloco"
			onsubmit={(e) => {
				e.preventDefault();
				salvarRenomear();
			}}
		>
			<label class="janela-bloco">
				<span class="rotulo-campo">Nome da seção</span>
				<input class="fm-campo" bind:value={modal.nome} data-foco-inicial />
			</label>
		</form>
		<div class="fm-aviso fm-aviso--ambar fm-aviso--pequeno">
			<span>
				Esta seção é compartilhada. O nome novo aparece em todos os formulários que a usam{#if usos.length}: <strong>{usos.join(', ')}</strong>{/if}.
			</span>
		</div>
		{#if erroModal}<p class="fm-erro-linha" role="alert">{erroModal}</p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" disabled={ocupadoModal} onclick={() => (modal = null)}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande botao-salvar" disabled={ocupadoModal || !modal.nome.trim()} onclick={salvarRenomear}>
				{#if ocupadoModal}<span class="fm-spinner fm-spinner--pequeno"></span>Salvando...{:else}Salvar{/if}
			</button>
		</div>
	</Janela>
{/if}

{#if modal?.tipo === 'importar' && tipo}
	<ImportarPerguntas
		{categoryId}
		{tipo}
		{titulo}
		fontes={fontesDeImportacao}
		ativaPrazo={uso && salvas.length === 0 ? { total: uso.total, quem: quemEnvia(tipo), prazo: diaEMes(uso.prazo) } : null}
		onfechar={() => (modal = null)}
		onimportado={importado}
	/>
{/if}

{#if toast}
	<div class="fm-toast" role="status">
		<span>{toast}</span>
		{#if toastDesfazer}
			<button type="button" class="fm-toast-acao" onclick={toastDesfazer}>Desfazer</button>
		{/if}
		<button type="button" class="fm-toast-fechar" aria-label="Fechar aviso" onclick={fecharAviso}>×</button>
	</div>
{/if}

<style>
	.acoes-linha {
		display: flex;
		gap: 10px;
	}

	/* ─── Topo ─── */
	.status {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 15px;
		color: var(--tinta-3);
	}

	.status-ok {
		color: #2e7d4f;
		font-weight: 700;
	}

	.abrir[aria-disabled='true'] {
		cursor: default;
	}

	.selo-leitura {
		padding: 4px 12px;
		font-size: 14px;
		font-weight: 700;
		color: var(--tinta-3);
		background: var(--opcional-bg);
		border-radius: 999px;
	}

	.contador {
		font-size: 14px;
		font-weight: 700;
		color: var(--icone-tipo-tx);
		background: var(--icone-tipo-bg);
		border-radius: 999px;
		padding: 1px 9px;
	}

	/* ─── Formulário vazio ─── */
	.vazio {
		padding: 56px 24px 60px;
	}

	.vazio-arco {
		width: 110px;
		height: 120px;
		box-sizing: border-box;
		border: 1px solid #cdb878;
		border-bottom: none;
		border-radius: 50% 50% 0 0 / 40% 40% 0 0;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.vazio-arco img {
		width: 100px;
		opacity: 0.5;
	}

	.vazio-titulo {
		margin: 0;
		font: 600 32px 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	/* ─── Grade de 3 colunas ─── */
	.grade {
		display: grid;
		grid-template-columns: 212px minmax(0, 1fr) 176px;
		gap: 24px;
		align-items: start;
	}

	.lateral-esq {
		position: sticky;
		top: 20px;
		display: flex;
		flex-direction: column;
		gap: 14px;
	}

	.busca {
		display: flex;
		flex-direction: column;
		gap: 6px;
		font-size: 15px;
		font-weight: 600;
	}

	.busca-campo {
		height: 46px;
		font-size: 16px;
	}

	.busca-resultado {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		font-size: 15px;
		color: var(--tinta-3);
	}

	.busca-resultado strong {
		color: var(--tinta);
	}

	.indice {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding-top: 4px;
		border-top: 1px solid var(--borda-suave);
	}

	.indice-titulo {
		padding: 8px 10px 4px;
	}

	.indice-item {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		padding: 9px 10px;
		border-radius: 8px;
		font-size: 16px;
		color: var(--tinta);
		text-decoration: none;
	}

	.indice-item:hover {
		background: var(--cabecalho-tabela);
		color: var(--azul);
	}

	.nota-obrigatoria {
		margin: 0;
		padding: 12px 10px 0;
		border-top: 1px solid var(--borda-suave);
		font-size: 15px;
		line-height: 1.4;
		color: var(--tinta-3);
	}

	.nota-obrigatoria strong {
		color: var(--tinta);
	}

	.lateral-dir {
		position: sticky;
		top: 20px;
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 14px;
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 14px;
		box-shadow: 0 14px 28px -20px rgba(15, 29, 61, 0.45);
	}

	.lateral-dir .fm-btn {
		height: 44px;
	}

	.lateral-nota {
		margin-top: 4px;
		font-size: 14px;
		line-height: 1.35;
		color: var(--tinta-3);
	}

	.centro {
		display: flex;
		flex-direction: column;
		gap: 28px;
		min-width: 0;
	}

	/* ─── Seção ─── */
	.secao {
		display: flex;
		flex-direction: column;
		gap: 10px;
		scroll-margin-top: 16px;
	}

	.secao-cabecalho {
		position: relative;
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 14px;
		box-shadow: 0 14px 28px -24px rgba(15, 29, 61, 0.45);
	}

	.secao-faixa {
		height: 6px;
		border-radius: 14px 14px 0 0;
		background: var(--vitral);
	}

	.secao-conteudo {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 16px;
		padding: 14px 20px 16px;
	}

	.secao-textos {
		display: flex;
		flex-direction: column;
		gap: 3px;
	}

	.secao-info {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.ancora {
		position: relative;
		flex: 0 0 auto;
	}

	.menu-secao {
		right: 0;
		top: 48px;
		width: 300px;
	}

	.menu-mover {
		left: 0;
		bottom: 50px;
		width: 290px;
		max-height: 420px;
		overflow: auto;
	}

	.menu-nota-solta {
		padding: 4px 12px 8px;
	}

	.secao-vazia {
		margin: 0;
		padding: 18px;
		text-align: center;
		font-size: 16px;
		color: var(--tinta-3);
		border: 1px dashed #cdb878;
		border-radius: 12px;
	}

	.mais-pergunta {
		align-self: flex-start;
	}

	/* ─── Cartão fechado ─── */
	.fechada {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.recuada {
		margin-left: 40px;
	}

	.selo-condicao {
		align-self: flex-start;
		font-size: 14px;
		font-weight: 600;
		color: var(--selo-cond-tx);
		background: var(--selo-cond-bg);
		border: 1px solid var(--selo-cond-bd);
		border-radius: 999px;
		padding: 2px 10px;
	}

	.cartao-fechado {
		display: flex;
		align-items: stretch;
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}

	.cartao-fechado:hover {
		border-color: #cdb878;
		box-shadow: 0 10px 22px -18px rgba(15, 29, 61, 0.5);
	}

	.cartao-fechado--alvo {
		border-color: var(--ouro);
		box-shadow: 0 -3px 0 0 var(--ouro);
	}

	.cartao-fechado--arrastando {
		opacity: 0.5;
	}

	.alca {
		flex: 0 0 34px;
		border: none;
		border-radius: 12px 0 0 12px;
		background: transparent;
		cursor: grab;
		color: #8a8c99;
		font-size: 17px;
		font-weight: 700;
		letter-spacing: -3px;
	}

	.alca:hover {
		background: var(--cabecalho-tabela);
		color: var(--tinta-3);
	}

	.abrir {
		flex: 1;
		display: flex;
		align-items: center;
		gap: 14px;
		min-width: 0;
		padding: 13px 18px 13px 2px;
		text-align: left;
		background: transparent;
		border: none;
		border-radius: 0 12px 12px 0;
		cursor: pointer;
	}

	.icone-tipo {
		flex: 0 0 34px;
		height: 34px;
		border-radius: 8px;
		background: var(--icone-tipo-bg);
		color: var(--icone-tipo-tx);
		font-size: 13px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.fechado-textos {
		flex: 1;
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}

	.fechado-pergunta {
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta);
	}

	.fechado-linha2 {
		font-size: 15px;
		color: var(--tinta-3);
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	.selo-opcional {
		flex: 0 0 auto;
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.06em;
		text-transform: uppercase;
		color: var(--tinta-3);
		background: var(--opcional-bg);
		border-radius: 999px;
		padding: 3px 10px;
	}

	.selo-rascunho {
		flex: 0 0 auto;
		font-size: 13px;
		font-weight: 700;
		color: var(--aviso-tx);
		background: var(--aviso-bg);
		border: 1px solid var(--aviso-bd);
		border-radius: 999px;
		padding: 3px 10px;
	}

	/* ─── Cartão aberto ─── */
	.cartao-aberto {
		display: flex;
		flex-direction: column;
		gap: 16px;
		padding: 20px 22px;
		background: #ffffff;
		border: 1px solid var(--ouro);
		border-radius: 14px;
		box-shadow: var(--cartao-aberto-anel), 0 22px 40px -24px rgba(15, 29, 61, 0.55);
	}

	.condicao {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		padding: 10px 14px;
		background: #eef1f7;
		border: 1px solid var(--selo-cond-bd);
		border-radius: 10px;
		font-size: 16px;
		color: var(--selo-cond-tx);
	}

	.condicao-campo {
		height: 42px;
		max-width: 180px;
		padding: 0 10px;
		font: 600 15px 'Source Sans 3', sans-serif;
		color: var(--tinta);
		background: #ffffff;
		border: 1.5px solid var(--campo-borda);
		border-radius: 8px;
	}

	.condicao-pai {
		max-width: 200px;
	}

	.desanexar {
		margin-left: auto;
		padding: 6px 4px;
		border: none;
		border-radius: 6px;
		background: transparent;
		font-size: 15px;
		font-weight: 600;
		color: var(--azul);
		text-decoration: underline;
		cursor: pointer;
	}

	.aberto-linha {
		display: grid;
		grid-template-columns: minmax(0, 1fr) 230px;
		gap: 12px;
	}

	.campo-rotulado {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.campo-pergunta {
		font-size: 18px;
		font-weight: 600;
	}

	select.fm-campo {
		padding: 0 12px;
		font-weight: 500;
	}

	.opcoes {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.opcao {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.opcao-marca {
		flex: 0 0 22px;
		text-align: center;
		font-size: 17px;
		color: var(--ouro-texto);
	}

	.opcao-campo {
		flex: 1;
		height: 44px;
		box-sizing: border-box;
		padding: 0 12px;
		font: 400 17px 'Source Sans 3', system-ui, sans-serif;
		color: var(--tinta);
		background: #ffffff;
		border: 1px solid var(--borda);
		border-radius: 8px;
	}

	.opcao-campo:focus {
		outline: none;
		border-color: var(--azul);
		box-shadow: 0 0 0 4px var(--anel-foco);
	}

	.opcao-remover {
		flex: 0 0 44px;
		height: 44px;
		border: 1px solid transparent;
		border-radius: 8px;
		background: transparent;
		font-size: 22px;
		color: var(--tinta-3);
		cursor: pointer;
	}

	.opcao-remover:hover {
		background: #f6e2df;
		color: var(--vermelho);
	}

	.recuo-opcao {
		align-self: flex-start;
		margin-left: 32px;
	}

	.fm-link.recuo-opcao {
		font-size: 16px;
	}

	.sim-nao {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
	}

	.pilula {
		font-size: 16px;
		color: var(--tinta-2);
		background: var(--marfim);
		border: 1px solid var(--borda-suave);
		border-radius: 999px;
		padding: 4px 14px;
	}

	.sim-nao-nota {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.dependentes {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 14px 16px;
		background: var(--cabecalho-tabela);
		border: 1px solid var(--linha);
		border-radius: 10px;
		font-size: 16px;
		color: var(--tinta-2);
	}

	.dependentes strong {
		color: var(--tinta);
	}

	.dependentes-nota {
		font-size: 15px;
		line-height: 1.4;
		color: var(--tinta-3);
	}

	.dependentes-acao {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}

	.botao-filha {
		height: 42px;
		color: var(--azul);
		background: #ffffff;
		border: 1.5px solid var(--azul);
	}

	.botao-filha:hover:not(:disabled) {
		background: #eef1f7;
	}

	.vazio-texto {
		max-width: 560px;
	}

	.aberto-rodape {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		flex-wrap: wrap;
		padding-top: 14px;
		border-top: 1px solid var(--linha);
	}

	.rodape-esq {
		display: flex;
		gap: 6px;
		align-items: center;
	}

	.rodape-dir {
		display: flex;
		align-items: center;
		gap: 16px;
	}

	.chave {
		display: flex;
		align-items: center;
		gap: 10px;
		min-height: 42px;
		padding: 6px 2px;
		background: transparent;
		border: none;
		border-radius: 8px;
		cursor: pointer;
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
	}

	.chave-trilho {
		position: relative;
		width: 46px;
		height: 26px;
		border-radius: 999px;
		background: var(--chave-off);
		transition: background 0.15s;
	}

	.chave-trilho--on {
		background: var(--chave-on);
	}

	.chave-bola {
		position: absolute;
		top: 3px;
		left: 3px;
		width: 20px;
		height: 20px;
		border-radius: 50%;
		background: #ffffff;
		box-shadow: 0 1px 3px rgba(15, 29, 61, 0.4);
		transition: left 0.15s;
	}

	.chave-trilho--on .chave-bola {
		left: 23px;
	}

	.concluir {
		height: 44px;
		padding: 0 22px;
		font-size: 16px;
	}

	/* ─── Janelas ─── */
	.janela-bloco {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.janela-bloco--separado {
		padding-top: 16px;
		border-top: 1px solid var(--borda-suave);
	}

	.secao-livre {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		min-height: 52px;
		padding: 0 16px;
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta);
		background: #ffffff;
		border: 1px solid var(--borda-suave);
		border-radius: 10px;
		cursor: pointer;
		text-align: left;
	}

	.secao-livre:hover {
		border-color: var(--ouro);
		background: var(--cabecalho-tabela);
	}

	.secao-livre span {
		font-size: 15px;
		color: var(--azul);
	}

	.nova-secao {
		display: flex;
		gap: 8px;
	}

	.nova-secao .fm-btn {
		flex: 0 0 auto;
	}

	.janela-nota-pequena {
		font-size: 15px;
		line-height: 1.4;
		color: var(--tinta-3);
	}

	.janela-nota {
		font-size: 16px;
	}

	.rotulo-campo {
		font-size: 16px;
		font-weight: 600;
	}

	.texto-perigo {
		color: #842424;
	}

	.botao-salvar {
		min-width: 140px;
	}
</style>
