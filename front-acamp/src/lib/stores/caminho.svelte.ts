/**
 * Tela aberta "dentro" de uma tela do menu, para o caminho da barra superior do
 * painel: "Painel da Comunidade › Formulários › Juvenil · Campista". A página
 * preenche ao abrir e limpa ao sair; o layout transforma a tela do menu num link.
 */
export const caminhoDoPainel = $state<{ subtela: string | null }>({ subtela: null });
