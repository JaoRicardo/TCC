<script lang="ts">
	/**
	 * Janela "Nova categoria" / "Editar {nome}" (handoff design_handoff_categorias,
	 * seção 9): três perguntas à esquerda e a prévia "Como fica" à direita. Os
	 * erros aparecem ao sair do campo ou ao tentar salvar e somem assim que o
	 * valor fica certo. O que o servidor recusaria (ciclo de pré-requisito, tipo
	 * de vaga com acampamento por acontecer) a tela já não deixa escolher.
	 */
	import { tick } from 'svelte';
	import Janela from '$lib/components/painel/Janela.svelte';
	import SeletorDegrau from './SeletorDegrau.svelte';
	import {
		comNoDegrau,
		depoisDe,
		escadaEmUso,
		faixaCurta,
		junta,
		normalizarNome,
		proximaQueAceitaCasados,
		textoPodeServirEm,
		textosDaCategoria,
		type CategoriaDaLista,
		type RegrasDaCategoria
	} from '$lib/categorias';
	import { criarCategoria, editarCategoria, type DadosDaCategoria } from '$lib/services/categoriaService';
	import { dataDoAcampamento, faseDoAcampamento } from './datas';
	import { TEXTO_DA_FASE } from '$lib/faseAtividade';

	let {
		categorias,
		editando,
		onfechar,
		onsalva
	}: {
		categorias: CategoriaDaLista[];
		editando: CategoriaDaLista | null;
		onfechar: () => void;
		onsalva: (c: RegrasDaCategoria, nova: boolean) => void;
	} = $props();

	// O formulário começa com o que a categoria tem hoje (ou vazio): a janela
	// abre de novo a cada edição, então guardar o valor inicial é o que se quer.
	// svelte-ignore state_referenced_locally
	const ini = editando;
	// svelte-ignore state_referenced_locally
	const depoisInicial = ini && ini.naEscada ? depoisDe(ini, categorias) : null;

	let nome = $state(ini?.nome ?? '');
	let soCasais = $state(ini?.soCasais ?? false);
	let de = $state(ini?.idadeMinima == null ? '' : String(ini.idadeMinima));
	let ate = $state(ini?.idadeMaxima == null ? '' : String(ini.idadeMaxima));
	let aceitaCasados = $state(ini?.aceitaCasados ?? true);
	let requer: number | null = $state(ini?.requerCategoriaId ?? null);
	// Padrão de uma categoria nova: fora da escada, para não mudar as regras de ninguém sem escolher.
	let naEscada = $state(ini?.naEscada ?? false);
	let depois: number | null = $state(depoisInicial);
	let responsavel = $state(ini?.preenchidoPeloResponsavel ?? false);

	let tocou = $state({ nome: false, idade: false });
	let tentou = $state(false);
	let enviando = $state(false);
	let erroServidor: { campo: Record<string, string>; geral: string } = $state({ campo: {}, geral: '' });

	const idDaCategoria = ini?.id ?? -1;
	const travaVaga = (ini?.acampamentosPorAcontecer ?? 0) > 0;

	// ─── Validação (na hora) ──────────────────────────────────────────────

	const numero = (v: string) => (v.trim() === '' ? null : Number(v.trim()));
	const ruim = (v: string) => v.trim() !== '' && (!/^\d+$/.test(v.trim()) || Number(v.trim()) > 120);

	const erroNome = $derived.by(() => {
		if (!nome.trim()) return 'Informe o nome da categoria.';
		const n = normalizarNome(nome);
		if (categorias.some((c) => c.id !== idDaCategoria && normalizarNome(c.nome) === n)) return 'Já existe uma categoria com esse nome.';
		return erroServidor.campo.nome ?? '';
	});
	const erroIdade = $derived.by(() => {
		if (ruim(de) || ruim(ate)) return 'Use uma idade entre 0 e 120.';
		const a = numero(de);
		const b = numero(ate);
		if (a !== null && b !== null && b < a) return 'A idade máxima não pode ser menor que a mínima.';
		return erroServidor.campo.idadeMinima ?? erroServidor.campo.idadeMaxima ?? '';
	});
	const verNome = $derived(!!erroNome && (tentou || tocou.nome));
	const verIdade = $derived(!!erroIdade && (tentou || tocou.idade));

	// Um erro do servidor some quando a pessoa mexe no campo.
	function mudou(campo: 'nome' | 'idade') {
		const c = { ...erroServidor.campo };
		if (campo === 'nome') delete c.nome;
		else {
			delete c.idadeMinima;
			delete c.idadeMaxima;
		}
		erroServidor = { ...erroServidor, campo: c };
	}

	// ─── Opções ───────────────────────────────────────────────────────────

	const emUso = $derived(categorias.filter((c) => !c.arquivada));
	/** Os degraus em uso, sem esta, do mais novo ao mais velho. */
	const outrasNaEscada = $derived(escadaEmUso(categorias).filter((c) => c.id !== idDaCategoria));

	/** Pedir `alvo` fecharia um círculo (alvo já pede esta, direta ou indiretamente)? */
	function formaCiclo(alvo: number): boolean {
		if (idDaCategoria < 0) return false;
		const vistos = new Set<number>();
		let atual: number | null = alvo;
		while (atual !== null && !vistos.has(atual)) {
			if (atual === idDaCategoria) return true;
			vistos.add(atual);
			atual = categorias.find((c) => c.id === atual)?.requerCategoriaId ?? null;
		}
		return false;
	}

	const opcoesPre = $derived(
		[{ v: null as number | null, t: 'Não precisa', s: 'Qualquer pessoa pode', bloq: false }].concat(
			categorias
				.filter((c) => c.id !== idDaCategoria && (!c.arquivada || c.id === requer))
				.map((c) => {
					const bloq = formaCiclo(c.id);
					return {
						v: c.id,
						t: c.nome,
						s: bloq ? 'Já pede esta categoria' : `${c.naEscada ? 'Na escada' : 'Fora da escada'} · ${faixaCurta(c)}`,
						bloq
					};
				})
		)
	);
	const poucasPre = $derived(opcoesPre.length <= 9);
	const bloqueadasPre = $derived(opcoesPre.filter((o) => o.bloq).map((o) => o.t));

	let preAberto = $state(false);
	let buscaPre = $state('');
	let caixaPre: HTMLDivElement | undefined = $state();
	const prePorBusca = $derived(opcoesPre.filter((o) => !buscaPre.trim() || normalizarNome(o.t).includes(normalizarNome(buscaPre))));

	function escolherPre(o: { v: number | null; bloq: boolean }) {
		if (o.bloq) return;
		requer = o.v;
		preAberto = false;
	}

	// ─── Prévia ───────────────────────────────────────────────────────────

	const esta: RegrasDaCategoria = $derived({
		id: idDaCategoria,
		nome: nome.trim() || 'Nova categoria',
		posicao: ini?.posicao ?? 0,
		idadeMinima: ruim(de) ? null : numero(de),
		idadeMaxima: ruim(ate) ? null : numero(ate),
		naEscada,
		requerCategoriaId: requer,
		soCasais,
		aceitaCasados: soCasais || aceitaCasados,
		preenchidoPeloResponsavel: responsavel,
		arquivada: false
	});
	const comEla = $derived(comNoDegrau(categorias as RegrasDaCategoria[], esta, naEscada ? depois : null));
	const estaNaLista = $derived(comEla.find((c) => c.id === idDaCategoria) ?? esta);
	const textos = $derived(textosDaCategoria(estaNaLista, comEla));

	const mini = $derived.by(() => {
		const escada = escadaEmUso(comEla);
		const k = escada.findIndex((c) => c.id === idDaCategoria);
		const de0 = Math.max(0, k - 2);
		const ate0 = Math.min(escada.length, k + 3);
		return {
			abaixo: de0,
			acima: escada.length - ate0,
			degraus: escada.slice(de0, ate0).map((c, j) => ({
				id: c.id,
				nome: c.nome,
				altura: 14 + j * 14,
				eu: c.id === idDaCategoria,
				serve: de0 + j < k
			}))
		};
	});

	const proxima = $derived(proximaQueAceitaCasados(estaNaLista, comEla));
	const ajudaCasados = $derived(
		proxima
			? `Quem é casado recebe: “Campistas casados(as) ou em união estável devem se inscrever na categoria ${proxima.nome}, não no ${esta.nome}.”`
			: 'Quem é casado recebe uma mensagem dizendo que esta categoria não aceita casados.'
	);

	const mudaDegrau = $derived(!!ini && ini.naEscada && naEscada && depois !== depoisInicial);

	// ─── Salvar ───────────────────────────────────────────────────────────

	async function salvar() {
		if (enviando) return;
		erroServidor = { ...erroServidor, geral: '' };
		if (erroNome || erroIdade) {
			tentou = true;
			await tick();
			document.querySelector<HTMLElement>('.cat-janela [aria-invalid="true"]')?.focus();
			return;
		}
		const dados: DadosDaCategoria = {
			nome: nome.trim(),
			idadeMinima: numero(de),
			idadeMaxima: numero(ate),
			naEscada,
			requerCategoriaId: requer,
			soCasais,
			aceitaCasados: soCasais || aceitaCasados,
			preenchidoPeloResponsavel: responsavel,
			...(naEscada ? { depois } : {})
		};
		enviando = true;
		const r = ini ? await editarCategoria(ini.id, dados) : await criarCategoria(dados);
		enviando = false;
		if (r.ok) {
			onsalva(r.data, !ini);
			return;
		}
		const campo: Record<string, string> = {};
		const outros: string[] = [];
		for (const [k, v] of Object.entries(r.erros ?? {})) {
			if (k === 'nome' || k === 'idadeMinima' || k === 'idadeMaxima') campo[k] = v;
			else outros.push(v);
		}
		erroServidor = {
			campo,
			geral: outros.join(' ') || (Object.keys(campo).length ? '' : r.status === 0 || !r.error ? 'Não foi possível salvar. Verifique sua conexão com a internet e tente novamente.' : r.error)
		};
		if (Object.keys(campo).length) {
			tentou = true;
			await tick();
			document.querySelector<HTMLElement>('.cat-janela [aria-invalid="true"]')?.focus();
		}
	}

	const fechar = () => !enviando && onfechar();
