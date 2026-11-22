<script lang="ts">
	/**
	 * Modelo por categoria (permissão "Setores de Servos"): grade setores ×
	 * categorias com as vagas que cada categoria recebe quando um acampamento é
	 * criado (ou quando a categoria dele muda). Clique na célula para mudar;
	 * vazio (ou 0) tira o setor da categoria.
	 */
	import { onDestroy, onMount, tick } from 'svelte';
	import { apiFetch } from '$lib/api';
	import Janela from '$lib/components/painel/Janela.svelte';
	import { CATEGORIAS_DE_ACAMPAMENTO, plural } from '$lib/equipes';
	import {
		criarSetor,
		editarSetor,
		excluirSetor,
		lerModelo,
		ligarNaCategoria,
		mudarVagasNaCategoria,
		tirarDaCategoria,
		type Resultado,
		type SetorDoModelo
	} from '$lib/services/equipeService';

	type Categoria = { id: number; name: string };

	let setores: SetorDoModelo[] = $state([]);
	let categorias: Categoria[] = $state([]);
	let estado: 'carregando' | 'erro' | 'pronto' = $state('carregando');

	async function carregar(mostrar = true) {
		if (mostrar) estado = 'carregando';
		try {
			const [m, c] = await Promise.all([lerModelo(), apiFetch('/categories').then((r) => r.json())]);
			if (!m.ok) throw new Error();
			setores = m.data;
			const ordem = (n: string) => {
				const i = CATEGORIAS_DE_ACAMPAMENTO.indexOf(n);
				return i < 0 ? 99 : i;
			};
			categorias = (c.data ?? [])
				.filter((x: { type: string }) => x.type === 'Acampamento')
				.sort((a: Categoria, b: Categoria) => ordem(a.name) - ordem(b.name));
			estado = 'pronto';
		} catch {
			if (mostrar) estado = 'erro';
		}
	}
	onMount(() => carregar());

	const grupos = $derived(
		[
			{ nome: 'Internos', lista: setores.filter((s) => s.place !== 'Externo') },
			{ nome: 'Externos', lista: setores.filter((s) => s.place === 'Externo') }
		].filter((g) => g.lista.length > 0)
	);
	const vinculo = (s: SetorDoModelo, c: Categoria) => s.categories.find((v) => v.category_id === c.id) ?? null;
	const totais = $derived(
		categorias.map((c) => {
			const v = setores.map((s) => vinculo(s, c)).filter((x) => x !== null);
			return { total: v.reduce((t, x) => t + (x?.vacancies ?? 0), 0), setores: v.length };
		})
	);

	// ─── Aviso ────────────────────────────────────────────────────────────

	let aviso = $state<{ texto: string; erro: boolean } | null>(null);
	let avisoTimer: ReturnType<typeof setTimeout>;
	function mostrarAviso(texto: string, erro = false) {
		aviso = { texto, erro };
		clearTimeout(avisoTimer);
		avisoTimer = setTimeout(() => (aviso = null), 7000);
	}
	onDestroy(() => clearTimeout(avisoTimer));

	// ─── Célula ───────────────────────────────────────────────────────────

	let celula: string | null = $state(null);
	let valorCelula: string | number | null = $state('');
	let salvandoCelula = $state(false);
	let grade: HTMLElement | undefined = $state();

	async function editarCelula(s: SetorDoModelo, c: Categoria) {
		celula = `${s.id}:${c.id}`;
		valorCelula = String(vinculo(s, c)?.vacancies ?? '');
		await tick();
		grade?.querySelector<HTMLInputElement>(`[data-celula="${celula}"]`)?.select();
	}

	async function fecharCelula(focarBotao = true) {
		const era = celula;
		celula = null;
		if (focarBotao && era) {
			await tick();
			grade?.querySelector<HTMLElement>(`[data-botao="${era}"]`)?.focus();
		}
	}

	async function salvarCelula(s: SetorDoModelo, c: Categoria) {
		if (salvandoCelula) return;
		const atual = vinculo(s, c);
		const texto = String(valorCelula ?? '').trim();
		const n = texto === '' ? 0 : Math.floor(Number(texto));
		if (Number.isNaN(n) || n < 0) {
			mostrarAviso('Use um número de vagas (0 ou mais).', true);
			return;
		}
		let acao: (() => Promise<Resultado>) | null = null;
		if (n === 0 && atual) acao = () => tirarDaCategoria(atual.id);
		else if (n > 0 && !atual) acao = () => ligarNaCategoria(s.id, c.id, n);
		else if (n > 0 && atual && atual.vacancies !== n) acao = () => mudarVagasNaCategoria(atual.id, n);
		if (!acao) {
			fecharCelula();
			return;
		}
		salvandoCelula = true;
		const r = await acao();
		salvandoCelula = false;
		if (!r.ok) {
			mostrarAviso('Não foi possível mudar as vagas. Nada mudou: tente de novo.', true);
			return;
		}
		await carregar(false);
		fecharCelula();
	}

	// ─── Janelas ──────────────────────────────────────────────────────────

	type TipoJanela = { tipo: 'novo' } | { tipo: 'editar'; setor: SetorDoModelo } | { tipo: 'excluir'; setor: SetorDoModelo };
	let janela = $state<TipoJanela | null>(null);
	let enviando = $state(false);
	let erroJanela = $state('');
	let erroNome = $state('');
	let entendo = $state(false);
	let nome = $state('');
	let local = $state<'Interno' | 'Externo'>('Interno');
	let vagasNovas: Record<number, string | number | null> = $state({});

	function abrir(j: TipoJanela) {
		janela = j;
		enviando = false;
		erroJanela = '';
		erroNome = '';
		entendo = false;
		nome = j.tipo === 'novo' ? '' : j.setor.name;
		local = j.tipo === 'novo' ? 'Interno' : j.setor.place === 'Externo' ? 'Externo' : 'Interno';
		vagasNovas = {};
	}
	function fechar() {
		if (!enviando) janela = null;
	}

	async function executar(acao: () => Promise<Resultado>, acaoTexto: string, sucesso: string) {
		enviando = true;
		erroJanela = '';
		erroNome = '';
		const r = await acao();
		enviando = false;
		if (!r.ok) {
			if (r.codigo === 'nome') erroNome = r.error;
			else erroJanela = `Não foi possível ${acaoTexto}. Nada mudou: tente de novo.`;
			return;
		}
		janela = null;
		await carregar(false);
		mostrarAviso(sucesso);
	}

	function salvarNovo() {
		if (!nome.trim()) {
			erroNome = 'Informe o nome do setor.';
			return;
		}
		const vagas: Record<number, number> = {};
		for (const [id, v] of Object.entries(vagasNovas)) {
			const n = Math.floor(Number(v));
			if (String(v ?? '').trim() !== '' && n > 0) vagas[Number(id)] = n;
		}
		executar(() => criarSetor(nome.trim(), local, vagas), 'criar o setor', 'Setor criado.');
	}

	function salvarEdicao(s: SetorDoModelo) {
		if (!nome.trim()) {
			erroNome = 'Informe o nome do setor.';
			return;
		}
		executar(() => editarSetor(s.id, nome.trim(), local), 'salvar o setor', 'Setor salvo.');
	}
