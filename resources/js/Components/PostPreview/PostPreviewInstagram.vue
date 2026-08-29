<script setup>
///////////////////////////*Scope*///////////////////////////
/* Instagram preview: a square media frame with the caption underneath, which is the part of an
   Instagram post someone recognises at a glance. Instagram refuses a post that carries no media,
   so the empty frame says that outright instead of rendering a blank card. */

import {computed} from "vue";
import Panel from "@/Components/Surface/Panel.vue";
import EditorReadOnly from "@/Components/Package/EditorReadOnly.vue";
import Play from "@/Icons/Play.vue";
import Photo from "@/Icons/Photo.vue";
import Chat from "@/Icons/Chat.vue";
import Share from "@/Icons/Share.vue";

const props = defineProps({
    name: {
        required: true,
        type: String
    },
    username: {
        required: true,
        type: String
    },
    image: {
        required: true,
        type: String
    },
    content: {
        required: true,
        type: Array,
    },
    options: {
        type: Object,
        default: () => ({})
    }
})

const mainContent = computed(() => {
    return props.content[0];
});

// Instagram crops every item to the same frame and shows the rest as a carousel, so only the
// first item tells the user what the post will look like.
const coverMedia = computed(() => {
    return mainContent.value.media.length ? mainContent.value.media[0] : null;
});

// share_to_feed only applies to a video, which Instagram publishes as a Reel. Saying so on a photo
// post would describe a setting that does nothing.
const showsReelInFeed = computed(() => {
    return coverMedia.value?.is_video && props.options.share_to_feed;
});
</script>
<template>
    <Panel :with-padding="false" class="relative overflow-hidden">
        <div class="flex items-center p-sm">
            <span class="inline-flex justify-center items-center shrink-0 w-8 h-8 rounded-full mr-xs">
                <img :src="image" class="object-cover w-full h-full rounded-full" alt=""/>
            </span>
            <div class="font-medium">{{ username }}</div>
        </div>

        <div class="relative aspect-square bg-gray-100">
            <img v-if="coverMedia" :src="coverMedia.thumb_url" class="object-cover w-full h-full" alt=""/>

            <div v-else class="flex flex-col items-center justify-center w-full h-full text-gray-400">
                <Photo class="w-8! h-8!"/>
                <div class="mt-xs px-lg text-center">Instagram needs a photo or video</div>
            </div>

            <div v-if="coverMedia?.is_video"
                 class="absolute inset-0 flex items-center justify-center">
                <span class="flex items-center justify-center w-14 h-14 rounded-full border-2 border-white text-white bg-black/30">
                    <Play class="w-8! h-8!"/>
                </span>
            </div>

            <div v-if="mainContent.media.length > 1"
                 class="absolute top-xs right-xs px-2 py-1 rounded-full bg-black/60 text-white text-sm">
                1/{{ mainContent.media.length }}
            </div>
        </div>

        <div class="p-sm">
            <div class="flex items-center text-gray-700">
                <svg class="w-6 h-6 mr-sm text-instagram" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 21.638h-.014C9.403 21.59 1.95 14.856 1.95 8.478c0-3.064 2.525-5.754 5.403-5.754 2.29 0 3.83 1.58 4.646 2.73.814-1.148 2.354-2.73 4.645-2.73 2.88 0 5.404 2.69 5.404 5.755 0 6.376-7.454 13.11-10.037 13.157H12z"></path>
                </svg>
                <Chat class="w-6! h-6! mr-sm"/>
                <Share class="w-6! h-6!"/>
            </div>

            <EditorReadOnly :value="mainContent.body" class="mt-xs"/>

            <div v-if="showsReelInFeed" class="mt-xs text-gray-500 text-sm">
                This Reel will also show in the profile feed.
            </div>
        </div>
    </Panel>
</template>
