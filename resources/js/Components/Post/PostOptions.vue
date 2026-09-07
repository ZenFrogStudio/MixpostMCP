<script setup>
import {computed, ref, watch} from "vue";
import {uniqBy} from "lodash";
import usePost from "@/Composables/usePost";
import usePostVersions from "@/Composables/usePostVersions";
import Label from "@/Components/Form/Label.vue";
import Input from "@/Components/Form/Input.vue";
import Textarea from "@/Components/Form/Textarea.vue";
import Select from "@/Components/Form/Select.vue";
import Checkbox from "@/Components/Form/Checkbox.vue";
import Alert from "@/Components/Util/Alert.vue";
import ChevronDown from "@/Icons/ChevronDown.vue";

const props = defineProps({
    selectedAccounts: {
        type: Array,
        required: true,
    },
    versions: {
        type: Array,
        required: true,
    },
    activeVersion: {
        type: Number,
        required: true,
    }
});

const {editAllowed} = usePost();
const {getIndexAccountVersion} = usePostVersions();

const isOpen = ref(false);

// The default version (0) applies to every selected account, an account version only to its own provider.
const visibleAccounts = computed(() => props.activeVersion === 0
    ? props.selectedAccounts
    : props.selectedAccounts.filter(account => account.id === props.activeVersion));

const providers = computed(() => {
    return uniqBy(visibleAccounts.value, 'provider')
        .filter(account => account.post_options?.length)
        .map(account => {
            return {
                provider: account.provider,
                name: account.provider_name,
                fields: account.provider === 'tiktok'
                    ? applyTikTokConstraints(account.post_options)
                    : account.post_options,
            }
        });
});

/////////////////////////*TikTok creator constraints*////////////////////////

// TikTok decides per creator which audiences they may post to and whether Duet, Stitch and
// comments are available. Those answers only exist behind creator_info/query, so the schema in
// TikTokProvider::postOptions() ships a fallback list and the real one is fetched here. Publishing
// with a level TikTok did not list for that creator is rejected.
const tiktokConstraints = ref(null);

const tiktokAccountIds = computed(() => visibleAccounts.value
    .filter(account => account.provider === 'tiktok')
    .map(account => account.id));

// A locked toggle is one TikTok has already switched off for the creator. Its checkbox is greyed
// out and shown on, which is what TikTok's content-sharing guidelines require, and publishing
// forces the same value regardless of what was saved.
const tiktokLockedBy = {
    disable_comment: 'comment_disabled',
    disable_duet: 'duet_disabled',
    disable_stitch: 'stitch_disabled',
};

// One version's options apply to every account it covers, so the composer offers what all the
// selected creators share and locks a toggle any one of them has switched off. Each account is
// still validated against its own creator info at publish time.
const mergeTikTokConstraints = (items) => {
    if (!items.length) {
        return null;
    }

    const privacyLevels = items.reduce((shared, item) => {
        const levels = item.privacy_level_options || {};

        if (shared === null) {
            return levels;
        }

        return Object.fromEntries(Object.entries(shared).filter(([value]) => value in levels));
    }, null) || {};

    return {
        privacy_level_options: privacyLevels,
        comment_disabled: items.some(item => item.comment_disabled),
        duet_disabled: items.some(item => item.duet_disabled),
        stitch_disabled: items.some(item => item.stitch_disabled),
        private_only: items.some(item => item.private_only),
        max_video_post_duration_sec: Math.min(...items.map(item => item.max_video_post_duration_sec || 0)),
    };
};

const applyTikTokConstraints = (fields) => {
    const constraints = tiktokConstraints.value;

    if (!constraints) {
        return fields;
    }

    return fields.map(field => {
        if (field.key === 'privacy_level' && Object.keys(constraints.privacy_level_options).length) {
            // The empty first choice stays: TikTok requires the creator to pick an audience rather
            // than inherit one, and publishPost() refuses an empty value.
            return {...field, choices: {'': 'Select a privacy level', ...constraints.privacy_level_options}};
        }

        const flag = tiktokLockedBy[field.key];

        return flag && constraints[flag] ? {...field, locked: true} : field;
    });
};

