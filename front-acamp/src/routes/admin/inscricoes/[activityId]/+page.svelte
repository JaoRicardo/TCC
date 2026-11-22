<script lang="ts">
	/**
	 * Inscritos e sorteio (handoff `Desing/design_handoff_inscritos_sorteio`).
	 * Acampamento: abas "Inscritos", "Sorteio de campistas" e "Sorteio de
	 * servos". Evento: só a lista. Todo confirm()/alert() virou janela do painel.
	 */
	import { onDestroy, onMount } from 'svelte';
	import { page } from '$app/stores';
	import { apiFetch } from '$lib/api';
	import '$lib/styles/formularios.css';
	import '$lib/styles/inscritos.css';
	import logoAsas from '$lib/imgs/logo-asas.png';
	import Janela from '$lib/components/painel/Janela.svelte';
	import SeloFase from '$lib/components/SeloFase.svelte';
	import PagamentosAtividade from '$lib/components/painel/PagamentosAtividade.svelte';
	import AbaInscritos from '$lib/components/painel/inscritos/AbaInscritos.svelte';
	import SorteioCampistas from '$lib/components/painel/inscritos/SorteioCampistas.svelte';
	import SorteioServos from '$lib/components/painel/inscritos/SorteioServos.svelte';
	import JanelaIndicar from '$lib/components/painel/inscritos/JanelaIndicar.svelte';
	import { datasDaAtividade, faseDaAtividade } from '$lib/faseAtividade';
	import { dm, dma, naFolga } from '$lib/pagamentos';
	import {
		COM_VAGA,
		SAIU,
		ehIndicado,
		naoPago,
		plural,
		situacaoDe,
		textoDasVagas,
		unidades,
		type Inscricao,
		type SorteioCampistas as TSorteioCampistas,
		type SorteioServos as TSorteioServos,
		type UnidadeChamada
	} from '$lib/inscritos';

	const atividadeId = Number($page.params.activityId as string);
	const agora = new Date();

	let atividade: any = $state(null);
	let inscricoes: Inscricao[] = $state([]);
	let sorteio = $state<{ campistas: TSorteioCampistas; servos: TSorteioServos } | null>(null);
	let metodos: { id: number; method: string; description: string }[] = $state([]);
	let estado: 'carregando' | 'erro' | 'pronto' = $state('carregando');

	type Aba = 'inscritos' | 'campistas' | 'servos';
	let aba: Aba = $state('inscritos');

	// ─── Carregar ─────────────────────────────────────────────────────────

	async function lerJson(caminho: string) {
		const res = await apiFetch(caminho);
		if (!res.ok) throw new Error(caminho);
		return res.json();
	}

	async function carregarInscricoes() {
		const [lista, resultado] = await Promise.all([
			lerJson(`/activities/${atividadeId}/subscriptions`),
			atividade?.tipo === 'Acampamento' ? lerJson(`/activities/${atividadeId}/sorteio`) : Promise.resolve(null)
		]);
		inscricoes = lista.subscriptions ?? [];
		sorteio = resultado ? { campistas: resultado.campistas, servos: resultado.servos } : null;
	}

	/** Depois de uma ação: a atividade também muda (o selo sai de "Aguardando sorteio"). */
	async function recarregar() {
		try {
			atividade = (await lerJson(`/activities/${atividadeId}`)).data;
			await carregarInscricoes();
		} catch {
			// Fica com o que já estava na tela; o aviso de sucesso continua valendo.
		}
	}

	async function carregar() {
		estado = 'carregando';
		try {
			const dados = await lerJson(`/activities/${atividadeId}`);
			atividade = dados.data;
			const [, m] = await Promise.all([
				carregarInscricoes(),
				atividade.tipo === 'Acampamento' ? lerJson('/admin/selection-methods?type=Acampamento') : Promise.resolve({ data: [] })
			]);
			metodos = (m.data ?? []).filter((x: { method: string }) => x.method !== 'Sorteio');
			estado = 'pronto';
		} catch {
			estado = 'erro';
		}
	}

	onMount(carregar);

	// ─── Dados derivados ──────────────────────────────────────────────────

	const evento = $derived(atividade?.tipo !== 'Acampamento');
	const casais = $derived(!!sorteio?.campistas.casais);
	const feito = $derived({ Campista: !!sorteio?.campistas.realizado, Servo: !!sorteio?.servos.realizado });
	const cobra = $derived(Number(atividade?.taxaParticipante ?? 0) > 0 || Number(atividade?.taxaServo ?? 0) > 0);
	const fase = $derived(atividade ? faseDaAtividade(datasDaAtividade(atividade), agora) : null);
	const campistas = $derived(inscricoes.filter((i) => i.subscription_type === 'Campista'));
	const servos = $derived(inscricoes.filter((i) => i.subscription_type === 'Servo'));
	const sitDe = (i: Inscricao) => situacaoDe(i, evento, feito);

	const naoPagos = $derived(inscricoes.filter(naoPago));
	const vencidos = $derived(naoPagos.filter((i) => naFolga(i.pagamento, agora)));

	const p2 = (n: number) => String(n).padStart(2, '0');
	const quando = $derived.by(() => {
		if (!atividade?.inicioEvento) return 'Data a definir';
		const ini = new Date(atividade.inicioEvento);
		const fim = atividade.fimEvento ? new Date(atividade.fimEvento) : ini;
		const d = (x: Date) => `${p2(x.getDate())}/${p2(x.getMonth() + 1)}`;
		if (d(ini) === d(fim)) return `${d(ini)}/${ini.getFullYear()}`;
		if (ini.getMonth() === fim.getMonth()) return `${p2(ini.getDate())} a ${d(fim)}/${fim.getFullYear()}`;
		return `${d(ini)} a ${d(fim)}/${fim.getFullYear()}`;
	});

	const conjugeDe = (userId: number) => inscricoes.find((i) => i.user.id === userId)?.conjuge?.user_id ?? null;
	const ehHomem = (userId: number) => inscricoes.find((i) => i.user.id === userId)?.user.sex === 'M';
	/** Quantas unidades (casais contam um) numa lista do sorteio. */
	const contarUnidades = (lista: { user_id: number; nome: string }[]) => unidades(lista, casais, conjugeDe, ehHomem).length;

	const abertasCampistas = $derived(
		sorteio?.campistas.realizado ? Object.values(sorteio.campistas.vagas_abertas ?? {}).reduce((t: number, n: number) => t + n, 0) : 0
	);
	const abertasServos = $derived(sorteio?.servos.realizado ? sorteio.servos.setores.reduce((t, x) => t + x.vagas_abertas, 0) : 0);

	type Cartao = { rot: string; val: string; sub: string; alerta?: boolean };
	const cartoes = $derived.by((): Cartao[] => {
		if (!atividade) return [];
		if (evento) {
			const inscritos = inscricoes.filter((i) => ['evInsc', 'andam'].includes(sitDe(i))).length;
			const prazo = atividade.prazoPagamentoParticipante;
			return [
				{ rot: 'Inscritos', val: `${inscritos}`, sub: atividade.vagasTotais ? `de ${atividade.vagasTotais} vagas` : 'sem limite de vagas' },
				{ rot: 'Inscrição em andamento', val: `${inscricoes.filter((i) => sitDe(i) === 'andam').length}`, sub: 'falta enviar o formulário' },
				...(cobra
					? [
							{
								rot: 'Não pagos',
								val: `${naoPagos.length}`,
								sub: vencidos.length ? `${vencidos.length} com prazo vencido` : prazo ? `prazo ${dm(prazo)}` : 'quem já precisa pagar',
								alerta: naoPagos.length > 0
							}
						]
					: []),
				{ rot: 'Saíram', val: `${inscricoes.filter((i) => SAIU.includes(sitDe(i))).length}`, sub: 'desistências e cancelamentos' }
			];
		}
		const s = sorteio?.campistas;
		const comVaga = campistas.filter((i) => COM_VAGA.includes(sitDe(i)));
		const indicados = comVaga.filter(ehIndicado).length;
		const sorteados = comVaga.length - indicados;
		const vagas = s ? s.vagas.Homem + s.vagas.Mulher + 2 * s.vagas.Casado : 0;
		const supCamp = s?.realizado ? contarUnidades(s.suplentes) : 0;
		const supServ = sorteio?.servos.realizado ? sorteio.servos.setores.reduce((t, x) => t + x.suplentes.length, 0) : 0;
		const abertas = abertasCampistas + abertasServos;
		return [
			{ rot: 'Inscritos', val: `${inscricoes.length}`, sub: `${plural(campistas.length, 'campista', 'campistas')} · ${plural(servos.length, 'servo', 'servos')}` },
			{
				rot: 'Campistas com vaga',
				val: `${s?.realizado ? comVaga.length : indicados}`,
				sub: s?.realizado
					? `${sorteados} de ${vagas} vagas + ${plural(indicados, 'indicado', 'indicados')}`
					: `${plural(indicados, 'indicado', 'indicados')} · sorteio não feito`
			},
			{
				rot: 'Suplentes na fila',
				val: s?.realizado ? `${supCamp + supServ}` : '—',
				sub: s?.realizado
					? `${casais ? plural(supCamp, 'casal', 'casais') : plural(supCamp, 'campista', 'campistas')} · ${plural(supServ, 'servo', 'servos')}`
					: 'depois do sorteio'
			},
			{ rot: 'Vagas abertas', val: `${abertas}`, sub: abertas ? 'use “Chamar próximo”' : 'nenhuma agora', alerta: abertas > 0 },
			...(cobra
				? [
						{
							rot: 'Não pagos',
							val: `${naoPagos.length}`,
							sub: vencidos.length ? `${vencidos.length} com prazo vencido` : 'quem já precisa pagar',
							alerta: vencidos.length > 0
						}
					]
				: [])
		];
	});

	const abas = $derived.by(() => {
		const selo = (realizado: boolean, abertas: number) =>
			!realizado ? { texto: 'não feito', tom: 'azul' } : abertas ? { texto: plural(abertas, 'vaga aberta', 'vagas abertas'), tom: 'ambar' } : null;
		return [
			{ k: 'inscritos' as Aba, nome: 'Inscritos', selo: null },
			{ k: 'campistas' as Aba, nome: 'Sorteio de campistas', selo: selo(feito.Campista, abertasCampistas) },
			{ k: 'servos' as Aba, nome: 'Sorteio de servos', selo: selo(feito.Servo, abertasServos) }
		];
	});

	// ─── Janelas ──────────────────────────────────────────────────────────

	type Janela =
		| { tipo: 'indicar'; inicial: Inscricao | null }
		| { tipo: 'realizar'; sorteio: 'Campista' | 'Servo' }
		| { tipo: 'refazer'; sorteio: 'Campista' | 'Servo' }
		| { tipo: 'apagar'; sorteio: 'Campista' | 'Servo' }
		| { tipo: 'chamar'; alvo: UnidadeChamada }
		| { tipo: 'desfazer'; inscricao: Inscricao };

	let janela = $state<Janela | null>(null);
	let enviando = $state(false);
	let erroJanela = $state('');
	let entendo = $state(false);

	function abrir(j: Janela) {
		janela = j;
		erroJanela = '';
		entendo = false;
		enviando = false;
	}
	function fechar() {
		if (!enviando) janela = null;
	}

	/** Faz a ação da janela; sucesso fecha, recarrega e mostra o aviso verde. */
	async function executar(acao: () => Promise<Response>, acaoTexto: string, sucesso: (dados: any) => string) {
		enviando = true;
		erroJanela = '';
		try {
			const res = await acao();
			const dados = await res.json().catch(() => ({}));
			if (!res.ok) {
				erroJanela = dados.error ? `Não foi possível ${acaoTexto}: ${dados.error}` : `Não foi possível ${acaoTexto}. Nada mudou: tente de novo.`;
				return;
			}
			const msg = sucesso(dados);
			janela = null;
			await recarregar();
			mostrarAviso(msg);
		} catch {
			erroJanela = `Não foi possível ${acaoTexto}. Nada mudou: tente de novo.`;
		} finally {
			enviando = false;
		}
	}

	const caminhoDoSorteio = (t: 'Campista' | 'Servo') => `/activities/${atividadeId}/sorteio/${t === 'Servo' ? 'servos' : 'campistas'}`;

	function realizar(t: 'Campista' | 'Servo', refazendo: boolean) {
		executar(
			() => apiFetch(caminhoDoSorteio(t), { method: 'POST' }),
			refazendo ? 'sortear de novo' : 'realizar o sorteio',
			(dados) => {
				if (refazendo) return 'Sorteio refeito. O resultado anterior foi substituído.';
				if (t === 'Servo') return 'Sorteio de servos realizado.';
				const comVaga = contarUnidades(dados.sorteados ?? []);
				const fila = contarUnidades(dados.suplentes ?? []);
				const un = (n: number) => (casais ? plural(n, 'casal', 'casais') : plural(n, 'pessoa', 'pessoas'));
				return `Sorteio realizado: ${un(comVaga)} com vaga e ${un(fila)} na fila de suplentes.`;
			}
		);
	}

	function apagar(t: 'Campista' | 'Servo') {
		executar(
			() => apiFetch(caminhoDoSorteio(t), { method: 'DELETE' }),
			'apagar o resultado',
			() =>
				t === 'Servo'
					? 'Resultado do sorteio de servos apagado.'
					: 'Resultado do sorteio apagado. O acampamento voltou para “Aguardando sorteio”.'
		);
	}

	function chamar(alvo: UnidadeChamada) {
		executar(
			() =>
				apiFetch(`/activities/${atividadeId}/sorteio/chamar-proximo`, {
					method: 'POST',
					body: JSON.stringify(alvo.tipo === 'Servo' ? { tipo: 'Servo', setor: alvo.setor } : { tipo: 'Campista', grupo: alvo.grupo })
				}),
			'chamar',
			(dados) => {
				const verbo = alvo.pessoas > 1 ? 'foram chamados e têm' : 'foi chamado(a) e tem';
				return dados.prazo
					? `${alvo.nome} ${verbo} até ${dma(dados.prazo)} para enviar o formulário e pagar.`
					: `${alvo.nome} ${alvo.pessoas > 1 ? 'foram chamados' : 'foi chamado(a)'}.`;
			}
		);
	}

	function desfazer(i: Inscricao) {
		executar(
			() => apiFetch(`/inscricoes/${i.id}/indicacao`, { method: 'DELETE' }),
			'desfazer a indicação',
			(dados) =>
				dados.conjuge_removido && i.conjuge
					? `Indicação de ${i.user.name} e ${i.conjuge.nome} desfeita.`
					: `Indicação de ${i.user.name} desfeita.`
		);
	}

	/** O mesmo cálculo do servidor: o maior entre o prazo da trilha e hoje + 3 dias, sem passar do primeiro dia. */
	function novoPrazo(tipo: 'Campista' | 'Servo'): Date | null {
		const base = tipo === 'Servo' ? atividade?.prazoPagamentoServo : atividade?.prazoPagamentoParticipante;
		const tres = new Date(agora.getTime() + 3 * 86400000);
		let prazo = base ? new Date(base) : null;
		if (!prazo || tres > prazo) prazo = tres;
		if (atividade?.inicioEvento) {
			const i = new Date(atividade.inicioEvento);
			const limite = new Date(i.getFullYear(), i.getMonth(), i.getDate(), 23, 59);
			if (prazo > limite) prazo = limite;
		}
		return prazo;
	}
	/** "Mateus e Natália Santos" → "Mateus e Natália"; "Arthur Moreira" → "Arthur". */
	const primeirosNomes = (nome: string) =>
		nome
			.split(' e ')
			.map((parte) => parte.trim().split(' ')[0])
			.join(' e ');
	const dataLonga = (d: Date | null) => (d ? `${p2(d.getDate())}/${p2(d.getMonth() + 1)}/${d.getFullYear()}` : 'a definir');

	// Resumo das janelas de sorteio.
	const vagasTxt = $derived(sorteio ? textoDasVagas(sorteio.campistas.vagas, casais) : '');
	const resumoRealizar = $derived.by(() => {
		const s = sorteio?.campistas;
		if (!s) return null;
		const concorrem = casais
			? `${plural(Math.ceil(s.concorrem.Casado / 2), 'casal', 'casais')} (${plural(s.concorrem.Casado, 'pessoa', 'pessoas')})`
			: `${plural(s.concorrem.Homem, 'homem', 'homens')} e ${plural(s.concorrem.Mulher, 'mulher', 'mulheres')}`;
		const nIndic = contarUnidades(s.indicados);
		const nFora = contarUnidades(s.nao_concorrem);
		return {
			concorrem,
			indicados: casais ? plural(nIndic, 'casal indicado', 'casais indicados') : plural(nIndic, 'indicado', 'indicados'),
			naoConcorrem: nFora ? `${casais ? plural(nFora, 'casal', 'casais') : plural(nFora, 'pessoa', 'pessoas')} (mora fora da área)` : 'ninguém'
		};
	});
	const prazoDoSorteio = $derived(atividade?.prazoFormularioCampista ?? atividade?.prazoPagamentoParticipante ?? null);
	const resumoRefazer = $derived.by(() => {
		if (!sorteio || !janela || janela.tipo !== 'refazer') return '';
		if (janela.sorteio === 'Servo') {
			const com = sorteio.servos.setores.reduce((t, x) => t + x.sorteados.length, 0);
			const fila = sorteio.servos.setores.reduce((t, x) => t + x.suplentes.length, 0);
			return `${plural(com, 'servo', 'servos')} com vaga e ${fila} na fila voltam a concorrer, e o sorteio é feito de novo com as vagas de cada setor.`;
		}
		const com = contarUnidades(sorteio.campistas.sorteados);
		const fila = contarUnidades(sorteio.campistas.suplentes);
		const un = casais ? plural(com, 'casal', 'casais') : plural(com, 'campista', 'campistas');
		return `${un} com vaga e ${fila} na fila voltam a concorrer, e o sorteio é feito de novo com as vagas do cadastro (${vagasTxt}).`;
	});

	// ─── Avisos ───────────────────────────────────────────────────────────

	let aviso = $state('');
	let avisoTimer: ReturnType<typeof setTimeout>;
	function mostrarAviso(texto: string) {
		aviso = texto;
		clearTimeout(avisoTimer);
		avisoTimer = setTimeout(() => (aviso = ''), 7000);
	}
	onDestroy(() => clearTimeout(avisoTimer));

	let pagamentosAberto = $state(false);
