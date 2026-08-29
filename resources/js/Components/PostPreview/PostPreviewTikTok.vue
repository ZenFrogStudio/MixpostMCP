<script setup>
///////////////////////////*Scope*///////////////////////////
/* TikTok preview: a vertical video frame with the caption overlaid, plus the audience and the
   Duet/Stitch/comment switches, because TikTok rejects a post whose privacy level was never
   chosen. A post with no video cannot exist on TikTok at all, so the empty frame is a warning
   rather than a placeholder. */

import {computed} from "vue";
import Panel from "@/Components/Surface/Panel.vue";
import EditorReadOnly from "@/Components/Package/EditorReadOnly.vue";
import Play from "@/Icons/Play.vue";
import VideoSolid from "@/Icons/VideoSolid.vue";

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

// TikTok publishes a single video and ignores anything else attached, so the preview shows the
// first video rather than the first media item.
const video = computed(() => {
    return mainContent.value.media.find(item => item.is_video) || null;
});

// Mirrors the choices in TikTokProvider::postOptions(). Creator info can narrow which of these a
// creator may pick, but the wording stays the same.
const tiktokPrivacyLabels = {
    'PUBLIC_TO_EVERYONE': 'Everyone',
    'MUTUAL_FOLLOW_FRIENDS': 'Friends',
    'FOLLOWER_OF_CREATOR': 'Followers',
    'SELF_ONLY': 'Only me',
};

const audienceLabel = computed(() => {
    return tiktokPrivacyLabels[props.options.privacy_level] || 'No audience chosen yet';
});

const switchedOff = computed(() => {
    return [
        props.options.disable_comment ? 'Comments' : null,
        props.options.disable_duet ? 'Duet' : null,
        props.options.disable_stitch ? 'Stitch' : null,
    ].filter(Boolean);
});
</script>
<template>
    <Panel class="relative">
        <div class="flex items-center mb-sm">
            <span class="inline-flex justify-center items-center shrink-0 w-10 h-10 rounded-full mr-sm">
                <img :src="image" class="object-cover w-full h-full rounded-full" alt=""/>
            </span>
            <div class="flex flex-col">
                <div class="font-medium">{{ name }}</div>
                <div class="text-gray-400 text-sm">@{{ username }}</div>
            </div>
        </div>

        <div class="relative mx-auto w-56 aspect-9/16 rounded-xl overflow-hidden bg-black">
            <img v-if="video" :src="video.thumb_url" class="object-cover w-full h-full opacity-80" alt=""/>

            <div v-if="video" class="absolute inset-0 flex items-center justify-center">
                <span class="flex items-center justify-center w-14 h-14 rounded-full border-2 border-white text-white">
                    <Play class="w-8! h-8!"/>
                </span>
            </div>

            <div v-else
                 class="flex flex-col items-center justify-center w-full h-full border-2 border-dashed border-gray-600 rounded-xl text-gray-400">
                <VideoSolid class="w-8! h-8!"/>
                <div class="mt-xs px-lg text-center">TikTok needs a video. Nothing will publish without one.</div>
            </div>

            <div class="absolute bottom-0 left-0 right-0 p-sm bg-gradient-to-t from-black/80 to-transparent text-white">
                <div class="font-medium">@{{ username }}</div>
                <EditorReadOnly :value="mainContent.body" class="mt-1 text-sm"/>
            </div>
        </div>

        <div class="mt-sm text-gray-500">
            <div>
                <span class="font-medium text-gray-700">Who can see this:</span>
                <span :class="{'text-tiktok font-medium': options.privacy_level}" class="ml-1">{{ audienceLabel }}</span>
            </div>
            <div v-if="switchedOff.length" class="mt-1">
                Turned off: {{ switchedOff.join(', ') }}
            </div>
        </div>
    </Panel>
</template>