// A creator's settings can change between edits, so this refetches whenever the set of selected
// TikTok accounts changes rather than caching per session. A failed call leaves the fallback list
// in place and lets the pre-publish validation catch anything wrong.
watch(() => tiktokAccountIds.value.join(','), (ids) => {
    if (!ids) {
        tiktokConstraints.value = null;
        return;
    }

    Promise.all(tiktokAccountIds.value.map(id =>
        axios.get(route('mixpostmcp.accounts.tiktokCreatorInfo', {account: id}))
            .then(response => response.data)
            .catch(() => null)
    )).then(results => {
        tiktokConstraints.value = mergeTikTokConstraints(results.filter(item => item && !item.error));
    });
}, {immediate: true});

// Falls back to the schema default until the user touches the field. Providers must apply
// the same default at publish time, since an untouched option is never saved.
const getOption = (provider, field) => {
    const version = props.versions.find(item => item.account_id === props.activeVersion);
    const value = version?.options?.[provider]?.[field.key];

    return value !== undefined ? value : field.default;
};

const setOption = (provider, key, value) => {
    const versionIndex = getIndexAccountVersion(props.versions, props.activeVersion);

    if (versionIndex < 0) {
        return;
    }

    const version = props.versions[versionIndex];

    version.options = {
        ...version.options,
        [provider]: {...version.options?.[provider], [key]: value}
    };
};
</script>
<template>
    <div v-if="providers.length" class="mt-lg">
        <button @click="isOpen = !isOpen" type="button"
                class="flex items-center gap-xs text-gray-500 hover:text-gray-700 transition-colors ease-in-out duration-200">
            <ChevronDown :class="{'-rotate-90': !isOpen}"
                         class="w-4! h-4! transition-transform ease-in-out duration-200"/>
            <span class="font-medium">Post options</span>
        </button>

        <div v-show="isOpen" class="mt-sm bg-white border border-gray-100 rounded-lg p-lg">
            <div v-for="(item, index) in providers" :key="item.provider"
                 :class="{'mt-lg pt-lg border-t border-gray-100': index > 0}">
                <div class="font-semibold text-black mb-sm">{{ item.name }}</div>

                <template v-if="item.provider === 'tiktok' && tiktokConstraints">
                    <!-- An unaudited TikTok app publishes successfully and the video is visible to
                         nobody. Saying so before the post goes out is the only chance to catch it. -->
                    <Alert v-if="tiktokConstraints.private_only" variant="warning" :closeable="false" class="mb-md">
                        TikTok offers this creator no audience other than "Only me". That usually means this
                        TikTok app has not passed the content posting audit yet, so anything published here
                        will be private and visible only to the creator.
                    </Alert>

                    <div v-if="tiktokConstraints.max_video_post_duration_sec" class="mb-md text-gray-500">
                        Maximum video length for this creator: {{ tiktokConstraints.max_video_post_duration_sec }}s.
                    </div>
                </template>

                <div v-for="field in item.fields" :key="field.key" class="mb-md last:mb-0">
                    <template v-if="field.type === 'checkbox'">
                        <label class="flex items-center gap-xs" :class="field.locked ? 'cursor-not-allowed' : 'cursor-pointer'">
                            <Checkbox :checked="!!(field.locked || getOption(item.provider, field))"
                                      :disabled="!editAllowed || field.locked"
                                      @update:checked="setOption(item.provider, field.key, $event)"/>
                            <span class="font-medium text-gray-700">{{ field.label }}</span>
                            <span v-if="field.locked" class="text-gray-500">— already off in this creator's TikTok settings</span>
                        </label>
                    </template>

                    <template v-else>
                        <Label :value="field.label"/>

                        <Select v-if="field.type === 'select'"
                                :modelValue="getOption(item.provider, field)"
                                :disabled="!editAllowed"
                                @update:modelValue="setOption(item.provider, field.key, $event)">
                            <option v-for="(choiceLabel, choiceValue) in field.choices" :key="choiceValue"
                                    :value="choiceValue">
                                {{ choiceLabel }}
                            </option>
                        </Select>

                        <Textarea v-else-if="field.type === 'textarea'"
                                  :modelValue="getOption(item.provider, field)"
                                  :disabled="!editAllowed"
                                  rows="3"
                                  @update:modelValue="setOption(item.provider, field.key, $event)"/>

                        <Input v-else
                               :modelValue="getOption(item.provider, field)"
                               :disabled="!editAllowed"
                               type="text"
                               @update:modelValue="setOption(item.provider, field.key, $event)"/>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
