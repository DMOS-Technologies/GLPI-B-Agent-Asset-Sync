<?php

namespace AssetSyncAgent;

use AssetSyncFields;
use CommonDBTM;
use DBmysql;
use Glpi\Asset\Asset;
use Throwable;

require_once __DIR__ . '/SyncException.php';
require_once __DIR__ . '/Notes.php';
require_once __DIR__ . '/Values.php';
require_once __DIR__ . '/History.php';
require_once __DIR__ . '/Fields.php';
require_once __DIR__ . '/Changes.php';

final class Sync
{
    public static function handle(array $request): array
    {
        global $CFG_GLPI, $DB;

        $type = $request['itemtype'] ?? null;
        $entity = $request['entity_id'] ?? null;
        $serial = $request['serial'] ?? null;
        $operation = $request['operation'] ?? null;
        if (!is_string($type) || !is_int($entity) || $entity < 0
            || !in_array($operation, ['probe', 'apply', 'changes'], true)
            || ($operation !== 'changes' && (!is_string($serial) || trim($serial) === ''
                || mb_strlen($serial) > 255 || str_contains($serial, "\0"))))
        {
            throw new SyncException('invalid_request');
        }
        if (!in_array($type, $CFG_GLPI['asset_types'] ?? [], true) || !is_subclass_of($type, CommonDBTM::class))
        {
            throw new SyncException('asset_type_unavailable', 422);
        }

        $table = $type::getTable();
        $columns = $DB->listFields($table);
        if (!isset($columns['id'], $columns['serial'], $columns['entities_id']) || !$type::canView())
        {
            throw new SyncException('asset_type_unavailable', 403);
        }

        // The custom asset classes share a table. Their definition ID is part of the identity.
        $scope = ['entities_id' => $entity];
        foreach (['is_deleted', 'is_template'] as $column)
        {
            if (isset($columns[$column]))
            {
                $scope[$column] = 0;
            }
        }
        if (is_subclass_of($type, Asset::class) && isset($columns['assets_assetdefinitions_id']))
        {
            $scope['assets_assetdefinitions_id'] = (int) $type::getDefinition()->getID();
        }
        $asset = new $type();
        if ($operation === 'changes')
        {
            $cursor = $request['cursor'] ?? null;
            if (!array_key_exists('cursor', $request) || ($cursor !== null && (!is_int($cursor) || $cursor < 0)))
            {
                throw new SyncException('invalid_request');
            }
            return Changes::read($asset, $scope, $cursor, self::getList($request, 'fields'));
        }

        $serial = trim($serial);
        $conditions = ["TRIM(serial) = '" . $DB->escape($serial) . "'"];
        foreach ($scope as $column => $value)
        {
            $conditions[] = DBmysql::quoteName($column) . ' = ' . $value;
        }
        $lookup = 'SELECT id FROM ' . DBmysql::quoteName($table) . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 2';

        if ($operation === 'probe')
        {
            return self::transaction(static fn() => self::probe($asset, $entity, $lookup, self::getList($request, 'fields')));
        }

        // Use the scope as the lock key so equivalent serials under the DB collation share a lock.
        // The short, nonblocking lock also covers the gap before an asset exists.
        $lock = hash('sha256', 'assetsyncagent:' . $DB->dbdefault . ':' . $type . ':' . $entity);
        $result = $DB->doQuery("SELECT GET_LOCK('$lock', 0)");
        if ((int) $DB->result($result, 0, 0) !== 1)
        {
            throw new SyncException('asset_busy', 409);
        }
        try
        {
            // Take row locks without locking unrelated serial ranges or using stale snapshots.
            $DB->doQuery('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            return self::transaction(static fn() => self::apply($asset, $entity, $serial, $lookup, $request));
        }
        finally
        {
            $DB->doQuery("SELECT RELEASE_LOCK('$lock')");
        }
    }

    private static function transaction(callable $work): array
    {
        global $DB;
        $DB->beginTransaction();
        try
        {
            $result = $work();
            $DB->commit();
            return $result;
        }
        catch (Throwable $error)
        {
            $DB->rollBack();
            if ($error instanceof \mysqli_sql_exception && in_array($error->getCode(), [1205, 1213], true))
            {
                throw new SyncException('asset_busy', 409);
            }
            throw $error;
        }
    }

    private static function findId(string $lookup, bool $lock = false): ?int
    {
        global $DB;
        $rows = $DB->doQuery($lookup . ($lock ? ' FOR UPDATE' : ''));
        $row = $DB->fetchAssoc($rows);
        if ($row !== null && $DB->fetchAssoc($rows) !== null)
        {
            throw new SyncException('ambiguous_serial', 409);
        }
        return $row === null ? null : (int) $row['id'];
    }

    private static function probe(CommonDBTM $asset, int $entity, string $lookup, array $descriptors): array
    {
        $id = self::findId($lookup);
        if ($id === null)
        {
            $asset->getEmpty();
            $asset->fields['entities_id'] = $entity;
        }
        else
        {
            self::loadForRead($asset, $id);
        }

        $fields = new AssetSyncFields($asset, $entity);
        $values = [];
        foreach ($descriptors as $descriptor)
        {
            if (!is_array($descriptor))
            {
                throw new SyncException('field_unavailable', 422);
            }
            $field = $fields->resolve($descriptor);
            if ($id !== null)
            {
                $values[$descriptor['id']] = $fields->read($field);
            }
        }
        if ($id === null)
        {
            return ['ok' => true, 'exists' => false];
        }
        return ['ok' => true, 'exists' => true, 'asset_id' => $id, 'values' => (object) $values];
    }

    private static function apply(CommonDBTM $asset, int $entity, string $serial, string $lookup, array $request): array
    {
        $create = $request['create'] ?? null;
        $expectedId = $request['expected_asset_id'] ?? null;
        $nativeSeeds = $request['initial_native'] ?? null;
        $mappedEntries = self::getList($request, 'values');
        $expectedEntries = self::getList($request, 'expected');
        $initialEntries = self::getList($request, 'initial');
        if (!is_bool($create) || !array_key_exists('expected_asset_id', $request) || !is_array($nativeSeeds)
            || ($nativeSeeds !== [] && array_is_list($nativeSeeds))
            || ($create ? ($expectedId !== null || $expectedEntries !== []) : (!is_int($expectedId) || $expectedId <= 0)))
        {
            throw new SyncException('invalid_request');
        }

        $id = self::findId($lookup, true);
        if ($create && $id !== null)
        {
            throw new SyncException('asset_changed', 409);
        }
        if (!$create && $id !== $expectedId)
        {
            throw new SyncException('asset_identity_changed', 409);
        }

        if ($create)
        {
            $identity = ['entities_id' => $entity, 'serial' => $serial];
            $asset->getEmpty();
            if (!$asset->can(-1, CREATE, $identity))
            {
                throw new SyncException('asset_access_denied', 403);
            }
        }
        else
        {
            self::loadForRead($asset, $id);
            if ($mappedEntries !== [] && !$asset->can($id, UPDATE))
            {
                throw new SyncException('asset_access_denied', 403);
            }
        }

        $before = $create ? [] : $asset->fields;
        $fields = new AssetSyncFields($asset, $entity);
        $mapped = self::resolveEntries($fields, $mappedEntries, true, $create ? $serial : null);
        $expected = self::resolveEntries($fields, $expectedEntries, false);
        $seeds = [];
        if ($create)
        {
            $seeds = self::resolveEntries($fields, $initialEntries, true);
            foreach ($nativeSeeds as $column => $value)
            {
                if (!is_string($column) || ($value !== null && !is_scalar($value)))
                {
                    throw new SyncException('invalid_field_value', 422);
                }
                if ($value === null || $value === '')
                {
                    continue;
                }
                // The source may have columns the destination model does not expose for portable writes.
                $field = $fields->resolveNativeColumn($column);
                if ($field !== null)
                {
                    $seeds[$field['key']] ??= ['field' => $field, 'value' => $value];
                }
            }
        }
        elseif (array_diff_key($mapped, $expected) !== [])
        {
            // An update without an expectation for every written field cannot be replayed safely.
            throw new SyncException('invalid_request');
        }

        $writes = $mapped + $seeds;
        if (!$create)
        {
            $fields->lockContainers(array_column($writes + $expected, 'field'));
            foreach ($expected as $entry)
            {
                if ($fields->read($entry['field'])['value'] !== $entry['value'])
                {
                    throw new SyncException('asset_changed', 409);
                }
            }
        }

        $native = [];
        $containers = [];
        $notes = [];
        $planned = [];
        foreach ($writes as $key => $entry)
        {
            $planned[$key] = $fields->plan($entry['field'], $entry['value'], $native, $containers, $notes);
        }

        // Record sync history below, where a failed audit write can roll back the apply.
        if ($create)
        {
            $id = $asset->add(['entities_id' => $entity, 'serial' => $serial, '_skip_rules' => true] + $native, [], false);
            if (!$id)
            {
                throw new SyncException('invalid_field_value', 422);
            }
            $id = (int) $id;
        }
        elseif ($native !== [] && !$asset->update(['id' => $id, '_skip_rules' => true] + $native, false))
        {
            throw new SyncException('invalid_field_value', 422);
        }

        $storedSerial = $create ? $serial : $before['serial'];
        if (self::findId($lookup, true) !== $id || !$asset->getFromDB($id)
            || (int) $asset->fields['entities_id'] !== $entity || $asset->fields['serial'] !== $storedSerial)
        {
            throw new SyncException('asset_identity_changed', 409);
        }
        $fields->saveContainers($containers);
        if ($notes !== [])
        {
            Notes::addMissing($asset, $notes);
        }

        if (!$create)
        {
            self::checkUnmappedFields($asset, $before, $native);
        }

        if ($create)
        {
            $fields->recordCreation();
        }

        $hasFieldChanges = false;
        $appliedValues = [];
        // Some GLPI models silently ignore or normalize inputs. Check the actual stored values.
        foreach ($writes as $key => $entry)
        {
            $storedValue = $fields->read($entry['field'])['value'];
            if ($storedValue !== $planned[$key])
            {
                throw new SyncException('invalid_field_value', 422);
            }
            if (isset($mapped[$key]))
            {
                // The controller needs the stored label, which can be the full path of a dropdown choice.
                $appliedValues[$entry['field']['id']] = $storedValue;
            }
            $previousValue = $create ? null : $expected[$key]['value'];
            if ($create || $storedValue !== $previousValue)
            {
                $fields->recordEdit($entry['field'], $previousValue, $storedValue, $create);
                $hasFieldChanges = true;
            }
        }

        if (!$create && $hasFieldChanges)
        {
            self::touchAsset($asset);
        }
        return ['ok' => true, 'asset_id' => $id, 'applied_values' => (object) $appliedValues];
    }

    private static function touchAsset(CommonDBTM $asset): void
    {
        $currentTime = $_SESSION['glpi_currenttime'];
        if (!array_key_exists('date_mod', $asset->fields) || $asset->fields['date_mod'] === $currentTime)
        {
            return;
        }

        // Related records such as notes and Fields containers do not update the asset's Last Update.
        $asset->fields['date_mod'] = $currentTime;
        if (!$asset->updateInDB(['date_mod']))
        {
            throw new SyncException('field_write_failed', 500);
        }
    }

    private static function checkUnmappedFields(CommonDBTM $asset, array $before, array $native): void
    {
        $allowed = $native + ['date_mod' => null];
        if ($asset instanceof Asset)
        {
            // Custom asset values are loaded individually as custom_* fields by GLPI.
            $allowed['custom_fields'] = null;
        }
        foreach (array_diff_key($before, $allowed) as $column => $value)
        {
            if ($value !== ($asset->fields[$column] ?? null))
            {
                // A model or asset rule tried to change a field outside this request.
                throw new SyncException('unmapped_field_changed', 409);
            }
        }
    }

    private static function loadForRead(CommonDBTM $asset, int $id): void
    {
        if (!$asset->getFromDB($id))
        {
            throw new SyncException('asset_identity_changed', 409);
        }
        if (!$asset->can($id, READ))
        {
            throw new SyncException('asset_access_denied', 403);
        }
    }

    private static function getList(array $request, string $key): array
    {
        $list = $request[$key] ?? null;
        if (!is_array($list) || !array_is_list($list))
        {
            throw new SyncException('invalid_request');
        }
        return $list;
    }

    private static function resolveEntries(AssetSyncFields $fields, array $entries, bool $write, ?string $creationSerial = null): array
    {
        $resolved = [];
        foreach ($entries as $entry)
        {
            if (!is_array($entry) || !is_array($entry['field'] ?? null) || !array_key_exists('value', $entry))
            {
                throw new SyncException('invalid_request');
            }
            $field = $fields->resolve($entry['field']);
            if ($creationSerial !== null && $field['source'] === 'native' && $field['column'] === 'serial'
                && is_string($entry['value']) && trim($entry['value']) === $creationSerial)
            {
                // The create identity already supplies this serial. Never write it from a mapping.
                continue;
            }
            if ($write && !$field['writable'])
            {
                throw new SyncException('field_read_only', 403);
            }
            if (isset($resolved[$field['key']]))
            {
                throw new SyncException('invalid_request');
            }
            $resolved[$field['key']] = ['field' => $field, 'value' => $entry['value']];
        }
        return $resolved;
    }
}
