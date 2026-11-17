<script lang="ts">
	/**
	 * Ficha da pessoa no painel: a organização vê e corrige o cadastro de
	 * alguém, inclusive CPF e nascimento (que a própria pessoa não muda em "Meu
	 * cadastro"). Abre pela lista de Pessoas e acessos.
	 *
	 * Coluna esquerda: o que se edita e só vale depois de "Salvar alterações".
	 * Coluna direita: o que vale na hora ou é só consulta — nada ali depende do
	 * botão Salvar.
	 */
	import '$lib/styles/formularios.css';
	import { onDestroy, untrack } from 'svelte';
	import { page } from '$app/stores';
	import { beforeNavigate, goto } from '$app/navigation';
	import { auth } from '$lib/stores/auth.svelte';
	import { pode } from '$lib/permissoes';
	import { caminhoDoPainel } from '$lib/stores/caminho.svelte';
	import Janela from '$lib/components/painel/Janela.svelte';
	import JanelasDaConta from '$lib/components/painel/JanelasDaConta.svelte';
	import logoAsas from '$lib/imgs/logo-asas.png';
	import {
		mascaraCep,
		mascaraCpf,
		mascaraData,
		mascaraTelefoneOuFixo,
		cpfValido,
		dataParaIso
	} from '$lib/mascaras';
	import {
		getFicha,
		gerarSenhaProvisoria,
		salvarFicha,
		previaFicha,
		getInscricoesDaPessoa,
		getEstadosCivis,
		type DadosDaFicha,
		type ErrosDaFicha,
		type FichaPessoa,
		type InscricaoDaPessoa,
		type PreviaDaFicha,
		type ItemPermissao
	} from '$lib/services/userService';

	const userId = $derived(Number($page.params.id as string));

	/** Volta para a lista com a mesma busca, filtro e página. */
	let voltarPara = $derived.by(() => {
		const lista = new URLSearchParams();
		for (const chave of ['busca', 'filtro', 'pagina']) {
			const valor = $page.url.searchParams.get(chave);
			if (valor) lista.set(chave, valor);
		}
		const qs = lista.toString();
		return '/admin/usuarios' + (qs ? `?${qs}` : '');
	});

	// ─── Campos ───────────────────────────────────────────────────────────
	const ROTULOS = {
		nome: 'Nome completo',
		cpf: 'CPF',
		nasc: 'Data de nascimento',
		sexo: 'Sexo',
		civil: 'Estado civil',
		tel: 'Telefone',
		email: 'E-mail',
		cep: 'CEP',
		cidade: 'Cidade',
		rua: 'Rua',
		num: 'Número',
		bairro: 'Bairro'
	};
	type Campo = keyof typeof ROTULOS;
	type Campos = Record<Campo, string>;
	const ORDEM = Object.keys(ROTULOS) as Campo[];

	/** Chave do erro que vem do servidor → campo da tela. */
	const CAMPO_DO_SERVIDOR: Record<string, Campo> = {
		name: 'nome',
		cpf: 'cpf',
		birthday: 'nasc',
		sex: 'sexo',
		marital_status_id: 'civil',
		phone: 'tel',
		email: 'email',
		'address.cep': 'cep',
		'address.city': 'cidade',
		'address.street': 'rua',
		'address.number': 'num',
		'address.neighborhood': 'bairro'
	};

	const AREA_DOS_SORTEIOS = ['primavera do leste', 'poxoreu'];
	const semAcento = (s: string) =>
		s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
	const naArea = (cidade: string) => AREA_DOS_SORTEIOS.includes(semAcento(cidade));

	const isoParaBr = (iso: string | null) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '');

	function camposDa(ficha: FichaPessoa): Campos {
		return {
			nome: ficha.name ?? '',
			cpf: mascaraCpf(ficha.cpf ?? ''),
			nasc: isoParaBr(ficha.birthday),
			sexo: ficha.sex ?? '',
			civil: ficha.marital_status_id ? String(ficha.marital_status_id) : '',
			tel: mascaraTelefoneOuFixo(ficha.phone ?? ''),
			email: ficha.email ?? '',
			cep: mascaraCep(ficha.address?.cep ?? ''),
			cidade: ficha.address?.city ?? '',
			rua: ficha.address?.street ?? '',
			num: ficha.address?.number ?? '',
			bairro: ficha.address?.neighborhood ?? ''
		};
	}

	const VAZIO: Campos = { nome: '', cpf: '', nasc: '', sexo: '', civil: '', tel: '', email: '', cep: '', cidade: '', rua: '', num: '', bairro: '' };

	// ─── Carregar ─────────────────────────────────────────────────────────
	let estado: 'carregando' | 'erro' | 'naoEncontrada' | 'pronto' | 'desativada' = $state('carregando');
	let ficha = $state<FichaPessoa | null>(null);
	let catalogo: ItemPermissao[] = $state([]);
	let inscricoes = $state<InscricaoDaPessoa[] | null>(null);
	let estadosCivis: { id: number; title: string }[] = $state([]);

	/** O que está salvo e o que está sendo digitado. */
	let orig: Campos = $state({ ...VAZIO });
	let f: Campos = $state({ ...VAZIO });

	async function carregar() {
		estado = 'carregando';
		saindo = false;
		try {
			const [res, civis] = await Promise.all([getFicha(userId), getEstadosCivis()]);
			if (res.naoEncontrada) {
				estado = 'naoEncontrada';
				return;
			}
			if (!res.ficha) {
				estado = 'erro';
				return;
			}
			estadosCivis = civis;
			aplicarFicha(res.ficha);
			catalogo = res.catalogo ?? [];
			conflito = false;
			estado = 'pronto';
			carregarInscricoes();
		} catch {
			estado = 'erro';
		}
	}

	function aplicarFicha(nova: FichaPessoa) {
		ficha = nova;
		orig = camposDa(nova);
		f = { ...orig };
		tentou = false;
		errosServidor = {};
		outraConta = null;
		erroSalvar = '';
	}

	async function carregarInscricoes() {
		try {
			const res = await getInscricoesDaPessoa(userId);
			inscricoes = res.inscricoes ?? [];
		} catch {
			inscricoes = [];
		}
	}

	$effect(() => {
		// Recarrega se a ficha aberta mudar (ex.: link "Abrir a ficha de ...").
		userId;
		untrack(carregar);
	});

	// ─── Cabeçalho ────────────────────────────────────────────────────────
	let primeiro = $derived(orig.nome.split(' ')[0] || 'A pessoa');
	let curto = $derived(orig.nome.split(' ').slice(0, 2).join(' '));
	let voce = $derived(ficha?.id === auth.user?.id);

	$effect(() => {
		caminhoDoPainel.subtela = estado === 'pronto' && curto ? `Ficha de ${curto}` : 'Ficha da pessoa';
	});
	onDestroy(() => (caminhoDoPainel.subtela = null));

	function idade(br: string): number | null {
		const iso = dataParaIso(br);
		if (!iso) return null;
		const [ano, mes, dia] = iso.split('-').map(Number);
		const hoje = new Date();
		let anos = hoje.getFullYear() - ano;
		if (hoje.getMonth() + 1 < mes || (hoje.getMonth() + 1 === mes && hoje.getDate() < dia)) anos--;
		return anos;
	}

	/** Quem vê tem "Cargos e permissões"? (só aí vêm as telas liberadas da pessoa) */
	let veAcesso = $derived(ficha?.telas_liberadas !== undefined);

	let selosAcesso = $derived.by(() => {
		const telas = ficha?.telas_liberadas ?? [];
		if (catalogo.length && catalogo.every((i) => telas.includes(i.chave))) return { completo: true, areas: [] as string[] };
		const areas = catalogo.filter((i) => telas.includes(i.chave)).map((i) => i.area_curta);
		return { completo: false, areas: [...new Set(areas)] };
	});

	/** CPF de quem usa o painel: só quem cuida das permissões corrige. */
	let cpfTravado = $derived(!!ficha?.usa_painel && !pode('permissoes'));

	// ─── Alterações e erros ───────────────────────────────────────────────
	let alterados = $derived(ORDEM.filter((k) => f[k] !== orig[k]));
	const alterado = (k: Campo) => alterados.includes(k);

	/** Os erros só aparecem depois de clicar em "Salvar alterações". */
	let tentou = $state(false);
	/** Erros do servidor valem enquanto o campo tiver o mesmo valor que foi enviado. */
	let errosServidor: Partial<Record<Campo, { mensagem: string; valor: string }>> = $state({});
	let outraConta = $state<{ id: number; name: string } | null>(null);

	function validar(c: Campos): Partial<Record<Campo, string>> {
		const e: Partial<Record<Campo, string>> = {};
		for (const k of ORDEM) if (!c[k].trim()) e[k] = 'Preencha este campo.';
		if (!c.nome.trim()) e.nome = 'Escreva o nome completo.';
		if (!e.cpf && !cpfValido(c.cpf)) e.cpf = 'CPF inválido. Confira os números.';
		if (!e.nasc) {
			const iso = dataParaIso(c.nasc);
			const hoje = new Date().toISOString().slice(0, 10);
			if (!iso || iso < '1900-01-01' || iso > hoje) e.nasc = 'Data inválida. Use dia/mês/ano, por exemplo 18/11/2005.';
		}
		if (!e.tel && c.tel.replace(/\D/g, '').length < 10) {
			e.tel = 'Telefone incompleto. Use o DDD e o número, por exemplo (66) 99714-8315.';
		}
		if (!e.email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(c.email.trim())) {
			e.email = 'E-mail inválido. Confira se tem @ e o final, como nome@gmail.com.';
		}
		if (!e.cep && c.cep.replace(/\D/g, '').length !== 8) e.cep = 'CEP incompleto. São 8 números.';
		return e;
	}

	let erros = $derived.by(() => {
		if (!tentou) return {} as Partial<Record<Campo, string>>;
		const e = validar(f);
		for (const [k, erro] of Object.entries(errosServidor) as [Campo, { mensagem: string; valor: string }][]) {
			if (!e[k] && f[k] === erro.valor) e[k] = erro.mensagem;
		}
		return e;
	});
	let nErros = $derived(Object.keys(erros).length);

	/** "Telefone e E-mail", "CPF, Telefone e E-mail" */
	function listaPt(itens: string[]) {
		return itens.length <= 1 ? (itens[0] ?? '') : `${itens.slice(0, -1).join(', ')} e ${itens[itens.length - 1]}`;
	}
	let listaAlterados = $derived(listaPt(alterados.map((k) => ROTULOS[k])));
	let quantosDados = $derived(alterados.length === 1 ? '1 dado' : `${alterados.length} dados`);

	function definir(k: Campo, valor: string) {
		f[k] = valor;
		erroSalvar = '';
	}

	function digitar(k: Campo, mascara?: (v: string) => string) {
		return (e: Event & { currentTarget: HTMLInputElement }) => {
			const valor = mascara ? mascara(e.currentTarget.value) : e.currentTarget.value;
			definir(k, valor);
			if (mascara) e.currentTarget.value = valor;
		};
	}

	function desfazer() {
		f = { ...orig };
		tentou = false;
		errosServidor = {};
		outraConta = null;
		erroSalvar = '';
	}

	// ─── Salvar ───────────────────────────────────────────────────────────
	let salvando = $state(false);
	let erroSalvar = $state('');
	/** Alguém salvou a ficha depois que ela foi aberta. */
	let conflito = $state(false);

	/** Mudar algum destes abre "Conferir antes de salvar". */
	const SENSIVEIS: Campo[] = ['cpf', 'nasc', 'sexo', 'cidade'];
	/** Estes podem tirar inscrições do sorteio. */
	const DO_SORTEIO: Campo[] = ['nasc', 'sexo', 'cidade'];

	/** O que o servidor vai fazer com os dados novos (vem da prévia). */
	let previa = $state<PreviaDaFicha | null>(null);
	let conferindo = $state(false);

	function corpo(c: Campos): DadosDaFicha {
		return {
			versao: ficha?.versao ?? '',
			name: c.nome.trim(),
			cpf: c.cpf,
			birthday: dataParaIso(c.nasc) ?? '',
			sex: c.sexo,
			marital_status_id: c.civil ? Number(c.civil) : null,
			phone: c.tel,
			email: c.email.trim(),
			address: {
				cep: c.cep,
				city: c.cidade.trim(),
				street: c.rua.trim(),
				number: c.num.trim(),
				neighborhood: c.bairro.trim()
			}
		};
	}

	/** Erro do servidor (no salvar ou na prévia): marca os campos ou a barra. */
	function mostrarErros(res: ErrosDaFicha, enviado: Campos) {
		if (res.conflito) {
			conflito = true;
		} else if (res.errors && Object.keys(res.errors).length) {
			const novos: typeof errosServidor = {};
			for (const [chave, mensagem] of Object.entries(res.errors)) {
				const campo = CAMPO_DO_SERVIDOR[chave];
				if (campo) novos[campo] = { mensagem, valor: enviado[campo] };
			}
			errosServidor = novos;
			outraConta = res.outraConta ?? null;
		} else {
			erroSalvar = 'Não foi possível salvar. Verifique sua conexão e tente de novo.';
		}
	}

	async function salvar() {
		if (salvando || conferindo) return;
		tentou = true;
		if (Object.keys(validar(f)).length) return;
		if (alterados.some((k) => SENSIVEIS.includes(k))) await conferir();
		else await gravar();
	}

	/** Pede ao servidor a prévia e abre a janela; CPF repetido aparece antes dela. */
	async function conferir() {
		conferindo = true;
		erroSalvar = '';
		const enviado = { ...f };
		try {
			const res = await previaFicha(userId, corpo(enviado));
			if (res.previa) {
				previa = res.previa;
				modal = 'conferir';
			} else {
				mostrarErros(res, enviado);
			}
		} catch {
			erroSalvar = 'Não foi possível salvar. Verifique sua conexão e tente de novo.';
		} finally {
			conferindo = false;
		}
	}

	async function gravar() {
		if (!ficha) return;
		salvando = true;
		erroSalvar = '';
		const enviado = { ...f };
		try {
			const res = await salvarFicha(userId, corpo(enviado));
			if (modal === 'conferir') modal = null;
			if (res.ficha) {
				aplicarFicha(res.ficha);
				const n = res.retiradas ?? 0;
				avisar(
					n === 0
						? 'Alterações salvas.'
						: `Alterações salvas. ${n === 1 ? '1 inscrição saiu' : `${n} inscrições saíram`} do sorteio.`
				);
				carregarInscricoes();
			} else {
				mostrarErros(res, enviado);
			}
		} catch {
			if (modal === 'conferir') modal = null;
			erroSalvar = 'Não foi possível salvar. Verifique sua conexão e tente de novo.';
		} finally {
			salvando = false;
		}
	}

	// ─── Janela "Conferir antes de salvar" ────────────────────────────────
	/** Como o dado aparece na tabela "o que vai mudar". */
	function exibir(k: Campo, valor: string) {
		if (k === 'sexo') return valor === 'M' ? 'Masculino' : valor === 'F' ? 'Feminino' : valor;
		if (k === 'civil') return estadosCivis.find((e) => String(e.id) === valor)?.title ?? valor;
		return valor.trim() || '—';
	}

	let mudancas = $derived(alterados.map((k) => ({ campo: ROTULOS[k], de: exibir(k, orig[k]), para: exibir(k, f[k]) })));
	let mexeNoSorteio = $derived(alterados.some((k) => DO_SORTEIO.includes(k)));

	/** Por que esta inscrição sai, quando não é a cidade (que já tem o aviso próprio). */
	function motivoDaRetirada(motivo: string) {
		if (motivo === 'idade') return 'não tem mais a idade da categoria';
		if (motivo === 'sexo') return `não há vagas de sorteio para ${f.sexo === 'M' ? 'homens' : 'mulheres'}`;
		return '';
	}

	// ─── Acesso à conta (vale na hora, sem "Salvar alterações") ──────────
	let modal: 'conferir' | 'sair' | 'senha' | 'senhaGerada' | 'permissoes' | 'desativar' | null = $state(null);

	/** "2026-11-16 22:41:00" → "16/11 às 22:41" */
	const diaEHora = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)} às ${iso.slice(11, 16)}`;

	let gerandoSenha = $state(false);
	let erroSenha = $state('');
	/** Só existe enquanto a janela da senha gerada está aberta; nunca volta do servidor. */
	let senhaGerada = $state('');
	let copiou = $state(false);

	function abrirSenha() {
		erroSenha = '';
		modal = 'senha';
	}

	async function gerarSenha() {
		if (!ficha) return;
		gerandoSenha = true;
		erroSenha = '';
		try {
			const res = await gerarSenhaProvisoria(ficha.id);
			if (!res.senha) {
				erroSenha = res.error ?? 'Não foi possível gerar a senha.';
				return;
			}
			senhaGerada = res.senha;
			ficha.senha_provisoria_em = res.geradaEm ?? null;
			copiou = false;
			modal = 'senhaGerada';
		} catch {
			erroSenha = 'Não foi possível gerar a senha. Verifique sua conexão e tente de novo.';
		} finally {
			gerandoSenha = false;
		}
	}

	async function copiarSenha() {
		try {
			await navigator.clipboard.writeText(senhaGerada);
			copiou = true;
		} catch {
			// Sem acesso à área de transferência: a senha continua na tela para anotar.
		}
	}

	function fecharSenhaGerada() {
		senhaGerada = '';
		modal = null;
		avisar('Senha provisória gerada. A senha antiga não funciona mais.');
	}

	/** As permissões mudam o selo, o CPF travado e quem pode ser desativado. */
	async function permissoesSalvas() {
		modal = null;
		avisar('Permissões salvas.');
		const res = await getFicha(userId).catch(() => null);
		if (res?.ficha && ficha) {
			ficha.usa_painel = res.ficha.usa_painel;
			ficha.ultimo_gestor = res.ficha.ultimo_gestor;
			ficha.telas_liberadas = res.ficha.telas_liberadas;
		}
	}

	function contaDesativada() {
		modal = null;
		saindo = true;
		estado = 'desativada';
	}

	// ─── Sair sem salvar ──────────────────────────────────────────────────
	let destinoPendente: URL | null = null;
	let saindo = false;

	beforeNavigate(({ cancel, to, type }) => {
		if (saindo || estado !== 'pronto' || alterados.length === 0) return;
		cancel();
		// Fechar ou recarregar a aba: o navegador pergunta sozinho.
		if (type === 'leave' || !to) return;
		destinoPendente = to.url;
		modal = 'sair';
	});

	function sairSemSalvar() {
		saindo = true;
		modal = null;
		goto(destinoPendente ?? voltarPara);
	}

	// ─── Aviso no rodapé ──────────────────────────────────────────────────
	let toast = $state('');
	let toastTimer: ReturnType<typeof setTimeout>;
	function avisar(texto: string) {
		toast = texto;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => (toast = ''), 6000);
	}
	onDestroy(() => clearTimeout(toastTimer));

	// ─── Inscrições ───────────────────────────────────────────────────────
	const SITUACAO: Record<string, string> = {
		Inscrito: 'pen',
		'Inscrição em andamento': 'pen',
		Sorteado: 'ok',
		Indicado: 'ok',
		'Formulário Enviado': 'ok',
		Suplente: 'esp',
		'Prazo Perdido': 'can',
		'Desistência': 'can',
		'Retirado do sorteio': 'can'
	};

	let gruposInscricoes = $derived.by(() => {
		const lista = inscricoes ?? [];
		return [
			{ nome: 'Em andamento', itens: lista.filter((i) => i.andamento) },
			{ nome: 'Anteriores', itens: lista.filter((i) => !i.andamento) }
		].filter((g) => g.itens.length);
	});

	/** "Acampamento Sênior" + 2026 → "Acampamento Sênior 2026" (sem repetir o ano que já está no nome). */
	const comAno = (atividade: string, ano: number | string | null | undefined) =>
		ano && !atividade.includes(String(ano)) ? `${atividade} ${ano}` : atividade;

	const dataBr = (iso: string | null | undefined) => (iso ? isoParaBr(iso) : '—');
</script>

<svelte:head>
	<title>{curto ? `Ficha de ${curto}` : 'Ficha da pessoa'} | Painel da Comunidade</title>
</svelte:head>

<div class="fm ficha">
	<a href={voltarPara} class="voltar">← Voltar para Pessoas e acessos</a>

	{#if estado === 'carregando'}
		<div class="carregando" role="status"><span class="spinner"></span>Carregando a ficha...</div>
		<div class="osso-titulo" aria-hidden="true">
			<span class="osso" style="height:40px;width:420px"></span>
			<span class="osso osso--claro" style="height:18px;width:300px"></span>
		</div>
		<div class="colunas" aria-hidden="true">
			<div class="coluna"><div class="osso-bloco" style="height:520px"></div><div class="osso-bloco" style="height:300px"></div></div>
			<div class="coluna"><div class="osso-bloco" style="height:320px"></div><div class="osso-bloco" style="height:220px"></div></div>
		</div>
	{:else if estado === 'erro'}
		<section class="fm-cartao centro" role="alert">
			<span class="centro-icone">!</span>
			<p class="centro-titulo">Não foi possível abrir a ficha.</p>
			<p class="centro-texto">Verifique sua conexão com a internet e tente novamente.</p>
			<div class="centro-botoes">
				<a href={voltarPara} class="fm-btn fm-btn--contorno fm-btn--grande">Voltar para a lista</a>
				<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Tentar novamente</button>
			</div>
		</section>
	{:else if estado === 'desativada'}
		<section class="fm-cartao centro">
			<div class="centro-arco"><img src={logoAsas} alt="" /></div>
			<p class="centro-titulo">A conta de {orig.nome} foi desativada.</p>
			<p class="centro-texto">Ela some da lista e não consegue mais entrar no site com este CPF.</p>
			<a href={voltarPara} class="fm-btn fm-btn--ouro fm-btn--grande">Voltar para Pessoas e acessos</a>
		</section>
	{:else if estado === 'naoEncontrada'}
		<section class="fm-cartao centro">
			<div class="centro-arco"><img src={logoAsas} alt="" /></div>
			<p class="centro-titulo">Esta pessoa não está mais na lista.</p>
			<a href={voltarPara} class="fm-btn fm-btn--ouro fm-btn--grande">Voltar para Pessoas e acessos</a>
		</section>
	{:else if ficha}
		{@const idadeSalva = idade(orig.nasc)}
		<div class="cabeca">
			<div class="cabeca-textos">
				<span class="rot">Ficha da pessoa</span>
				<h1>{orig.nome}</h1>
				<span class="cabeca-linha">
					{[`CPF ${orig.cpf}`, idadeSalva !== null ? `${idadeSalva} anos` : '', `cadastro em ${dataBr(ficha.created)}`]
						.filter(Boolean)
						.join(' · ')}
				</span>
			</div>
			<div class="cabeca-selos">
				{#if naArea(orig.cidade)}
					<span class="pill ok"><i></i>Participa dos sorteios</span>
				{:else}
					<span class="pill esp"><i></i>Fora da área dos sorteios</span>
				{/if}
				{#if veAcesso}
					{#if selosAcesso.completo}
						<span class="area area--completo">Acesso completo</span>
					{:else if selosAcesso.areas.length}
						{#each selosAcesso.areas as area}<span class="area">Painel · {area}</span>{/each}
					{:else}
						<span class="so-participante">Só participante</span>
					{/if}
				{/if}
				{#if voce}<span class="selo-voce">Você</span>{/if}
			</div>
		</div>

		<div class="colunas">
			<!-- O que se edita e salva junto -->
			<div class="coluna">
				<section class="fm-cartao cartao" aria-labelledby="t-dados">
					<div>
						<h2 id="t-dados" class="h2">Dados pessoais</h2>
						<p class="sub">As alterações daqui e do Endereço só valem depois de “Salvar alterações”.</p>
					</div>
					<div class="grade">
						<div class="fld cheio">
							{@render rotulo('nome', 'f-nome')}
							<input
								id="f-nome"
								class="campo"
								class:erro={erros.nome}
								class:mudou={alterado('nome') && !erros.nome}
								value={f.nome}
								oninput={digitar('nome')}
								autocomplete="off"
								aria-invalid={erros.nome ? 'true' : undefined}
								aria-describedby={erros.nome ? 'e-nome' : undefined}
							/>
							{@render erroDo('nome')}
						</div>

						<div class="fld">
							{@render rotulo('cpf', 'f-cpf')}
							{#if cpfTravado}
								<div class="lido num" id="f-cpf">{f.cpf}</div>
								<p class="ajuda">Esta pessoa usa o painel. Para corrigir o CPF, fale com quem cuida das permissões.</p>
							{:else}
								<input
									id="f-cpf"
									class="campo num"
									class:erro={erros.cpf}
									class:mudou={alterado('cpf') && !erros.cpf}
									value={f.cpf}
									oninput={digitar('cpf', mascaraCpf)}
									inputmode="numeric"
									placeholder="000.000.000-00"
									autocomplete="off"
									aria-invalid={erros.cpf ? 'true' : undefined}
									aria-describedby={erros.cpf ? 'e-cpf' : 'a-cpf'}
								/>
								{#if erros.cpf}
									<p class="err" id="e-cpf">
										<i>!</i>
										<span>
											{erros.cpf}
											{#if outraConta && errosServidor.cpf && f.cpf === errosServidor.cpf.valor}
												<a class="link link--erro" href="/admin/pessoas/{outraConta.id}{$page.url.search}">Abrir a ficha de {outraConta.name}</a>
											{/if}
										</span>
									</p>
								{:else}
									<p class="ajuda" id="a-cpf">É o login da pessoa no site.</p>
								{/if}
							{/if}
						</div>

						<div class="fld">
							{@render rotulo('nasc', 'f-nasc')}
							<input
								id="f-nasc"
								class="campo num"
								class:erro={erros.nasc}
								class:mudou={alterado('nasc') && !erros.nasc}
								value={f.nasc}
								oninput={digitar('nasc', mascaraData)}
								inputmode="numeric"
								placeholder="dd/mm/aaaa"
								autocomplete="off"
								aria-invalid={erros.nasc ? 'true' : undefined}
								aria-describedby={erros.nasc ? 'e-nasc' : 'a-nasc'}
							/>
							{#if erros.nasc}
								{@render erroDo('nasc')}
							{:else}
								{@const anos = idade(f.nasc)}
								<p class="ajuda" id="a-nasc">
									{#if anos !== null && anos >= 0}{anos} anos.{/if} A idade define a categoria em que a pessoa pode se inscrever.
								</p>
							{/if}
						</div>

						<div class="fld">
							<span class="lbl" id="l-sexo">
								Sexo{#if alterado('sexo')}<span class="alt">Alterado</span>{/if}
							</span>
							<div class="radios" role="radiogroup" aria-labelledby="l-sexo">
								{#each [['M', 'Masculino'], ['F', 'Feminino']] as [valor, nome]}
									<button
										type="button"
										class="radio"
										class:radio--on={f.sexo === valor}
										role="radio"
										aria-checked={f.sexo === valor}
										onclick={() => definir('sexo', valor)}
									>
										<span class="radio-bola" aria-hidden="true"></span>{nome}
									</button>
								{/each}
							</div>
							{#if erros.sexo}
								{@render erroDo('sexo')}
							{:else}
								<p class="ajuda">Os sorteios têm vagas separadas por sexo.</p>
							{/if}
						</div>

						<div class="fld">
							{@render rotulo('civil', 'f-civil')}
							<select
								id="f-civil"
								class="campo"
								class:erro={erros.civil}
								class:mudou={alterado('civil') && !erros.civil}
								value={f.civil}
								onchange={(e) => definir('civil', e.currentTarget.value)}
								aria-invalid={erros.civil ? 'true' : undefined}
							>
								{#if !f.civil}<option value="">Escolha</option>{/if}
								{#each estadosCivis as civil}
									<option value={String(civil.id)}>{civil.title}</option>
								{/each}
							</select>
							{#if erros.civil}
								{@render erroDo('civil')}
							{:else}
								<p class="ajuda">Conta para a categoria Casais.</p>
							{/if}
						</div>

						<div class="fld">
							{@render rotulo('tel', 'f-tel', 'Telefone (WhatsApp)')}
							<input
								id="f-tel"
								class="campo num"
								class:erro={erros.tel}
								class:mudou={alterado('tel') && !erros.tel}
								value={f.tel}
								oninput={digitar('tel', mascaraTelefoneOuFixo)}
								inputmode="tel"
								placeholder="(00) 00000-0000"
								autocomplete="off"
								aria-invalid={erros.tel ? 'true' : undefined}
								aria-describedby={erros.tel ? 'e-tel' : undefined}
							/>
							{@render erroDo('tel')}
						</div>

						<div class="fld">
							{@render rotulo('email', 'f-email')}
							<input
								id="f-email"
								class="campo"
								class:erro={erros.email}
								class:mudou={alterado('email') && !erros.email}
								value={f.email}
								oninput={digitar('email')}
								inputmode="email"
								placeholder="nome@gmail.com"
								autocomplete="off"
								aria-invalid={erros.email ? 'true' : undefined}
								aria-describedby={erros.email ? 'e-email' : undefined}
							/>
							{@render erroDo('email')}
						</div>
					</div>
				</section>

				<section class="fm-cartao cartao" aria-labelledby="t-end">
					<div class="cartao-topo">
						<div>
							<h2 id="t-end" class="h2">Endereço</h2>
							<p class="sub">Só quem mora em Primavera do Leste ou Poxoréu participa dos sorteios dos acampamentos.</p>
						</div>
						{#if naArea(f.cidade)}
							<span class="pill ok"><i></i>Participa dos sorteios</span>
						{:else}
							<span class="pill esp"><i></i>Fora da área dos sorteios</span>
						{/if}
					</div>
					<div class="grade">
						<div class="fld">
							{@render rotulo('cep', 'f-cep')}
							<input
								id="f-cep"
								class="campo num estreito"
								class:erro={erros.cep}
								class:mudou={alterado('cep') && !erros.cep}
								value={f.cep}
								oninput={digitar('cep', mascaraCep)}
								inputmode="numeric"
								placeholder="00000-000"
								autocomplete="off"
								aria-invalid={erros.cep ? 'true' : undefined}
								aria-describedby={erros.cep ? 'e-cep' : undefined}
							/>
							{@render erroDo('cep')}
						</div>
						<div class="fld">
							{@render rotulo('cidade', 'f-cidade')}
							<input
								id="f-cidade"
								class="campo"
								class:erro={erros.cidade}
								class:mudou={alterado('cidade') && !erros.cidade}
								value={f.cidade}
								oninput={digitar('cidade')}
								autocomplete="off"
								aria-invalid={erros.cidade ? 'true' : undefined}
								aria-describedby={erros.cidade ? 'e-cidade' : undefined}
							/>
							{@render erroDo('cidade')}
						</div>
						<div class="fld cheio">
							{@render rotulo('rua', 'f-rua')}
							<input
								id="f-rua"
								class="campo"
								class:erro={erros.rua}
								class:mudou={alterado('rua') && !erros.rua}
								value={f.rua}
								oninput={digitar('rua')}
								autocomplete="off"
								aria-invalid={erros.rua ? 'true' : undefined}
								aria-describedby={erros.rua ? 'e-rua' : undefined}
							/>
							{@render erroDo('rua')}
						</div>
						<div class="fld">
							{@render rotulo('num', 'f-num')}
							<input
								id="f-num"
								class="campo estreito"
								class:erro={erros.num}
								class:mudou={alterado('num') && !erros.num}
								value={f.num}
								oninput={digitar('num')}
								autocomplete="off"
								aria-invalid={erros.num ? 'true' : undefined}
								aria-describedby={erros.num ? 'e-num' : undefined}
							/>
							{@render erroDo('num')}
						</div>
						<div class="fld">
							{@render rotulo('bairro', 'f-bairro')}
							<input
								id="f-bairro"
								class="campo"
								class:erro={erros.bairro}
								class:mudou={alterado('bairro') && !erros.bairro}
								value={f.bairro}
								oninput={digitar('bairro')}
								autocomplete="off"
								aria-invalid={erros.bairro ? 'true' : undefined}
								aria-describedby={erros.bairro ? 'e-bairro' : undefined}
							/>
							{@render erroDo('bairro')}
						</div>
					</div>
					{#if alterado('cidade') && naArea(orig.cidade) && !naArea(f.cidade)}
						<div class="aviso av-amb">
							<i></i>
							<span>
								Com a cidade nova, {primeiro} fica <strong>fora da área dos sorteios</strong>. Ao salvar, as inscrições
								que aguardam sorteio saem do sorteio.
							</span>
						</div>
					{/if}
				</section>
			</div>

			<!-- O que vale na hora ou é só consulta -->
			<div class="coluna">
				<section class="fm-cartao cartao cartao--conta" aria-labelledby="t-conta">
					<div>
						<h2 id="t-conta" class="h2">Acesso à conta</h2>
						<p class="sub">Estas ações valem na hora, sem precisar salvar.</p>
					</div>
					<div class="linhas">
						<div class="linha">
							<span class="rot">Senha</span>
							{#if voce}
								<p class="ajuda ajuda--16">Para trocar a sua própria senha, use “Meu cadastro” no site.</p>
							{:else if cpfTravado}
								<p class="ajuda ajuda--16">Esta pessoa usa o painel. Para gerar uma senha nova, fale com quem cuida das permissões.</p>
							{:else}
								{#if ficha.senha_provisoria_em}
									<span class="pill esp sozinho"><i></i>Senha provisória gerada em {diaEHora(ficha.senha_provisoria_em)}</span>
									<p class="ajuda">{primeiro} vai ter que criar uma senha própria no primeiro acesso.</p>
								{:else}
									<p class="ajuda ajuda--16">A pessoa esqueceu a senha? Gere uma senha provisória e passe para ela.</p>
								{/if}
								<button type="button" class="fm-btn fm-btn--contorno sozinho" onclick={abrirSenha}>
									{ficha.senha_provisoria_em ? 'Gerar outra senha provisória' : 'Gerar senha provisória'}
								</button>
							{/if}
						</div>

						{#if veAcesso}
							<div class="linha">
								<span class="rot">Acesso ao painel</span>
								<div class="selos-acesso">
									{#if selosAcesso.completo}
										<span class="area area--completo">Acesso completo</span>
									{:else if selosAcesso.areas.length}
										{#each selosAcesso.areas as area}<span class="area">{area}</span>{/each}
									{:else}
										<span class="so-participante so-participante--16">Só participante: não entra no painel.</span>
									{/if}
								</div>
								<button type="button" class="fm-btn fm-btn--contorno sozinho" onclick={() => (modal = 'permissoes')}>
									Editar permissões
								</button>
							</div>
						{/if}

						<div class="linha">
							<span class="rot">Desativar conta</span>
							{#if voce || ficha.ultimo_gestor}
								<button type="button" class="fm-btn trava sozinho" disabled aria-describedby="nao-desativa">Desativar conta</button>
								<p class="ajuda" id="nao-desativa">
									{voce
										? 'É a sua conta: não dá para desativar.'
										: 'É a única pessoa que cuida das permissões. Dê essa permissão a outra pessoa antes de desativar.'}
								</p>
							{:else}
								<p class="ajuda ajuda--16">A pessoa some da lista e não consegue mais entrar no site.</p>
								<button type="button" class="fm-btn fm-btn--perigo sozinho" onclick={() => (modal = 'desativar')}>
									Desativar conta
								</button>
							{/if}
						</div>
					</div>
				</section>

				<section class="fm-cartao cartao cartao--lista" aria-labelledby="t-insc">
					<div>
						<h2 id="t-insc" class="h2">Inscrições</h2>
						<p class="sub">
							Só para consulta. Corrigir nascimento, sexo ou cidade pode tirar do sorteio as inscrições que ainda
							aguardam sorteio.
						</p>
					</div>
					{#if inscricoes === null}
						<div class="carregando carregando--pequeno" role="status"><span class="spinner"></span>Carregando...</div>
					{:else if gruposInscricoes.length === 0}
						<p class="ajuda ajuda--16">Nenhuma inscrição ainda.</p>
					{:else}
						{#each gruposInscricoes as grupo}
							<div class="grupo">
								<span class="rot">{grupo.nome}</span>
								{#each grupo.itens as insc (insc.id)}
									<div class="insc">
										<span class="insc-textos">
											<span class="insc-nome">{comAno(insc.atividade, insc.ano)}</span>
											<span class="insc-como">{insc.como}</span>
										</span>
										<span class="pill {SITUACAO[insc.status] ?? 'neu'}"><i></i>{insc.status}</span>
									</div>
								{/each}
							</div>
						{/each}
					{/if}
				</section>
			</div>
		</div>

		{#if alterados.length || conflito}
			<div class="barra" role="region" aria-label="Alterações não salvas">
				{#if conflito}
					<span class="err err--barra" role="alert">
						<i>!</i>Esta ficha foi alterada por outra pessoa. Recarregue para ver a versão nova antes de salvar.
					</span>
					<div class="barra-botoes">
						<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={carregar}>Recarregar</button>
					</div>
				{:else}
					{#if nErros}
						<span class="err err--barra" role="alert">
							<i>!</i>{nErros === 1 ? 'Corrija 1 campo antes de salvar.' : `Corrija ${nErros} campos antes de salvar.`}
						</span>
					{:else if erroSalvar}
						<span class="err err--barra" role="alert"><i>!</i>{erroSalvar}</span>
					{:else}
						<span class="barra-resumo">
							<span class="barra-ponto" aria-hidden="true"></span>
							<span><strong>Você alterou {quantosDados}</strong> · {listaAlterados}</span>
						</span>
					{/if}
					<div class="barra-botoes">
							<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={desfazer} disabled={salvando || conferindo}>
							Desfazer
						</button>
						<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={salvar} disabled={salvando || conferindo}>
							{#if salvando}<span class="spinner spinner--botao"></span>Salvando...
							{:else if conferindo}<span class="spinner spinner--botao"></span>Conferindo...
							{:else}Salvar alterações{/if}
						</button>
					</div>
				{/if}
			</div>
		{/if}
	{/if}
</div>

{#snippet rotulo(k: Campo, id: string, texto: string = ROTULOS[k])}
	<label for={id} class="lbl">{texto}{#if alterado(k)}<span class="alt">Alterado</span>{/if}</label>
{/snippet}

{#snippet erroDo(k: Campo)}
	{#if erros[k]}<p class="err" id="e-{k}"><i>!</i>{erros[k]}</p>{/if}
{/snippet}

{#if modal === 'conferir' && previa}
	<Janela
		idTitulo="t-conf"
		largura={680}
		faixa="linear-gradient(90deg,#C08A1E,#B69E55)"
		travada={salvando}
		onfechar={() => (modal = null)}
	>
		<h2 id="t-conf" class="fm-janela-titulo">Conferir antes de salvar</h2>
		<p class="janela-texto">Você está mudando dados importantes de <strong>{orig.nome}</strong>.</p>

		<div class="mudancas" role="table" aria-label="O que vai mudar">
			<div role="row" class="mudancas-linha">
				<span role="columnheader" class="rot mudancas-cab">Dado</span>
				<span role="columnheader" class="rot mudancas-cab">Antes</span>
				<span class="mudancas-cab" aria-hidden="true"></span>
				<span role="columnheader" class="rot mudancas-cab">Depois</span>
			</div>
			{#each mudancas as m (m.campo)}
				<div role="row" class="mudancas-linha">
					<span role="rowheader" class="mudancas-campo">{m.campo}</span>
					<span role="cell" class="mudancas-de">{m.de}</span>
					<span class="mudancas-seta" aria-hidden="true">→</span>
					<span role="cell" class="mudancas-para">{m.para}</span>
				</div>
			{/each}
		</div>

		<div class="efeitos">
			<span class="rot">O que acontece ao salvar</span>
			{#if alterado('cpf')}
				<div class="aviso av-azul">
					<i></i>
					<span>
						{voce
							? `Você vai passar a entrar no site com o CPF ${f.cpf}.`
							: `${primeiro} passa a entrar no site com o CPF ${f.cpf}. O CPF antigo deixa de funcionar. Avise a pessoa.`}
					</span>
				</div>
			{/if}
			{#if previa.saiu_da_area}
				<div class="aviso av-amb">
					<i></i><span>{primeiro} passa a ficar <strong>fora da área dos sorteios</strong>.</span>
				</div>
			{/if}
			{#if previa.afetadas.length}
				<div class="aviso av-verm">
					<i></i>
					<div class="afetadas">
						<strong>
							{previa.afetadas.length === 1
								? '1 inscrição sai do sorteio:'
								: `${previa.afetadas.length} inscrições saem do sorteio:`}
						</strong>
						{#each previa.afetadas as a (a.id)}
							<span class="afetada">
								<strong class="afetada-nome">{comAno(a.atividade, a.ano)}</strong>
								· {[a.como, motivoDaRetirada(a.motivo)].filter(Boolean).join(', ')}:
								<span class="pill pen pill--mini"><i></i>Inscrito</span>
								→
								<span class="pill can pill--mini"><i></i>Retirado do sorteio</span>
							</span>
						{/each}
					</div>
				</div>
			{:else if mexeNoSorteio}
				<div class="aviso av-azul"><i></i><span>Nenhuma inscrição aguardando sorteio é afetada.</span></div>
			{/if}
			{#if mexeNoSorteio && previa.ja_sorteadas}
				<p class="ajuda ajuda--16">
					Inscrições já sorteadas, de suplente ou com formulário enviado continuam como estão. Se for preciso mudar
					alguma, use Inscritos e Sorteios.
				</p>
			{/if}
		</div>

		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={() => (modal = null)} disabled={salvando}>
				Voltar e revisar
			</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" data-foco-inicial onclick={gravar} disabled={salvando}>
				{#if salvando}<span class="spinner spinner--botao"></span>Salvando...{:else}Salvar alterações{/if}
			</button>
		</div>
	</Janela>
{/if}

{#if modal === 'sair'}
	<Janela idTitulo="t-sair" largura={580} faixa="linear-gradient(90deg,#C08A1E,#B69E55)" onfechar={() => (modal = null)}>
		<h2 id="t-sair" class="fm-janela-titulo">Sair sem salvar?</h2>
		<p class="janela-texto">
			Você alterou {quantosDados} na ficha de {curto}: {listaAlterados}. Se sair agora, essas alterações se perdem.
		</p>
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--perigo fm-btn--grande" onclick={sairSemSalvar}>Sair sem salvar</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" data-foco-inicial onclick={() => (modal = null)}>
				Continuar editando
			</button>
		</div>
	</Janela>
{/if}

{#if modal === 'senha' && ficha}
	<Janela idTitulo="t-senha" travada={gerandoSenha} onfechar={() => (modal = null)}>
		<h2 id="t-senha" class="fm-janela-titulo">Gerar senha provisória para {curto}?</h2>
		<ul class="fm-janela-lista">
			<li>A senha atual de {primeiro} deixa de funcionar na hora.</li>
			<li>Você vai ver a senha nova <strong>uma única vez</strong>, para passar para a pessoa.</li>
			<li>No primeiro acesso, {primeiro} vai ter que criar uma senha própria.</li>
		</ul>
		{#if erroSenha}
			<p class="err" role="alert"><i>!</i>{erroSenha}</p>
		{/if}
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" onclick={() => (modal = null)} disabled={gerandoSenha}>
				Cancelar
			</button>
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={gerarSenha} disabled={gerandoSenha}>
				{#if gerandoSenha}<span class="spinner spinner--botao"></span>Gerando...{:else}Gerar senha{/if}
			</button>
		</div>
	</Janela>
{/if}

{#if modal === 'senhaGerada'}
	<!-- Não fecha com Esc nem com clique fora: a senha não aparece de novo. -->
	<Janela idTitulo="t-senha-gerada" travada onfechar={fecharSenhaGerada}>
		<h2 id="t-senha-gerada" class="fm-janela-titulo">Senha provisória de {curto}</h2>
		<div class="senha-caixa">
			<span class="senha-texto" aria-label="Senha: {senhaGerada.split('').join(' ')}">{senhaGerada}</span>
			{#if copiou}
				<span class="pill ok copiada" role="status"><i></i>Copiada</span>
			{:else}
				<button type="button" class="fm-btn fm-btn--contorno fm-btn--grande" data-foco-inicial onclick={copiarSenha}>Copiar senha</button>
			{/if}
		</div>
		<div class="aviso av-amb">
			<i></i><span><strong>Esta senha não aparece de novo.</strong> Copie ou anote antes de fechar.</span>
		</div>
		<ul class="fm-janela-lista lista-16">
			<li>Passe só para a própria pessoa, de preferência pessoalmente ou pelo WhatsApp dela: {orig.tel}.</li>
			<li>Ela entra com o CPF {orig.cpf} e esta senha. Logo em seguida, o site pede para ela criar uma senha própria.</li>
		</ul>
		<div class="fm-janela-acoes">
			<button type="button" class="fm-btn fm-btn--ouro fm-btn--grande" onclick={fecharSenhaGerada}>Já passei a senha, fechar</button>
		</div>
	</Janela>
{/if}

{#if (modal === 'permissoes' || modal === 'desativar') && ficha}
	<JanelasDaConta
		tipo={modal}
		alvo={{ id: ficha.id, name: orig.nome, cpf: orig.cpf, email: orig.email }}
		onfechar={() => (modal = null)}
		onpermissoes={permissoesSalvas}
		ondesativado={contaDesativada}
	/>
{/if}

{#if toast}
	<div class="fm-toast" class:toast--alto={alterados.length > 0} role="status">
		<span class="toast-ok" aria-hidden="true">✓</span><span>{toast}</span>
		<button type="button" class="fm-toast-fechar" aria-label="Fechar aviso" onclick={() => (toast = '')}>×</button>
	</div>
{/if}

<style>
	.ficha {
		padding: 24px 32px 40px;
		--campo-alterado: #fffcf2;
		--selo-alterado-bg: #f1e8cf;
		--selo-alterado-tx: #6b5822;
		--campo-lido-bg: #f1ece0;
		--toast-ok: #7fd1a0;
	}

	.voltar {
		align-self: flex-start;
		display: flex;
		align-items: center;
		min-height: 40px;
		font-size: 16px;
		font-weight: 600;
		color: var(--azul);
		text-decoration: none;
	}

	.voltar:hover {
		color: var(--ouro-texto);
	}

	.rot {
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}

	/* ─── Cabeçalho ─── */
	.cabeca {
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		gap: 20px;
		flex-wrap: wrap;
	}

	.cabeca-textos {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.cabeca h1 {
		margin: 0;
		font: 600 44px/1.05 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.cabeca-linha {
		font-size: 17px;
		color: var(--tinta-3);
		font-variant-numeric: tabular-nums;
	}

	.cabeca-selos {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		align-items: center;
		justify-content: flex-end;
	}

	.area {
		font-size: 14px;
		font-weight: 600;
		color: var(--azul);
		background: #e6ebf5;
		border: 1px solid #c4cfe6;
		border-radius: 999px;
		padding: 3px 11px;
	}

	.area--completo {
		font-weight: 700;
		color: var(--azul-noite);
		background: var(--ouro-claro);
		border-color: #cdb878;
	}

	.so-participante {
		font-size: 15px;
		color: #5e6272;
	}

	.selo-voce {
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--ouro-claro);
		background: var(--azul);
		border-radius: 999px;
		padding: 3px 10px;
	}

	/* ─── Colunas e cartões ─── */
	.colunas {
		display: grid;
		grid-template-columns: minmax(0, 1fr) 430px;
		gap: 24px;
		align-items: start;
	}

	.coluna {
		display: flex;
		flex-direction: column;
		gap: 22px;
		min-width: 0;
	}

	.cartao {
		display: flex;
		flex-direction: column;
		gap: 20px;
		padding: 24px 28px 28px;
	}

	.cartao--lista {
		gap: 14px;
	}

	.cartao-topo {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 16px;
	}

	.h2 {
		margin: 0;
		font: 600 30px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.sub {
		margin: 4px 0 0;
		font-size: 16px;
		line-height: 1.4;
		color: var(--tinta-3);
	}

	/* ─── Campos ─── */
	.grade {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 20px 24px;
	}

	.cheio {
		grid-column: 1 / -1;
	}

	.fld {
		display: flex;
		flex-direction: column;
		gap: 8px;
		min-width: 0;
	}

	.lbl {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
	}

	.alt {
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.06em;
		text-transform: uppercase;
		color: var(--selo-alterado-tx);
		background: var(--selo-alterado-bg);
		border-radius: 999px;
		padding: 2px 8px;
	}

	.campo {
		width: 100%;
		box-sizing: border-box;
		height: 52px;
		padding: 0 16px;
		font: 400 17px 'Source Sans 3', sans-serif;
		color: var(--tinta);
		background: #ffffff;
		border: 1.5px solid var(--campo-borda);
		border-radius: 10px;
		outline: none;
	}

	select.campo {
		padding: 0 12px;
	}

	.campo::placeholder {
		color: #7c7f8e;
	}

	/* Foco: borda azul mais grossa, sem anel dourado. */
	.ficha .campo:focus,
	.ficha .campo:focus-visible {
		border-color: var(--azul);
		box-shadow: 0 0 0 1px var(--azul);
	}

	.campo.mudou {
		background: var(--campo-alterado);
	}

	.ficha .campo.erro {
		border-color: var(--vermelho);
		box-shadow: 0 0 0 1px var(--vermelho);
	}

	.num {
		font-variant-numeric: tabular-nums;
	}

	.estreito {
		max-width: 220px;
	}

	.lido {
		display: flex;
		align-items: center;
		height: 52px;
		box-sizing: border-box;
		padding: 0 16px;
		font-size: 17px;
		color: var(--tinta-3);
		background: var(--campo-lido-bg);
		border: 1.5px solid var(--borda-suave);
		border-radius: 10px;
	}

	.ajuda {
		margin: 0;
		font-size: 15px;
		line-height: 1.4;
		color: var(--tinta-3);
	}

	.ajuda--16 {
		font-size: 16px;
	}

	.err {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		margin: 0;
		font-size: 15px;
		line-height: 1.4;
		font-weight: 600;
		color: #842424;
	}

	.err i {
		flex: 0 0 20px;
		height: 20px;
		border-radius: 50%;
		background: var(--vermelho);
		color: var(--pergaminho);
		font: 700 13px/20px 'Source Sans 3', sans-serif;
		font-style: normal;
		text-align: center;
	}

	.err--barra {
		font-size: 17px;
		align-items: center;
	}

	.link {
		font-weight: 600;
		color: var(--azul);
		text-decoration: underline;
		text-underline-offset: 3px;
	}

	.link--erro {
		color: #842424;
	}

	.radios {
		display: flex;
		gap: 10px;
	}

	.radio {
		display: flex;
		align-items: center;
		gap: 10px;
		height: 52px;
		padding: 0 20px;
		font-size: 17px;
		font-weight: 600;
		color: var(--tinta);
		background: #ffffff;
		border: 1.5px solid var(--campo-borda);
		border-radius: 999px;
		cursor: pointer;
	}

	.radio-bola {
		flex: 0 0 20px;
		height: 20px;
		box-sizing: border-box;
		border-radius: 50%;
		border: 2px solid var(--campo-borda);
		background: #ffffff;
	}

	.radio--on {
		color: var(--pergaminho);
		background: var(--azul);
		border-color: var(--azul);
	}

	.radio--on .radio-bola {
		border-color: var(--pergaminho);
		background: var(--pergaminho);
		box-shadow: inset 0 0 0 3px var(--azul);
	}

	/* ─── Selos e avisos ─── */
	.pill {
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
		flex: 0 0 8px;
	}

	.ok { color: #1b5236; background: #e4f1e8; border-color: #b9d9c4; }
	.ok i { background: #2e7d4f; }
	.esp { color: #6a480c; background: #fbf0d6; border-color: #ebd39c; }
	.esp i { background: #c08a1e; }
	.can { color: #842424; background: #f6e2df; border-color: #e6bdb6; }
	.can i { background: #b33a3a; }
	.pen { color: #21376a; background: #e6ebf5; border-color: #c4cfe6; }
	.pen i { background: #3c5aa0; }
	.neu { color: #4f5468; background: #efebe1; border-color: #e3d8bc; }
	.neu i { background: #8a8c99; }

	.aviso {
		display: flex;
		gap: 10px;
		align-items: flex-start;
		padding: 12px 14px;
		border-radius: 10px;
		font-size: 16px;
		line-height: 1.45;
	}

	.aviso > i {
		flex: 0 0 8px;
		height: 8px;
		margin-top: 8px;
		border-radius: 50%;
	}

	.av-amb { background: #fbf0d6; border: 1px solid #ebd39c; color: #6a480c; }
	.av-amb > i { background: #c08a1e; }
	.av-azul { background: #e6ebf5; border: 1px solid #c4cfe6; color: #21376a; }
	.av-azul > i { background: #3c5aa0; }
	.av-verm { background: #f6e2df; border: 1px solid #e6bdb6; color: #842424; }
	.av-verm > i { background: #9b2c2c; }

	/* ─── Inscrições ─── */
	.grupo {
		display: flex;
		flex-direction: column;
	}

	.grupo .rot {
		padding-bottom: 6px;
	}

	.insc {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
		padding: 10px 0;
		border-top: 1px solid var(--linha);
	}

	.insc-textos {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}

	.insc-nome {
		font-size: 16px;
		font-weight: 600;
	}

	.insc-como {
		font-size: 15px;
		color: var(--tinta-3);
	}

	/* ─── Barra de salvar ─── */
	.barra {
		position: sticky;
		bottom: 16px;
		z-index: 10;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 20px;
		padding: 14px 16px 14px 24px;
		background: var(--pergaminho);
		border: 1px solid var(--ouro);
		border-radius: 16px;
		box-shadow: 0 18px 40px -16px rgba(15, 29, 61, 0.55);
	}

	.barra-resumo {
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 17px;
	}

	.barra-ponto {
		flex: 0 0 10px;
		height: 10px;
		border-radius: 50%;
		background: #c08a1e;
	}

	.barra-botoes {
		display: flex;
		gap: 10px;
		flex: 0 0 auto;
	}

	/* ─── Carregando, erro e não encontrada ─── */
	.carregando {
		display: flex;
		align-items: center;
		gap: 12px;
		font-size: 17px;
		color: var(--tinta-3);
	}

	.carregando--pequeno {
		font-size: 16px;
	}

	.spinner {
		width: 18px;
		height: 18px;
		box-sizing: border-box;
		border-radius: 50%;
		border: 2.5px solid var(--borda-suave);
		border-top-color: var(--ouro);
		animation: girar 0.8s linear infinite;
	}

	.spinner--botao {
		border-color: rgba(15, 29, 61, 0.25);
		border-top-color: var(--azul-noite);
	}

	@keyframes girar {
		to { transform: rotate(360deg); }
	}

	.osso-titulo {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	.osso {
		display: block;
		border-radius: 6px;
		background: #ede5d1;
	}

	.osso--claro {
		background: #f1eada;
	}

	.osso-bloco {
		border-radius: 14px;
		background: #f3eddf;
	}

	.centro {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 14px;
		padding: 72px 24px;
	}

	.centro-icone {
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

	.centro-arco {
		width: 110px;
		height: 120px;
		border: 1px solid #cdb878;
		border-bottom: none;
		border-radius: 50% 50% 0 0 / 40% 40% 0 0;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.centro-arco img {
		width: 100px;
		opacity: 0.5;
	}

	.centro-titulo {
		margin: 0;
		font: 600 30px 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.centro-texto {
		margin: 0;
		font-size: 17px;
		color: var(--tinta-3);
		max-width: 480px;
	}

	.centro-botoes {
		display: flex;
		gap: 10px;
		margin-top: 4px;
	}

	/* ─── Acesso à conta ─── */
	.cartao--conta {
		gap: 16px;
	}

	.linhas {
		display: flex;
		flex-direction: column;
	}

	.linha {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 18px 0;
		border-top: 1px solid var(--linha);
	}

	.linha:first-child {
		border-top: none;
		padding-top: 0;
	}

	.linha:last-child {
		padding-bottom: 0;
	}

	.sozinho {
		align-self: flex-start;
	}

	.selos-acesso {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
		align-items: center;
	}

	.so-participante--16 {
		font-size: 16px;
	}

	.ficha .trava,
	.ficha .trava:disabled {
		color: #7c7f8e;
		background: #f1ede3;
		border: 1.5px dashed var(--borda);
		opacity: 1;
		cursor: not-allowed;
	}

	.senha-caixa {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		padding: 18px 20px;
		background: #ffffff;
		border: 1.5px solid var(--azul);
		border-radius: 12px;
	}

	.senha-texto {
		font: 700 34px/1 'Source Sans 3', sans-serif;
		letter-spacing: 0.08em;
		color: var(--azul-noite);
		font-variant-numeric: tabular-nums;
	}

	.copiada {
		height: 44px;
		box-sizing: border-box;
	}

	.lista-16 {
		font-size: 16px;
	}

	/* ─── Conferir antes de salvar ─── */
	.mudancas {
		display: grid;
		grid-template-columns: minmax(150px, auto) minmax(0, 1fr) 24px minmax(0, 1fr);
		background: #ffffff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		overflow: hidden;
		font-size: 16px;
	}

	.mudancas-linha {
		display: contents;
	}

	.mudancas-linha > * {
		padding: 10px 14px;
		border-top: 1px solid var(--linha);
		overflow-wrap: anywhere;
	}

	.mudancas-linha > .mudancas-cab {
		background: var(--cabecalho-tabela);
		border-top: none;
	}

	.mudancas-linha > .mudancas-seta {
		padding: 10px 0;
		color: var(--ouro);
		font-weight: 700;
	}

	.mudancas-campo {
		font-weight: 700;
	}

	.mudancas-de {
		color: var(--tinta-3);
		text-decoration: line-through;
		text-decoration-color: #b5ae9c;
	}

	.mudancas-para {
		font-weight: 700;
		color: var(--tinta);
	}

	.efeitos {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	.afetadas {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.afetada {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		color: #2b3045;
	}

	.afetada-nome {
		color: var(--tinta);
	}

	.pill--mini {
		font-size: 14px;
		padding: 2px 8px;
	}

	/* ─── Janela e aviso ─── */
	.janela-texto {
		margin: 0;
		font-size: 17px;
		line-height: 1.45;
		color: var(--tinta-2);
	}

	.toast--alto {
		bottom: 110px;
	}

	.toast-ok {
		color: var(--toast-ok, #7fd1a0);
		font-weight: 700;
	}
</style>
