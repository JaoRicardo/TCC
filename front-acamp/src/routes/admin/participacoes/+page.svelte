<script lang="ts">
	/**
	 * Gestão › Participações anteriores (permissão "participacoes"): quem já foi
	 * campista numa categoria antes do sistema existir, cadastrado pelo CPF —
	 * mesmo sem conta. Vale igual a um acampamento concluído pelo sistema: não
	 * deixa ser campista de novo na categoria, libera servir (nela e nos degraus
	 * de baixo) e cumpre o pré-requisito de outra categoria.
	 *
	 * Feita para cadastrar muita gente em sequência: Tab passa de campo em campo,
	 * Enter (ou "Adicionar") grava, limpa tudo e volta para o CPF.
	 */
	import { onMount, tick } from 'svelte';
	import '$lib/styles/formularios.css';
	import '$lib/styles/inscritos.css';
	import { pode } from '$lib/permissoes';
	import { semAcento, digitosDeCpf, cpfContem } from '$lib/busca';
	import { cpfValido } from '$lib/mascaras';
	import {
		adicionarParticipacao,
		consultarCpf,
		listarParticipacoes,
		removerParticipacao,
		type CategoriaDoCampo,
		type ConsultaCpf,
		type NovaParticipacao,
		type Participacao
	} from '$lib/services/participacaoService';

	/** A ficha da pessoa é da tela Pessoas e acessos. */
	const veFichas = pode('usuarios');

	let participacoes: Participacao[] = $state([]);
	let categorias: CategoriaDoCampo[] = $state([]);
	let estado: 'carregando' | 'erro' | 'pronto' = $state('carregando');

	// ─── Formulário ─────────────────────────────────────────────────
	let cpf = $state('');
	let nome = $state('');
	let categoriaId: number | null = $state(null);
	let ano = $state('');
	let erros: Record<string, string> = $state({});
	let enviando = $state(false);
	let campoCpf: HTMLInputElement | undefined = $state();

	/** O que já se sabe do CPF digitado (conta e onde já conta como campista). */
	let consulta: ConsultaCpf | null = $state(null);
	let consultando = $state(false);
	/** O nome veio da conta (e não foi digitado): some se o CPF mudar. */
	let nomeDaConta = $state(false);

	function mascararCpf(valor: string): string {
		const d = valor.replace(/\D/g, '').slice(0, 11);
		if (d.length <= 3) return d;
		if (d.length <= 6) return `${d.slice(0, 3)}.${d.slice(3)}`;
		if (d.length <= 9) return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6)}`;
		return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}`;
	}

	let ultimoConsultado = '';
	async function aoDigitarCpf(e: Event) {
		const campo = e.currentTarget as HTMLInputElement;
		cpf = mascararCpf(campo.value);
		// Letra, símbolo ou 12º número deixam o valor mascarado igual ao de antes;
		// sem mudança de estado o Svelte não redesenha o campo, então ele
		// mostraria o que foi digitado. Por isso se escreve direto no campo.
		if (campo.value !== cpf) campo.value = cpf;
		delete erros.cpf;
		const digitos = cpf.replace(/\D/g, '');
		// Incompleto ou com os dígitos verificadores errados: nada a consultar.
		if (!cpfValido(digitos)) {
			consulta = null;
			ultimoConsultado = '';
			if (nomeDaConta) {
				nome = '';
				nomeDaConta = false;
			}
			return;
		}
		if (digitos === ultimoConsultado) return;
		ultimoConsultado = digitos;
		consultando = true;
		const r = await consultarCpf(digitos);
		if (digitos !== cpf.replace(/\D/g, '')) return; // já mudou
		consultando = false;
		consulta = r.ok ? r.data : null;
		// Nome da conta ou, sem conta, o de um cadastro anterior deste CPF.
		const nomeConhecido = consulta?.conta?.nome ?? consulta?.cadastradas[0]?.nome;
		if (nomeConhecido && (nome.trim() === '' || nomeDaConta)) {
			nome = nomeConhecido;
			nomeDaConta = true;
			delete erros.nome;
		}
	}

	/** Categorias em que este CPF já conta como campista: aparecem marcadas no campo. */
	const jaConta = $derived.by(() => {
		const m = new Map<number, string>();
		if (!consulta) return m;
		for (const s of consulta.sistema) m.set(s.categoriaId, 'pelo sistema');
		for (const c of consulta.cadastradas) m.set(c.categoriaId, 'já cadastrada');
		return m;
	});

	/** Com os 11 números a tela diz se o CPF é válido (antes disso, nada). */
	const cpfCompleto = $derived(cpf.replace(/\D/g, '').length === 11);

	const categoriasEmUso =$derived(categorias.filter((c) => !c.arquivada));
	const categoriasArquivadas = $derived(categorias.filter((c) => c.arquivada));

	function limpar() {
		cpf = '';
		nome = '';
		categoriaId = null;
		ano = '';
		erros = {};
		consulta = null;
		ultimoConsultado = '';
		nomeDaConta = false;
	}

	async function adicionar(e?: SubmitEvent) {
		e?.preventDefault();
		if (enviando) return;
		const dados: NovaParticipacao = { cpf, nome, categoriaId, ano };

		// Os mesmos avisos do servidor, sem esperar a volta.
		const locais: Record<string, string> = {};
		if (cpf.replace(/\D/g, '').length !== 11) locais.cpf = cpf ? 'O CPF tem 11 números.' : 'Informe o CPF.';
		else if (!cpfValido(cpf)) locais.cpf = 'CPF inválido. Confira os números.';
		if (!nome.trim()) locais.nome = 'Informe o nome.';
		if (!categoriaId) locais.categoriaId = 'Escolha a categoria.';
		if (ano && !/^\d{4}$/.test(ano)) locais.ano = 'Ano com 4 números, ou deixe em branco.';
		if (Object.keys(locais).length) {
			erros = locais;
			focarPrimeiroErro();
			return;
		}

		enviando = true;
		const r = await adicionarParticipacao(dados);
		enviando = false;
		if (!r.ok) {
			erros = r.erros ?? {};
			if (!r.erros) avisar(r.error || 'Não foi possível adicionar. Tente novamente.', true);
			focarPrimeiroErro();
			return;
		}

		participacoes = [r.data, ...participacoes];
		const p = r.data;
		avisar(`${p.nome}: campista em ${p.categoria}${p.ano ? ` (${p.ano})` : ''}.`, false, () => desfazerAdicao(p));
		limpar();
		await tick();
		campoCpf?.focus();
	}

	async function focarPrimeiroErro() {
		await tick();
		const ordem = ['cpf', 'nome', 'categoriaId', 'ano'];
		const primeiro = ordem.find((c) => erros[c]);
		if (primeiro) document.getElementById(`pa-${primeiro}`)?.focus();
	}

	/** Enter no campo de categoria também grava (no select o Enter não envia o formulário). */
	function enterNoSelect(e: KeyboardEvent) {
		if (e.key === 'Enter') {
			e.preventDefault();
			adicionar();
		}
	}

	async function desfazerAdicao(p: Participacao) {
		aviso = null;
		const r = await removerParticipacao(p.id);
		if (!r.ok) return avisar('Não foi possível desfazer.', true);
		participacoes = participacoes.filter((x) => x.id !== p.id);
		avisar(`Cadastro de ${p.nome} desfeito.`);
	}

	async function remover(p: Participacao) {
		const r = await removerParticipacao(p.id);
		if (!r.ok) return avisar(r.error || 'Não foi possível remover.', true);
		participacoes = participacoes.filter((x) => x.id !== p.id);
		avisar(`Removida: ${p.nome}, campista em ${p.categoria}.`, false, () => refazer(p));
	}

	/** "Desfazer" da remoção: cadastra de novo com os mesmos dados. */
	async function refazer(p: Participacao) {
		aviso = null;
		const r = await adicionarParticipacao({ cpf: p.cpf, nome: p.nome, categoriaId: p.categoriaId, ano: p.ano ? String(p.ano) : '' });
		if (!r.ok) return avisar(r.error || 'Não foi possível desfazer.', true);
		participacoes = [r.data, ...participacoes].sort((a, b) => (b.registradoEm ?? '').localeCompare(a.registradoEm ?? '') || b.id - a.id);
		avisar(`${p.nome} voltou para a lista.`);
	}

	// ─── Aviso no rodapé ────────────────────────────────────────────
	let aviso: { texto: string; erro: boolean; desfazer?: () => void } | null = $state(null);
	let timerAviso: ReturnType<typeof setTimeout> | undefined;
	function avisar(texto: string, erro = false, desfazer?: () => void) {
		aviso = { texto, erro, desfazer };
		clearTimeout(timerAviso);
		timerAviso = setTimeout(() => (aviso = null), desfazer ? 8000 : 4000);
	}

	// ─── Lista ──────────────────────────────────────────────────────
	let busca = $state('');
	let filtroCategoria: number | 0 = $state(0);
	const POR_VEZ = 100;
	let mostrando = $state(POR_VEZ);

	const filtradas = $derived.by(() => {
		const termo = semAcento(busca.trim());
		const digitos = digitosDeCpf(busca);
		return participacoes.filter((p) => {
			if (filtroCategoria && p.categoriaId !== filtroCategoria) return false;
			if (!termo) return true;
			return semAcento(p.nome).includes(termo) || cpfContem(p.cpf, digitos);
		});
	});

	$effect(() => {
		// Nova busca ou filtro: volta ao começo da lista.
		void busca;
		void filtroCategoria;
		mostrando = POR_VEZ;
	});

	const dataCurta = (iso: string | null) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '');

	async function carregar() {
		estado = 'carregando';
		const r = await listarParticipacoes();
		if (!r.ok) {
			estado = 'erro';
			return;
		}
		participacoes = r.data.participacoes;
		categorias = r.data.categorias;
		estado = 'pronto';
		await tick();
		campoCpf?.focus();
	}

	onMount(() => {
		carregar();
		return () => clearTimeout(timerAviso);
	});
