<script setup>
///////////////////////////*Scope*///////////////////////////
/* LinkedIn preview: avatar, name, a muted subtitle carrying the handle and the chosen audience,
   then the body and its media. LinkedIn's feed card is the same shape as Facebook's, so this
   reuses Facebook's media gallery rather than growing a second copy of that grid. */

import {computed} from "vue";
import useEditor from "@/Composables/useEditor";
import Panel from "@/Components/Surface/Panel.vue";
import Gallery from "@/Components/ProviderGallery/Facebook/FacebookGallery.vue"
import EditorReadOnly from "@/Components/Package/EditorReadOnly.vue";
import LockClosed from "@/Icons/LockClosed.vue";
import Chat from "@/Icons/Chat.vue";
import Share from "@/Icons/Share.vue";
import PaperAirplane from "@/Icons/PaperAirplane.vue";
import {REPRESENTATIVE_DATA_TEXT} from "../../Constants/Text";

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

const {isDocEmpty} = useEditor();

const mainContent = computed(() => {
    return props.content[0];
});

// Mirrors the choices in LinkedInProvider::postOptions(). A company page always publishes
// publicly, so an unrecognised value falls back to the public label rather than showing nothing.
const audienceLabel = computed(() => {
    return props.options.visibility === 'CONNECTIONS' ? 'Connections only' : 'Anyone';
});
</script>
<template>
    <Panel class="relative">
        <div class="flex items-center">
            <span class="inline-flex justify-center items-center shrink-0 w-10 h-10 rounded-full mr-sm">
                <img :src="image" class="object-cover w-full h-full rounded-full" alt=""/>
            </span>
            <div class="flex flex-col">
                <div class="font-medium">{{ name }}</div>
                <div class="text-gray-400 text-sm">{{ username }}</div>
                <div class="flex items-center text-gray-400 text-sm">
                    <span>19h</span>
                    <span class="mx-1">·</span>
                    <LockClosed v-if="options.visibility === 'CONNECTIONS'" class="w-3! h-3! mr-1"/>
                    <span>{{ audienceLabel }}</span>
                </div>
            </div>
        </div>

        <div class="w-full">
            <EditorReadOnly :value="mainContent.body"
                            :class="{'mt-xs': !isDocEmpty(mainContent.body), 'mb-xs': mainContent.media.length}"/>

            <Gallery :media="mainContent.media"/>
        </div>

        <div v-tooltip="REPRESENTATIVE_DATA_TEXT" class="mt-5 flex items-center justify-between text-gray-500">
            <div>👍 💡 48</div>
            <div>6 comments</div>
        </div>

        <div class="mt-xs flex items-center justify-around border-t border-gray-200 text-gray-500 pt-2">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-linkedin" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M2 9.5h4v11H2v-11zm7.5 0h3.8v1.5h.05c.53-.95 1.83-1.95 3.76-1.95 4.02 0 4.76 2.5 4.76 5.76v5.69h-4v-5.05c0-1.2-.02-2.75-1.75-2.75-1.76 0-2.03 1.31-2.03 2.66v5.14h-4v-11z"></path>
                </svg>
                <span class="ml-1 font-semibold">Like</span>
            </div>
            <div class="flex items-center">
                <Chat class="w-5! h-5!"/>
                <span class="ml-1 font-semibold">Comment</span>
            </div>
            <div class="flex items-center">
                <Share class="w-5! h-5!"/>
                <span class="ml-1 font-semibold">Repost</span>
            </div>
            <div class="flex items-center">
                <PaperAirplane class="w-5! h-5!"/>
                <span class="ml-1 font-semibold">Send</span>
            </div>
        </div>
    </Panel>
</template>
