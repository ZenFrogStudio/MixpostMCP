<script setup>
import { Link } from '@inertiajs/vue3'
import TikTokIcon from "@/Icons/TikTok.vue";
</script>
<template>
    <Link :href="route('mixpostmcp.accounts.add', {provider: 'tiktok'})" method="post" as="button" type="button" class="w-full flex items-center px-lg py-4 hover:bg-tiktok/20 ease-in-out duration-200">
        <span class="flex mr-4">
            <TikTokIcon class="text-tiktok"/>
        </span>
        <span class="flex flex-col items-start">
            <span class="font-medium">TikTok</span>
            <span>Connect a new TikTok account</span>
        </span>
    </Link>
</template>