</script>

{#if estado === 'carregando'}
	<div class="carregando" aria-busy="true"><span class="fm-spinner"></span>Carregando o modelo...</div>
{:else if estado === 'erro'}
	<section class="centro" role="alert">
		<span class="icone-erro" aria-hidden="true">!</span>
		<p class="centro-titulo">Não foi possível carregar o modelo.</p>
		<p class="centro-texto">Verifique sua conexão com a internet e tente novamente.</p>
		<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={() => carregar()}>Tentar novamente</button>
	</section>
{:else}
	<div class="topo">
		<div class="topo-textos">
			<h2 class="fm-h2">Modelo por categoria</h2>
			<p class="ins-sub">
				Os setores e as vagas que cada categoria recebe <strong>quando um acampamento é criado</strong>, ou quando a categoria de um acampamento
				muda. Mudar o modelo não mexe nos acampamentos que já existem.
			</p>
		</div>
		<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={() => abrir({ tipo: 'novo' })}>+ Novo setor</button>
	</div>

	<div bind:this={grade} class="grade" role="table" aria-label="Vagas por setor e categoria" style="--cats:{categorias.length}">
		<div role="row" class="linha cabecalho">
			<span role="columnheader" class="h esquerda">Setor</span>
			{#each categorias as c (c.id)}<span role="columnheader" class="h">{c.name}</span>{/each}
			<span role="columnheader" class="h"><span class="ins-sr">Ações</span></span>
		</div>
		{#each grupos as g (g.nome)}
			<div role="row" class="faixa-grupo"><span role="cell" class="eq-rot">Setores {g.nome}</span></div>
			{#each g.lista as s (s.id)}
				<div role="row" class="linha">
					<span role="cell" class="setor">
						<strong>{s.name}</strong>
						<span>{s.place || 'Interno'} · em {plural(s.categories.length, 'categoria', 'categorias')}</span>
					</span>
					{#each categorias as c (c.id)}
						{@const v = vinculo(s, c)}
						{@const k = `${s.id}:${c.id}`}
						<span role="cell" class="cel">
							{#if celula === k}
								<span class="editando">
									<input
										type="number"
										min="0"
										class="fm-campo"
										data-celula={k}
										aria-label="{s.name} no {c.name}: vagas"
										bind:value={valorCelula}
										disabled={salvandoCelula}
										onkeydown={(e) => {
											if (e.key === 'Enter') salvarCelula(s, c);
											if (e.key === 'Escape') {
												e.stopPropagation();
												fecharCelula();
											}
										}}
									/>
									<button type="button" class="ok" aria-label="Salvar" disabled={salvandoCelula} onclick={() => salvarCelula(s, c)}>✓</button>
								</span>
							{:else}
								<button
									type="button"
									class="celula"
									class:vazia={!v}
									data-botao={k}
									aria-label="{s.name} no {c.name}: {v ? plural(v.vacancies, 'vaga', 'vagas') : 'não existe'}. Mudar"
									onclick={() => editarCelula(s, c)}>{v ? v.vacancies : '—'}</button
								>
							{/if}
						</span>
					{/each}
					<span role="cell" class="acoes">
						<button type="button" class="ins-acao2" onclick={() => abrir({ tipo: 'editar', setor: s })}>Editar</button>
						<button type="button" class="ins-acao2 perigo" onclick={() => abrir({ tipo: 'excluir', setor: s })}>Excluir</button>
					</span>
				</div>
			{/each}
		{:else}
			<div role="row" class="faixa-grupo"><span role="cell" class="ins-ajuda">Nenhum setor cadastrado ainda. Use “+ Novo setor”.</span></div>
		{/each}
		<div role="row" class="linha total">
			<span role="cell" class="eq-rot setor">Total</span>
			{#each totais as t, i (categorias[i].id)}
				<span role="cell" class="total-cel"><strong>{t.total}</strong><span>{plural(t.setores, 'setor', 'setores')}</span></span>
			{/each}
			<span role="cell"></span>
		</div>
	</div>
	<p class="ins-ajuda">
		Clique numa célula para mudar as vagas. “—” quer dizer que o setor não existe naquela categoria; deixe a célula vazia para tirar o setor
		da categoria.
	</p>
{/if}

{#if janela?.tipo === 'novo' || janela?.tipo === 'editar'}
	{@const editando = janela.tipo === 'editar' ? janela.setor : null}
	<Janela idTitulo="t-setor" largura={editando ? 560 : 660} faixa="linear-gradient(90deg,#1B2F5E,#B69E55)" travada={enviando} onfechar={fechar}>
		<h2 id="t-setor" class="fm-janela-titulo">{editando ? 'Editar o setor' : 'Novo setor'}</h2>
		<div class="duas">
			<div class="eq-campo-bloco">
				<label for="setor-nome">Nome <span class="eq-obrig">*</span></label>
				<input
					id="setor-nome"
					class="fm-campo"
					placeholder="Ex.: Lavanderia"
					bind:value={nome}
					aria-invalid={!!erroNome}
					aria-describedby={erroNome ? 'erro-nome' : undefined}
				/>
				{#if erroNome}<p id="erro-nome" class="ins-erro">{erroNome}</p>{/if}
			</div>
			<fieldset class="eq-campo-bloco">
				<legend class="eq-legenda">Local</legend>
				<div class="eq-papeis" role="radiogroup" aria-label="Local">
					{#each ['Interno', 'Externo'] as const as l (l)}
						<button type="button" role="radio" aria-checked={local === l} class="eq-radio local" onclick={() => (local = l)}>
							<span class="eq-bola" aria-hidden="true"></span>{l}
						</button>
					{/each}
				</div>
			</fieldset>
		</div>
		{#if editando}
			<p class="ins-ajuda">O novo nome aparece também nos acampamentos que já têm o setor.</p>
		{:else}
			<p class="ins-ajuda">
				Interno: com os campistas (liturgia, música…). Externo: apoio (cozinha, limpeza…). Serve para agrupar os setores na tela.
			</p>
			<div class="eq-campo-bloco">
				<span class="eq-legenda sem-margem">Vagas em cada categoria <span class="eq-opc">deixe vazio onde o setor não existe</span></span>
				<div class="vagas-novas" style="--cats:{categorias.length}">
					{#each categorias as c (c.id)}
						<label class="vaga-nova">
							{c.name}
							<input type="number" min="0" class="fm-campo" bind:value={vagasNovas[c.id]} />
						</label>
					{/each}
				</div>
			</div>
		{/if}
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fechar} disabled={enviando}>Cancelar</button>
			<button
				type="button"
				class="fm-btn fm-btn--ouro fm-btn--grande"
				disabled={enviando}
				onclick={() => (editando ? salvarEdicao(editando) : salvarNovo())}
			>
				{enviando ? 'Salvando...' : editando ? 'Salvar' : 'Criar setor'}
			</button>
		</div>
	</Janela>
{:else if janela?.tipo === 'excluir'}
	{@const s = janela.setor}
	<Janela idTitulo="t-excluir" largura={600} faixa="#9B2C2C" alerta travada={enviando} onfechar={fechar}>
		<h2 id="t-excluir" class="fm-janela-titulo">Excluir o setor {s.name} do modelo?</h2>
		{#if s.categories.length}
			<p class="ins-aviso verm">
				<span
					>Ele está em <strong
						>{plural(s.categories.length, 'categoria', 'categorias')}: {s.categories.map((v) => v.category_name).join(', ')}</strong
					>. Sai de {s.categories.length === 1 ? 'dela' : 'todas'}.</span
				>
			</p>
		{/if}
		<p class="eq-texto eq-texto--menor">
			Os acampamentos que já existem não mudam: quem tem esse setor continua com ele e com a equipe.
		</p>
		<label class="ins-check"><input type="checkbox" bind:checked={entendo} disabled={enviando} />Entendo que não dá para desfazer</label>
		{#if erroJanela}<p class="ins-aviso verm" role="alert"><span>{erroJanela}</span></p>{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={fechar} disabled={enviando}>Cancelar</button>
			<button
				type="button"
				class="fm-btn fm-btn--perigo-cheio fm-btn--grande"
				disabled={!entendo || enviando}
				onclick={() => executar(() => excluirSetor(s.id), 'excluir o setor', `O setor ${s.name} foi excluído do modelo.`)}
			>
				{enviando ? 'Excluindo...' : 'Excluir setor'}
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
	.topo {
		display: flex;
		justify-content: space-between;
		align-items: flex-end;
		gap: 16px;
		flex-wrap: wrap;
	}
	.topo-textos {
		display: flex;
		flex-direction: column;
		gap: 4px;
		max-width: 760px;
	}
	.grade {
		display: flex;
		flex-direction: column;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		overflow: hidden;
	}
	.linha {
		display: grid;
		grid-template-columns: minmax(200px, 1.6fr) repeat(var(--cats), minmax(0, 1fr)) 150px;
		align-items: center;
		border-bottom: 1px solid var(--linha);
	}
	.cabecalho {
		background: var(--cabecalho-tabela);
		border-bottom: 1px solid var(--borda-suave);
	}
	.h {
		padding: 12px 8px;
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--ouro-texto);
		text-align: center;
	}
	.h.esquerda {
		padding-left: 16px;
		text-align: left;
	}
	.faixa-grupo {
		padding: 8px 16px;
		background: var(--marfim);
		border-bottom: 1px solid var(--borda-suave);
	}
	.setor {
		display: flex;
		flex-direction: column;
		gap: 1px;
		padding: 10px 16px;
	}
	.setor strong {
		font-size: 16px;
		color: var(--tinta);
	}
	.setor span {
		font-size: 13px;
		color: var(--tinta-3);
	}
	.cel {
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 6px;
	}
	.celula {
		min-width: 56px;
		height: 40px;
		padding: 0 10px;
		border: 1px solid transparent;
		border-radius: 8px;
		font: 700 17px 'Source Sans 3', sans-serif;
		color: var(--tinta);
		background: var(--cabecalho-tabela);
		cursor: pointer;
		font-variant-numeric: tabular-nums;
	}
	.celula.vazia {
		font-weight: 400;
		color: #a9a08a;
		background: transparent;
	}
	.celula:hover {
		border-color: var(--ouro);
		background: #ffffff;
	}
	.editando {
		display: flex;
		align-items: center;
		gap: 4px;
	}
	.editando .fm-campo {
		width: 64px;
		height: 40px;
		padding: 0 8px;
		text-align: center;
		font-size: 16px;
	}
	.ok {
		width: 32px;
		height: 32px;
		border: none;
		border-radius: 8px;
		background: var(--azul);
		color: var(--pergaminho);
		cursor: pointer;
	}
	.acoes {
		display: flex;
		align-items: center;
		justify-content: flex-end;
		gap: 2px;
		padding-right: 10px;
	}
	.acoes .ins-acao2 {
		height: 32px;
		padding: 0 8px;
		font-size: 14px;
	}
	.total {
		background: var(--cabecalho-tabela);
		border-bottom: none;
	}
	.total .setor {
		padding: 12px 16px;
	}
	.total-cel {
		display: flex;
		flex-direction: column;
		align-items: center;
		padding: 10px 4px;
	}
	.total-cel strong {
		font: 600 24px/1 'Cormorant Garamond', serif;
		color: var(--azul);
	}
	.total-cel span {
		font-size: 12px;
		color: var(--tinta-3);
	}
	.duas {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 20px 24px;
	}
	.local {
		min-width: 0;
	}
	.sem-margem {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 0;
	}
	.vagas-novas {
		display: grid;
		grid-template-columns: repeat(var(--cats), minmax(0, 1fr));
		gap: 8px;
	}
	.vaga-nova {
		display: flex;
		flex-direction: column;
		gap: 4px;
		font-size: 14px;
		font-weight: 600;
		color: var(--tinta-2);
	}
	.vaga-nova .fm-campo {
		height: 44px;
		padding: 0 8px;
		text-align: center;
	}
	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 18px 0;
		font-size: 17px;
		color: var(--tinta-3);
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
