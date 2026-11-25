<script lang="ts">
	import { onMount } from 'svelte';
	import { page } from '$app/stores';
	import { replaceState } from '$app/navigation';
	import { getUsers, type ItemPermissao } from '$lib/services/userService';
	import type { User } from '$lib/types/models';
	import { auth } from '$lib/stores/auth.svelte';
	import { pode } from '$lib/permissoes';
	import { mascaraCpf, mascaraTelefone } from '$lib/mascaras';
	import { semAcento, digitosDeCpf, cpfContem } from '$lib/busca';
	import logoAsas from '$lib/imgs/logo-asas.png';
	import JanelasDaConta from '$lib/components/painel/JanelasDaConta.svelte';

	const POR_PAGINA = 25;

	// Quem gerencia permissões vê a versão completa: filtro, coluna "Acesso ao
	// painel" e o botão "Permissões". Os outros só listam e desativam contas.
	let gerenciaPermissoes = $derived(pode('permissoes'));

	// ─── Lista ────────────────────────────────────────────────────────────
	let users: User[] = $state([]);
	/** Telas do painel com a área de cada uma (só vem para quem gerencia permissões). */
	let catalogo: ItemPermissao[] = $state([]);
	let estadoLista: 'carregando' | 'erro' | 'lista' = $state('carregando');

	async function carregar() {
		estadoLista = 'carregando';
		try {
			const res = await getUsers();
			if (res.error) {
				estadoLista = 'erro';
				return;
			}
			users = res.users;
			catalogo = res.catalogo ?? [];
			estadoLista = 'lista';
		} catch {
			estadoLista = 'erro';
		}
	}

	onMount(carregar);

	// ─── Busca, filtro e páginas ──────────────────────────────────────────
	// Ficam no endereço (?busca=joao&filtro=todos&pagina=2): a ficha da pessoa
	// recebe os mesmos valores e o "Voltar" dela cai no mesmo lugar da lista.
	const naUrl = $page.url.searchParams;
	let busca = $state(naUrl.get('busca') ?? '');
	let filtro: 'todos' | 'painel' | 'part' = $state(
		(['todos', 'painel', 'part'] as const).find((f) => f === naUrl.get('filtro')) ?? 'todos'
	);
	let pagina = $state(Math.max(1, Number(naUrl.get('pagina')) || 1));

	let filtrosDaLista = $derived.by(() => {
		const p = new URLSearchParams();
		if (busca.trim()) p.set('busca', busca.trim());
		if (filtro !== 'todos') p.set('filtro', filtro);
		if (pagina > 1) p.set('pagina', String(pagina));
		const qs = p.toString();
		return qs ? `?${qs}` : '';
	});

	// Compara com window.location, não com $page: o replaceState não muda
	// $page.url, mas avisa o store $page — ler $page aqui fazia o efeito rodar
	// de novo a cada troca, em laço sem fim (a tela travava ao digitar).
	$effect(() => {
		const endereco = location.pathname + filtrosDaLista;
		if (endereco !== location.pathname + location.search) replaceState(endereco, {});
	});

	const linkDaFicha = (u: User) => `/admin/pessoas/${u.id}${filtrosDaLista}`;

	// Ignora acentos e maiúsculas; o CPF só entra quando o termo parece um CPF.
	let encontrados = $derived.by(() => {
		const termo = semAcento(busca.trim());
		if (!termo) return users;
		const digitos = digitosDeCpf(termo);
		return users.filter(
			(u) =>
				semAcento(u.name).includes(termo) ||
				semAcento(u.email ?? '').includes(termo) ||
				cpfContem(u.cpf, digitos)
		);
	});

	const temPainel = (u: User) => (u.telas_liberadas?.length ?? 0) > 0;

	let contagem = $derived({
		todos: encontrados.length,
		painel: encontrados.filter(temPainel).length,
		part: encontrados.filter((u) => !temPainel(u)).length
	});

	let filtrados = $derived(
		!gerenciaPermissoes || filtro === 'todos'
			? encontrados
			: encontrados.filter((u) => (filtro === 'painel') === temPainel(u))
	);

	let totalPaginas = $derived(Math.max(1, Math.ceil(filtrados.length / POR_PAGINA)));
	let paginaAtual = $derived(Math.min(pagina, totalPaginas));
	let visiveis = $derived(filtrados.slice((paginaAtual - 1) * POR_PAGINA, paginaAtual * POR_PAGINA));

	/** Números das páginas com "…" no meio: 1 … 4 5 6 … 21 */
	let numerosPaginas = $derived.by(() => {
		const n = totalPaginas;
		if (n <= 7) return Array.from({ length: n }, (_, i) => i + 1);
		const perto = [paginaAtual - 1, paginaAtual, paginaAtual + 1].filter((p) => p > 1 && p < n);
		const lista: (number | '…')[] = [1];
		if (perto[0] > 2) lista.push('…');
		lista.push(...perto);
		if (perto[perto.length - 1] < n - 1) lista.push('…');
		lista.push(n);
		return lista;
	});

	function mudarBusca(e: Event) {
		busca = (e.currentTarget as HTMLInputElement).value;
		pagina = 1;
	}

	function limparBusca() {
		busca = '';
		pagina = 1;
	}

	function escolherFiltro(f: typeof filtro) {
		filtro = f;
		pagina = 1;
	}

	// ─── Exibição ─────────────────────────────────────────────────────────
	// A máscara de digitação supõe celular (11 dígitos); fixo tem 10: (00) 0000-0000.
	function telefone(valor: string) {
		const d = valor.replace(/\D/g, '');
		return d.length === 10 ? d.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3') : mascaraTelefone(d);
	}

	const dataBr = (iso?: string) =>
		iso ? new Date(iso.length === 10 ? iso + 'T12:00:00Z' : iso).toLocaleDateString('pt-BR') : '—';

	/** Um selo por área com alguma tela; todas as telas viram um só "Acesso completo". */
	function selosDe(u: User): { completo: boolean; areas: string[] } {
		const telas = u.telas_liberadas ?? [];
		if (catalogo.length && catalogo.every((i) => telas.includes(i.chave))) {
			return { completo: true, areas: [] };
		}
		const areas = catalogo.filter((i) => telas.includes(i.chave)).map((i) => i.area_curta);
		return { completo: false, areas: [...new Set(areas)] };
	}

	// ─── Aviso curto depois de salvar ─────────────────────────────────────
	let aviso = $state('');
	let avisoTimer: ReturnType<typeof setTimeout>;
	function avisar(texto: string) {
		aviso = texto;
		clearTimeout(avisoTimer);
		avisoTimer = setTimeout(() => (aviso = ''), 3500);
	}

	// ─── Janelas (Desativar e Permissões) ────────────────────────────────
	let modal: { tipo: 'desativar' | 'permissoes'; alvo: User } | null = $state(null);

	function abrirDesativar(user: User) {
		modal = { tipo: 'desativar', alvo: user };
	}

	function abrirPermissoes(user: User) {
		modal = { tipo: 'permissoes', alvo: user };
	}

	function desativado(alvo: User) {
		users = users.filter((u) => u.id !== alvo.id);
		modal = null;
		avisar(`Conta de ${alvo.name.split(' ')[0]} desativada`);
	}

	function permissoesSalvas(alvo: User, ativas: string[]) {
		users = users.map((u) => (u.id === alvo.id ? { ...u, telas_liberadas: ativas } : u));
		modal = null;
		avisar('Permissões salvas');
	}