</script>

<svelte:head>
	<title>Inscritos e sorteio | Painel da Comunidade</title>
</svelte:head>

<div class="fm">
	<div class="cabeca">
		<div class="cabeca-textos">
			<h1 class="fm-h1">Inscritos e sorteio</h1>
			{#if atividade}
				<div class="atividade">
					<strong>{atividade.nome}</strong>
					{#if fase}<SeloFase {fase} />{/if}
					<span class="datas">{quando}</span>
				</div>
			{/if}
		</div>
		<div class="cabeca-acoes">
			{#if atividade && !evento && estado === 'pronto'}
				<button type="button" class="ins-acao" onclick={() => abrir({ tipo: 'indicar', inicial: null })}>
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z" /></svg>
					Indicar
				</button>
			{/if}
			{#if cobra && estado === 'pronto'}
				<button type="button" class="ins-acao" aria-haspopup="dialog" onclick={() => (pagamentosAberto = true)}>
					<span class="rs" aria-hidden="true">R$</span>Pagamentos
					{#if naoPagos.length}<span class="nao-pagos">{naoPagos.length} não {naoPagos.length === 1 ? 'pago' : 'pagos'}</span>{/if}
				</button>
			{/if}
			<a href="/admin/atividades" class="ins-acao2">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7" /></svg>
				Gestão de Atividades
			</a>
		</div>
	</div>

	{#if estado === 'carregando'}
		<section class="fm-cartao carregando" aria-busy="true">
			<div class="carregando-topo"><span class="fm-spinner"></span>Carregando os inscritos...</div>
			{#each [1, 2, 3, 4, 5, 6] as _}
				<div class="osso-linha"><span class="osso"></span><span class="osso claro"></span><span class="osso claro"></span><span class="osso claro"></span><span class="osso claro"></span></div>
			{/each}
		</section>
	{:else if estado === 'erro'}
		<section class="fm-cartao centro" role="alert">
			<span class="icone-erro" aria-hidden="true">!</span>
			<p class="centro-titulo">Não foi possível carregar os inscritos.</p>
			<p class="centro-texto">Verifique sua conexão com a internet e tente novamente.</p>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Tentar novamente</button>
		</section>
	{:else if inscricoes.length === 0}
		<section class="fm-cartao centro">
			<img src={logoAsas} alt="" class="brasao" />
			<p class="centro-titulo">Ninguém se inscreveu ainda.</p>
			<p class="centro-texto">As inscrições aparecem aqui assim que as pessoas se inscreverem pelo site.</p>
		</section>
	{:else}
		<div class="cartoes">
			{#each cartoes as c (c.rot)}
				<div class="cartao-num" class:alerta={c.alerta}>
					<span class="ins-rot">{c.rot}</span>
					<span class="num">{c.val}</span>
					<span class="num-sub">{c.sub}</span>
				</div>
			{/each}
		</div>

		<div class="painel">
			{#if !evento}
				<div class="abas" role="tablist" aria-label="Partes da tela">
					{#each abas as t (t.k)}
						<button
							type="button"
							role="tab"
							id="aba-{t.k}"
							aria-selected={aba === t.k}
							aria-controls="painel-aba"
							class="aba"
							class:sel={aba === t.k}
							onclick={() => (aba = t.k)}
						>
							{t.nome}
							{#if t.selo}<span class="aba-selo {t.selo.tom}">{t.selo.texto}</span>{/if}
						</button>
					{/each}
				</div>
			{/if}
			<section id="painel-aba" class="fm-cartao conteudo" class:com-abas={!evento} role={evento ? undefined : 'tabpanel'} aria-labelledby={evento ? undefined : `aba-${aba}`}>
				{#if evento || aba === 'inscritos'}
					<AbaInscritos
						{inscricoes}
						{evento}
						{casais}
						{feito}
						{cobra}
						{agora}
						onindicar={(i) => abrir({ tipo: 'indicar', inicial: i })}
						ondesfazer={(i) => abrir({ tipo: 'desfazer', inscricao: i })}
					/>
				{:else if aba === 'campistas' && sorteio}
					<SorteioCampistas
						s={sorteio.campistas}
						inscricoes={campistas}
						{atividadeId}
						onchamar={(alvo) => abrir({ tipo: 'chamar', alvo })}
						onindicar={() => abrir({ tipo: 'indicar', inicial: null })}
						ondesfazer={(i) => abrir({ tipo: 'desfazer', inscricao: i })}
						onrealizar={() => abrir({ tipo: 'realizar', sorteio: 'Campista' })}
						onrefazer={() => abrir({ tipo: 'refazer', sorteio: 'Campista' })}
						onapagar={() => abrir({ tipo: 'apagar', sorteio: 'Campista' })}
					/>
				{:else if aba === 'servos' && sorteio}
					<SorteioServos
						s={sorteio.servos}
						inscricoes={servos}
						{atividadeId}
						onchamar={(alvo) => abrir({ tipo: 'chamar', alvo })}
						onrealizar={() => abrir({ tipo: 'realizar', sorteio: 'Servo' })}
						onrefazer={() => abrir({ tipo: 'refazer', sorteio: 'Servo' })}
						onapagar={() => abrir({ tipo: 'apagar', sorteio: 'Servo' })}
					/>
				{/if}
			</section>
		</div>
	{/if}
</div>

<!-- ─── Janelas ─── -->
{#if janela?.tipo === 'indicar'}
	<JanelaIndicar
		{atividadeId}
		{inscricoes}
		{metodos}
		{feito}
		inicial={janela.inicial}
		onfechar={fechar}
		onfeito={async (msg) => {
			janela = null;
			await recarregar();
			mostrarAviso(msg);
		}}
	/>
{:else if janela?.tipo === 'realizar' && janela.sorteio === 'Campista' && resumoRealizar}
	<Janela idTitulo="t-realizar" largura={620} faixa="linear-gradient(90deg,#1B2F5E,#B69E55)" travada={enviando} onfechar={fechar}>
		<h2 id="t-realizar" class="fm-janela-titulo">Realizar o sorteio de campistas?</h2>
		<dl class="ins-resumo">
			<dt>Vagas</dt><dd>{vagasTxt}</dd>
			<dt>Concorrem</dt><dd>{resumoRealizar.concorrem}</dd>
			<dt>Fora do sorteio</dt><dd>{resumoRealizar.indicados} (vaga garantida)</dd>
			<dt>Não concorrem</dt><dd>{resumoRealizar.naoConcorrem}</dd>
		</dl>
		<p class="ins-janela-texto">
			O sistema sorteia os titulares e o mesmo número de suplentes, em ordem. Quem tiver vaga vê o resultado no site e precisa enviar o
			formulário e pagar até <strong>{prazoDoSorteio ? dma(prazoDoSorteio) : 'o prazo do acampamento'}</strong>. O acampamento passa de
			“Aguardando sorteio” para “Inscrições encerradas”.
		</p>
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fechar} disabled={enviando}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={() => realizar('Campista', false)} disabled={enviando}>
				{enviando ? 'Sorteando...' : 'Sortear agora'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'realizar' && janela.sorteio === 'Servo' && sorteio}
	{@const s = sorteio.servos}
	<Janela idTitulo="t-realizar-s" largura={600} faixa="linear-gradient(90deg,#1B2F5E,#B69E55)" travada={enviando} onfechar={fechar}>
		<h2 id="t-realizar-s" class="fm-janela-titulo">Realizar o sorteio de servos?</h2>
		<p class="ins-janela-texto">
			Concorrem <strong>{plural(s.concorrem, 'servo', 'servos')}</strong> para
			<strong>{plural(s.setores.reduce((t, x) => t + x.vacancies, 0), 'vaga', 'vagas')}</strong> em {plural(s.setores.length, 'setor', 'setores')}.
			Cada setor sorteia as vagas que faltam e o mesmo número de suplentes. Indicados, quem já está na equipe e o servo geral não entram.
		</p>
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fechar} disabled={enviando}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={() => realizar('Servo', false)} disabled={enviando}>
				{enviando ? 'Sorteando...' : 'Sortear agora'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'refazer'}
	{@const t = janela.sorteio}
	<Janela idTitulo="t-refazer" largura={620} faixa="#C08A1E" alerta travada={enviando} onfechar={fechar}>
		<h2 id="t-refazer" class="fm-janela-titulo">Sortear de novo?</h2>
		<p class="ins-janela-texto">O resultado atual é <strong>substituído</strong>: {resumoRefazer}</p>
		<p class="ins-aviso azul"><span>Indicados, cancelados e recusados continuam como estão.</span></p>
		<label class="ins-check"><input type="checkbox" bind:checked={entendo} disabled={enviando} />Entendo que o resultado atual será substituído</label>
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={fechar} disabled={enviando}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--perigo-cheio fm-btn--grande" onclick={() => realizar(t, true)} disabled={!entendo || enviando}>
				{enviando ? 'Sorteando...' : 'Sortear de novo'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'apagar'}
	{@const t = janela.sorteio}
	<Janela idTitulo="t-apagar" largura={620} faixa="#9B2C2C" alerta travada={enviando} onfechar={fechar}>
		<h2 id="t-apagar" class="fm-janela-titulo">Apagar o resultado do sorteio?</h2>
		<p class="ins-janela-texto">
			{#if t === 'Servo'}
				Os servos sorteados e os suplentes saem das equipes e voltam a aguardar o sorteio. Quem foi colocado à mão na equipe continua.
			{:else}
				Sorteados, suplentes e não sorteados voltam para “Aguardando sorteio”, e o acampamento volta a mostrar “Aguardando sorteio” no
				site.
			{/if}
			<strong>Não dá para desfazer.</strong>
		</p>
		<p class="ins-aviso azul"><span>Indicados, cancelados e recusados continuam como estão.</span></p>
		<label class="ins-check"><input type="checkbox" bind:checked={entendo} disabled={enviando} />Entendo que não dá para desfazer</label>
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={fechar} disabled={enviando}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--perigo-cheio fm-btn--grande" onclick={() => apagar(t)} disabled={!entendo || enviando}>
				{enviando ? 'Apagando...' : 'Apagar resultado'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'chamar'}
	{@const alvo = janela.alvo}
	<Janela idTitulo="t-chamar" largura={600} faixa="linear-gradient(90deg,#C08A1E,#B69E55)" travada={enviando} onfechar={fechar}>
		<h2 id="t-chamar" class="fm-janela-titulo">Chamar o próximo da fila?</h2>
		<p class="ins-janela-texto">
			<strong>{alvo.nome}</strong> (1º da fila de {alvo.fila}) {alvo.pessoas > 1 ? 'passam' : 'passa'} a ter a vaga e
			{alvo.pessoas > 1 ? 'veem' : 'vê'} o aviso no site.
		</p>
		<dl class="ins-resumo"><dt>Novo prazo</dt><dd>{dataLonga(novoPrazo(alvo.tipo))}, para enviar o formulário e pagar</dd></dl>
		<p class="ins-ajuda">
			São 3 dias a partir de hoje, sem passar do primeiro dia do acampamento. Se o prazo do acampamento for mais tarde, vale o do
			acampamento.
		</p>
		{#if alvo.formulario}<p class="ins-aviso azul"><span>Já enviou o formulário enquanto estava na fila: falta só o pagamento.</span></p>{/if}
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fechar} disabled={enviando}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={() => chamar(alvo)} disabled={enviando}>
				{enviando ? 'Chamando...' : `Chamar ${primeirosNomes(alvo.nome)}`}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'desfazer'}
	{@const i = janela.inscricao}
	{@const conjugeIndicado = i.conjuge ? inscricoes.find((x) => x.id === i.conjuge?.id && ehIndicado(x)) : undefined}
	<Janela idTitulo="t-desfazer" largura={600} faixa="#9B2C2C" alerta travada={enviando} onfechar={fechar}>
		<h2 id="t-desfazer" class="fm-janela-titulo">Desfazer a indicação?</h2>
		<p class="ins-janela-texto">
			<strong>{i.user.name}</strong> perde a vaga garantida e volta para “Aguardando sorteio”. Se o sorteio já foi feito, fica sem resultado
			até o sorteio ser refeito.
		</p>
		{#if conjugeIndicado}<p class="ins-aviso amb"><span><strong>{conjugeIndicado.user.name}</strong> (cônjuge) também perde a indicação.</span></p>{/if}
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={fechar} disabled={enviando}>Manter a indicação</button>
			<button type="button" class="fm-btn fm-btn--perigo-cheio fm-btn--grande" onclick={() => desfazer(i)} disabled={enviando}>
				{enviando ? 'Desfazendo...' : 'Desfazer indicação'}
			</button>
		</div>
	</Janela>
{/if}

{#if pagamentosAberto && atividade}
	<PagamentosAtividade atividade={atividade} {inscricoes} onfechar={() => (pagamentosAberto = false)} onmudou={carregarInscricoes} />
{/if}

{#if aviso}
	<div class="ins-toast" role="status">
		<span aria-hidden="true">✓</span><span>{aviso}</span>
		<button type="button" aria-label="Fechar aviso" onclick={() => (aviso = '')}>✕</button>
	</div>
{/if}

<style>
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
		gap: 8px;
	}
	.atividade {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
		font-size: 17px;
		color: var(--tinta-2);
	}
	.atividade strong {
		font-size: 19px;
		color: var(--tinta);
	}
	.atividade :global(.selo) {
		box-shadow: none;
		font-size: 11px;
		padding: 3px 9px;
	}
	.datas {
		color: var(--tinta-3);
	}
	.cabeca-acoes {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
	}
	.rs {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 26px;
		height: 26px;
		margin-left: -6px;
		border-radius: 50%;
		background: var(--azul);
		color: var(--ouro-claro);
		font: 700 11px 'Source Sans 3', sans-serif;
	}
	.nao-pagos {
		padding: 1px 8px;
		font-size: 13px;
		font-weight: 700;
		color: #6a480c;
		background: #fbf0d6;
		border: 1px solid #ebd39c;
		border-radius: 999px;
	}

	/* Estados */
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
		grid-template-columns: 2fr 1.4fr 1fr 1fr 1.4fr;
		gap: 20px;
		padding: 18px 24px;
		border-bottom: 1px solid var(--linha);
	}
	.osso {
		height: 20px;
		border-radius: 4px;
		background: #ede5d1;
	}
	.osso.claro {
		height: 18px;
		background: #f3eddf;
	}
	.centro {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 14px;
		padding: 72px 24px;
		text-align: center;
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
	.icone-erro {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 56px;
		height: 56px;
		font-size: 28px;
		font-weight: 700;
		color: var(--vermelho);
		background: #f6e2df;
		border: 1px solid #e6bdb6;
		border-radius: 50%;
	}
	.brasao {
		width: 120px;
		opacity: 0.55;
		filter: sepia(1) saturate(0.6);
	}

	/* Números */
	.cartoes {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 14px;
	}
	.cartao-num {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 16px 20px;
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 14px;
		box-shadow: 0 14px 28px -24px rgba(15, 29, 61, 0.45);
	}
	.cartao-num.alerta {
		background: #fbf0d6;
		border-color: #ebd39c;
	}
	.num {
		font: 600 38px/1 'Cormorant Garamond', serif;
		color: var(--azul);
		font-variant-numeric: tabular-nums;
	}
	.alerta .num {
		color: #6a480c;
	}
	.num-sub {
		font-size: 15px;
		color: var(--tinta-3);
	}

	/* Abas */
	.painel {
		display: flex;
		flex-direction: column;
	}
	.abas {
		display: flex;
		gap: 6px;
	}
	.aba {
		position: relative;
		z-index: 1;
		flex: 1;
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 10px;
		height: 56px;
		margin-bottom: -1px;
		padding: 0 20px;
		font: 600 17px 'Source Sans 3', sans-serif;
		color: var(--tinta-3);
		background: transparent;
		border: 1px solid transparent;
		border-bottom: none;
		border-radius: 12px 12px 0 0;
		cursor: pointer;
	}
	.aba.sel {
		z-index: 2;
		font-weight: 700;
		color: var(--azul);
		background: var(--pergaminho);
		border-color: var(--borda-suave);
	}
	.aba.sel::before {
		content: '';
		position: absolute;
		left: 14px;
		right: 14px;
		top: -1px;
		height: 3px;
		border-radius: 0 0 3px 3px;
		background: var(--ouro);
	}
	.aba-selo {
		padding: 2px 9px;
		font-size: 13px;
		font-weight: 700;
		border-radius: 999px;
	}
	.aba-selo.azul {
		color: #21376a;
		background: #e6ebf5;
	}
	.aba-selo.ambar {
		color: #6a480c;
		background: #fbf0d6;
	}
	.conteudo {
		display: flex;
		flex-direction: column;
		gap: 20px;
		padding: 24px 28px 28px;
	}
	.conteudo.com-abas {
		border-top-left-radius: 4px;
		border-top-right-radius: 4px;
	}
</style>
