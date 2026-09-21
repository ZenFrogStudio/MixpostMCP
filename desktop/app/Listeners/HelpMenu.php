<?php

namespace App\Listeners;

use App\Support\McpLauncher;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\Clipboard;
use Native\Desktop\Facades\Notification;
use Native\Desktop\Facades\Shell;

/**
 * Handles the Help menu built in NativeAppServiceProvider. Picked up by Laravel's event discovery.
 */
class HelpMenu
{
    public function handle(MenuItemClicked $event): void
    {
        match ($event->item['id'] ?? null) {
            'copy-claude-config' => $this->copyClaudeConfig(),
            'open-data-folder' => Shell::openFile(env('NATIVEPHP_USER_DATA_PATH')),
            default => null,
        };
    }

    private function copyClaudeConfig(): void
    {
        Clipboard::text(McpLauncher::claudeDesktopConfig());

        Notification::title('MixpostMCP')
            ->message('Copied — paste into claude_desktop_config.json')
            ->show();
    }
}
