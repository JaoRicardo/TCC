<script lang="ts">
	/**
	 * Criar / Editar Acampamento (handoff Desing/design_handoff_painel_acampamento),
	 * no mesmo padrão de Criar / Editar Evento: três abas sem passo a passo, salvar
	 * de qualquer aba (a validação confere todas e abre a primeira com erro) e a
	 * prévia do cartão do Início ao lado.
	 *
	 * - Datas sem hora: o primeiro dia e as aberturas vão às 00h00; fechamentos e
	 *   prazos às 23h59. A duração é último − primeiro + 1; o ano vem do primeiro dia.
	 * - Sorteio só manual (Inscritos e Sorteios): a data aqui é a prevista.
	 * - Um prazo só por trilha: o do formulário, que é também o do pagamento.
	 * - Vagas do sorteio: no Casais só casais; nas outras, homens e mulheres.
	 *   Indicações ficam fora da conta (não têm limite).
	 * O servidor confere as mesmas regras (ActivitiesController::validarAcampamento).
	 */
	import { onDestroy, onMount, tick } from 'svelte';
	import { apiFetch, API_HOST } from '$lib/api';
	import { pode } from '$lib/permissoes';
	import { caminhoDoPainel } from '$lib/stores/caminho.svelte';
	import SeloFase from '$lib/components/SeloFase.svelte';
	import {
		algumaInscricaoAberta,
		datasDaAtividade,
		diaMes,
		faseDaAtividade,
		fraseAcontece,
		servosAbertosAte,
		TEXTO_DA_FASE
	} from '$lib/faseAtividade';
	import logoAsas from '$lib/imgs/logo-asas.png';

	let { activityId = null }: { activityId?: string | null } = $props();
	let editando = $derived(activityId !== null);

	type Aba = 'acampamento' | 'campistas' | 'servos';
	type T = 'c' | 's';
	type Campo =
		| 'nome' | 'categoria' | 'local' | 'ini' | 'fim'
		| 'cAbre' | 'cFecha' | 'cSorteio' | 'cPrazo' | 'vagas' | 'cValor'
		| 'sAbre' | 'sFecha' | 'sSorteio' | 'sPrazo' | 'sValor';
	type Estado = 'carregando' | 'normal' | 'salvando' | 'criado' | 'salvo' | 'erroServidor';
	type Doc = 'notice' | 'term';
	type Setor = { nome: string; vagas: number };
	type CategoriaApoio = { id: number; nome: string; formularioCampista: boolean; formularioServo: boolean; setores: Setor[] };
	type Sorteio = { feito: boolean; em: string | null };

	const ABAS: [Aba, string][] = [
		['acampamento', 'O acampamento'],
		['campistas', 'Campistas'],
		['servos', 'Servos']
	];
	const CAMPOS_DA_ABA: Record<Aba, Campo[]> = {
		acampamento: ['nome', 'categoria', 'local', 'ini', 'fim'],
		campistas: ['cAbre', 'cFecha', 'cSorteio', 'vagas', 'cValor', 'cPrazo'],
		servos: ['sAbre', 'sFecha', 'sSorteio', 'sValor', 'sPrazo']
	};
	const ROTULO: Record<Campo, string> = {
		nome: 'Nome do acampamento',
		categoria: 'Categoria',
		local: 'Local',
		ini: 'Primeiro dia',
		fim: 'Último dia',
		cAbre: 'Abertura (campistas)',
		cFecha: 'Fechamento (campistas)',
		cSorteio: 'Sorteio previsto (campistas)',
		cPrazo: 'Prazo (campistas)',
		vagas: 'Vagas (campistas)',
		cValor: 'Valor por campista',
		sAbre: 'Abertura (servos)',
		sFecha: 'Fechamento (servos)',
		sSorteio: 'Sorteio previsto (servos)',
		sPrazo: 'Prazo (servos)',
		sValor: 'Valor por servo'
	};
	/** Coluna do servidor → campo da tela, para mostrar o erro no lugar certo. */
	const CAMPO_DO_SERVIDOR: Record<string, Campo> = {
		name: 'nome',
		place: 'local',
		category_id: 'categoria',
		start_date: 'ini',
		duration_days: 'fim',
		participant_registration_start_date: 'cAbre',
		participant_registration_end_date: 'cFecha',
		raffle_camper_date: 'cSorteio',
		camper_form_deadline: 'cPrazo',
		participant_payment_date: 'cPrazo',
		raffle_man_vacancies: 'vagas',
		raffle_woman_vacancies: 'vagas',
		raffle_couple_vacancies: 'vagas',
		participant_fee: 'cValor',
		raffle_servant_subscription_start_date: 'sAbre',
		raffle_servant_subscription_end_date: 'sFecha',
		raffle_servant_date: 'sSorteio',
		servant_form_deadline: 'sPrazo',
		servant_payment_date: 'sPrazo',
		servant_fee: 'sValor'
	};

	/**
	 * Linha da regra de cada categoria — as mesmas regras do servidor
	 * (InscricoesController::checkAcampamentoEligibility). Servir numa categoria
	 * pede ter sido campista nela ou numa acima (Mirim → FAC → Juvenil → Sênior).
	 */
	const REGRA: Record<string, [string, string]> = {
		Mirim: ['Campistas de 10 a 12 anos, uma vez só nesta categoria.', 'Quem já foi campista no Mirim, no FAC, no Juvenil ou no Sênior.'],
		FAC: ['Campistas de 14 a 17 anos, uma vez só nesta categoria.', 'Quem já foi campista no FAC, no Juvenil ou no Sênior.'],
		Juvenil: ['Campistas de 18 a 24 anos, que não sejam casados, uma vez só nesta categoria.', 'Quem já foi campista no Juvenil ou no Sênior.'],
		Sênior: ['Campistas de 25 a 60 anos, uma vez só nesta categoria.', 'Quem já foi campista no Sênior.'],
		Casais: [
			'Só pessoas casadas ou em união estável. Cada um se inscreve na própria conta, com o CPF do cônjuge, e o casal é sorteado junto. Uma vez só nesta categoria.',
			'Quem já foi campista no Casais.'
		],
		Discipulados: ['Quem já foi campista no Sênior, uma vez só nesta categoria.', 'Quem já foi campista no Discipulados.']
	};

	const VAZIO = {
		nome: '',
		categoria: '',
		local: '',
		ini: '',
		fim: '',
		sobre: '',
		cAbre: '',
		cFecha: '',
		cSorteio: '',
		cPrazo: '',
		homens: '' as string | number,
		mulheres: '' as string | number,
		casais: '' as string | number,
		cPago: false,
		cValor: '',
		sAbre: '',
		sFecha: '',
		sSorteio: '',
		sPrazo: '',
		sPago: false,
		sValor: ''
	};

	let f = $state({ ...VAZIO });
	let aba = $state<Aba>('acampamento');
	let estado = $state<Estado>('normal');
	/** Os erros só aparecem depois da primeira tentativa de salvar. */
	let tentou = $state(false);
	let errosDoServidor = $state<Partial<Record<Campo, string>>>({});
	let mensagemDoServidor = $state('');
	let idCriado = $state<number | null>(null);
	let faseCriada = $state('');
	let copiou = $state(false);

	// Edição: o que já existe no acampamento.
	const SEM_SORTEIO: Sorteio = { feito: false, em: null };
	let resumo = $state({
		inscricoes: 0,
		inscricoesCampistas: 0,
		inscricoesServos: 0,
		sorteioCampistas: SEM_SORTEIO as Sorteio,
		sorteioServos: SEM_SORTEIO as Sorteio
	});
	let categoriaOriginal = $state('');

	// Apoio: formulários e setores de cada categoria.
	let categorias: CategoriaApoio[] = $state([]);
	let setoresDaAtividade: Setor[] | null = $state(null);

	// Imagem: nenhuma, nova (escolhida agora) ou a atual (edição).
	let imagem = $state<'nenhuma' | 'nova' | 'atual'>('nenhuma');
	let imagemOriginal = $state<'nenhuma' | 'atual'>('nenhuma');
	let arquivo = $state<File | null>(null);
	let previaArquivo = $state<string | null>(null);
	let imagemAtualUrl = $state<string | null>(null);
	let inputImagem = $state<HTMLInputElement>();

	// Edital e termo: nenhum, novo (escolhido agora) ou o atual (edição).
	const DOCS: [Doc, string][] = [
		['notice', 'Edital'],
		['term', 'Termo de responsabilidade']
	];
	type EstadoDoc = {
		estado: 'nenhum' | 'novo' | 'atual';
		original: 'nenhum' | 'atual';
		arquivo: File | null;
		url: string | null;
		nome: string;
		tamanho: number | null;
	};
	const docVazio = (): EstadoDoc => ({ estado: 'nenhum', original: 'nenhum', arquivo: null, url: null, nome: '', tamanho: null });
	let docs = $state<Record<Doc, EstadoDoc>>({ notice: docVazio(), term: docVazio() });
	let inputsDoc: Record<Doc, HTMLInputElement | undefined> = $state({ notice: undefined, term: undefined });

	const hoje = new Date();
	const hojeDia = isoDia(hoje);

	// ─── Datas ────────────────────────────────────────────────────────────
	function isoDia(x: Date) {
		return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
	}
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
	const plural = (n: number, um: string, varios: string) => `${n} ${n === 1 ? um : varios}`;

	// ─── Derivados ────────────────────────────────────────────────────────
	let categoriaApoio = $derived(categorias.find((c) => String(c.id) === f.categoria) ?? null);
	let nomeCategoria = $derived(categoriaApoio?.nome ?? '');
	let ehCasais = $derived(nomeCategoria === 'Casais');
	let trocouCategoria = $derived(editando && !!categoriaOriginal && f.categoria !== categoriaOriginal);
	let nomeCategoriaOriginal = $derived(categorias.find((c) => String(c.id) === categoriaOriginal)?.nome ?? '');

	/** Vagas do sorteio de campistas: [homens, mulheres, casais]; vazio conta 0. */
	let vagas = $derived.by(() => {
		const n = (v: unknown) => numero(v) ?? 0;
		return ehCasais ? { h: 0, m: 0, c: n(f.casais) } : { h: n(f.homens), m: n(f.mulheres), c: 0 };
	});
	let totalPessoas = $derived(vagas.h + vagas.m + 2 * vagas.c);
	let textoVagas = $derived.by(() => {
		if (ehCasais) return `${plural(vagas.c, 'casal', 'casais')} (${plural(totalPessoas, 'pessoa', 'pessoas')})`;
		const partes = [];
		if (vagas.h) partes.push(plural(vagas.h, 'homem', 'homens'));
		if (vagas.m) partes.push(plural(vagas.m, 'mulher', 'mulheres'));
		if (!partes.length) return 'nenhuma vaga';
		return `${partes.join(' e ')} (${plural(totalPessoas, 'pessoa', 'pessoas')})`;
	});

	let setores = $derived<Setor[]>(
		editando && setoresDaAtividade !== null && !trocouCategoria ? setoresDaAtividade : (categoriaApoio?.setores ?? [])
	);
	let vagasDeServos = $derived(setores.reduce((t, s) => t + s.vagas, 0));

	// ─── Validação (a mesma do servidor) ──────────────────────────────────
	function validarTrilha(e: Partial<Record<Campo, string>>, t: T) {
		const quem = t === 'c' ? 'campistas' : 'servos';
		const abre = f[`${t}Abre`];
		const fecha = f[`${t}Fecha`];
		const sorteio = f[`${t}Sorteio`];
		const prazo = f[`${t}Prazo`];
		const pago = f[`${t}Pago`];
		if (!abre) e[`${t}Abre`] = `Informe quando as inscrições de ${quem} abrem.`;
		if (!fecha) e[`${t}Fecha`] = `Informe quando as inscrições de ${quem} fecham.`;
		else if (abre && fecha < abre) e[`${t}Fecha`] = 'O fechamento não pode ser antes da abertura.';
		if (!sorteio) e[`${t}Sorteio`] = 'Informe a data prevista do sorteio.';
		else if (fecha && sorteio <= fecha)
			e[`${t}Sorteio`] = `O sorteio precisa ser depois do fechamento das inscrições: a partir de ${dma(maisDias(fecha, 1))}.`;
		else if (f.ini && sorteio >= f.ini) e[`${t}Sorteio`] = `O sorteio precisa ser antes do primeiro dia do acampamento (${dma(f.ini)}).`;
		if (!prazo) e[`${t}Prazo`] = pago ? 'Informe o prazo do formulário e do pagamento.' : 'Informe o prazo do formulário.';
		else if (sorteio && prazo < sorteio) e[`${t}Prazo`] = `O prazo não pode ser antes do sorteio previsto (${dma(sorteio)}).`;
		else if (f.ini && prazo > f.ini) e[`${t}Prazo`] = `O prazo precisa ser até o primeiro dia do acampamento (${dma(f.ini)}).`;
		if (pago) {
			const val = dinheiro(f[`${t}Valor`]);
			if (val === null || val <= 0)
				e[`${t}Valor`] = `Informe o valor por ${t === 'c' ? 'campista' : 'servo'}. Se não houver cobrança, escolha “Gratuito”.`;
		}
	}

	function validar(): Partial<Record<Campo, string>> {
		const e: Partial<Record<Campo, string>> = {};
		if (!f.nome.trim()) e.nome = 'Informe o nome do acampamento.';
		if (!f.categoria) e.categoria = 'Escolha a categoria.';
		if (!f.local.trim()) e.local = 'Informe o local.';
		if (!f.ini) e.ini = 'Informe o primeiro dia do acampamento.';
		else if (!editando && f.ini < hojeDia) e.ini = 'O primeiro dia já passou. Escolha hoje ou uma data futura.';
		if (!f.fim) e.fim = 'Informe o último dia do acampamento.';
		else if (f.ini && f.fim < f.ini) e.fim = 'O último dia não pode ser antes do primeiro.';
		validarTrilha(e, 'c');
		validarTrilha(e, 's');
		const campos = ehCasais ? [f.casais] : [f.homens, f.mulheres];
		if (campos.some((v) => txt(v) !== '' && numero(v) === null)) e.vagas = 'Use um número inteiro, 0 ou mais.';
		else if (f.categoria && totalPessoas < 1)
			e.vagas = ehCasais ? 'Informe quantos casais serão sorteados.' : 'Informe pelo menos uma vaga.';
		return e;
	}

	let todosOsErros = $derived(validar());
	/** O que aparece: a validação da tela e, por cima, o que o servidor recusou. */
	let erros = $derived<Partial<Record<Campo, string>>>(tentou ? { ...errosDoServidor, ...todosOsErros } : {});
	let listaDeErros = $derived((Object.keys(erros) as Campo[]).map((c) => ROTULO[c]));
	const errosDaAba = (a: Aba) => CAMPOS_DA_ABA[a].filter((c) => erros[c]).length;

	/** Aviso, sem impedir: menos de 3 dias entre o sorteio previsto e o prazo. */
	function avisoDoPrazo(t: T) {
		const sorteio = f[`${t}Sorteio`];
		const prazo = f[`${t}Prazo`];
		if (!sorteio || !prazo || prazo < sorteio) return '';
		const n = diasEntre(sorteio, prazo);
		if (n >= 3) return '';
		const tempo = n === 0 ? 'menos de 1 dia' : plural(n, 'dia', 'dias');
		return `Entre o sorteio previsto e o prazo há ${tempo}: pouco tempo para quem tiver vaga enviar o formulário${f[`${t}Pago`] ? ' e pagar' : ''}.`;
	}

	// ─── Resumos ──────────────────────────────────────────────────────────
	function sorteioDe(t: T): Sorteio {
		return editando ? (t === 'c' ? resumo.sorteioCampistas : resumo.sorteioServos) : SEM_SORTEIO;
	}

	function fraseResumo(t: T) {
		const quem = t === 'c' ? 'campistas' : 'servos';
		const abre = f[`${t}Abre`];
		const fecha = f[`${t}Fecha`];
		const sorteio = f[`${t}Sorteio`];
		const prazo = f[`${t}Prazo`];
		if (!abre || !fecha) return 'Preencha as datas para ver o resumo.';
		let r =
			hojeDia < abre
				? `As inscrições de ${quem} abrem em ${dmx(abre)} e vão até ${dmx(fecha)}.`
				: hojeDia <= fecha
					? `As inscrições de ${quem} estão abertas desde ${dmx(abre)} e vão até ${dmx(fecha)}.`
					: `As inscrições de ${quem} foram de ${dmx(abre)} a ${dmx(fecha)}.`;
		const oQue =
			t === 'c'
				? `${textoVagas}, com o mesmo número de suplentes`
				: setores.length
					? `completa as ${plural(vagasDeServos, 'vaga', 'vagas')} dos ${plural(setores.length, 'setor', 'setores')}`
					: 'completa as vagas que faltam em cada setor';
		const feito = sorteioDe(t);
		if (feito.feito) r += feito.em ? ` O sorteio foi feito em ${dmx(feito.em)}: ${oQue}.` : ` O sorteio já foi feito: ${oQue}.`;
		else if (sorteio && sorteio < hojeDia) r += ` O sorteio estava previsto para ${dmx(sorteio)} e ainda não foi feito: ${oQue}.`;
		else if (sorteio) r += ` O sorteio está previsto para ${dmx(sorteio)}: ${oQue}.`;
		if (prazo) {
			const val = dinheiro(f[`${t}Valor`]);
			const pagar = f[`${t}Pago`] && val ? ` e pagar ${brl(val)}` : '';
			r +=
				prazo >= hojeDia
					? ` Quem tiver vaga precisa enviar o formulário${pagar} até ${dmx(prazo)}.`
					: ` O prazo para quem tem vaga enviar o formulário${pagar} foi ${dmx(prazo)}.`;
		}
		return r;
	}

	function ajudaDoPrazo(t: T) {
		const pago = f[`${t}Pago`];
		const prazo = f[`${t}Prazo`];
		const pagar = pago ? `; quem não paga perde a vaga 2 dias depois${prazo ? `, em ${dmx(maisDias(prazo, 2))}` : ''}` : '';
		return `Até 23h59 deste dia, e no máximo até o primeiro dia do acampamento. Quem tem vaga e não envia o formulário até aqui perde a vaga${pagar}. Suplente chamado tem pelo menos 3 dias a partir da chamada, sem passar do primeiro dia.`;
	}

	// ─── Prévia ───────────────────────────────────────────────────────────
	let datasDaPrevia = $derived(
		datasDaAtividade({
			tipo: 'Acampamento',
			inicioEvento: f.ini ? `${f.ini}T00:00` : null,
			fimEvento: f.fim ? `${f.fim}T00:00` : f.ini ? `${f.ini}T00:00` : null,
			inicioInscricoes: f.cAbre ? `${f.cAbre}T00:00` : null,
			fimInscricoes: f.cFecha ? `${f.cFecha}T23:59` : null,
			inicioInscricoesServos: f.sAbre ? `${f.sAbre}T00:00` : null,
			fimInscricoesServos: f.sFecha ? `${f.sFecha}T23:59` : null,
			inicioSorteio: f.cSorteio ? `${f.cSorteio}T00:00` : null,
			sorteioCampistasRealizado: sorteioDe('c').feito
		})
	);
	let fasePrevia = $derived(faseDaAtividade(datasDaPrevia, hoje));
	let servosAteDaPrevia = $derived(servosAbertosAte(datasDaPrevia, fasePrevia, hoje));
	let imagemDaPrevia = $derived(imagem === 'nova' ? previaArquivo : imagem === 'atual' ? imagemAtualUrl : null);
	function valorDaPrevia(t: T) {
		if (!f[`${t}Pago`]) return 'Gratuito';
		const val = dinheiro(f[`${t}Valor`]);
		return val ? brl(val) : 'Pago · valor a definir';
	}
	function sorteioDaPrevia(t: T) {
		const feito = sorteioDe(t);
		if (feito.feito) return feito.em ? `feito em ${dmx(feito.em)}` : 'já feito';
		const d = f[`${t}Sorteio`];
		return d ? `previsto ${dmx(d)}` : 'a definir';
	}

	// ─── Carregar ─────────────────────────────────────────────────────────
	async function carregarApoio() {
		try {
			const res = await apiFetch(`/admin/acampamentos/apoio${editando ? `?atividade=${activityId}` : ''}`);
			const data = await res.json();
			if (!res.ok) return;
			categorias = data.data?.categorias ?? [];
			setoresDaAtividade = data.data?.setoresDaAtividade ?? null;
		} catch {
			categorias = [];
		}
	}

	const soDia = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '');
	const nomeDoArquivo = (caminho: string) => caminho.split('/').pop() ?? caminho;
	const urlDe = (caminho: string) => (caminho.startsWith('http') ? caminho : `${API_HOST}/${caminho}`);

	/** Carrega o acampamento. `silencioso`: depois de salvar, sem trocar a tela pelo esqueleto. */
	async function carregarAcampamento(silencioso = false) {
		if (!silencioso) estado = 'carregando';
		try {
			const res = await apiFetch(`/activities/${activityId}/edit`);
			const data = await res.json();
			const a = data.data?.activity;
			const c = data.data?.camping;
			if (!res.ok || !a || !c) {
				mensagemDoServidor = data.error || 'Não foi possível carregar o acampamento.';
				estado = 'erroServidor';
				return;
			}
			const taxaC = Number(a.participant_fee ?? 0);
			const taxaS = Number(a.servant_fee ?? 0);
			const ini = soDia(a.start_date);
			const n = (v: unknown) => (v === null || v === undefined ? '' : String(v));
			f = {
				nome: a.name ?? '',
				categoria: a.category_id ? String(a.category_id) : '',
				local: a.place ?? '',
				ini,
				fim: ini ? maisDias(ini, Math.max(0, Number(a.duration_days ?? 1) - 1)) : '',
				sobre: a.description ?? '',
				cAbre: soDia(a.participant_registration_start_date),
				cFecha: soDia(a.participant_registration_end_date),
				cSorteio: soDia(c.raffle_camper_date),
				cPrazo: soDia(c.camper_form_deadline),
				homens: n(c.raffle_man_vacancies),
				mulheres: n(c.raffle_woman_vacancies),
				casais: n(c.raffle_couple_vacancies),
				cPago: taxaC > 0,
				cValor: taxaC > 0 ? taxaC.toFixed(2).replace('.', ',') : '',
				sAbre: soDia(c.raffle_servant_subscription_start_date),
				sFecha: soDia(c.raffle_servant_subscription_end_date),
				sSorteio: soDia(c.raffle_servant_date),
				sPrazo: soDia(c.servant_form_deadline),
				sPago: taxaS > 0,
				sValor: taxaS > 0 ? taxaS.toFixed(2).replace('.', ',') : ''
			};
			categoriaOriginal = f.categoria;
			const r = data.data.resumo ?? {};
			resumo = {
				inscricoes: r.inscricoes ?? 0,
				inscricoesCampistas: r.inscricoesCampistas ?? 0,
				inscricoesServos: r.inscricoesServos ?? 0,
				sorteioCampistas: r.sorteioCampistas ?? SEM_SORTEIO,
				sorteioServos: r.sorteioServos ?? SEM_SORTEIO
			};
			imagem = imagemOriginal = 'nenhuma';
			arquivo = null;
			if (a.image && a.image !== '#') {
				imagemAtualUrl = urlDe(a.image);
				imagem = imagemOriginal = 'atual';
			}
			for (const [d] of DOCS) {
				const caminho = c[d];
				docs[d] =
					caminho && caminho !== '#'
						? { estado: 'atual', original: 'atual', arquivo: null, url: urlDe(caminho), nome: nomeDoArquivo(caminho), tamanho: r.documentos?.[d] ?? null }
						: docVazio();
			}
			if (!silencioso) estado = 'normal';
		} catch {
			mensagemDoServidor = 'Não foi possível carregar o acampamento. Verifique sua conexão.';
			estado = 'erroServidor';
		}
	}

	onMount(() => {
		carregarApoio();
		if (activityId !== null) carregarAcampamento();
	});

	$effect(() => {
		caminhoDoPainel.subtela = editando ? 'Editar acampamento' : null;
	});
	onDestroy(() => {
		caminhoDoPainel.subtela = null;
		if (previaArquivo) URL.revokeObjectURL(previaArquivo);
	});

	// ─── Campos ───────────────────────────────────────────────────────────
	function mudarPrimeiroDia(valor: string) {
		// O último dia acompanha o primeiro enquanto estava vazio ou igual.
		if (!f.fim || f.fim === f.ini) f.fim = valor;
		f.ini = valor;
	}

	let podeCopiar = $derived(!!(f.cAbre && f.cFecha && f.cSorteio && f.cPrazo));
	function copiarDatasDosCampistas() {
		f.sAbre = f.cAbre;
		f.sFecha = f.cFecha;
		f.sSorteio = f.cSorteio;
		f.sPrazo = f.cPrazo;
		copiou = true;
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
			ajuda: imagemOriginal === 'atual' ? 'A nova imagem substitui a atual quando você salvar.' : 'É assim que ela aparece no arco do cartão.'
		};
	});

	function escolherDoc(d: Doc, e: Event) {
		const escolhido = (e.currentTarget as HTMLInputElement).files?.[0];
		if (!escolhido) return;
		docs[d].arquivo = escolhido;
		docs[d].estado = 'novo';
		(e.currentTarget as HTMLInputElement).value = '';
	}
	function removerDoc(d: Doc) {
		docs[d].arquivo = null;
		docs[d].estado = 'nenhum';
	}
	function manterDoc(d: Doc) {
		docs[d].arquivo = null;
		docs[d].estado = 'atual';
	}
	function iconeDoDoc(x: EstadoDoc) {
		if (x.estado === 'nenhum') return '—';
		const nome = x.estado === 'novo' ? (x.arquivo?.name ?? '') : x.nome;
		return /\.pdf$/i.test(nome) ? 'PDF' : 'IMG';
	}
	function infoDoDoc(x: EstadoDoc) {
		if (x.estado === 'novo' && x.arquivo)
			return `${x.arquivo.name} · ${tamanho(x.arquivo.size)}${x.original === 'atual' ? ' · substitui o atual quando você salvar' : ''}`;
		if (x.estado === 'atual') return `Atual: ${x.nome}${x.tamanho ? ` · ${tamanho(x.tamanho)}` : ''}`;
		if (x.original === 'atual') return 'Será removido quando você salvar.';
		return 'Nenhum arquivo.';
	}

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
		if (!editando) body.append('type', 'Camping');
		const atividade: Record<string, string> = {
			name: f.nome.trim(),
			place: f.local.trim(),
			category_id: f.categoria,
			description: f.sobre,
			start_date: `${f.ini}T00:00`,
			duration_days: String(diasEntre(f.ini, f.fim) + 1),
			participant_registration_start_date: `${f.cAbre}T00:00`,
			participant_registration_end_date: `${f.cFecha}T23:59`,
			participant_fee: f.cPago ? String(dinheiro(f.cValor) ?? 0) : '0',
			servant_fee: f.sPago ? String(dinheiro(f.sValor) ?? 0) : '0'
		};
		// O servidor iguala o prazo do pagamento ao do formulário e soma o total de vagas.
		const acampamento: Record<string, string> = {
			raffle_man_vacancies: String(vagas.h),
			raffle_woman_vacancies: String(vagas.m),
			raffle_couple_vacancies: String(vagas.c),
			raffle_camper_date: `${f.cSorteio}T00:00`,
			camper_form_deadline: `${f.cPrazo}T23:59`,
			raffle_servant_subscription_start_date: `${f.sAbre}T00:00`,
			raffle_servant_subscription_end_date: `${f.sFecha}T23:59`,
			raffle_servant_date: `${f.sSorteio}T00:00`,
			servant_form_deadline: `${f.sPrazo}T23:59`
		};
		for (const [k, v] of Object.entries(atividade)) body.append(`activity[${k}]`, v);
		for (const [k, v] of Object.entries(acampamento)) body.append(`camping[${k}]`, v);
		if (imagem === 'nova' && arquivo) body.append('activity[image]', arquivo);
		if (editando && imagem === 'nenhuma' && imagemOriginal === 'atual') body.append('activity[remove_image]', '1');
		for (const [d] of DOCS) {
			const x = docs[d];
			if (x.estado === 'novo' && x.arquivo) body.append(`camping[${d}]`, x.arquivo);
			if (editando && x.estado === 'nenhum' && x.original === 'atual') body.append(`camping[remove_${d}]`, '1');
		}
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
				// Recarrega para mostrar o que ficou gravado (imagem, edital e termo atuais).
				await carregarAcampamento(true);
				copiou = false;
				if (estado === 'salvando') estado = 'salvo';
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
		aba = 'acampamento';
		tentou = false;
		copiou = false;
		errosDoServidor = {};
		idCriado = null;
		removerImagem();
		docs = { notice: docVazio(), term: docVazio() };
		estado = 'normal';
		await tick();
		document.getElementById('f-nome')?.focus();
	}

	let proximaAba = $derived(ABAS[ABAS.findIndex(([k]) => k === aba) + 1]);
