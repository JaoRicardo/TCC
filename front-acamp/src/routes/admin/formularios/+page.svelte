<script lang="ts">
	/**
	 * Lista dos formulários.
	 *
	 * - Acampamentos: uma linha por categoria, com o formulário de Campista e o
	 *   de Servo. Cada célula diz quantas perguntas o formulário tem e se ele
	 *   está em uso (acampamento com o prazo aberto) — inclusive o caso
	 *   perigoso de estar em uso e vazio.
	 * - Eventos: formulários com nome próprio, que cada evento escolhe no
	 *   cadastro; mais os eventos que vão acontecer sem formulário.
	 */
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import '$lib/styles/formularios.css';
	import Janela from '$lib/components/painel/Janela.svelte';
	import {
		criarFormularioDeEvento,
		getResumoFormularios,
		type EventoDoFormulario,
		type ResumoEventos,
		type ResumoFormulario
	} from '$lib/services/perguntaService';
	import {
		diaEMes,
		doAcampamento,
		doEvento,
		enderecoDasRespostas,
		enderecoDoEditor,
		nomeCurto,
		tituloDoFormulario,
		type AlvoFormulario
	} from '$lib/formularios';
	import { pode } from '$lib/permissoes';

	let formularios: ResumoFormulario[] = $state([]);
	let eventos: ResumoEventos = $state({ formularios: [], sem_formulario: [] });
	let estado: 'carregando' | 'erro' | 'pronto' = $state('carregando');

	// ─── Novo formulário de evento ───────────────────────────────────────
	let novoAberto = $state(false);
	let novoNome = $state('');
	/** enderecoDoEditor() do formulário de origem, ou '' para começar em branco. */
	let novoBase = $state('');
	let novoEventos: number[] = $state([]);
	let criando = $state(false);
	let erroNovo = $state('');

	/** Formulários com perguntas, para "Começar com". */
	let bases = $derived.by(() => {
		const lista: { alvo: AlvoFormulario; titulo: string; grupo: string }[] = [];
		for (const f of formularios) {
			if (f.perguntas > 0) {
				lista.push({ alvo: doAcampamento(f.category_id, f.subscription_type), titulo: tituloDoFormulario(f.categoria, f.subscription_type), grupo: 'Acampamentos' });
			}
		}
		for (const f of eventos.formularios) {
			if (f.perguntas > 0) lista.push({ alvo: doEvento(f.id), titulo: f.nome, grupo: 'Eventos' });
		}
		return lista;
	});

	function abrirNovo() {
		novoNome = '';
		novoBase = '';
		novoEventos = [];
		erroNovo = '';
		novoAberto = true;
	}

	async function criarNovo() {
		const nome = novoNome.trim();
		if (!nome) {
			erroNovo = 'Dê um nome ao formulário.';
			return;
		}
		criando = true;
		erroNovo = '';
		try {
			const base = bases.find((b) => enderecoDoEditor(b.alvo) === novoBase);
			const res = await criarFormularioDeEvento({ nome, copiarDe: base?.alvo ?? null, eventos: novoEventos });
			if (res.error || !res.data) {
				erroNovo = res.error || 'Não foi possível criar o formulário.';
				return;
			}
			goto(enderecoDoEditor(doEvento(res.data.id)));
		} catch {
			erroNovo = 'Não foi possível criar o formulário. Verifique sua conexão.';
		} finally {
			criando = false;
		}
	}

	/** "Missa de Envio (21/11)" */
	const eventoCurto = (e: EventoDoFormulario) => `${e.nome}${e.inicio ? ` (${diaEMes(e.inicio)})` : ''}`;

	/**
	 * Uma linha por categoria, na ordem da tela Gestão › Categorias. As
	 * arquivadas vão para o fim, separadas: os formulários e as respostas dos
	 * acampamentos antigos continuam acessíveis.
	 */
	type Linha = { categoria: string; arquivada: boolean; campista?: ResumoFormulario; servo?: ResumoFormulario };
	let todasAsLinhas = $derived.by(() => {
		const porCategoria = new Map<number, Linha>();
		for (const f of formularios) {
			const linha = porCategoria.get(f.category_id) ?? { categoria: f.categoria, arquivada: !!f.arquivada };
			if (f.subscription_type === 'Campista') linha.campista = f;
			else linha.servo = f;
			porCategoria.set(f.category_id, linha);
		}
		return [...porCategoria.values()];
	});
	let linhas = $derived(todasAsLinhas.filter((l) => !l.arquivada));
	let linhasArquivadas = $derived(todasAsLinhas.filter((l) => l.arquivada));

	async function carregar() {
		estado = 'carregando';
		try {
			const res = await getResumoFormularios();
			if (res.error || !res.data) {
				estado = 'erro';
				return;
			}
			formularios = res.data;
			eventos = res.eventos ?? { formularios: [], sem_formulario: [] };
			estado = 'pronto';
		} catch {
			estado = 'erro';
		}
	}

	onMount(carregar);
