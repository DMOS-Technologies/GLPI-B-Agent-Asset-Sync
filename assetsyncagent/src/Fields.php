<?php

use AssetSyncAgent\History;
use AssetSyncAgent\Notes;
use AssetSyncAgent\SyncException;
use AssetSyncAgent\Values;
use Glpi\Asset\Asset;
use Glpi\Asset\CustomFieldDefinition;
use Glpi\Asset\CustomFieldType as CustomType;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;
use Glpi\Features\StateInterface;
use Glpi\Search\SearchOption;

final class AssetSyncFields
{
    private array $options;
    private array $containerRows = [];

    public function __construct(private CommonDBTM $asset, private int $entityId)
    {
        $this->options = SearchOption::getCleanedOptions($asset::class, READ);
    }

    public function resolve(array $descriptor, bool $write = false): array
    {
        $id = $descriptor['id'] ?? null;
        $option = is_int($id) ? ($this->options[$id] ?? null) : null;
        if (!is_array($option) || !empty($option['nodisplay'])
            || ($descriptor['table'] ?? null) !== ($option['table'] ?? null)
            || ($descriptor['field'] ?? null) !== ($option['field'] ?? null))
        {
            // Controller A can still identify this stale mapping by the requested ID.
            throw (new SyncException('field_unavailable', 422))->forField(['id' => $id])
                ->because('The saved mapping no longer matches an available search option on GLPI B.');
        }
        // The REST API adds its own UID. Names and source labels can be translated.
        foreach (['linkfield', 'datatype'] as $key)
        {
            if (array_key_exists($key, $descriptor) && $descriptor[$key] !== ($option[$key] ?? ''))
            {
                throw (new SyncException('field_unavailable', 422))->forField(['id' => $id])
                    ->because('The saved mapping no longer matches the current search option on GLPI B.');
            }
        }

        $definition = $option['field_definition'] ?? null;
        $pluginFieldId = $option['pfields_fields_id'] ?? null;
        if ($pluginFieldId === null && str_starts_with($option['field'], 'items_id_')
            && str_starts_with($option['table'], 'glpi_plugin_fields_'))
        {
            // Fields publishes the reference ID directly after its paired item type.
            $pluginFieldId = $this->options[$id - 1]['pfields_fields_id'] ?? null;
        }

        // Keep the matched ID if local permissions or storage prevent resolving the field.
        try
        {
            if ($id === 200 && $option['table'] === Notepad::getTable() && $option['field'] === 'content')
            {
                if (!Session::haveRight($this->asset::$rightname, READNOTE))
                {
                    throw new SyncException('field_unavailable', 422);
                }
                $field = [
                    'column' => 'content',
                    'table' => Notepad::getTable(),
                    'source' => 'notes',
                    'kind' => 'notes',
                    'writable' => Session::haveRight($this->asset::$rightname, UPDATENOTE),
                ];
            }
            elseif ($definition instanceof CustomFieldDefinition)
            {
                $field = $this->nativeCustomField($definition);
            }
            elseif ($pluginFieldId !== null)
            {
                $field = $this->fieldsPluginField((int) $pluginFieldId, $option);
            }
            else
            {
                $field = $this->nativeField($option);
            }
        }
        catch (SyncException $error)
        {
            throw $error->forField(['id' => $id])->because('GLPI B cannot use this field in the destination asset or entity.');
        }

        $field['id'] = $id;
        if ($write && !$field['writable'])
        {
            throw (new SyncException('field_read_only', 403))->forField($field)
                ->because('The destination field is read-only for the sync account.');
        }

        $field['key'] = $field['table'] . '.' . $field['column'];
        $field['option'] = $option;
        return $field;
    }

    public function resolveNativeColumn(string $column): ?array
    {
        if (!self::portableColumn($column))
        {
            return null;
        }
        foreach ($this->options as $id => $option)
        {
            if (is_int($id) && ($option['table'] ?? null) === $this->asset::getTable()
                && ($option['field'] ?? null) === $column && empty($option['nodisplay']))
            {
                try
                {
                    $field = $this->resolve(['id' => $id, 'table' => $option['table'], 'field' => $column]);
                }
                catch (SyncException $error)
                {
                    throw $error->forField(['seed_column' => $column]);
                }
                if (!$field['writable'] || $field['source'] !== 'native')
                {
                    return null;
                }
                $field['seed_column'] = $column;
                return $field;
            }
        }
        return null;
    }