</script>

<div class="ev">
	{#if estado === 'carregando'}
		<div class="osso-titulo"><span class="osso" style="height:44px;width:360px"></span><span class="osso claro" style="height:20px;width:480px"></span></div>
		<section class="cartao" aria-busy="true">
			<div class="carregando"><span class="spinner"></span>Carregando o acampamento...</div>
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
					<h1 class="criado-titulo">Acampamento criado!</h1>
					<p class="criado-texto">“{f.nome}” já aparece no Início com o selo “{faseCriada}”. Lembre de conferir as vagas dos setores em Equipes de Servos.</p>
					<div class="criado-acoes">
						{#if idCriado}<a href="/dashboard/atividades/{idCriado}" class="btn b-ouro">Ver como o público vê</a>{/if}
						<a href="/admin/atividades" class="btn b-azul b-grande">Ir para a Gestão de Atividades</a>
					</div>
					<button type="button" class="link" onclick={criarOutro}>Criar outro acampamento</button>
				</div>
			</section>
			{@render previa()}
		</div>
	{:else}
		<div class="cabeca">
			<div class="cabeca-textos">
				<h1 class="titulo">{editando ? 'Editar acampamento' : 'Criar acampamento'}</h1>
				<p class="subtitulo">
					{editando ? f.nome : 'A atividade principal da comunidade: inscrição de campistas e servos, com sorteio das vagas.'}
				</p>
			</div>
			{#if editando}<a href="/dashboard/atividades/{activityId}" target="_blank" rel="noopener" class="btn b-claro">Ver página do acampamento ↗</a>{/if}
		</div>

		{#if estado === 'erroServidor'}
			<div class="erro-servidor" role="alert">
				<span class="err grande"><i>!</i><span>{mensagemDoServidor || 'Não foi possível salvar o acampamento. Nada do que você preencheu foi perdido: tente de novo em alguns instantes.'}</span></span>
				{#if !mensagemDoServidor}<button type="button" class="btn b-perigo" onclick={salvar}>Tentar de novo</button>{/if}
			</div>
		{/if}

		<div class="corpo">
			<div class="coluna">
				<div class="abas" role="tablist" aria-label="Partes do cadastro do acampamento">
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
						{#if aba === 'acampamento'}
							{#if trocouCategoria && resumo.inscricoes > 0}
								<p class="aviso av-amb" role="status"><i></i><span><strong>Você trocou a categoria.</strong> Já há {plural(resumo.inscricoes, 'inscrição', 'inscrições')}. Daqui para a frente, quem pode se inscrever segue as regras de {nomeCategoria}, e os setores do modelo {nomeCategoria} entram junto com os que já existem.</span></p>
							{/if}
							<div class="grade2">
								<div class="campo-bloco">
									<label for="f-nome" class="rotulo">Nome do acampamento <span class="req" aria-hidden="true">*</span></label>
									<input id="f-nome" class="campo" class:erro={erros.nome} bind:value={f.nome} placeholder="Ex.: Acampamento FAC 2027" aria-required="true" aria-invalid={!!erros.nome} aria-describedby="e-nome" />
									{#if erros.nome}<p id="e-nome" class="err"><i>!</i>{erros.nome}</p>{/if}
								</div>
								<div class="campo-bloco">
									<label for="f-cat" class="rotulo">Categoria <span class="req" aria-hidden="true">*</span></label>
									<select id="f-cat" class="campo" class:erro={erros.categoria} bind:value={f.categoria} aria-required="true" aria-invalid={!!erros.categoria} aria-describedby="e-cat">
										<option value="" disabled>Selecione a categoria</option>
										{#each categorias as c (c.id)}<option value={String(c.id)}>{c.nome}</option>{/each}
									</select>
									{#if erros.categoria}<p id="e-cat" class="err"><i>!</i>{erros.categoria}</p>{/if}
								</div>
							</div>
							{#if nomeCategoria && REGRA[nomeCategoria]}
								<p class="aviso av-azul regra"><i></i><span><strong>{nomeCategoria}:</strong> {REGRA[nomeCategoria][0]} <strong>Servos:</strong> {REGRA[nomeCategoria][1]}</span></p>
							{/if}
							<div class="campo-bloco">
								<label for="f-local" class="rotulo">Local <span class="req" aria-hidden="true">*</span></label>
								<input id="f-local" class="campo" class:erro={erros.local} bind:value={f.local} placeholder="Ex.: Sítio Recanto da Paz" aria-required="true" aria-invalid={!!erros.local} aria-describedby="e-local" />
								{#if erros.local}<p id="e-local" class="err"><i>!</i>{erros.local}</p>{/if}
							</div>
							<div class="grade2">
								<div class="campo-bloco">
									<label for="f-ini" class="rotulo">Primeiro dia <span class="req" aria-hidden="true">*</span></label>
									<input id="f-ini" type="date" class="campo" class:erro={erros.ini} value={f.ini} oninput={(e) => mudarPrimeiroDia(e.currentTarget.value)} aria-required="true" aria-invalid={!!erros.ini} aria-describedby="ini-ajuda e-ini" />
									{#if erros.ini}<p id="e-ini" class="err"><i>!</i>{erros.ini}</p>{/if}
									<p id="ini-ajuda" class="ajuda">O ano do acampamento vem desta data.</p>
								</div>
								<div class="campo-bloco">
									<label for="f-fim" class="rotulo">Último dia <span class="req" aria-hidden="true">*</span></label>
									<input id="f-fim" type="date" class="campo" class:erro={erros.fim} bind:value={f.fim} aria-required="true" aria-invalid={!!erros.fim} aria-describedby="fim-ajuda e-fim" />
									{#if erros.fim}<p id="e-fim" class="err"><i>!</i>{erros.fim}</p>{/if}
									<p id="fim-ajuda" class="ajuda">No dia seguinte ao último, o acampamento sai do Início.</p>
								</div>
							</div>

							<div class="campo-bloco">
								<label for="f-sobre" class="rotulo">Sobre o acampamento <span class="opc">opcional</span></label>
								<textarea id="f-sobre" class="campo texto" rows="6" bind:value={f.sobre} placeholder="Programação, horário de chegada e saída, o que levar..." aria-describedby="sobre-ajuda"></textarea>
								<p id="sobre-ajuda" class="ajuda">Aparece na página do acampamento, do jeito que você escrever. Como as datas não têm hora, diga aqui os horários: “Chegada às 17h da sexta”.</p>
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

							<div class="campo-bloco">
								<span class="rotulo">Documentos <span class="opc">opcional</span></span>
								<div class="docs">
									{#each DOCS as [d, nome] (d)}
										{@const x = docs[d]}
										<div class="doc">
											<span class="doc-icone" class:doc-vazio={x.estado === 'nenhum'} aria-hidden="true">{iconeDoDoc(x)}</span>
											<div class="doc-textos">
												<span class="doc-nome">{nome}</span>
												<span class="doc-info" class:doc-removido={x.estado === 'nenhum' && x.original === 'atual'}>{infoDoDoc(x)}</span>
											</div>
											<div class="doc-acoes">
												{#if x.estado === 'atual' && x.url}<a href={x.url} target="_blank" rel="noopener" class="link">Ver ↗</a>{/if}
												<button type="button" class="btn b-azul" onclick={() => inputsDoc[d]?.click()}>{x.estado === 'nenhum' ? 'Escolher arquivo' : 'Trocar'}</button>
												{#if x.estado !== 'nenhum'}<button type="button" class="btn b-perigo" onclick={() => removerDoc(d)}>Remover</button>{/if}
												{#if x.original === 'atual' && x.estado !== 'atual'}<button type="button" class="link" onclick={() => manterDoc(d)}>Manter o atual</button>{/if}
											</div>
											<input bind:this={inputsDoc[d]} type="file" accept=".pdf,image/*" class="escondido" onchange={(e) => escolherDoc(d, e)} tabindex="-1" aria-hidden="true" />
										</div>
									{/each}
								</div>
								<p class="ajuda">PDF ou imagem. Aparecem em “Documentos” na página do acampamento.</p>
							</div>
						{:else if aba === 'campistas'}
							{@render trilha('c')}
						{:else}
							{@render trilha('s')}
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
				<span class="barra-texto"><span class="ponto" class:ponto-edicao={editando} aria-hidden="true"></span>{editando ? 'Salve quando quiser, de qualquer aba.' : 'Os campos com * são obrigatórios. Você pode criar o acampamento de qualquer aba.'}</span>
			{/if}
			<div class="barra-acoes">
				<a href="/admin/atividades" class="btn b-azul b-grande">Cancelar</a>
				<button type="button" class="btn b-ouro" onclick={salvar} disabled={estado === 'salvando'} aria-busy={estado === 'salvando'}>
					{estado === 'salvando' ? 'Salvando...' : editando ? 'Salvar alterações' : 'Criar acampamento'}
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

{#snippet trilha(t: T)}
	{@const quem = t === 'c' ? 'campistas' : 'servos'}
	{@const umDeles = t === 'c' ? 'campista' : 'servo'}
	{@const inscricoes = t === 'c' ? resumo.inscricoesCampistas : resumo.inscricoesServos}
	{@const sorteio = sorteioDe(t)}
	{@const temFormulario = categoriaApoio ? (t === 'c' ? categoriaApoio.formularioCampista : categoriaApoio.formularioServo) : true}
	{@const aviso = avisoDoPrazo(t)}

	{#if editando && inscricoes > 0}
		<p class="aviso av-azul"><i></i><span>Já há <strong>{plural(inscricoes, `inscrição de ${umDeles}`, `inscrições de ${quem}`)}</strong>. Mudanças nas datas valem também para elas.</span></p>
	{/if}
	{#if sorteio.feito}
		<p class="aviso av-amb"><i></i><span><strong>O sorteio de {quem} já foi feito{sorteio.em ? ` em ${dma(sorteio.em)}` : ''}.</strong> {t === 'c' ? 'Mudar as vagas ou a data prevista' : 'Mudar a data prevista'} não refaz o sorteio. Para refazer, use Inscritos e Sorteios.</span></p>
	{/if}

	<div class="secao secao-linha">
		<div class="secao">
			<h2 class="h3">Inscrições e sorteio</h2>
			<p class="ajuda">
				{t === 'c'
					? 'O sorteio é feito à mão pela organização, em Inscritos e Sorteios. A data aqui é a prevista: aparece no cronograma e no selo “Aguardando sorteio” até o sorteio ser feito.'
					: 'Os servos têm datas próprias. O sorteio de servos completa as vagas que faltam em cada setor.'}
			</p>
		</div>
		{#if t === 's'}
			<div class="copiar">
				<button type="button" class="btn b-azul" onclick={copiarDatasDosCampistas} disabled={!podeCopiar}>Copiar datas dos campistas</button>
				{#if !podeCopiar}<span class="ajuda">Preencha primeiro as datas dos campistas.</span>{/if}
			</div>
		{/if}
	</div>
	{#if t === 's' && copiou}
		<p class="aviso av-azul" role="status"><i></i><span><strong>Datas copiadas dos campistas:</strong> abertura, fechamento, sorteio previsto e prazo. Você pode mudar qualquer uma.</span></p>
	{/if}
	<div class="grade3">
		<div class="campo-bloco">
			<label for="f-{t}abre" class="rotulo">Abertura <span class="req" aria-hidden="true">*</span></label>
			<input id="f-{t}abre" type="date" class="campo" class:erro={erros[`${t}Abre`]} bind:value={f[`${t}Abre`]} aria-required="true" aria-invalid={!!erros[`${t}Abre`]} aria-describedby="{t}abre-ajuda e-{t}abre" />
			{#if erros[`${t}Abre`]}<p id="e-{t}abre" class="err"><i>!</i>{erros[`${t}Abre`]}</p>{/if}
			<p id="{t}abre-ajuda" class="ajuda">Abre às 00h00 deste dia.</p>
		</div>
		<div class="campo-bloco">
			<label for="f-{t}fecha" class="rotulo">Fechamento <span class="req" aria-hidden="true">*</span></label>
			<input id="f-{t}fecha" type="date" class="campo" class:erro={erros[`${t}Fecha`]} bind:value={f[`${t}Fecha`]} aria-required="true" aria-invalid={!!erros[`${t}Fecha`]} aria-describedby="{t}fecha-ajuda e-{t}fecha" />
			{#if erros[`${t}Fecha`]}<p id="e-{t}fecha" class="err"><i>!</i>{erros[`${t}Fecha`]}</p>{/if}
			<p id="{t}fecha-ajuda" class="ajuda">Fecha às 23h59 deste dia.</p>
		</div>
		<div class="campo-bloco">
			<label for="f-{t}sorteio" class="rotulo">Sorteio previsto <span class="req" aria-hidden="true">*</span></label>
			<input id="f-{t}sorteio" type="date" class="campo" class:erro={erros[`${t}Sorteio`]} bind:value={f[`${t}Sorteio`]} aria-required="true" aria-invalid={!!erros[`${t}Sorteio`]} aria-describedby="{t}sorteio-ajuda e-{t}sorteio" />
			{#if erros[`${t}Sorteio`]}<p id="e-{t}sorteio" class="err"><i>!</i>{erros[`${t}Sorteio`]}</p>{/if}
			<p id="{t}sorteio-ajuda" class="ajuda">Data prevista. O sorteio é feito à mão em Inscritos e Sorteios.</p>
		</div>
	</div>

	<div class="divisoria"></div>
	{#if t === 'c'}
		<div class="secao">
			<h2 class="h3">Vagas</h2>
			<p class="ajuda">
				{!f.categoria
					? 'Escolha a categoria na aba “O acampamento”: no Casais só há vagas de casais.'
					: ehCasais
						? 'No Casais, só há vagas de casais: o casal é sorteado junto e sai junto.'
						: 'Pessoas casadas que se inscrevem fora do Casais entram como homem ou mulher.'}
			</p>
		</div>
		<div class="vagas-linha">
			{#if ehCasais}
				<div class="campo-bloco curto">
					<label for="f-casais" class="rotulo">Casais</label>
					<input id="f-casais" type="number" min="0" inputmode="numeric" class="campo" class:erro={erros.vagas} bind:value={f.casais} placeholder="0" aria-invalid={!!erros.vagas} aria-describedby="vagas-ajuda e-vagas" />
				</div>
			{:else}
				<div class="campo-bloco curto">
					<label for="f-homens" class="rotulo">Homens</label>
					<input id="f-homens" type="number" min="0" inputmode="numeric" class="campo" class:erro={erros.vagas} bind:value={f.homens} placeholder="0" aria-invalid={!!erros.vagas} aria-describedby="vagas-ajuda e-vagas" />
				</div>
				<div class="campo-bloco curto">
					<label for="f-mulheres" class="rotulo">Mulheres</label>
					<input id="f-mulheres" type="number" min="0" inputmode="numeric" class="campo" class:erro={erros.vagas} bind:value={f.mulheres} placeholder="0" aria-invalid={!!erros.vagas} aria-describedby="vagas-ajuda e-vagas" />
				</div>
			{/if}
			<div class="total-vagas"><span class="rot">Total no sorteio</span><strong>{textoVagas}</strong></div>
		</div>
		{#if erros.vagas}<p id="e-vagas" class="err"><i>!</i>{erros.vagas}</p>{/if}
		<p id="vagas-ajuda" class="ajuda">É o número de sorteados de cada grupo. O sorteio também escolhe o mesmo número de suplentes. Indicações (Base, Forania, Conselho) ficam fora dessa conta e não têm limite. Na página do acampamento aparece o total.</p>
	{:else}
		<div class="setores">
			<span class="rot">Vagas de servos por setor</span>
			{#if !f.categoria}
				<p class="ajuda">Escolha a categoria para ver os setores.</p>
			{:else}
				<strong class="setores-titulo">{plural(setores.length, 'setor', 'setores')}, {plural(vagasDeServos, 'vaga de servos', 'vagas de servos')}</strong>
				<p class="ajuda">
					{editando && setoresDaAtividade !== null
						? `Deste acampamento (copiadas do modelo ${nomeCategoriaOriginal || nomeCategoria} na criação).${trocouCategoria ? ` Ao salvar, entram também os setores do modelo ${nomeCategoria}.` : ''}`
						: `Vindas do modelo ${nomeCategoria}. São copiadas quando o acampamento é criado.`}
				</p>
				{#if setores.length}
					<ul class="pilulas">
						{#each setores as s}<li>{s.nome} <strong>{s.vagas}</strong></li>{/each}
					</ul>
				{/if}
				<p class="ajuda">
					{editando
						? 'As vagas por setor não são definidas aqui. Ajuste as deste acampamento na tela de equipes.'
						: 'As vagas por setor não são definidas aqui. Depois de criar, ajuste as deste acampamento na tela de equipes; o modelo de cada categoria fica em Equipes de Servos › Modelo por categoria.'}
				</p>
				<div class="links">
					{#if editando && pode('equipes')}<a href="/admin/equipes?atividade={activityId}" class="link">Ajustar a equipe deste acampamento</a>{/if}
					{#if pode('setores')}<a href="/admin/equipes?aba=modelo" class="link">Ver o modelo por categoria</a>{/if}
				</div>
			{/if}
		</div>
	{/if}

	<div class="divisoria"></div>
	<div class="secao"><h2 class="h3">Formulário e pagamento</h2></div>
	<fieldset class="grupo">
		<legend class="rotulo">Para {quem}, o acampamento é</legend>
		<div role="radiogroup" aria-label="Para {quem}, o acampamento é" class="tipos">
			{#each [[false, 'Gratuito', `Nenhum ${umDeles} paga.`], [true, 'Pago', `Cada ${umDeles} com vaga paga direto com a organização.`]] as [pago, nome, desc]}
				<button type="button" role="radio" aria-checked={f[`${t}Pago`] === pago} class="tipo" class:sel={f[`${t}Pago`] === pago} onclick={() => (f[`${t}Pago`] = pago as boolean)}>
					<span class="bolinha" aria-hidden="true"></span>
					<span class="tipo-textos"><span class="tipo-nome">{nome}</span><span class="tipo-desc">{desc}</span></span>
				</button>
			{/each}
		</div>
	</fieldset>
	<div class="grade2">
		{#if f[`${t}Pago`]}
			<div class="campo-bloco">
				<label for="f-{t}valor" class="rotulo">Valor por {umDeles} (R$) <span class="req" aria-hidden="true">*</span></label>
				<div class="dinheiro">
					<span aria-hidden="true">R$</span>
					<input id="f-{t}valor" inputmode="decimal" class="campo" class:erro={erros[`${t}Valor`]} bind:value={f[`${t}Valor`]} placeholder="0,00" aria-required="true" aria-invalid={!!erros[`${t}Valor`]} aria-describedby="e-{t}valor" />
				</div>
				{#if erros[`${t}Valor`]}<p id="e-{t}valor" class="err"><i>!</i>{erros[`${t}Valor`]}</p>{/if}
			</div>
		{/if}
		<div class="campo-bloco">
			<label for="f-{t}prazo" class="rotulo">{f[`${t}Pago`] ? 'Prazo do formulário e do pagamento' : 'Prazo do formulário'} <span class="req" aria-hidden="true">*</span></label>
			<input id="f-{t}prazo" type="date" class="campo" class:erro={erros[`${t}Prazo`]} bind:value={f[`${t}Prazo`]} aria-required="true" aria-invalid={!!erros[`${t}Prazo`]} aria-describedby="{t}prazo-ajuda e-{t}prazo" />
			{#if erros[`${t}Prazo`]}<p id="e-{t}prazo" class="err"><i>!</i>{erros[`${t}Prazo`]}</p>{/if}
		</div>
	</div>
	<p id="{t}prazo-ajuda" class="ajuda">{ajudaDoPrazo(t)}</p>
	{#if aviso}<p class="aviso av-amb" role="status"><i></i><span>{aviso}</span></p>{/if}
	{#if nomeCategoria}
		{#if temFormulario}
			<p class="ajuda">
				O formulário de {umDeles} do {nomeCategoria} é montado em
				{#if pode('formularios')}<a href="/admin/formularios/{f.categoria}/{umDeles}" target="_blank" rel="noopener" class="link">Formulários ↗</a>{:else}Formulários{/if}.
				Quem tem vaga precisa enviá-lo até o prazo.
			</p>
		{:else}
			<p class="aviso av-amb"><i></i><span>
				<strong>O formulário de {umDeles} do {nomeCategoria} ainda não existe.</strong> Monte em
				{#if pode('formularios')}<a href="/admin/formularios/{f.categoria}/{umDeles}" target="_blank" rel="noopener" class="link">Formulários ↗</a>{:else}Formulários{/if}
				antes do sorteio: sem ele, quem tem vaga não consegue enviar.
			</span></p>
		{/if}
	{/if}

	<div class="resumo">
		<span class="losango" aria-hidden="true"></span>
		<div><span class="rot">Resumo</span><p>{fraseResumo(t)}</p></div>
	</div>
{/snippet}

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
					<span class="etiqueta">Acampamento</span>
					<span class="card-situacao"><SeloFase fase={fasePrevia} /></span>
				</div>
			</div>
			<div class="card-corpo">
				<h3 class="card-titulo" class:vazio={!f.nome.trim()}>{f.nome.trim() || 'Nome do acampamento'}</h3>
				<div class="filete"><span class="filete-traco"></span><span class="filete-losango"></span><span class="filete-linha"></span></div>
				<p class="card-quando">{fraseAcontece(datasDaPrevia, fasePrevia, hoje)}</p>
				<dl class="card-dados">
					<dt>Público</dt><dd>{nomeCategoria || '—'}</dd>
					<dt>Local</dt><dd>{f.local.trim() || '—'}</dd>
				</dl>
				{#if servosAteDaPrevia}
					<p class="card-servos"><span class="losango-servos" aria-hidden="true"></span><span>Inscrições para servos abertas até <strong>{diaMes(servosAteDaPrevia)}</strong></span></p>
				{/if}
				<div class="card-botoes">
					<span class="btn-detalhes">Ver Detalhes</span>
					{#if algumaInscricaoAberta(datasDaPrevia, hoje)}<span class="btn-inscrever">Inscrever-se</span>{/if}
				</div>
			</div>
		</article>
		<div class="previa-rodape">
			<span><strong>Campista:</strong> {valorDaPrevia('c')}</span>
			<span><strong>Servo:</strong> {valorDaPrevia('s')}</span>
			<span><strong>Sorteios:</strong> campistas {sorteioDaPrevia('c')} · servos {sorteioDaPrevia('s')}</span>
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
		left: 10px;
		right: 10px;
		bottom: 12px;
		/* Igual ao cartão do Início: tipo à esquerda, fase à direita, na mesma linha. */
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 6px;
	}
	.card-situacao {
		display: flex;
	}
	.card-situacao :global(.selo) {
		font-size: 11px;
		letter-spacing: 0.02em;
		padding: 5px 8px;
	}
	.etiqueta {
		font-size: 11px;
		font-weight: 700;
		letter-spacing: 0.02em;
		text-transform: uppercase;
		color: var(--pergaminho);
		background: var(--azul);
		border: 1px solid var(--ouro);
		border-radius: 999px;
		padding: 5px 8px;
		white-space: nowrap;
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

	/* ─── Só no acampamento ──────────────────────────────────────── */
	.grade3 {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 20px 24px;
	}
	.secao-linha {
		flex-direction: row;
		align-items: flex-start;
		justify-content: space-between;
		gap: 20px;
	}
	.copiar {
		flex: 0 0 auto;
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 6px;
		max-width: 240px;
		text-align: right;
	}
	.copiar .ajuda {
		font-size: 14px;
	}
	.vagas-linha {
		display: flex;
		align-items: flex-end;
		gap: 16px;
		flex-wrap: wrap;
	}
	.vagas-linha .campo-bloco.curto {
		width: 150px;
	}
	.total-vagas {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 10px 16px;
		min-height: 52px;
		justify-content: center;
		background: #fff;
		border: 1px solid var(--borda-suave);
		border-radius: 10px;
	}
	.total-vagas strong {
		font-size: 17px;
		color: var(--azul);
	}
	.setores {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 18px 20px;
		background: #fff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}
	.setores-titulo {
		font-size: 19px;
		color: var(--azul);
	}
	.pilulas {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}
	.pilulas li {
		padding: 6px 12px;
		font-size: 15px;
		color: var(--tinta-2);
		background: #fbf6e6;
		border: 1px solid var(--borda-suave);
		border-radius: 999px;
	}
	.pilulas strong {
		color: var(--azul);
	}
	.docs {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.doc {
		display: flex;
		align-items: center;
		gap: 14px;
		padding: 12px 14px;
		background: #fff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		flex-wrap: wrap;
	}
	.doc-icone {
		flex: 0 0 40px;
		height: 48px;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.04em;
		color: #6b5822;
		background: #f1e8cf;
		border-radius: 6px;
	}
	.doc-icone.doc-vazio {
		color: var(--tinta-3);
		background: #efebe1;
	}
	.doc-textos {
		flex: 1 1 200px;
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}
	.doc-nome {
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta);
	}
	.doc-info {
		font-size: 15px;
		color: var(--tinta-3);
		overflow-wrap: anywhere;
	}
	.doc-info.doc-removido {
		color: #842424;
		font-weight: 600;
	}
	.doc-acoes {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
	}
	.card-servos {
		margin: 0;
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 15px;
		line-height: 1.35;
		color: var(--tinta-2);
	}
	.losango-servos {
		flex: 0 0 7px;
		width: 7px;
		height: 7px;
		background: var(--ouro);
		transform: rotate(45deg);
	}
	@media (max-width: 1100px) {
		.grade3 {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
	}
</style>
