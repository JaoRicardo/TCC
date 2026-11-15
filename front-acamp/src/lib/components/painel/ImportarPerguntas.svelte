<script lang="ts" module>
	import type { AlvoFormulario } from '$lib/formularios';

	/** Um formulário de onde dá para copiar perguntas (de acampamento ou de evento). */
	export type FonteDeImportacao = { alvo: AlvoFormulario; titulo: string; perguntas: number };
</script>

<script lang="ts">
	/**
	 * Painel lateral "Importar perguntas": escolhe o formulário de origem, marca
	 * seções ou perguntas soltas e copia para o formulário aberto. Marcar uma
	 * condicional marca a pergunta de que ela depende; desmarcar a pai desmarca
	 * as condicionais dela (o servidor também traz a pai junto, de todo jeito).
	 */
	import { onMount } from 'svelte';
	import Janela from './Janela.svelte';
	import { TIPOS_PERGUNTA, TIPOS_COM_OPCOES, getPerguntas, importarPerguntas, type Pergunta } from '$lib/services/perguntaService';
	import { enderecoDoEditor } from '$lib/formularios';

	let {
		destino,
		titulo,
		fontes,
		ativaPrazo = null,
		onfechar,
		onimportado
	}: {
		destino: AlvoFormulario;
		/** Formulário de destino, ex.: "Juvenil · Servo". */
		titulo: string;
		/** Formulários com perguntas, menos este. */
		fontes: FonteDeImportacao[];
		/** Formulário vazio e em uso: o aviso de que importar ativa o prazo. */
		ativaPrazo?: string | null;
		onfechar: () => void;
		onimportado: (copiadas: Pergunta[], deQual: string) => void;
	} = $props();

	type Grupo = { id: number; titulo: string; itens: { p: Pergunta; filha: boolean }[] };

	const chave = (f: FonteDeImportacao) => enderecoDoEditor(f.alvo);
	const nomeDe = (f: FonteDeImportacao) => f.titulo;

	let fonte = $state('');
	let perguntas: Pergunta[] = $state([]);
	let marcadas: Set<number> = $state(new Set());
	let abertas: number[] = $state([]);
	let carregando = $state(false);
	let erro = $state('');
	let importando = $state(false);

	let fonteAtual = $derived(fontes.find((f) => chave(f) === fonte));

	async function escolher(k: string) {
		fonte = k;
		const f = fontes.find((x) => chave(x) === k);
		if (!f) return;
		carregando = true;
		erro = '';
		perguntas = [];
		marcadas = new Set();
		abertas = [];
		try {
			const res = await getPerguntas(f.alvo);
			if (fonte !== k) return;
			if (res.error || !res.data) {
				erro = 'Não foi possível carregar as perguntas deste formulário.';
				return;
			}
			perguntas = res.data;
			marcadas = new Set(res.data.map((p) => p.id));
		} catch {
			if (fonte === k) erro = 'Não foi possível carregar as perguntas. Verifique sua conexão.';
		} finally {
			if (fonte === k) carregando = false;
		}
	}

	// Começa pelo outro formulário da mesma categoria (Juvenil · Servo ← Juvenil · Campista).
	onMount(() => {
		const mesmaCategoria = (f: FonteDeImportacao) =>
			destino.tipo === 'acampamento' && f.alvo.tipo === 'acampamento' && f.alvo.categoryId === destino.categoryId;
		const inicial = fontes.find(mesmaCategoria) ?? fontes[0];
		if (inicial) escolher(chave(inicial));
	});

	// ─── Relações e grupos ───────────────────────────────────────────────
	let paiDe = $derived.by(() => {
		const dono = new Map<number, Pergunta>();
		for (const p of perguntas) for (const o of p.options) dono.set(o.id, p);
		const mapa = new Map<number, Pergunta>();
		for (const p of perguntas) {
			const pai = p.depends_on_option_id !== null ? dono.get(p.depends_on_option_id) : undefined;
			if (pai) mapa.set(p.id, pai);
		}
		return mapa;
	});

	const filhasDe = (p: Pergunta) => perguntas.filter((x) => paiDe.get(x.id)?.id === p.id);

	/** Por seção, na ordem do formulário: cada pergunta seguida das condicionais dela. */
	let grupos = $derived.by(() => {
		const lista: Grupo[] = [];
		for (const p of perguntas) {
			if (!lista.some((g) => g.id === p.section.id)) lista.push({ id: p.section.id, titulo: p.section.titulo, itens: [] });
		}
		for (const g of lista) {
			const ps = perguntas.filter((p) => p.section.id === g.id);
			const filhaAqui = (p: Pergunta) => paiDe.get(p.id)?.section.id === g.id;
			for (const topo of ps.filter((p) => !filhaAqui(p))) {
				g.itens.push({ p: topo, filha: false });
				for (const c of ps) if (filhaAqui(c) && paiDe.get(c.id)?.id === topo.id) g.itens.push({ p: c, filha: true });
			}
		}
		return lista;
	});

	// ─── Marcar ──────────────────────────────────────────────────────────
	function alternar(p: Pergunta) {
		const s = new Set(marcadas);
		if (s.has(p.id)) {
			s.delete(p.id);
			for (const f of filhasDe(p)) s.delete(f.id);
		} else {
			s.add(p.id);
			const pai = paiDe.get(p.id);
			if (pai) s.add(pai.id);
		}
		marcadas = s;
	}

	function alternarSecao(g: Grupo) {
		const s = new Set(marcadas);
		if (g.itens.every((it) => s.has(it.p.id))) {
			for (const it of g.itens) {
				s.delete(it.p.id);
				for (const f of filhasDe(it.p)) s.delete(f.id);
			}
		} else {
			for (const it of g.itens) {
				s.add(it.p.id);
				const pai = paiDe.get(it.p.id);
				if (pai) s.add(pai.id);
			}
		}
		marcadas = s;
	}

	function marcarTodas(marcar: boolean) {
		marcadas = new Set(marcar ? perguntas.map((p) => p.id) : []);
	}

	const marcadasNa = (g: Grupo) => g.itens.filter((it) => marcadas.has(it.p.id)).length;

	function estadoDa(g: Grupo): 'true' | 'false' | 'mixed' {
		const n = marcadasNa(g);
		return n === 0 ? 'false' : n === g.itens.length ? 'true' : 'mixed';
	}

	function alternarAberta(id: number) {
		abertas = abertas.includes(id) ? abertas.filter((x) => x !== id) : [...abertas, id];
	}

	function linhaDois(p: Pergunta): string {
		const nome = TIPOS_PERGUNTA.find((t) => t.value === p.type)?.label ?? p.type;
		return TIPOS_COM_OPCOES.includes(p.type) && p.options.length ? `${nome} · ${p.options.map((o) => o.text).join(' · ')}` : nome;
	}

	let total = $derived(marcadas.size);

	async function importar() {
		if (!fonteAtual || total === 0) return;
		importando = true;
		erro = '';
		try {
			const res = await importarPerguntas(destino, [...marcadas]);
			if (res.error || !res.data) {
				erro = res.error || 'Não foi possível importar as perguntas.';
				return;
			}
			onimportado(res.data, nomeDe(fonteAtual));
		} catch {
			erro = 'Não foi possível importar as perguntas. Verifique sua conexão e tente de novo.';
		} finally {
			importando = false;
		}
	}
