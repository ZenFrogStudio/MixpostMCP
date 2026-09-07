<script setup>
import { Link } from '@inertiajs/vue3'
import YouTubeIcon from "@/Icons/YouTube.vue";
</script>
<template>
    <Link :href="route('mixpostmcp.accounts.add', {provider: 'youtube'})" method="post" as="button" type="button" class="w-full flex items-center px-lg py-4 hover:bg-youtube/20 ease-in-out duration-200">
        <span class="flex mr-4">
            <YouTubeIcon class="text-youtube"/>
        </span>
        <span class="flex flex-col items-start">
            <span class="font-medium">YouTube</span>
            <span>Connect a new YouTube channel</span>
        </span>
    </Link>
</template>
