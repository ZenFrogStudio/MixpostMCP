<script setup>
import {computed} from "vue";
import useProviderClassesColor from "@/Composables/useProviderClassesColor";
import TwitterIcon from "@/Icons/Twitter.vue";
import FacebookIcon from "@/Icons/Facebook.vue";
import MastodonIcon from "@/Icons/Mastodon.vue";
import InstagramIcon from "@/Icons/Instagram.vue";
import LinkedInIcon from "@/Icons/LinkedIn.vue";
import TikTokIcon from "@/Icons/TikTok.vue";
import YouTubeIcon from "@/Icons/YouTube.vue";

const props = defineProps({
    provider: {
        type: String,
        required: true
    }
})

const providers = {
    'twitter': TwitterIcon,
    'facebook': FacebookIcon,
    'facebook_page': FacebookIcon,
    'mastodon': MastodonIcon,
    'instagram': InstagramIcon,
    'linkedin': LinkedInIcon,
    'tiktok': TikTokIcon,
    'youtube': YouTubeIcon,
};

const component = computed(() => {
    return providers[props.provider];
});

const {textClasses} = useProviderClassesColor(props.provider);
</script>
<template>
    <component :is="component" :class="textClasses"/>
</template>
