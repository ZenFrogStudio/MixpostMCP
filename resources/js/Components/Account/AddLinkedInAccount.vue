<script setup>
import { Link } from '@inertiajs/vue3'
import LinkedInIcon from "@/Icons/LinkedIn.vue";
</script>
<template>
    <Link :href="route('mixpostmcp.accounts.add', {provider: 'linkedin'})" method="post" as="button" type="button" class="w-full flex items-center px-lg py-4 hover:bg-linkedin/20 ease-in-out duration-200">
        <span class="flex mr-4">
            <LinkedInIcon class="text-linkedin"/>
        </span>
        <span class="flex flex-col items-start">
            <span class="font-medium">LinkedIn</span>
            <span>Connect a new LinkedIn profile or company page</span>
        </span>
    </Link>
</template>