</script>

{#snippet opcao(marcada: boolean, titulo: string, sub: string, escolher: () => void, bloqueada = false)}
	<button
		type="button"
		role="radio"
		class="cat-opcao"
		aria-checked={marcada}
		aria-disabled={bloqueada}
		onclick={() => !bloqueada && escolher()}
	>
		<span class="cat-bola" aria-hidden="true"></span>
		<span class="cat-op-txt"><span class="cat-op-t">{titulo}</span><span class="cat-op-s">{sub}</span></span>
	</button>
{/snippet}

<Janela idTitulo="t-cat-form" largura={1100} travada={enviando} onfechar={fechar}>
	<div class="cat-janela" style="display:flex;flex-direction:column;gap:20px">
		<h2 id="t-cat-form" class="fm-janela-titulo">{ini ? `Editar ${ini.nome}` : 'Nova categoria'}</h2>

		{#if ini && ini.porAcontecer.length > 0}
			<div class="ins-aviso amb">
				<span style="display:flex;flex-direction:column;gap:6px">
					<span>
						Há {ini.porAcontecer.length === 1 ? '1 acampamento desta categoria' : `${ini.porAcontecer.length} acampamentos desta categoria`} por acontecer.
						As mudanças valem para as próximas inscrições; quem já se inscreveu continua inscrito.
					</span>
					<ul style="margin:0;padding-left:18px">
						{#each ini.porAcontecer as a (a.id)}
							<li><strong>{a.nome}</strong> · {dataDoAcampamento(a)} · {TEXTO_DA_FASE[faseDoAcampamento(a)]}</li>
						{/each}
					</ul>
				</span>
			</div>
		{/if}

		<div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:28px;align-items:start">
			<div style="display:flex;flex-direction:column;gap:18px;min-width:0">
				<div class="cat-fld">
					<label for="c-nome" class="cat-lbl">Nome <span aria-hidden="true" style="color:var(--vermelho)">*</span></label>
					<input
						id="c-nome"
						class="fm-campo"
						class:cat-invalido={verNome}
						bind:value={nome}
						oninput={() => mudou('nome')}
						onblur={() => (tocou.nome = true)}
						placeholder="Ex.: Juvenil"
						maxlength="100"
						aria-required="true"
						aria-invalid={verNome}
						aria-describedby="{verNome ? 'c-nome-e ' : ''}c-nome-a"
						data-foco-inicial
					/>
					{#if verNome}<p id="c-nome-e" class="cat-erro">{erroNome}</p>{/if}
					<p id="c-nome-a" class="ins-ajuda">Mudar o nome não muda nenhuma regra: as regras são as respostas abaixo.</p>
				</div>

				<div role="group" aria-labelledby="b1" class="cat-bloco">
					<h3 id="b1" class="cat-bloco-t"><span class="cat-bloco-n">1</span>Quem pode ser campista?</h3>
					<div class="cat-fld">
						<span id="l-vagas" class="cat-lbl">Vagas do sorteio</span>
						<div role="radiogroup" aria-labelledby="l-vagas" class="cat-op2">
							{@render opcao(!soCasais, 'Homens e mulheres', 'Vagas separadas para homens e para mulheres.', () => (soCasais = false), travaVaga && soCasais)}
							{@render opcao(soCasais, 'Só casais', 'Só pessoas casadas ou em união estável; o casal é sorteado junto.', () => (soCasais = true), travaVaga && !soCasais)}
						</div>
						{#if travaVaga}<p class="ins-ajuda">Só muda depois que os acampamentos por acontecer terminarem.</p>{/if}
					</div>

					<div class="cat-fld">
						<span id="l-idade" class="cat-lbl">Idade do campista <span class="cat-opcional">opcional</span></span>
						<div role="group" aria-labelledby="l-idade" style="display:flex;align-items:center;gap:10px;font-size:17px;color:var(--tinta-2)">
							De
							<input
								aria-label="Idade mínima"
								inputmode="numeric"
								class="fm-campo"
								class:cat-invalido={verIdade}
								style="width:88px;text-align:center"
								bind:value={de}
								oninput={() => mudou('idade')}
								onblur={() => (tocou.idade = true)}
								aria-invalid={verIdade}
								aria-describedby={verIdade ? 'c-idade-e' : undefined}
							/>
							até
							<input
								aria-label="Idade máxima"
								inputmode="numeric"
								class="fm-campo"
								class:cat-invalido={verIdade}
								style="width:88px;text-align:center"
								bind:value={ate}
								oninput={() => mudou('idade')}
								onblur={() => (tocou.idade = true)}
								aria-invalid={verIdade}
								aria-describedby={verIdade ? 'c-idade-e' : undefined}
							/>
							anos
						</div>
						{#if verIdade}<p id="c-idade-e" class="cat-erro">{erroIdade}</p>{/if}
						<p class="ins-ajuda">Conta a idade no primeiro dia do acampamento. Em branco = sem limite. Servos não dependem da idade.</p>
					</div>

					{#if !soCasais}
						<div class="cat-fld">
							<span id="l-cas" class="cat-lbl">Pessoas casadas podem ser campistas?</span>
							<div role="radiogroup" aria-labelledby="l-cas" class="cat-op2">
								{@render opcao(aceitaCasados, 'Sim', 'Solteiros e casados se inscrevem.', () => (aceitaCasados = true))}
								{@render opcao(!aceitaCasados, 'Não, só quem não é casado', 'Casados e em união estável não se inscrevem como campistas.', () => (aceitaCasados = false))}
							</div>
							{#if !aceitaCasados}<p class="ins-ajuda">{ajudaCasados}</p>{/if}
						</div>
					{/if}

					<div class="cat-fld">
						<span id="l-pre" class="cat-lbl">Precisa ter sido campista em outra categoria?</span>
						{#if poucasPre}
							<div role="radiogroup" aria-labelledby="l-pre" class="cat-chips">
								{#each opcoesPre as o (o.v ?? 0)}
									<button
										type="button"
										role="radio"
										class="cat-chip"
										aria-checked={requer === o.v}
										aria-disabled={o.bloq}
										onclick={() => escolherPre(o)}>{o.t}</button
									>
								{/each}
							</div>
						{:else}
							<div class="cat-seletor-caixa" bind:this={caixaPre}>
								<button
									type="button"
									class="fm-campo cat-seletor"
									aria-haspopup="listbox"
									aria-expanded={preAberto}
									aria-labelledby="l-pre"
									onclick={() => {
										preAberto = !preAberto;
										buscaPre = '';
									}}
								>
									<span>{opcoesPre.find((o) => o.v === requer)?.t ?? 'Não precisa'}</span><span aria-hidden="true">▾</span>
								</button>
								{#if preAberto}
									<div class="cat-lista-sel">
										<div style="padding:8px">
											<!-- svelte-ignore a11y_autofocus -->
											<input
												type="search"
												class="fm-campo"
												style="height:42px;font-size:16px"
												placeholder="Buscar categoria"
												aria-label="Buscar categoria"
												bind:value={buscaPre}
												autofocus
												onkeydown={(e) => {
													if (e.key === 'Escape') {
														e.stopPropagation();
														preAberto = false;
													}
												}}
											/>
										</div>
										<div role="listbox" aria-labelledby="l-pre" style="display:flex;flex-direction:column">
											{#each prePorBusca as o (o.v ?? 0)}
												<button
													type="button"
													role="option"
													class="cat-op-lista"
													aria-selected={requer === o.v}
													aria-disabled={o.bloq}
													onclick={() => escolherPre(o)}
												>
													<span class="cat-op-t" style:color={o.bloq ? '#7C7F8E' : undefined}>{o.t}</span>
													<span class="cat-op-s">{o.s}</span>
												</button>
											{/each}
										</div>
										{#if prePorBusca.length === 0}<p class="ins-ajuda" style="padding:8px 12px">Nenhuma categoria com esse nome.</p>{/if}
									</div>
								{/if}
							</div>
						{/if}
						{#if bloqueadasPre.length}
							<p class="ins-ajuda">
								{junta(bloqueadasPre)} já {bloqueadasPre.length > 1 ? 'pedem' : 'pede'} esta categoria; as duas não podem pedir uma à outra.
							</p>
						{/if}
					</div>
				</div>

				<div role="group" aria-labelledby="b2" class="cat-bloco">
					<h3 id="b2" class="cat-bloco-t"><span class="cat-bloco-n">2</span>Quem pode servir?</h3>
					<div role="radiogroup" aria-labelledby="b2" class="cat-op2">
						{@render opcao(naEscada, 'Entra na escada por idade', 'Quem foi campista aqui pode servir aqui e em todos os degraus de baixo.', () => (naEscada = true))}
						{@render opcao(!naEscada, 'Fica fora da escada', 'Quem foi campista aqui só serve aqui. Como Casais e Discipulados.', () => (naEscada = false))}
					</div>
					{#if !ini && !naEscada}
						<p class="ins-ajuda">Fora da escada, a categoria nova não muda onde ninguém pode servir. Ela entra no fim da lista.</p>
					{/if}
					{#if naEscada}
						<div class="cat-fld">
							<span id="l-deg" class="cat-lbl">Fica logo acima de</span>
							<SeletorDegrau
								escada={outrasNaEscada}
								bind:depois
								atual={ini?.naEscada ? depoisInicial : undefined}
								marcaAtual="(onde está hoje)"
								idRotulo="l-deg"
							/>
							<p class="ins-ajuda">Pela idade: os mais novos embaixo, os mais velhos em cima.</p>
							{#if mudaDegrau}
								<p class="ins-aviso amb">
									<span>Mudar o degrau muda quem pode servir onde, para todo mundo, nas próximas inscrições de servo. Confira na prévia.</span>
								</p>
							{/if}
						</div>
					{/if}
				</div>

				<div role="group" aria-labelledby="b3" class="cat-bloco">
					<h3 id="b3" class="cat-bloco-t"><span class="cat-bloco-n">3</span>Quem preenche o formulário?</h3>
					<div role="radiogroup" aria-labelledby="b3" class="cat-op2">
						{@render opcao(!responsavel, 'A própria pessoa', 'O formulário fala com quem se inscreve: “Seus dados”, “você”.', () => (responsavel = false))}
						{@render opcao(
							responsavel,
							'O pai, a mãe ou o responsável',
							'Para crianças: o formulário mostra “Dados da criança”, “Respostas sobre a criança” e fala “a criança” em vez de “você”.',
							() => (responsavel = true)
						)}
					</div>
				</div>
			</div>

			<aside class="cat-previa" aria-labelledby="t-prev">
				<span id="t-prev" class="ins-rot">Como fica</span>
				<p><strong>Campistas:</strong> {textos.campista}</p>
				<p><strong>Servos:</strong> {textos.servo}</p>
				{#if naEscada}
					<div class="cat-mini" aria-hidden="true">
						{#if mini.abaixo > 0}<span class="cat-mini-mais" style="padding-bottom:2px">+{mini.abaixo} abaixo …</span>{/if}
						{#each mini.degraus as m (m.id)}
							<span class="cat-mini-degrau" class:eu={m.eu} class:serve={m.serve}>
								<span>{m.eu ? esta.nome : m.nome}</span>
								<i style="height:{m.altura}px"></i>
							</span>
						{/each}
						{#if mini.acima > 0}<span class="cat-mini-mais" style="align-self:flex-start">… +{mini.acima} acima</span>{/if}
					</div>
					<p style="font-size:15px;color:var(--tinta-2)">Quem foi campista aqui pode servir {textoPodeServirEm(estaNaLista, comEla)}.</p>
				{:else}
					<p style="padding-top:10px;border-top:1px solid var(--borda-suave);font-size:15px;color:var(--tinta-2)">
						Fora da escada: quem foi campista aqui só serve aqui.
					</p>
				{/if}
				<p class="ins-ajuda" style="font-size:14px">É assim que as regras aparecem ao criar um acampamento.</p>
			</aside>
		</div>

		{#if erroServidor.geral}
			<p role="alert" class="ins-aviso verm"><span>{erroServidor.geral}</span></p>
		{/if}
		<div class="fm-janela-acoes" style="padding-top:16px;border-top:1px solid var(--linha)">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={fechar} disabled={enviando}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={salvar} disabled={enviando}>
				{enviando ? (ini ? 'Salvando...' : 'Criando...') : ini ? 'Salvar' : 'Criar categoria'}
			</button>
		</div>
	</div>
</Janela>

<svelte:window onclick={(e) => preAberto && caixaPre && !caixaPre.contains(e.target as Node) && (preAberto = false)} />
