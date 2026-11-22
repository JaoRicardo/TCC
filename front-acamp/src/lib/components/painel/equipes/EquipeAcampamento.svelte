<script lang="ts">
	/**
	 * A equipe de servos de um acampamento (handoff design_handoff_equipes_servos):
	 * servo geral, setores (Internos e Externos), setores do modelo fora do
	 * acampamento, "Aplicar o modelo", Banco de servos e todas as janelas
	 * (no lugar de confirm()/alert()). Serve o painel e a área do participante
	 * (`noSite`), mostrando só o que o papel da pessoa permite.
	 */
	import { onDestroy } from 'svelte';
	import Janela from '$lib/components/painel/Janela.svelte';
	import CartaoSetor from './CartaoSetor.svelte';
	import BancoServos from './BancoServos.svelte';
	import { auth } from '$lib/stores/auth.svelte';
	import {
		aDo,
		completo,
		oA,
		buscaNoBanco,
		da,
		dataBr,
		diferentesDoModelo,
		foraDoAcampamento,
		gruposPorLocal,
		infoDaPessoa,
		ligados,
		na,
		ocupadas,
		plural,
		poderesDe,
		primeiroNome,
		textoDaSituacao,
		vagasDaOpcao
	} from '$lib/equipes';
	import {
		aplicarModelo,
		designarServoGeral,
		lerEquipe,
		ligarSetor,
		mudarLideranca,
		mudarVagas,
		moverDeSetor,
		porNaEquipe,
		tirarDaEquipe,
		tirarServoGeral,
		tirarSetor,
		type EquipeDoAcampamento,
		type PessoaDaEquipe,
		type PessoaDoBanco,
		type Resultado,
		type SetorDaEquipe
	} from '$lib/services/equipeService';

	let {
		atividadeId,
		noSite = false,
		equipe = $bindable(null)
	}: {
		atividadeId: number;
		noSite?: boolean;
		equipe?: EquipeDoAcampamento | null;
	} = $props();

	let estado: 'carregando' | 'erro' | 'pronto' = $state('carregando');

	async function carregar(mostrarCarregando = true) {
		if (mostrarCarregando) estado = 'carregando';
		const r = await lerEquipe(atividadeId);
		if (r.ok) {
			equipe = r.data;
			estado = 'pronto';
		} else if (mostrarCarregando) {
			estado = 'erro';
		}
	}

	$effect(() => {
		atividadeId;
		carregar();
	});

	const eu = $derived(auth.user?.id as number | undefined);
	const poderes = $derived(equipe ? poderesDe(equipe, eu, noSite) : null);
	const categoria = $derived(equipe?.atividade.categoria ?? '');
	const sorteioFeito = $derived(!!equipe?.sorteio.feito);
	const lig = $derived(equipe ? ligados(equipe) : []);
	const grupos = $derived(gruposPorLocal(lig));
	const fora = $derived(equipe && poderes?.organizacao ? foraDoAcampamento(equipe) : []);
	const difs = $derived(equipe ? diferentesDoModelo(equipe) : []);
	const podeAplicar = $derived(!!poderes?.organizacao && !sorteioFeito && (difs.length > 0 || fora.length > 0));
	const banco = $derived(equipe?.banco ?? null);
	const meusSetores = $derived(equipe?.meu_papel.meus_setores_ids ?? []);
	/** Setores em que a pessoa pode pôr gente (líder: só o dela). */
	const setoresParaPor = $derived(poderes ? lig.filter((s) => poderes.porNo(s)) : []);
	const textoBotaoBanco = $derived.by(() => {
		if (!poderes || setoresParaPor.length === 0) return null;
		if (!poderes.lideres && setoresParaPor.length === 1) return `Pôr ${na(setoresParaPor[0].sector_name)}`;
		return 'Pôr em…';
	});
	const semInscritos = $derived(
		!!equipe && (banco?.length ?? 0) === 0 && equipe.servo_geral.length === 0 && lig.every((s) => ocupadas(s) === 0)
	);

	// ─── Aviso no canto ───────────────────────────────────────────────────

	let aviso = $state<{ texto: string; erro: boolean } | null>(null);
	let avisoTimer: ReturnType<typeof setTimeout>;
	function mostrarAviso(texto: string, erro = false) {
		aviso = { texto, erro };
		clearTimeout(avisoTimer);
		avisoTimer = setTimeout(() => (aviso = null), 7000);
	}
	onDestroy(() => clearTimeout(avisoTimer));

	// ─── Janelas ──────────────────────────────────────────────────────────

	type TipoJanela =
		| { tipo: 'por'; setor: SetorDaEquipe | null; pessoa: PessoaDoBanco | null }
		| { tipo: 'mover'; setor: SetorDaEquipe; pessoa: PessoaDaEquipe }
		| { tipo: 'tirar'; setor: SetorDaEquipe; pessoa: PessoaDaEquipe }
		| { tipo: 'tirarSetor'; setor: SetorDaEquipe }
		| { tipo: 'ligar'; setor: SetorDaEquipe }
		| { tipo: 'geral' }
		| { tipo: 'tirarGeral'; pessoa: PessoaDaEquipe }
		| { tipo: 'aplicar' };

	let janela = $state<TipoJanela | null>(null);
	let enviando = $state(false);
	let erroJanela = $state('');
	let entendo = $state(false);
	let escolhaSetor: number | null = $state(null);
	let escolhaPessoa: number | null = $state(null);
	let comoLider = $state(false);
	let buscaJanela = $state('');
	let vagasLigar = $state(0);

	function abrir(j: TipoJanela) {
		janela = j;
		enviando = false;
		erroJanela = '';
		entendo = false;
		comoLider = false;
		buscaJanela = '';
		escolhaPessoa = j.tipo === 'por' && j.pessoa ? j.pessoa.user_id : null;
		escolhaSetor = j.tipo === 'por' && j.setor ? j.setor.id : j.tipo === 'por' && setoresParaPor.length === 1 ? setoresParaPor[0].id : null;
		if (j.tipo === 'ligar') vagasLigar = j.setor.default_vacancies ?? j.setor.actual_vacancies;
	}
	function fechar() {
		if (!enviando) janela = null;
	}

	/** Faz a ação; sucesso fecha a janela, recarrega e mostra o aviso verde. */
	async function executar(acao: () => Promise<Resultado>, acaoTexto: string, sucesso: string | ((d: any) => string)) {
		enviando = true;
		erroJanela = '';
		const r = await acao();
		enviando = false;
		if (!r.ok) {
			erroJanela = r.error && r.status !== 0 ? `Não foi possível ${acaoTexto}: ${r.error}` : `Não foi possível ${acaoTexto}. Nada mudou: tente de novo.`;
			return;
		}
		janela = null;
		await carregar(false);
		mostrarAviso(typeof sucesso === 'string' ? sucesso : sucesso(r.data));
	}

	// Ações sem janela: vagas e liderança.
	async function mudarVagasDo(s: SetorDaEquipe, vagas: number) {
		if (!equipe || s.id === null || vagas < 0) return;
		s.actual_vacancies = vagas;
		const r = await mudarVagas(atividadeId, s.id, vagas);
		if (!r.ok) {
			mostrarAviso(`Não foi possível mudar as vagas ${da(s.sector_name)}. Nada mudou: tente de novo.`, true);
			await carregar(false);
		}
	}

	async function mudarLider(s: SetorDaEquipe, p: PessoaDaEquipe, lider: boolean) {
		if (s.id === null) return;
		const r = await mudarLideranca(atividadeId, s.id, p.user_id, lider);
		if (!r.ok) {
			mostrarAviso('Não foi possível mudar a liderança. Nada mudou: tente de novo.', true);
			return;
		}
		await carregar(false);
		mostrarAviso(lider ? `${p.nome} agora é líder ${da(s.sector_name)}.` : `${p.nome} deixou de ser líder ${da(s.sector_name)}.`);
	}

	// ─── Dados das janelas ────────────────────────────────────────────────

	const setorEscolhido = $derived(lig.find((s) => s.id === escolhaSetor) ?? null);
	const pessoaEscolhida = $derived(banco?.find((p) => p.user_id === escolhaPessoa) ?? null);
	const candidatos = $derived(banco ? buscaNoBanco(banco, buscaJanela).slice(0, 60) : []);
	const destinoCompleto = $derived(
		setorEscolhido && ocupadas(setorEscolhido) >= setorEscolhido.actual_vacancies
			? `${aDo(setorEscolhido.sector_name)} já está ${completo(setorEscolhido.sector_name)} (${ocupadas(setorEscolhido)} de ${setorEscolhido.actual_vacancies}). A pessoa entra além das vagas; se quiser, aumente as vagas do setor.`
			: ''
	);
	const textoDifs = $derived(difs.map((s) => `${s.sector_name}: ${s.actual_vacancies} (modelo ${s.default_vacancies})`).join('; '));

	function enviarPor() {
		const s = setorEscolhido;
		const p = pessoaEscolhida;
		if (!s || s.id === null || !p) return;
		const acsId = s.id;
		executar(
			() => porNaEquipe(atividadeId, acsId, p.user_id, comoLider),
			'pôr na equipe',
			`${p.nome} entrou ${na(s.sector_name)} como ${comoLider ? 'líder' : 'servo'}.`
		);
	}
