<script setup>
import {Head} from '@inertiajs/vue3';
import useNotifications from "../../Composables/useNotifications";
import PageHeader from "../../Components/DataDisplay/PageHeader.vue";
import Panel from "../../Components/Surface/Panel.vue";
import Table from "../../Components/DataDisplay/Table.vue";
import TableRow from "../../Components/DataDisplay/TableRow.vue";
import TableCell from "../../Components/DataDisplay/TableCell.vue";
import Badge from "../../Components/DataDisplay/Badge.vue";
import PrimaryButton from "../../Components/Button/PrimaryButton.vue";
import Clipboard from "../../Icons/Clipboard.vue";

const props = defineProps({
    health: Object,
    tech: Object,
})

const {notify} = useNotifications();

// Server installs run Redis under Horizon; the desktop app runs the `database` driver with a
// built-in worker. The two queue rows read differently for each, so the wording lives here.
const isDatabaseQueue = props.health.queue_driver === 'database';

const horizonVariant = {
    'Active': 'success',
    'Paused': 'warning',
    'Not installed': 'neutral',
}[props.health.horizon_status] ?? 'error';

const publishQueueVariant = props.health.publish_queue_supervised === null
    ? 'warning'
    : (props.health.publish_queue_supervised ? 'success' : 'error');

const publishQueueSummary = props.health.publish_queue_supervised === null
    ? 'Cannot verify'
    : (props.health.publish_queue_supervised ? 'Ok' : 'Not ok');

const getBody = () => {
    let body = `## Describe your issue\n\n--- \n`;

    body += `## Health\n\n`;
    body += `**Environment**: ${props.health.env} \n`;
    body += `**Debug Mode**: ${props.health.debug ? 'Enabled' : 'Disabled'} \n`
    body += `**Horizon**: ${props.health.horizon_status} \n`
    body += `**Queue connection**: ${props.health.has_queue_connection ? 'Ok' : 'Not ok'} (${props.health.queue_driver ?? 'none'}) \n`
    body += `**Publish queue**: ${publishQueueSummary} \n`
    body += `**Schedule**: ${props.health.last_scheduled_run.message} \n`

    body += `\n`;

    body += `## Technical Details:\n\n`;
    body += `**App directory**: ${props.tech.base_path} \n`;
    body += `**Upload Media Disk**: ${props.tech.disk} \n`;
    body += `**Log Channel**: ${props.tech.log_channel} \n`;
    body += `**Cache Driver**: ${props.tech.cache_driver} \n`;
    body += `**User agent**: ${props.tech.user_agent} \n`;
    body += `**FFmpeg**: ${props.tech.ffmpeg_status} \n`;
    if (props.tech.versions.database) {
        body += `**Database**: ${props.tech.versions.database} \n`;
    }
    body += `**PHP**: ${props.tech.versions.php} \n`;
    body += `**Laravel**: ${props.tech.versions.laravel} \n`;
    body += `**Horizon**: ${props.tech.versions.horizon ?? 'Not installed'} \n`;
    body += `**MixpostMCP**: ${props.tech.versions.mixpostmcp} \n`;

    return body;
}

