<script lang="ts">
	/**
	 * Alterar senha — aberta pelo botão "Alterar senha" de Meu cadastro.
	 *
	 * Também é onde fica presa quem entrou com a senha provisória gerada pela
	 * organização: sem "Cancelar" nem volta, até criar a própria senha. Aí não
	 * pede a senha atual (a pessoa acabou de digitá-la no login).
	 */
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { auth } from '$lib/stores/auth.svelte';
	import { destinoSeguro, loginUrl } from '$lib/redirect';
	import CabecalhoSite from '$lib/components/CabecalhoSite.svelte';
	import { trocarMinhaSenha } from '$lib/services/userService';

	$effect(() => {
		if (!auth.isLoading && !auth.isAuthenticated) {
			goto(loginUrl($page.url.pathname), { replaceState: true });
		}
	});

	let senhaAtual = $state('');
	let novaSenha = $state('');
	let confirmarSenha = $state('');
	let trocando = $state(false);
	let erro = $state('');
	let trocada = $state(false);
	/** Senha provisória: a troca é obrigatória. */
	let obrigatoria = $derived(!!auth.user?.trocar_senha);
	/** Guardado ao trocar, porque o cadastro recarregado já vem sem a marca. */
	let criouAPrimeira = $state(false);
	/** Para onde seguir depois de criar a senha (ex.: a atividade em que ia se inscrever). */
	let destino = $derived(destinoSeguro($page.url.searchParams.get('redirect')) ?? '/dashboard');
	/** "Mostrar" de cada campo, como na tela de login. */
	let mostrar = $state({ atual: false, nova: false, confirmar: false });

	async function trocarSenha(e: SubmitEvent) {
		e.preventDefault();
		if (trocando) return;
		if (novaSenha.length < 6) {
			erro = 'A nova senha precisa ter pelo menos 6 caracteres.';
			return;
		}
		if (novaSenha !== confirmarSenha) {
			erro = 'A confirmação não é igual à nova senha.';
			return;
		}
		trocando = true;
		erro = '';
		try {
			const res = await trocarMinhaSenha(obrigatoria ? '' : senhaAtual, novaSenha);
			if (res.success) {
				senhaAtual = novaSenha = confirmarSenha = '';
				criouAPrimeira = obrigatoria;
				await auth.refreshUser();
				trocada = true;
			} else {
				erro = res.error || 'Não foi possível trocar a senha.';
			}
		} catch {
			erro = 'Não foi possível trocar a senha. Verifique sua conexão e tente de novo.';
		} finally {
			trocando = false;
		}
	}
</script>

<svelte:head>
	<title>Alterar senha | Acampamento São Miguel</title>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link
		href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap"
		rel="stylesheet"
	/>
</svelte:head>

