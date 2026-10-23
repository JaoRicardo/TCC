<script lang="ts">
	/**
	 * Ficha de inscrição do acampamento, no formato de formulário (uma pergunta por
	 * cartão), com as cores do projeto.
	 *
	 * Responde quem foi sorteado, indicado ou está como suplente. No fim vem a etapa
	 * de pagamento, que só é cobrada de quem tem vaga — suplente não paga.
	 */
	import { onMount } from 'svelte';
	import { page } from '$app/stores';
	import { auth } from '$lib/stores/auth.svelte';
	import {
		getFormulario,
		salvarFormulario,
		iniciarPagamento,
		type FormularioData,
		type PerguntaFormulario
	} from '$lib/services/formularioService';

	const preRegId = $page.params.preRegId as string;

	let form: FormularioData | null = $state(null);
	let loading = $state(true);
	let error = $state('');
	let saving = $state(false);
	let saveError = $state('');
	let saveSuccess = $state(false);
	let pagando = $state(false);
	let pagamentoError = $state('');

	// respostas por pergunta: para multipla_escolha guardamos um JSON de ids selecionados
	let answers: Record<number, string> = $state({});

	$effect(() => {
		if (!auth.isLoading && !auth.isAuthenticated) {
			window.location.href = '/login';
		}
	});

	const todasPerguntas: PerguntaFormulario[] = $derived.by(() =>
		(form?.sections ?? []).flatMap((s) => s.questions)
	);

	/** De qual pergunta cada opção é — usado para resolver as perguntas condicionais. */
	const perguntaDaOpcao = $derived.by(() => {
		const mapa: Record<number, number> = {};
		for (const q of todasPerguntas) {
			for (const opt of q.options) mapa[opt.id] = q.id;
		}
		return mapa;
	});

	/**
	 * Perguntas condicionais (ex.: "Qual remédio?") só aparecem quando a pergunta
	 * da qual elas dependem foi respondida com a opção certa. A checagem é feita na
	 * resposta daquela pergunta específica — e não em "alguma resposta do
	 * formulário", que fazia perguntas aparecerem por coincidência de id.
	 */
	function perguntaVisivel(q: PerguntaFormulario, visitadas: Set<number> = new Set()): boolean {
		if (!q.dependsOnOptionId) return true;

		// Cadeia de dependências mal cadastrada (A depende de B que depende de A)
		// não pode travar a tela.
		if (visitadas.has(q.id)) return true;
		visitadas.add(q.id);

		const idMae = perguntaDaOpcao[q.dependsOnOptionId];
		// Opção que não existe mais: não dá para avaliar a condição, então mostra.
		if (!idMae) return true;

		const mae = todasPerguntas.find((p) => p.id === idMae);
		if (mae && !perguntaVisivel(mae, visitadas)) return false;

		return respostaContemOpcao(answers[idMae], q.dependsOnOptionId);
	}

	/**
	 * O valor de uma resposta como texto. Precisa existir porque `bind:value` num
	 * `<input type="number">` guarda um número, e o resto do código trata resposta
	 * como string (`.trim()`, JSON de múltipla escolha, comparação com id de opção).
	 */
	function texto(valor: unknown): string {
		return String(valor ?? '').trim();
	}

	function respostaContemOpcao(resposta: unknown, optionId: number): boolean {
		const valor = texto(resposta);
		if (!valor) return false;
		if (valor === String(optionId)) return true;
		return parseMulti(valor).includes(optionId);
	}

	function parseMulti(raw: unknown): number[] {
		if (!raw) return [];
		try {
			const arr = JSON.parse(String(raw));
			return Array.isArray(arr) ? arr.map(Number) : [];
		} catch {
			return [];
		}
	}

	const perguntasVisiveis = $derived(todasPerguntas.filter((q) => perguntaVisivel(q)));

	/** Quantas das perguntas que estão valendo já foram respondidas. */
	const respondidas = $derived(perguntasVisiveis.filter((q) => texto(answers[q.id]) !== '').length);

	const progresso = $derived(
		perguntasVisiveis.length === 0
			? 0
			: Math.round((respondidas / perguntasVisiveis.length) * 100)
	);

	async function carregar() {
		const res = await getFormulario(preRegId);
		if (res.error) {
			error = res.error;
			loading = false;
			return;
		}
		form = res.data ?? null;
		const initial: Record<number, string> = {};
		for (const secao of form?.sections ?? []) {
			for (const q of secao.questions) {
				initial[q.id] = q.answer ?? '';
			}
		}
		answers = initial;
		loading = false;
	}

	onMount(carregar);

	function toggleMultiOption(questionId: number, optionId: number) {
		const atual = parseMulti(answers[questionId]);
		const idx = atual.indexOf(optionId);
		if (idx >= 0) atual.splice(idx, 1);
		else atual.push(optionId);
		answers[questionId] = JSON.stringify(atual);
	}

	function formatarPrazo(iso: string): string {
		const [data, hora] = iso.split('T');
		const [y, m, d] = data.split('-');
		return `${d}/${m}/${y} às ${hora}`;
	}

	function diasRestantes(iso: string): number {
		return Math.ceil((new Date(iso).getTime() - Date.now()) / 86400000);
	}

	function formatarValor(v: number): string {
		return v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
	}

	async function handleSubmit(e: Event) {
		e.preventDefault();
		saving = true;
		saveError = '';
		saveSuccess = false;

		// Conferência no navegador antes de mandar, para o erro aparecer na hora e
		// apontando a pergunta — o backend valida de novo, ele é quem decide.
		const faltando = perguntasVisiveis.find((q) => q.isRequired && !texto(answers[q.id]));
		if (faltando) {
			saveError = `A pergunta "${faltando.text}" é obrigatória.`;
			saving = false;
			document
				.getElementById(`q-${faltando.id}`)
				?.scrollIntoView({ behavior: 'smooth', block: 'center' });
			return;
		}

		const idsVisiveis = new Set(perguntasVisiveis.map((q) => q.id));
		const payload = Object.entries(answers)
			.filter(([question_id]) => idsVisiveis.has(Number(question_id)))
			// String(): o campo numérico chega como number do bind:value.
			.map(([question_id, answer]) => ({
				question_id: Number(question_id),
				answer: String(answer ?? '')
			}));

		const res = await salvarFormulario(preRegId, payload);
		if (res.success) {
			saveSuccess = true;
			await carregar();
			window.scrollTo({ top: 0, behavior: 'smooth' });
		} else {
			saveError = res.error || 'Erro ao salvar.';
		}
		saving = false;
	}

	async function handlePagar() {
		pagando = true;
		pagamentoError = '';
		const res = await iniciarPagamento(preRegId);
		if (res.url) {
			window.location.href = res.url;
			return;
		}
		pagamentoError = res.error || 'Não foi possível iniciar o pagamento.';
		pagando = false;
	}

	function formatDate(d?: string | null) {
		if (!d) return '—';
		const [y, m, day] = d.split('-');
		return `${day}/${m}/${y}`;
	}
