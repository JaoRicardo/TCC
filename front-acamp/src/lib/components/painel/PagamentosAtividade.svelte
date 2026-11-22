<script lang="ts">
	/**
	 * Janela lateral "Pagamentos" de Inscritos e Sorteios (handoff
	 * design_handoff_painel_evento, seção 4). A organização registra quem pagou
	 * direto com ela (dinheiro ou Pix) e desfaz um registro feito por engano.
	 * Vale para acampamento e evento; as regras ficam no servidor (PagamentoService).
	 */
	import { apiFetch } from '$lib/api';
	import { auth } from '$lib/stores/auth.svelte';
	import Janela from '$lib/components/painel/Janela.svelte';
	import {
		dm,
		dma,
		FORMAS,
		linhaDoPago,
		naFolga,
		paraData,
		reais,
		SITUACAO,
		type ResumoPagamento
	} from '$lib/pagamentos';
	import '$lib/styles/formularios.css';

	interface Inscricao {
		id: number;
		subscription_type: string;
		pagamento: ResumoPagamento;
		user: { name: string; cpf: string };
	}

	let {
		atividade,
		inscricoes,
		onfechar,
		onmudou
	}: {
		/** A atividade como `/activities/{id}` manda (nome, tipo, prazos). */
		atividade: any;
		inscricoes: Inscricao[];
		onfechar: () => void;
		/** Depois de marcar ou desfazer: recarregar a lista de inscritos. */
		onmudou: () => Promise<void>;
	} = $props();

	type Filtro = 'naoPagos' | 'pagos' | 'todos';

	const agora = new Date();
	const hojeIso = `${agora.getFullYear()}-${String(agora.getMonth() + 1).padStart(2, '0')}-${String(agora.getDate()).padStart(2, '0')}`;
	let evento = $derived(atividade?.tipo === 'Evento');

	let filtro = $state<Filtro>('naoPagos');
	let busca = $state('');
	let aviso = $state('');

	// Confirmações
	let marcando = $state<Inscricao | null>(null);
	let desfazendo = $state<Inscricao | null>(null);
	let forma = $state<'' | 'dinheiro' | 'pix'>('');
	let data = $state(hojeIso);
	let observacao = $state('');
	let tentouMarcar = $state(false);
	let salvando = $state(false);
	let erroServidor = $state(false);
	let errosServidor = $state<{ forma?: string; data?: string }>({});

	/** Só entra quem tem pagamento (atividade paga): ativos, pagos e quem perdeu a vaga. */
	let comPagamento = $derived(inscricoes.filter((i) => i.pagamento?.situacao));

	let contagem = $derived({
		naoPagos: comPagamento.filter((i) => i.pagamento.situacao === 'nao_pago').length,
		pagos: comPagamento.filter((i) => i.pagamento.situacao === 'pago').length,
		todos: comPagamento.length
	});

	let totais = $derived({
		recebido: comPagamento
			.filter((i) => i.pagamento.situacao === 'pago' && !i.pagamento.canceladaEm)
			.reduce((s, i) => s + i.pagamento.valor, 0),
		aReceber: comPagamento.filter((i) => i.pagamento.situacao === 'nao_pago').reduce((s, i) => s + i.pagamento.valor, 0)
	});

	let prazoDaAtividade = $derived.by(() => {
		const campista = atividade?.prazoPagamentoParticipante;
		const servo = atividade?.prazoPagamentoServo;
		if (evento) return campista ? dma(campista) : 'Sem prazo';
		const partes = [
			campista && Number(atividade?.taxaParticipante) > 0 ? `Campistas ${dm(campista)}` : '',
			servo && Number(atividade?.taxaServo) > 0 ? `Servos ${dm(servo)}` : ''
		].filter(Boolean);
		return partes.length ? partes.join(' · ') : 'Sem prazo';
	});

	const semAcento = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

	/** Ordem: na folga, precisam pagar, ainda não precisam, pagos, perderam a vaga; por nome. */
	function grupo(p: ResumoPagamento) {
		if (p.situacao === 'nao_pago') return naFolga(p, agora) ? 0 : p.precisaPagar ? 1 : 2;
		return p.situacao === 'pago' ? 3 : 4;
	}

	let lista = $derived.by(() => {
		const termo = semAcento(busca.trim());
		const digitos = busca.replace(/\D/g, '');
		return comPagamento
			.filter((i) => {
				const s = i.pagamento.situacao;
				if (filtro === 'naoPagos' && s !== 'nao_pago') return false;
				if (filtro === 'pagos' && s !== 'pago') return false;
				if (!termo) return true;
				return semAcento(i.user.name).includes(termo) || (digitos.length >= 3 && i.user.cpf.replace(/\D/g, '').includes(digitos));
			})
			.sort((a, b) => grupo(a.pagamento) - grupo(b.pagamento) || a.user.name.localeCompare(b.user.name, 'pt-BR'));
	});

	const tipoDe = (i: Inscricao) => (evento ? 'Participante' : i.subscription_type);

	function prazoDe(p: ResumoPagamento) {
		if (p.situacao === 'nao_pago' && !p.precisaPagar) return evento ? 'Depois de concluir a inscrição' : 'Depois do formulário';
		return p.prazo ? dma(p.prazo) : 'Sem prazo';
	}

	function notaDe(p: ResumoPagamento): { texto: string; tom: 'azul' | 'ambar' } | null {
		if (p.situacao === 'pago' && p.canceladaEm)
			return { texto: `Inscrição cancelada pela organização em ${dma(p.canceladaEm)}. Veja se é preciso devolver o valor.`, tom: 'ambar' };
		if (p.situacao !== 'nao_pago') return null;
		if (!p.precisaPagar)
			return {
				texto: `Ainda não precisa pagar: o prazo vale depois que ${evento ? 'concluir a inscrição' : 'enviar o formulário'}.`,
				tom: 'azul'
			};
		if (naFolga(p, agora) && p.prazo && p.limite)
			return { texto: `O prazo terminou em ${dma(p.prazo)}. A vaga sai em ${dma(p.limite)} se o pagamento não for registrado até lá.`, tom: 'ambar' };
		return null;
	}

	// ─── Marcar como pago ─────────────────────────────────────────────────
	function abrirMarcar(i: Inscricao) {
		marcando = i;
		forma = '';
		data = hojeIso;
		observacao = '';
		tentouMarcar = false;
		erroServidor = false;
		errosServidor = {};
		aviso = '';
	}

	let erroForma = $derived(tentouMarcar ? (errosServidor.forma ?? (!forma ? 'Escolha a forma de pagamento.' : '')) : '');
	let erroData = $derived(
		tentouMarcar
			? (errosServidor.data ??
					(!data ? 'Informe a data do pagamento.' : data > hojeIso ? 'A data do pagamento não pode ser depois de hoje.' : ''))
			: ''
	);

	async function confirmarMarcar() {
		tentouMarcar = true;
		errosServidor = {};
		erroServidor = false;
		if (!forma || !data || data > hojeIso || !marcando) return;
		salvando = true;
		try {
			const res = await apiFetch(`/admin/inscricoes/${marcando.id}/pagamento`, {
				method: 'POST',
				body: JSON.stringify({ forma, data, observacao })
			});
			const r = await res.json().catch(() => ({}));
			if (!res.ok) {
				if (r.errors?.forma || r.errors?.data) errosServidor = { forma: r.errors.forma, data: r.errors.data };
				else erroServidor = true;
				return;
			}
			const nome = marcando.user.name;
			await onmudou();
			marcando = null;
			aviso = `Pagamento de ${nome} registrado.`;
		} catch {
			erroServidor = true;
		} finally {
			salvando = false;
		}
	}

	// ─── Desfazer ─────────────────────────────────────────────────────────
	function abrirDesfazer(i: Inscricao) {
		desfazendo = i;
		erroServidor = false;
		aviso = '';
	}

	/** O que acontece depois de desfazer, pela data. */
	function avisoDoDesfazer(i: Inscricao): { texto: string; tom: 'azul' | 'ambar' | 'vermelho' } | null {
		const p = i.pagamento;
		const nome = i.user.name.split(' ')[0];
		if (!p.precisaPagar || !p.prazo || !p.limite) return null;
		if (agora <= paraData(p.prazo)) return { texto: `Depois de desfazer, ${nome} vai precisar pagar até ${dma(p.prazo)}.`, tom: 'azul' };
		if (agora <= paraData(p.limite))
			return {
				texto: `O prazo já terminou: se o pagamento não for registrado de novo até ${dma(p.limite)}, ${nome} perde a vaga.`,
				tom: 'ambar'
			};
		return { texto: `O prazo e os 2 dias de folga já passaram: ao desfazer, ${nome} perde a vaga na hora.`, tom: 'vermelho' };
	}

	async function confirmarDesfazer() {
		if (!desfazendo) return;
		salvando = true;
		erroServidor = false;
		try {
			const res = await apiFetch(`/admin/inscricoes/${desfazendo.id}/pagamento`, { method: 'DELETE' });
			if (!res.ok) {
				erroServidor = true;
				return;
			}
			const nome = desfazendo.user.name;
			await onmudou();
			desfazendo = null;
			aviso = `Pagamento de ${nome} desfeito.`;
		} catch {
			erroServidor = true;
		} finally {
			salvando = false;
		}
	}

	let eu = $derived((auth.user?.name ?? '').split(' ').slice(0, 2).join(' '));