</script>

{#if estado === 'carregando'}
	<section class="carregando" aria-busy="true">
		<div class="carregando-topo"><span class="fm-spinner"></span>Carregando a equipe...</div>
		<div class="ossos">{#each [1, 2, 3, 4, 5, 6] as _}<span class="osso"></span>{/each}</div>
	</section>
{:else if estado === 'erro'}
	<section class="centro" role="alert">
		<span class="icone-erro" aria-hidden="true">!</span>
		<p class="centro-titulo">Não foi possível carregar a equipe.</p>
		<p class="centro-texto">Verifique sua conexão com a internet e tente novamente.</p>
		<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={() => carregar()}>Tentar novamente</button>
	</section>
{:else if equipe && poderes}
	{#if !noSite}
		<div class="eq-faixa {sorteioFeito ? 'eq-faixa--cinza' : 'eq-faixa--azul'}">
			<span>
				{#if sorteioFeito}
					O sorteio de servos foi feito{equipe.sorteio.feito_em ? ` em ${dataBr(equipe.sorteio.feito_em)}` : ''}.
				{:else}
					O sorteio de servos ainda não foi feito{equipe.sorteio.previsto ? ` (previsto para ${dataBr(equipe.sorteio.previsto)})` : ''}. Ele só completa as
					vagas que faltarem em cada setor.
				{/if}
			</span>
			<a href="/admin/inscricoes/{atividadeId}?aba=servos" class="ins-acao2">Ver o sorteio de servos</a>
		</div>
		{#if sorteioFeito}
			<p class="ins-aviso amb">
				<span
					><strong>O sorteio já foi feito.</strong> Mudar vagas ou pessoas aqui não refaz o sorteio. Quem você põe à mão conta como indicação e
					continua se o sorteio for refeito.</span
				>
			</p>
		{/if}
	{/if}

	<div class="colunas" class:com-banco={banco !== null}>
		<div class="principal">
			<div class="eq-geral">
				<span class="eq-geral-titulo">
					<strong>Servo geral</strong>
					<span>Coordena a equipe toda. Não fica em setor.</span>
				</span>
				{#each equipe.servo_geral as g (g.user_id)}
					<span class="eq-geral-pessoa">
						{g.nome}{#if g.user_id === eu}&nbsp;<span class="eq-voce">(você)</span>{/if}
						{#if poderes.organizacao}
							<button type="button" aria-label="Tirar {g.nome} de servo geral" onclick={() => abrir({ tipo: 'tirarGeral', pessoa: g })}>✕</button>
						{/if}
					</span>
				{:else}
					<span class="eq-geral-vazio">Ninguém designado ainda.</span>
				{/each}
				{#if poderes.organizacao}
					<button type="button" class="ins-acao2 a-direita" onclick={() => abrir({ tipo: 'geral' })}>+ Designar servo geral</button>
				{/if}
			</div>

			{#if equipe.meu_papel.so_minha_equipe}
				<p class="ins-aviso azul"><span>Aqui aparece só a sua equipe. A organização e os líderes cuidam da montagem.</span></p>
			{/if}

			{#if lig.length === 0}
				<div class="eq-vazio">
					<p class="eq-vazio-titulo">Este acampamento ainda não tem setores.</p>
					{#if poderes.organizacao}
						<p class="eq-vazio-texto">Ligue os setores do modelo {da(categoria)} para começar a montar a equipe.</p>
					{/if}
				</div>
			{/if}

			{#each grupos as g (g.nome)}
				<div class="eq-grupo">
					<span class="eq-rot">Setores {g.nome}</span>
					<div class="eq-cartoes">
						{#each g.setores as s (s.id)}
							<CartaoSetor
								setor={s}
								{poderes}
								{categoria}
								{eu}
								{noSite}
								meu={noSite && s.id !== null && meusSetores.includes(s.id)}
								onvagas={mudarVagasDo}
								onpor={(setor) => abrir({ tipo: 'por', setor, pessoa: null })}
								ontirarSetor={(setor) => abrir({ tipo: 'tirarSetor', setor })}
								onlider={mudarLider}
								onmover={(setor, pessoa) => abrir({ tipo: 'mover', setor, pessoa })}
								ontirar={(setor, pessoa) => abrir({ tipo: 'tirar', setor, pessoa })}
							/>
						{/each}
					</div>
				</div>
			{/each}

			{#if fora.length}
				<div class="eq-faixa eq-faixa--tracejada">
					<span>Do modelo {da(categoria)}, fora deste acampamento:</span>
					{#each fora as s (s.categories_sectors_id)}
						<button type="button" class="ins-acao fora" onclick={() => abrir({ tipo: 'ligar', setor: s })}>
							+ {s.sector_name} <span class="fora-info">({s.sector_place} · {plural(s.default_vacancies ?? 0, 'vaga', 'vagas')})</span>
						</button>
					{/each}
				</div>
			{/if}
			{#if podeAplicar}
				<div class="eq-faixa eq-faixa--azul">
					<span>
						Diferente do modelo {da(categoria)}:
						{textoDifs}{difs.length && fora.length ? '; ' : ''}{fora.length
							? `${fora.length === 1 ? 'falta o setor' : 'faltam os setores'} ${fora.map((s) => s.sector_name).join(', ')}`
							: ''}.
					</span>
					<button type="button" class="fm-btn fm-btn--contorno" onclick={() => abrir({ tipo: 'aplicar' })}>Aplicar o modelo {da(categoria)}</button>
				</div>
			{/if}
		</div>

		{#if banco !== null}
			<BancoServos
				{banco}
				{sorteioFeito}
				{semInscritos}
				textoBotao={textoBotaoBanco}
				comFiltros={!noSite}
				subtitulo={noSite ? 'Inscritos como servo que ainda não estão em nenhum setor.' : undefined}
				onpor={(pessoa) => abrir({ tipo: 'por', setor: null, pessoa })}
			/>
		{/if}
	</div>
{/if}

<!-- ─── Janelas ─── -->
{#if janela?.tipo === 'por'}
	{@const fixo = janela.setor}
	<Janela idTitulo="t-por" largura={640} faixa="linear-gradient(90deg,#1B2F5E,#B69E55)" travada={enviando} onfechar={fechar}>
		<h2 id="t-por" class="fm-janela-titulo">
			{fixo ? `Pôr pessoa ${na(fixo.sector_name)}` : `Pôr ${janela.pessoa?.nome ?? ''} numa equipe`}
		</h2>
		{#if janela.pessoa}
			<div class="eq-alvo">
				<strong>{janela.pessoa.nome}</strong>
				<span>{infoDaPessoa(janela.pessoa)}</span>
			</div>
		{:else}
			<div class="eq-campo-bloco">
				<label for="busca-por">Quem entra (do banco de servos)</label>
				<div class="eq-busca eq-busca--grande">
					<input id="busca-por" type="search" class="fm-campo" placeholder="Buscar por nome ou CPF" bind:value={buscaJanela} />
				</div>
				<div class="eq-opcoes--lista" role="radiogroup" aria-label="Pessoa">
					{#each candidatos as o (o.user_id)}
						<button type="button" role="radio" aria-checked={escolhaPessoa === o.user_id} class="eq-opcao" onclick={() => (escolhaPessoa = o.user_id)}>
							<span class="eq-bola" aria-hidden="true"></span>
							<span class="eq-opcao-textos">
								<span class="eq-opcao-nome">{o.nome}</span>
								<span class="eq-opcao-info">{infoDaPessoa(o)}</span>
							</span>
							<span class="eq-opcao-sit">{textoDaSituacao(o)}</span>
						</button>
					{:else}
						<p class="eq-banco-vazio">
							{buscaJanela.trim() ? `Ninguém com “${buscaJanela.trim()}” no banco.` : 'Todos os inscritos como servo já estão numa equipe.'}
						</p>
					{/each}
				</div>
			</div>
		{/if}
		{#if !fixo}
			<fieldset class="eq-campo-bloco">
				<legend class="eq-legenda">Em qual setor</legend>
				<div class="eq-opcoes" role="radiogroup" aria-label="Setor">
					{#each setoresParaPor as s (s.id)}
						<button type="button" role="radio" aria-checked={escolhaSetor === s.id} class="eq-opcao" onclick={() => (escolhaSetor = s.id)}>
							<span class="eq-bola" aria-hidden="true"></span>
							<span class="eq-opcao-textos">
								<span class="eq-opcao-nome">{s.sector_name}</span>
								<span class="eq-opcao-info">{vagasDaOpcao(s)}</span>
							</span>
						</button>
					{/each}
				</div>
			</fieldset>
		{/if}
		{#if poderes?.lideres}
			<fieldset class="eq-campo-bloco">
				<legend class="eq-legenda">Como</legend>
				<div class="eq-papeis" role="radiogroup" aria-label="Papel">
					<button type="button" role="radio" aria-checked={!comoLider} class="eq-radio" onclick={() => (comoLider = false)}>
						<span class="eq-bola" aria-hidden="true"></span>Servo
					</button>
					<button type="button" role="radio" aria-checked={comoLider} class="eq-radio" onclick={() => (comoLider = true)}>
						<span class="eq-bola" aria-hidden="true"></span>Líder
					</button>
				</div>
			</fieldset>
		{/if}
		{#if pessoaEscolhida?.situacao === 'sup' && pessoaEscolhida.suplente_setor}
			<p class="ins-aviso amb"><span>Está na fila de suplentes {da(pessoaEscolhida.suplente_setor)}. Ao entrar na equipe, sai da fila.</span></p>
		{/if}
		{#if destinoCompleto}<p class="ins-aviso amb"><span>{destinoCompleto}</span></p>{/if}
		{#if sorteioFeito}<p class="ins-aviso azul"><span>Conta como indicação: continua na equipe se o sorteio for refeito.</span></p>{/if}
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fechar} disabled={enviando}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={enviarPor} disabled={!setorEscolhido || !pessoaEscolhida || enviando}>
				{enviando ? 'Salvando...' : comoLider ? 'Pôr como líder' : 'Pôr como servo'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'mover'}
	{@const de = janela.setor}
	{@const p = janela.pessoa}
	<Janela idTitulo="t-mover" largura={600} faixa="linear-gradient(90deg,#1B2F5E,#B69E55)" travada={enviando} onfechar={fechar}>
		<h2 id="t-mover" class="fm-janela-titulo">Mover {p.nome}</h2>
		<p class="eq-texto">Hoje está {na(de.sector_name)}. Escolha o novo setor.</p>
		<div class="eq-opcoes" role="radiogroup" aria-label="Novo setor">
			{#each lig.filter((s) => s.id !== de.id) as s (s.id)}
				<button type="button" role="radio" aria-checked={escolhaSetor === s.id} class="eq-opcao" onclick={() => (escolhaSetor = s.id)}>
					<span class="eq-bola" aria-hidden="true"></span>
					<span class="eq-opcao-textos">
						<span class="eq-opcao-nome">{s.sector_name}</span>
						<span class="eq-opcao-info">{vagasDaOpcao(s)}</span>
					</span>
				</button>
			{/each}
		</div>
		<p class="eq-texto eq-texto--menor">
			{setorEscolhido
				? `${primeiroNome(p.nome)} sai ${da(de.sector_name)} e entra ${na(setorEscolhido.sector_name)} como servo.`
				: 'Escolha o setor de destino.'}
		</p>
		{#if p.lider}<p class="ins-aviso amb"><span>É líder {da(de.sector_name)}: deixa de ser líder e entra no novo setor como servo.</span></p>{/if}
		{#if destinoCompleto}<p class="ins-aviso amb"><span>{destinoCompleto}</span></p>{/if}
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fechar} disabled={enviando}>Cancelar</button>
			<button
				type="button"
				class="fm-btn fm-btn--ouro fm-btn--grande"
				disabled={!setorEscolhido || enviando}
				onclick={() => {
					const para = setorEscolhido;
					if (!para || para.id === null || de.id === null) return;
					const deId = de.id;
					const paraId = para.id;
					executar(() => moverDeSetor(atividadeId, deId, p.user_id, paraId), 'mover', `${p.nome} foi para ${oA(para.sector_name)}.`);
				}}
			>
				{enviando ? 'Movendo...' : 'Mover'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'tirar'}
	{@const s = janela.setor}
	{@const p = janela.pessoa}
	<Janela idTitulo="t-tirar" largura={580} faixa="#9B2C2C" alerta travada={enviando} onfechar={fechar}>
		<h2 id="t-tirar" class="fm-janela-titulo">Tirar {p.nome} da equipe?</h2>
		<p class="eq-texto">Sai {da(s.sector_name)} e volta para o banco de servos. A inscrição continua.</p>
		{#if p.sorteado}
			<p class="ins-aviso amb"><span>Foi sorteado(a): passa para “Não sorteado” no quadro do sorteio, e a vaga fica aberta.</span></p>
		{/if}
		{#if p.lider}<p class="ins-aviso amb"><span>É líder: {oA(s.sector_name)} fica sem líder.</span></p>{/if}
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={fechar} disabled={enviando}>Cancelar</button>
			<button
				type="button"
				class="fm-btn fm-btn--perigo-cheio fm-btn--grande"
				disabled={enviando}
				onclick={() => {
					if (s.id === null) return;
					const acsId = s.id;
					executar(() => tirarDaEquipe(atividadeId, acsId, p.user_id), 'tirar da equipe', `${p.nome} saiu da equipe e voltou para o banco.`);
				}}
			>
				{enviando ? 'Tirando...' : 'Tirar da equipe'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'tirarSetor'}
	{@const s = janela.setor}
	{@const n = ocupadas(s)}
	<Janela idTitulo="t-tirar-setor" largura={600} faixa="#9B2C2C" alerta travada={enviando} onfechar={fechar}>
		<h2 id="t-tirar-setor" class="fm-janela-titulo">Tirar {oA(s.sector_name)} deste acampamento?</h2>
		{#if n > 0}
			<p class="ins-aviso verm">
				<span
					><strong>{plural(n, 'pessoa sai', 'pessoas saem')} da equipe</strong> e {n === 1 ? 'volta' : 'voltam'} para o banco de servos. Quem foi
					sorteado passa para “Não sorteado”.</span
				>
			</p>
		{/if}
		<p class="eq-texto eq-texto--menor">
			{#if s.default_vacancies !== null}
				O setor continua no modelo {da(categoria)} e não volta sozinho para este acampamento. Para ligá-lo de novo, use “Do modelo {da(categoria)},
				fora deste acampamento”.
			{:else}
				O setor não está no modelo {da(categoria)}: depois de tirado, só volta se for posto no modelo.
			{/if}
		</p>
		{#if n > 0}
			<label class="ins-check"><input type="checkbox" bind:checked={entendo} disabled={enviando} />Entendo que as pessoas saem da equipe</label>
		{/if}
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={fechar} disabled={enviando}>Cancelar</button>
			<button
				type="button"
				class="fm-btn fm-btn--perigo-cheio fm-btn--grande"
				disabled={(n > 0 && !entendo) || enviando}
				onclick={() => {
					if (s.id === null) return;
					const acsId = s.id;
					executar(() => tirarSetor(atividadeId, acsId), 'tirar o setor', `O setor ${s.sector_name} saiu do acampamento.`);
				}}
			>
				{enviando ? 'Tirando...' : 'Tirar setor'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'ligar'}
	{@const s = janela.setor}
	<Janela idTitulo="t-ligar" largura={520} faixa="linear-gradient(90deg,#1B2F5E,#B69E55)" travada={enviando} onfechar={fechar}>
		<h2 id="t-ligar" class="fm-janela-titulo">Ligar {oA(s.sector_name)} a este acampamento</h2>
		<div class="eq-campo-bloco vagas-ligar">
			<label for="vagas-ligar">Vagas</label>
			<input id="vagas-ligar" type="number" min="0" class="fm-campo" bind:value={vagasLigar} />
		</div>
		<p class="ins-ajuda">Começa com as vagas do modelo {da(categoria)}. Mudar aqui vale só para este acampamento.</p>
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fechar} disabled={enviando}>Cancelar</button>
			<button
				type="button"
				class="fm-btn fm-btn--ouro fm-btn--grande"
				disabled={enviando || !(Number(vagasLigar) >= 0)}
				onclick={() => executar(() => ligarSetor(atividadeId, s.categories_sectors_id, Math.max(0, Math.floor(Number(vagasLigar) || 0))), 'ligar o setor', `${s.sector_name} entrou no acampamento.`)}
			>
				{enviando ? 'Ligando...' : 'Ligar setor'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'geral'}
	<Janela idTitulo="t-geral" largura={620} faixa="linear-gradient(90deg,#1B2F5E,#B69E55)" travada={enviando} onfechar={fechar}>
		<h2 id="t-geral" class="fm-janela-titulo">Designar servo geral</h2>
		<p class="eq-texto eq-texto--menor">
			O servo geral coordena a equipe toda e pode pôr líderes e servos em qualquer setor. Pode haver mais de um.
		</p>
		<div class="eq-busca eq-busca--grande">
			<input type="search" aria-label="Buscar no banco" class="fm-campo" placeholder="Buscar por nome ou CPF" bind:value={buscaJanela} />
		</div>
		<div class="eq-opcoes--lista" role="radiogroup" aria-label="Pessoa">
			{#each candidatos as o (o.user_id)}
				<button type="button" role="radio" aria-checked={escolhaPessoa === o.user_id} class="eq-opcao" onclick={() => (escolhaPessoa = o.user_id)}>
					<span class="eq-bola" aria-hidden="true"></span>
					<span class="eq-opcao-textos">
						<span class="eq-opcao-nome">{o.nome}</span>
						<span class="eq-opcao-info">{infoDaPessoa(o)}</span>
					</span>
				</button>
			{:else}
				<p class="eq-banco-vazio">{buscaJanela.trim() ? `Ninguém com “${buscaJanela.trim()}” no banco.` : 'Ninguém no banco de servos.'}</p>
			{/each}
		</div>
		<p class="ins-aviso azul">
			<span>Só aparecem pessoas do banco. Quem já está num setor precisa sair dele antes: servo geral não fica em setor.</span>
		</p>
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fechar} disabled={enviando}>Cancelar</button>
			<button
				type="button"
				class="fm-btn fm-btn--ouro fm-btn--grande"
				disabled={!pessoaEscolhida || enviando}
				onclick={() => {
					const p = pessoaEscolhida;
					if (p) executar(() => designarServoGeral(atividadeId, p.user_id), 'designar o servo geral', 'Servo geral designado.');
				}}
			>
				{enviando ? 'Salvando...' : 'Designar'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'tirarGeral'}
	{@const p = janela.pessoa}
	<Janela idTitulo="t-tirar-geral" largura={560} faixa="#9B2C2C" alerta travada={enviando} onfechar={fechar}>
		<h2 id="t-tirar-geral" class="fm-janela-titulo">Tirar {p.nome} de servo geral?</h2>
		<p class="eq-texto">Deixa de coordenar a equipe e volta para o banco de servos.</p>
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={fechar} disabled={enviando}>Cancelar</button>
			<button
				type="button"
				class="fm-btn fm-btn--perigo-cheio fm-btn--grande"
				disabled={enviando}
				onclick={() => executar(() => tirarServoGeral(atividadeId, p.user_id), 'tirar de servo geral', `${p.nome} deixou de ser servo geral e voltou para o banco.`)}
			>
				{enviando ? 'Tirando...' : 'Tirar de servo geral'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'aplicar'}
	<Janela idTitulo="t-aplicar" largura={600} faixa="linear-gradient(90deg,#1B2F5E,#B69E55)" travada={enviando} onfechar={fechar}>
		<h2 id="t-aplicar" class="fm-janela-titulo">Aplicar o modelo {da(categoria)}?</h2>
		<ul class="fm-janela-lista">
			{#if difs.length}<li>As vagas voltam para as do modelo: {difs.map((s) => `${s.sector_name} ${s.actual_vacancies} → ${s.default_vacancies}`).join(', ')}.</li>{/if}
			{#if fora.length}<li>Entram os setores do modelo que faltam: {fora.map((s) => s.sector_name).join(', ')}.</li>{/if}
			<li>Ninguém sai da equipe, e nenhum setor é tirado.</li>
		</ul>
		<p class="ins-ajuda">Só existe antes do sorteio de servos.</p>
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fechar} disabled={enviando}>Cancelar</button>
			<button
				type="button"
				class="fm-btn fm-btn--ouro fm-btn--grande"
				disabled={enviando}
				onclick={() => executar(() => aplicarModelo(atividadeId), 'aplicar o modelo', `Modelo aplicado: setores e vagas iguais aos do modelo ${da(categoria)}.`)}
			>
				{enviando ? 'Aplicando...' : 'Aplicar o modelo'}
			</button>
		</div>
	</Janela>
{/if}

{#if aviso}
	<div class="eq-toast" class:eq-toast--erro={aviso.erro} role="status">
		<span aria-hidden="true">{aviso.erro ? '!' : '✓'}</span><span>{aviso.texto}</span>
		<button type="button" aria-label="Fechar aviso" onclick={() => (aviso = null)}>✕</button>
	</div>
{/if}

<style>
	.colunas {
		display: grid;
		grid-template-columns: minmax(0, 1fr);
		gap: 20px;
		align-items: start;
	}
	.colunas.com-banco {
		grid-template-columns: minmax(0, 1fr) 320px;
	}
	.principal {
		display: flex;
		flex-direction: column;
		gap: 18px;
		min-width: 0;
	}
	.a-direita {
		margin-left: auto;
	}
	.fora {
		height: 36px;
	}
	.fora-info {
		font-weight: 400;
		color: var(--tinta-3);
	}
	.vagas-ligar {
		max-width: 200px;
	}

	.carregando {
		overflow: hidden;
	}
	.carregando-topo {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 4px 0 16px;
		font-size: 17px;
		color: var(--tinta-3);
		border-bottom: 1px solid var(--linha);
	}
	.ossos {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 16px;
		padding-top: 20px;
	}
	.osso {
		height: 180px;
		border-radius: 14px;
		background: #f3eddf;
	}
	.centro {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 14px;
		padding: 56px 24px;
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
</style>
