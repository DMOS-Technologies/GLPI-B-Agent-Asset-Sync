<?php

function plugin_assetsyncagent_install(): bool
{
    // GLPI expects true on success. There are no plugin tables to create.
    return true;
}

function plugin_assetsyncagent_uninstall(): bool
{
    // There are no plugin tables or settings to remove.
    return true;
}