</script>

<Janela idTitulo="t-pagamentos" largura={640} lateral faixa="var(--ouro)" travada={!!marcando || !!desfazendo} {onfechar}>
	<div class="pg">
		<div class="pg-cabeca">
			<div class="pg-topo">
				<div class="pg-titulos">
					<span class="rot">{atividade?.nome}</span>
					<h2 id="t-pagamentos" class="pg-titulo">Pagamentos</h2>
				</div>
				<button type="button" class="pg-fechar" aria-label="Fechar pagamentos" onclick={onfechar}>✕</button>
			</div>
			<dl class="pg-numeros">
				<div class="num num-ok"><dt>Recebido</dt><dd>{reais(totais.recebido)}</dd></div>
				<div class="num num-esp"><dt>A receber</dt><dd>{reais(totais.aReceber)}</dd></div>
				<div class="num num-prazo"><dt>Prazo</dt><dd>{prazoDaAtividade}</dd></div>
			</dl>
			<div class="pg-busca">
				<label for="busca-pag" class="sr">Buscar por nome ou CPF</label>
				<span class="lupa" aria-hidden="true"></span>
				<input id="busca-pag" type="search" class="fm-campo" bind:value={busca} placeholder="Buscar por nome ou CPF" />
			</div>
			<div role="radiogroup" aria-label="Mostrar" class="pg-filtros">
				{#each [['naoPagos', 'Não pagos'], ['pagos', 'Pagos'], ['todos', 'Todos']] as [k, nome]}
					<button type="button" role="radio" aria-checked={filtro === k} class="filtro" class:sel={filtro === k} onclick={() => (filtro = k as Filtro)}>
						{nome} <span class="filtro-n">{contagem[k as Filtro]}</span>
					</button>
				{/each}
			</div>
		</div>

		<div class="pg-lista">
			{#if lista.length === 0}
				<div class="pg-vazio">
					{#if busca.trim()}
						<p class="vazio-titulo">Ninguém encontrado.</p>
						<p>Nenhuma inscrição com “{busca.trim()}” neste filtro. Tente o filtro “Todos”.</p>
						<button type="button" class="fm-link" onclick={() => (busca = '')}>Limpar a busca</button>
					{:else if filtro === 'pagos'}
						<p class="vazio-titulo">Nenhum pagamento registrado ainda.</p>
						<p>Os pagamentos aparecem aqui assim que alguém da equipe marcar.</p>
					{:else if filtro === 'naoPagos'}
						<p class="vazio-titulo">Ninguém com pagamento pendente.</p>
						<p>Todas as inscrições ativas estão pagas.</p>
					{:else}
						<p class="vazio-titulo">Nenhuma inscrição com valor a pagar.</p>
					{/if}
				</div>
			{/if}

			{#each lista as i (i.id)}
				{@const p = i.pagamento}
				{@const nota = notaDe(p)}
				<article class="pessoa" class:pessoa-folga={naFolga(p, agora)} aria-label={i.user.name}>
					<div class="pessoa-topo">
						<div class="pessoa-nome">
							<strong>{i.user.name}</strong>
							<span>{tipoDe(i)} · CPF {i.user.cpf}</span>
						</div>
						{#if p.situacao}<span class="pill {SITUACAO[p.situacao].tom}"><i></i>{SITUACAO[p.situacao].texto}</span>{/if}
					</div>
					<dl class="pessoa-dados">
						<div><dt>Valor</dt><dd class="valor">{reais(p.valor)}</dd></div>
						<div><dt>Prazo</dt><dd>{prazoDe(p)}</dd></div>
					</dl>
					{#if nota}<p class="fm-aviso fm-aviso--pequeno fm-aviso--{nota.tom}">{nota.texto}</p>{/if}
					{#if p.situacao === 'perdeu_vaga' && p.prazo}
						<p class="fm-aviso fm-aviso--pequeno aviso-vermelho">Pagamento não registrado até {dma(p.prazo)}.{p.perdeuEm ? ` A vaga saiu em ${dma(p.perdeuEm)}.` : ''}</p>
					{/if}
					{#if p.situacao === 'pago'}
						<div class="pessoa-pago">
							<span>{linhaDoPago(p)}</span>
							{#if p.observacao}<span class="obs">“{p.observacao}”</span>{/if}
						</div>
					{/if}
					{#if p.situacao !== 'perdeu_vaga'}
						<div class="pessoa-acoes">
							{#if p.situacao === 'nao_pago'}
								<button type="button" class="fm-btn fm-btn--contorno" onclick={() => abrirMarcar(i)}>Marcar como pago</button>
							{:else}
								<button type="button" class="fm-link desfazer" onclick={() => abrirDesfazer(i)}>Desfazer pagamento</button>
							{/if}
						</div>
					{/if}
				</article>
			{/each}

			<p class="pg-rodape">
				Aparecem as inscrições ativas de quem tem valor a pagar, e quem perdeu a vaga por falta de pagamento. “Marcar como pago” vale mesmo antes de a pessoa precisar pagar.
			</p>
		</div>

		{#if aviso}
			<div class="pg-ok" role="status">
				<span>✓ {aviso}</span>
				<button type="button" aria-label="Fechar aviso" onclick={() => (aviso = '')}>✕</button>
			</div>
		{/if}
	</div>
</Janela>

{#if marcando}
	{@const p = marcando.pagamento}
	<Janela idTitulo="t-marcar" faixa="linear-gradient(90deg,#2e7d4f,#b69e55)" travada={salvando} onfechar={() => (marcando = null)}>
		<h2 id="t-marcar" class="fm-janela-titulo">Marcar como pago</h2>
		<div class="conf-alvo">
			<span class="conf-nome"><strong>{marcando.user.name}</strong><span>{tipoDe(marcando)} · {atividade?.nome}</span></span>
			<span class="conf-valor">{reais(p.valor)}</span>
		</div>
		{#if naFolga(p, agora) && p.prazo && p.limite}
			<p class="fm-aviso fm-aviso--ambar">O prazo terminou em {dma(p.prazo)}, mas a vaga só sai em {dma(p.limite)}. Registrando agora, a vaga fica garantida.</p>
		{/if}
		<fieldset class="conf-grupo">
			<legend class="conf-rotulo">Forma de pagamento <span class="req" aria-hidden="true">*</span></legend>
			<div role="radiogroup" aria-label="Forma de pagamento" aria-invalid={!!erroForma} class="conf-radios">
				{#each Object.entries(FORMAS) as [k, nome]}
					<button type="button" role="radio" aria-checked={forma === k} class="radio" class:sel={forma === k} onclick={() => (forma = k as 'dinheiro' | 'pix')}>
						<span class="bolinha" aria-hidden="true"></span>{nome}
					</button>
				{/each}
			</div>
			{#if erroForma}<p class="err"><i>!</i>{erroForma}</p>{/if}
		</fieldset>
		<div class="conf-campo curto">
			<label for="c-data" class="conf-rotulo">Data do pagamento <span class="req" aria-hidden="true">*</span></label>
			<input id="c-data" type="date" max={hojeIso} class="fm-campo" class:erro={!!erroData} bind:value={data} aria-invalid={!!erroData} aria-describedby="c-data-ajuda c-data-erro" />
			{#if erroData}<p id="c-data-erro" class="err"><i>!</i>{erroData}</p>{/if}
			<p id="c-data-ajuda" class="ajuda">Hoje já vem preenchido. Mude se a pessoa pagou em outro dia.</p>
		</div>
		<div class="conf-campo">
			<label for="c-obs" class="conf-rotulo">Observação <span class="opc">opcional</span></label>
			<textarea id="c-obs" class="fm-campo texto" rows="3" bind:value={observacao} placeholder="Ex.: pagou na secretaria; comprovante no WhatsApp da organização."></textarea>
		</div>
		<p class="ajuda">
			Fica registrado “Pago em {data ? dma(data) : '{data}'} · {forma ? FORMAS[forma] : '{forma}'} · registrado por {eu}”, aqui e no histórico da ficha da pessoa. A pessoa vê “Pago em {data ? dma(data) : '{data}'}” no site.
		</p>
		{#if erroServidor}
			<p role="alert" class="fm-aviso aviso-vermelho">Não foi possível registrar o pagamento. Nada foi salvo: tente de novo.</p>
		{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={() => (marcando = null)} disabled={salvando}>Cancelar</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={confirmarMarcar} disabled={salvando} aria-busy={salvando}>
				{salvando ? 'Registrando...' : 'Confirmar pagamento'}
			</button>
		</div>
	</Janela>
{/if}

{#if desfazendo}
	{@const aviso2 = avisoDoDesfazer(desfazendo)}
	<Janela idTitulo="t-desfazer" faixa="#9b2c2c" travada={salvando} onfechar={() => (desfazendo = null)}>
		<h2 id="t-desfazer" class="fm-janela-titulo">Desfazer o pagamento?</h2>
		<p class="conf-texto">
			O registro <strong>“{linhaDoPago(desfazendo.pagamento)}”</strong> será apagado, e a inscrição de {desfazendo.user.name} volta para “Não pago”. Use quando a marcação foi feita por engano.
		</p>
		{#if aviso2}
			<p class="fm-aviso {aviso2.tom === 'vermelho' ? 'aviso-vermelho' : `fm-aviso--${aviso2.tom}`}">{aviso2.texto}</p>
		{/if}
		<p class="ajuda">A mudança fica no histórico da ficha da pessoa, com o seu nome.</p>
		{#if erroServidor}
			<p role="alert" class="fm-aviso aviso-vermelho">Não foi possível desfazer o pagamento. Nada mudou: tente de novo.</p>
		{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={() => (desfazendo = null)} disabled={salvando}>Manter o pagamento</button>
			<button type="button" class="fm-btn fm-btn--perigo-cheio fm-btn--grande" onclick={confirmarDesfazer} disabled={salvando} aria-busy={salvando}>
				{salvando ? 'Desfazendo...' : 'Desfazer pagamento'}
			</button>
		</div>
	</Janela>
{/if}

<style>
	.pg {
		position: relative;
		display: flex;
		flex-direction: column;
		flex: 1;
		min-height: 0;
		background: #f7f2e7;
		font-family: 'Source Sans 3', system-ui, sans-serif;
		color: #1e2233;
	}
	.pg-cabeca {
		display: flex;
		flex-direction: column;
		gap: 16px;
		padding: 20px 28px 18px;
		background: #fffdf8;
		border-bottom: 1px solid #e3d8bc;
		box-shadow:
			0 3px 0 -1px #fffdf8,
			0 4px 0 -1px #b69e55;
	}
	.pg-topo {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 16px;
	}
	.pg-titulos {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.rot {
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: #7a6528;
	}
	.pg-titulo {
		margin: 0;
		font: 600 36px/1.05 'Cormorant Garamond', serif;
		color: #1b2f5e;
	}
	.pg-fechar {
		flex: 0 0 44px;
		height: 44px;
		border-radius: 50%;
		border: 1px solid #d9cca6;
		background: #fffdf8;
		color: #1b2f5e;
		font-size: 20px;
		cursor: pointer;
	}
	.pg-fechar:hover {
		background: #fbf6e6;
	}
	.pg-numeros {
		margin: 0;
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 10px;
	}
	.num {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 10px 14px;
		border-radius: 10px;
	}
	.num dt {
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.08em;
		text-transform: uppercase;
	}
	.num dd {
		margin: 0;
		font-size: 20px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
	}
	.num-ok {
		background: #e4f1e8;
		border: 1px solid #b9d9c4;
		color: #1b5236;
	}
	.num-esp {
		background: #fbf0d6;
		border: 1px solid #ebd39c;
		color: #6a480c;
	}
	.num-prazo {
		background: #fff;
		border: 1px solid #e3d8bc;
	}
	.num-prazo dt {
		color: #7a6528;
	}
	.num-prazo dd {
		font-size: 16px;
		line-height: 1.3;
		font-weight: 600;
		color: #1e2233;
	}
	.pg-busca {
		position: relative;
	}
	.pg-busca .fm-campo {
		padding-left: 44px;
	}
	.lupa {
		position: absolute;
		left: 16px;
		top: 50%;
		transform: translateY(-50%);
		width: 14px;
		height: 14px;
		margin-top: -2px;
		border: 2px solid #4f5468;
		border-radius: 50%;
	}
	.sr {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip: rect(0 0 0 0);
	}
	.pg-filtros {
		display: flex;
		gap: 4px;
		padding: 4px;
		background: #efe7d3;
		border-radius: 999px;
		align-self: flex-start;
	}
	.filtro {
		height: 40px;
		padding: 0 16px;
		font: 600 15px 'Source Sans 3', sans-serif;
		color: #4f5468;
		background: transparent;
		border: none;
		border-radius: 999px;
		cursor: pointer;
	}
	.filtro.sel {
		font-weight: 700;
		color: #1b2f5e;
		background: #fffdf8;
		box-shadow: 0 1px 3px rgba(15, 29, 61, 0.25);
	}
	.filtro-n {
		font-weight: 700;
		color: #7a6528;
	}
	.pg-lista {
		flex: 1;
		overflow: auto;
		padding: 16px 28px 80px;
		display: flex;
		flex-direction: column;
		gap: 12px;
	}
	.pg-vazio {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 8px;
		padding: 40px 16px;
		color: #4f5468;
	}
	.pg-vazio p {
		margin: 0;
		font-size: 16px;
	}
	.pg-vazio .vazio-titulo {
		font: 600 26px 'Cormorant Garamond', serif;
		color: #1b2f5e;
	}
	.pessoa {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 16px 18px;
		background: #fffdf8;
		border: 1px solid #e3d8bc;
		border-radius: 12px;
		box-shadow: 0 10px 20px -18px rgba(15, 29, 61, 0.5);
	}
	.pessoa.pessoa-folga {
		border-color: #ebd39c;
		box-shadow: 0 0 0 1px #ebd39c;
	}
	.pessoa-topo {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 12px;
	}
	.pessoa-nome {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}
	.pessoa-nome strong {
		font-size: 18px;
	}
	.pessoa-nome span {
		font-size: 15px;
		color: #4f5468;
	}
	.pessoa-dados {
		margin: 0;
		display: flex;
		gap: 24px;
		flex-wrap: wrap;
		font-size: 16px;
	}
	.pessoa-dados div {
		display: flex;
		gap: 6px;
	}
	.pessoa-dados dt {
		color: #4f5468;
	}
	.pessoa-dados dd {
		margin: 0;
		font-weight: 600;
	}
	.pessoa-dados .valor {
		font-weight: 700;
		font-variant-numeric: tabular-nums;
	}
	.pessoa-pago {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding-top: 8px;
		border-top: 1px solid #efe7d3;
		font-size: 16px;
		font-weight: 600;
		color: #1b5236;
	}
	.pessoa-pago .obs {
		font-size: 15px;
		font-weight: 400;
		color: #2b3045;
	}
	.pessoa-acoes {
		display: flex;
		justify-content: flex-end;
		gap: 10px;
	}
	.desfazer {
		color: #9b2c2c;
	}
	.pg-rodape {
		margin: 4px 0 0;
		font-size: 14px;
		line-height: 1.4;
		color: #4f5468;
	}
	.pg-ok {
		position: absolute;
		left: 28px;
		right: 28px;
		bottom: 20px;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding: 14px 18px;
		background: #1b5236;
		color: #fffdf8;
		border-radius: 12px;
		font-size: 17px;
		font-weight: 600;
		box-shadow: 0 18px 40px -16px rgba(15, 29, 61, 0.6);
	}
	.pg-ok button {
		width: 30px;
		height: 30px;
		border: none;
		border-radius: 50%;
		background: rgba(255, 253, 248, 0.15);
		color: #fffdf8;
		cursor: pointer;
	}

	/* Selos de situação (mesmos tons das outras telas). */
	.pill {
		flex: 0 0 auto;
		display: inline-flex;
		align-items: center;
		gap: 8px;
		font-size: 15px;
		font-weight: 600;
		border-radius: 8px;
		padding: 4px 10px;
		border: 1px solid;
		white-space: nowrap;
	}
	.pill i {
		width: 8px;
		height: 8px;
		border-radius: 50%;
	}
	.ok {
		color: #1b5236;
		background: #e4f1e8;
		border-color: #b9d9c4;
	}
	.ok i {
		background: #2e7d4f;
	}
	.esp {
		color: #6a480c;
		background: #fbf0d6;
		border-color: #ebd39c;
	}
	.esp i {
		background: #c08a1e;
	}
	.can {
		color: #842424;
		background: #f6e2df;
		border-color: #e6bdb6;
	}
	.can i {
		background: #b33a3a;
	}
	.aviso-vermelho {
		background: #f6e2df;
		border: 1px solid #e6bdb6;
		color: #842424;
	}
	.aviso-vermelho::before {
		background: #9b2c2c;
	}
	p.fm-aviso {
		margin: 0;
	}

	/* Confirmações */
	.conf-alvo {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 16px;
		padding: 14px 18px;
		background: #fff;
		border: 1px solid #e3d8bc;
		border-radius: 12px;
	}
	.conf-nome {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.conf-nome strong {
		font-size: 18px;
	}
	.conf-nome span {
		font-size: 15px;
		color: #4f5468;
	}
	.conf-valor {
		font-size: 22px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
	}
	.conf-grupo {
		margin: 0;
		padding: 0;
		border: none;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.conf-rotulo {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 0;
		font-size: 16px;
		font-weight: 600;
		color: #1e2233;
	}
	legend.conf-rotulo {
		margin-bottom: 10px;
	}
	.conf-radios {
		display: flex;
		gap: 10px;
	}
	.radio {
		/* Mesma largura para todos, conteúdo centralizado; nunca encolhe nem quebra. */
		display: inline-flex;
		flex: 0 0 auto;
		min-width: 140px;
		justify-content: center;
		white-space: nowrap;
		align-items: center;
		gap: 10px;
		height: 52px;
		padding: 0 20px;
		font: 600 17px 'Source Sans 3', sans-serif;
		color: #1e2233;
		background: #fff;
		border: 1.5px solid #d9cca6;
		border-radius: 999px;
		cursor: pointer;
	}
	.radio.sel {
		background: #fbf6e6;
		border-color: #b69e55;
	}
	.bolinha {
		flex: 0 0 20px;
		height: 20px;
		box-sizing: border-box;
		border-radius: 50%;
		border: 2px solid #a39363;
		background: #fff;
	}
	.radio.sel .bolinha {
		border: 6px solid #1b2f5e;
	}
	.conf-campo {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.conf-campo.curto {
		max-width: 280px;
	}
	.fm-campo.erro {
		border-color: #9b2c2c;
	}
	.fm-campo.texto {
		height: auto;
		min-height: 88px;
		padding: 12px 16px;
		line-height: 1.5;
		resize: vertical;
	}
	.req {
		color: #9b2c2c;
	}
	.opc {
		font-size: 13px;
		font-weight: 600;
		color: #4f5468;
		background: #efebe1;
		border-radius: 999px;
		padding: 2px 9px;
	}
	.ajuda {
		margin: 0;
		font-size: 15px;
		line-height: 1.4;
		color: #4f5468;
	}
	.conf-texto {
		margin: 0;
		font-size: 17px;
		line-height: 1.5;
		color: #2b3045;
	}
	.err {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		margin: 0;
		font-size: 15px;
		font-weight: 600;
		color: #842424;
	}
	.err i {
		flex: 0 0 20px;
		height: 20px;
		border-radius: 50%;
		background: #9b2c2c;
		color: #fffdf8;
		font: 700 13px/20px 'Source Sans 3', sans-serif;
		font-style: normal;
		text-align: center;
	}
</style>
