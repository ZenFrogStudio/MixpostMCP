<script setup>
///////////////////////////*Scope*///////////////////////////
/* YouTube preview: a 16:9 thumbnail with the title and description shown as the two separate
   fields YouTube actually receives. The post body is one block of text, so YouTubeProvider splits
   it — first line becomes the title, the rest the description — and this preview repeats that
   split so the user sees where the cut lands before publishing. */

import {computed} from "vue";
import useEditor from "@/Composables/useEditor";
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

const {getTextFromHtmlString} = useEditor();

const mainContent = computed(() => {
    return props.content[0];
});

const video = computed(() => {
    return mainContent.value.media.find(item => item.is_video) || null;
});

// YouTube rejects a title or description containing angle brackets outright, so the provider
// strips them before uploading and the preview shows the same stripped text.
const bodyLines = computed(() => {
    const text = getTextFromHtmlString(mainContent.value.body).replace(/[<>]/g, '');
    const breakAt = text.indexOf('\n');

    return breakAt === -1
        ? {firstLine: text.trim(), rest: ''}
        : {firstLine: text.slice(0, breakAt).trim(), rest: text.slice(breakAt + 1).trim()};
});

// YouTube's title limit is 100 characters and a hard cut mid-word reads as a mistake, so the
// provider trims back to the last whole word. Same rule here.
const truncateOnWordBoundary = (title) => {
    if (title.length <= 100) {
        return title;
    }

    const cut = title.slice(0, 100);
    const lastSpace = cut.lastIndexOf(' ');

    return (lastSpace > 0 ? cut.slice(0, lastSpace) : cut) + '…';
};

const usesExplicitTitle = computed(() => {
    return Boolean(props.options.title);
});

const videoTitle = computed(() => {
    return usesExplicitTitle.value
        ? truncateOnWordBoundary(props.options.title)
        : truncateOnWordBoundary(bodyLines.value.firstLine);
});

// Mirrors the choices in YouTubeProvider::postOptions(), which defaults to private so an
// unattended scheduler never publishes publicly by accident.
const youtubePrivacyLabels = {
    'public': 'Public',
    'unlisted': 'Unlisted',
    'private': 'Private',
};
</script>
<template>
    <Panel class="relative">
        <div class="relative aspect-video rounded-xl overflow-hidden bg-gray-900">
            <img v-if="video" :src="video.thumb_url" class="object-cover w-full h-full opacity-80" alt=""/>

            <div v-if="video" class="absolute inset-0 flex items-center justify-center">
                <span class="flex items-center justify-center w-16 h-11 rounded-lg text-white bg-youtube">
                    <Play class="w-7! h-7!"/>
                </span>
            </div>

            <div v-else class="flex flex-col items-center justify-center w-full h-full text-gray-400">
                <VideoSolid class="w-8! h-8!"/>
                <div class="mt-xs px-lg text-center">YouTube needs a video to upload.</div>
            </div>
        </div>

        <div class="flex items-start mt-sm">
            <span class="inline-flex justify-center items-center shrink-0 w-10 h-10 rounded-full mr-sm">
                <img :src="image" class="object-cover w-full h-full rounded-full" alt=""/>
            </span>

            <div class="w-full">
                <div class="text-gray-500 text-sm">Title</div>
                <div v-if="videoTitle" class="font-semibold text-black hyphens-none">{{ videoTitle }}</div>
                <div v-else class="text-gray-400">The first line of your post becomes the title.</div>

                <div class="text-gray-400 text-sm mt-1">
                    {{ name }}
                    <template v-if="usesExplicitTitle"> · title set in post options</template>
                </div>
            </div>
        </div>

        <div class="mt-sm pt-sm border-t border-gray-100">
            <div class="text-gray-500 text-sm">Description</div>

            <!-- The explicit title leaves the whole body as the description; otherwise the first
                 line has already been taken and only the remainder is left. -->
            <EditorReadOnly v-if="usesExplicitTitle" :value="mainContent.body" class="text-gray-500"/>
            <div v-else-if="bodyLines.rest" class="text-gray-500 whitespace-pre-line hyphens-none">{{ bodyLines.rest }}</div>
            <div v-else class="text-gray-400">Everything after the first line becomes the description.</div>
        </div>

        <div v-if="options.privacy_status" class="mt-sm text-gray-500">
            <span class="font-medium text-gray-700">Visibility:</span>
            <span class="ml-1">{{ youtubePrivacyLabels[options.privacy_status] || options.privacy_status }}</span>
        </div>
    </Panel>
</template>
