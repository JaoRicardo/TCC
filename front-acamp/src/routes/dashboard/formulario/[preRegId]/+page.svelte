<script lang="ts">
	/**
	 * Formulário de inscrição do acampamento, pensado primeiro para o celular:
	 * uma etapa por vez — "Seus dados" (do cadastro), uma etapa por seção e a
	 * Revisão. As respostas ficam guardadas no aparelho (rascunho) a cada
	 * mudança, então dá para fechar a aba e continuar depois, e um envio que
	 * falha não perde nada. O servidor continua validando tudo no envio.
	 *
	 * Mirim: quem preenche é o responsável, na conta da criança — os textos
	 * falam da criança.
	 *
	 * Depois do envio vem o pagamento da taxa, que só é cobrado de quem tem vaga
	 * (suplente não paga).
	 *
	 * Pergunta de arquivo (documento, receita…): o arquivo sobe na hora em que é
	 * escolhido, e o rascunho guarda só o id que o servidor devolveu.
	 *
	 * Correção pedida pela organização: o formulário inteiro fica editável, abre
	 * na primeira seção e as perguntas marcadas aparecem em destaque.
	 */
	import { tick } from 'svelte';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { auth } from '$lib/stores/auth.svelte';
	import { loginUrl } from '$lib/redirect';
	import CabecalhoSite from '$lib/components/CabecalhoSite.svelte';
	import logoAsas from '$lib/imgs/logo-asas.png';
	import {
		getFormulario,
		salvarFormulario,
		iniciarPagamento,
		enviarArquivo,
		TAMANHO_MAXIMO_ARQUIVO,
		TIPOS_DE_ARQUIVO,
		type ArquivoEnviado,
		type EnvioDeArquivo,
		type FormularioData,
		type PerguntaFormulario,
		type SecaoFormulario
	} from '$lib/services/formularioService';

	type Modo = 'preenchendo' | 'enviado' | 'encerrado';
	type Rascunho = { respostas: Record<string, string>; etapa: number; salvoEm: string };

	const preRegId = $page.params.preRegId as string;
	const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

	let estado: 'inicio' | 'carregando' | 'erro' | 'pronto' = $state('inicio');
	let erroCarregar = $state('');
	let form: FormularioData | null = $state(null);
	let respostas: Record<number, string> = $state({});
	let modo: Modo = $state('preenchendo');
	let etapa = $state(0);
	let mostrarErros = $state(false);
	let enviando = $state(false);
	/** null = sem erro; '' = falha de conexão; texto = o que o servidor disse. */
	let erroEnvio: string | null = $state(null);
	let rascunhoAs = $state('');
	let pagando = $state(false);
	let erroPagamento = $state('');
	let topo: HTMLElement | undefined = $state();
	/** Respostas como estavam no servidor: com correção pedida, mostra o que a pessoa já atualizou. */
	let originais: Record<number, string> = $state({});
	/** Acabou de reenviar depois da correção (tela "Correção enviada!"). */
	let correcaoEnviada = $state(false);

	// ─── Entrar e carregar ───────────────────────────────────────────────
	$effect(() => {
		if (auth.isLoading || estado !== 'inicio') return;
		if (!auth.isAuthenticated) {
			goto(loginUrl($page.url.pathname), { replaceState: true });
			return;
		}
		carregar();
	});

	async function carregar() {
		estado = 'carregando';
		try {
			const res = await getFormulario(preRegId);
			if (res.error || !res.data) {
				erroCarregar = res.error || 'Não foi possível carregar o formulário.';
				estado = 'erro';
				return;
			}
			aplicar(res.data);
			estado = 'pronto';
		} catch {
			erroCarregar = 'Não foi possível carregar o formulário. Verifique sua conexão.';
			estado = 'erro';
		}
	}

	function aplicar(dados: FormularioData) {
		form = dados;
		const doServidor: Record<number, string> = {};
		const guardados: Record<number, ArquivoEnviado | null> = {};
		for (const s of dados.sections) {
			for (const q of s.questions) {
				// Arquivo: vale o que está guardado no sistema (mesmo antes do envio do formulário).
				doServidor[q.id] = q.type === 'arquivo' ? (q.arquivo ? String(q.arquivo.id) : '') : (q.answer ?? '');
				if (q.type === 'arquivo') guardados[q.id] = q.arquivo;
			}
		}
		respostas = doServidor;
		originais = { ...doServidor };
		arquivos = guardados;
		envios = {};
		mostrarErros = false;

		if (!dados.formulario_disponivel) return;
		if (!dados.editavel) {
			modo = 'encerrado';
			etapa = dados.sections.length + 1;
			return;
		}
		const rascunho = lerRascunho();
		// Rascunho mais novo que o último envio (ou sem envio): continua de onde parou.
		if (rascunho && (!dados.enviado_em || rascunho.salvoEm > dados.enviado_em)) {
			for (const [id, valor] of Object.entries(rascunho.respostas)) {
				// O arquivo não fica no aparelho: vale o que o sistema guardou.
				if (Number(id) in guardados) continue;
				respostas[Number(id)] = valor;
			}
			etapa = Math.min(Math.max(0, rascunho.etapa), dados.sections.length + 1);
			rascunhoAs = horaDe(rascunho.salvoEm);
			modo = 'preenchendo';
			return;
		}
		// Correção pedida: abre já na primeira seção, com as respostas preenchidas.
		if (dados.correcao) {
			modo = 'preenchendo';
			etapa = dados.sections.length ? 1 : 0;
			return;
		}
		modo = dados.respondido ? 'enviado' : 'preenchendo';
		etapa = 0;
	}

	// ─── Rascunho no aparelho ────────────────────────────────────────────
	let chaveRascunho = $derived(auth.user?.id ? `rascunho-formulario:${auth.user.id}:${preRegId}` : null);

	function lerRascunho(): Rascunho | null {
		if (!chaveRascunho) return null;
		try {
			const bruto = localStorage.getItem(chaveRascunho);
			return bruto ? (JSON.parse(bruto) as Rascunho) : null;
		} catch {
			return null;
		}
	}

	function guardarRascunho() {
		if (!chaveRascunho || !form?.editavel) return;
		const agora = new Date();
		try {
			const rascunho: Rascunho = { respostas: { ...respostas }, etapa, salvoEm: isoLocal(agora) };
			localStorage.setItem(chaveRascunho, JSON.stringify(rascunho));
			rascunhoAs = horaDe(rascunho.salvoEm);
		} catch {
			// Navegador sem armazenamento (aba anônima, bloqueado): segue sem rascunho.
		}
	}

	function apagarRascunho() {
		if (!chaveRascunho) return;
		try {
			localStorage.removeItem(chaveRascunho);
		} catch {
			/* sem armazenamento: nada a apagar */
		}
	}

	function responder(id: number, valor: string) {
		respostas[id] = valor;
		guardarRascunho();
	}

	// ─── Perguntas visíveis (condicionais) ───────────────────────────────
	let todas = $derived.by(() => {
		if (!form) return [] as PerguntaFormulario[];
		return form.sections.flatMap((s) => s.questions);
	});
	let donoDaOpcao = $derived.by(() => {
		const mapa = new Map<number, PerguntaFormulario>();
		for (const q of todas) for (const o of q.options) mapa.set(o.id, q);
		return mapa;
	});

	function marcadas(valor: string | undefined): number[] {
		const v = String(valor ?? '').trim();
		if (!v) return [];
		if (/^\d+$/.test(v)) return [Number(v)];
		try {
			const lista = JSON.parse(v);
			return Array.isArray(lista) ? lista.map(Number) : [];
		} catch {
			return [];
		}
	}

	/** Condicional aparece logo que a opção da pai é marcada; some (e não é enviada) se desmarcar. */
	function visivel(q: PerguntaFormulario, vistas = new Set<number>()): boolean {
		if (!q.dependsOnOptionId) return true;
		if (vistas.has(q.id)) return true;
		vistas.add(q.id);
		const pai = donoDaOpcao.get(q.dependsOnOptionId);
		if (!pai) return true; // opção que não existe mais: mostra
		return visivel(pai, vistas) && marcadas(respostas[pai.id]).includes(q.dependsOnOptionId);
	}

	const ehFilha = (q: PerguntaFormulario) => !!q.dependsOnOptionId && donoDaOpcao.has(q.dependsOnOptionId);
	const visiveisDa = (s: SecaoFormulario) => s.questions.filter((q) => visivel(q));
	const vazia = (q: PerguntaFormulario) => {
		const v = String(respostas[q.id] ?? '').trim();
		return v === '' || v === '[]';
	};
	const faltandoNa = (s: SecaoFormulario) => visiveisDa(s).filter((q) => q.isRequired && vazia(q));
	const ehEscolha = (q: PerguntaFormulario) => ['sim_nao', 'unica_escolha', 'multipla_escolha', 'lista_suspensa'].includes(q.type);

	function textoDaResposta(q: PerguntaFormulario): string {
		const v = String(respostas[q.id] ?? '').trim();
		if (!v) return '';
		if (q.type === 'arquivo') return arquivos[q.id] ? `Arquivo enviado: ${arquivos[q.id]!.nome}` : '';
		if (ehEscolha(q)) {
			const ids = marcadas(v);
			return q.options
				.filter((o) => ids.includes(o.id))
				.map((o) => o.text)
				.join(', ');
		}
		if (q.type === 'data' && /^\d{4}-\d{2}-\d{2}$/.test(v)) {
			const [a, m, d] = v.split('-');
			return `${d}/${m}/${a}`;
		}
		return v;
	}

	// ─── Etapas ──────────────────────────────────────────────────────────
	let secoes = $derived.by(() => {
		if (!form) return [] as SecaoFormulario[];
		return form.sections;
	});
	let totalEtapas = $derived(secoes.length + 2);
	let ehRevisao = $derived(etapa === secoes.length + 1);
	let secaoAtual = $derived(etapa >= 1 && etapa <= secoes.length ? secoes[etapa - 1] : null);
	let faltando = $derived(secaoAtual ? faltandoNa(secaoAtual) : []);
	let comErro = $derived(new Set(mostrarErros ? faltando.map((q) => q.id) : []));

	let ehMirim = $derived.by(() => {
		if (!form) return false;
		return form.atividade?.categoria === 'Mirim';
	});
	let nomeDaEtapa = $derived(etapa === 0 ? (ehMirim ? 'Dados da criança' : 'Seus dados') : ehRevisao ? 'Revisão' : (secaoAtual?.title ?? ''));

	async function irPara(n: number) {
		etapa = n;
		mostrarErros = false;
		guardarRascunho();
		await tick();
		topo?.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	// ─── Perguntas de arquivo ────────────────────────────────────────────
	type Envio = {
		estado: 'enviando' | 'erro';
		nome: string;
		pct: number;
		/** grande/formato: escolher outro; conexao: tentar de novo com o mesmo arquivo. */
		erro?: 'grande' | 'formato' | 'conexao' | 'servidor';
		mensagem?: string;
		arquivo?: File;
		envio?: EnvioDeArquivo;
	};
	/** O arquivo guardado no sistema para cada pergunta de arquivo. */
	let arquivos: Record<number, ArquivoEnviado | null> = $state({});
	/** Envio em andamento ou com erro, por pergunta. */
	let envios: Record<number, Envio> = $state({});
	/** Perguntas cujo arquivo foi enviado nesta visita (as outras mostram "já está guardado"). */
	let enviadosAgora: number[] = $state([]);
	let arrastandoEm: number | null = $state(null);
	let algumEnviando = $derived(Object.values(envios).some((e) => e.estado === 'enviando'));

	const mb = (bytes: number) => `${(bytes / 1024 / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
	const tamanhoLegivel = (bytes: number) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : mb(bytes));
	const extensao = (a: ArquivoEnviado) => (a.tipo === 'pdf' ? 'PDF' : (a.nome.split('.').pop() ?? 'JPG').toUpperCase().slice(0, 4));

	function escolherArquivo(q: PerguntaFormulario, arquivo: File | undefined) {
		if (!arquivo) return;
		if (!TIPOS_DE_ARQUIVO.includes(arquivo.type)) {
			envios[q.id] = {
				estado: 'erro',
				nome: arquivo.name,
				pct: 0,
				erro: 'formato',
				mensagem: `Este tipo de arquivo não é aceito (${arquivo.name}). Envie uma foto (JPG, PNG ou WebP) ou um PDF.`
			};
			return;
		}
		// Confere o tamanho aqui, antes de gastar a internet da pessoa.
		if (arquivo.size > TAMANHO_MAXIMO_ARQUIVO) {
			envios[q.id] = {
				estado: 'erro',
				nome: arquivo.name,
				pct: 0,
				erro: 'grande',
				mensagem: `O arquivo tem ${mb(arquivo.size)} e o limite é 5 MB. Tire outra foto ou envie um PDF menor.`
			};
			return;
		}
		subir(q, arquivo);
	}

	async function subir(q: PerguntaFormulario, arquivo: File) {
		const envio = enviarArquivo(preRegId, q.id, arquivo, (pct) => {
			if (envios[q.id]?.estado === 'enviando') envios[q.id].pct = pct;
		});
		envios[q.id] = { estado: 'enviando', nome: arquivo.name, pct: 0, arquivo, envio };
		const r = await envio.resultado;
		if (r.data) {
			arquivos[q.id] = r.data;
			delete envios[q.id];
			if (!enviadosAgora.includes(q.id)) enviadosAgora = [...enviadosAgora, q.id];
			responder(q.id, String(r.data.id));
		} else if (r.cancelado) {
			delete envios[q.id];
		} else if (r.conexao) {
			envios[q.id] = {
				estado: 'erro',
				nome: arquivo.name,
				pct: 0,
				erro: 'conexao',
				mensagem: 'Não foi possível enviar o arquivo. Verifique a internet e tente de novo.',
				arquivo
			};
		} else {
			envios[q.id] = { estado: 'erro', nome: arquivo.name, pct: 0, erro: 'servidor', mensagem: r.error };
		}
	}

	function removerArquivo(q: PerguntaFormulario) {
		arquivos[q.id] = null;
		responder(q.id, '');
	}

	function soltarArquivo(q: PerguntaFormulario, e: DragEvent) {
		e.preventDefault();
		arrastandoEm = null;
		escolherArquivo(q, e.dataTransfer?.files?.[0]);
	}

	// ─── Correção pedida pela organização ────────────────────────────────
	let correcao = $derived.by(() => {
		if (!form) return null;
		return form.correcao;
	});
	let idsMarcados = $derived(new Set(correcao?.perguntas ?? []));
	const marcadaParaCorrigir = (q: PerguntaFormulario) => idsMarcados.has(q.id);
	const atualizada = (q: PerguntaFormulario) => String(respostas[q.id] ?? '').trim() !== String(originais[q.id] ?? '').trim();
	/** As perguntas marcadas, na ordem do formulário. */
	let perguntasMarcadas = $derived(todas.filter((q) => idsMarcados.has(q.id)));
	/** Etapa (seção) da primeira pergunta marcada. */
	let etapaDaPrimeiraMarcada = $derived.by(() => {
		const i = secoes.findIndex((s) => s.questions.some((q) => idsMarcados.has(q.id)));
		return i >= 0 ? i + 1 : null;
	});

	async function irParaOQueCorrigir() {
		if (etapaDaPrimeiraMarcada === null) return;
		await irPara(etapaDaPrimeiraMarcada);
		const primeira = perguntasMarcadas.find((q) => secoes[etapaDaPrimeiraMarcada! - 1]?.questions.includes(q));
		if (primeira) document.getElementById(`q-${primeira.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}

	async function proxima() {
		if (secaoAtual && faltando.length) {
			mostrarErros = true;
			await tick();
			document.getElementById('resumo-erros')?.focus();
			return;
		}
		if (ehRevisao) {
			await enviar();
			return;
		}
		await irPara(etapa + 1);
	}

	// ─── Envio ───────────────────────────────────────────────────────────
	async function enviar() {
		if (!form || algumEnviando) return;
		// Garante que nenhuma etapa ficou com obrigatória em branco (ex.: rascunho antigo).
		const comFalta = secoes.findIndex((s) => faltandoNa(s).length > 0);
		if (comFalta >= 0) {
			await irPara(comFalta + 1);
			mostrarErros = true;
			return;
		}
		enviando = true;
		erroEnvio = null;
		const visiveis = todas.filter((q) => visivel(q));
		const payload = visiveis.map((q) => ({ question_id: q.id, answer: String(respostas[q.id] ?? '').trim() }));
		try {
			const res = await salvarFormulario(preRegId, payload);
			if (!res.success) {
				erroEnvio = res.error ?? '';
				return;
			}
			correcaoEnviada = !!res.correcaoEnviada;
			apagarRascunho();
			const novo = await getFormulario(preRegId);
			if (novo.data) aplicar(novo.data);
			modo = 'enviado';
			await tick();
			window.scrollTo({ top: 0, behavior: 'smooth' });
		} catch {
			erroEnvio = '';
		} finally {
			enviando = false;
		}
	}

	function verRespostas() {
		modo = 'preenchendo';
		etapa = secoes.length + 1;
	}

	async function pagar() {
		pagando = true;
		erroPagamento = '';
		try {
			const res = await iniciarPagamento(preRegId);
			if (res.url) {
				window.location.href = res.url;
				return;
			}
			erroPagamento = res.error || 'Não foi possível iniciar o pagamento.';
		} catch {
			erroPagamento = 'Não foi possível iniciar o pagamento. Verifique sua conexão.';
		}
		pagando = false;
	}


	// ─── Textos e datas ──────────────────────────────────────────────────
	function isoLocal(d: Date): string {
		const p = (n: number) => String(n).padStart(2, '0');
		return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
	}
	const horaDe = (iso: string) => iso.slice(11, 16);
	const diaMes = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`;
	/** "2026-11-20T23:59" → "20/11, às 23h59" */
	const diaEHora = (iso: string) => `${diaMes(iso)}, às ${iso.slice(11, 13)}h${iso.slice(14, 16)}`;
	const valorEmReais = (v: number) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

	/** "27 a 29 de novembro · Chácara Recanto da Paz" */
	let infoDoAcampamento = $derived.by(() => {
		const a = form?.atividade;
		if (!a) return '';
		const partes: string[] = [];
		if (a.inicio) {
			const [ai, mi, di] = a.inicio.slice(0, 10).split('-').map(Number);
			const fim = a.fim ?? a.inicio.slice(0, 10);
			const [af, mf, df] = fim.split('-').map(Number);
			partes.push(
				di === df && mi === mf
					? `${di} de ${MESES[mi - 1]}`
					: mi === mf && ai === af
						? `${di} a ${df} de ${MESES[mf - 1]}`
						: `${di} de ${MESES[mi - 1]} a ${df} de ${MESES[mf - 1]}`
			);
		}
		if (a.local) partes.push(a.local);
		return partes.join(' · ');
	});

	/** Evento: o formulário faz parte da inscrição, que só conclui no envio. */
	let ehEvento = $derived.by(() => {
		if (!form) return false;
		return form.eh_evento;
	});

	let textoDoPrazo = $derived.by(() => {
		const prazo = form?.prazo;
		if (!prazo) return '';
		if (form?.correcao) {
			return prazo.vencido
				? `O prazo da correção terminou em ${diaMes(prazo.limite)}.`
				: `Corrija até ${diaEHora(prazo.limite)}. Se não corrigir até lá, a inscrição é recusada e você perde a vaga.`;
		}
		if (ehEvento) {
			if (prazo.vencido) return `As inscrições terminaram em ${diaMes(prazo.limite)}.`;
			if (form?.respondido) return `Inscrição concluída. Dá para alterar as respostas até ${diaEHora(prazo.limite)}.`;
			return `Responda para concluir sua inscrição. As inscrições vão até ${diaEHora(prazo.limite)}.`;
		}
		if (prazo.vencido) return `O prazo terminou em ${diaMes(prazo.limite)}. As respostas não podem mais ser alteradas.`;
		if (form?.respondido) return `Dá para alterar as respostas até ${diaEHora(prazo.limite)}.`;
		if (prazo.eh_suplente) return `Envie até ${diaEHora(prazo.limite)}: só é chamado para uma vaga quem já enviou.`;
		return `Envie até ${diaEHora(prazo.limite)}. Quem não enviar até lá perde a vaga.`;
	});

	let rotuloDoEnvio = $derived.by(() => {
		if (ehRevisao && erroEnvio !== null) return 'Tentar de novo';
		if (ehRevisao && form?.correcao) return 'Reenviar formulário';
		if (ehRevisao) return ehEvento && form && !form.respondido ? 'Enviar e concluir inscrição' : 'Enviar formulário';
		return etapa === 0 ? 'Está tudo certo, continuar' : 'Próxima etapa →';
	});

	function tipoDoCampo(q: PerguntaFormulario): { type: string; inputmode: 'text' | 'tel' | 'decimal' } {
		if (q.type === 'data') return { type: 'date', inputmode: 'text' };
		if (q.type === 'numero') return { type: 'text', inputmode: 'decimal' };
		if (/(telefone|celular|whats)/i.test(q.text)) return { type: 'tel', inputmode: 'tel' };
		return { type: 'text', inputmode: 'text' };
	}

	const mensagemDeErro = (q: PerguntaFormulario) =>
		q.type === 'arquivo' ? 'Envie o arquivo.' : ehEscolha(q) ? 'Escolha uma opção.' : 'Responda esta pergunta.';

	function alternarMultipla(q: PerguntaFormulario, opcaoId: number) {
		const atual = marcadas(respostas[q.id]);
		const nova = atual.includes(opcaoId) ? atual.filter((x) => x !== opcaoId) : [...atual, opcaoId];
		responder(q.id, nova.length ? JSON.stringify(nova) : '');
	}

	function formatarData(d?: string | null) {
		if (!d) return '—';
		const [y, m, day] = d.split('-');
		return `${day}/${m}/${y}`;
	}

	function idadeDe(nascimento: string | null): string {
		if (!nascimento) return '';
		const [a, m, d] = nascimento.split('-').map(Number);
		const hoje = new Date();
		let idade = hoje.getFullYear() - a;
		if (hoje.getMonth() + 1 < m || (hoje.getMonth() + 1 === m && hoje.getDate() < d)) idade--;
		return ` · ${idade} anos`;
	}
</script>

<svelte:head>
	<title>{form?.atividade?.nome ?? 'Formulário de inscrição'} | Formulário</title>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link
		href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap"
		rel="stylesheet"
	/>
</svelte:head>

{#snippet campo(q: PerguntaFormulario)}
	{@const erro = comErro.has(q.id)}
	{@const idErro = `erro-${q.id}`}
	{@const corrigir = marcadaParaCorrigir(q)}
	<div
		class="pergunta"
		class:pergunta--filha={ehFilha(q)}
		class:pergunta--corrigir={corrigir && !atualizada(q)}
		class:pergunta--corrigida={corrigir && atualizada(q)}
		id="q-{q.id}"
	>
		{#if corrigir}
			<span class="selo-corrigir" class:selo-corrigir--ok={atualizada(q)}>
				{atualizada(q) ? '✓ Resposta atualizada' : 'Corrigir esta resposta'}
			</span>
		{/if}
		{#if q.type === 'arquivo'}
			{@const guardado = arquivos[q.id]}
			{@const envio = envios[q.id]}
			<span class="rotulo" id="rotulo-{q.id}">
				{q.text}{#if !q.isRequired}<span class="opcional">{' (opcional)'}</span>{/if}
			</span>
			{#if envio?.estado === 'enviando'}
				<div class="arquivo arquivo--enviando" role="status">
					<span class="arquivo-nome">{envio.nome}</span>
					<span class="arquivo-progresso-texto">Enviando… <span class="numeros">{envio.pct}%</span></span>
					<span class="arquivo-barra"><span style="width:{envio.pct}%"></span></span>
					<button type="button" class="link-acao" onclick={() => envio.envio?.cancelar()}>Cancelar envio</button>
				</div>
			{:else if envio?.estado === 'erro'}
				<div class="arquivo arquivo--erro" role="alert">
					<span>{envio.mensagem}</span>
					{#if envio.erro === 'conexao' && envio.arquivo}
						<button type="button" class="btn btn--contorno" onclick={() => subir(q, envio.arquivo!)}>Tentar de novo</button>
					{:else}
						<label class="btn btn--contorno">
							<input
								type="file"
								class="escondido"
								accept="image/jpeg,image/png,image/webp,application/pdf"
								onchange={(e) => {
									escolherArquivo(q, e.currentTarget.files?.[0]);
									e.currentTarget.value = '';
								}}
							/>
							Escolher outro arquivo
						</label>
					{/if}
				</div>
			{:else if guardado}
				<div class="arquivo arquivo--ok">
					<div class="arquivo-linha">
						<span class="arquivo-icone" aria-hidden="true">{extensao(guardado)}</span>
						<span class="arquivo-dados">
							<span class="arquivo-nome">{guardado.nome}</span>
							<span class="arquivo-meta">
								{tamanhoLegivel(guardado.tamanho)}{guardado.enviado_em
									? ` · enviado em ${diaMes(guardado.enviado_em)} às ${horaDe(guardado.enviado_em)}`
									: ''}
							</span>
						</span>
					</div>
					{#if !enviadosAgora.includes(q.id)}
						<span class="arquivo-guardado">✓ Este arquivo já está guardado no sistema. Não precisa enviar de novo.</span>
					{/if}
					<div class="arquivo-acoes">
						<label class="btn" class:btn--ouro={corrigir} class:btn--contorno={!corrigir}>
							<input
								type="file"
								class="escondido"
								accept="image/jpeg,image/png,image/webp,application/pdf"
								aria-labelledby="rotulo-{q.id}"
								onchange={(e) => {
									escolherArquivo(q, e.currentTarget.files?.[0]);
									e.currentTarget.value = '';
								}}
							/>
							Trocar arquivo
						</label>
						{#if !q.isRequired}
							<button type="button" class="btn btn--perigo" onclick={() => removerArquivo(q)}>Remover</button>
						{/if}
					</div>
				</div>
			{:else}
				<!-- svelte-ignore a11y_no_static_element_interactions -->
				<div
					class="arquivo arquivo--vazio"
					class:com-erro={erro}
					class:arquivo--arrastando={arrastandoEm === q.id}
					ondragover={(e) => {
						e.preventDefault();
						arrastandoEm = q.id;
					}}
					ondragleave={() => (arrastandoEm = null)}
					ondrop={(e) => soltarArquivo(q, e)}
				>
					<label class="btn btn--contorno btn--arquivo">
						<input
							type="file"
							class="escondido"
							accept="image/jpeg,image/png,image/webp,application/pdf"
							aria-labelledby="rotulo-{q.id}"
							aria-invalid={erro || undefined}
							aria-describedby={erro ? idErro : undefined}
							onchange={(e) => {
								escolherArquivo(q, e.currentTarget.files?.[0]);
								e.currentTarget.value = '';
							}}
						/>
						Enviar foto ou PDF
					</label>
					<span class="arquivo-arraste">ou arraste o arquivo para cá</span>
				</div>
			{/if}
			<span class="nota nota--pequena">Foto (JPG, PNG ou WebP) ou PDF, até 5 MB.</span>
		{:else if q.type === 'sim_nao' || q.type === 'unica_escolha' || q.type === 'multipla_escolha'}
			{@const multipla = q.type === 'multipla_escolha'}
			<span class="rotulo" id="rotulo-{q.id}">
				{q.text}{#if !q.isRequired}<span class="opcional">{' (opcional)'}</span>{/if}
			</span>
			<div
				class={q.type === 'sim_nao' ? 'pilulas' : 'linhas'}
				role={multipla ? 'group' : 'radiogroup'}
				aria-labelledby="rotulo-{q.id}"
				aria-invalid={erro || undefined}
				aria-describedby={erro ? idErro : undefined}
			>
				{#each q.options as o (o.id)}
					{@const marcada = marcadas(respostas[q.id]).includes(o.id)}
					<label class={q.type === 'sim_nao' ? 'pilula' : 'linha'} class:marcada class:com-erro={erro}>
						{#if multipla}
							<input type="checkbox" class="escondido" checked={marcada} onchange={() => alternarMultipla(q, o.id)} />
							<span class="marca marca--caixa" aria-hidden="true">{marcada ? '✓' : ''}</span>
						{:else}
							<input
								type="radio"
								class="escondido"
								name="q-{q.id}"
								checked={marcada}
								onchange={() => responder(q.id, String(o.id))}
							/>
							<span class="marca" aria-hidden="true"></span>
						{/if}
						{o.text}
					</label>
				{/each}
			</div>
		{:else}
			<label class="rotulo" for="in-{q.id}">
				{q.text}{#if !q.isRequired}<span class="opcional">{' (opcional)'}</span>{/if}
			</label>
			{#if q.type === 'texto'}
				<textarea
					id="in-{q.id}"
					class="campo campo--longo"
					class:com-erro={erro}
					rows="3"
					maxlength="2000"
					value={respostas[q.id] ?? ''}
					oninput={(e) => responder(q.id, e.currentTarget.value)}
					aria-invalid={erro || undefined}
					aria-describedby={erro ? idErro : undefined}
				></textarea>
			{:else if q.type === 'lista_suspensa'}
				<select
					id="in-{q.id}"
					class="campo"
					class:com-erro={erro}
					value={respostas[q.id] ?? ''}
					onchange={(e) => responder(q.id, e.currentTarget.value)}
					aria-invalid={erro || undefined}
					aria-describedby={erro ? idErro : undefined}
				>
					<option value="">Escolha uma opção</option>
					{#each q.options as o (o.id)}
						<option value={String(o.id)}>{o.text}</option>
					{/each}
				</select>
			{:else}
				{@const tipo = tipoDoCampo(q)}
				<input
					id="in-{q.id}"
					class="campo"
					class:campo--curto={q.type === 'numero' || q.type === 'data'}
					class:com-erro={erro}
					type={tipo.type}
					inputmode={tipo.inputmode}
					value={respostas[q.id] ?? ''}
					oninput={(e) => responder(q.id, e.currentTarget.value)}
					aria-invalid={erro || undefined}
					aria-describedby={erro ? idErro : undefined}
				/>
			{/if}
		{/if}
		{#if erro}
			<span class="erro-campo" id={idErro}><span class="erro-icone" aria-hidden="true">!</span>{mensagemDeErro(q)}</span>
		{/if}
	</div>
{/snippet}

{#snippet revisao(podeEditar: boolean)}
	{#each secoes as s, i (s.id)}
		{@const itens = visiveisDa(s)}
		{#if itens.length}
			<div class="revisao-secao">
				<div class="revisao-topo">
					<h3>{s.title}</h3>
					{#if podeEditar}
						<button type="button" class="btn btn--leve" aria-label="Editar {s.title}" onclick={() => irPara(i + 1)}>Editar</button>
					{/if}
				</div>
				<dl class="revisao-lista">
					{#each itens as q (q.id)}
						{@const v = textoDaResposta(q)}
						<div class:revisao-filha={ehFilha(q)}>
							<dt>{ehFilha(q) ? '↳ ' : ''}{q.text}</dt>
							<dd class:sem-resposta={!v}>{v || (q.type === 'arquivo' ? 'Não enviado' : 'Não respondeu')}</dd>
						</div>
					{/each}
				</dl>
			</div>
		{/if}
	{/each}
{/snippet}

{#snippet pagamento()}
	{#if form?.pagamento?.necessario}
		{@const pg = form.pagamento}
		<section class="cartao pagamento">
			<div class="cartao-cabeca">
				<h2>Pagamento da inscrição</h2>
				<p class="valor">{valorEmReais(pg.valor)}</p>
			</div>
			{#if pg.pago}
				<p class="faixa faixa--ok">✓ Pagamento confirmado. A inscrição está completa!</p>
			{:else if pg.eh_suplente}
				<p class="nota">
					Suplentes não pagam a taxa. Esse é o valor para quem tem vaga: se uma vaga for liberada, o pagamento aparece aqui.
				</p>
			{:else if !form.respondido}
				<p class="nota">Envie o formulário para liberar o pagamento.</p>
			{:else if pg.prazo_vencido}
				<p class="faixa faixa--erro">
					O prazo para pagar terminou{pg.prazo ? ` em ${diaMes(pg.prazo)}` : ''}. Procure a organização.
				</p>
			{:else}
				{#if pg.prazo}<p class="nota">Pague até {diaEHora(pg.prazo)}.</p>{/if}
				{#if erroPagamento}<p class="faixa faixa--erro" role="alert">{erroPagamento}</p>{/if}
				{#if pg.integracao_ativa}
					<p class="nota">
						Você vai para o Mercado Pago, onde dá para pagar por Pix, cartão ou boleto. A confirmação chega aqui sozinha.
					</p>
					<button type="button" class="btn btn--ouro btn--largo" onclick={pagar} disabled={pagando}>
						{pagando ? 'Abrindo o Mercado Pago...' : 'Pagar com Mercado Pago'}
					</button>
					{#if pg.status_provedor === 'pending' && pg.url_pagamento}
						<p class="nota">Já existe um pagamento em aberto: o botão abre o mesmo.</p>
					{/if}
				{:else if pg.link_manual}
					<p class="nota">Use o link de pagamento informado pela organização:</p>
					<a class="btn btn--ouro btn--largo" href={pg.link_manual} target="_blank" rel="noopener noreferrer">Abrir link de pagamento</a>
				{:else}
					<p class="nota">O pagamento online ainda está sendo configurado. Assim que for liberado, o botão aparece aqui.</p>
				{/if}
			{/if}
		</section>
	{/if}
{/snippet}

<div class="pagina">
	<CabecalhoSite />

	<main class="folha" bind:this={topo}>
		{#if ehEvento && form?.atividade}
			<a href="/dashboard/atividades/{form.atividade.id}" class="voltar">← Detalhes do evento</a>
		{:else}
			<a href="/dashboard/formularios" class="voltar">← Meus Formulários</a>
		{/if}

		{#if estado === 'inicio' || estado === 'carregando'}
			<section class="cartao centro" role="status">
				<span class="spinner"></span>
				<p class="nota">Carregando o formulário...</p>
			</section>
		{:else if estado === 'erro' || !form}
			<section class="cartao centro" role="alert">
				<h2>Não foi possível abrir o formulário</h2>
				<p class="nota">{erroCarregar}</p>
				<button type="button" class="btn btn--ouro" onclick={carregar}>Tentar de novo</button>
			</section>
		{:else}
			<!-- Topo em vitral -->
			<section class="vitral">
				<div class="vitral-trama"></div>
				<div class="vitral-conteudo">
					<span class="sobretitulo"
						><span class="sobretitulo-traco"></span>{ehEvento ? 'Inscrição no evento' : 'Formulário de inscrição'}</span
					>
					<h1>{form.atividade?.nome ?? 'Formulário de inscrição'}</h1>
					{#if infoDoAcampamento}<p>{infoDoAcampamento}</p>{/if}
				</div>
			</section>

			{#if !form.formulario_disponivel}
				<!-- Sem formulário agora: prazo perdido, não selecionado, desistência ou ainda não montado -->
				<section class="cartao centro">
					<div class="arco"><img src={logoAsas} alt="" /></div>
					{#if form.status === 'Prazo Perdido'}
						<h2>{form.prazo ? `O prazo terminou em ${diaMes(form.prazo.limite)}.` : 'O prazo terminou.'}</h2>
						<p class="nota nota--centro">{form.motivo_indisponivel}</p>
						<span class="selo selo--perigo"><span class="selo-ponto"></span>Prazo Perdido</span>
						<p class="nota nota--centro">Se acha que houve um engano, fale com a organização do acampamento.</p>
					{:else}
						<h2>{form.formulario_montado ? 'Formulário indisponível' : 'O formulário ainda não está pronto'}</h2>
						<p class="nota nota--centro">{form.motivo_indisponivel}</p>
						<span class="selo" class:selo--perigo={['Retirado do sorteio', 'Cancelada pela organização', 'Recusada'].includes(form.status)}
							><span class="selo-ponto"></span>{form.status}</span
						>
						{#if form.status === 'Cancelada pela organização' || form.status === 'Recusada'}
							<p class="nota nota--centro">Se tiver alguma dúvida, fale com a organização.</p>
						{/if}
					{/if}
					<a href="/dashboard/formularios" class="btn btn--leve">Voltar para Meus Formulários</a>
				</section>
			{:else}
				{#if form.prazo}
					<div class="faixa-prazo">
						<span
							class="selo"
							class:selo--aviso={['Suplente', 'Inscrição em andamento', 'Correção pedida'].includes(form.status)}><span class="selo-ponto"></span>{form.status}</span
						>
						<span>{textoDoPrazo}</span>
					</div>
				{/if}

				{#if correcao && modo === 'preenchendo'}
					<div class="aviso-correcao" role="note">
						<strong>A organização pediu uma correção{correcao.pedida_em ? ` em ${diaMes(correcao.pedida_em)}` : ''}</strong>
						<span class="aviso-correcao-motivo">“{correcao.motivo}”</span>
						<span>
							Você pode mudar qualquer resposta.{#if perguntasMarcadas.length}{' '}O que precisa de atenção: {perguntasMarcadas.map((q) => q.text).join(', ')}.{/if}{' '}
							Depois, envie de novo{correcao.prazo ? ` até ${diaMes(correcao.prazo)}` : ''}.
						</span>
						{#if etapaDaPrimeiraMarcada !== null && etapa !== etapaDaPrimeiraMarcada}
							<button type="button" class="btn btn--leve" onclick={irParaOQueCorrigir}>Ir para o que precisa corrigir</button>
						{/if}
					</div>
				{/if}

				{#if ehMirim && modo === 'preenchendo'}
					<p class="faixa faixa--info">
						<strong>Pai, mãe ou responsável:</strong> o formulário é preenchido por vocês, aqui na conta da criança. As
						perguntas são sobre a criança.
					</p>
				{/if}

				{#if modo === 'enviado'}
					<!-- ─── Enviado ─── -->
					<section class="cartao centro">
						<span class="check" aria-hidden="true">✓</span>
						{#if correcaoEnviada}
							<h2>Correção enviada!</h2>
							<p class="nota nota--centro">
								Recebemos o formulário corrigido. Sua situação voltou a Formulário Enviado{form.editavel && form.prazo
									? `, e você ainda pode alterar até ${diaEHora(form.prazo.limite)}`
									: ''}.
							</p>
						{:else}
							<h2>{ehEvento ? 'Inscrição concluída!' : 'Formulário enviado!'}</h2>
							<p class="nota nota--centro">
								{form.enviado_em ? `Recebemos as respostas em ${diaEHora(form.enviado_em)}.` : 'Recebemos as respostas.'}
								{#if form.editavel && form.prazo}
									Dá para alterar até {diaEHora(form.prazo.limite)}{ehEvento ? ', quando as inscrições fecham' : ''}.
								{/if}
							</p>
						{/if}
						<span class="selo"><span class="selo-ponto"></span>{ehEvento ? 'Inscrito(a)' : 'Formulário Enviado'}</span>
						<div class="acoes-centro">
							{#if form.editavel}
								<button type="button" class="btn btn--contorno" onclick={verRespostas}>Ver ou alterar respostas</button>
							{/if}
							<a href="/dashboard" class="btn btn--ouro">Voltar para o início</a>
						</div>
					</section>
					{@render pagamento()}
				{:else if modo === 'encerrado' && !form.respondido}
					<!-- ─── Evento: inscrições terminaram sem o formulário enviado ─── -->
					<section class="cartao centro">
						<div class="arco"><img src={logoAsas} alt="" /></div>
						<h2>{form.prazo ? `As inscrições terminaram em ${diaMes(form.prazo.limite)}.` : 'As inscrições terminaram.'}</h2>
						<p class="nota nota--centro">{form.motivo_indisponivel}</p>
						<span class="selo selo--perigo"><span class="selo-ponto"></span>Inscrição não concluída</span>
						<p class="nota nota--centro">Se acha que houve um engano, fale com a organização do evento.</p>
					</section>
				{:else if modo === 'encerrado'}
					<!-- ─── Prazo encerrado depois de enviar: só leitura ─── -->
					<section class="cartao">
						<div class="cartao-cabeca">
							<h2>{ehMirim ? 'Respostas sobre a criança' : 'Suas respostas'}</h2>
							<p class="nota">
								{form.enviado_em ? `Enviadas em ${diaEHora(form.enviado_em)}.` : 'O prazo terminou e as respostas não podem mais ser alteradas.'}
							</p>
						</div>
						{@render revisao(false)}
					</section>
					{@render pagamento()}
				{:else}
					<!-- ─── Preenchendo ─── -->
					<div class="progresso">
						<div class="progresso-texto">
							<span><strong>Etapa {etapa + 1} de {totalEtapas}</strong> · {nomeDaEtapa}</span>
							{#if rascunhoAs}<span class="progresso-rascunho">Rascunho guardado às {rascunhoAs}</span>{/if}
						</div>
						<div
							class="segmentos"
							role="progressbar"
							aria-valuemin={1}
							aria-valuemax={totalEtapas}
							aria-valuenow={etapa + 1}
							aria-label="Progresso do formulário"
						>
							{#each Array(totalEtapas) as _, i}
								<span class="segmento" class:segmento--feito={i < etapa} class:segmento--atual={i === etapa}></span>
							{/each}
						</div>
					</div>

					<section class="cartao">
						{#if etapa === 0}
							<div class="cartao-cabeca">
								<h2>{ehMirim ? 'Confira os dados da criança' : 'Confira seus dados'}</h2>
								<p class="nota">
									{ehMirim
										? 'Eles vêm do cadastro da criança, então não precisa digitar de novo.'
										: 'Eles vêm do seu cadastro, então não precisa digitar de novo.'}
								</p>
							</div>
							<dl class="dados">
								<div class="dados-largo"><dt>Nome</dt><dd>{form.perfil.nome}</dd></div>
								<div><dt>CPF</dt><dd>{form.perfil.cpf}</dd></div>
								<div><dt>Telefone</dt><dd>{form.perfil.telefone || '—'}</dd></div>
								<div><dt>E-mail</dt><dd>{form.perfil.email || '—'}</dd></div>
								<div>
									<dt>Nascimento</dt>
									<dd>{formatarData(form.perfil.data_nascimento)}{idadeDe(form.perfil.data_nascimento)}</dd>
								</div>
								<div><dt>Sexo</dt><dd>{form.perfil.sexo === 'M' ? 'Masculino' : 'Feminino'}</dd></div>
								<div><dt>Estado civil</dt><dd>{form.perfil.estado_civil ?? '—'}</dd></div>
								{#if form.perfil.endereco}
									<div class="dados-largo">
										<dt>Endereço</dt>
										<dd>
											{form.perfil.endereco.rua}, {form.perfil.endereco.numero} · {form.perfil.endereco.bairro} ·
											{form.perfil.endereco.cidade}
										</dd>
									</div>
								{/if}
							</dl>
							<p class="nota">Algo errado ou mudou? Corrija em <a href="/dashboard/perfil">Meu cadastro</a> — as respostas ficam guardadas.</p>

						{:else if secaoAtual}
							<div class="cartao-cabeca">
								<h2>{secaoAtual.title}</h2>
								<p class="nota">Todas as perguntas são obrigatórias, menos as marcadas “opcional”.</p>
							</div>
							{#if mostrarErros && faltando.length}
								<div class="resumo-erros" id="resumo-erros" role="alert" tabindex="-1">
									<span class="erro-icone erro-icone--grande" aria-hidden="true">!</span>
									<div>
										<strong>{faltando.length === 1 ? 'Falta 1 resposta nesta etapa:' : `Faltam ${faltando.length} respostas nesta etapa:`}</strong>
										{#each faltando as q (q.id)}
											<a href="#q-{q.id}">{q.text}</a>
										{/each}
									</div>
								</div>
							{/if}
							{#each visiveisDa(secaoAtual) as q (q.id)}
								{@render campo(q)}
							{:else}
								<p class="nota">Nenhuma pergunta desta etapa vale para as suas respostas. Pode seguir.</p>
							{/each}
						{:else if ehRevisao}
							<div class="cartao-cabeca">
								<h2>Revise antes de enviar</h2>
								<p class="nota">
									Confira tudo.{#if ehEvento && !form.respondido}
										Ao enviar, sua inscrição fica concluída.{:else if form.prazo && !form.prazo.vencido}
										Depois de enviar, ainda dá para alterar até {diaEHora(form.prazo.limite)}.{/if}
								</p>
							</div>
							{#if erroEnvio !== null}
								<div class="resumo-erros" role="alert">
									<span class="erro-icone erro-icone--grande" aria-hidden="true">!</span>
									<span>
										<strong>Não foi possível enviar.</strong>
										{erroEnvio || 'Suas respostas continuam guardadas neste aparelho. Confira a internet e toque em “Tentar de novo”.'}
									</span>
								</div>
							{/if}
							{@render revisao(true)}
						{/if}

						<div class="navegacao">
							<div class="navegacao-botoes">
								{#if etapa > 0}
									<button type="button" class="btn btn--contorno" disabled={enviando} onclick={() => irPara(etapa - 1)}>← Voltar</button>
								{/if}
								<button
									type="button"
									class="btn btn--ouro"
									class:btn--largo={etapa === 0 || ehRevisao}
									disabled={enviando || (ehRevisao && algumEnviando)}
									onclick={proxima}
								>
									{#if enviando}<span class="spinner spinner--pequeno"></span>Enviando...{:else}{rotuloDoEnvio}{/if}
								</button>
							</div>
							<span class="nota nota--centro">
								{ehRevisao
									? 'As respostas ficam guardadas neste aparelho até o envio.'
									: 'Rascunho guardado neste aparelho. Pode parar e continuar depois.'}
							</span>
						</div>
					</section>
					{@render pagamento()}
				{/if}
			{/if}
		{/if}
	</main>
</div>

<style>
	.pagina {
		--ouro: #b69e55;
		--ouro-claro: #e2ce8a;
		--ouro-texto: #7a6528;
		--azul: #1b2f5e;
		--azul-noite: #0f1d3d;
		--vermelho: #9b2c2c;
		--marfim: #f7f2e7;
		--pergaminho: #fffdf8;
		--borda: #d9cca6;
		--borda-suave: #e3d8bc;
		--linha: #efe7d3;
		--tinta: #1e2233;
		--tinta-2: #2b3045;
		--tinta-3: #4f5468;
		--campo-borda: #a39363;
		--anel-foco: rgba(182, 158, 85, 0.45);
		--margem: 16px;

		min-height: 100vh;
		background: var(--marfim);
		font-family: 'Source Sans 3', system-ui, sans-serif;
		color: var(--tinta);
	}

	.pagina :is(button, a, input, select, textarea, label) {
		font-family: inherit;
		box-sizing: border-box;
	}

	.pagina :is(button, a, input, select, textarea):focus-visible,
	.pagina label:has(input:focus-visible),
	.resumo-erros:focus-visible {
		outline: none;
		box-shadow: 0 0 0 4px var(--anel-foco);
	}

	/* ─── Folha ─── */
	.folha {
		display: flex;
		flex-direction: column;
		gap: 16px;
		max-width: 760px;
		margin: 0 auto;
		padding: 16px var(--margem) 48px;
		scroll-margin-top: 8px;
	}

	.voltar {
		align-self: flex-start;
		display: flex;
		align-items: center;
		min-height: 44px;
		font-size: 16px;
		font-weight: 600;
		color: var(--azul);
		text-decoration: none;
	}

	/* ─── Vitral ─── */
	.vitral {
		position: relative;
		overflow: hidden;
		border-radius: 16px;
		background:
			radial-gradient(circle at 12% 30%, rgba(214, 186, 104, 0.35), transparent 28%),
			radial-gradient(circle at 70% 120%, rgba(31, 107, 110, 0.85), transparent 45%),
			radial-gradient(circle at 95% 0%, rgba(155, 44, 44, 0.35), transparent 30%),
			linear-gradient(150deg, #22397a 0%, #1b2f5e 45%, #0f1d3d 100%);
		box-shadow:
			inset 0 0 0 6px #0f1d3d,
			inset 0 0 0 7px rgba(182, 158, 85, 0.75);
	}

	.vitral-trama {
		position: absolute;
		inset: 0;
		background:
			repeating-linear-gradient(45deg, transparent 0 46px, rgba(8, 14, 32, 0.32) 46px 48px),
			repeating-linear-gradient(-45deg, transparent 0 46px, rgba(8, 14, 32, 0.32) 46px 48px);
		pointer-events: none;
	}

	.vitral-conteudo {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: clamp(22px, 5vw, 40px) clamp(20px, 5vw, 40px);
	}

	.sobretitulo {
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 13px;
		font-weight: 600;
		letter-spacing: 0.16em;
		text-transform: uppercase;
		color: var(--ouro-claro);
	}

	.sobretitulo-traco {
		width: 28px;
		height: 1px;
		background: var(--ouro);
	}

	.vitral h1 {
		margin: 0;
		font: 600 clamp(32px, 7vw, 48px) / 1.05 'Cormorant Garamond', serif;
		color: var(--pergaminho);
		text-wrap: balance;
	}

	.vitral p {
		margin: 0;
		font-size: 17px;
		color: #e6e9f2;
	}

	/* ─── Faixas ─── */
	.faixa-prazo {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
		padding: 12px 16px;
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 12px;
		box-shadow:
			inset 0 0 0 4px #fffdf8,
			inset 0 0 0 5px rgba(182, 158, 85, 0.35);
		font-size: 16px;
		line-height: 1.4;
		color: var(--tinta-2);
	}

	.faixa {
		margin: 0;
		padding: 12px 16px;
		border-radius: 10px;
		font-size: 16px;
		line-height: 1.45;
	}

	.faixa--info {
		color: #21376a;
		background: #e6ebf5;
		border: 1px solid #c4cfe6;
	}

	.faixa--ok {
		color: #1b5236;
		background: #e4f1e8;
		border: 1px solid #b9d9c4;
	}

	.faixa--erro {
		color: #842424;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
	}

	.selo {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		padding: 4px 10px;
		font-size: 15px;
		font-weight: 600;
		color: #1b5236;
		background: #e4f1e8;
		border: 1px solid #b9d9c4;
		border-radius: 8px;
	}

	.selo-ponto {
		width: 8px;
		height: 8px;
		border-radius: 50%;
		background: #2e7d4f;
	}

	.selo--aviso {
		color: #6a480c;
		background: #fbf0d6;
		border-color: #ebd39c;
	}

	.selo--aviso .selo-ponto {
		background: #c08a1e;
	}

	.selo--perigo {
		color: #842424;
		background: #f6e2df;
		border-color: #e6bdb6;
	}

	.selo--perigo .selo-ponto {
		background: #b33a3a;
	}

	/* ─── Progresso ─── */
	.progresso {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.progresso-texto {
		display: flex;
		justify-content: space-between;
		align-items: baseline;
		gap: 12px;
		flex-wrap: wrap;
		font-size: 15px;
		color: var(--tinta-3);
	}

	.progresso-texto strong {
		color: var(--tinta);
	}

	.segmentos {
		display: flex;
		gap: 4px;
	}

	.segmento {
		flex: 1;
		height: 8px;
		border-radius: 999px;
		background: var(--borda-suave);
	}

	.segmento--feito {
		background: var(--azul);
	}

	.segmento--atual {
		background: var(--ouro);
	}

	/* ─── Cartão ─── */
	.cartao {
		display: flex;
		flex-direction: column;
		gap: 22px;
		padding: clamp(20px, 5vw, 32px) clamp(18px, 5vw, 32px);
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 16px;
		box-shadow: 0 14px 28px -22px rgba(15, 29, 61, 0.45);
	}

	.cartao-cabeca {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.cartao h2 {
		margin: 0;
		font: 600 clamp(28px, 6vw, 34px) / 1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.nota {
		margin: 0;
		font-size: 16px;
		line-height: 1.45;
		color: var(--tinta-3);
	}

	.nota--centro {
		text-align: center;
		max-width: 480px;
	}

	.centro {
		align-items: center;
		text-align: center;
		gap: 14px;
	}

	.acoes-centro {
		display: flex;
		flex-wrap: wrap;
		justify-content: center;
		gap: 10px;
		width: 100%;
		margin-top: 8px;
	}

	.acoes-centro .btn {
		flex: 1 1 220px;
		max-width: 280px;
	}

	.check {
		width: 72px;
		height: 72px;
		border-radius: 50%;
		background: #e4f1e8;
		border: 2px solid #b9d9c4;
		color: #2e7d4f;
		font-size: 36px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.arco {
		width: 110px;
		height: 120px;
		border: 1px solid #cdb878;
		border-bottom: none;
		border-radius: 50% 50% 0 0 / 40% 40% 0 0;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.arco img {
		width: 100px;
		opacity: 0.5;
	}

	/* ─── Seus dados ─── */
	.dados {
		margin: 0;
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr));
		gap: 14px 28px;
		padding: 18px;
		background: #ffffff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}

	.dados div {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	.dados-largo {
		grid-column: 1 / -1;
	}

	.dados dt {
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}

	.dados dd {
		margin: 0;
		font-size: 17px;
		color: var(--tinta);
		overflow-wrap: anywhere;
	}

	/* ─── Campo de arquivo ─── */
	.arquivo {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 16px;
		background: #ffffff;
		border-radius: 12px;
		font-size: 16px;
	}

	.arquivo--vazio {
		align-items: center;
		padding: 20px 16px;
		text-align: center;
		border: 2px dashed #cdb878;
	}

	.arquivo--arrastando {
		background: #fbf6e6;
		border-color: var(--azul);
	}

	.arquivo--vazio.com-erro {
		border-style: dashed;
	}

	.btn--arquivo {
		position: relative;
		border-color: var(--azul);
	}

	.arquivo-arraste {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.arquivo--enviando {
		border: 1.5px solid #c4cfe6;
	}

	.arquivo-progresso-texto {
		color: var(--tinta-2);
	}

	.numeros {
		font-variant-numeric: tabular-nums;
	}

	.arquivo-barra {
		display: block;
		height: 8px;
		background: var(--borda-suave);
		border-radius: 999px;
		overflow: hidden;
	}

	.arquivo-barra span {
		display: block;
		height: 100%;
		background: var(--azul);
		transition: width 0.2s;
	}

	.link-acao {
		align-self: flex-start;
		padding: 0;
		font-size: 15px;
		font-weight: 600;
		color: var(--azul);
		background: none;
		border: none;
		text-decoration: underline;
		cursor: pointer;
	}

	.arquivo--ok {
		border: 1.5px solid #b9d9c4;
	}

	.arquivo-linha {
		display: flex;
		align-items: center;
		gap: 12px;
	}

	.arquivo-icone {
		flex: 0 0 40px;
		height: 48px;
		display: flex;
		align-items: flex-end;
		justify-content: center;
		padding-bottom: 6px;
		box-sizing: border-box;
		font-size: 11px;
		font-weight: 700;
		color: var(--azul);
		background: #eef1f7;
		border: 1.5px solid #c4cfe6;
		border-radius: 4px 12px 4px 4px;
	}

	.arquivo-dados {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}

	.arquivo-nome {
		font-weight: 600;
		color: var(--tinta);
		overflow-wrap: anywhere;
	}

	.arquivo-meta {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.arquivo-guardado {
		font-size: 15px;
		font-weight: 600;
		color: #1b5236;
	}

	.arquivo-acoes {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;
	}

	.arquivo-acoes .btn,
	.arquivo--erro .btn {
		position: relative;
		height: 44px;
		align-self: flex-start;
	}

	.btn--perigo {
		color: #842424;
		border-color: #b33a3a;
	}

	.arquivo--erro {
		color: #842424;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
	}

	.nota--pequena {
		font-size: 15px;
	}

	/* ─── Correção pedida ─── */
	.aviso-correcao {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 6px;
		padding: 14px 16px;
		font-size: 16px;
		line-height: 1.45;
		color: #6a480c;
		background: #fbf0d6;
		border: 1px solid #ebd39c;
		border-radius: 12px;
	}

	.aviso-correcao-motivo {
		font-style: italic;
		color: var(--tinta-2);
	}

	.pergunta--corrigir,
	.pergunta--corrigida {
		padding: 14px 16px;
		border-radius: 12px;
	}

	.pergunta--corrigir {
		background: #fff8e6;
		box-shadow: inset 0 0 0 1.5px #ebd39c;
	}

	.pergunta--corrigida {
		background: #f1f8f3;
		box-shadow: inset 0 0 0 1.5px #b9d9c4;
	}

	.selo-corrigir {
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

	.selo-corrigir--ok {
		color: #1b5236;
		background: #d6ecdd;
	}

	/* ─── Perguntas ─── */
	.pergunta {
		display: flex;
		flex-direction: column;
		gap: 10px;
		scroll-margin-top: 16px;
	}

	.pergunta--filha {
		margin-left: 10px;
		padding-left: 16px;
		border-left: 2px solid var(--borda-suave);
	}

	.rotulo {
		font-size: 18px;
		font-weight: 600;
		line-height: 1.35;
		color: var(--tinta);
	}

	.opcional {
		font-size: 15px;
		font-weight: 400;
		color: var(--tinta-3);
	}

	.campo {
		width: 100%;
		height: 52px;
		padding: 0 16px;
		font-size: 17px;
		color: var(--tinta);
		background: #ffffff;
		border: 1.5px solid var(--campo-borda);
		border-radius: 10px;
	}

	.campo:focus {
		border-color: var(--azul);
	}

	.campo--curto {
		max-width: 240px;
	}

	.campo--longo {
		height: auto;
		min-height: 104px;
		padding: 12px 16px;
		line-height: 1.45;
		resize: vertical;
	}

	.com-erro {
		border-color: var(--vermelho) !important;
	}

	.escondido {
		position: absolute;
		opacity: 0;
		width: 1px;
		height: 1px;
		pointer-events: none;
	}

	.pilulas {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 160px));
		gap: 10px;
	}

	.pilula {
		position: relative;
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 10px;
		height: 52px;
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta);
		background: #ffffff;
		border: 1.5px solid var(--borda);
		border-radius: 999px;
		cursor: pointer;
	}

	.pilula.marcada {
		color: var(--pergaminho);
		background: var(--azul);
		border-color: var(--azul);
	}

	.linhas {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.linha {
		position: relative;
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: 52px;
		padding: 8px 16px;
		font-size: 17px;
		font-weight: 500;
		color: var(--tinta);
		background: #ffffff;
		border: 1.5px solid var(--borda);
		border-radius: 10px;
		cursor: pointer;
	}

	.linha.marcada {
		background: #eef1f7;
		border-color: var(--azul);
	}

	.marca {
		flex: 0 0 20px;
		height: 20px;
		box-sizing: border-box;
		border-radius: 50%;
		border: 2px solid var(--campo-borda);
		background: #ffffff;
	}

	.linha .marca {
		flex-basis: 22px;
		height: 22px;
	}

	.marcada .marca {
		border-color: var(--azul);
		background: var(--azul);
		box-shadow: inset 0 0 0 4px #ffffff;
	}

	.pilula.marcada .marca {
		border-color: var(--pergaminho);
		background: var(--pergaminho);
		box-shadow: inset 0 0 0 4px var(--azul);
	}

	.marca--caixa {
		border-radius: 6px;
		color: var(--pergaminho);
		font-size: 14px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.marcada .marca--caixa {
		box-shadow: none;
	}

	.erro-campo {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 15px;
		font-weight: 600;
		color: #842424;
	}

	.erro-icone {
		flex: 0 0 20px;
		height: 20px;
		border-radius: 50%;
		background: var(--vermelho);
		color: var(--pergaminho);
		font-size: 13px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.erro-icone--grande {
		flex-basis: 26px;
		height: 26px;
		font-size: 16px;
	}

	.resumo-erros {
		display: flex;
		gap: 12px;
		align-items: flex-start;
		padding: 14px 16px;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
		border-radius: 10px;
		font-size: 16px;
		line-height: 1.45;
		color: #842424;
	}

	.resumo-erros div {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.resumo-erros a {
		color: #842424;
		font-weight: 600;
	}

	/* ─── Revisão ─── */
	.revisao-secao {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 16px 18px;
		background: #ffffff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}

	.revisao-topo {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
	}

	.revisao-topo h3 {
		margin: 0;
		font: 600 24px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.revisao-lista {
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	.revisao-lista div {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	.revisao-filha {
		padding-left: 16px;
	}

	.revisao-lista dt {
		font-size: 15px;
		font-weight: 600;
		color: var(--ouro-texto);
	}

	.revisao-lista dd {
		margin: 0;
		font-size: 17px;
		color: var(--tinta);
		overflow-wrap: anywhere;
	}

	.sem-resposta {
		color: #5e6272 !important;
	}

	/* ─── Navegação ─── */
	.navegacao {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding-top: 18px;
		border-top: 1px solid var(--linha);
	}

	.navegacao-botoes {
		display: flex;
		justify-content: space-between;
		gap: 10px;
	}

	.navegacao-botoes .btn--ouro {
		margin-left: auto;
	}

	/* ─── Botões ─── */
	.btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 8px;
		height: 52px;
		padding: 0 22px;
		font-size: 17px;
		font-weight: 600;
		color: var(--azul);
		text-decoration: none;
		background: transparent;
		border: 1.5px solid transparent;
		border-radius: 999px;
		cursor: pointer;
	}

	.btn:disabled {
		cursor: not-allowed;
		opacity: 0.6;
	}

	.btn--ouro {
		padding: 0 26px;
		font-weight: 700;
		color: var(--azul-noite);
		background: linear-gradient(180deg, #d6be72, #b69e55);
		border: 1px solid #9c8642;
		box-shadow: 0 6px 16px -8px rgba(122, 101, 40, 0.9);
	}

	.btn--ouro:hover:not(:disabled) {
		background: #b69e55;
	}

	.btn--contorno {
		border-color: var(--azul);
	}

	.btn--contorno:hover:not(:disabled) {
		background: #eef1f7;
	}

	.btn--leve {
		height: 44px;
		padding: 0 16px;
		font-size: 15px;
		background: var(--pergaminho);
		border: 1px solid var(--borda);
	}

	.btn--leve:hover {
		background: #fbf6e6;
	}

	.btn--largo {
		flex: 1;
	}

	/* ─── Pagamento ─── */
	.pagamento {
		gap: 14px;
	}

	.valor {
		margin: 0;
		font: 600 30px 'Cormorant Garamond', serif;
		color: var(--tinta);
	}

	/* ─── Carregando ─── */
	.spinner {
		width: 34px;
		height: 34px;
		border: 3px solid var(--borda-suave);
		border-top-color: var(--azul);
		border-radius: 50%;
		animation: girar 0.9s linear infinite;
	}

	.spinner--pequeno {
		width: 18px;
		height: 18px;
		border-width: 2px;
	}

	@keyframes girar {
		to {
			transform: rotate(360deg);
		}
	}

	/* ─── Computador ─── */
	@media (min-width: 720px) {
		.pagina {
			--margem: clamp(16px, 4vw, 48px);
		}

		.folha {
			padding-top: 24px;
			gap: 20px;
		}

		.navegacao-botoes .btn--largo {
			flex: 0 1 auto;
			min-width: 280px;
		}
	}
</style>
