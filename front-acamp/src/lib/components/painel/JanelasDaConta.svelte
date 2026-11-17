<script lang="ts">
	/**
	 * Janelas "Desativar a conta" e "Permissões" de uma pessoa. As mesmas na
	 * lista de Pessoas e acessos e na ficha da pessoa, com as mesmas regras:
	 * ninguém desativa a própria conta nem tira de si a permissão de gerenciar
	 * permissões, e a última pessoa que cuida das permissões não é desativada.
	 */
	import { onMount, tick } from 'svelte';
	import { deactivateUser, getPermissoes, salvarPermissoes, type ItemPermissao } from '$lib/services/userService';
	import { auth } from '$lib/stores/auth.svelte';
	import { mascaraCpf } from '$lib/mascaras';

	let {
		tipo,
		alvo,
		onfechar,
		ondesativado,
		onpermissoes
	}: {
		tipo: 'desativar' | 'permissoes';
		alvo: { id: number; name: string; cpf?: string; email?: string | null };
		onfechar: () => void;
		ondesativado?: () => void;
		/** Telas que ficaram ligadas depois de salvar. */
		onpermissoes?: (ativas: string[]) => void;
	} = $props();

	// Enquanto salva ou desativa, a janela não fecha.
	function fechar() {
		if (!desativando && estadoModal !== 'salvando') onfechar();
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

	async function confirmarDesativar() {
		desativando = true;
		erroDesativar = '';
		try {
			const res = await deactivateUser(alvo.id);
			if (!res.success) {
				erroDesativar = res.error ?? 'Não foi possível desativar a conta.';
				return;
			}
			desativando = false;
			ondesativado?.();
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

	onMount(async () => {
		if (tipo !== 'permissoes') return;
		estadoModal = 'carregando';
		try {
			const res = await getPermissoes(alvo.id);
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
	});

	// A própria pessoa não pode tirar de si a permissão de gerenciar permissões.
	const travada = (chave: string) =>
		alvo.id === auth.user?.id && (chave === 'permissoes' || chave === 'usuarios');

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
		estadoModal = 'salvando';
		erroPermissoes = '';
		try {
			const res = await salvarPermissoes(alvo.id, marcadas);
			if (res.error) {
				erroPermissoes = res.error;
				estadoModal = 'normal';
				return;
			}
			// Mudou as próprias permissões: atualiza o menu na hora.
			if (alvo.id === auth.user?.id) await auth.verifyToken();
			estadoModal = 'normal';
			onpermissoes?.(res.ativas ?? []);
		} catch {
			erroPermissoes = 'Não foi possível salvar as permissões. Verifique sua conexão e tente de novo.';
			estadoModal = 'normal';
		}
	}
</script>

{#if tipo === 'desativar'}
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
{:else}
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

<style>
	/* Identidade da São Miguel Arcanjo (a mesma do site e do login). */
	.fundo {
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

	.fundo :is(button, input, h2):focus-visible {
		outline: 4px solid var(--anel-foco);
		outline-offset: 0;
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
</style>
