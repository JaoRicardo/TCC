<script lang="ts">
	/**
	 * Painel da categoria selecionada (handoff design_handoff_categorias,
	 * seção 6): onde fica, uso, regras em frase e "Para completar" (formulários
	 * e modelo de setores, que não impedem de criar acampamentos).
	 */
	import {
		escadaEmUso,
		faltaConfigurar,
		textoPodeServirEm,
		textosDaCategoria,
		type CategoriaDaLista
	} from '$lib/categorias';
	import { TEXTO_DA_FASE } from '$lib/faseAtividade';
	import { pode } from '$lib/permissoes';
	import { dataDoAcampamento, faseDoAcampamento } from './datas';

	let {
		c,
		categorias,
		novo,
		oneditar,
		ontirar,
		onfechar
	}: {
		c: CategoriaDaLista;
		categorias: CategoriaDaLista[];
		/** Acabou de ser criada: mostra o aviso de próximos passos. */
		novo: boolean;
		oneditar: () => void;
		ontirar: () => void;
		onfechar: () => void;
	} = $props();

	const plural = (n: number, um: string, varios: string) => `${n} ${n === 1 ? um : varios}`;

	const escada = $derived(escadaEmUso(categorias));
	const lugar = $derived(
		c.naEscada ? `Escada por idade · degrau ${escada.findIndex((x) => x.id === c.id) + 1} de ${escada.length}` : 'Fora da escada'
	);
	const uso = $derived(
		c.acampamentos
			? `${plural(c.acampamentos, 'acampamento', 'acampamentos')} no total · ${c.porAcontecer.length ? `${c.porAcontecer.length} por acontecer` : 'nenhum por acontecer'}`
			: 'Nenhum acampamento ainda.'
	);
	const textos = $derived(textosDaCategoria(c, categorias));
	const falta = $derived(faltaConfigurar(c));

	const editaFormularios = pode('formularios');
	const editaSetores = pode('setores');

	const passos = $derived([
		{
			rot: 'Formulário de campista',
			ok: c.perguntasCampista > 0,
			feito: plural(c.perguntasCampista, 'pergunta', 'perguntas') + '.',
			vazio: 'Vazio: sem perguntas, ninguém perde a vaga pelo prazo.',
			link: c.perguntasCampista > 0 ? 'Editar' : 'Montar',
			href: editaFormularios ? `/admin/formularios/${c.id}/campista` : null
		},
		{
			rot: 'Formulário de servo',
			ok: c.perguntasServo > 0,
			feito: plural(c.perguntasServo, 'pergunta', 'perguntas') + '.',
			vazio: 'Vazio: sem perguntas, ninguém perde a vaga pelo prazo.',
			link: c.perguntasServo > 0 ? 'Editar' : 'Montar',
			href: editaFormularios ? `/admin/formularios/${c.id}/servo` : null
		},
		{
			rot: 'Modelo de setores',
			ok: c.setores > 0,
			feito: `${plural(c.setores, 'setor', 'setores')} · ${plural(c.vagasSetores, 'vaga', 'vagas')}.`,
			vazio: 'Sem setores: as equipes dos acampamentos começam sem setores.',
			link: c.setores > 0 ? 'Ver o modelo' : 'Montar o modelo',
			href: editaSetores ? '/admin/equipes?aba=modelo' : null
		}
	]);
</script>

