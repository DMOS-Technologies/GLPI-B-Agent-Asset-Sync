<?php

use Glpi\Plugin\Hooks;

// GLPI's History tab resolves this class when rendering synchronized changes.
require_once __DIR__ . '/src/History.php';

// This route authenticates with legacy REST headers and needs no browser session.
function plugin_assetsyncagent_boot(): void
{
    \Glpi\Http\SessionManager::registerPluginStatelessPath('assetsyncagent', '#^/front/sync\\.php$#');
}

// GLPI calls this on startup when the plugin is enabled.
function plugin_init_assetsyncagent(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['assetsyncagent'] = 'js/history-field.js';
}

// GLPI uses this information in Setup > Plugins.
function plugin_version_assetsyncagent(): array
{
    return [
        'name' => 'Asset Sync Agent',
        'version' => '0.0.6',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '12.0.0',
            ],
        ],
    ];
}
