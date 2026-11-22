<script lang="ts">
	/**
	 * Criar / Editar Evento (handoff Desing/design_handoff_painel_evento). Três
	 * abas sem passo a passo: dá para salvar de qualquer aba, e ao salvar a
	 * validação confere todas (abre a primeira com erro). Ao lado, a prévia do
	 * cartão do Início.
	 *
	 * As datas do evento não têm hora: o primeiro dia e a abertura vão às 00h00,
	 * o fechamento e o prazo do pagamento às 23h59; a duração é último − primeiro
	 * + 1. O servidor confere as mesmas regras (ActivitiesController::validarEvento).
	 * Não há link de pagamento: a pessoa paga direto com a organização.
	 */
	import { onDestroy, onMount, tick } from 'svelte';
	import { apiFetch, API_HOST } from '$lib/api';
	import { getFormulariosDeEvento, type FormularioDeEvento } from '$lib/services/perguntaService';
	import { pode } from '$lib/permissoes';
	import { caminhoDoPainel } from '$lib/stores/caminho.svelte';
	import SeloFase from '$lib/components/SeloFase.svelte';
	import { datasDaAtividade, faseDaAtividade, fraseAcontece, inscricaoAberta, TEXTO_DA_FASE } from '$lib/faseAtividade';
	import { textoDaFaixa } from '$lib/faixaEtaria';
	import logoAsas from '$lib/imgs/logo-asas.png';

	let { activityId = null }: { activityId?: string | null } = $props();
	let editando = $derived(activityId !== null);

	type Aba = 'evento' | 'datas' | 'valor';
	type Campo = 'nome' | 'local' | 'faixa' | 'ini' | 'fim' | 'abre' | 'fecha' | 'vagas' | 'valor' | 'prazo';
	type Estado = 'carregando' | 'normal' | 'salvando' | 'criado' | 'salvo' | 'erroServidor';

	const ABAS: [Aba, string][] = [
		['evento', 'O evento'],
		['datas', 'Datas e inscrições'],
		['valor', 'Valor e pagamento']
	];
	const CAMPOS_DA_ABA: Record<Aba, Campo[]> = {
		evento: ['nome', 'local', 'faixa'],
		datas: ['ini', 'fim', 'abre', 'fecha', 'vagas'],
		valor: ['valor', 'prazo']
	};
	const ROTULO: Record<Campo, string> = {
		nome: 'Nome do evento',
		local: 'Local',
		faixa: 'Faixa etária',
		ini: 'Primeiro dia',
		fim: 'Último dia',
		abre: 'Abertura',
		fecha: 'Fechamento',
		vagas: 'Total de vagas',
		valor: 'Valor por pessoa',
		prazo: 'Prazo do pagamento'
	};
	/** Valor guardado em `target_audience` → rótulo. "Geral" é o nome antigo de "Todos". */
	const PUBLICOS = ['Todos', 'Homens', 'Mulheres', 'Casais'];
	/** Campo do servidor → campo da tela, para mostrar o erro no lugar certo. */
	const CAMPO_DO_SERVIDOR: Record<string, Campo> = {
		name: 'nome',
		place: 'local',
		min_age: 'faixa',
		max_age: 'faixa',
		start_date: 'ini',
		duration_days: 'fim',
		participant_registration_start_date: 'abre',
		participant_registration_end_date: 'fecha',
		total_vacancies: 'vagas',
		participant_fee: 'valor',
		participant_payment_date: 'prazo'
	};

	const VAZIO = {
		nome: '',
		local: '',
		publico: 'Todos',
		idMin: '',
		idMax: '',
		sobre: '',
		ini: '',
		fim: '',
		abre: '',
		fecha: '',
		vagas: '',
		form: '',
		pago: false,
		valor: '',
		prazo: ''
	};

	let f = $state({ ...VAZIO });
	let aba = $state<Aba>('evento');
	let estado = $state<Estado>('normal');
	/** Os erros só aparecem depois da primeira tentativa de salvar. */
	let tentou = $state(false);
	let errosDoServidor = $state<Partial<Record<Campo, string>>>({});
	let mensagemDoServidor = $state('');
	let idCriado = $state<number | null>(null);
	let faseCriada = $state('');

	// Edição: o que já existe no evento.
	let resumo = $state({ inscricoes: 0, vagasOcupadas: 0, pagamentos: 0 });
	let formOriginal = $state('');
	let pagoOriginal = $state(false);

	// Imagem: nenhuma, nova (escolhida agora) ou a atual (edição).
	let imagem = $state<'nenhuma' | 'nova' | 'atual'>('nenhuma');
	let imagemOriginal = $state<'nenhuma' | 'atual'>('nenhuma');
	let arquivo = $state<File | null>(null);
	let previaArquivo = $state<string | null>(null);
	let imagemAtualUrl = $state<string | null>(null);
	let inputImagem = $state<HTMLInputElement>();

	let formularios: FormularioDeEvento[] = $state([]);
	let carregandoFormularios = $state(false);

	const hoje = new Date();
	const hojeDia = isoDia(hoje);

	// ─── Datas ────────────────────────────────────────────────────────────
	function isoDia(x: Date) {
		return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
	}
	const p2 = (n: number) => String(n).padStart(2, '0');
	/** "2026-11-29" → Date às 00h00 (sem fuso). */
	const paraData = (s: string) => (s ? new Date(`${s}T00:00`) : null);
	const dma = (s: string) => s.split('-').reverse().join('/');
	/** dd/mm, com o ano quando não for o atual. */
	function dmx(s: string) {
		const [a, m, d] = s.split('-');
		return Number(a) === hoje.getFullYear() ? `${d}/${m}` : `${d}/${m}/${a}`;
	}
	function maisDias(s: string, n: number) {
		const x = paraData(s)!;
		x.setDate(x.getDate() + n);
		return isoDia(x);
	}
	function diasEntre(a: string, b: string) {
		return Math.round((paraData(b)!.getTime() - paraData(a)!.getTime()) / 864e5);
	}
	/** Campo como texto: os <input type="number"> devolvem número (ou null quando vazios). */
	const txt = (v: unknown) => (v === null || v === undefined ? '' : String(v)).trim();
	const numero = (v: unknown) => (!/^\d+$/.test(txt(v)) ? null : Number(txt(v)));
	function dinheiro(v: unknown) {
		const limpo = txt(v).replace(/\./g, '').replace(',', '.');
		if (limpo === '' || isNaN(Number(limpo))) return null;
		return Number(limpo);
	}
	const brl = (v: number) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

	// ─── Validação (a mesma do servidor) ──────────────────────────────────
	function validar(): Partial<Record<Campo, string>> {
		const e: Partial<Record<Campo, string>> = {};
		if (!f.nome.trim()) e.nome = 'Informe o nome do evento.';
		if (!f.local.trim()) e.local = 'Informe o local.';
		const mi = numero(f.idMin);
		const ma = numero(f.idMax);
		if ((txt(f.idMin) && (mi === null || mi > 120)) || (txt(f.idMax) && (ma === null || ma > 120)))
			e.faixa = 'Use idades entre 0 e 120 anos.';
		else if (mi !== null && ma !== null && mi > ma) e.faixa = 'A idade mínima não pode ser maior que a máxima.';
		if (!f.ini) e.ini = 'Informe o primeiro dia do evento.';
		else if (!editando && f.ini < hojeDia) e.ini = 'O primeiro dia já passou. Escolha hoje ou uma data futura.';
		if (!f.fim) e.fim = 'Informe o último dia do evento.';
		else if (f.ini && f.fim < f.ini) e.fim = 'O último dia não pode ser antes do primeiro.';
		if (!f.abre) e.abre = 'Informe quando as inscrições abrem.';
		if (!f.fecha) e.fecha = 'Informe quando as inscrições fecham.';
		else if (f.abre && f.fecha < f.abre) e.fecha = 'O fechamento não pode ser antes da abertura.';
		else if (f.ini && f.fecha > f.ini) e.fecha = `As inscrições precisam fechar até o primeiro dia do evento (${dma(f.ini)}).`;
		const v = numero(f.vagas);
		if (v === null || v < 1) e.vagas = 'Informe quantas vagas o evento tem (pelo menos 1).';
		else if (editando && v < resumo.vagasOcupadas)
			e.vagas = `Já há ${resumo.vagasOcupadas} vagas ocupadas: o total não pode ser menor que isso.`;
		if (f.pago) {
			const val = dinheiro(f.valor);
			if (val === null || val <= 0) e.valor = 'Informe o valor por pessoa. Se não houver cobrança, escolha “Gratuito”.';
			if (!f.prazo) e.prazo = 'Informe o prazo do pagamento.';
			else if (f.ini && f.prazo > f.ini) e.prazo = `O prazo do pagamento precisa ser até o primeiro dia do evento (${dma(f.ini)}).`;
			else if (f.abre && f.prazo < f.abre) e.prazo = 'O prazo do pagamento não pode ser antes da abertura das inscrições.';
		}
		return e;
	}

	let todosOsErros = $derived(validar());
	/** O que aparece: a validação da tela e, por cima, o que o servidor recusou. */
	let erros = $derived<Partial<Record<Campo, string>>>(tentou ? { ...errosDoServidor, ...todosOsErros } : {});
	let listaDeErros = $derived((Object.keys(erros) as Campo[]).map((c) => ROTULO[c]));
	const errosDaAba = (a: Aba) => CAMPOS_DA_ABA[a].filter((c) => erros[c]).length;

	// ─── Derivados ────────────────────────────────────────────────────────
	let faixa = $derived(textoDaFaixa(numero(f.idMin), numero(f.idMax)));
	let temFormulario = $derived(!!f.form);
	let trocouFormulario = $derived(editando && f.form !== formOriginal && !!f.form);

	let frasResumo = $derived.by(() => {
		if (!f.abre || !f.fecha) return 'Preencha as datas e as vagas para ver o resumo.';
		const vagas = numero(f.vagas);
		let r = `${f.abre <= hojeDia ? `As inscrições estão abertas desde ${dmx(f.abre)}` : `As inscrições abrem em ${dmx(f.abre)}`} e vão até ${dmx(f.fecha)}${vagas ? `, ou até acabarem as ${vagas} vagas` : ''}.`;
		if (temFormulario) r += ' A vaga só fica garantida quando a pessoa envia o formulário.';
		const val = dinheiro(f.valor);
		if (f.pago && val && f.prazo) r += ` Cada pessoa paga ${brl(val)} até ${dmx(f.prazo)}; quem não paga perde a vaga.`;
		return r;
	});

	/** Fase e frase "Acontece…" da prévia, pelas datas digitadas. */
	let datasDaPrevia = $derived(
		datasDaAtividade({
			tipo: 'Evento',
			inicioEvento: f.ini ? `${f.ini}T00:00` : null,
			fimEvento: f.fim ? `${f.fim}T00:00` : f.ini ? `${f.ini}T00:00` : null,
			inicioInscricoes: f.abre ? `${f.abre}T00:00` : null,
			fimInscricoes: f.fecha ? `${f.fecha}T23:59` : null
		})
	);
	let fasePrevia = $derived(faseDaAtividade(datasDaPrevia, hoje));
	let imagemDaPrevia = $derived(imagem === 'nova' ? previaArquivo : imagem === 'atual' ? imagemAtualUrl : null);
	let valorDaPrevia = $derived.by(() => {
		if (!f.pago) return 'Gratuito';
		const val = dinheiro(f.valor);
		if (!val) return 'Pago · valor a definir';
		return `${brl(val)}${f.prazo ? ` · pagar até ${dmx(f.prazo)}` : ''}`;
	});
	let formularioDaPrevia = $derived(
		f.form ? (formularios.find((x) => String(x.id) === f.form)?.nome ?? 'Formulário escolhido') : 'Nenhum'
	);

	// ─── Carregar ─────────────────────────────────────────────────────────
	async function carregarFormularios() {
		carregandoFormularios = true;
		try {
			formularios = (await getFormulariosDeEvento()).data ?? [];
		} catch {
			formularios = [];
		} finally {
			carregandoFormularios = false;
		}
	}

	const soDia = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '');

	async function carregarEvento() {
		estado = 'carregando';
		try {
			const res = await apiFetch(`/activities/${activityId}/edit`);
			const data = await res.json();
			const a = data.data?.activity;
			if (!res.ok || !a) {
				mensagemDoServidor = data.error || 'Não foi possível carregar o evento.';
				estado = 'erroServidor';
				return;
			}
			const fee = Number(a.participant_fee ?? 0);
			const ini = soDia(a.start_date);
			f = {
				nome: a.name ?? '',
				local: a.place ?? '',
				publico: PUBLICOS.includes(a.target_audience) ? a.target_audience : 'Todos',
				idMin: a.min_age != null ? String(a.min_age) : '',
				idMax: a.max_age != null ? String(a.max_age) : '',
				sobre: a.description ?? '',
				ini,
				fim: ini ? maisDias(ini, Math.max(0, Number(a.duration_days ?? 1) - 1)) : '',
				abre: soDia(a.participant_registration_start_date),
				fecha: soDia(a.participant_registration_end_date),
				vagas: a.total_vacancies != null ? String(a.total_vacancies) : '',
				form: a.event_form_id ? String(a.event_form_id) : '',
				pago: fee > 0,
				valor: fee > 0 ? fee.toFixed(2).replace('.', ',') : '',
				prazo: soDia(a.participant_payment_date)
			};
			formOriginal = f.form;
			pagoOriginal = f.pago;
			resumo = data.data.resumo ?? resumo;
			if (a.image && a.image !== '#') {
				imagemAtualUrl = a.image.startsWith('http') ? a.image : `${API_HOST}/${a.image}`;
				imagem = imagemOriginal = 'atual';
			}
			estado = 'normal';
		} catch {
			mensagemDoServidor = 'Não foi possível carregar o evento. Verifique sua conexão.';
			estado = 'erroServidor';
		}
	}

	onMount(() => {
		carregarFormularios();
		if (activityId !== null) carregarEvento();
	});

	$effect(() => {
		caminhoDoPainel.subtela = editando ? 'Editar evento' : null;
	});
	onDestroy(() => {
		caminhoDoPainel.subtela = null;
		if (previaArquivo) URL.revokeObjectURL(previaArquivo);
	});

	// ─── Campos ───────────────────────────────────────────────────────────
	function mudarPrimeiroDia(valor: string) {
		// Evento de um dia: o último acompanha o primeiro enquanto estava vazio ou igual.
		if (!f.fim || f.fim === f.ini) f.fim = valor;
		f.ini = valor;
	}

	function escolherImagem(e: Event) {
		const escolhido = (e.currentTarget as HTMLInputElement).files?.[0];
		if (!escolhido) return;
		if (previaArquivo) URL.revokeObjectURL(previaArquivo);
		arquivo = escolhido;
		previaArquivo = URL.createObjectURL(escolhido);
		imagem = 'nova';
		(e.currentTarget as HTMLInputElement).value = '';
	}

	function removerImagem() {
		arquivo = null;
		imagem = 'nenhuma';
	}

	function manterImagemAtual() {
		arquivo = null;
		imagem = 'atual';
	}

	const tamanho = (b: number) =>
		b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} KB` : `${(b / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;

	let textoImagem = $derived.by(() => {
		if (imagem === 'nenhuma')
			return {
				titulo: 'Sem imagem',
				ajuda:
					imagemOriginal === 'atual'
						? 'A imagem atual será removida quando você salvar. Sem imagem, o Início usa o vitral com o brasão.'
						: 'Sem imagem, o Início usa o vitral com o brasão. Prefira foto na horizontal: o arco corta os cantos de cima.'
			};
		if (imagem === 'atual') return { titulo: 'Imagem atual', ajuda: 'É assim que ela aparece no arco do cartão.' };
		return {
			titulo: arquivo ? `${arquivo.name} · ${tamanho(arquivo.size)}` : 'Nova imagem',
			ajuda:
				imagemOriginal === 'atual'
					? 'A nova imagem substitui a atual quando você salvar.'
					: 'É assim que ela aparece no arco do cartão.'
		};
	});

	// ─── Salvar ───────────────────────────────────────────────────────────
	async function salvar() {
		tentou = true;
		errosDoServidor = {};
		mensagemDoServidor = '';
		const e = validar();
		if (Object.keys(e).length) {
			aba = ABAS.find(([k]) => CAMPOS_DA_ABA[k].some((c) => e[c]))![0];
			if (estado === 'salvo' || estado === 'erroServidor') estado = 'normal';
			return;
		}

		estado = 'salvando';
		const body = new FormData();
		if (!editando) body.append('type', 'Event');
		const val = dinheiro(f.valor) ?? 0;
		const campos: Record<string, string> = {
			name: f.nome.trim(),
			place: f.local.trim(),
			target_audience: f.publico,
			description: f.sobre,
			min_age: txt(f.idMin),
			max_age: txt(f.idMax),
			start_date: `${f.ini}T00:00`,
			duration_days: String(diasEntre(f.ini, f.fim) + 1),
			total_vacancies: txt(f.vagas),
			participant_registration_start_date: `${f.abre}T00:00`,
			participant_registration_end_date: `${f.fecha}T23:59`,
			participant_fee: f.pago ? String(val) : '0',
			participant_payment_date: f.pago ? `${f.prazo}T23:59` : '',
			// Vazio = nenhum formulário (o servidor entende '' como "sem formulário").
			event_form_id: f.form
		};
		for (const [k, v] of Object.entries(campos)) body.append(`activity[${k}]`, v);
		if (imagem === 'nova' && arquivo) body.append('activity[image]', arquivo);
		if (editando && imagem === 'nenhuma' && imagemOriginal === 'atual') body.append('activity[remove_image]', '1');
		// PHP só lê multipart em POST; o _method faz o CakePHP tratar como PATCH.
		if (editando) body.append('_method', 'PATCH');

		try {
			const res = await apiFetch(editando ? `/activities/${activityId}` : '/activities', { method: 'POST', body });
			const data = await res.json().catch(() => ({}));
			if (!res.ok) {
				const porCampo: Partial<Record<Campo, string>> = {};
				for (const [campo, regras] of Object.entries((data.errors ?? {}) as Record<string, Record<string, string>>)) {
					const nosso = CAMPO_DO_SERVIDOR[campo];
					if (nosso) porCampo[nosso] ??= Object.values(regras)[0];
				}
				if (Object.keys(porCampo).length) {
					errosDoServidor = porCampo;
					aba = ABAS.find(([k]) => CAMPOS_DA_ABA[k].some((c) => porCampo[c]))![0];
					estado = 'normal';
				} else {
					mensagemDoServidor = '';
					estado = 'erroServidor';
				}
				return;
			}
			if (editando) {
				formOriginal = f.form;
				pagoOriginal = f.pago;
				if (imagem === 'nova') {
					imagemAtualUrl = previaArquivo;
					imagem = imagemOriginal = 'atual';
				} else if (imagem === 'nenhuma') {
					imagemOriginal = 'nenhuma';
				}
				estado = 'salvo';
			} else {
				idCriado = data.data?.id ?? null;
				faseCriada = TEXTO_DA_FASE[fasePrevia];
				estado = 'criado';
				window.scrollTo(0, 0);
			}
		} catch {
			estado = 'erroServidor';
		}
	}

	async function criarOutro() {
		f = { ...VAZIO };
		aba = 'evento';
		tentou = false;
		errosDoServidor = {};
		idCriado = null;
		removerImagem();
		estado = 'normal';
		await tick();
		document.getElementById('f-nome')?.focus();
	}

	let proximaAba = $derived(ABAS[ABAS.findIndex(([k]) => k === aba) + 1]);
</script>

<div class="ev">
	{#if estado === 'carregando'}
		<div class="osso-titulo"><span class="osso" style="height:44px;width:320px"></span><span class="osso claro" style="height:20px;width:460px"></span></div>
		<section class="cartao" aria-busy="true">
			<div class="carregando"><span class="spinner"></span>Carregando o evento...</div>
			<div class="cartao-corpo">
				{#each [1, 2, 3, 4] as _}
					<div class="osso-campo"><span class="osso" style="height:16px;width:180px"></span><span class="osso claro" style="height:52px"></span></div>
				{/each}
			</div>
		</section>
	{:else if estado === 'criado'}
		<div class="corpo">
			<section class="cartao criado" role="status">
				<div class="faixa-ok"></div>
				<div class="criado-corpo">
					<span class="criado-ok" aria-hidden="true">✓</span>
					<h1 class="criado-titulo">Evento criado!</h1>
					<p class="criado-texto">“{f.nome}” já aparece no Início com o selo “{faseCriada}”.</p>
					<div class="criado-acoes">
						{#if idCriado}<a href="/dashboard/atividades/{idCriado}" class="btn b-ouro">Ver como o público vê</a>{/if}
						<a href="/admin/atividades" class="btn b-azul b-grande">Ir para a Gestão de Atividades</a>
					</div>
					<button type="button" class="link" onclick={criarOutro}>Criar outro evento</button>
				</div>
			</section>
			{@render previa()}
		</div>
	{:else}
		<div class="cabeca">
			<div class="cabeca-textos">
				<h1 class="titulo">{editando ? 'Editar evento' : 'Criar evento'}</h1>
				<p class="subtitulo">
					{editando ? f.nome : 'Vigília, tarde de formação, retiro, show: atividades sem sorteio, com vagas por ordem de inscrição.'}
				</p>
			</div>
			{#if editando}<a href="/dashboard/atividades/{activityId}" target="_blank" rel="noopener" class="btn b-claro">Ver página do evento ↗</a>{/if}
		</div>

		{#if estado === 'erroServidor'}
			<div class="erro-servidor" role="alert">
				<span class="err grande"><i>!</i><span>{mensagemDoServidor || 'Não foi possível salvar o evento. Nada do que você preencheu foi perdido: tente de novo em alguns instantes.'}</span></span>
				{#if !mensagemDoServidor}<button type="button" class="btn b-perigo" onclick={salvar}>Tentar de novo</button>{/if}
			</div>
		{/if}

		<div class="corpo">
			<div class="coluna">
				<div class="abas" role="tablist" aria-label="Partes do cadastro do evento">
					{#each ABAS as [k, nome], i}
						{@const n = errosDaAba(k)}
						<button
							type="button"
							role="tab"
							id="aba-{k}"
							class="aba"
							class:ativa={aba === k}
							class:com-erro={n > 0}
							aria-selected={aba === k}
							aria-controls="painel-aba"
							onclick={() => (aba = k)}
						>
							<span class="aba-num" aria-hidden="true">{i + 1}</span>{nome}
							{#if n > 0}<span class="aba-erros">{n === 1 ? '1 erro' : `${n} erros`}</span>{/if}
						</button>
					{/each}
				</div>

				<div id="painel-aba" role="tabpanel" tabindex="-1" aria-labelledby="aba-{aba}" class="cartao painel">
					<div class="cartao-corpo">
						{#if aba === 'evento'}
							<div class="grade2">
								<div class="campo-bloco">
									<label for="f-nome" class="rotulo">Nome do evento <span class="req" aria-hidden="true">*</span></label>
									<input id="f-nome" class="campo" class:erro={erros.nome} bind:value={f.nome} placeholder="Ex.: Vigília de São Miguel" aria-required="true" aria-invalid={!!erros.nome} aria-describedby="e-nome" />
									{#if erros.nome}<p id="e-nome" class="err"><i>!</i>{erros.nome}</p>{/if}
								</div>
								<div class="campo-bloco">
									<label for="f-local" class="rotulo">Local <span class="req" aria-hidden="true">*</span></label>
									<input id="f-local" class="campo" class:erro={erros.local} bind:value={f.local} placeholder="Ex.: Igreja São Miguel Arcanjo" aria-required="true" aria-invalid={!!erros.local} aria-describedby="e-local" />
									{#if erros.local}<p id="e-local" class="err"><i>!</i>{erros.local}</p>{/if}
								</div>
							</div>

							<fieldset class="grupo">
								<legend class="rotulo">Para quem é</legend>
								<div role="radiogroup" aria-label="Para quem é" class="radios">
									{#each PUBLICOS as p}
										<button type="button" role="radio" aria-checked={f.publico === p} class="radio" class:sel={f.publico === p} onclick={() => (f.publico = p)}>
											<span class="bolinha" aria-hidden="true"></span>{p}
										</button>
									{/each}
								</div>
								<p class="ajuda">Aparece em “Público” no cartão e na página do evento. É só informação: não impede ninguém de se inscrever.</p>
								{#if f.publico === 'Casais'}
									<p class="aviso av-azul"><i></i><span>Cada pessoa se inscreve na própria conta. Quem é casado(a) ou vive em união estável informa o CPF do cônjuge ao se inscrever, em qualquer evento.</span></p>
								{/if}
							</fieldset>

							<fieldset class="grupo">
								<legend class="rotulo">Faixa etária <span class="opc">opcional</span></legend>
								<div class="faixa">
									<div class="campo-bloco curto">
										<label for="f-min" class="rotulo-menor">Idade mínima</label>
										<input id="f-min" type="number" min="0" max="120" inputmode="numeric" class="campo" class:erro={erros.faixa} bind:value={f.idMin} placeholder="Sem mínimo" aria-invalid={!!erros.faixa} aria-describedby="faixa-txt e-faixa" />
									</div>
									<div class="campo-bloco curto">
										<label for="f-max" class="rotulo-menor">Idade máxima</label>
										<input id="f-max" type="number" min="0" max="120" inputmode="numeric" class="campo" class:erro={erros.faixa} bind:value={f.idMax} placeholder="Sem máximo" aria-invalid={!!erros.faixa} aria-describedby="faixa-txt e-faixa" />
									</div>
									{#if faixa}
										<span id="faixa-txt" class="faixa-txt"><span class="rot">Vai aparecer como</span><strong>{faixa}</strong></span>
									{/if}
								</div>
								{#if erros.faixa}<p id="e-faixa" class="err"><i>!</i>{erros.faixa}</p>{/if}
								<p class="ajuda">A idade é contada no primeiro dia do evento. Quem estiver fora da faixa não consegue se inscrever. Deixe em branco para aceitar todas as idades.</p>
							</fieldset>

							<div class="campo-bloco">
								<label for="f-sobre" class="rotulo">Sobre o evento <span class="opc">opcional</span></label>
								<textarea id="f-sobre" class="campo texto" rows="6" bind:value={f.sobre} placeholder="Programação, horário, o que levar..." aria-describedby="sobre-ajuda"></textarea>
								<p id="sobre-ajuda" class="ajuda">Aparece na página do evento, do jeito que você escrever. Como as datas não têm hora, diga aqui o horário: “Começa às 19h”.</p>
							</div>

							<div class="campo-bloco">
								<span class="rotulo">Imagem de capa <span class="opc">opcional</span></span>
								<div class="imagem">
									<div class="imagem-arco">
										{#if imagemDaPrevia}
											<img src={imagemDaPrevia} alt="Pré-visualização da imagem de capa" class="imagem-foto" />
										{:else}
											<div class="vitral"><img src={logoAsas} alt="" /></div>
										{/if}
										<div class="moldura"></div>
									</div>
									<div class="imagem-textos">
										<span class="imagem-titulo">{textoImagem.titulo}</span>
										<p class="ajuda">{textoImagem.ajuda}</p>
										<div class="imagem-acoes">
											<button type="button" class="btn b-azul" onclick={() => inputImagem?.click()}>{imagem === 'nenhuma' ? 'Escolher imagem' : 'Trocar imagem'}</button>
											{#if imagem !== 'nenhuma'}<button type="button" class="btn b-perigo" onclick={removerImagem}>Remover imagem</button>{/if}
											{#if imagemOriginal === 'atual' && imagem !== 'atual'}<button type="button" class="link" onclick={manterImagemAtual}>Manter a imagem atual</button>{/if}
										</div>
										<input bind:this={inputImagem} type="file" accept="image/*" class="escondido" onchange={escolherImagem} tabindex="-1" aria-hidden="true" />
									</div>
								</div>
							</div>
						{:else if aba === 'datas'}
							{#if editando && resumo.inscricoes > 0}
								<p class="aviso av-azul"><i></i><span>Este evento já tem <strong>{resumo.inscricoes} {resumo.inscricoes === 1 ? 'inscrição' : 'inscrições'}</strong>. Mudanças nas datas e nas vagas valem também para elas.</span></p>
							{/if}
							<div class="secao"><h2 class="h3">Quando acontece</h2><p class="ajuda">Só a data. Se o horário importa, escreva em “Sobre o evento”.</p></div>
							<div class="grade2">
								<div class="campo-bloco">
									<label for="f-ini" class="rotulo">Primeiro dia <span class="req" aria-hidden="true">*</span></label>
									<input id="f-ini" type="date" class="campo" class:erro={erros.ini} value={f.ini} oninput={(e) => mudarPrimeiroDia(e.currentTarget.value)} aria-required="true" aria-invalid={!!erros.ini} aria-describedby="e-ini" />
									{#if erros.ini}<p id="e-ini" class="err"><i>!</i>{erros.ini}</p>{/if}
								</div>
								<div class="campo-bloco">
									<label for="f-fim" class="rotulo">Último dia <span class="req" aria-hidden="true">*</span></label>
									<input id="f-fim" type="date" class="campo" class:erro={erros.fim} bind:value={f.fim} aria-required="true" aria-invalid={!!erros.fim} aria-describedby="fim-ajuda e-fim" />
									{#if erros.fim}<p id="e-fim" class="err"><i>!</i>{erros.fim}</p>{/if}
									<p id="fim-ajuda" class="ajuda">Evento de um dia: deixe igual ao primeiro dia. No dia seguinte ao último, o evento sai do Início.</p>
								</div>
							</div>

							<div class="divisoria"></div>
							<div class="secao"><h2 class="h3">Inscrições</h2><p class="ajuda">A vaga é de quem conclui a inscrição primeiro.</p></div>
							<div class="grade2">
								<div class="campo-bloco">
									<label for="f-abre" class="rotulo">Abertura <span class="req" aria-hidden="true">*</span></label>
									<input id="f-abre" type="date" class="campo" class:erro={erros.abre} bind:value={f.abre} aria-required="true" aria-invalid={!!erros.abre} aria-describedby="abre-ajuda e-abre" />
									{#if erros.abre}<p id="e-abre" class="err"><i>!</i>{erros.abre}</p>{/if}
									<p id="abre-ajuda" class="ajuda">Abre às 00h00 deste dia. Até lá, o cartão mostra “Em breve”.</p>
								</div>
								<div class="campo-bloco">
									<label for="f-fecha" class="rotulo">Fechamento <span class="req" aria-hidden="true">*</span></label>
									<input id="f-fecha" type="date" class="campo" class:erro={erros.fecha} bind:value={f.fecha} aria-required="true" aria-invalid={!!erros.fecha} aria-describedby="fecha-ajuda e-fecha" />
									{#if erros.fecha}<p id="e-fecha" class="err"><i>!</i>{erros.fecha}</p>{/if}
									<p id="fecha-ajuda" class="ajuda">Fecha às 23h59 deste dia. Pode ser o próprio primeiro dia do evento.</p>
								</div>
								<div class="campo-bloco">
									<label for="f-vagas" class="rotulo">Total de vagas <span class="req" aria-hidden="true">*</span></label>
									<input id="f-vagas" type="number" min="1" inputmode="numeric" class="campo vagas" class:erro={erros.vagas} bind:value={f.vagas} aria-required="true" aria-invalid={!!erros.vagas} aria-describedby="vagas-ajuda e-vagas" />
									{#if erros.vagas}<p id="e-vagas" class="err"><i>!</i>{erros.vagas}</p>{/if}
									<p id="vagas-ajuda" class="ajuda">Acabadas as vagas, a inscrição fecha sozinha.</p>
								</div>
								<div class="campo-bloco">
									<label for="f-form" class="rotulo">Formulário de inscrição</label>
									<select id="f-form" class="campo" bind:value={f.form} disabled={carregandoFormularios} aria-describedby="form-ajuda">
										<option value="">Nenhum (a inscrição conclui na hora)</option>
										{#each formularios as x (x.id)}<option value={String(x.id)}>{x.nome}</option>{/each}
									</select>
									<p id="form-ajuda" class="ajuda">
										{temFormulario
											? 'A inscrição fica “em andamento” até a pessoa enviar o formulário. A vaga só é ocupada no envio.'
											: 'Sem formulário, a inscrição conclui na hora e a vaga já é ocupada.'}
									</p>
									{#if pode('formularios')}
										<div class="links">
											<a href="/admin/formularios" target="_blank" rel="noopener" class="link">+ Novo formulário ↗</a>
											<button type="button" class="link" onclick={carregarFormularios}>Atualizar a lista</button>
										</div>
									{/if}
								</div>
							</div>
							{#if trocouFormulario}
								<p class="aviso av-amb" role="status"><i></i><span><strong>Você trocou o formulário.</strong> Quem já se inscreveu também vai precisar enviar este formulário para concluir a inscrição.</span></p>
							{/if}

							<div class="resumo">
								<span class="losango" aria-hidden="true"></span>
								<div><span class="rot">Resumo</span><p>{frasResumo}</p></div>
							</div>
						{:else}
							{#if editando && pagoOriginal && resumo.pagamentos > 0}
								<p class="aviso av-azul"><i></i><span>Já há <strong>{resumo.pagamentos} {resumo.pagamentos === 1 ? 'pagamento registrado' : 'pagamentos registrados'}</strong>. Quem já pagou continua como “Pago”, mesmo que você mude o valor.</span></p>
							{/if}
							<fieldset class="grupo">
								<legend class="rotulo">O evento é</legend>
								<div role="radiogroup" aria-label="O evento é" class="tipos">
									{#each [[false, 'Gratuito', 'Ninguém paga. O site mostra “Gratuito”.'], [true, 'Pago', 'Cada pessoa paga direto com a organização, até o prazo.']] as [pago, nome, desc]}
										<button type="button" role="radio" aria-checked={f.pago === pago} class="tipo" class:sel={f.pago === pago} onclick={() => (f.pago = pago as boolean)}>
											<span class="bolinha" aria-hidden="true"></span>
											<span class="tipo-textos"><span class="tipo-nome">{nome}</span><span class="tipo-desc">{desc}</span></span>
										</button>
									{/each}
								</div>
							</fieldset>
							{#if editando && pagoOriginal && !f.pago && resumo.pagamentos > 0}
								<p class="aviso av-amb" role="status"><i></i><span><strong>Há {resumo.pagamentos} {resumo.pagamentos === 1 ? 'pagamento registrado' : 'pagamentos registrados'}.</strong> Se o evento ficar gratuito, esses registros continuam na lista de pagamentos, para a organização decidir se devolve o valor.</span></p>
							{/if}
							{#if f.pago}
								<div class="grade2">
									<div class="campo-bloco">
										<label for="f-valor" class="rotulo">Valor por pessoa (R$) <span class="req" aria-hidden="true">*</span></label>
										<div class="dinheiro">
											<span aria-hidden="true">R$</span>
											<input id="f-valor" inputmode="decimal" class="campo" class:erro={erros.valor} bind:value={f.valor} placeholder="0,00" aria-required="true" aria-invalid={!!erros.valor} aria-describedby="e-valor" />
										</div>
										{#if erros.valor}<p id="e-valor" class="err"><i>!</i>{erros.valor}</p>{/if}
									</div>
									<div class="campo-bloco">
										<label for="f-prazo" class="rotulo">Prazo do pagamento <span class="req" aria-hidden="true">*</span></label>
										<input id="f-prazo" type="date" class="campo" class:erro={erros.prazo} bind:value={f.prazo} aria-required="true" aria-invalid={!!erros.prazo} aria-describedby="prazo-ajuda e-prazo" />
										{#if erros.prazo}<p id="e-prazo" class="err"><i>!</i>{erros.prazo}</p>{/if}
										<p id="prazo-ajuda" class="ajuda">Até 23h59 deste dia, e no máximo até o primeiro dia do evento. Quem não paga perde a vaga.</p>
									</div>
								</div>
								<div class="como-funciona">
									<span class="rot">Como funciona o pagamento</span>
									<ul>
										<li>A pessoa paga direto com a organização, em dinheiro ou Pix. O site não tem botão de pagar.</li>
										<li>A equipe registra o pagamento em <strong>Inscritos e Sorteios › Pagamentos</strong>.</li>
										<li>
											A pessoa vê o prazo {f.prazo ? `“Pague até ${dmx(f.prazo)}”` : '“Pague até {prazo}”'}. O sistema só tira a vaga de quem não pagou
											<strong>2 dias depois</strong>, em {f.prazo ? dmx(maisDias(f.prazo, 2)) : 'prazo + 2 dias'}, para dar tempo de registrar quem pagou no último dia.
										</li>
									</ul>
								</div>
							{/if}
						{/if}

						{#if !editando && proximaAba}
							<div class="continuar">
								<button type="button" class="btn b-azul" onclick={() => (aba = proximaAba[0])}>Continuar: {proximaAba[1]} →</button>
							</div>
						{/if}
					</div>
				</div>
			</div>

			{@render previa()}
		</div>

		<div class="barra" class:barra-erro={listaDeErros.length > 0} role="region" aria-label="Salvar">
			{#if listaDeErros.length > 0}
				<span role="alert" class="err grande"><i>!</i><span>Corrija {listaDeErros.length === 1 ? '1 campo' : `${listaDeErros.length} campos`} para salvar: {listaDeErros.join(', ')}.</span></span>
			{:else}
				<span class="barra-texto"><span class="ponto" class:ponto-edicao={editando} aria-hidden="true"></span>{editando ? 'Salve quando quiser, de qualquer aba.' : 'Os campos com * são obrigatórios. Você pode criar o evento de qualquer aba.'}</span>
			{/if}
			<div class="barra-acoes">
				<a href="/admin/atividades" class="btn b-azul b-grande">Cancelar</a>
				<button type="button" class="btn b-ouro" onclick={salvar} disabled={estado === 'salvando'} aria-busy={estado === 'salvando'}>
					{estado === 'salvando' ? 'Salvando...' : editando ? 'Salvar alterações' : 'Criar evento'}
				</button>
			</div>
		</div>

		{#if estado === 'salvo'}
			<div class="salvo" role="status">
				<span aria-hidden="true">✓</span>Alterações salvas.
				<button type="button" aria-label="Fechar aviso" onclick={() => (estado = 'normal')}>✕</button>
			</div>
		{/if}
	{/if}
</div>

{#snippet previa()}
	<aside class="previa" aria-label="Prévia do cartão no Início">
		<div class="previa-cabeca"><span class="rot">Prévia no Início</span><span>Como o cartão aparece hoje, {hoje.toLocaleDateString('pt-BR')}.</span></div>
		<article class="card" aria-hidden="true">
			<div class="card-arco">
				{#if imagemDaPrevia}
					<img src={imagemDaPrevia} alt="" class="imagem-foto" />
				{:else}
					<div class="vitral"><img src={logoAsas} alt="" class="card-logo" /></div>
				{/if}
				<div class="moldura"></div>
				<div class="card-etiquetas">
					<span class="etiqueta">Evento</span>
					<span class="card-situacao"><SeloFase fase={fasePrevia} /></span>
				</div>
			</div>
			<div class="card-corpo">
				<h3 class="card-titulo" class:vazio={!f.nome.trim()}>{f.nome.trim() || 'Nome do evento'}</h3>
				<div class="filete"><span class="filete-traco"></span><span class="filete-losango"></span><span class="filete-linha"></span></div>
				<p class="card-quando">{fraseAcontece(datasDaPrevia, fasePrevia, hoje)}</p>
				<dl class="card-dados">
					<dt>Público</dt><dd>{f.publico}</dd>
					{#if faixa}<dt>Idade</dt><dd>{faixa}</dd>{/if}
					<dt>Local</dt><dd>{f.local.trim() || '—'}</dd>
				</dl>
				<div class="card-botoes">
					<span class="btn-detalhes">Ver Detalhes</span>
					{#if inscricaoAberta(datasDaPrevia.inscricao, hoje)}<span class="btn-inscrever">Inscrever-se</span>{/if}
				</div>
			</div>
		</article>
		<div class="previa-rodape">
			<span><strong>Valor:</strong> {valorDaPrevia}</span>
			<span><strong>Formulário:</strong> {formularioDaPrevia}</span>
		</div>
	</aside>
{/snippet}

<style>
	.ev {
		--ouro: #b69e55;
		--ouro-claro: #e2ce8a;
		--ouro-texto: #7a6528;
		--azul: #1b2f5e;
		--azul-noite: #0f1d3d;
		--vermelho: #9b2c2c;
		--pergaminho: #fffdf8;
		--borda: #d9cca6;
		--borda-suave: #e3d8bc;
		--tinta: #1e2233;
		--tinta-2: #2b3045;
		--tinta-3: #4f5468;
		--campo-borda: #a39363;
		--linha: #efe7d3;

		display: flex;
		flex-direction: column;
		gap: 22px;
		font-family: 'Source Sans 3', system-ui, sans-serif;
		color: var(--tinta);
		position: relative;
	}

	.ev :global(*) {
		box-sizing: border-box;
	}

	/* ─── Cabeçalho ──────────────────────────────────────────────── */
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
	.titulo {
		margin: 0;
		font: 600 44px/1.05 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.subtitulo {
		margin: 0;
		max-width: 760px;
		font-size: 17px;
		line-height: 1.45;
		color: var(--tinta-3);
	}

	/* ─── Botões ─────────────────────────────────────────────────── */
	.btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 8px;
		height: 44px;
		padding: 0 18px;
		font: 600 15px 'Source Sans 3', sans-serif;
		border-radius: 999px;
		cursor: pointer;
		white-space: nowrap;
		text-decoration: none;
	}
	.btn:disabled {
		cursor: not-allowed;
		opacity: 0.75;
	}
	.b-ouro {
		height: 52px;
		padding: 0 26px;
		font-size: 17px;
		font-weight: 700;
		color: var(--azul-noite);
		background: linear-gradient(180deg, #d6be72, #b69e55);
		border: 1px solid #9c8642;
		box-shadow: 0 6px 16px -8px rgba(122, 101, 40, 0.9);
	}
	.b-ouro:hover {
		background: var(--ouro);
	}
	.b-azul {
		color: var(--azul);
		background: transparent;
		border: 1.5px solid var(--azul);
	}
	.b-azul:hover {
		background: #eef1f7;
	}
	.b-claro {
		color: var(--azul);
		background: var(--pergaminho);
		border: 1px solid var(--borda);
	}
	.b-claro:hover {
		border-color: var(--ouro);
		background: #fbf6e6;
	}
	.b-perigo {
		color: var(--vermelho);
		background: var(--pergaminho);
		border: 1.5px solid #e6bdb6;
	}
	.b-perigo:hover {
		background: #f6e2df;
		border-color: var(--vermelho);
	}
	.b-grande {
		height: 52px;
		padding: 0 24px;
		font-size: 17px;
	}
	.link {
		padding: 4px 0;
		border: none;
		background: transparent;
		font: 600 15px 'Source Sans 3', sans-serif;
		color: var(--azul);
		text-decoration: underline;
		text-underline-offset: 3px;
		cursor: pointer;
	}

	/* ─── Corpo e abas ───────────────────────────────────────────── */
	.corpo {
		display: grid;
		grid-template-columns: minmax(0, 1fr) 344px;
		gap: 28px;
		align-items: start;
	}
	.coluna {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}
	/* As três abas dividem a largura do formulário, cada uma numa linha só. */
	.abas {
		display: flex;
		gap: 6px;
	}
	.aba {
		position: relative;
		flex: 1 1 auto;
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 8px;
		height: 56px;
		padding: 0 12px;
		white-space: nowrap;
		font: 600 17px 'Source Sans 3', sans-serif;
		color: var(--tinta-3);
		background: transparent;
		border: 1px solid transparent;
		border-bottom: none;
		border-radius: 12px 12px 0 0;
		cursor: pointer;
		margin-bottom: -1px;
		z-index: 1;
	}
	.aba:hover {
		color: var(--azul);
	}
	.aba.ativa {
		font-weight: 700;
		color: var(--azul);
		background: var(--pergaminho);
		border-color: var(--borda-suave);
		z-index: 2;
	}
	.aba.ativa::before {
		content: '';
		position: absolute;
		left: 14px;
		right: 14px;
		top: -1px;
		height: 3px;
		border-radius: 0 0 3px 3px;
		background: var(--ouro);
	}
	.aba-num {
		flex: 0 0 26px;
		height: 26px;
		border-radius: 50%;
		font: 700 14px/26px 'Source Sans 3', sans-serif;
		text-align: center;
		color: var(--tinta-3);
		background: #e9e1cc;
	}
	.aba.ativa .aba-num {
		color: var(--pergaminho);
		background: var(--azul);
	}
	.aba.com-erro .aba-num {
		color: var(--pergaminho);
		background: var(--vermelho);
	}
	.aba-erros {
		font-size: 13px;
		font-weight: 700;
		color: var(--pergaminho);
		background: var(--vermelho);
		border-radius: 999px;
		padding: 2px 8px;
	}

	.cartao {
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 14px;
		box-shadow: 0 14px 28px -22px rgba(15, 29, 61, 0.45);
	}
	.painel {
		border-top-left-radius: 0;
		border-top-right-radius: 0;
	}
	.cartao-corpo {
		display: flex;
		flex-direction: column;
		gap: 24px;
		padding: 28px 32px 30px;
	}

	/* ─── Campos ─────────────────────────────────────────────────── */
	.grade2 {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 20px 24px;
	}
	.campo-bloco {
		display: flex;
		flex-direction: column;
		gap: 8px;
		min-width: 0;
	}
	.campo-bloco.curto {
		width: 180px;
	}
	.rotulo {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 0;
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
	}
	legend.rotulo {
		margin-bottom: 10px;
	}
	.rotulo-menor {
		font-size: 15px;
		font-weight: 600;
		color: var(--tinta-2);
	}
	.req {
		color: var(--vermelho);
	}
	.opc {
		font-size: 13px;
		font-weight: 600;
		color: var(--tinta-3);
		background: #efebe1;
		border-radius: 999px;
		padding: 2px 9px;
	}
	.campo {
		width: 100%;
		height: 52px;
		padding: 0 16px;
		font: 400 17px 'Source Sans 3', sans-serif;
		color: var(--tinta);
		background: #fff;
		border: 1.5px solid var(--campo-borda);
		border-radius: 10px;
		outline: none;
	}
	select.campo {
		padding: 0 12px;
	}
	.campo:focus {
		border-color: var(--azul);
		box-shadow: 0 0 0 1px var(--azul), 0 0 0 4px rgba(182, 158, 85, 0.45);
	}
	.campo.erro {
		border-color: var(--vermelho);
		box-shadow: 0 0 0 1px var(--vermelho);
	}
	.campo.texto {
		height: auto;
		min-height: 150px;
		padding: 12px 16px;
		line-height: 1.5;
		resize: vertical;
	}
	.campo.vagas {
		max-width: 220px;
	}
	.ajuda {
		margin: 0;
		font-size: 15px;
		line-height: 1.4;
		color: var(--tinta-3);
	}
	.err {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		margin: 0;
		font-size: 15px;
		line-height: 1.4;
		font-weight: 600;
		color: #842424;
	}
	.err.grande {
		font-size: 17px;
	}
	.err i {
		flex: 0 0 20px;
		height: 20px;
		border-radius: 50%;
		background: var(--vermelho);
		color: var(--pergaminho);
		font: 700 13px/20px 'Source Sans 3', sans-serif;
		font-style: normal;
		text-align: center;
	}
	.grupo {
		margin: 0;
		padding: 0;
		border: none;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.radios {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;
	}
	.radio {
		/* Mesma largura para todos, conteúdo centralizado; nunca encolhe nem quebra. */
		display: inline-flex;
		flex: 0 0 auto;
		min-width: 140px;
		justify-content: center;
		white-space: nowrap;
		align-items: center;
		gap: 10px;
		height: 52px;
		padding: 0 20px;
		font: 600 17px 'Source Sans 3', sans-serif;
		color: var(--tinta);
		background: #fff;
		border: 1.5px solid var(--borda);
		border-radius: 999px;
		cursor: pointer;
	}
	.radio.sel {
		color: var(--azul-noite);
		background: #fbf6e6;
		border-color: var(--ouro);
	}
	.bolinha {
		flex: 0 0 20px;
		height: 20px;
		border-radius: 50%;
		border: 2px solid var(--campo-borda);
		background: #fff;
	}
	.sel .bolinha {
		border: 6px solid var(--azul);
	}
	.faixa {
		display: flex;
		align-items: flex-end;
		gap: 16px;
		flex-wrap: wrap;
	}
	.faixa-txt {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding-bottom: 6px;
	}
	.faixa-txt strong {
		font-size: 18px;
		color: var(--azul);
	}
	.rot {
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}
	.aviso {
		display: flex;
		gap: 10px;
		align-items: flex-start;
		margin: 0;
		padding: 12px 14px;
		border-radius: 10px;
		font-size: 16px;
		line-height: 1.45;
	}
	.aviso > i {
		flex: 0 0 8px;
		height: 8px;
		margin-top: 8px;
		border-radius: 50%;
	}
	.av-amb {
		background: #fbf0d6;
		border: 1px solid #ebd39c;
		color: #6a480c;
	}
	.av-amb > i {
		background: #c08a1e;
	}
	.av-azul {
		background: #e6ebf5;
		border: 1px solid #c4cfe6;
		color: #21376a;
	}
	.av-azul > i {
		background: #3c5aa0;
	}
	.secao {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.h3 {
		margin: 0;
		font: 600 24px/1.15 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.divisoria {
		height: 1px;
		background: var(--linha);
	}
	.links {
		display: flex;
		gap: 16px;
	}
	.resumo {
		display: flex;
		gap: 14px;
		align-items: flex-start;
		padding: 18px 20px;
		background: #fbf6e6;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}
	.resumo > div {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.resumo p {
		margin: 0;
		font-size: 18px;
		line-height: 1.5;
		color: var(--tinta);
	}
	.losango {
		flex: 0 0 10px;
		height: 10px;
		margin-top: 9px;
		background: var(--ouro);
		transform: rotate(45deg);
	}
	.tipos {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 14px;
	}
	.tipo {
		display: flex;
		gap: 14px;
		align-items: flex-start;
		text-align: left;
		padding: 18px 20px;
		background: #fff;
		border: 1px solid var(--borda);
		border-radius: 14px;
		cursor: pointer;
		font-family: 'Source Sans 3', sans-serif;
	}
	.tipo.sel {
		background: #fbf6e6;
		border: 2px solid var(--ouro);
	}
	.tipo .bolinha {
		flex-basis: 22px;
		height: 22px;
		margin-top: 2px;
	}
	.tipo.sel .bolinha {
		border-width: 7px;
	}
	.tipo-textos {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.tipo-nome {
		font-size: 19px;
		font-weight: 700;
		color: var(--tinta);
	}
	.tipo-desc {
		font-size: 16px;
		line-height: 1.4;
		color: var(--tinta-3);
	}
	.dinheiro {
		position: relative;
		max-width: 260px;
	}
	.dinheiro span {
		position: absolute;
		left: 16px;
		top: 50%;
		transform: translateY(-50%);
		font-size: 17px;
		color: var(--tinta-3);
	}
	.dinheiro .campo {
		padding-left: 48px;
	}
	.como-funciona {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 18px 20px;
		background: #fff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}
	.como-funciona ul {
		margin: 0;
		padding: 0 0 0 20px;
		display: flex;
		flex-direction: column;
		gap: 6px;
		font-size: 16px;
		line-height: 1.45;
		color: var(--tinta-2);
	}
	.continuar {
		display: flex;
		justify-content: flex-end;
		padding-top: 18px;
		border-top: 1px solid var(--linha);
	}

	/* ─── Imagem ─────────────────────────────────────────────────── */
	.imagem {
		display: flex;
		align-items: center;
		gap: 24px;
		flex-wrap: wrap;
	}
	.imagem-arco {
		position: relative;
		flex: 0 0 300px;
		height: 170px;
		border-radius: 50% 50% 6px 6px / 78px 78px 6px 6px;
		overflow: hidden;
		box-shadow: inset 0 0 0 1px #cdb878;
	}
	.imagem-foto {
		position: absolute;
		inset: 0;
		width: 100%;
		height: 100%;
		object-fit: cover;
	}
	.vitral {
		position: absolute;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		background:
			repeating-linear-gradient(45deg, transparent 0 26px, rgba(8, 14, 32, 0.45) 26px 28px),
			repeating-linear-gradient(-45deg, transparent 0 26px, rgba(8, 14, 32, 0.45) 26px 28px),
			radial-gradient(circle at 25% 25%, rgba(226, 196, 110, 0.55), transparent 35%),
			radial-gradient(circle at 80% 75%, rgba(31, 107, 110, 0.9), transparent 45%),
			radial-gradient(circle at 85% 15%, rgba(155, 44, 44, 0.45), transparent 30%),
			linear-gradient(160deg, #25407f, #1b2f5e 50%, #0f1d3d);
	}
	.vitral img {
		width: 130px;
		margin-top: -14px;
		filter: brightness(1.2) drop-shadow(0 2px 6px rgba(0, 0, 0, 0.4));
	}
	.moldura {
		position: absolute;
		inset: 0;
		border-radius: inherit;
		box-shadow:
			inset 0 0 0 5px rgba(255, 253, 248, 0.9),
			inset 0 0 0 6px rgba(182, 158, 85, 0.8);
		pointer-events: none;
	}
	.imagem-textos {
		flex: 1 1 240px;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.imagem-titulo {
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta);
		overflow-wrap: anywhere;
	}
	.imagem-acoes {
		display: flex;
		gap: 10px;
		flex-wrap: wrap;
	}
	.escondido {
		display: none;
	}

	/* ─── Prévia do cartão (igual ao Início) ─────────────────────── */
	.previa {
		position: sticky;
		top: 20px;
		display: flex;
		flex-direction: column;
		gap: 12px;
	}
	.previa-cabeca {
		display: flex;
		flex-direction: column;
		gap: 2px;
		font-size: 15px;
		color: var(--tinta-3);
	}
	.card {
		display: flex;
		flex-direction: column;
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		padding: 10px;
		box-shadow: 0 14px 28px -20px rgba(15, 29, 61, 0.45);
	}
	.card-arco {
		position: relative;
		height: 200px;
		border-radius: 50% 50% 6px 6px / 92px 92px 6px 6px;
		overflow: hidden;
		box-shadow: inset 0 0 0 1px #cdb878;
	}
	.card-arco .vitral img.card-logo {
		width: 150px;
		margin-top: -18px;
	}
	.card-etiquetas {
		position: absolute;
		left: 12px;
		right: 12px;
		bottom: 12px;
		display: flex;
		flex-wrap: wrap;
		justify-content: space-between;
		align-items: flex-end;
		gap: 6px 8px;
	}
	.card-situacao {
		margin-left: auto;
		display: flex;
	}
	.etiqueta {
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--pergaminho);
		background: var(--vermelho);
		border: 1px solid #d9a9a0;
		border-radius: 999px;
		padding: 5px 10px;
		box-shadow: 0 2px 8px rgba(15, 29, 61, 0.35);
	}
	.card-corpo {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 16px 8px 6px;
	}
	.card-titulo {
		margin: 0;
		font: 600 25px/1.12 'Cormorant Garamond', serif;
		color: var(--tinta);
		overflow-wrap: anywhere;
	}
	.card-titulo.vazio {
		color: #8a8c99;
	}
	.filete {
		display: flex;
		align-items: center;
		gap: 8px;
	}
	.filete-traco {
		flex: 0 0 22px;
		height: 1px;
		background: var(--ouro);
	}
	.filete-losango {
		width: 5px;
		height: 5px;
		background: var(--ouro);
		transform: rotate(45deg);
	}
	.filete-linha {
		flex: 1;
		height: 1px;
		background: var(--borda-suave);
	}
	.card-quando {
		margin: 0;
		font-size: 17px;
		font-weight: 600;
		color: var(--azul);
	}
	.card-dados {
		margin: 0;
		display: grid;
		grid-template-columns: auto 1fr;
		gap: 6px 12px;
		font-size: 16px;
		line-height: 1.3;
	}
	.card-dados dt {
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
		padding-top: 2px;
	}
	.card-dados dd {
		margin: 0;
		color: var(--tinta-2);
		overflow-wrap: anywhere;
	}
	.card-botoes {
		display: flex;
		gap: 8px;
		padding-top: 4px;
	}
	.btn-detalhes,
	.btn-inscrever {
		flex: 1;
		font-size: 16px;
		border-radius: 999px;
		padding: 11px 14px;
		text-align: center;
	}
	.btn-detalhes {
		font-weight: 600;
		color: var(--azul);
		border: 1.5px solid var(--azul);
	}
	.btn-inscrever {
		font-weight: 700;
		color: var(--azul-noite);
		background: linear-gradient(180deg, #d6be72, #b69e55);
		border: 1px solid #9c8642;
	}
	.previa-rodape {
		display: flex;
		flex-direction: column;
		gap: 6px;
		padding: 14px 16px;
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		font-size: 15px;
		line-height: 1.4;
		color: var(--tinta-2);
	}

	/* Janela estreita: a prévia desce para baixo do formulário, que fica com a
	   largura toda (assim as três abas cabem numa linha). */
	@media (max-width: 1439px) {
		.corpo {
			grid-template-columns: minmax(0, 1fr);
		}
		.previa {
			position: static;
			max-width: 344px;
		}
	}

	/* ─── Barra de baixo ─────────────────────────────────────────── */
	.barra {
		position: sticky;
		bottom: 16px;
		z-index: 10;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 20px;
		padding: 14px 16px 14px 24px;
		background: var(--pergaminho);
		border: 1px solid var(--ouro);
		border-radius: 16px;
		box-shadow: 0 18px 40px -16px rgba(15, 29, 61, 0.55);
	}
	.barra.barra-erro {
		border-color: #e6bdb6;
	}
	.barra-texto {
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 17px;
		color: var(--tinta-2);
	}
	.ponto {
		flex: 0 0 10px;
		height: 10px;
		border-radius: 50%;
		background: var(--ouro);
	}
	.ponto.ponto-edicao {
		background: #c08a1e;
	}
	.barra-acoes {
		display: flex;
		gap: 10px;
		flex: 0 0 auto;
	}
	.salvo {
		position: fixed;
		top: 84px;
		right: 32px;
		z-index: 30;
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 14px 18px;
		background: #1b5236;
		color: var(--pergaminho);
		border-radius: 12px;
		font-size: 17px;
		font-weight: 600;
		box-shadow: 0 18px 40px -16px rgba(15, 29, 61, 0.6);
	}
	.salvo button {
		margin-left: 6px;
		width: 30px;
		height: 30px;
		border: none;
		border-radius: 50%;
		background: rgba(255, 253, 248, 0.15);
		color: var(--pergaminho);
		font-size: 16px;
		cursor: pointer;
	}

	/* ─── Estados ────────────────────────────────────────────────── */
	.erro-servidor {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
		padding: 16px 20px;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
		border-radius: 12px;
	}
	.osso-titulo,
	.osso-campo {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.osso {
		display: block;
		border-radius: 6px;
		background: #ede5d1;
	}
	.osso.claro {
		background: #f3eddf;
		border-radius: 10px;
	}
	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 18px 28px;
		font-size: 17px;
		color: var(--tinta-3);
		border-bottom: 1px solid var(--linha);
	}
	.spinner {
		width: 18px;
		height: 18px;
		border-radius: 50%;
		border: 2.5px solid var(--borda-suave);
		border-top-color: var(--ouro);
		animation: girar 1s linear infinite;
	}
	@keyframes girar {
		to {
			transform: rotate(360deg);
		}
	}
	.criado {
		overflow: hidden;
	}
	.faixa-ok {
		height: 6px;
		background: linear-gradient(90deg, #2e7d4f, #b69e55);
	}
	.criado-corpo {
		display: flex;
		flex-direction: column;
		gap: 14px;
		padding: 32px 36px 36px;
	}
	.criado-ok {
		width: 56px;
		height: 56px;
		border-radius: 50%;
		background: #e4f1e8;
		border: 1px solid #b9d9c4;
		color: #1b5236;
		font-size: 28px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.criado-titulo {
		margin: 0;
		font: 600 42px/1.05 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.criado-texto {
		margin: 0;
		font-size: 18px;
		line-height: 1.5;
		color: var(--tinta-2);
	}
	.criado-acoes {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;
		margin-top: 6px;
	}
	.criado .link {
		align-self: flex-start;
		font-size: 16px;
	}
</style>