    private static function portableColumn(string $column): bool
    {
        // The destination owns identity, lifecycle, security, and generated metadata.
        return preg_match('/\A[a-z][a-z0-9_]*\z/', $column) === 1
            && !preg_match('/(?:^custom_|_ids?$|password|token|secret)/i', $column)
            && !in_array($column, [
                'id', 'serial', 'uuid', 'checksum', 'last_inventory_update', 'date_creation', 'date_mod',
                'is_deleted', 'is_template', 'is_dynamic', 'is_recursive', 'is_locked', 'template_name',
                'completename', 'level', 'ancestors_cache', 'sons_cache', 'custom_fields', 'customfields',
                'custom_field_values', 'pictures', 'ticket_tco',
            ], true);
    }

    private function nativeField(array $option): array
    {
        global $DB;

        $table = $this->asset::getTable();
        $column = $option['field'];
        $dropdown = null;
        if ($option['table'] !== $table)
        {
            // Support the asset's own foreign key, not indirect search joins.
            $column = $option['linkfield'] ?? '';
            $dropdown = getItemTypeForTable($option['table']);
            $foreignKey = getForeignKeyFieldForTable($option['table']);
            if (!is_string($dropdown) || !is_a($dropdown, CommonDBTM::class, true)
                || !in_array($option['field'], ['name', 'completename'], true)
                || ($column !== $foreignKey && !str_starts_with($column, $foreignKey . '_'))
                || !empty($option['joinparams']['beforejoin']))
            {
                throw new SyncException('field_unavailable', 422);
            }
        }

        $storage = $DB->getField($table, $column);
        if ($storage === null || preg_match('/password|token|secret|credential/i', $column) === 1)
        {
            throw new SyncException('field_unavailable', 422);
        }
        // IDs are writeable only when there is a named destination reference resolver.
        $safeReference = $dropdown !== null && !in_array($column, ['entities_id', 'agents_id', 'snmpcredentials_id'], true)
            && !str_starts_with($column, 'auth') && !str_contains($column, 'credential');
        $writable = self::portableColumn($column) || $safeReference;
        if ($this->asset instanceof Asset)
        {
            foreach ($this->asset::getDefinition()->getDecodedFieldsField() as $configured)
            {
                if ($configured['key'] === $column)
                {
                    $writable = $writable && !self::isRestricted($configured['field_options']['readonly'] ?? []);
                    if (self::isRestricted($configured['field_options']['hidden'] ?? []))
                    {
                        throw new SyncException('field_unavailable', 422);
                    }
                }
            }
        }
        return [
            'column' => $column,
            'table' => $table,
            'source' => 'native',
            'kind' => $dropdown === null ? self::nativeKind($option, $storage['Type']) : 'dropdown',
            'dropdown_itemtype' => $dropdown,
            'dropdown_criteria' => $dropdown === State::class && $this->asset instanceof StateInterface
                ? ['id' => new QuerySubQuery(['SELECT' => State::getTable() . '.id', 'FROM' => State::getTable()]
                    + $this->asset->getStateVisibilityCriteria())]
                : [],
            'nullable' => $storage['Null'] === 'YES',
            'writable' => $writable,
        ];
    }

    private static function isRestricted(mixed $profiles): bool
    {
        return is_array($profiles) && (in_array(-1, array_map('intval', $profiles), true)
            || in_array((int) $_SESSION['glpiactiveprofile']['id'], array_map('intval', $profiles), true));
    }

