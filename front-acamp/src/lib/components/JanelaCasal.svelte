<script lang="ts">
	/**
	 * Janela do casal (acampamento de Casais), em qualquer tela do site e do
	 * Painel. Para o cônjuge: confirmar que está ciente da inscrição feita pelo
	 * outro (sem isso, o casal não concorre ao sorteio). Para quem inscreveu:
	 * aviso de que o cônjuge recusou e as duas inscrições foram canceladas.
	 * Não dá para deixar para depois: a janela só fecha com uma resposta
	 * (decisão do usuário em 2026-11-26).
	 */
	import { tick } from 'svelte';
	import { auth } from '$lib/stores/auth.svelte';
	import { casal } from '$lib/stores/casal.svelte';
	import { marcarRecusaVista, responderCasal } from '$lib/services/casalService';
	import { mascaraCep } from '$lib/mascaras';
	import { estadoCivilNoGenero } from '$lib/estadoCivil';

	type Etapa = 'pergunta' | 'certeza' | 'confirmada' | 'recusada';

	let etapa = $state<Etapa>('pergunta');
	let enviando = $state(false);
	let erro = $state('');
	let errosCampo = $state<Record<string, string>>({});
	let cidade = $state('');
	let cep = $state('');
	/** O que a pessoa acabou de responder (a lista já foi recarregada). */
	let respondida = $state<{ nome: string; conjuge: string; estadoCivil: string | null } | null>(null);
	let janela: HTMLElement | undefined = $state();

	let pendente = $derived(casal.pendentes[0] ?? null);
	let recusa = $derived(pendente ? null : (casal.recusas[0] ?? null));
	let aberta = $derived(!!auth.user && (!!respondida || !!pendente || !!recusa));
	/** Estado civil no gênero de quem lê ("Casada" para uma mulher). */
	const noGenero = (titulo: string) => estadoCivilNoGenero(titulo, auth.user?.sex);

	// Foco na janela quando ela abre ou muda de assunto.
	$effect(() => {
		if (!aberta) return;
		void etapa;
		void pendente?.id;
		void recusa?.id;
		tick().then(() => janela?.querySelector<HTMLElement>('[data-foco]')?.focus());
	});

	const dia = (iso: string | null) => (iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}` : '');
	function periodo(inicio: string | null, fim: string | null): string {
		if (!inicio) return '';
		if (!fim || fim === inicio) return dia(inicio);
		return `de ${dia(inicio).slice(0, 5)} a ${dia(fim)}`;
	}

	function reiniciar() {
		etapa = 'pergunta';
		erro = '';
		errosCampo = {};
		cidade = '';
		cep = '';
	}

	async function responder(resposta: 'confirmar' | 'recusar') {
		if (!pendente || enviando) return;
		erro = '';
		errosCampo = {};
		if (resposta === 'confirmar' && pendente.precisaEndereco) {
			if (!cidade.trim()) errosCampo.city = 'Informe a cidade.';
			if (cep.replace(/\D/g, '').length !== 8) errosCampo.cep = cep ? 'O CEP tem 8 números.' : 'Informe o CEP.';
			if (Object.keys(errosCampo).length) return;
		}
		enviando = true;
		const atual = pendente;
		const r = await responderCasal(
			atual.id,
			resposta,
			atual.precisaEndereco && resposta === 'confirmar' ? { city: cidade.trim(), cep } : undefined
		);
		enviando = false;
		if (!r.ok) {
			erro = r.error ?? '';
			errosCampo = r.errors ?? {};
			return;
		}
		respondida = { nome: atual.atividade.nome, conjuge: atual.conjuge, estadoCivil: r.estadoCivil ?? null };
		etapa = resposta === 'confirmar' ? 'confirmada' : 'recusada';
		casal.respostas++;
		await casal.carregar();
		// Estado civil e endereço podem ter mudado: o resto do site lê do usuário.
		if (resposta === 'confirmar') auth.refreshUser();
	}

	function fecharResposta() {
		respondida = null;
		reiniciar();
	}

	async function entendi() {
		if (!recusa) return;
		const id = recusa.id;
		casal.recusas = casal.recusas.filter((r) => r.id !== id);
		await marcarRecusaVista(id);
	}

	// Esc só fecha a tela de resultado ou volta da pergunta "tem certeza?";
	// a pergunta do casal não fecha sem resposta.
	function teclado(e: KeyboardEvent) {
		if (e.key !== 'Escape') return;
		if (respondida) fecharResposta();
		else if (etapa === 'certeza' && !enviando) etapa = 'pergunta';
	}
</script>

{#if aberta}
	<div class="jc-fundo">
		<div class="jc-janela" role="dialog" aria-modal="true" aria-labelledby="jc-titulo" tabindex="-1" bind:this={janela} onkeydown={teclado}>
			<div class="jc-filete"></div>

			{#if respondida && etapa === 'confirmada'}
				<h2 id="jc-titulo" class="jc-titulo">Inscrição confirmada!</h2>
				<p>
					Você e <strong>{respondida.conjuge}</strong> estão inscritos como casal no <strong>{respondida.nome}</strong> e já
					concorrem ao sorteio.
				</p>
				{#if respondida.estadoCivil}
					<p class="jc-nota">Seu estado civil no cadastro agora é <strong>{noGenero(respondida.estadoCivil)}</strong>.</p>
				{/if}
				<div class="jc-acoes">
					<button type="button" class="jc-btn jc-btn--ouro" data-foco onclick={fecharResposta}>Fechar</button>
				</div>
			{:else if respondida && etapa === 'recusada'}
				<h2 id="jc-titulo" class="jc-titulo">Inscrição cancelada</h2>
				<p>
					A inscrição do casal no <strong>{respondida.nome}</strong> foi cancelada. <strong>{respondida.conjuge}</strong> vai ver
					um aviso quando entrar no site.
				</p>
				<div class="jc-acoes">
					<button type="button" class="jc-btn jc-btn--azul" data-foco onclick={fecharResposta}>Fechar</button>
				</div>
			{:else if pendente && etapa === 'certeza'}
				<h2 id="jc-titulo" class="jc-titulo">Cancelar a inscrição do casal?</h2>
				<p>
					A inscrição de vocês dois no <strong>{pendente.atividade.nome}</strong> será cancelada, e
					<strong>{pendente.conjuge}</strong> será avisado(a).
				</p>
				{#if erro}<p class="jc-erro" role="alert">{erro}</p>{/if}
				<div class="jc-acoes">
					<button type="button" class="jc-btn jc-btn--perigo" disabled={enviando} onclick={() => responder('recusar')}>
						{enviando ? 'Cancelando…' : 'Sim, cancelar a inscrição'}
					</button>
					<button type="button" class="jc-btn jc-btn--linha" data-foco onclick={() => (etapa = 'pergunta')}>Voltar</button>
				</div>
			{:else if pendente}
				<span class="jc-sobre">Acampamento de Casais</span>
				<h2 id="jc-titulo" class="jc-titulo">Confirme a inscrição do casal</h2>
				<p>
					<strong>{pendente.conjuge}</strong> inscreveu vocês dois como casal no <strong>{pendente.atividade.nome}</strong
					>{pendente.atividade.inicio ? ` (${periodo(pendente.atividade.inicio, pendente.atividade.fim)})` : ''}.
				</p>
				<p>
					Para a inscrição valer, você precisa confirmar que está ciente. Enquanto você não confirmar, o casal não concorre ao
					sorteio.
				</p>

				{#if pendente.estadoCivil}
					<p class="jc-nota">
						Seu cadastro está como <strong>{pendente.estadoCivil.atual ? noGenero(pendente.estadoCivil.atual) : 'sem estado civil'}</strong>.
						Ao confirmar, ele passa a ser <strong>{noGenero(pendente.estadoCivil.novo)}</strong>.
					</p>
				{/if}

				{#if pendente.precisaEndereco}
					<p class="jc-pede">Falta a sua cidade no cadastro (o sorteio é só para quem mora em Primavera do Leste ou Poxoréu):</p>
					<!-- Mesma ordem da janela "Confirmar inscrição": CEP e depois Cidade. -->
					<div class="jc-campos">
						<label class="jc-campo">
							<span>CEP</span>
							<input
								value={cep}
								oninput={(e) => {
									cep = mascaraCep(e.currentTarget.value);
									e.currentTarget.value = cep;
								}}
								inputmode="numeric"
								placeholder="00000-000"
								class:com-erro={errosCampo.cep}
								autocomplete="postal-code"
							/>
							{#if errosCampo.cep}<small>{errosCampo.cep}</small>{/if}
						</label>
						<label class="jc-campo">
							<span>Cidade</span>
							<input bind:value={cidade} class:com-erro={errosCampo.city} autocomplete="address-level2" />
							{#if errosCampo.city}<small>{errosCampo.city}</small>{/if}
						</label>
					</div>
				{/if}

				{#if erro}<p class="jc-erro" role="alert">{erro}</p>{/if}

				<div class="jc-acoes">
					<button type="button" class="jc-btn jc-btn--ouro" data-foco disabled={enviando} onclick={() => responder('confirmar')}>
						{enviando ? 'Confirmando…' : 'Sim, estou ciente'}
					</button>
					<button type="button" class="jc-btn jc-btn--linha" disabled={enviando} onclick={() => (etapa = 'certeza')}>
						Não estou ciente
					</button>
				</div>
			{:else if recusa}
				<span class="jc-sobre">Acampamento de Casais</span>
				<h2 id="jc-titulo" class="jc-titulo">A inscrição do casal foi cancelada</h2>
				<p>
					<strong>{recusa.conjuge ?? 'Seu cônjuge'}</strong> disse que não está ciente da inscrição de vocês no
					<strong>{recusa.atividade.nome}</strong>. Por isso, as duas inscrições foram canceladas.
				</p>
				<p class="jc-nota">Se foi um engano (por exemplo, o CPF errado), você pode se inscrever de novo.</p>
				<div class="jc-acoes">
					<button type="button" class="jc-btn jc-btn--azul" data-foco onclick={entendi}>Entendi</button>
				</div>
			{/if}
		</div>
	</div>
{/if}

<style>
	.jc-fundo {
		position: fixed;
		inset: 0;
		z-index: 1000;
		display: grid;
		place-items: center;
		padding: 24px;
		background: rgba(17, 29, 58, 0.55);
	}
	.jc-janela {
		position: relative;
		width: min(540px, 100%);
		max-height: calc(100vh - 48px);
		overflow: auto;
		padding: 34px 36px 26px;
		background: #fffdf8;
		border: 1px solid #e3d8bc;
		border-radius: 14px;
		box-shadow: 0 24px 60px rgba(17, 29, 58, 0.35);
		color: #2b2f3d;
		font-family: 'Source Sans 3', system-ui, sans-serif;
		font-size: 16px;
		line-height: 1.55;
		outline: none;
	}
	/* A mesma faixa de vitral da janela "Confirmar inscrição", de ponta a ponta. */
	.jc-filete {
		position: absolute;
		top: 0;
		left: 0;
		right: 0;
		height: 6px;
		background: linear-gradient(90deg, #1b2f5e, #1f6b6e 40%, #b69e55 70%, #9b2c2c);
	}
	.jc-sobre {
		display: block;
		margin-bottom: 4px;
		color: #8a7434;
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.12em;
		text-transform: uppercase;
	}
	.jc-titulo {
		margin: 0 0 14px;
		color: #1b2f5e;
		font-family: 'Cormorant Garamond', Georgia, serif;
		font-size: 30px;
		font-weight: 600;
		line-height: 1.15;
	}
	p {
		margin: 0 0 12px;
	}
	strong {
		color: #1b2f5e;
	}
	.jc-nota {
		padding: 10px 14px;
		background: #f6f1e3;
		border-left: 3px solid #b69e55;
		border-radius: 6px;
		font-size: 15px;
	}
	.jc-pede {
		margin-top: 16px;
		font-size: 15px;
	}
	.jc-campos {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 12px;
		margin-bottom: 12px;
	}
	.jc-campo {
		display: flex;
		flex-direction: column;
		gap: 4px;
		font-size: 14px;
		font-weight: 600;
		color: #1b2f5e;
	}
	.jc-campo input {
		padding: 9px 12px;
		border: 1px solid #cfc3a3;
		border-radius: 8px;
		font: inherit;
		font-weight: 400;
		color: #2b2f3d;
		background: #fff;
	}
	.jc-campo input:focus {
		outline: 2px solid #b69e55;
		outline-offset: 1px;
	}
	.jc-campo input.com-erro {
		border-color: #9b2c2c;
	}
	.jc-campo small,
	.jc-erro {
		color: #9b2c2c;
		font-weight: 600;
	}
	.jc-erro {
		font-size: 15px;
	}
	.jc-acoes {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;
		margin-top: 20px;
	}
	.jc-btn {
		padding: 11px 20px;
		border-radius: 999px;
		border: 1px solid transparent;
		font: inherit;
		font-weight: 700;
		cursor: pointer;
	}
	.jc-btn:disabled {
		opacity: 0.6;
		cursor: default;
	}
	.jc-btn:focus-visible {
		outline: 2px solid #1b2f5e;
		outline-offset: 2px;
	}
	.jc-btn--ouro {
		background: #b69e55;
		color: #1b2f5e;
	}
	.jc-btn--ouro:hover:not(:disabled) {
		background: #a68e48;
	}
	.jc-btn--azul {
		background: #1b2f5e;
		color: #fff;
	}
	.jc-btn--perigo {
		background: #9b2c2c;
		color: #fff;
	}
	.jc-btn--linha {
		background: transparent;
		border-color: #cfc3a3;
		color: #1b2f5e;
	}
	.jc-btn--linha:hover:not(:disabled) {
		background: #f6f1e3;
	}
</style>