</script>

<svelte:head>
	<title>Participações anteriores | Painel da Comunidade</title>
</svelte:head>

{#snippet opcaoDeCategoria(c: CategoriaDoCampo)}
	<option value={c.id}>{c.nome}{jaConta.has(c.id) ? ` — ${jaConta.get(c.id)}` : ''}</option>
{/snippet}

<div class="fm pa">
	<div class="cabeca">
		<h1 class="fm-h1">Participações anteriores</h1>
		<p class="fm-sub">
			Quem já foi campista antes do sistema. Basta o CPF, mesmo que a pessoa ainda não tenha conta: quando ela se inscrever, o
			sistema já sabe em que categoria ela foi campista — não deixa repetir como campista e libera servir.
		</p>
	</div>

	{#if estado === 'carregando'}
		<section class="fm-cartao" aria-busy="true">
			<div class="carregando"><span class="fm-spinner"></span>Carregando...</div>
		</section>
	{:else if estado === 'erro'}
		<section class="fm-cartao">
			<div class="centro" role="alert">
				<span class="erro-icone" aria-hidden="true">!</span>
				<p class="centro-titulo">Não foi possível carregar as participações.</p>
				<p class="centro-texto">Verifique sua conexão com a internet e tente novamente.</p>
				<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Tentar novamente</button>
			</div>
		</section>
	{:else}
		<section class="fm-cartao novo" aria-labelledby="t-novo">
			<div class="novo-topo">
				<h2 id="t-novo" class="fm-h2">Adicionar participação</h2>
				<p class="dica">
					<kbd>Tab</kbd> passa para o próximo campo · <kbd>Enter</kbd> adiciona e volta para o CPF
				</p>
			</div>

			<form class="linha-form" onsubmit={adicionar} novalidate>
				<div class="campo campo--cpf">
					<label class="fm-rotulo" for="pa-cpf">CPF</label>
					<input
						id="pa-cpf"
						class="fm-campo"
						class:com-erro={erros.cpf}
						bind:this={campoCpf}
						value={cpf}
						oninput={aoDigitarCpf}
						inputmode="numeric"
						maxlength="14"
						autocomplete="off"
						placeholder="000.000.000-00"
						aria-invalid={!!erros.cpf}
						aria-describedby="pa-cpf-info"
					/>
				</div>

				<div class="campo campo--nome">
					<label class="fm-rotulo" for="pa-nome">Nome</label>
					<input
						id="pa-nome"
						class="fm-campo"
						class:com-erro={erros.nome}
						bind:value={nome}
						oninput={() => {
							nomeDaConta = false;
							delete erros.nome;
						}}
						autocomplete="off"
						placeholder="Nome completo"
						aria-invalid={!!erros.nome}
					/>
				</div>

				<div class="campo campo--categoria">
					<label class="fm-rotulo" for="pa-categoriaId">Categoria</label>
					<select
						id="pa-categoriaId"
						class="fm-campo"
						class:com-erro={erros.categoriaId}
						bind:value={categoriaId}
						onchange={() => delete erros.categoriaId}
						onkeydown={enterNoSelect}
						aria-invalid={!!erros.categoriaId}
					>
						<option value={null} disabled>Escolha…</option>
						{#each categoriasEmUso as c (c.id)}{@render opcaoDeCategoria(c)}{/each}
						{#if categoriasArquivadas.length}
							<optgroup label="Arquivadas">
								{#each categoriasArquivadas as c (c.id)}{@render opcaoDeCategoria(c)}{/each}
							</optgroup>
						{/if}
					</select>
				</div>

				<div class="campo campo--ano">
					<label class="fm-rotulo" for="pa-ano">Ano <span class="opcional">(opcional)</span></label>
					<input
						id="pa-ano"
						class="fm-campo"
						class:com-erro={erros.ano}
						bind:value={ano}
						oninput={() => {
							ano = ano.replace(/\D/g, '').slice(0, 4);
							delete erros.ano;
						}}
						inputmode="numeric"
						autocomplete="off"
						placeholder="aaaa"
						aria-invalid={!!erros.ano}
					/>
				</div>

				<button type="submit" class="fm-btn fm-btn--ouro fm-btn--grande adicionar" disabled={enviando}>
					{enviando ? 'Adicionando…' : 'Adicionar'}
				</button>
			</form>

			<div class="sob-form" id="pa-cpf-info" aria-live="polite">
				{#if Object.keys(erros).length}
					<ul class="erros">
						{#each ['cpf', 'nome', 'categoriaId', 'ano'] as c (c)}
							{#if erros[c]}<li class="fm-erro-linha">{erros[c]}</li>{/if}
						{/each}
					</ul>
				{/if}
				{#if cpfCompleto && !erros.cpf}
					<p class="info">
						{#if cpfValido(cpf)}
							<span class="ins-pill ins-pill--mini ok">CPF válido</span>
						{:else}
							<span class="ins-pill ins-pill--mini can">CPF inválido</span> Confira os números.
						{/if}
					</p>
				{/if}
				{#if consultando}
					<p class="info"><span class="fm-spinner fm-spinner--pequeno"></span>Conferindo o CPF…</p>
				{:else if consulta}
					{#if consulta.sistema.length || consulta.cadastradas.length}
						<p class="info info--ja">
							Já conta como campista em:
							{#each consulta.sistema as s, i (`s${i}`)}
								<strong>{s.categoria}</strong> (pelo sistema, {s.atividade}){i < consulta.sistema.length - 1 || consulta.cadastradas.length ? ', ' : ''}
							{/each}
							{#each consulta.cadastradas as c, i (c.id)}
								<strong>{c.categoria}</strong>{c.ano ? ` (${c.ano})` : ''}{i < consulta.cadastradas.length - 1 ? ', ' : ''}
							{/each}.
						</p>
					{/if}
				{/if}
			</div>
		</section>

		<section class="fm-cartao lista" aria-labelledby="t-lista">
			<div class="lista-topo">
				<h2 id="t-lista" class="fm-h2">Cadastradas</h2>
				<span class="contagem">
					{filtradas.length === participacoes.length
						? `${participacoes.length} ${participacoes.length === 1 ? 'participação' : 'participações'}`
						: `${filtradas.length} de ${participacoes.length}`}
				</span>
				<div class="filtros">
					<label class="ins-sr" for="pa-busca">Buscar por nome ou CPF</label>
					<input id="pa-busca" class="fm-campo busca" type="search" bind:value={busca} placeholder="Buscar por nome ou CPF" autocomplete="off" />
					<label class="ins-sr" for="pa-filtro">Categoria</label>
					<select id="pa-filtro" class="fm-campo filtro" bind:value={filtroCategoria}>
						<option value={0}>Todas as categorias</option>
						{#each categorias as c (c.id)}<option value={c.id}>{c.nome}</option>{/each}
					</select>
				</div>
			</div>

			{#if participacoes.length === 0}
				<p class="vazio">Nenhuma participação cadastrada ainda. Comece pelo CPF, no quadro acima.</p>
			{:else if filtradas.length === 0}
				<p class="vazio">Nada encontrado com essa busca.</p>
			{:else}
				<table class="tabela">
					<thead>
						<tr>
							<th scope="col">Nome</th>
							<th scope="col">CPF</th>
							<th scope="col">Categoria</th>
							<th scope="col">Ano</th>
							<th scope="col">Conta</th>
							<th scope="col">Cadastrada</th>
							<th scope="col"><span class="ins-sr">Ações</span></th>
						</tr>
					</thead>
					<tbody>
						{#each filtradas.slice(0, mostrando) as p (p.id)}
							<tr>
								<td class="nome">
									{#if p.contaId && veFichas}<a href="/admin/pessoas/{p.contaId}">{p.nome}</a>{:else}{p.nome}{/if}
								</td>
								<td class="cpf">{p.cpf}</td>
								<td>{p.categoria}</td>
								<td>{p.ano ?? '—'}</td>
								<td>
									{#if p.contaId}<span class="ins-pill ins-pill--mini ok">Tem conta</span>{:else}<span class="ins-pill ins-pill--mini neutro">Sem conta</span>{/if}
								</td>
								<td class="quem">{dataCurta(p.registradoEm)}{p.registradoPor ? ` · ${p.registradoPor.split(' ')[0]}` : ''}</td>
								<td class="acoes">
									<button type="button" class="fm-btn fm-btn--perigo" onclick={() => remover(p)} aria-label="Remover {p.nome}, campista em {p.categoria}">Remover</button>
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
				{#if filtradas.length > mostrando}
					<div class="mais">
						<button type="button" class="fm-btn fm-btn--contorno" onclick={() => (mostrando += POR_VEZ)}>
							Mostrar mais ({filtradas.length - mostrando} restantes)
						</button>
					</div>
				{/if}
			{/if}
		</section>
	{/if}
</div>

{#if aviso}
	<div class="fm-toast" class:toast-erro={aviso.erro} role="status">
		<span>{aviso.texto}</span>
		{#if aviso.desfazer}<button type="button" class="fm-toast-acao" onclick={aviso.desfazer}>Desfazer</button>{/if}
		<button type="button" class="fm-toast-fechar" aria-label="Fechar aviso" onclick={() => (aviso = null)}>✕</button>
	</div>
{/if}

<style>
	.cabeca {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.cabeca .fm-sub {
		max-width: 900px;
	}

	/* ─── Adicionar ─── */
	.novo {
		padding: 22px 26px 18px;
		display: flex;
		flex-direction: column;
		gap: 16px;
	}
	.novo-topo {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
	}
	.novo-topo .fm-h2 {
		font-size: 26px;
	}
	.dica {
		margin: 0;
		font-size: 15px;
		color: var(--tinta-3);
	}
	kbd {
		display: inline-block;
		padding: 1px 7px;
		font: 600 13px 'Source Sans 3', sans-serif;
		color: var(--azul);
		background: var(--marfim);
		border: 1px solid var(--borda);
		border-bottom-width: 2px;
		border-radius: 6px;
	}
	.linha-form {
		display: grid;
		grid-template-columns: 200px minmax(220px, 1fr) 220px 130px auto;
		gap: 14px;
		align-items: end;
	}
	.campo {
		display: flex;
		flex-direction: column;
		gap: 6px;
		min-width: 0;
	}
	.opcional {
		font-weight: 500;
		letter-spacing: 0;
		text-transform: none;
		color: var(--tinta-3);
	}
	.fm-campo.com-erro {
		border-color: var(--vermelho);
	}
	.adicionar {
		height: 52px;
		min-width: 140px;
	}
	.sob-form {
		display: flex;
		flex-direction: column;
		gap: 6px;
		min-height: 24px;
	}
	.erros {
		margin: 0;
		padding: 0;
		list-style: none;
	}
	.info {
		margin: 0;
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		font-size: 16px;
		color: var(--tinta-2);
	}
	.info--ja {
		display: block;
		color: var(--aviso-tx);
	}
	:global(.ins-pill.neutro) {
		color: #4f5468;
		background: #efebe1;
		border-color: #d9d2bf;
	}
	:global(.ins-pill.neutro::before) {
		background: #9a937f;
	}

	/* ─── Lista ─── */
	.lista {
		overflow: hidden;
	}
	.lista-topo {
		display: flex;
		align-items: center;
		gap: 14px;
		flex-wrap: wrap;
		padding: 20px 26px;
		border-bottom: 1px solid var(--linha);
	}
	.lista-topo .fm-h2 {
		font-size: 26px;
	}
	.contagem {
		font-size: 15px;
		color: var(--tinta-3);
	}
	.filtros {
		margin-left: auto;
		display: flex;
		gap: 10px;
	}
	.busca {
		width: 280px;
		height: 44px;
	}
	.filtro {
		width: 220px;
		height: 44px;
	}
	.tabela {
		width: 100%;
		border-collapse: collapse;
		font-size: 16px;
	}
	.tabela th {
		padding: 10px 16px;
		text-align: left;
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--ouro-texto);
		background: var(--cabecalho-tabela);
		border-bottom: 1px solid var(--linha);
	}
	.tabela td {
		padding: 10px 16px;
		border-bottom: 1px solid var(--linha);
		vertical-align: middle;
	}
	.tabela tbody tr:hover {
		background: #fbf8ef;
	}
	.nome {
		font-weight: 600;
		color: var(--azul);
	}
	.nome a {
		color: inherit;
	}
	.cpf {
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
	}
	.quem {
		font-size: 15px;
		color: var(--tinta-3);
		white-space: nowrap;
	}
	.acoes {
		text-align: right;
	}
	.acoes .fm-btn {
		height: 36px;
	}
	.vazio {
		margin: 0;
		padding: 36px 26px;
		font-size: 17px;
		color: var(--tinta-3);
	}
	.mais {
		display: flex;
		justify-content: center;
		padding: 16px;
	}

	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 18px 24px;
		font-size: 17px;
		color: var(--tinta-3);
	}
	.centro {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 12px;
		padding: 64px 24px;
	}
	.centro-titulo {
		margin: 0;
		font: 600 30px 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.centro-texto {
		margin: 0;
		color: var(--tinta-3);
	}
	.erro-icone {
		width: 48px;
		height: 48px;
		display: grid;
		place-items: center;
		font: 700 24px 'Source Sans 3', sans-serif;
		color: #fffdf8;
		background: var(--vermelho);
		border-radius: 50%;
	}
	.toast-erro {
		background: #9b2c2c;
	}
</style>