    private static function nativeKind(array $option, string $storage): string
    {
        return match (true)
        {
            ($option['datatype'] ?? '') === 'bool' => 'boolean',
            preg_match('/^(datetime|timestamp)\b/i', $storage) === 1 => 'datetime',
            preg_match('/^date\b/i', $storage) === 1 => 'date',
            preg_match('/^(tiny|small|medium|big)?int\b/i', $storage) === 1 => 'integer',
            preg_match('/^(decimal|float|double)\b/i', $storage) === 1 => 'number',
            ($option['datatype'] ?? '') === 'weblink' => 'url',
            ($option['datatype'] ?? '') === 'text' => 'multiline',
            preg_match('/^(tiny|medium|long)?(?:text|varchar|char|enum)\b/i', $storage) === 1 => 'text',
            default => throw new SyncException('field_unavailable', 422),
        };
    }

    private function nativeCustomField(CustomFieldDefinition $definition): array
    {
        $type = $definition->fields['type'];
        $options = $definition->getFieldType()->getOptionValues();
        if (!empty($options['hidden']))
        {
            throw new SyncException('field_unavailable', 422);
        }
        $kind = match ($type)
        {
            CustomType\BooleanType::class => 'boolean',
            CustomType\NumberType::class => 'number',
            CustomType\DateTimeType::class => 'datetime',
            CustomType\DateType::class => 'date',
            CustomType\URLType::class => 'url',
            CustomType\DropdownType::class => !empty($options['multiple']) ? 'multiselect' : 'dropdown',
            CustomType\TextType::class => !empty($options['enable_richtext']) ? 'richtext' : 'multiline',
            CustomType\StringType::class => 'text',
            default => throw new SyncException('field_unavailable', 422),
        };
        return [
            'column' => 'custom_' . $definition->fields['system_name'],
            'table' => $this->asset::getTable(),
            'source' => 'custom',
            'kind' => $kind,
            'dropdown_itemtype' => $definition->fields['itemtype'] ?? null,
            'nullable' => true,
            'writable' => empty($options['readonly']),
            'required' => !empty($options['required']),
        ];
    }

    private function fieldsPluginField(int $fieldId, array $option): array
    {
        global $DB;

        if (!Plugin::isPluginActive('fields'))
        {
            throw new SyncException('field_unavailable', 422);
        }
        $definition = new PluginFieldsField();
        $container = new PluginFieldsContainer();
        if (!$definition->getFromDB($fieldId) || empty($definition->fields['is_active'])
            || !$container->getFromDB($definition->fields['plugin_fields_containers_id'])
            || empty($container->fields['is_active'])
            || !in_array($this->asset::class, PluginFieldsToolbox::decodeJSONItemtypes($container->fields['itemtypes']), true))
        {
            throw new SyncException('field_unavailable', 422);
        }
        $containerId = (int) $container->getID();
        $ownerEntity = (int) $container->fields['entities_id'];
        $right = PluginFieldsProfile::getRightOnContainer((int) $_SESSION['glpiactiveprofile']['id'], $containerId);
        // The Fields plugin treats this as a level: 0 none, READ read-only, > READ editable.
        if ($right < READ || ($ownerEntity !== $this->entityId
            && (empty($container->fields['is_recursive'])
                || !in_array($this->entityId, array_map('intval', getSonsOf(Entity::getTable(), $ownerEntity)), true))))
        {
            throw new SyncException('field_unavailable', 422);
        }

        $model = PluginFieldsContainer::getClassname($this->asset::class, $container->fields['name']);
        if (!is_a($model, PluginFieldsAbstractContainerInstance::class, true))
        {
            throw new SyncException('field_unavailable', 422);
        }

        $table = $model::getTable();
        $type = $definition->fields['type'];
        $column = $definition->fields['name'];
        $typeColumn = null;
        $dropdown = null;
        $allowedTypes = [];
        $writable = $right > READ;
        if ($type === 'glpi_item')
        {
            $typeColumn = 'itemtype_' . $column;
            $idColumn = 'items_id_' . $column;
            if ($option['table'] !== $table || !in_array($option['field'], [$typeColumn, $idColumn], true))
            {
                throw new SyncException('field_unavailable', 422);
            }
            $column = $option['field'];
            $kind = $column === $idColumn ? 'reference' : 'text';
            $allowedTypes = json_decode((string) $definition->fields['allowed_values'], true) ?? [];
            $writable = $writable && $kind === 'reference';
        }
        elseif ($type === 'dropdown' || str_starts_with($type, 'dropdown-'))
        {
            if ($type === 'dropdown')
            {
                $dropdown = PluginFieldsDropdown::getClassname($column);
                $column = 'plugin_fields_' . $column . 'dropdowns_id';
            }
            else
            {
                $dropdown = substr($type, strlen('dropdown-'));
            }
            $kind = !empty($definition->fields['multiple']) ? 'multiselect' : 'dropdown';
        }
        else
        {
            $kind = match ($type)
            {
                'yesno' => 'boolean',
                'textarea' => 'multiline',
                'text', 'number', 'date', 'datetime', 'url', 'richtext' => $type,
                default => throw new SyncException('field_unavailable', 422),
            };
        }

        $storage = $DB->getField($table, $column);
        if ($storage === null)
        {
            throw new SyncException('field_unavailable', 422);
        }
        $settings = $this->fieldsPluginSettings($containerId, $definition->fields);
        return [
            'column' => $column,
            'table' => $table,
            'source' => 'fields',
            'kind' => $kind,
            'dropdown_itemtype' => $dropdown,
            'allowed_itemtypes' => $allowedTypes,
            'type_column' => $typeColumn,
            'nullable' => $storage['Null'] === 'YES',
            'writable' => $writable && empty($settings['is_readonly']),
            'required' => !empty($settings['mandatory']),
            'container' => $containerId,
            'container_label' => trim((string) ($container->fields['label'] ?? '')),
            'model' => $model,
            'definition' => $definition->fields,
        ];
    }