</script>

<svelte:head>
	<title>Formulários | Painel da Comunidade</title>
</svelte:head>

{#snippet celula(f: ResumoFormulario | undefined)}
	{#if f}
		{@const titulo = tituloDoFormulario(f.categoria, f.subscription_type)}
		{@const acao = !pode('formularios') ? 'Ver' : f.perguntas > 0 ? 'Editar' : 'Montar'}
		<div class="celula" class:celula--vazia={f.perguntas === 0}>
			<div class="celula-info">
				{#if f.perguntas > 0}
					<span class="celula-numeros">
						<strong>{f.perguntas} {f.perguntas === 1 ? 'pergunta' : 'perguntas'}</strong>
						· {f.secoes.length} {f.secoes.length === 1 ? 'seção' : 'seções'}
					</span>
					{#if f.uso}
						<span class="celula-uso">
							<span class="selo-uso">Em uso · {nomeCurto(f.uso.nome)}</span>
							Prazo {diaEMes(f.uso.prazo)} · {f.uso.enviados} de {f.uso.total} enviaram
						</span>
					{:else}
						<span class="celula-nota">Nenhum acampamento aberto agora.</span>
					{/if}
				{:else}
					<span class="celula-vazio">Vazio</span>
					{#if f.uso}
						<span class="faixa-perigo">{nomeCurto(f.uso.nome)} está aberto: ninguém perde a vaga pelo prazo</span>
					{:else}
						<span class="celula-nota">Sem perguntas, ninguém perde a vaga pelo prazo.</span>
					{/if}
				{/if}
			</div>
			<div class="celula-acoes">
				{#if f.respostas > 0 && pode('respostas_formularios')}
					<a
						class="fm-btn"
						href={enderecoDasRespostas(doAcampamento(f.category_id, f.subscription_type))}
						aria-label="Respostas de {titulo}"
					>
						Respostas
					</a>
				{/if}
				<a
					class="fm-btn fm-btn--contorno"
					href={enderecoDoEditor(doAcampamento(f.category_id, f.subscription_type))}
					aria-label="{acao} {titulo}"
				>
					{acao}
				</a>
			</div>
		</div>
	{/if}
{/snippet}

<div class="fm">
	<div class="cabecalho">
		<h1 class="fm-h1">Formulários</h1>
		<p class="fm-sub">
			Perguntas que sorteados, indicados e suplentes respondem depois de confirmados. Cada formulário vale para
			todos os acampamentos da categoria, ano após ano.
		</p>
	</div>

	{#if estado === 'erro'}
		<section class="fm-cartao fm-centro" role="alert">
			<span class="fm-erro-icone">!</span>
			<p class="fm-centro-titulo">Não foi possível carregar os formulários.</p>
			<p class="fm-sub">Verifique sua conexão com a internet e tente novamente.</p>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Tentar novamente</button>
		</section>
	{:else}
		<section class="fm-cartao tabela-cartao">
			<div class="tabela-titulo">
				<h2 class="fm-h2">Acampamentos</h2>
				<p>Um formulário por categoria, separado para campistas e servos.</p>
			</div>

			{#if estado === 'carregando'}
				<div class="carregando" role="status"><span class="fm-spinner"></span>Carregando formulários...</div>
				{#each Array(6) as _}
					<div class="linha" aria-hidden="true">
						<span class="fm-osso" style="height:22px;width:60%;align-self:center"></span>
						<span class="fm-osso fm-osso--claro" style="height:74px;border-radius:12px"></span>
						<span class="fm-osso fm-osso--claro" style="height:74px;border-radius:12px"></span>
					</div>
				{/each}
			{:else}
				<table>
					<thead>
						<tr class="linha linha--cabecalho">
							<th scope="col">Categoria</th>
							<th scope="col">Campista</th>
							<th scope="col">Servo</th>
						</tr>
					</thead>
					<tbody>
						{#each linhas as linha}
							<tr class="linha">
								<th scope="row" class="categoria">{linha.categoria}</th>
								<td>{@render celula(linha.campista)}</td>
								<td>{@render celula(linha.servo)}</td>
							</tr>
						{/each}
						{#if linhasArquivadas.length}
							<tr class="linha linha--grupo">
								<th scope="colgroup" colspan="3">Categorias arquivadas</th>
							</tr>
							{#each linhasArquivadas as linha}
								<tr class="linha linha--arquivada">
									<th scope="row" class="categoria">{linha.categoria}<span class="selo-arquivada">Arquivada</span></th>
									<td>{@render celula(linha.campista)}</td>
									<td>{@render celula(linha.servo)}</td>
								</tr>
							{/each}
						{/if}
					</tbody>
				</table>
			{/if}
		</section>

		{#if estado === 'pronto'}
			<section class="fm-cartao tabela-cartao">
				<div class="tabela-titulo tabela-titulo--acao">
					<div>
						<h2 class="fm-h2">Eventos</h2>
						<p>
							Eventos não têm categoria nem servos. Cada evento escolhe o formulário no próprio cadastro, e um mesmo
							formulário pode servir para vários eventos.
						</p>
					</div>
					{#if pode('formularios')}
						<button type="button" class="fm-btn fm-btn--ouro" onclick={abrirNovo}>+ Novo formulário de evento</button>
					{/if}
				</div>

				{#if eventos.formularios.length === 0}
					<p class="eventos-vazio">Nenhum formulário de evento ainda. Sem formulário, a inscrição no evento termina na hora.</p>
				{:else}
					<table>
						<thead>
							<tr class="linha linha--cabecalho linha--eventos">
								<th scope="col">Formulário</th>
								<th scope="col">Usado nos eventos</th>
								<th scope="col"><span class="visualmente-escondido">Ações</span></th>
							</tr>
						</thead>
						<tbody>
							{#each eventos.formularios as f (f.id)}
								{@const acao = !pode('formularios') ? 'Ver' : f.perguntas > 0 ? 'Editar' : 'Montar'}
								<tr class="linha linha--eventos">
									<th scope="row" class="evento-form">
										<span class="evento-nome">{f.nome}</span>
										<span class="celula-nota">
											{f.perguntas > 0
												? `${f.perguntas} ${f.perguntas === 1 ? 'pergunta' : 'perguntas'} · ${f.secoes.length} ${f.secoes.length === 1 ? 'seção' : 'seções'}`
												: 'Vazio: a inscrição termina na hora'}
										</span>
									</th>
									<td>
										<div class="eventos-uso">
											{#each f.eventos as e (e.atividade_id)}
												<span class="evento-uso">
													<span class="selo-evento">Evento</span>{e.nome}
													{#if e.fim_inscricoes}<span class="celula-nota">
															· {e.aberto ? 'inscrições até' : 'inscrições encerradas em'} {diaEMes(e.fim_inscricoes)}</span
														>{/if}
												</span>
											{:else}
												<span class="celula-nota">Nenhum evento usa este formulário.</span>
											{/each}
										</div>
									</td>
									<td>
										<div class="celula-acoes">
											{#if f.respostas > 0 && pode('respostas_formularios')}
												<a class="fm-btn" href={enderecoDasRespostas(doEvento(f.id))} aria-label="Respostas de {f.nome}">Respostas</a>
											{/if}
											<a class="fm-btn fm-btn--contorno" href={enderecoDoEditor(doEvento(f.id))} aria-label="{acao} {f.nome}">{acao}</a>
										</div>
									</td>
								</tr>
							{/each}
						</tbody>
					</table>
				{/if}

				{#if eventos.sem_formulario.length}
					<p class="sem-formulario">
						<strong>Sem formulário:</strong>
						{eventos.sem_formulario.map(eventoCurto).join(', ')}. Neles, a inscrição termina na hora, sem perguntas.
					</p>
				{/if}
			</section>
		{/if}
	{/if}
</div>

{#if novoAberto}
	<Janela idTitulo="t-novo-evento" largura={600} travada={criando} onfechar={() => (novoAberto = false)}>
		<h2 id="t-novo-evento" class="fm-janela-titulo">Novo formulário de evento</h2>
		<form
			class="novo"
			onsubmit={(e) => {
				e.preventDefault();
				criarNovo();
			}}
		>
			<label class="novo-campo">
				<span class="fm-rotulo">Nome</span>
				<input class="fm-campo" bind:value={novoNome} placeholder="Ex: Retiro de Carnaval" maxlength="150" data-foco-inicial />
			</label>
			<label class="novo-campo">
				<span class="fm-rotulo">Começar com</span>
				<select class="fm-campo" bind:value={novoBase}>
					<option value="">Em branco</option>
					{#each ['Acampamentos', 'Eventos'] as grupo}
						{@const doGrupo = bases.filter((b) => b.grupo === grupo)}
						{#if doGrupo.length}
							<optgroup label="Copiar de um formulário de {grupo.toLowerCase()}">
								{#each doGrupo as b (enderecoDoEditor(b.alvo))}
									<option value={enderecoDoEditor(b.alvo)}>{b.titulo}</option>
								{/each}
							</optgroup>
						{/if}
					{/each}
				</select>
				{#if novoBase}<span class="novo-nota">As perguntas são copiadas: mudar uma depois não altera a outra.</span>{/if}
			</label>
			{#if eventos.sem_formulario.length}
				<fieldset class="novo-campo novo-eventos">
					<legend class="fm-rotulo">Usar nos eventos sem formulário <span class="novo-opcional">(opcional)</span></legend>
					{#each eventos.sem_formulario as e (e.atividade_id)}
						<label class="novo-evento">
							<input type="checkbox" value={e.atividade_id} bind:group={novoEventos} />
							{e.nome}{#if e.inicio}<span class="celula-nota"> · {diaEMes(e.inicio)}</span>{/if}
						</label>
					{/each}
				</fieldset>
			{/if}
			{#if erroNovo}<p class="fm-erro-linha" role="alert">{erroNovo}</p>{/if}
			<div class="fm-janela-acoes">
				<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" disabled={criando} onclick={() => (novoAberto = false)}>
					Cancelar
				</button>
				<button type="submit" class="fm-btn fm-btn--ouro fm-btn--grande" disabled={criando || !novoNome.trim()}>
					{#if criando}<span class="fm-spinner fm-spinner--pequeno"></span>Criando...{:else}Criar e abrir{/if}
				</button>
			</div>
		</form>
	</Janela>
{/if}

<style>
	.cabecalho {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.cabecalho .fm-sub {
		max-width: 780px;
	}

	.tabela-cartao {
		overflow: hidden;
	}

	.tabela-titulo {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 20px 24px 16px;
	}

	.tabela-titulo p {
		margin: 0;
		font-size: 16px;
		color: var(--tinta-3);
	}

	table {
		width: 100%;
		border-collapse: collapse;
	}

	.linha {
		display: grid;
		grid-template-columns: 180px minmax(0, 1fr) minmax(0, 1fr);
		gap: 18px;
		align-items: stretch;
		padding: 14px 24px;
		border-bottom: 1px solid var(--linha);
	}

	.linha--cabecalho {
		padding: 12px 24px;
		background: var(--cabecalho-tabela);
		border-top: 1px solid var(--borda-suave);
		border-bottom: 1px solid var(--borda-suave);
	}

	.linha--cabecalho th {
		text-align: left;
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}

	.linha td,
	.linha th {
		padding: 0;
	}

	/* ─── Eventos ─── */
	.tabela-titulo--acao {
		flex-direction: row;
		align-items: flex-start;
		justify-content: space-between;
		gap: 20px;
	}

	.tabela-titulo--acao > div {
		display: flex;
		flex-direction: column;
		gap: 4px;
		max-width: 760px;
	}

	.linha--eventos {
		grid-template-columns: minmax(200px, 0.9fr) minmax(0, 1.6fr) auto;
		align-items: center;
	}

	.evento-form {
		display: flex;
		flex-direction: column;
		gap: 2px;
		text-align: left;
	}

	.evento-nome {
		font: 600 22px/1.15 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.eventos-uso {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.evento-uso {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 8px;
		font-size: 16px;
		color: var(--tinta);
	}

	.selo-evento {
		padding: 1px 8px;
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.06em;
		text-transform: uppercase;
		color: #fffdf8;
		background: var(--vermelho);
		border-radius: 999px;
	}

	.eventos-vazio,
	.sem-formulario {
		margin: 0;
		padding: 16px 24px 20px;
		font-size: 15px;
		line-height: 1.45;
		color: var(--tinta-3);
	}

	.sem-formulario {
		border-top: 1px solid var(--linha);
		background: var(--cabecalho-tabela);
	}

	.visualmente-escondido {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip: rect(0 0 0 0);
	}

	/* ─── Janela "Novo formulário de evento" ─── */
	.novo {
		display: flex;
		flex-direction: column;
		gap: 18px;
	}

	.novo-campo {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin: 0;
		padding: 0;
		border: none;
	}

	.novo-nota,
	.novo-opcional {
		font-size: 14px;
		font-weight: 400;
		letter-spacing: 0;
		text-transform: none;
		color: var(--tinta-3);
	}

	.novo-eventos {
		gap: 4px;
	}

	.novo-evento {
		display: flex;
		align-items: center;
		gap: 10px;
		min-height: 40px;
		font-size: 16px;
		cursor: pointer;
	}

	.novo-evento input {
		width: 20px;
		height: 20px;
		accent-color: var(--azul);
	}

	.categoria {
		align-self: center;
		text-align: left;
		font: 600 26px/1.1 'Cormorant Garamond', serif;
		color: var(--tinta);
	}

	.linha--grupo {
		display: block;
		padding: 10px 24px;
		background: var(--cabecalho-tabela);
		border-top: 1px solid var(--borda-suave);
	}

	.linha--grupo th {
		text-align: left;
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}

	.linha--arquivada .categoria {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 6px;
		color: var(--tinta-3);
	}

	.selo-arquivada {
		font: 600 12px 'Source Sans 3', sans-serif;
		color: var(--tinta-3);
		background: #efebe1;
		border-radius: 999px;
		padding: 2px 9px;
	}

	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 18px 24px;
		font-size: 17px;
		color: var(--tinta-3);
		border-top: 1px solid var(--borda-suave);
		border-bottom: 1px solid var(--linha);
	}

	.celula-acoes {
		display: flex;
		gap: 8px;
		flex: 0 0 auto;
	}

	.celula {
		height: 100%;
		box-sizing: border-box;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		padding: 14px 16px;
		border-radius: 12px;
		background: #ffffff;
		border: 1px solid var(--borda-suave);
	}

	.celula--vazia {
		background: transparent;
		border: 1px dashed #cdb878;
	}

	.celula-info {
		display: flex;
		flex-direction: column;
		gap: 6px;
		min-width: 0;
	}

	.celula-numeros {
		font-size: 16px;
		color: var(--tinta-2);
	}

	.celula-numeros strong {
		font-size: 19px;
		color: var(--tinta);
	}

	.celula-vazio {
		font-size: 18px;
		font-weight: 700;
		color: var(--tinta-3);
	}

	.celula-nota {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.celula-uso {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		font-size: 15px;
		color: var(--selo-cond-tx);
	}

	.selo-uso {
		font-weight: 700;
		background: var(--selo-cond-bg);
		border: 1px solid var(--selo-cond-bd);
		border-radius: 999px;
		padding: 2px 10px;
	}

	.faixa-perigo {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 15px;
		font-weight: 600;
		color: var(--aviso-tx);
		background: var(--aviso-bg);
		border: 1px solid var(--aviso-bd);
		border-radius: 8px;
		padding: 5px 10px;
	}

	.faixa-perigo::before {
		content: '';
		flex: 0 0 8px;
		height: 8px;
		border-radius: 50%;
		background: var(--aviso-ponto);
	}
</style>
