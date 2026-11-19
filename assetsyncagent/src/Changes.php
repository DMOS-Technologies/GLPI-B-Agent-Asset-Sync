<?php

namespace AssetSyncAgent;

use AssetSyncFields;
use CommonDBTM;
use DBmysql;
use Glpi\DBAL\QueryExpression;
use Log;
use Notepad;

final class Changes
{
    private const PAGE_SIZE = 500;

    public static function read(CommonDBTM $asset, array $scope, ?int $cursor, array $descriptors): array
    {
        global $DB;

        $asset->getEmpty();
        $asset->fields['entities_id'] = $scope['entities_id'];
        $fields = new AssetSyncFields($asset, $scope['entities_id']);
        $historyToFields = [];
        $notesId = null;
        foreach ($descriptors as $descriptor)
        {
            if (!is_array($descriptor))
            {
                throw new SyncException('field_unavailable', 422);
            }
            $field = $fields->resolve($descriptor);
            if ($field['source'] === 'notes')
            {
                // GLPI logs user edits of Notes as child-item changes, rather than option #200.
                $notesId = $field['id'];
                continue;
            }
            foreach ($fields->historyOptionIds($field) as $historyId)
            {
                $historyToFields[$historyId][$field['id']] = $field['id'];
            }
        }

        $logs = Log::getTable();
        $table = $asset::getTable();
        $matches = [];
        if ($historyToFields !== [])
        {
            // Native and Fields edits have no linked itemtype. Agent writes use History::class.
            $matches[] = ["$logs.itemtype_link" => '', "$logs.id_search_option" => array_keys($historyToFields)];
        }
        if ($notesId !== null)
        {
            $matches[] = ["$logs.itemtype_link" => Notepad::class];
        }
        if ($matches === [])
        {
            return ['ok' => true, 'cursor' => $cursor ?? 0, 'changes' => [], 'more' => false];
        }

        $where = ["$logs.itemtype" => $asset::class, 'OR' => $matches];
        foreach ($scope as $column => $value)
        {
            $where["$table.$column"] = $value;
        }
        $query = [
            'FROM' => $logs,
            'INNER JOIN' => [$table => ['ON' => [$logs => 'items_id', $table => 'id']]],
            'WHERE' => $where,
        ];
        if ($cursor === null)
        {
            // Start at the current checkpoint without replaying the route's entire history.
            $logId = DBmysql::quoteName("$logs.id");
            $latest = $DB->request(['SELECT' => [new QueryExpression("MAX($logId) AS latest")]] + $query)->current();
            return ['ok' => true, 'cursor' => (int) ($latest['latest'] ?? 0), 'changes' => [], 'more' => false];
        }

        $query['WHERE']["$logs.id"] = ['>', $cursor];
        $rows = $DB->request([
            'SELECT' => ["$logs.id AS event_id", "$logs.items_id AS asset_id", "$logs.id_search_option",
                "$logs.itemtype_link", "$table.serial"],
            'ORDER' => ["$logs.id"],
            'LIMIT' => self::PAGE_SIZE + 1,
        ] + $query);
        $more = count($rows) > self::PAGE_SIZE;
        $changes = [];
        $readable = [];
        $scanned = 0;
        foreach ($rows as $row)
        {
            if ($scanned++ === self::PAGE_SIZE)
            {
                break;
            }
            $cursor = (int) $row['event_id'];
            $assetId = (int) $row['asset_id'];
            $serial = trim((string) $row['serial']);
            if ($serial === '')
            {
                continue;
            }
            $readable[$assetId] ??= $asset->can($assetId, READ);
            if (!$readable[$assetId])
            {
                continue;
            }
            $ids = $row['itemtype_link'] === Notepad::class
                ? [$notesId]
                : array_values($historyToFields[$row['id_search_option']] ?? []);
            // Prefix the key so all-digit serials stay strings in JSON.
            $key = ':' . $serial;
            $changes[$key] ??= ['serial' => $serial, 'field_ids' => []];
            foreach ($ids as $id)
            {
                $changes[$key]['field_ids'][$id] = $id;
            }
        }
        foreach ($changes as &$change)
        {
            $change['field_ids'] = array_values($change['field_ids']);
        }
        return ['ok' => true, 'cursor' => $cursor, 'changes' => array_values($changes), 'more' => $more];
    }
}