</script>

<Janela idTitulo="t-importar" lateral largura={600} travada={importando} {onfechar}>
	<div class="cabeca">
		<h2 id="t-importar" class="fm-janela-titulo">Importar perguntas</h2>
		<p class="para">para <strong>{titulo}</strong></p>
	</div>

	<div class="miolo">
		<fieldset class="bloco">
			<legend class="fm-rotulo legenda">1 · De qual formulário?</legend>
			<div class="fontes" role="radiogroup" aria-label="Formulário de origem">
				{#each fontes as f (chave(f))}
					{@const sel = fonte === chave(f)}
					<button type="button" role="radio" aria-checked={sel} class="fonte" class:fonte--sel={sel} onclick={() => escolher(chave(f))}>
						<span class="radio" class:radio--sel={sel}>{#if sel}<span class="radio-ponto"></span>{/if}</span>
						<span class="fonte-titulo">{nomeDe(f)}</span>
						<span class="fonte-n">{f.perguntas} {f.perguntas === 1 ? 'pergunta' : 'perguntas'}</span>
					</button>
				{/each}
			</div>
		</fieldset>

		<div class="bloco">
			<div class="bloco-topo">
				<span class="fm-rotulo">2 · O que importar?</span>
				{#if perguntas.length > 0 && !carregando}
					<div class="todas">
						<button type="button" class="ver" disabled={total === perguntas.length} onclick={() => marcarTodas(true)}>
							Marcar todas
						</button>
						<button type="button" class="ver" disabled={total === 0} onclick={() => marcarTodas(false)}>
							Desmarcar todas
						</button>
					</div>
				{/if}
			</div>
			{#if carregando}
				<p class="fm-sub carregando" role="status"><span class="fm-spinner fm-spinner--pequeno"></span>Carregando as perguntas...</p>
			{:else if erro && perguntas.length === 0}
				<p class="fm-erro-linha" role="alert">{erro}</p>
				{#if fonte}<button type="button" class="fm-link" onclick={() => escolher(fonte)}>Tentar de novo</button>{/if}
			{:else}
				{#each grupos as g (g.id)}
					{@const estado = estadoDa(g)}
					{@const aberta = abertas.includes(g.id)}
					<div class="secao">
						<div class="secao-linha">
							<button
								type="button"
								role="checkbox"
								aria-checked={estado}
								aria-label="Importar a seção {g.titulo}"
								class="caixa"
								class:caixa--on={estado !== 'false'}
								onclick={() => alternarSecao(g)}
							>
								{estado === 'true' ? '✓' : estado === 'mixed' ? '–' : ''}
							</button>
							<span class="secao-nome">{g.titulo}</span>
							<span class="secao-conta">{marcadasNa(g)} de {g.itens.length}</span>
							<button type="button" class="ver" aria-expanded={aberta} onclick={() => alternarAberta(g.id)}>
								{aberta ? 'Esconder' : 'Ver perguntas'}
							</button>
						</div>
						{#if aberta}
							<div class="itens">
								{#each g.itens as it (it.p.id)}
									{@const on = marcadas.has(it.p.id)}
									<button
										type="button"
										role="checkbox"
										aria-checked={on}
										class="item"
										class:item--filha={it.filha}
										onclick={() => alternar(it.p)}
									>
										<span class="caixa caixa--pequena" class:caixa--on={on} aria-hidden="true">{on ? '✓' : ''}</span>
										<span class="item-textos">
											<span class="item-texto">{it.filha ? '↳ ' : ''}{it.p.text}</span>
											<span class="item-linha2">{linhaDois(it.p)}</span>
										</span>
									</button>
								{/each}
							</div>
						{/if}
					</div>
				{/each}
			{/if}
		</div>

		<p class="nota">
			As perguntas entram no fim de cada seção, como cópias: mudar uma depois não altera a outra. Uma pergunta condicional vem
			sempre junto com a pergunta de que depende.
		</p>

		{#if ativaPrazo}
			<div class="fm-aviso fm-aviso--ambar fm-aviso--pequeno">
				<span>{ativaPrazo}</span>
			</div>
		{/if}

		{#if erro && perguntas.length > 0}
			<p class="fm-erro-linha" role="alert">{erro}</p>
		{/if}
	</div>

	<div class="rodape">
		<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" disabled={importando} onclick={onfechar}>Cancelar</button>
		<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" disabled={total === 0 || importando || carregando} onclick={importar}>
			{#if importando}
				<span class="fm-spinner fm-spinner--pequeno"></span>Importando...
			{:else}
				Importar {total} {total === 1 ? 'pergunta' : 'perguntas'}{ativaPrazo
					? destino.tipo === 'evento'
						? ' e ativar o formulário'
						: ' e ativar o prazo'
					: ''}
			{/if}
		</button>
	</div>
</Janela>

<style>
	.cabeca {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 22px 28px 16px;
		border-bottom: 1px solid var(--borda-suave);
	}

	.para {
		margin: 0;
		font-size: 17px;
		color: var(--tinta-3);
	}

	.para strong {
		color: var(--tinta);
	}

	.miolo {
		flex: 1;
		overflow: auto;
		display: flex;
		flex-direction: column;
		gap: 22px;
		padding: 20px 28px;
	}

	.bloco {
		display: flex;
		flex-direction: column;
		gap: 10px;
		margin: 0;
		padding: 0;
		border: none;
	}

	.bloco-topo {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}

	.todas {
		display: flex;
		gap: 4px;
	}

	.ver:disabled {
		color: var(--tinta-3);
		text-decoration: none;
	}

	.legenda {
		padding: 0 0 10px;
	}

	.fontes {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.fonte {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: 56px;
		padding: 8px 14px;
		background: #ffffff;
		border: 1.5px solid var(--borda-suave);
		border-radius: 10px;
		text-align: left;
		cursor: pointer;
	}

	.fonte:hover {
		border-color: var(--ouro);
	}

	.fonte--sel {
		background: var(--cabecalho-tabela);
		border-color: var(--azul);
	}

	.radio {
		flex: 0 0 22px;
		height: 22px;
		box-sizing: border-box;
		border-radius: 50%;
		border: 2px solid var(--campo-borda);
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.radio--sel {
		border-color: var(--azul);
	}

	.radio-ponto {
		width: 10px;
		height: 10px;
		border-radius: 50%;
		background: var(--azul);
	}

	.fonte-titulo {
		flex: 1;
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta);
	}

	.fonte-n {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.carregando {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.secao {
		background: #ffffff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		overflow: hidden;
	}

	.secao-linha {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 8px 10px 8px 12px;
		background: var(--cabecalho-tabela);
	}

	.caixa {
		flex: 0 0 26px;
		height: 26px;
		box-sizing: border-box;
		padding: 0;
		border-radius: 6px;
		border: 2px solid var(--campo-borda);
		background: #ffffff;
		color: var(--pergaminho);
		font-size: 17px;
		font-weight: 700;
		line-height: 1;
		display: flex;
		align-items: center;
		justify-content: center;
		cursor: pointer;
	}

	.caixa--on {
		background: var(--azul);
		border-color: var(--azul);
	}

	.caixa--pequena {
		flex-basis: 22px;
		height: 22px;
		font-size: 15px;
		cursor: inherit;
	}

	.secao-nome {
		flex: 1;
		font: 600 22px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.secao-conta {
		font-size: 14px;
		font-weight: 700;
		color: var(--icone-tipo-tx);
		background: var(--icone-tipo-bg);
		border-radius: 999px;
		padding: 2px 10px;
	}

	.ver {
		height: 40px;
		padding: 0 10px;
		border: none;
		border-radius: 8px;
		background: transparent;
		font-size: 15px;
		font-weight: 600;
		color: var(--azul);
		text-decoration: underline;
		cursor: pointer;
	}

	.itens {
		display: flex;
		flex-direction: column;
		padding: 6px;
	}

	.item {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 8px 10px;
		background: transparent;
		border: none;
		border-radius: 8px;
		text-align: left;
		cursor: pointer;
	}

	.item:hover {
		background: var(--cabecalho-tabela);
	}

	.item--filha {
		margin-left: 34px;
	}

	.item-textos {
		display: flex;
		flex-direction: column;
		gap: 1px;
	}

	.item-texto {
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
	}

	.item-linha2 {
		font-size: 14px;
		color: var(--tinta-3);
	}

	.nota {
		margin: 0;
		font-size: 15px;
		line-height: 1.45;
		color: var(--tinta-3);
	}

	.rodape {
		display: flex;
		justify-content: space-between;
		gap: 10px;
		padding: 16px 28px 22px;
		border-top: 1px solid var(--borda-suave);
		background: var(--pergaminho);
	}
</style>
