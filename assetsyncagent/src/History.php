<?php

namespace AssetSyncAgent;

use AssetSyncFields;
use CommonDBTM;
use Glpi\Asset\Asset;
use Glpi\RichText\RichText;
use Glpi\Search\SearchOption;
use Log;

final class History
{
    private const FIELD_UPDATED = Log::HISTORY_PLUGIN;
    private const FIELD_INITIALIZED = Log::HISTORY_PLUGIN + 1;

    public static function getTypeName(int $count = 1): string
    {
        return __('Asset Sync', 'assetsyncagent');
    }

    public static function recordField(
        CommonDBTM $asset,
        int $fieldId,
        array $field,
        mixed $before,
        mixed $after,
        bool $initial
    ): bool
    {
        $changes = [$fieldId];
        $kind = $field['kind'];
        $option = $field['option'] ?? [];
        $fieldName = implode(' ', [$field['column'], $field['table'] ?? '', $option['name'] ?? '', $option['linkfield'] ?? '']);
        $sensitiveField = preg_match('/password|passphrase|secret|token|credential|(?:api|private|access|license)[ _-]*key|url/i', $fieldName);
        if ($kind !== 'url' && !$sensitiveField)
        {
            $oldText = $initial ? '' : self::formatValue($before, $kind);
            $newText = self::formatValue($after, $kind);
            if ($newText !== '' && ($initial || $oldText !== ''))
            {
                $changes = [$fieldId, $oldText, $newText];
            }
        }

        // GLPI stores the actor, timestamp and search option ID used by future sync probes.
        $action = $initial ? self::FIELD_INITIALIZED : self::FIELD_UPDATED;
        return (bool) Log::history($asset->getID(), $asset::class, $changes, self::class, $action);
    }

    private static function formatValue(mixed $value, string $kind): string
    {
        if ($kind === 'notes')
        {
            // Notes may contain private text. Show the count without copying the bodies.
            $count = count($value ?? []);
            $value = sprintf(_n('%d note', '%d notes', $count, 'assetsyncagent'), $count);
        }
        elseif (is_bool($value))
        {
            $value = $value ? __('Yes') : __('No');
        }
        elseif (is_array($value))
        {
            $value = $value['label'] ?? implode(', ', $value);
        }
        elseif (in_array($kind, ['richtext', 'rich_text'], true) && is_string($value))
        {
            $value = RichText::getTextFromHtml($value, false, true);
        }

        $text = trim((string) $value);
        // A URL pasted into an ordinary text field can contain credentials too.
        if (preg_match('~https?://[^/\s]+@|[?&#](?:[^=&#\s]*(?:password|token|secret|credential)|api[_-]?key)=~i', $text))
        {
            return '';
        }
        if ($text === '')
        {
            $text = __('(empty)', 'assetsyncagent');
        }
        elseif ($kind === 'datetime')
        {
            $text .= ' UTC';
        }
        if (mb_strlen($text) > 254)
        {
            $text = mb_substr($text, 0, 253) . '…';
        }

        // GLPI discards strings that look like JSON arrays or objects. The marker also
        // distinguishes a displayable empty value from an intentionally omitted value.
        return '=' . $text;
    }

    public static function getHistoryEntry(array $entry): string
    {
        $type = $entry['itemtype'];
        $id = (int) $entry['id_search_option'];
        $option = SearchOption::getCleanedOptions($type, READ)[$id] ?? null;
        if ($option === null || !self::canReadOption($entry, $option))
        {
            $label = sprintf(__('Field #%d', 'assetsyncagent'), $id);
            return self::renderEntry($label, sprintf(__('%s synchronized.', 'assetsyncagent'), $label));
        }

        $label = $option['name'];
        $initial = (int) $entry['linked_action'] === self::FIELD_INITIALIZED;
        $before = $entry['old_value'];
        $after = $entry['new_value'];
        if (!str_starts_with($after, '=') || (!$initial && !str_starts_with($before, '=')))
        {
            $message = $initial
                ? __('%s set during synchronization. Values are hidden.', 'assetsyncagent')
                : __('%s updated during synchronization. Values are hidden.', 'assetsyncagent');
            return self::renderEntry($label, sprintf($message, $label));
        }

        if ($initial)
        {
            return self::renderEntry($label, sprintf(__('%1$s set to %2$s.', 'assetsyncagent'), $label, substr($after, 1)));
        }
        return self::renderEntry($label, sprintf(
            __('%1$s changed from %2$s to %3$s.', 'assetsyncagent'),
            $label,
            substr($before, 1),
            substr($after, 1)
        ));
    }

    private static function renderEntry(string $label, string $message): string
    {
        // GLPI uses the plugin name for its Field column. Pass the label to the tab script
        // while keeping the Update text readable on its own, including in exports.
        return sprintf(
            '<span data-asset-sync-history-field="%s">%s</span>',
            htmlescape($label),
            htmlescape($message)
        );
    }

    private static function canReadOption(array $entry, array $option): bool
    {
        $type = $entry['itemtype'];
        $id = (int) $entry['id_search_option'];
        $hasAdditionalPermissions = ($id === 200 && $option['table'] === 'glpi_notepads')
            || str_starts_with($option['table'], 'glpi_plugin_fields_') || is_subclass_of($type, Asset::class);
        if (!$hasAdditionalPermissions)
        {
            return true;
        }

        // Resolve fields with the viewer's profile and entity, including Notes and Fields plugin rights.
        require_once __DIR__ . '/Sync.php';
        $asset = new $type();
        if (!$asset->getFromDB((int) $entry['items_id']))
        {
            return false;
        }
        try
        {
            $fields = new AssetSyncFields($asset, (int) $asset->fields['entities_id']);
            $fields->resolve(['id' => $id, 'table' => $option['table'], 'field' => $option['field']]);
            return true;
        }
        catch (SyncException)
        {
            return false;
        }
    }
}
