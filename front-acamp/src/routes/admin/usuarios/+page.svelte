<script lang="ts">
	import { onMount, tick } from 'svelte';
	import {
		getUsers,
		deactivateUser,
		getPermissoes,
		salvarPermissoes,
		type ItemPermissao
	} from '$lib/services/userService';
	import type { User } from '$lib/types/models';
	import { auth } from '$lib/stores/auth.svelte';
	import { pode } from '$lib/permissoes';
	import { mascaraCpf, mascaraTelefone } from '$lib/mascaras';
	import logoAsas from '$lib/imgs/logo-asas.png';

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
	let busca = $state('');
	let filtro: 'todos' | 'painel' | 'part' = $state('todos');
	let pagina = $state(1);

	// Ignora acentos e maiúsculas; no CPF compara só os dígitos.
	const semAcento = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

	let encontrados = $derived.by(() => {
		const termo = semAcento(busca.trim());
		if (!termo) return users;
		const digitos = termo.replace(/\D/g, '');
		return users.filter(
			(u) =>
				semAcento(u.name).includes(termo) ||
				semAcento(u.email ?? '').includes(termo) ||
				(digitos !== '' && (u.cpf ?? '').replace(/\D/g, '').includes(digitos))
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

	// ─── Janelas ──────────────────────────────────────────────────────────
	let modal: { tipo: 'desativar' | 'permissoes'; alvo: User } | null = $state(null);

	// Enquanto salva ou desativa, a janela não fecha.
	function fechar() {
		if (!desativando && estadoModal !== 'salvando') modal = null;
	}

	function cliqueNoFundo(e: MouseEvent) {
		if (e.target === e.currentTarget) fechar();
	}

	/**
	 * Prende o foco dentro da janela, fecha com Esc e devolve o foco para o
	 * botão que a abriu. `seletorInicial` diz onde o foco começa.
	 */
	function janela(seletorInicial: string) {
		return (no: HTMLElement) => {
			const anterior = document.activeElement as HTMLElement | null;
			tick().then(() => (no.querySelector<HTMLElement>(seletorInicial) ?? no).focus());

			function teclado(e: KeyboardEvent) {
				if (e.key === 'Escape') {
					e.preventDefault();
					fechar();
					return;
				}
				if (e.key !== 'Tab') return;
				const focaveis = [...no.querySelectorAll<HTMLElement>('button, input, [tabindex="0"]')].filter(
					(el) => !(el as HTMLButtonElement).disabled && el.offsetParent !== null
				);
				if (!focaveis.length) return;
				const primeiro = focaveis[0];
				const ultimo = focaveis[focaveis.length - 1];
				if (e.shiftKey && document.activeElement === primeiro) {
					e.preventDefault();
					ultimo.focus();
				} else if (!e.shiftKey && document.activeElement === ultimo) {
					e.preventDefault();
					primeiro.focus();
				}
			}

			document.addEventListener('keydown', teclado);
			return () => {
				document.removeEventListener('keydown', teclado);
				anterior?.focus();
			};
		};
	}

	// ─── Desativar ────────────────────────────────────────────────────────
	let desativando = $state(false);
	let erroDesativar = $state('');

	function abrirDesativar(user: User) {
		erroDesativar = '';
		modal = { tipo: 'desativar', alvo: user };
	}

	async function confirmarDesativar() {
		if (!modal) return;
		const alvo = modal.alvo;
		desativando = true;
		erroDesativar = '';
		try {
			const res = await deactivateUser(alvo.id);
			if (!res.success) {
				erroDesativar = res.error ?? 'Não foi possível desativar a conta.';
				return;
			}
			users = users.filter((u) => u.id !== alvo.id);
			desativando = false;
			modal = null;
			avisar(`Conta de ${alvo.name.split(' ')[0]} desativada`);
		} catch {
			erroDesativar = 'Não foi possível desativar a conta. Verifique sua conexão e tente de novo.';
		} finally {
			desativando = false;
		}
	}

	// ─── Permissões ───────────────────────────────────────────────────────
	let itensModal: ItemPermissao[] = $state([]);
	let marcadas: string[] = $state([]);
	let estadoModal: 'carregando' | 'normal' | 'salvando' = $state('normal');
	let erroPermissoes = $state('');

	async function abrirPermissoes(user: User) {
		modal = { tipo: 'permissoes', alvo: user };
		erroPermissoes = '';
		estadoModal = 'carregando';
		try {
			const res = await getPermissoes(user.id);
			if (res.error) {
				erroPermissoes = res.error;
			} else {
				itensModal = res.catalogo ?? [];
				marcadas = res.ativas ?? [];
			}
		} catch {
			erroPermissoes = 'Não foi possível carregar as permissões. Verifique sua conexão e tente de novo.';
		}
		estadoModal = 'normal';
	}

	// A própria pessoa não pode tirar de si a permissão de gerenciar permissões.
	const travada = (chave: string) =>
		modal?.alvo.id === auth.user?.id && (chave === 'permissoes' || chave === 'usuarios');

	const baseDe = (chave: string) => itensModal.find((i) => i.chave === chave)?.depende_de;

	// Ligar uma tela liga também a tela por onde ela se abre; desligar essa tela
	// desliga as que dependem dela (menos as travadas).
	function ligar(chaves: string[]) {
		const s = new Set(marcadas);
		for (const c of chaves) {
			s.add(c);
			const base = baseDe(c);
			if (base) s.add(base);
		}
		marcadas = itensModal.map((i) => i.chave).filter((c) => s.has(c));
	}

	function desligar(chaves: string[]) {
		const sair = new Set(chaves.filter((c) => !travada(c)));
		for (const i of itensModal) {
			if (i.depende_de && sair.has(i.depende_de) && !travada(i.chave)) sair.add(i.chave);
		}
		marcadas = marcadas.filter((c) => !sair.has(c));
	}

	function alternar(chave: string) {
		if (travada(chave) || estadoModal !== 'normal') return;
		erroPermissoes = '';
		if (marcadas.includes(chave)) desligar([chave]);
		else ligar([chave]);
	}

	function alternarArea(chaves: string[]) {
		if (estadoModal !== 'normal') return;
		erroPermissoes = '';
		if (chaves.every((c) => marcadas.includes(c))) desligar(chaves);
		else ligar(chaves);
	}

	function ativarTudo() {
		erroPermissoes = '';
		marcadas = itensModal.map((i) => i.chave);
	}

	function desativarTudo() {
		erroPermissoes = '';
		marcadas = itensModal.map((i) => i.chave).filter(travada);
	}

	/** Áreas da janela, cada uma com as telas principais e as que se abrem por elas. */
	let areasModal = $derived.by(() => {
		const areas: { nome: string; chaves: string[]; itens: { item: ItemPermissao; filhos: ItemPermissao[] }[] }[] = [];
		for (const item of itensModal) {
			let area = areas.find((a) => a.nome === item.area);
			if (!area) areas.push((area = { nome: item.area, chaves: [], itens: [] }));
			area.chaves.push(item.chave);
			const pai = item.depende_de && area.itens.find((i) => i.item.chave === item.depende_de);
			if (pai) pai.filhos.push(item);
			else area.itens.push({ item, filhos: [] });
		}
		return areas;
	});

	// Distribui as áreas nas duas colunas para equilibrar a altura (pelo número de telas).
	let colunasModal = $derived.by(() => {
		const colunas: (typeof areasModal)[] = [[], []];
		const tamanho = [0, 0];
		for (const area of areasModal) {
			const i = tamanho[1] < tamanho[0] ? 1 : 0;
			colunas[i].push(area);
			tamanho[i] += area.chaves.length + 1;
		}
		return colunas.filter((c) => c.length);
	});

	async function salvar() {
		if (!modal) return;
		const alvo = modal.alvo;
		estadoModal = 'salvando';
		erroPermissoes = '';
		try {
			const res = await salvarPermissoes(alvo.id, marcadas);
			if (res.error) {
				erroPermissoes = res.error;
				estadoModal = 'normal';
				return;
			}
			users = users.map((u) => (u.id === alvo.id ? { ...u, telas_liberadas: res.ativas ?? [] } : u));
			// Mudou as próprias permissões: atualiza o menu na hora.
			if (alvo.id === auth.user?.id) await auth.verifyToken();
			estadoModal = 'normal';
			modal = null;
			avisar('Permissões salvas');
		} catch {
			erroPermissoes = 'Não foi possível salvar as permissões. Verifique sua conexão e tente de novo.';
			estadoModal = 'normal';
		}
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
							<span>{user.name}</span>
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

{#if modal?.tipo === 'desativar'}
	{@const alvo = modal.alvo}
	<div class="fundo" role="presentation" onclick={cliqueNoFundo}>
		<div
			class="janela janela--desativar"
			role="dialog"
			aria-modal="true"
			aria-labelledby="titulo-desativar"
			tabindex="-1"
			{@attach janela('.js-cancelar')}
		>
			<div class="faixa faixa--perigo"></div>
			<div class="janela-corpo">
				<h2 id="titulo-desativar">Desativar a conta de {alvo.name}?</h2>
				<div class="cartao-pessoa">
					<strong>{alvo.name}</strong>
					<span>CPF {alvo.cpf ? mascaraCpf(alvo.cpf) : '—'} · {alvo.email}</span>
				</div>
				<ul class="consequencias">
					<li>A pessoa some desta lista.</li>
					<li>Ela não consegue mais entrar no site com este CPF.</li>
					<li><strong>Essa ação não pode ser desfeita.</strong></li>
				</ul>
				{#if erroDesativar}
					<div class="caixa-erro" role="alert"><span class="caixa-erro-icone">!</span><span>{erroDesativar}</span></div>
				{/if}
				<div class="janela-botoes">
					<button type="button" class="btn btn--contorno btn--grande js-cancelar" onclick={fechar} disabled={desativando}>Cancelar</button>
					<button type="button" class="btn btn--vermelho btn--grande" onclick={confirmarDesativar} disabled={desativando}>
						{#if desativando}<span class="spinner spinner--botao spinner--claro"></span>Desativando...{:else}Desativar conta{/if}
					</button>
				</div>
			</div>
		</div>
	</div>
{/if}

{#if modal?.tipo === 'permissoes'}
	{@const alvo = modal.alvo}
	{@const bloqueado = estadoModal !== 'normal' || itensModal.length === 0}
	<div class="fundo" role="presentation" onclick={cliqueNoFundo}>
		<div
			class="janela janela--permissoes"
			role="dialog"
			aria-modal="true"
			aria-labelledby="titulo-permissoes"
			tabindex="-1"
			{@attach janela('h2')}
		>
			<div class="faixa faixa--vitral"></div>
			<div class="perm-topo">
				<div class="perm-topo-textos">
					<h2 id="titulo-permissoes" tabindex="-1">Permissões de {alvo.name}</h2>
					<p>Marque as telas do painel que esta pessoa pode ver e usar.</p>
				</div>
				<div class="perm-atalhos">
					<div class="perm-atalhos-botoes">
						<button type="button" class="btn btn--atalho" onclick={ativarTudo} disabled={bloqueado}>Ativar tudo</button>
						<button type="button" class="btn btn--atalho" onclick={desativarTudo} disabled={bloqueado}>Desativar tudo</button>
					</div>
					{#if estadoModal !== 'carregando' && itensModal.length}
						<span class="perm-contador"><strong>{marcadas.length}</strong> de {itensModal.length} telas marcadas</span>
					{/if}
				</div>
			</div>

			<div class="perm-corpo">
				{#if erroPermissoes}
					<div class="caixa-erro" role="alert"><span class="caixa-erro-icone">!</span><span>{erroPermissoes}</span></div>
				{/if}

				{#if estadoModal === 'carregando'}
					<div class="carregando carregando--janela" role="status"><span class="spinner"></span>Carregando...</div>
					<div class="perm-colunas" aria-hidden="true">
						<div class="osso-bloco" style="height:380px"></div>
						<div class="perm-coluna">
							<div class="osso-bloco" style="height:182px"></div>
							<div class="osso-bloco" style="height:182px"></div>
						</div>
					</div>
				{:else}
					<div class="perm-colunas">
						{#each colunasModal as coluna}
							<div class="perm-coluna">
								{#each coluna as area}
									{@const n = area.chaves.filter((c) => marcadas.includes(c)).length}
									{@const todas = n === area.chaves.length}
									<div class="area">
										<button
											type="button"
											class="area-cabecalho"
											role="checkbox"
											aria-checked={todas ? 'true' : n ? 'mixed' : 'false'}
											disabled={estadoModal !== 'normal'}
											onclick={() => alternarArea(area.chaves)}
										>
											<span class="caixa caixa--area" class:caixa--on={n > 0} aria-hidden="true">{todas ? '✓' : n ? '–' : ''}</span>
											<span class="area-nome">{area.nome}</span>
											<span class="area-contagem">{n} de {area.chaves.length}</span>
										</button>
										<div class="area-itens">
											{#each area.itens as { item, filhos }}
												{@render tela(item)}
												{#if filhos.length}
													<div class="filhos">
														<span class="filhos-rotulo">Dentro de {item.nome}:</span>
														{#each filhos as filho}{@render tela(filho)}{/each}
													</div>
												{/if}
											{/each}
										</div>
									</div>
								{/each}
							</div>
						{/each}
					</div>
				{/if}
			</div>

			<div class="perm-rodape">
				{#if estadoModal !== 'carregando' && itensModal.length && marcadas.length === 0}
					<div class="aviso-ambar" role="status">
						<span class="aviso-ponto"></span>
						<span>Sem nenhuma tela marcada, <strong>{alvo.name.split(' ')[0]}</strong> deixa de entrar no painel e passa a ser só participante.</span>
					</div>
				{:else}
					<span class="perm-dica">Se nenhuma tela ficar marcada, a pessoa deixa de entrar no painel e passa a ser só participante.</span>
				{/if}
				<div class="janela-botoes">
					<button type="button" class="btn btn--contorno btn--grande" onclick={fechar} disabled={estadoModal === 'salvando'}>Cancelar</button>
					<button type="button" class="btn btn--ouro btn--grande btn--salvar" onclick={salvar} disabled={bloqueado}>
						{#if estadoModal === 'salvando'}<span class="spinner spinner--botao"></span>Salvando...{:else}Salvar{/if}
					</button>
				</div>
			</div>
		</div>
	</div>
{/if}

{#snippet tela(item: ItemPermissao)}
	{@const marcada = marcadas.includes(item.chave)}
	{@const presa = travada(item.chave)}
	<label class="item" class:item--travada={presa}>
		<input
			type="checkbox"
			class="item-nativa"
			checked={marcada}
			disabled={presa || estadoModal !== 'normal'}
			onchange={() => alternar(item.chave)}
		/>
		<span class="caixa" class:caixa--on={marcada} class:caixa--travada={presa} aria-hidden="true">{marcada ? '✓' : ''}</span>
		<span class="item-textos">
			<span class="item-nome">{item.nome}</span>
			<span class="item-desc">{item.descricao}</span>
			{#if presa}<span class="item-trava">Travada · Você não pode tirar esta de si mesmo.</span>{/if}
		</span>
	</label>
{/snippet}

{#if aviso}
	<div class="aviso-salvo" role="status">✓ {aviso}</div>
{/if}

<style>
	/* Identidade da São Miguel Arcanjo (a mesma do site e do login). */
	.pessoas,
	.fundo,
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

	.pessoas :is(button, input):focus-visible,
	.fundo :is(button, input, h2):focus-visible {
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
		--colunas: minmax(180px, 1.2fr) minmax(200px, 1.45fr) 160px minmax(150px, 1fr) 236px;
		container-type: inline-size;
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 14px;
		/* clip (e não hidden) para o cabeçalho continuar grudado no topo ao rolar */
		overflow: clip;
		box-shadow: 0 14px 28px -22px rgba(15, 29, 61, 0.45);
	}

	.tabela--basica {
		--colunas: minmax(240px, 1.3fr) minmax(280px, 1.6fr) 190px 210px;
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
		padding: 0 16px;
	}

	.btn--grande {
		height: 52px;
		padding: 0 24px;
		font-size: 17px;
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

	.btn--vermelho {
		font-weight: 700;
		color: var(--pergaminho);
		background: var(--vermelho);
		border: 1px solid #7e2020;
		box-shadow: 0 6px 16px -8px rgba(155, 44, 44, 0.9);
	}

	.btn--vermelho:hover:not(:disabled) {
		background: #842424;
	}

	.btn--atalho {
		height: 44px;
		padding: 0 16px;
		color: var(--azul);
		background: var(--pergaminho);
		border: 1px solid var(--borda);
	}

	.btn--atalho:hover:not(:disabled) {
		border-color: var(--ouro);
		background: var(--cabecalho-tabela);
	}

	.btn--salvar {
		min-width: 140px;
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

	.carregando--janela {
		padding: 0;
		border: none;
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

	.spinner--botao {
		width: 16px;
		height: 16px;
		border-color: rgba(15, 29, 61, 0.2);
		border-top-color: var(--azul-noite);
	}

	.spinner--claro {
		border-color: rgba(255, 253, 248, 0.35);
		border-top-color: var(--pergaminho);
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

	/* ─── Janelas ─────────────────────────────────────────────────── */
	.fundo {
		position: fixed;
		inset: 0;
		z-index: 50;
		display: flex;
		align-items: flex-start;
		justify-content: center;
		padding: 40px 24px;
		background: rgba(15, 29, 61, 0.58);
		overflow: auto;
	}

	.janela {
		width: 100%;
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 18px;
		overflow: hidden;
		box-shadow: 0 40px 80px -30px rgba(8, 14, 32, 0.7);
	}

	.janela:focus {
		outline: none;
	}

	.janela--desativar {
		max-width: 580px;
		margin-top: 80px;
	}

	.janela--permissoes {
		max-width: 1000px;
	}

	.faixa {
		height: 6px;
	}

	.faixa--perigo {
		background: linear-gradient(90deg, #9b2c2c, #b69e55);
	}

	.faixa--vitral {
		background: linear-gradient(90deg, #1b2f5e, #1f6b6e 40%, #b69e55 70%, #9b2c2c);
	}

	.janela h2 {
		margin: 0;
		font: 600 32px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.janela-corpo {
		display: flex;
		flex-direction: column;
		gap: 18px;
		padding: 30px 32px 28px;
	}

	.cartao-pessoa {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 14px 16px;
		background: #fff;
		border: 1px solid var(--borda-suave);
		border-radius: 10px;
		font-size: 17px;
	}

	.cartao-pessoa span {
		font-size: 15px;
		color: var(--tinta-3);
		overflow-wrap: anywhere;
	}

	.consequencias {
		margin: 0;
		padding: 0 0 0 20px;
		display: flex;
		flex-direction: column;
		gap: 6px;
		font-size: 17px;
		line-height: 1.45;
		color: var(--tinta-2);
	}

	.consequencias strong {
		color: #842424;
	}

	.janela-botoes {
		display: flex;
		justify-content: flex-end;
		gap: 10px;
		flex: 0 0 auto;
	}

	.caixa-erro {
		display: flex;
		gap: 10px;
		align-items: center;
		padding: 12px 14px;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
		border-radius: 10px;
		color: #842424;
		font-size: 16px;
		font-weight: 600;
	}

	.caixa-erro-icone {
		flex: 0 0 24px;
		height: 24px;
		border-radius: 50%;
		background: var(--vermelho);
		color: var(--pergaminho);
		font-size: 15px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	/* ─── Janela de permissões ────────────────────────────────────── */
	.perm-topo {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 20px;
		padding: 26px 32px 18px;
		border-bottom: 1px solid var(--borda-suave);
	}

	.perm-topo-textos {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.perm-topo h2 {
		font-size: 34px;
	}

	.perm-topo p {
		margin: 0;
		font-size: 17px;
		color: var(--tinta-3);
	}

	.perm-atalhos {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 8px;
		flex: 0 0 auto;
	}

	.perm-atalhos-botoes {
		display: flex;
		gap: 8px;
	}

	.perm-contador {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.perm-contador strong {
		color: var(--tinta);
	}

	.perm-corpo {
		display: flex;
		flex-direction: column;
		gap: 16px;
		padding: 20px 32px 8px;
		max-height: calc(100vh - 330px);
		min-height: 200px;
		overflow-y: auto;
	}

	.perm-colunas {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 16px;
		align-items: start;
	}

	.perm-coluna {
		display: flex;
		flex-direction: column;
		gap: 16px;
	}

	.osso-bloco {
		border-radius: 12px;
		background: #f3eddf;
	}

	.area {
		background: #fff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		overflow: hidden;
	}

	.area-cabecalho {
		display: flex;
		align-items: center;
		gap: 12px;
		width: 100%;
		padding: 12px 14px;
		background: var(--cabecalho-tabela);
		border: none;
		border-bottom: 1px solid var(--linha);
		cursor: pointer;
		text-align: left;
		font-family: inherit;
	}

	.area-cabecalho:disabled {
		cursor: default;
	}

	.area-nome {
		flex: 1;
		font: 600 22px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.area-contagem {
		font-size: 14px;
		font-weight: 700;
		color: #6b5822;
		background: #f1e8cf;
		border-radius: 999px;
		padding: 2px 10px;
	}

	.area-itens {
		display: flex;
		flex-direction: column;
		padding: 6px;
	}

	.item {
		position: relative;
		display: flex;
		gap: 12px;
		align-items: flex-start;
		padding: 10px;
		border-radius: 8px;
		cursor: pointer;
	}

	.item:hover {
		background: var(--cabecalho-tabela);
	}

	.item--travada {
		cursor: not-allowed;
	}

	/* A caixa nativa fica invisível, mas é ela que recebe o foco e o clique. */
	.item-nativa {
		position: absolute;
		opacity: 0;
		width: 1px;
		height: 1px;
		margin: 0;
	}

	.item-nativa:focus-visible + .caixa {
		outline: 4px solid var(--anel-foco);
	}

	.caixa {
		flex: 0 0 24px;
		height: 24px;
		box-sizing: border-box;
		margin-top: 1px;
		border-radius: 6px;
		border: 2px solid var(--campo-borda);
		background: #fff;
		color: var(--pergaminho);
		font-size: 16px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.caixa--area {
		flex-basis: 26px;
		height: 26px;
		margin: 0;
		font-size: 17px;
	}

	.caixa--on {
		background: var(--azul);
		border-color: var(--azul);
	}

	.caixa--travada {
		background: var(--trava);
		border-color: var(--trava);
	}

	.item-textos {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}

	.item-nome {
		font-size: 17px;
		font-weight: 600;
	}

	.item-desc {
		font-size: 15px;
		line-height: 1.35;
		color: var(--tinta-3);
	}

	.item-trava {
		margin-top: 4px;
		align-self: flex-start;
		font-size: 15px;
		font-weight: 600;
		color: #6b5822;
		background: #f1e8cf;
		border-radius: 6px;
		padding: 2px 8px;
	}

	.filhos {
		display: flex;
		flex-direction: column;
		margin: 0 0 4px 21px;
		padding-left: 13px;
		border-left: 2px solid var(--borda-suave);
	}

	.filhos-rotulo {
		padding: 2px 10px 4px;
		font-size: 14px;
		font-weight: 600;
		color: var(--ouro-texto);
	}

	.perm-rodape {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 20px;
		padding: 16px 32px 24px;
		border-top: 1px solid var(--linha);
	}

	.perm-dica {
		flex: 1;
		font-size: 15px;
		color: var(--tinta-3);
	}

	.aviso-ambar {
		flex: 1;
		display: flex;
		gap: 10px;
		align-items: center;
		padding: 10px 14px;
		background: var(--aviso-bg);
		border: 1px solid var(--aviso-bd);
		border-radius: 10px;
		font-size: 16px;
		color: var(--aviso-tx);
	}

	.aviso-ponto {
		flex: 0 0 8px;
		height: 8px;
		border-radius: 50%;
		background: var(--aviso-ponto);
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
