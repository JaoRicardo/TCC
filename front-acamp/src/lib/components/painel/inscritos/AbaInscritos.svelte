<script lang="ts">
	/**
	 * Aba "Inscritos" (no evento, a tela inteira): busca, filtros e a tabela.
	 * Tudo filtra na própria tela, sobre a lista que vem numa leitura só.
	 */
	import { SITUACAO, linhaCurta, naFolga } from '$lib/pagamentos';
	import { pode } from '$lib/permissoes';
	import {
		COM_VAGA,
		SAIU,
		SIT,
		casaBusca,
		grupoDe,
		idade,
		motivoDe,
		naoPago,
		situacaoDe,
		textoDaSituacao,
		type Inscricao,
		type Sit
	} from '$lib/inscritos';

	let {
		inscricoes,
		evento,
		casais,
		feito,
		cobra,
		agora,
		onindicar,
		ondesfazer
	}: {
		inscricoes: Inscricao[];
		evento: boolean;
		casais: boolean;
		feito: { Campista: boolean; Servo: boolean };
		cobra: boolean;
		agora: Date;
		onindicar: (i: Inscricao) => void;
		ondesfazer: (i: Inscricao) => void;
	} = $props();

	type Tipo = 'todos' | 'camp' | 'serv';
	type FiltroSit = 'todas' | 'aguarda' | 'comVaga' | 'sup' | 'pend' | 'saiu';

	let busca = $state('');
	let tipo: Tipo = $state('todos');
	let filtroSit: FiltroSit = $state('todas');
	let soNaoPagos = $state(false);

	const sitDe = (i: Inscricao) => situacaoDe(i, evento, feito);

	const FILTROS_SIT: [FiltroSit, string][] = $derived(evento
		? [
				['todas', 'Todas'],
				['comVaga', 'Inscritos'],
				['pend', 'Em andamento'],
				['saiu', 'Saíram']
			]
		: [
				['todas', 'Todas'],
				['aguarda', 'Aguardando sorteio'],
				['comVaga', 'Com vaga'],
				['sup', 'Suplentes'],
				['pend', 'Pendências'],
				['saiu', 'Saíram']
			]);

	function naSituacao(s: Sit, f: FiltroSit): boolean {
		if (f === 'todas') return true;
		if (f === 'aguarda') return s === 'insc';
		if (f === 'comVaga') return COM_VAGA.includes(s);
		if (f === 'sup') return s === 'sup';
		if (f === 'pend') return s === 'corr' || s === 'andam';
		return SAIU.includes(s);
	}
	const doTipo = (i: Inscricao, t: Tipo) =>
		t === 'todos' || (t === 'camp' ? i.subscription_type === 'Campista' : i.subscription_type === 'Servo');

	/** Passa em todos os filtros, menos o ignorado (para as contagens de cada botão). */
	function passa(i: Inscricao, ignora?: 'tipo' | 'sit'): boolean {
		return (
			(ignora === 'tipo' || doTipo(i, tipo)) &&
			(ignora === 'sit' || naSituacao(sitDe(i), filtroSit)) &&
			casaBusca(i.user.name, i.user.cpf, busca) &&
			(!soNaoPagos || naoPago(i))
		);
	}

	const tipos = $derived(
		([
			['todos', 'Todos'],
			['camp', 'Campistas'],
			['serv', 'Servos']
		] as [Tipo, string][]).map(([k, nome]) => ({
			k,
			nome,
			n: inscricoes.filter((i) => doTipo(i, k) && passa(i, 'tipo')).length
		}))
	);
	const situacoes = $derived(
		FILTROS_SIT.map(([k, nome]) => ({ k, nome, n: inscricoes.filter((i) => naSituacao(sitDe(i), k) && passa(i, 'sit')).length }))
	);

	const ORDEM_TIPO: Record<string, number> = { Campista: 0, Participante: 0, Servo: 1 };
	/** Campistas e depois servos; o casal junto (pela ordem do nome de quem vem antes); depois por nome. */
	const linhas = $derived.by(() => {
		const chave = (i: Inscricao) => {
			const nomes = [i.user.name, i.conjuge?.nome].filter(Boolean).sort((a, b) => a!.localeCompare(b!, 'pt-BR'));
			return nomes[0] as string;
		};
		return inscricoes
			.filter((i) => passa(i))
			.sort(
				(a, b) =>
					(ORDEM_TIPO[a.subscription_type] ?? 2) - (ORDEM_TIPO[b.subscription_type] ?? 2) ||
					chave(a).localeCompare(chave(b), 'pt-BR') ||
					a.user.name.localeCompare(b.user.name, 'pt-BR')
			);
	});

	const mesmoCasal = (a: Inscricao | undefined, b: Inscricao) => !!a && !!b.conjuge && b.conjuge.id === a.id;

	function limpar() {
		busca = '';
		tipo = 'todos';
		filtroSit = 'todas';
		soNaoPagos = false;
	}

	const dataBr = (s: string | null) => (s ? s.slice(0, 10).split('-').reverse().join('/') : '—');