</script>

<svelte:head>
	<title>{form?.atividade?.nome ?? 'Formulário de Inscrição'} | Formulário</title>
</svelte:head>

<div class="fundo">
	<div class="folha">
		<a href="/dashboard/formularios" class="voltar">&larr; Meus formulários</a>

		{#if loading}
			<div class="cartao estado">
				<div class="spinner"></div>
				<p>Carregando formulário...</p>
			</div>
		{:else if error}
			<div class="cartao estado erro">{error}</div>
		{:else if form && !form.formulario_disponivel}
			<!-- Estado explicado: antes qualquer caso caía num "ainda não está
			     disponível" genérico, e acampamento sem perguntas abria um formulário
			     vazio que podia ser enviado em branco. -->
			<div class="cartao estado">
				<div class="estado-icone">
					{form.status === 'Prazo Perdido' ? '⏳' : form.formulario_montado ? '🔒' : '📝'}
				</div>
				<h3>
					{#if form.status === 'Prazo Perdido'}
						Prazo encerrado
					{:else if !form.formulario_montado}
						Formulário ainda não disponível
					{:else}
						Formulário indisponível
					{/if}
				</h3>
				<p>{form.motivo_indisponivel}</p>
				<p class="estado-status">
					Situação da sua inscrição: <strong>{form.status}</strong>
				</p>
				<a href="/dashboard/formularios" class="botao botao--claro">Voltar</a>
			</div>
		{:else if form}
			<!-- Cabeçalho do formulário, com a faixa verde do projeto -->
			<header class="cartao cabecalho">
				<div class="faixa"></div>
				<div class="cabecalho-corpo">
					<h1>{form.atividade?.nome ?? 'Formulário de Inscrição'}</h1>
					<p class="cabecalho-sub">
						Ficha de inscrição{form.atividade?.categoria
							? ` · ${form.atividade.categoria}`
							: ''}{form.atividade?.local ? ` · ${form.atividade.local}` : ''}
					</p>

					<div class="selos">
						<span class="selo selo--status">{form.status}</span>
						<!-- Para quem está como Suplente o status não diz que a ficha foi
						     entregue, então o selo de enviado ainda faz falta. -->
						{#if form.respondido && form.status !== 'Formulário Enviado'}
							<span class="selo selo--ok">✓ Enviado</span>
						{/if}
					</div>

					{#if form.prazo && !form.prazo.vencido}
						<p class="aviso" class:aviso--urgente={diasRestantes(form.prazo.limite) <= 2}>
							<strong>Prazo: {formatarPrazo(form.prazo.limite)}</strong><br />
							{#if form.respondido}
								Sua ficha já foi entregue. Dá para revisar e reenviar até essa data.
							{:else if form.prazo.eh_suplente}
								Você é suplente e também precisa enviar a ficha até essa data: só é chamado para
								uma vaga quem já entregou. A taxa não é cobrada de suplentes.
							{:else}
								Se a ficha não for enviada até essa data, sua vaga será passada para o próximo
								suplente da fila.
							{/if}
						</p>
					{/if}

					<p class="obrigatorias"><span class="asterisco">*</span> Indica pergunta obrigatória</p>
				</div>
			</header>

			{#if saveSuccess}
				<div class="cartao faixa-aviso faixa-aviso--ok">
					✓ Formulário enviado com sucesso!
				</div>
			{:else if form.respondido}
				<div class="cartao faixa-aviso faixa-aviso--ok">
					✓ Você já enviou este formulário.
					{#if form.editavel}Pode revisar e reenviar se precisar corrigir algo.{/if}
				</div>
			{/if}

			{#if !form.editavel}
				<div class="cartao faixa-aviso faixa-aviso--erro">{form.motivo_indisponivel}</div>
			{/if}

			{#if saveError}
				<div class="cartao faixa-aviso faixa-aviso--erro">{saveError}</div>
			{/if}

			<!-- Confirmação dos dados de cadastro -->
			<section class="cartao">
				<h2 class="secao-titulo">Confira seus dados</h2>
				<p class="secao-dica">
					Esses dados já estão no sistema. Se algo estiver errado, corrija no seu perfil antes de
					enviar.
				</p>
				<div class="grade-dados">
					<div><span>Nome</span><strong>{form.perfil.nome}</strong></div>
					<div><span>CPF</span><strong>{form.perfil.cpf}</strong></div>
					<div><span>Celular</span><strong>{form.perfil.telefone}</strong></div>
					<div><span>E-mail</span><strong>{form.perfil.email}</strong></div>
					<div><span>Nascimento</span><strong>{formatDate(form.perfil.data_nascimento)}</strong></div>
					<div>
						<span>Sexo</span><strong>{form.perfil.sexo === 'M' ? 'Masculino' : 'Feminino'}</strong>
					</div>
					<div><span>Estado civil</span><strong>{form.perfil.estado_civil ?? '—'}</strong></div>
					{#if form.perfil.endereco}
						<div class="largo">
							<span>Endereço</span>
							<strong>
								{form.perfil.endereco.rua}, {form.perfil.endereco.numero} —
								{form.perfil.endereco.bairro}, {form.perfil.endereco.cidade}
								({form.perfil.endereco.cep})
							</strong>
						</div>
					{/if}
				</div>
			</section>

			<form onsubmit={handleSubmit}>
				<fieldset class="sem-moldura" disabled={!form.editavel}>
					{#each form.sections as secao}
						{@const visiveis = secao.questions.filter((q) => perguntaVisivel(q))}
						{#if visiveis.length > 0}
							<div class="secao-marca">
								<h2>{secao.title}</h2>
								<span>{visiveis.length} {visiveis.length === 1 ? 'pergunta' : 'perguntas'}</span>
							</div>

							{#each visiveis as q}
								<section class="cartao pergunta" id="q-{q.id}">
									<p class="pergunta-texto">
										{q.text}
										{#if q.isRequired}<span class="asterisco">*</span>{/if}
									</p>

									{#if q.type === 'texto_curto'}
										<input
											class="entrada entrada--linha"
											type="text"
											maxlength="255"
											placeholder="Sua resposta"
											bind:value={answers[q.id]}
											required={q.isRequired}
										/>
									{:else if q.type === 'texto_longo'}
										<textarea
											class="entrada entrada--caixa"
											maxlength="2000"
											rows="3"
											placeholder="Sua resposta"
											bind:value={answers[q.id]}
											required={q.isRequired}
										></textarea>
									{:else if q.type === 'numero'}
										<input
											class="entrada entrada--linha entrada--curta"
											type="number"
											placeholder="0"
											bind:value={answers[q.id]}
											required={q.isRequired}
										/>
									{:else if q.type === 'data'}
										<input
											class="entrada entrada--linha entrada--curta"
											type="date"
											bind:value={answers[q.id]}
											required={q.isRequired}
										/>
									{:else if q.type === 'lista_suspensa'}
										<select class="entrada entrada--caixa entrada--curta" bind:value={answers[q.id]} required={q.isRequired}>
											<option value="">Escolher</option>
											{#each q.options as opt}
												<option value={String(opt.id)}>{opt.text}</option>
											{/each}
										</select>
									{:else if q.type === 'sim_nao' || q.type === 'unica_escolha'}
										<div class="opcoes">
											{#each q.options as opt}
												<label class="opcao">
													<input
														type="radio"
														name="q-{q.id}"
														value={opt.id}
														checked={String(opt.id) === texto(answers[q.id])}
														onchange={() => (answers[q.id] = String(opt.id))}
														required={q.isRequired}
													/>
													<span>{opt.text}</span>
												</label>
											{/each}
										</div>
									{:else if q.type === 'multipla_escolha'}
										<div class="opcoes">
											{#each q.options as opt}
												<label class="opcao">
													<input
														type="checkbox"
														checked={parseMulti(answers[q.id]).includes(opt.id)}
														onchange={() => toggleMultiOption(q.id, opt.id)}
													/>
													<span>{opt.text}</span>
												</label>
											{/each}
										</div>
									{/if}
								</section>
							{/each}
						{/if}
					{/each}
				</fieldset>

				{#if form.editavel}
					<div class="rodape">
						<div class="progresso">
							<div class="progresso-barra">
								<div class="progresso-preenchido" style="width: {progresso}%"></div>
							</div>
							<span>{respondidas} de {perguntasVisiveis.length} respondidas</span>
						</div>
						<button type="submit" class="botao" disabled={saving}>
							{saving ? 'Enviando...' : form.respondido ? 'Salvar alterações' : 'Enviar'}
						</button>
					</div>
				{/if}
			</form>

			<!-- Etapa final: pagamento da taxa, só para quem tem vaga -->
			{#if form.pagamento?.necessario}
				<section class="cartao pagamento">
					<h2 class="secao-titulo">Pagamento da inscrição</h2>
					<p class="valor">{formatarValor(form.pagamento.valor)}</p>

					{#if form.pagamento.pago}
						<div class="faixa-aviso faixa-aviso--ok sem-cartao">
							✓ Pagamento confirmado. Sua inscrição está completa!
						</div>
					{:else if form.pagamento.eh_suplente}
						<!-- Suplente responde o formulário, mas a taxa é só de quem tem vaga. -->
						<p class="secao-dica">
							Suplentes não pagam a taxa. Esse é o valor da inscrição para quem tem vaga — se uma
							vaga for liberada para você, o pagamento aparece aqui automaticamente.
						</p>
					{:else if !form.respondido}
						<p class="secao-dica">Envie o formulário acima para liberar o pagamento.</p>
					{:else if form.pagamento.prazo_vencido}
						<div class="faixa-aviso faixa-aviso--erro sem-cartao">
							O prazo para pagar terminou{form.pagamento.prazo
								? ` em ${formatarPrazo(form.pagamento.prazo)}`
								: ''}. Procure a organização.
						</div>
					{:else}
						{#if form.pagamento.prazo}
							<p class="secao-dica">Pague até {formatarPrazo(form.pagamento.prazo)}.</p>
						{/if}

						{#if pagamentoError}
							<div class="faixa-aviso faixa-aviso--erro sem-cartao">{pagamentoError}</div>
						{/if}

						{#if form.pagamento.integracao_ativa}
							<p class="secao-dica">
								Você será levado ao ambiente do Mercado Pago, onde pode pagar por Pix, cartão ou
								boleto. A confirmação chega automaticamente aqui depois do pagamento.
							</p>
							<button type="button" class="botao" onclick={handlePagar} disabled={pagando}>
								{pagando ? 'Abrindo o Mercado Pago...' : 'Pagar com Mercado Pago'}
							</button>
							{#if form.pagamento.status_provedor === 'pending' && form.pagamento.url_pagamento}
								<p class="secao-dica">Já existe um pagamento em aberto — o botão acima abre o mesmo.</p>
							{/if}
						{:else if form.pagamento.link_manual}
							<p class="secao-dica">Use o link de pagamento informado pela organização:</p>
							<a
								class="botao"
								href={form.pagamento.link_manual}
								target="_blank"
								rel="noopener noreferrer"
							>
								Abrir link de pagamento
							</a>
						{:else}
							<p class="secao-dica">
								O pagamento online ainda está sendo configurado pela organização. Assim que estiver
								liberado, o botão aparece aqui.
							</p>
						{/if}
					{/if}
				</section>
			{/if}

			<p class="rodape-nota">Acampamento São Miguel · não envie senhas por este formulário.</p>
		{/if}
	</div>
</div>

<style>
	.fundo {
		background: #f3f8f6;
		min-height: 100%;
		padding: 2rem 1rem 4rem;
	}

	.folha {
		max-width: 720px;
		margin: 0 auto;
		/* Espaço para a barra de envio fixa não esconder o fim do formulário. */
		padding-bottom: 5rem;
	}

	.voltar {
		display: inline-block;
		margin-bottom: 1rem;
		color: #1c8b6b;
		text-decoration: none;
		font-weight: 600;
		font-size: 0.9rem;
	}

	.cartao {
		background: #fff;
		border: 1px solid #e6e6e6;
		border-radius: 12px;
		padding: 1.5rem;
		margin-bottom: 0.9rem;
	}

	/* ---------- Cabeçalho ---------- */

	.cabecalho {
		padding: 0;
		overflow: hidden;
		border-top: none;
	}

	.faixa {
		height: 10px;
		background: linear-gradient(90deg, #115843, #1c8b6b 45%, #6fdec2);
	}

	.cabecalho-corpo {
		padding: 1.6rem 1.5rem 1.4rem;
	}

	.cabecalho-corpo h1 {
		margin: 0;
		font-size: 1.7rem;
		line-height: 1.2;
		color: #14342a;
	}

	.cabecalho-sub {
		margin: 0.4rem 0 0;
		color: #6b7280;
		font-size: 0.92rem;
	}

	.selos {
		display: flex;
		gap: 0.4rem;
		flex-wrap: wrap;
		margin-top: 0.9rem;
	}

	.selo {
		font-size: 0.7rem;
		font-weight: 800;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		padding: 0.22rem 0.65rem;
		border-radius: 999px;
	}

	.selo--status {
		background: #ecfdf5;
		color: #065f46;
		border: 1px solid #a7f3d0;
	}

	.selo--ok {
		background: #6fdec2;
		color: #115843;
	}

	.aviso {
		margin: 1rem 0 0;
		padding: 0.85rem 1rem;
		background: #eff6ff;
		border: 1px solid #bfdbfe;
		color: #1e3a8a;
		border-radius: 10px;
		font-size: 0.87rem;
		line-height: 1.5;
	}

	.aviso--urgente {
		background: #fff7ed;
		border-color: #fed7aa;
		color: #9a3412;
	}

	.obrigatorias {
		margin: 1rem 0 0;
		font-size: 0.8rem;
		color: #9a9a9a;
	}

	.asterisco {
		color: #d93025;
		font-weight: 700;
	}

	/* ---------- Avisos ---------- */

	.faixa-aviso {
		font-weight: 600;
		font-size: 0.92rem;
	}

	.faixa-aviso--ok {
		background: #ecfdf5;
		border-color: #a7f3d0;
		color: #065f46;
	}

	.faixa-aviso--erro {
		background: #fef2f2;
		border-color: #fecaca;
		color: #991b1b;
	}

	.sem-cartao {
		border: 1px solid;
		border-radius: 10px;
		padding: 0.85rem 1rem;
		margin: 0.6rem 0;
	}

	/* ---------- Estados ---------- */

	.estado {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 0.6rem;
		text-align: center;
		padding: 3rem 2rem;
		color: #666;
	}

	.estado.erro {
		color: #b91c1c;
	}

	.estado h3 {
		margin: 0;
		color: #222;
	}

	.estado p {
		margin: 0;
		max-width: 46ch;
		line-height: 1.5;
		font-size: 0.94rem;
	}

	.estado-icone {
		font-size: 2.4rem;
	}

	.estado-status {
		font-size: 0.85rem !important;
		color: #8a8a8a;
	}

	.spinner {
		width: 32px;
		height: 32px;
		border: 4px solid rgba(111, 222, 194, 0.3);
		border-top-color: #6fdec2;
		border-radius: 50%;
		animation: spin 1s linear infinite;
	}

	@keyframes spin {
		to {
			transform: rotate(360deg);
		}
	}

	/* ---------- Dados de confirmação ---------- */

	.secao-titulo {
		margin: 0 0 0.3rem;
		font-size: 1.05rem;
		color: #14342a;
	}

	.secao-dica {
		color: #7a7a7a;
		font-size: 0.86rem;
		margin: 0 0 1rem;
		line-height: 1.5;
	}

	.grade-dados {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 0.8rem 1.5rem;
	}

	.grade-dados > div {
		display: flex;
		flex-direction: column;
		gap: 0.15rem;
		min-width: 0;
	}

	.grade-dados span {
		font-size: 0.7rem;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		color: #a0a0a0;
	}

	.grade-dados strong {
		font-weight: 500;
		font-size: 0.92rem;
		color: #333;
		overflow-wrap: anywhere;
	}

	.largo {
		grid-column: 1 / -1;
	}

	/* ---------- Seções e perguntas ---------- */

	.sem-moldura {
		border: none;
		padding: 0;
		margin: 0;
		min-width: 0;
	}

	.sem-moldura:disabled {
		opacity: 0.75;
	}

	.secao-marca {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: 1rem;
		margin: 1.8rem 0.3rem 0.7rem;
	}

	.secao-marca h2 {
		margin: 0;
		font-size: 0.82rem;
		font-weight: 800;
		text-transform: uppercase;
		letter-spacing: 0.07em;
		color: #1c8b6b;
	}

	.secao-marca span {
		font-size: 0.75rem;
		color: #a0a0a0;
	}

	.pergunta {
		border-left: 4px solid transparent;
		transition: border-color 0.15s ease;
	}

	.pergunta:focus-within {
		border-left-color: #6fdec2;
	}

	.pergunta-texto {
		margin: 0 0 1rem;
		font-size: 0.98rem;
		color: #202124;
		line-height: 1.45;
	}

	.entrada {
		font-family: inherit;
		font-size: 0.95rem;
		color: #202124;
		width: 100%;
		background: transparent;
	}

	.entrada--linha {
		border: none;
		border-bottom: 1px solid #dadce0;
		padding: 0.45rem 0.1rem;
		border-radius: 0;
	}

	.entrada--linha:focus {
		outline: none;
		border-bottom: 2px solid #1c8b6b;
		padding-bottom: calc(0.45rem - 1px);
	}

	.entrada--caixa {
		border: 1px solid #dadce0;
		border-radius: 8px;
		padding: 0.6rem 0.75rem;
		resize: vertical;
	}

	.entrada--caixa:focus {
		outline: none;
		border-color: #1c8b6b;
		box-shadow: 0 0 0 3px rgba(111, 222, 194, 0.28);
	}

	.entrada--curta {
		max-width: 280px;
	}

	.opcoes {
		display: flex;
		flex-direction: column;
		gap: 0.15rem;
	}

	.opcao {
		display: flex;
		align-items: center;
		gap: 0.7rem;
		padding: 0.5rem 0.4rem;
		border-radius: 8px;
		font-size: 0.93rem;
		color: #202124;
		cursor: pointer;
	}

	.opcao:hover {
		background: #f5faf8;
	}

	.opcao input {
		accent-color: #1c8b6b;
		width: 1.05rem;
		height: 1.05rem;
		flex-shrink: 0;
		cursor: pointer;
	}

	/* ---------- Rodapé com progresso e envio ---------- */

	.rodape {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 1rem;
		flex-wrap: wrap;
		background: rgba(255, 255, 255, 0.97);
		backdrop-filter: blur(6px);
		border: 1px solid #cfe7de;
		border-radius: 12px;
		padding: 1rem 1.3rem;
		margin-bottom: 0.9rem;
		position: sticky;
		bottom: 0.8rem;
		box-shadow: 0 8px 24px rgba(17, 88, 67, 0.18);
	}

	.progresso {
		display: flex;
		flex-direction: column;
		gap: 0.35rem;
		flex: 1 1 200px;
		min-width: 160px;
	}

	.progresso span {
		font-size: 0.78rem;
		color: #8a8a8a;
	}

	.progresso-barra {
		height: 6px;
		background: #eef2f0;
		border-radius: 999px;
		overflow: hidden;
	}

	.progresso-preenchido {
		height: 100%;
		background: linear-gradient(90deg, #1c8b6b, #6fdec2);
		border-radius: 999px;
		transition: width 0.25s ease;
	}

	.botao {
		display: inline-block;
		background: #1c8b6b;
		color: #fff;
		border: none;
		padding: 0.7rem 2rem;
		border-radius: 999px;
		font-weight: 700;
		font-size: 0.94rem;
		cursor: pointer;
		text-decoration: none;
	}

	.botao:hover:not(:disabled) {
		background: #17755a;
	}

	.botao:disabled {
		opacity: 0.6;
		cursor: not-allowed;
	}

	.botao--claro {
		background: #f3f4f6;
		color: #374151;
		border: 1px solid #e5e7eb;
		margin-top: 0.8rem;
	}

	/* ---------- Pagamento ---------- */

	.pagamento {
		border-color: #cdeee3;
		background: #fbfffd;
	}

	.valor {
		margin: 0.2rem 0 1rem;
		font-size: 1.9rem;
		font-weight: 700;
		color: #115843;
	}

	.rodape-nota {
		text-align: center;
		color: #a8b3ae;
		font-size: 0.75rem;
		margin: 1.5rem 0 0;
	}

	@media (max-width: 520px) {
		.fundo {
			padding: 1.2rem 0.7rem 3rem;
		}

		.cartao {
			padding: 1.2rem;
		}

		.cabecalho-corpo h1 {
			font-size: 1.35rem;
		}

		.grade-dados {
			grid-template-columns: 1fr;
		}
	}
</style>
