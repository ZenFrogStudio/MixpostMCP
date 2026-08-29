import {computed} from "vue";

const useProviderClassesColor = (provider) => {
    const textClasses = computed(() => {
        return {
            'twitter': 'text-twitter',
            'facebook': 'text-facebook',
            'facebook_page': 'text-facebook',
            'mastodon': 'text-mastodon',
            'instagram': 'text-instagram',
            'linkedin': 'text-linkedin',
            'tiktok': 'text-tiktok',
            'youtube': 'text-youtube'
        }[provider];
    });

    const borderClasses = computed(() => {
        return {
            'twitter': 'border-twitter',
            'facebook': 'border-facebook',
            'facebook_page': 'border-facebook',
            'mastodon': 'border-mastodon',
            'instagram': 'border-instagram',
            'linkedin': 'border-linkedin',
            'tiktok': 'border-tiktok',
            'youtube': 'border-youtube'
        }[provider];
    });

    const activeBgClasses = computed(() => {
        return {
            'twitter': 'bg-twitter',
            'facebook': 'bg-facebook',
            'facebook_page': 'bg-facebook',
            'mastodon': 'bg-mastodon',
            'instagram': 'bg-instagram',
            'linkedin': 'bg-linkedin',
            'tiktok': 'bg-tiktok',
            'youtube': 'bg-youtube'
        }[provider];
    });

    return {
        textClasses,
        borderClasses,
        activeBgClasses
    }
}

export default useProviderClassesColor;
