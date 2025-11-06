<script setup>
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <div>
        <Link :href="route('stream.create')">Create Stream</Link>
    </div>
</template>