const copyToClipboard = () => {
    navigator.clipboard.writeText(getBody())
        .then(() => {
            notify('success', 'System status information copied to clipboard')
        })
        .catch(() => {
            notify('error', 'Error copying status information to clipboard');
        });
}
</script>
<template>
    <Head title="Status"/>

    <div class="w-full mx-auto row-py">
        <PageHeader title="Status">
            <PrimaryButton @click="copyToClipboard" size="md">
                <Clipboard class="mr-xs"/>
                Copy info
            </PrimaryButton>
        </PageHeader>

        <div class="mt-lg row-px w-full">
            <Panel>
                <template #title>Health</template>

                <Table>
                    <template #body>
                        <TableRow :hoverable="true">
                            <TableCell>
                                <Badge :variant="health.env === 'production' ? 'success' : 'warning'">Environment</Badge>
                            </TableCell>
                            <TableCell>
                                {{ health.env }}
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell>
                                <Badge :variant="health.debug ? 'warning' : 'success'">Debug Mode</Badge>
                            </TableCell>
                            <TableCell>
                                {{ health.debug ? 'Enabled' : 'Disabled' }}
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell>
                                <Badge :variant="horizonVariant">
                                    Horizon
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <span v-if="health.horizon_status === 'Inactive'">
                                    <span class="block">Inactive</span>
                                    Read the <a
                                    :href="`${$page.props.mixpostmcp.docs_link}/lite/installation/laravel-package#5-install-horizon`">documentation</a>.
                                </span>
                                <span v-else-if="health.horizon_status === 'Not installed'">
                                    <span class="block">Not installed</span>
                                    Jobs run through a built-in worker instead of Horizon.
                                </span>
                                <span v-else>
                                    {{ health.horizon_status }}
                                </span>
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell>
                                <Badge :variant="health.has_queue_connection ? 'success'  : 'error'">
                                    Queue connection
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <span v-if="health.has_queue_connection && isDatabaseQueue">The default queue connection is the database.</span>
                                <span v-else-if="health.has_queue_connection">The default queue connection is Redis.</span>
                                <span v-else>
                                    <span class="block">The default <span class="font-medium">queue connection</span> is <span class="font-medium">{{ health.queue_driver ?? 'not set' }}</span>, which cannot run jobs in the background.</span>
                                    <span class="block">Set <span class="font-medium">QUEUE_CONNECTION=redis</span> (or <span class="font-medium">database</span>) in your <span class="font-medium">.env</span>.</span>
                                     Read the <a
                                    :href="`${$page.props.mixpostmcp.docs_link}/lite/installation/laravel-package#5-install-horizon`">documentation</a>.
                               </span>
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell>
                                <Badge :variant="publishQueueVariant">
                                    Publish queue
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <span v-if="health.publish_queue_supervised === null">
                                    Cannot verify which queues the worker is listening on. Scheduled posts are sent only if a worker runs the <span class="font-medium">publish-post</span> queue.
                                </span>
                                <span v-else-if="health.publish_queue_supervised && isDatabaseQueue">A built-in worker lists the <span class="font-medium">publish-post</span> queue.</span>
                                <span v-else-if="health.publish_queue_supervised">A Horizon supervisor is working the <span class="font-medium">publish-post</span> queue.</span>
                                <span v-else-if="isDatabaseQueue">
                                    <span class="block">No built-in worker lists the <span class="font-medium">publish-post</span> queue, so scheduled posts will never be sent.</span>
                                    <span class="block">Add <span class="font-medium">'publish-post'</span> to the <span class="font-medium">queues</span> array of a worker in <span class="font-medium">config/nativephp.php</span>, then restart the app.</span>
                                </span>
                                <span v-else>
                                    <span class="block">No Horizon supervisor lists the <span class="font-medium">publish-post</span> queue, so scheduled posts will never be sent.</span>
                                    <span class="block">Add <span class="font-medium">'publish-post'</span> to the <span class="font-medium">queue</span> array of a supervisor in <span class="font-medium">config/horizon.php</span>, then restart Horizon.</span>
                               </span>
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell>
                                <Badge :variant="health.last_scheduled_run.variant">Schedule</Badge>
                            </TableCell>
                            <TableCell>
                                {{ health.last_scheduled_run.message }}
                            </TableCell>
                        </TableRow>
                    </template>
                </Table>
            </Panel>

            <Panel class="mt-lg">
                <template #title>Technical details</template>

                <Table>
                    <template #body>
                        <TableRow :hoverable="true">
                            <TableCell class="font-medium">
                                App directory
                            </TableCell>
                            <TableCell>
                                {{ tech.base_path }}
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell class="font-medium">
                                Upload Media Disk
                            </TableCell>
                            <TableCell>
                                {{ tech.disk }}
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell class="font-medium">
                                Log Channel
                            </TableCell>
                            <TableCell>
                                {{ tech.log_channel }}
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell class="font-medium">
                                Cache Driver
                            </TableCell>
                            <TableCell>
                                {{ tech.cache_driver }}
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell class="font-medium">
                                User agent
                            </TableCell>
                            <TableCell>
                                {{ tech.user_agent }}
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell class="font-medium">
                                FFMpeg
                            </TableCell>
                            <TableCell>
                                {{ tech.ffmpeg_status }}
                            </TableCell>
                        </TableRow>
                        <template v-if="tech.versions.database">
                            <TableRow :hoverable="true">
                                <TableCell class="font-medium">
                                    Database
                                </TableCell>
                                <TableCell>
                                    {{ tech.versions.database }}
                                </TableCell>
                            </TableRow>
                        </template>
                        <TableRow :hoverable="true">
                            <TableCell class="font-medium">
                                PHP
                            </TableCell>
                            <TableCell>
                                {{ tech.versions.php }}
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell class="font-medium">
                                Laravel
                            </TableCell>
                            <TableCell>
                                {{ tech.versions.laravel }}
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell class="font-medium">
                                Horizon
                            </TableCell>
                            <TableCell>
                                {{ tech.versions.horizon ?? 'Not installed' }}
                            </TableCell>
                        </TableRow>
                        <TableRow :hoverable="true">
                            <TableCell class="font-medium">
                                MixpostMCP
                            </TableCell>
                            <TableCell>
                                {{ tech.versions.mixpostmcp }}
                            </TableCell>
                        </TableRow>
                    </template>
                </Table>
            </Panel>
        </div>
    </div>
</template>
