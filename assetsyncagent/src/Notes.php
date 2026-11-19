<?php

namespace AssetSyncAgent;

use CommonDBTM;
use DBmysqlIterator;
use Glpi\DBAL\QueryExpression;
use Glpi\RichText\RichText;
use Notepad;

final class Notes
{
    public static function normalize(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value))
        {
            throw new SyncException('invalid_field_value', 422);
        }

        $bodies = [];
        foreach ($value as $body)
        {
            if (!is_string($body))
            {
                throw new SyncException('invalid_field_value', 422);
            }
            $body = $body === '0' ? $body : RichText::getSafeHtml($body);
            // Embedded images and GLPI document links use identifiers local to the source.
            $body = preg_replace('~<img\b[^>]*>~i', '', $body);
            $body = preg_replace('~<a\b[^>]*\bhref="[^"]*(?:document\.send\.php|document\.php)[^"]*"[^>]*>(.*?)</a>~is', '$1', $body);
            $body = trim($body);
            if ($body !== '')
            {
                $bodies[] = $body;
            }
        }

        $bodies = array_values(array_unique($bodies, SORT_STRING));
        sort($bodies, SORT_STRING);
        return $bodies;
    }

    public static function read(CommonDBTM $asset, bool $lock = false): array
    {
        global $DB;

        $bodies = [];
        $modifiedAt = null;
        if ($asset->getID() > 0)
        {
            $query = new DBmysqlIterator($DB);
            $query->buildQuery([
                'SELECT' => ['content', new QueryExpression('UNIX_TIMESTAMP(COALESCE(date_mod, date_creation)) AS changed_at')],
                'FROM' => Notepad::getTable(),
                'WHERE' => ['itemtype' => $asset::class, 'items_id' => $asset->getID()],
            ]);
            $rows = $DB->doQuery($query->getSql() . ($lock ? ' FOR UPDATE' : ''));
            foreach ($rows as $row)
            {
                $bodies[] = (string) $row['content'];
                if ($row['changed_at'] !== null)
                {
                    $modifiedAt = max($modifiedAt ?? 0, (int) $row['changed_at']);
                }
            }
        }
        return ['value' => self::normalize($bodies), 'kind' => 'notes', 'modified_at' => $modifiedAt];
    }

    public static function addMissing(CommonDBTM $asset, array $bodies): void
    {
        $existing = self::read($asset, true)['value'];
        foreach (array_diff($bodies, $existing) as $body)
        {
            $input = [
                'itemtype' => $asset::class,
                'items_id' => $asset->getID(),
                'content' => $body,
                'visible_from_ticket' => 0,
                '_no_history' => true,
            ];
            $note = new Notepad();
            if (!$note->can(-1, CREATE, $input))
            {
                throw new SyncException('field_read_only', 403);
            }
            if (!$note->add($input, [], false))
            {
                throw new SyncException('field_write_failed', 422);
            }
        }
    }
}
