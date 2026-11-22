<script lang="ts">
	/** "Setores de Servos" virou a aba "Modelo por categoria" de Equipes de Servos. */
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';

	onMount(() => goto('/admin/equipes?aba=modelo', { replaceState: true }));
</script>
