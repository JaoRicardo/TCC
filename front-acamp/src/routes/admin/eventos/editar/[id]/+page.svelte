<script lang="ts">
	import { page } from "$app/stores";
	import EventoForm from "$lib/components/EventoForm.svelte";

	const id = $page.params.id as string;
</script>

<EventoForm activityId={id} />