<section class="fm-cartao" aria-labelledby="t-det" style="overflow:hidden">
	<div style="height:5px;background:linear-gradient(90deg,#1b2f5e,#b69e55)"></div>
	<div style="display:flex;flex-direction:column;gap:16px;padding:20px 22px 22px">
		<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
			<div style="display:flex;flex-direction:column;gap:4px;min-width:0">
				<span class="ins-rot">{lugar}</span>
				<h2 id="t-det" style="margin:0;font:600 34px/1.05 'Cormorant Garamond',serif;color:var(--azul)">{c.nome}</h2>
				<span style="font-size:15px;color:var(--tinta-3)">{uso}</span>
			</div>
			<button type="button" class="ins-acao2" style="width:40px;justify-content:center;font-size:20px" aria-label="Fechar o painel da categoria" onclick={onfechar}>✕</button>
		</div>
		<div style="display:flex;gap:10px">
			<button type="button" class="fm-btn fm-btn--contorno" style="flex:1" onclick={oneditar}>Editar</button>
			<button type="button" class="fm-btn" style="flex:1" onclick={ontirar}>Tirar de uso…</button>
		</div>

		{#if novo}
			<p class="ins-aviso azul" style="font-size:15px">
				<span>
					<strong>Categoria criada.</strong> Ela já aparece ao criar acampamentos. Para o primeiro acampamento dela sair completo, monte os
					formulários e o modelo de setores aqui embaixo.
				</span>
			</p>
		{/if}

		{#if c.porAcontecer.length > 0}
			<div style="display:flex;flex-direction:column;gap:6px">
				<span class="ins-rot">Por acontecer</span>
				{#each c.porAcontecer as a (a.id)}
					{@const fase = faseDoAcampamento(a)}
					<div class="cat-acamp">
						<span><strong>{a.nome}</strong><small>{dataDoAcampamento(a)}</small></span>
						<span class="cat-fase cat-fase--{fase}">{TEXTO_DA_FASE[fase]}</span>
					</div>
				{/each}
			</div>
		{/if}

		<dl class="cat-regras">
			<div><dt>Quem pode ser campista</dt><dd>{textos.campista}</dd></div>
			<div><dt>Quem pode servir aqui</dt><dd>{textos.servo}</dd></div>
			<div><dt>Quem foi campista aqui</dt><dd>{c.naEscada ? `Pode servir ${textoPodeServirEm(c, categorias)}.` : `Só no ${c.nome}.`}</dd></div>
			<div>
				<dt>Quem preenche o formulário</dt>
				<dd>
					{c.preenchidoPeloResponsavel
						? 'O pai, a mãe ou o responsável. O formulário mostra “Dados da criança” e fala “a criança” em vez de “você”.'
						: 'A própria pessoa.'}
				</dd>
			</div>
		</dl>

		<div class="cat-completar">
			<div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap;padding-bottom:8px">
				<h3 class="cat-h3" style="font-size:21px">{falta ? `Para completar · falta ${falta} de 3` : 'Para completar · tudo pronto'}</h3>
				<span style="font-size:14px;color:var(--tinta-3)">Não impede de criar acampamentos.</span>
			</div>
			{#each passos as p (p.rot)}
				<div class="cat-passo">
					<span class="cat-passo-icone" class:ok={p.ok} aria-hidden="true">{p.ok ? '✓' : '!'}</span>
					<span style="display:flex;flex-direction:column;gap:1px;min-width:0">
						<strong style="font-size:16px;color:var(--tinta)">{p.rot}<span class="ins-sr">{p.ok ? ': pronto' : ': falta'}</span></strong>
						<span style="font-size:14px;line-height:1.35" style:color={p.ok ? 'var(--tinta-3)' : 'var(--aviso-tx)'}>{p.ok ? p.feito : p.vazio}</span>
					</span>
					{#if p.href}
						<a href={p.href} class="ins-acao" style="height:36px;padding:0 12px;font-size:14px">{p.link} <span aria-hidden="true">→</span></a>
					{:else}
						<span></span>
					{/if}
				</div>
			{/each}
		</div>
	</div>
</section>

<style>
	.cat-regras {
		margin: 0;
		display: flex;
		flex-direction: column;
	}
	.cat-regras div {
		display: flex;
		flex-direction: column;
		gap: 3px;
		padding: 10px 0;
		border-top: 1px solid var(--linha);
	}
	.cat-regras dt {
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}
	.cat-regras dd {
		margin: 0;
		font-size: 16px;
		line-height: 1.45;
		color: var(--tinta);
	}
	.cat-completar {
		display: flex;
		flex-direction: column;
		padding: 14px 16px 4px;
		background: #fbf6e6;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}
	.cat-passo {
		display: grid;
		grid-template-columns: 28px minmax(0, 1fr) auto;
		gap: 10px;
		align-items: center;
		padding: 10px 0;
		border-top: 1px solid var(--linha);
	}
	.cat-passo-icone {
		width: 26px;
		height: 26px;
		box-sizing: border-box;
		border-radius: 50%;
		border: 1px solid #ebd39c;
		background: #fbf0d6;
		color: #6a480c;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 14px;
		font-weight: 700;
	}
	.cat-passo-icone.ok {
		border-color: #b9d9c4;
		background: #e4f1e8;
		color: #1b5236;
	}
</style>