    private function fieldsPluginSettings(int $containerId, array $definition): array
    {
        foreach (PluginFieldsStatusOverride::getOverridesForItem($containerId, $this->asset) as $override)
        {
            if ((int) $override['plugin_fields_fields_id'] === (int) $definition['id'])
            {
                $definition['is_readonly'] = $override['is_readonly'];
                $definition['mandatory'] = $override['mandatory'];
            }
        }
        return $definition;
    }

    public function lockContainers(array $fields): void
    {
        global $DB;

        $containers = [];
        foreach ($fields as $field)
        {
            if ($field['source'] === 'fields')
            {
                $containers[$field['container']] = $field;
            }
        }
        ksort($containers);
        foreach ($containers as $containerId => $field)
        {
            $query = new DBmysqlIterator($DB);
            $query->buildQuery(['FROM' => $field['table'], 'WHERE' => $this->containerCriteria($containerId), 'LIMIT' => 2]);
            $result = $DB->doQuery($query->getSql() . ' FOR UPDATE');
            if ($result->num_rows > 1)
            {
                throw new SyncException('field_unavailable', 422);
            }
            $this->containerRows[$containerId] = $result->fetch_assoc() ?? [];
        }
    }

    private function containerCriteria(int $containerId): array
    {
        return [
            'items_id' => $this->asset->getID(),
            'itemtype' => $this->asset::class,
            'plugin_fields_containers_id' => $containerId,
        ];
    }