</script>

<div class="barra">
	<div class="busca">
		<label for="busca-insc" class="ins-sr">Buscar por nome ou CPF</label>
		<svg class="lupa" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
		<input id="busca-insc" type="search" class="fm-campo" placeholder="Buscar por nome ou CPF" bind:value={busca} autocomplete="off" />
	</div>
	{#if !evento}
		<div class="pilulas" role="radiogroup" aria-label="Tipo">
			{#each tipos as t (t.k)}
				<button type="button" role="radio" aria-checked={tipo === t.k} class:sel={tipo === t.k} onclick={() => (tipo = t.k)}>
					{t.nome} <span class="n">{t.n}</span>
				</button>
			{/each}
		</div>
	{/if}
	{#if cobra}
		<label class="so-nao-pagos"><input type="checkbox" bind:checked={soNaoPagos} />Só não pagos</label>
	{/if}
</div>

<div class="situacoes" role="radiogroup" aria-label="Situação">
	{#each situacoes as s (s.k)}
		<button type="button" role="radio" aria-checked={filtroSit === s.k} class:sel={filtroSit === s.k} onclick={() => (filtroSit = s.k)}>
			{s.nome} · {s.n}
		</button>
	{/each}
</div>

{#if linhas.length === 0}
	<div class="sem-linhas">
		<p>Ninguém com esse filtro.</p>
		<button type="button" class="fm-link" onclick={limpar}>Limpar a busca e os filtros</button>
	</div>
{:else}
	<div class="tabela" class:sem-pagamento={!cobra} role="table" aria-label="Inscrições">
		<div class="linha cabeca" role="row">
			<span role="columnheader">Pessoa</span><span role="columnheader">Grupo</span><span role="columnheader">Tipo</span><span
				role="columnheader">Inscrição</span
			><span role="columnheader">Situação</span>{#if cobra}<span role="columnheader">Pagamento</span>{/if}
		</div>
		{#each linhas as i, n (i.id)}
			{@const sit = sitDe(i)}
			{@const motivo = motivoDe(i, sit)}
			{@const antes = linhas[n - 1]}
			{@const depois = linhas[n + 1]}
			{@const ligado = mesmoCasal(antes, i) || mesmoCasal(depois, i)}
			{@const pag = i.pagamento}
			<div class="linha" class:casal={ligado} class:casal-segundo={mesmoCasal(antes, i)} role="row">
				<span role="cell" class="pessoa">
					{#if pode('usuarios')}
						<a href="/admin/pessoas/{i.user.id}" class="nome">{i.user.name}</a>
					{:else}
						<span class="nome">{i.user.name}</span>
					{/if}
					<span class="cpf">{i.user.cpf}{#if idade(i.user.birthday)} · {idade(i.user.birthday)}{/if}</span>
				</span>
				<span role="cell" class="texto">{grupoDe(i, casais)}</span>
				<span role="cell" class="texto">{i.subscription_type}</span>
				<span role="cell" class="data">{dataBr(i.created)}</span>
				<span role="cell" class="situacao">
					<span class="ins-pill {SIT[sit][1]}">{textoDaSituacao(i, sit)}</span>
					{#if motivo}<span class="motivo">{motivo}</span>{/if}
					{#if !evento && (sit === 'insc' || sit === 'sup' || sit === 'nao')}
						<button type="button" class="ins-link" onclick={() => onindicar(i)}>Indicar</button>
					{/if}
					{#if !evento && (sit === 'ind' || ((sit === 'env' || sit === 'corr') && i.metodo && i.metodo !== 'Sorteio'))}
						<button type="button" class="ins-link perigo" onclick={() => ondesfazer(i)}>Desfazer indicação</button>
					{/if}
				</span>
				{#if cobra}
					<span role="cell" class="pagamento">
						{#if pag?.situacao && !(SAIU.includes(sit) && pag.situacao === 'nao_pago')}
							{@const venceu = pag.situacao === 'nao_pago' && naFolga(pag, agora)}
							<span class="ins-pill {venceu ? 'can' : SITUACAO[pag.situacao].tom}">{SITUACAO[pag.situacao].texto}</span>
							<span class="pag-sub">{linhaCurta(pag, evento, agora)}</span>
						{/if}
					</span>
				{/if}
			</div>
		{/each}
	</div>
{/if}
<p class="ins-ajuda rodape">
	{linhas.length === 1 ? '1 inscrição' : `${linhas.length} inscrições`} ·
	{pode('usuarios') ? 'O nome abre a ficha da pessoa.' : ''}
	{casais ? 'Casais aparecem juntos, ligados por um filete dourado.' : ''}
</p>

<style>
	.barra {
		display: flex;
		align-items: center;
		gap: 14px;
		flex-wrap: wrap;
	}
	.busca {
		position: relative;
		flex: 1 1 320px;
		max-width: 420px;
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
	.pilulas {
		display: flex;
		gap: 4px;
		padding: 4px;
		background: var(--linha);
		border-radius: 999px;
	}
	.pilulas button {
		height: 40px;
		padding: 0 16px;
		font: 600 15px 'Source Sans 3', sans-serif;
		color: var(--tinta-3);
		background: transparent;
		border: none;
		border-radius: 999px;
		cursor: pointer;
	}
	.pilulas button.sel {
		font-weight: 700;
		color: var(--azul-noite);
		background: var(--pergaminho);
		box-shadow: 0 2px 6px -2px rgba(15, 29, 61, 0.35);
	}
	.pilulas .n {
		font-weight: 700;
		color: var(--ouro-texto);
	}
	.so-nao-pagos {
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
		cursor: pointer;
	}
	.so-nao-pagos input {
		width: 20px;
		height: 20px;
		accent-color: #1b2f5e;
	}
	.situacoes {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
	}
	.situacoes button {
		height: 36px;
		padding: 0 14px;
		font: 600 15px 'Source Sans 3', sans-serif;
		color: var(--azul);
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 999px;
		cursor: pointer;
	}
	.situacoes button.sel {
		color: var(--pergaminho);
		background: var(--azul);
		border-color: var(--azul);
	}
	.sem-linhas {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8px;
		padding: 40px 16px;
		text-align: center;
	}
	.sem-linhas p {
		margin: 0;
		font: 600 26px 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.tabela {
		display: flex;
		flex-direction: column;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		overflow: hidden;
	}
	.linha {
		display: grid;
		grid-template-columns: minmax(0, 2fr) minmax(0, 1.4fr) 110px 110px minmax(0, 1.6fr) 170px;
		gap: 16px;
		align-items: center;
		padding: 14px 20px 14px 21px;
		border-top: 1px solid var(--linha);
		border-left: 3px solid transparent;
		background: var(--pergaminho);
	}
	.sem-pagamento .linha {
		grid-template-columns: minmax(0, 2fr) minmax(0, 1.4fr) 110px 110px minmax(0, 1.6fr);
	}
	.linha.cabeca {
		padding: 12px 20px 12px 24px;
		border-top: none;
		border-left: none;
		border-bottom: 1px solid var(--borda-suave);
		background: var(--cabecalho-tabela);
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}
	.linha.cabeca + .linha {
		border-top: none;
	}
	.linha.casal {
		border-left-color: var(--ouro);
	}
	.linha.casal-segundo {
		border-top: 1px dashed var(--borda-suave);
	}
	.pessoa {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}
	.nome {
		font-size: 17px;
		font-weight: 700;
		color: var(--tinta);
		text-decoration: none;
	}
	a.nome:hover {
		color: var(--azul);
		text-decoration: underline;
	}
	.cpf {
		font-size: 14px;
		color: var(--tinta-3);
		font-variant-numeric: tabular-nums;
	}
	.texto {
		font-size: 15px;
		line-height: 1.35;
		color: var(--tinta-2);
	}
	.data {
		font-size: 15px;
		color: var(--tinta-3);
		font-variant-numeric: tabular-nums;
	}
	.situacao {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 6px;
	}
	.motivo {
		font-size: 14px;
		line-height: 1.35;
		color: var(--tinta-3);
	}
	.pagamento {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 3px;
	}
	.pag-sub {
		font-size: 13px;
		color: var(--tinta-3);
	}
	.rodape {
		font-size: 14px;
	}
</style>