{#snippet camposNovaSenha()}
	<div class="campo-grupo">
		<label class="rotulo" for="senha-nova">Nova senha</label>
		<div class="senha">
			<input
				id="senha-nova"
				class="campo"
				type={mostrar.nova ? 'text' : 'password'}
				bind:value={novaSenha}
				autocomplete="new-password"
			/>
			{@render botaoMostrar('nova')}
		</div>
	</div>
	<div class="campo-grupo">
		<label class="rotulo" for="senha-confirmar">Confirme a nova senha</label>
		<div class="senha">
			<input
				id="senha-confirmar"
				class="campo"
				type={mostrar.confirmar ? 'text' : 'password'}
				bind:value={confirmarSenha}
				autocomplete="new-password"
			/>
			{@render botaoMostrar('confirmar')}
		</div>
	</div>

	{#if erro}
		<span class="erro-campo" role="alert"><span class="erro-icone" aria-hidden="true">!</span>{erro}</span>
	{/if}
{/snippet}

{#snippet botaoMostrar(campo: keyof typeof mostrar)}
	<button
		type="button"
		class="mostrar"
		onclick={() => (mostrar[campo] = !mostrar[campo])}
		aria-label={mostrar[campo] ? 'Ocultar senha' : 'Mostrar senha'}
	>
		{mostrar[campo] ? 'Ocultar' : 'Mostrar'}
	</button>
{/snippet}

<div class="pagina">
	<CabecalhoSite />

	<main class="folha">
		{#if !auth.user}
			<!-- Esperando o cadastro: decide entre "Alterar senha" e "Crie sua senha". -->
		{:else if criouAPrimeira}
			<section class="cartao centro" role="status">
				<div class="check" aria-hidden="true">✓</div>
				<h2>Senha criada</h2>
				<p class="nota">A partir de agora, entre com o seu CPF e esta senha.</p>
				<a href={destino} class="btn btn--ouro">Continuar</a>
			</section>
		{:else if obrigatoria}
			<form class="cartao" onsubmit={trocarSenha} novalidate>
				<div class="cartao-cabeca">
					<h2>Crie sua senha</h2>
					<p class="nota">
						Você entrou com uma senha provisória. Para continuar, crie uma senha só sua, com pelo menos 6
						caracteres.
					</p>
				</div>
				{@render camposNovaSenha()}
				<div class="acoes">
					<button type="submit" class="btn btn--ouro" disabled={trocando || !novaSenha || !confirmarSenha}>
						{#if trocando}<span class="spinner"></span>Salvando...{:else}Criar senha{/if}
					</button>
				</div>
			</form>
		{:else}
		<a href="/dashboard/perfil" class="voltar">← Meu cadastro</a>

		{#if trocada}
			<section class="cartao centro" role="status">
				<div class="check" aria-hidden="true">✓</div>
				<h2>Senha alterada</h2>
				<p class="nota">Use a nova senha no próximo login.</p>
				<a href="/dashboard/perfil" class="btn btn--ouro">Voltar para Meu cadastro</a>
			</section>
		{:else}
			<form class="cartao" onsubmit={trocarSenha} novalidate>
				<div class="cartao-cabeca">
					<h2>Alterar senha</h2>
					<p class="nota">Informe a senha que você usa hoje e escolha a nova, com pelo menos 6 caracteres.</p>
				</div>

				<div class="campo-grupo">
					<label class="rotulo" for="senha-atual">Senha atual</label>
					<div class="senha">
						<input
							id="senha-atual"
							class="campo"
							type={mostrar.atual ? 'text' : 'password'}
							bind:value={senhaAtual}
							autocomplete="current-password"
						/>
						{@render botaoMostrar('atual')}
					</div>
				</div>
				{@render camposNovaSenha()}

				<div class="acoes">
					<a href="/dashboard/perfil" class="btn btn--leve">Cancelar</a>
					<button type="submit" class="btn btn--ouro" disabled={trocando || !senhaAtual || !novaSenha || !confirmarSenha}>
						{#if trocando}<span class="spinner"></span>Salvando...{:else}Salvar nova senha{/if}
					</button>
				</div>
			</form>
		{/if}
		{/if}
	</main>
</div>

<style>
	.pagina {
		--ouro: #b69e55;
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

	.pagina :is(button, a, input, label) {
		font-family: inherit;
		box-sizing: border-box;
	}

	.pagina :is(button, a, input):focus-visible {
		outline: none;
		box-shadow: 0 0 0 4px var(--anel-foco);
	}

	.folha {
		display: flex;
		flex-direction: column;
		gap: 16px;
		max-width: 560px;
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

	.cartao {
		display: flex;
		flex-direction: column;
		gap: 20px;
		padding: clamp(20px, 5vw, 32px) clamp(18px, 5vw, 32px);
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 16px;
		box-shadow: 0 14px 28px -22px rgba(15, 29, 61, 0.45);
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

	.check {
		width: 72px;
		height: 72px;
		border-radius: 50%;
		background: #e4f1e8;
		border: 2px solid #b9d9c4;
		color: #2e7d4f;
		font-size: 36px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.campo-grupo {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.rotulo {
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

	.senha {
		position: relative;
	}

	.senha .campo {
		padding-right: 104px;
	}

	.mostrar {
		position: absolute;
		right: 5px;
		top: 5px;
		height: 42px;
		min-width: 88px;
		padding: 0 12px;
		font-size: 15px;
		font-weight: 700;
		color: var(--azul);
		background: #f4eedd;
		border: none;
		border-radius: 8px;
		cursor: pointer;
	}

	.mostrar:hover {
		background: #ece2c6;
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

	.acoes {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		align-items: center;
		gap: 10px;
		padding-top: 18px;
		border-top: 1px solid var(--linha);
	}

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

	.spinner {
		width: 18px;
		height: 18px;
		border: 2px solid var(--borda-suave);
		border-top-color: var(--azul);
		border-radius: 50%;
		animation: girar 0.9s linear infinite;
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
	}
</style>
