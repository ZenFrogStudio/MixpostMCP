<script setup>
import { Link } from '@inertiajs/vue3'
import InstagramIcon from "@/Icons/Instagram.vue";
</script>
<template>
    <Link :href="route('mixpost.accounts.add', {provider: 'instagram'})" method="post" as="button" type="button" class="w-full flex items-center px-lg py-4 hover:bg-instagram/20 ease-in-out duration-200">
        <span class="flex mr-4">
            <InstagramIcon class="text-instagram"/>
        </span>
        <span class="flex flex-col items-start">
            <span class="font-medium">Instagram</span>
            <span>Connect a new Instagram business account</span>
        </span>
    </Link>
</template>
