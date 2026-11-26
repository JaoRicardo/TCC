<script lang="ts">
	/**
	 * Meu cadastro — a pessoa confere os próprios dados e corrige o que estava
	 * errado ou mudou (telefone, endereço, estado civil...) e troca a senha. O CPF é o login e não muda por aqui.
	 */
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { auth } from '$lib/stores/auth.svelte';
	import { loginUrl } from '$lib/redirect';
	import CabecalhoSite from '$lib/components/CabecalhoSite.svelte';
	import Janela from '$lib/components/painel/Janela.svelte';
	import '$lib/styles/formularios.css';
	import { mascaraCep, mascaraNome, mascaraTelefone } from '$lib/mascaras';
	import {
		getMeuCadastro,
		salvarMeuCadastro,
		getEstadosCivis,
		type MeuCadastro,
		type PreviaDaFicha
	} from '$lib/services/userService';

	type Estado = 'inicio' | 'carregando' | 'pronto' | 'erro';

	let estado: Estado = $state('inicio');
	let erroCarregar = $state('');
	// CPF e nascimento só aparecem: o CPF é o login e a idade decide a categoria.
	let cpf = $state('');
	let nascimento = $state('');
	let estadosCivis: { id: number; title: string }[] = $state([]);

	let dados = $state({
		name: '',
		sex: '',
		marital_status_id: '',
		phone: '',
		email: '',
		cep: '',
		city: ''
	});
	/** Como os dados vieram do servidor, para saber se há algo a salvar. */
	let original = $state('');
	const alterado = $derived(JSON.stringify(dados) !== original);

	let salvando = $state(false);
	let erros: Record<string, string> = $state({});
	let erroSalvar = $state('');
	let salvoAgora = $state(false);
	/** Quantas inscrições saíram do sorteio no último salvar. */
	let retiradasAgora = $state(0);
	/** Com os dados novos, a pessoa sai do sorteio: a janela pede confirmação antes de gravar. */
	let saidaDoSorteio = $state<PreviaDaFicha | null>(null);

	// ─── Entrar e carregar ───────────────────────────────────────────────
	$effect(() => {
		if (auth.isLoading || estado !== 'inicio') return;
		if (!auth.isAuthenticated) {
			goto(loginUrl($page.url.pathname), { replaceState: true });
			return;
		}
		carregar();
	});

	async function carregar() {
		estado = 'carregando';
		try {
			const [cadastro, estados] = await Promise.all([getMeuCadastro(), getEstadosCivis()]);
			if (!cadastro.user) {
				erroCarregar = cadastro.error || 'Não foi possível carregar o seu cadastro.';
				estado = 'erro';
				return;
			}
			estadosCivis = estados;
			aplicar(cadastro.user);
			estado = 'pronto';
		} catch {
			erroCarregar = 'Não foi possível carregar o seu cadastro. Verifique sua conexão.';
			estado = 'erro';
		}
	}

	function aplicar(user: MeuCadastro) {
		cpf = user.cpf;
		nascimento = user.birthday ? dataBr(user.birthday) : '';
		dados = {
			name: user.name ?? '',
			sex: user.sex ?? '',
			marital_status_id: user.marital_status ? String(user.marital_status.id) : '',
			phone: mascaraTelefone(user.phone ?? ''),
			email: user.email ?? '',
			cep: mascaraCep(user.address?.cep ?? ''),
			city: user.address?.city ?? ''
		};
		original = JSON.stringify(dados);
	}

	// ─── Salvar ──────────────────────────────────────────────────────────
	async function salvar(e: SubmitEvent) {
		e.preventDefault();
		await gravar(false);
	}

	async function gravar(confirmouSaidaDoSorteio: boolean) {
		if (salvando) return;
		salvoAgora = false;
		erroSalvar = '';

		const novosErros: Record<string, string> = {};
		if (!dados.name.trim()) novosErros.name = 'Informe o nome completo.';
		if (dados.phone.replace(/\D/g, '').length < 10) novosErros.phone = 'Informe o telefone com DDD.';
		if (Object.keys(novosErros).length) {
			erros = novosErros;
			erroSalvar = 'Confira os campos marcados.';
			return;
		}

		salvando = true;
		try {
			const res = await salvarMeuCadastro(
				{
					name: dados.name.trim(),
					sex: dados.sex,
					marital_status_id: dados.marital_status_id ? Number(dados.marital_status_id) : null,
					phone: dados.phone,
					email: dados.email.trim(),
					address: {
						cep: dados.cep,
						city: dados.city.trim()
					}
				},
				confirmouSaidaDoSorteio
			);
			saidaDoSorteio = null;
			if (res.sorteio) {
				saidaDoSorteio = res.sorteio;
			} else if (res.user) {
				erros = {};
				retiradasAgora = res.retiradas ?? 0;
				aplicar(res.user);
				// O nome no cabeçalho e os dados das próximas inscrições já saem atualizados.
				auth.user = res.user;
				salvoAgora = true;
			} else {
				erros = res.errors ?? {};
				erroSalvar = res.error || 'Não foi possível salvar o cadastro.';
			}
		} catch {
			saidaDoSorteio = null;
			erroSalvar = 'Não foi possível salvar. Verifique sua conexão e tente de novo.';
		} finally {
			salvando = false;
		}
	}

	/** Por que a inscrição sai do sorteio, dito para a própria pessoa. */
	function motivoParaPessoa(motivo: string) {
		if (motivo === 'cidade') return 'só quem mora em Primavera do Leste ou Poxoréu participa';
		if (motivo === 'sexo') return `este acampamento não tem vagas de sorteio para ${dados.sex === 'M' ? 'homens' : 'mulheres'}`;
		return 'você não tem a idade da categoria';
	}

	const comAno = (atividade: string, ano: number | string | null) =>
		ano && !atividade.includes(String(ano)) ? `${atividade} ${ano}` : atividade;

	function desfazer() {
		const antes = JSON.parse(original);
		dados = antes;
		erros = {};
		erroSalvar = '';
	}

	const dataBr = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`;
</script>

<svelte:head>
	<title>Meu cadastro | Acampamento São Miguel</title>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link
		href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap"
		rel="stylesheet"
	/>
</svelte:head>

{#snippet erro(chave: string)}
	{#if erros[chave]}
		<span class="erro-campo" id="erro-{chave}"><span class="erro-icone" aria-hidden="true">!</span>{erros[chave]}</span>
	{/if}
{/snippet}

<div class="pagina">
	<CabecalhoSite />

	<main class="folha">
		<a href="/dashboard" class="voltar">← Acampamentos e Eventos</a>

		<section class="vitral">
			<div class="vitral-trama"></div>
			<div class="vitral-conteudo">
				<span class="sobretitulo"><span class="sobretitulo-traco"></span>Seus dados</span>
				<h1>Meu cadastro</h1>
				<p>Confira seus dados e corrija o que estiver errado ou tiver mudado.</p>
			</div>
		</section>

		{#if estado === 'inicio' || estado === 'carregando'}
			<section class="cartao centro" role="status">
				<span class="spinner"></span>
				<p class="nota">Carregando o seu cadastro...</p>
			</section>
		{:else if estado === 'erro'}
			<section class="cartao centro" role="alert">
				<h2>Não foi possível abrir o cadastro</h2>
				<p class="nota">{erroCarregar}</p>
				<button type="button" class="btn btn--ouro" onclick={carregar}>Tentar de novo</button>
			</section>
		{:else}
			<form class="pilha" onsubmit={salvar} novalidate>
				<!-- Dados pessoais -->
				<section class="cartao">
					<div class="cartao-cabeca">
						<h2>Dados pessoais</h2>
						<p class="nota">Esses dados vão para as fichas de inscrição dos acampamentos e eventos.</p>
					</div>

					<div class="grade">
						<label class="campo-grupo largo">
							<span class="rotulo">Nome completo</span>
							<input
								class="campo"
								class:com-erro={erros.name}
								value={dados.name}
								oninput={(e) => {
									dados.name = mascaraNome(e.currentTarget.value);
									e.currentTarget.value = dados.name;
								}}
								autocomplete="name"
								aria-invalid={!!erros.name}
								aria-describedby={erros.name ? 'erro-name' : undefined}
							/>
							{@render erro('name')}
						</label>

						<div class="campo-grupo">
							<span class="rotulo">CPF</span>
							<span class="campo campo--fixo">{cpf}</span>
							<span class="dica">O CPF é o seu login. Para corrigir, fale com a organização.</span>
						</div>

						<div class="campo-grupo">
							<span class="rotulo">Data de nascimento</span>
							<span class="campo campo--fixo">{nascimento || '—'}</span>
							<span class="dica">A idade decide a categoria do acampamento. Para corrigir, fale com a organização.</span>
						</div>

						<fieldset class="campo-grupo">
							<legend class="rotulo">Sexo</legend>
							<div class="pilulas">
								{#each [['M', 'Masculino'], ['F', 'Feminino']] as [valor, nome] (valor)}
									<label class="pilula" class:marcada={dados.sex === valor}>
										<input type="radio" class="escondido" name="sexo" value={valor} bind:group={dados.sex} />
										<span class="marca" aria-hidden="true"></span>{nome}
									</label>
								{/each}
							</div>
							{@render erro('sex')}
						</fieldset>

						<label class="campo-grupo">
							<span class="rotulo">Estado civil</span>
							<select class="campo" class:com-erro={erros.marital_status_id} bind:value={dados.marital_status_id}>
								<option value="" disabled>Escolha</option>
								{#each estadosCivis as ec (ec.id)}
									<option value={String(ec.id)}>{ec.title}</option>
								{/each}
							</select>
							{@render erro('marital_status_id')}
						</label>

						<label class="campo-grupo">
							<span class="rotulo">Telefone (WhatsApp)</span>
							<input
								class="campo"
								class:com-erro={erros.phone}
								value={dados.phone}
								oninput={(e) => (dados.phone = e.currentTarget.value = mascaraTelefone(e.currentTarget.value))}
								inputmode="tel"
								placeholder="(00) 00000-0000"
								autocomplete="tel"
								aria-invalid={!!erros.phone}
								aria-describedby={erros.phone ? 'erro-phone' : undefined}
							/>
							{@render erro('phone')}
						</label>

						<label class="campo-grupo largo">
							<span class="rotulo">E-mail</span>
							<input
								class="campo"
								class:com-erro={erros.email}
								type="email"
								bind:value={dados.email}
								autocomplete="email"
								aria-invalid={!!erros.email}
								aria-describedby={erros.email ? 'erro-email' : undefined}
							/>
							{@render erro('email')}
						</label>
					</div>
				</section>

				<!-- Endereço -->
				<section class="cartao">
					<div class="cartao-cabeca">
						<h2>Endereço</h2>
						<p class="nota">Só quem mora em Primavera do Leste ou Poxoréu participa dos sorteios dos acampamentos.</p>
					</div>

					<div class="grade">
						<label class="campo-grupo">
							<span class="rotulo">CEP</span>
							<input
								class="campo campo--curto"
								class:com-erro={erros['address.cep']}
								value={dados.cep}
								oninput={(e) => (dados.cep = e.currentTarget.value = mascaraCep(e.currentTarget.value))}
								inputmode="numeric"
								placeholder="00000-000"
								autocomplete="postal-code"
								aria-invalid={!!erros['address.cep']}
								aria-describedby={erros['address.cep'] ? 'erro-address.cep' : undefined}
							/>
							{@render erro('address.cep')}
						</label>

						<label class="campo-grupo">
							<span class="rotulo">Cidade</span>
							<input
								class="campo"
								class:com-erro={erros['address.city']}
								bind:value={dados.city}
								autocomplete="address-level2"
								aria-invalid={!!erros['address.city']}
								aria-describedby={erros['address.city'] ? 'erro-address.city' : undefined}
							/>
							{@render erro('address.city')}
						</label>



					</div>
				</section>

				<div class="barra-salvar" class:barra-salvar--ativa={alterado}>
					<div class="barra-texto" role="status">
						{#if erroSalvar}
							<span class="erro-campo"><span class="erro-icone" aria-hidden="true">!</span>{erroSalvar}</span>
						{:else if salvoAgora && !alterado}
							<span class="sucesso">
								✓ Cadastro atualizado.{#if retiradasAgora}
									{retiradasAgora === 1 ? ' 1 inscrição saiu do sorteio.' : ` ${retiradasAgora} inscrições saíram do sorteio.`}
								{/if}
							</span>
						{:else if alterado}
							<span class="nota">Você tem alterações que ainda não foram salvas.</span>
						{/if}
					</div>
					{#if alterado}
						<button type="button" class="btn btn--leve" onclick={desfazer} disabled={salvando}>Desfazer</button>
					{/if}
					<button type="submit" class="btn btn--ouro" disabled={!alterado || salvando}>
						{#if salvando}<span class="spinner spinner--pequeno"></span>Salvando...{:else}Salvar alterações{/if}
					</button>
				</div>
			</form>

			<!-- Senha -->
			<section class="cartao cartao--linha">
				<div class="cartao-cabeca">
					<h2>Senha</h2>
					<p class="nota">A senha que você usa para entrar com o seu CPF.</p>
				</div>
				<a href="/dashboard/perfil/senha" class="btn btn--contorno">Alterar senha</a>
			</section>
		{/if}
	</main>
</div>

{#if saidaDoSorteio}
	{@const n = saidaDoSorteio.afetadas.length}
	<Janela
		idTitulo="t-sorteio"
		largura={620}
		faixa="linear-gradient(90deg,#C08A1E,#B69E55)"
		travada={salvando}
		onfechar={() => (saidaDoSorteio = null)}
	>
		<h2 id="t-sorteio" class="fm-janela-titulo">Antes de salvar</h2>
		<p class="janela-texto">
			Com os dados novos, você deixa de concorrer ao sorteio {n === 1 ? 'desta inscrição' : `destas ${n} inscrições`}:
		</p>
		<ul class="fm-janela-lista">
			{#each saidaDoSorteio.afetadas as a (a.id)}
				<li><strong>{comAno(a.atividade, a.ano)}</strong> ({a.como}): {motivoParaPessoa(a.motivo)}.</li>
			{/each}
		</ul>
		<p class="janela-texto">Se for um engano, volte e corrija. Depois de salvar, só a organização consegue rever.</p>
		<div class="fm-janela-acoes">
			<button
				type="button"
				class="fm-btn fm-btn--contorno fm-btn--grande"
				data-foco-inicial
				onclick={() => (saidaDoSorteio = null)}
				disabled={salvando}
			>
				Voltar e revisar
			</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={() => gravar(true)} disabled={salvando}>
				{#if salvando}Salvando...{:else}Salvar mesmo assim{/if}
			</button>
		</div>
	</Janela>
{/if}

<style>
	.janela-texto {
		margin: 0;
		font-size: 17px;
		line-height: 1.45;
		color: #2b3045;
	}

	.pagina {
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
		--linha: #efe7d3;
		--tinta: #1e2233;
		--tinta-3: #4f5468;
		--campo-borda: #a39363;
		--anel-foco: rgba(182, 158, 85, 0.45);
		--margem: 16px;

		min-height: 100vh;
		background: var(--marfim);
		font-family: 'Source Sans 3', system-ui, sans-serif;
		color: var(--tinta);
	}

	.pagina :is(button, a, input, select, label) {
		font-family: inherit;
		box-sizing: border-box;
	}

	.pagina :is(button, a, input, select):focus-visible,
	.pilula:has(input:focus-visible) {
		outline: none;
		box-shadow: 0 0 0 4px var(--anel-foco);
	}

	/* ─── Folha ─── */
	.folha,
	.pilha {
		display: flex;
		flex-direction: column;
		gap: 16px;
	}

	.folha {
		max-width: 760px;
		margin: 0 auto;
		padding: 16px var(--margem) 48px;
	}

	.voltar {
		align-self: flex-start;
		display: flex;
		align-items: center;
		min-height: 44px;
		font-size: 16px;
		font-weight: 600;
		color: var(--azul);
		text-decoration: none;
	}

	/* ─── Vitral ─── */
	.vitral {
		position: relative;
		overflow: hidden;
		border-radius: 16px;
		background:
			radial-gradient(circle at 12% 30%, rgba(214, 186, 104, 0.35), transparent 28%),
			radial-gradient(circle at 70% 120%, rgba(31, 107, 110, 0.85), transparent 45%),
			radial-gradient(circle at 95% 0%, rgba(155, 44, 44, 0.35), transparent 30%),
			linear-gradient(150deg, #22397a 0%, #1b2f5e 45%, #0f1d3d 100%);
		box-shadow:
			inset 0 0 0 6px #0f1d3d,
			inset 0 0 0 7px rgba(182, 158, 85, 0.75);
	}

	.vitral-trama {
		position: absolute;
		inset: 0;
		background:
			repeating-linear-gradient(45deg, transparent 0 46px, rgba(8, 14, 32, 0.32) 46px 48px),
			repeating-linear-gradient(-45deg, transparent 0 46px, rgba(8, 14, 32, 0.32) 46px 48px);
		pointer-events: none;
	}

	.vitral-conteudo {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: clamp(22px, 5vw, 40px) clamp(20px, 5vw, 40px);
	}

	.sobretitulo {
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 13px;
		font-weight: 600;
		letter-spacing: 0.16em;
		text-transform: uppercase;
		color: var(--ouro-claro);
	}

	.sobretitulo-traco {
		width: 28px;
		height: 1px;
		background: var(--ouro);
	}

	.vitral h1 {
		margin: 0;
		font: 600 clamp(32px, 7vw, 48px) / 1.05 'Cormorant Garamond', serif;
		color: var(--pergaminho);
	}

	.vitral p {
		margin: 0;
		font-size: 17px;
		color: #e6e9f2;
	}

	/* ─── Cartão ─── */
	.cartao {
		display: flex;
		flex-direction: column;
		gap: 22px;
		padding: clamp(20px, 5vw, 32px) clamp(18px, 5vw, 32px);
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 16px;
		box-shadow: 0 14px 28px -22px rgba(15, 29, 61, 0.45);
	}

	.cartao--linha {
		flex-direction: row;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
	}

	.cartao-cabeca {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.cartao h2 {
		margin: 0;
		font: 600 clamp(28px, 6vw, 34px) / 1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.nota {
		margin: 0;
		font-size: 16px;
		line-height: 1.45;
		color: var(--tinta-3);
	}

	.centro {
		align-items: center;
		text-align: center;
		gap: 14px;
	}

	/* ─── Campos ─── */
	.grade {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr));
		gap: 20px 24px;
	}

	.largo {
		grid-column: 1 / -1;
	}

	.campo-grupo {
		display: flex;
		flex-direction: column;
		gap: 8px;
		min-width: 0;
		margin: 0;
		padding: 0;
		border: none;
	}

	.rotulo {
		padding: 0;
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta);
	}

	.campo {
		width: 100%;
		height: 52px;
		padding: 0 16px;
		font-size: 17px;
		color: var(--tinta);
		background: #ffffff;
		border: 1.5px solid var(--campo-borda);
		border-radius: 10px;
	}

	.campo:focus {
		border-color: var(--azul);
	}

	.campo--curto {
		max-width: 200px;
	}

	.campo--fixo {
		display: flex;
		align-items: center;
		color: var(--tinta-3);
		background: #f3eee2;
		border-color: var(--borda-suave);
	}

	.dica {
		font-size: 14px;
		line-height: 1.4;
		color: var(--tinta-3);
	}

	.com-erro {
		border-color: var(--vermelho) !important;
	}

	.escondido {
		position: absolute;
		opacity: 0;
		width: 1px;
		height: 1px;
		pointer-events: none;
	}

	.pilulas {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 10px;
	}

	.pilula {
		position: relative;
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 10px;
		height: 52px;
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta);
		background: #ffffff;
		border: 1.5px solid var(--borda);
		border-radius: 999px;
		cursor: pointer;
	}

	.pilula.marcada {
		color: var(--pergaminho);
		background: var(--azul);
		border-color: var(--azul);
	}

	.marca {
		flex: 0 0 20px;
		height: 20px;
		box-sizing: border-box;
		border-radius: 50%;
		border: 2px solid var(--campo-borda);
		background: #ffffff;
	}

	.pilula.marcada .marca {
		border-color: var(--pergaminho);
		background: var(--pergaminho);
		box-shadow: inset 0 0 0 4px var(--azul);
	}

	.erro-campo {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 15px;
		font-weight: 600;
		color: #842424;
	}

	.erro-icone {
		flex: 0 0 20px;
		height: 20px;
		border-radius: 50%;
		background: var(--vermelho);
		color: var(--pergaminho);
		font-size: 13px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.sucesso {
		font-size: 16px;
		font-weight: 600;
		color: #2e7d4f;
	}

	/* ─── Salvar ─── */
	.barra-salvar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 10px;
		padding: 12px 16px;
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 14px;
		box-shadow: 0 14px 28px -22px rgba(15, 29, 61, 0.45);
	}

	/* Com alteração pendente, a barra acompanha a rolagem para o Salvar ficar à mão. */
	.barra-salvar--ativa {
		position: sticky;
		bottom: 12px;
		border-color: var(--ouro);
		box-shadow: 0 10px 30px -12px rgba(15, 29, 61, 0.5);
	}

	.barra-texto {
		flex: 1 1 240px;
	}

	/* ─── Botões ─── */
	.btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 8px;
		height: 52px;
		padding: 0 22px;
		font-size: 17px;
		font-weight: 600;
		color: var(--azul);
		text-decoration: none;
		background: transparent;
		border: 1.5px solid transparent;
		border-radius: 999px;
		cursor: pointer;
	}

	.btn:disabled {
		cursor: not-allowed;
		opacity: 0.6;
	}

	.btn--ouro {
		padding: 0 26px;
		font-weight: 700;
		color: var(--azul-noite);
		background: linear-gradient(180deg, #d6be72, #b69e55);
		border: 1px solid #9c8642;
		box-shadow: 0 6px 16px -8px rgba(122, 101, 40, 0.9);
	}

	.btn--ouro:hover:not(:disabled) {
		background: #b69e55;
	}

	.btn--contorno {
		border-color: var(--azul);
	}

	.btn--contorno:hover:not(:disabled) {
		background: #eef1f7;
	}

	.btn--leve {
		height: 44px;
		padding: 0 16px;
		font-size: 15px;
		background: var(--pergaminho);
		border: 1px solid var(--borda);
	}

	.btn--leve:hover {
		background: #fbf6e6;
	}

	/* ─── Carregando ─── */
	.spinner {
		width: 34px;
		height: 34px;
		border: 3px solid var(--borda-suave);
		border-top-color: var(--azul);
		border-radius: 50%;
		animation: girar 0.9s linear infinite;
	}

	.spinner--pequeno {
		width: 18px;
		height: 18px;
		border-width: 2px;
	}

	@keyframes girar {
		to {
			transform: rotate(360deg);
		}
	}

	@media (min-width: 720px) {
		.pagina {
			--margem: clamp(16px, 4vw, 48px);
		}

		.folha {
			padding-top: 24px;
			gap: 20px;
		}

		.pilha {
			gap: 20px;
		}
	}
</style>