    public function read(array $field, bool $allowUnresolvedDropdown = false): array
    {
        global $DB;

        if ($field['source'] === 'notes')
        {
            return Notes::read($this->asset);
        }

        $column = $field['column'];
        $value = $this->asset->fields[$column] ?? null;
        if ($field['source'] === 'fields')
        {
            $row = $this->containerRows[$field['container']] ?? $DB->request([
                'FROM' => $field['table'],
                'WHERE' => $this->containerCriteria($field['container']),
            ])->current();
            $value = $row[$column] ?? null;
            if (!$row && $field['kind'] !== 'reference')
            {
                $value = PluginFieldsField::getDefaultValue($field['definition']);
                // A missing default option is a configuration error and has no stored ID to replace.
                $allowUnresolvedDropdown = false;
            }
            if ($field['kind'] === 'reference')
            {
                $value = ['itemtype' => (string) ($row[$field['type_column']] ?? ''), 'items_id' => (int) $value];
            }
            if ($field['kind'] === 'multiselect' && is_string($value))
            {
                $value = json_decode($value, true) ?? [];
            }
        }

        if (in_array($field['kind'], ['date', 'datetime'], true) && $value === 'NULL')
        {
            $value = null;
        }
        if ($field['kind'] === 'datetime' && is_string($value) && $value !== '' && !str_starts_with($value, '0000-00-00'))
        {
            // GLPI native fields and display models operate in the active GLPI timezone.
            try
            {
                $value = (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            catch (Exception)
            {
                throw (new SyncException('invalid_field_value', 422))->forField($field)
                    ->because('GLPI B could not read the stored date and time for this field.');
            }
        }

        try
        {
            $snapshot = Values::decode($field, $value, $this->entityId, $allowUnresolvedDropdown);
        }
        catch (SyncException $error)
        {
            throw $error->forField($field)->because('GLPI B could not interpret the stored value of this field.');
        }
        $snapshot['modified_at'] = $this->lastEdit($field);
        return $snapshot;
    }

    private function lastEdit(array $field): ?int
    {
        global $DB;

        // The asset's date_mod changes when any field changes. Only query applicable field history.
        $row = $DB->request([
            'SELECT' => [new QueryExpression('MAX(UNIX_TIMESTAMP(`date_mod`)) AS changed_at')],
            'FROM' => Log::getTable(),
            'WHERE' => [
                'itemtype' => $this->asset::class,
                'items_id' => $this->asset->getID(),
                'id_search_option' => $this->historyOptionIds($field),
            ],
        ])->current();
        return isset($row['changed_at']) ? (int) $row['changed_at'] : null;
    }

    public function historyOptionIds(array $field): array
    {
        $ids = [$field['id'], $this->historyId($field)];
        if ($field['kind'] === 'reference')
        {
            // A change to only the referenced item type still changes the compound reference.
            $ids[] = $field['id'] - 1;
        }
        return array_values(array_unique($ids));
    }

    private function historyId(array $field): int
    {
        if ($field['source'] === 'native')
        {
            return $this->nativeHistoryId($field['column']) ?? $field['id'];
        }
        return $field['id'];
    }

    private function nativeHistoryId(string $column): ?int
    {
        // GLPI uses the first matching option when aliases share a physical column.
        $table = $this->asset::getTable();
        foreach (SearchOption::getOptionsForItemtype($this->asset::class) as $id => $option)
        {
            if (is_int($id) && (($option['linkfield'] ?? null) === $column
                || (($option['table'] ?? null) === $table && ($option['field'] ?? null) === $column)))
            {
                return $id;
            }
        }
        return null;
    }

    public function recordCreation(): void
    {
        if (!$this->asset->dohistory)
        {
            return;
        }
        if (!Log::history($this->asset->getID(), $this->asset::class, [0], '', Log::HISTORY_CREATE_ITEM))
        {
            throw new SyncException('field_write_failed', 500);
        }

        // Creation always supplies this identity, even when it is absent from the field mappings.
        $entity = new Entity();
        $entityName = (string) $this->entityId;
        if ($entity->getFromDB($this->entityId))
        {
            $entityName = $entity->fields['completename'] ?: $entity->fields['name'];
        }
        foreach (['entities_id' => $entityName, 'serial' => $this->asset->fields['serial']] as $column => $value)
        {
            $id = $this->nativeHistoryId($column);
            if ($id !== null && !History::recordField($this->asset, $id, ['column' => $column, 'kind' => 'text'], null, $value, true))
            {
                throw new SyncException('field_write_failed', 500);
            }
        }
    }

    public function recordEdit(array $field, mixed $before, mixed $after, bool $initial): void
    {
        if ($this->asset->dohistory
            && !History::recordField($this->asset, $this->historyId($field), $field, $before, $after, $initial))
        {
            throw (new SyncException('field_write_failed', 500))->forField($field)
                ->because('GLPI B could not record the field change in the asset history.');
        }
    }

    public function plan(array $field, mixed $value, array &$native, array &$containers, array &$notes): mixed
    {
        if (!$field['writable'])
        {
            throw (new SyncException('field_read_only', 403))->forField($field)
                ->because('The destination field is read-only for the sync account.');
        }
        try
        {
            if ($field['source'] === 'notes')
            {
                // Notes are separate records. Keep destination notes and plan only additions.
                $notes = Notes::normalize(array_merge(Notes::read($this->asset, true)['value'], Notes::normalize($value)));
                return $notes;
            }

            $raw = Values::encode($field, $value, $this->entityId);
            $portable = Values::decode($field, $raw, $this->entityId)['value'];
        }
        catch (SyncException $error)
        {
            throw $error->forField($field)->because('GLPI B could not prepare the submitted value for this field.');
        }

        if (!empty($field['required']) && ($portable === null || $portable === '' || $portable === []))
        {
            throw (new SyncException('invalid_field_value', 422))->forField($field)
                ->because('The destination field is required and the submitted value is empty.');
        }
        if ($field['kind'] === 'datetime' && is_string($raw) && $raw !== '')
        {
            $raw = (new DateTimeImmutable($raw, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        }

        if ($field['source'] === 'fields')
        {
            $containers[$field['container']][$field['key']] = ['field' => $field, 'raw' => $raw, 'value' => $portable];
        }
        else
        {
            // GLPI's native custom field model uses isset() to detect submitted columns.
            $native[$field['column']] = $field['source'] === 'custom' && $raw === null ? '' : $raw;
        }
        return $portable;
    }

    public function saveContainers(array $containers): void
    {
        foreach ($containers as $containerId => $writes)
        {
            $input = $this->containerCriteria($containerId);
            $fields = array_column($writes, 'field');
            $group = ($fields[0]['container_label'] ?? '') ?: "Fields container #$containerId";
            foreach ($writes as ['field' => $field, 'raw' => $raw, 'value' => $portable])
            {
                // Recheck status-dependent rules after any native status change in this request.
                $settings = $this->fieldsPluginSettings($containerId, $field['definition']);
                if (!empty($settings['is_readonly']))
                {
                    throw (new SyncException('field_read_only', 403))->forField($field)
                        ->because('The destination field is read-only for the asset’s current status.');
                }
                if (!empty($settings['mandatory']) && ($portable === null || $portable === '' || $portable === []))
                {
                    throw (new SyncException('invalid_field_value', 422))->forField($field)
                        ->because('The asset’s current status requires a value in this field.');
                }
                if ($field['kind'] === 'reference')
                {
                    $input[$field['column']] = $raw['items_id'];
                    $input[$field['type_column']] = $raw['itemtype'];
                }
                else
                {
                    $input[$field['column']] = $raw;
                }
            }
            if (!PluginFieldsContainer::validateValues($input, $this->asset::class, true))
            {
                // The Fields validator returns one result for the entire container.
                throw (new SyncException('invalid_field_value', 422))->forGroup($group, $fields)
                    ->because('The Fields plugin rejected this group during validation.');
            }
            foreach ($writes as ['field' => $field, 'raw' => $raw])
            {
                // Generated Fields models store the local multiselect IDs as JSON.
                if ($field['kind'] === 'multiselect')
                {
                    $input[$field['column']] = json_encode($raw, JSON_THROW_ON_ERROR);
                }
            }

            // updateFieldsValues() in Fields ignores its model's add/update return value.
            // Use the same validation and model directly so failed writes can be rolled back.
            $model = new ($fields[0]['model'])();
            if ($model->getFromDBByCrit($this->containerCriteria($containerId)))
            {
                $input['id'] = $model->getID();
                $written = $model->update($input, false);
            }
            else
            {
                $written = $model->add($input, [], false);
            }
            if (!$written)
            {
                throw (new SyncException('field_write_failed', 422))->forGroup($group, $fields)
                    ->because('The Fields plugin could not save the values for this group.');
            }

            // Refresh the locked row so the transaction can verify the persisted values.
            $this->lockContainers($fields);
        }
    }
}