</script>

<div class="pessoas">
	<div class="titulo">
		<h1>Pessoas e acessos</h1>
		<p>Contas cadastradas no sistema.</p>
	</div>

	<div class="ferramentas">
		<label class="busca">
			<span>Buscar</span>
			<input type="search" value={busca} oninput={mudarBusca} placeholder="Nome, CPF ou e-mail" />
		</label>

		{#if gerenciaPermissoes}
			<div class="filtro" role="group" aria-labelledby="rotulo-filtro">
				<span id="rotulo-filtro">Mostrar</span>
				<div class="chips">
					{#each [['todos', 'Todos'], ['painel', 'Com acesso ao painel'], ['part', 'Só participantes']] as const as [chave, rotulo]}
						<button
							type="button"
							class="chip"
							class:chip--ativo={filtro === chave}
							aria-pressed={filtro === chave}
							onclick={() => escolherFiltro(chave)}
						>
							{rotulo}<span class="chip-num">{contagem[chave]}</span>
						</button>
					{/each}
				</div>
			</div>
		{/if}
	</div>

	<section class="tabela" class:tabela--basica={!gerenciaPermissoes} role="table" aria-label="Pessoas cadastradas">
		<div class="linha cabecalho" role="row">
			<span role="columnheader">Pessoa · CPF</span>
			<span role="columnheader">Contato</span>
			<span role="columnheader">Datas</span>
			{#if gerenciaPermissoes}<span role="columnheader">Acesso ao painel</span>{/if}
			<span role="columnheader" class="direita">Ações</span>
		</div>

		{#if estadoLista === 'carregando'}
			<div class="carregando" role="status">
				<span class="spinner"></span>Carregando lista de usuários...
			</div>
			{#each Array(8) as _}
				<div class="linha esqueleto" aria-hidden="true">
					<div class="pilha"><span class="osso" style="width:70%;height:16px"></span><span class="osso osso--claro" style="width:45%"></span></div>
					<div class="pilha"><span class="osso osso--medio" style="width:80%;height:14px"></span><span class="osso osso--claro" style="width:40%"></span></div>
					<span class="osso osso--medio" style="width:70%;height:14px"></span>
					{#if gerenciaPermissoes}<span class="osso osso--medio" style="width:60%;height:26px;border-radius:999px"></span>{/if}
					<span class="osso osso--claro" style="height:40px;border-radius:999px"></span>
				</div>
			{/each}
		{:else if estadoLista === 'erro'}
			<div class="centro" role="alert">
				<span class="erro-icone">!</span>
				<p class="centro-titulo">Não foi possível carregar a lista.</p>
				<p class="centro-texto">Verifique sua conexão com a internet e tente novamente.</p>
				<button type="button" class="btn btn--ouro" onclick={carregar}>Tentar novamente</button>
			</div>
		{:else if visiveis.length === 0}
			<div class="centro">
				<div class="vazio-arco"><img src={logoAsas} alt="" /></div>
				<p class="centro-titulo">Nenhum usuário encontrado.</p>
				{#if busca.trim()}
					<p class="centro-texto">Nada com “{busca.trim()}”. Confira a grafia ou busque pelo CPF.</p>
					<button type="button" class="btn btn--contorno" onclick={limparBusca}>Limpar busca</button>
				{/if}
			</div>
		{:else}
			{#each visiveis as user (user.id)}
				{@const voce = user.id === auth.user?.id}
				<div class="linha" class:linha--voce={voce} role="row">
					<div class="pilha" role="cell">
						<div class="nome">
							<a class="nome-link" href={linkDaFicha(user)}>{user.name}</a>
							{#if voce}<span class="selo-voce">Você</span>{/if}
						</div>
						<span class="secundario">{user.cpf ? mascaraCpf(user.cpf) : '—'}</span>
					</div>

					<div class="pilha" role="cell">
						<span class="email">{user.email || '—'}</span>
						<span class="secundario">{user.phone ? telefone(user.phone) : '—'}</span>
					</div>

					<div class="datas" role="cell">
						<span class="datas-rotulo">Nasc.</span><span>{dataBr(user.birthday)}</span>
						<span class="datas-rotulo">Cadastro</span><span>{dataBr(user.created)}</span>
					</div>

					{#if gerenciaPermissoes}
						{@const selos = selosDe(user)}
						<div class="selos" role="cell">
							{#if selos.completo}
								<span class="selo selo--completo">Acesso completo</span>
							{:else if selos.areas.length}
								{#each selos.areas as area}<span class="selo">{area}</span>{/each}
							{:else}
								<span class="so-participante">Só participante</span>
							{/if}
						</div>
					{/if}

					<div class="acoes" role="cell">
						<div class="acoes-botoes">
							<a class="btn btn--linha btn--ficha" href={linkDaFicha(user)} aria-label="Abrir ficha de {user.name}">
								Abrir ficha
							</a>
							{#if gerenciaPermissoes}
								<button type="button" class="btn btn--linha btn--contorno" onclick={() => abrirPermissoes(user)}>
									Permissões
								</button>
							{/if}
							{#if voce}
								<button
									type="button"
									class="btn btn--linha btn--travado"
									disabled
									title="Você não pode desativar a própria conta."
									aria-describedby="ajuda-voce"
								>
									Desativar
								</button>
							{:else}
								<button type="button" class="btn btn--linha btn--perigo" onclick={() => abrirDesativar(user)}>
									Desativar
								</button>
							{/if}
						</div>
						{#if voce}
							<span id="ajuda-voce" class="acoes-ajuda">É a sua conta: não dá para desativar.</span>
						{/if}
					</div>
				</div>
			{/each}

			<div class="rodape">
				<span>
					Mostrando <strong>{(paginaAtual - 1) * POR_PAGINA + 1}–{(paginaAtual - 1) * POR_PAGINA + visiveis.length}</strong>
					de <strong>{filtrados.length}</strong> {filtrados.length === 1 ? 'conta' : 'contas'}
				</span>
				{#if totalPaginas > 1}
					<nav class="paginas" aria-label="Páginas">
						<button type="button" class="pag" disabled={paginaAtual === 1} onclick={() => (pagina = paginaAtual - 1)}>← Anterior</button>
						{#each numerosPaginas as n}
							{#if n === '…'}
								<span class="pag-reticencias">…</span>
							{:else}
								<button
									type="button"
									class="pag pag--num"
									class:pag--atual={n === paginaAtual}
									aria-current={n === paginaAtual ? 'page' : undefined}
									onclick={() => (pagina = n)}
								>
									{n}
								</button>
							{/if}
						{/each}
						<button type="button" class="pag" disabled={paginaAtual === totalPaginas} onclick={() => (pagina = paginaAtual + 1)}>Próxima →</button>
					</nav>
				{/if}
			</div>
		{/if}
	</section>
</div>

{#if modal}
	{@const alvo = modal.alvo}
	<JanelasDaConta
		tipo={modal.tipo}
		{alvo}
		onfechar={() => (modal = null)}
		ondesativado={() => desativado(alvo)}
		onpermissoes={(ativas) => permissoesSalvas(alvo, ativas)}
	/>
{/if}

{#if aviso}
	<div class="aviso-salvo" role="status">✓ {aviso}</div>
{/if}

<style>
	/* Identidade da São Miguel Arcanjo (a mesma do site e do login). */
	.pessoas,
	.aviso-salvo {
		--ouro: #b69e55;
		--ouro-claro: #e2ce8a;
		--ouro-texto: #7a6528;
		--azul: #1b2f5e;
		--azul-noite: #0f1d3d;
		--vermelho: #9b2c2c;
		--marfim: #f7f2e7;
		--pergaminho: #fffdf8;
		--borda: #d9cca6;
		--borda-suave: #e3d8bc;
		--tinta: #1e2233;
		--tinta-2: #2b3045;
		--tinta-3: #4f5468;
		--campo-borda: #a39363;
		--anel-foco: rgba(182, 158, 85, 0.45);
		--linha: #efe7d3;
		--cabecalho-tabela: #fbf6e6;
		--hover-linha: #fbf8ef;
		--linha-voce: #fdf9ee;
		--selo-area-bg: #e6ebf5;
		--selo-area-bd: #c4cfe6;
		--trava: #8a90a6;
		--aviso-bg: #fbf0d6;
		--aviso-bd: #ebd39c;
		--aviso-tx: #6a480c;
		--aviso-ponto: #c08a1e;
		font-family: 'Source Sans 3', system-ui, sans-serif;
		color: var(--tinta);
	}

	/* Ocupa a área de conteúdo inteira do painel (cobre o respiro do layout). */
	.pessoas {
		margin: -2.5rem;
		min-height: calc(100% + 5rem);
		box-sizing: border-box;
		padding: 28px 32px 40px;
		background: var(--marfim);
		display: flex;
		flex-direction: column;
		gap: 20px;
	}

	.pessoas :is(button, input, a):focus-visible {
		outline: 4px solid var(--anel-foco);
		outline-offset: 0;
	}

	.titulo {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.titulo h1 {
		margin: 0;
		font: 600 44px/1.05 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.titulo p {
		margin: 0;
		font-size: 17px;
		color: var(--tinta-3);
	}

	/* ─── Busca e filtro ──────────────────────────────────────────── */
	.ferramentas {
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		gap: 16px 24px;
		flex-wrap: wrap;
	}

	.busca,
	.filtro {
		display: flex;
		flex-direction: column;
		gap: 8px;
		font-size: 16px;
		font-weight: 600;
	}

	.busca {
		flex: 1 1 380px;
		max-width: 520px;
	}

	.busca input {
		width: 100%;
		box-sizing: border-box;
		height: 52px;
		padding: 0 16px;
		font: 400 17px 'Source Sans 3', sans-serif;
		color: var(--tinta);
		background: #fff;
		border: 1.5px solid var(--campo-borda);
		border-radius: 10px;
	}

	.busca input::placeholder {
		color: #7c7f8e;
	}

	.busca input:focus-visible {
		border-color: var(--azul);
	}

	.chips {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
	}

	.chip {
		display: flex;
		align-items: center;
		gap: 8px;
		height: 52px;
		box-sizing: border-box;
		padding: 0 18px;
		font: 600 16px 'Source Sans 3', sans-serif;
		color: var(--tinta-2);
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 999px;
		cursor: pointer;
	}

	.chip:hover {
		border-color: var(--ouro);
		background: var(--cabecalho-tabela);
	}

	.chip-num {
		font-size: 14px;
		color: #6b5822;
		background: #f1e8cf;
		border-radius: 999px;
		padding: 1px 9px;
	}

	.chip--ativo,
	.chip--ativo:hover {
		color: var(--pergaminho);
		background: var(--azul);
		border-color: var(--azul);
	}

	.chip--ativo .chip-num {
		color: var(--azul);
		background: var(--ouro-claro);
	}

	/* ─── Tabela ──────────────────────────────────────────────────── */
	.tabela {
		--colunas: minmax(190px, 1.15fr) minmax(200px, 1.35fr) 150px minmax(140px, 1fr) 336px;
		container-type: inline-size;
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 14px;
		/* clip (e não hidden) para o cabeçalho continuar grudado no topo ao rolar */
		overflow: clip;
		box-shadow: 0 14px 28px -22px rgba(15, 29, 61, 0.45);
	}

	.tabela--basica {
		--colunas: minmax(240px, 1.3fr) minmax(280px, 1.6fr) 190px 236px;
	}

	.linha {
		display: grid;
		grid-template-columns: var(--colunas);
		gap: 16px;
		align-items: center;
		padding: 14px 20px;
		border-bottom: 1px solid var(--linha);
	}

	.linha:not(.cabecalho, .esqueleto):hover {
		background: var(--hover-linha);
	}

	.linha--voce,
	.linha--voce:not(.cabecalho):hover {
		background: var(--linha-voce);
	}

	.cabecalho {
		position: sticky;
		top: 0;
		z-index: 1;
		background: var(--cabecalho-tabela);
		border-bottom-color: var(--borda-suave);
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}

	.direita {
		text-align: right;
	}

	.pilha {
		display: flex;
		flex-direction: column;
		gap: 3px;
		min-width: 0;
	}

	.nome {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		font-size: 17px;
		font-weight: 700;
	}

	.nome-link {
		color: var(--azul);
		text-decoration: underline;
		text-decoration-color: #cdb878;
		text-underline-offset: 3px;
	}

	.nome-link:hover {
		color: var(--ouro-texto);
	}

	.selo-voce {
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--ouro-claro);
		background: var(--azul);
		border-radius: 999px;
		padding: 2px 9px;
	}

	.secundario {
		font-size: 15px;
		color: var(--tinta-3);
		font-variant-numeric: tabular-nums;
	}

	.email {
		font-size: 16px;
		color: var(--tinta-2);
		overflow-wrap: anywhere;
	}

	.datas {
		display: grid;
		grid-template-columns: auto 1fr;
		gap: 2px 8px;
		font-size: 15px;
		color: var(--tinta-2);
		font-variant-numeric: tabular-nums;
	}

	.datas-rotulo {
		color: var(--ouro-texto);
		font-weight: 600;
	}

	.selos {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	.selo {
		font-size: 14px;
		font-weight: 600;
		color: var(--azul);
		background: var(--selo-area-bg);
		border: 1px solid var(--selo-area-bd);
		border-radius: 999px;
		padding: 3px 11px;
	}

	.selo--completo {
		font-weight: 700;
		color: var(--azul-noite);
		background: var(--ouro-claro);
		border-color: #cdb878;
	}

	.so-participante {
		font-size: 15px;
		color: #5e6272;
	}

	.acoes {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 6px;
	}

	.acoes-botoes {
		display: flex;
		gap: 8px;
		justify-content: flex-end;
	}

	.acoes-ajuda {
		font-size: 15px;
		color: var(--tinta-3);
		text-align: right;
	}

	/* Telas mais estreitas: os dois botões ficam um embaixo do outro. */
	@container (max-width: 1000px) {
		.tabela:not(.tabela--basica) .linha {
			grid-template-columns: minmax(180px, 1.2fr) minmax(200px, 1.45fr) 160px minmax(150px, 1fr) 150px;
		}

		.tabela:not(.tabela--basica) .acoes-botoes {
			flex-direction: column;
			align-self: stretch;
		}
	}

	/* ─── Botões ──────────────────────────────────────────────────── */
	.btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 10px;
		box-sizing: border-box;
		font: 600 15px 'Source Sans 3', sans-serif;
		border-radius: 999px;
		cursor: pointer;
		white-space: nowrap;
	}

	.btn:disabled {
		cursor: not-allowed;
		opacity: 0.6;
	}

	.btn--linha {
		height: 42px;
		padding: 0 14px;
	}

	.btn--ficha {
		color: var(--pergaminho);
		background: var(--azul);
		border: 1.5px solid var(--azul);
		text-decoration: none;
	}

	.btn--ficha:hover {
		background: var(--azul-noite);
	}

	.btn--contorno {
		color: var(--azul);
		background: transparent;
		border: 1.5px solid var(--azul);
	}

	.btn--contorno:hover:not(:disabled) {
		background: #eef1f7;
	}

	.btn--perigo {
		color: var(--vermelho);
		background: var(--pergaminho);
		border: 1.5px solid #e6bdb6;
	}

	.btn--perigo:hover {
		background: #f6e2df;
		border-color: var(--vermelho);
	}

	.btn--travado,
	.btn--travado:disabled {
		color: #7c7f8e;
		background: #f1ede3;
		border: 1.5px dashed var(--borda);
		opacity: 1;
	}

	.btn--ouro {
		height: 52px;
		padding: 0 28px;
		font-size: 17px;
		font-weight: 700;
		color: var(--azul-noite);
		background: linear-gradient(180deg, #d6be72, #b69e55);
		border: 1px solid #9c8642;
		box-shadow: 0 6px 16px -8px rgba(122, 101, 40, 0.9);
	}

	.btn--ouro:hover:not(:disabled) {
		background: var(--ouro);
	}

	/* ─── Estados da lista ────────────────────────────────────────── */
	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 18px 20px;
		font-size: 17px;
		color: var(--tinta-3);
		border-bottom: 1px solid var(--linha);
	}

	.spinner {
		flex: 0 0 auto;
		width: 18px;
		height: 18px;
		box-sizing: border-box;
		border-radius: 50%;
		border: 2.5px solid var(--borda-suave);
		border-top-color: var(--ouro);
		animation: girar 0.9s linear infinite;
	}

	@keyframes girar {
		to {
			transform: rotate(360deg);
		}
	}

	.esqueleto {
		padding: 18px 20px;
	}

	.pilha:has(.osso) {
		gap: 8px;
	}

	.osso {
		display: block;
		height: 12px;
		border-radius: 4px;
		background: #ede5d1;
	}

	.osso--medio {
		background: #f1eada;
	}

	.osso--claro {
		background: #f3eddf;
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
		max-width: 480px;
		font-size: 17px;
		color: var(--tinta-3);
	}

	.erro-icone {
		width: 56px;
		height: 56px;
		border-radius: 50%;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
		color: var(--vermelho);
		font-size: 28px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.vazio-arco {
		width: 110px;
		height: 120px;
		border: 1px solid #cdb878;
		border-bottom: none;
		border-radius: 50% 50% 0 0 / 40% 40% 0 0;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.vazio-arco img {
		width: 100px;
		opacity: 0.5;
	}

	/* ─── Rodapé e páginas ────────────────────────────────────────── */
	.rodape {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
		padding: 14px 20px;
		background: var(--cabecalho-tabela);
		font-size: 16px;
		color: var(--tinta-3);
	}

	.rodape strong {
		color: var(--tinta);
	}

	.paginas {
		display: flex;
		align-items: center;
		gap: 6px;
	}

	.pag {
		height: 42px;
		padding: 0 14px;
		font: 600 15px 'Source Sans 3', sans-serif;
		color: var(--azul);
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 8px;
		cursor: pointer;
	}

	.pag:hover:not(:disabled, .pag--atual) {
		border-color: var(--ouro);
	}

	.pag:disabled {
		color: #8a8c99;
		background: transparent;
		border-color: var(--borda-suave);
		cursor: default;
	}

	.pag--num {
		width: 42px;
		padding: 0;
	}

	.pag--atual {
		font-weight: 700;
		color: var(--pergaminho);
		background: var(--azul);
		border-color: var(--azul);
	}

	.pag-reticencias {
		padding: 0 4px;
	}

	/* ─── Aviso "Permissões salvas" ───────────────────────────────── */
	.aviso-salvo {
		position: fixed;
		right: 32px;
		bottom: 32px;
		z-index: 60;
		padding: 14px 20px;
		font-size: 17px;
		font-weight: 600;
		color: var(--pergaminho);
		background: var(--azul);
		border: 1px solid var(--ouro);
		border-radius: 12px;
		box-shadow: 0 20px 40px -20px rgba(8, 14, 32, 0.7);
	}
</style>
