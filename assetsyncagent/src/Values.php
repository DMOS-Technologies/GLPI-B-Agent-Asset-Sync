<?php

namespace AssetSyncAgent;

use CommonDBTM;
use CommonDropdown;
use DateTimeImmutable;
use DateTimeZone;
use Entity;
use Glpi\DBAL\QuerySubQuery;
use Glpi\RichText\RichText;
use Profile_User;
use User;

final class Values
{
    // Convert the portable wire value into the destination model's input format.
    public static function encode(array $field, mixed $value, int $entityId): mixed
    {
        $kind = self::kind($field);
        if ($kind === 'dropdown' || $kind === 'dropdown_list')
        {
            try
            {
                return self::encodeDropdown($field, $kind, $value, $entityId);
            }
            catch (SyncException $error)
            {
                throw $error->forDropdown($field['id'] ?? null);
            }
        }
        if ($kind === 'item')
        {
            return self::encodeItem($field, $value, $entityId);
        }
        if ($value === null || $value === '')
        {
            if ($kind === 'text' && !($field['nullable'] ?? true))
            {
                return '';
            }
            return $kind === 'boolean' && !($field['nullable'] ?? true) ? 0 : null;
        }

        return self::scalar($field, $kind, $value, true);
    }

    public static function decode(array $field, mixed $value, int $entityId): array
    {
        $kind = self::kind($field);
        if ($kind === 'dropdown' || $kind === 'dropdown_list')
        {
            try
            {
                $value = self::decodeDropdown($field, $kind, $value, $entityId);
            }
            catch (SyncException $error)
            {
                throw $error->forDropdown($field['id'] ?? null);
            }
        }
        elseif ($kind === 'item')
        {
            $value = self::decodeItem($field, $value, $entityId);
        }
        elseif ($value === '' || (in_array($kind, ['date', 'datetime'], true)
            && is_string($value) && str_starts_with($value, '0000-00-00')))
        {
            $value = null;
        }
        elseif ($value !== null)
        {
            $value = self::scalar($field, $kind, $value, false);
        }

        return ['value' => $value, 'kind' => $kind];
    }

    private static function kind(array $field): string
    {
        return match ($field['kind'] ?? null) {
            'multiselect' => 'dropdown_list',
            'reference' => 'item',
            'multiline', 'richtext', 'rich_text', 'url' => 'text',
            'text', 'dropdown', 'dropdown_list', 'item', 'number', 'integer',
            'date', 'datetime', 'boolean' => $field['kind'],
            default => throw new SyncException('field_unavailable', 422),
        };
    }

    private static function scalar(array $field, string $kind, mixed $value, bool $writing): mixed
    {
        if ($kind === 'boolean')
        {
            $boolean = match (strtolower(trim(self::text($value)))) {
                'yes', 'true', 'on', '1' => true,
                'no', 'false', 'off', '0' => false,
                default => throw new SyncException('invalid_field_value', 422),
            };
            return $writing ? (int) $boolean : $boolean;
        }
        if ($kind === 'integer' || $kind === 'number')
        {
            $number = self::decimal($value);
            if ($kind === 'number')
            {
                return $number;
            }
            $integer = filter_var($number, FILTER_VALIDATE_INT);
            if ($integer === false)
            {
                throw new SyncException('invalid_field_value', 422);
            }
            return $integer;
        }
        if ($kind === 'date' || $kind === 'datetime')
        {
            return self::date($value, $kind === 'datetime');
        }

        $text = self::text($value);
        if ($writing && ($field['kind'] ?? null) === 'url'
            && (!filter_var($text, FILTER_VALIDATE_URL)
                || !in_array(strtolower((string) parse_url($text, PHP_URL_SCHEME)), ['http', 'https', 'ftp'], true)))
        {
            throw new SyncException('invalid_field_value', 422);
        }
        if ($writing && in_array($field['kind'], ['richtext', 'rich_text'], true))
        {
            return RichText::getSafeHtml($text);
        }
        return $text;
    }

    private static function text(mixed $value): string
    {
        if (is_bool($value))
        {
            return $value ? 'Yes' : 'No';
        }
        if (is_scalar($value))
        {
            return (string) $value;
        }
        if (is_array($value) && is_string($value['label'] ?? null))
        {
            return $value['label'];
        }
        if (is_array($value) && array_is_list($value))
        {
            foreach ($value as $label)
            {
                if (!is_string($label))
                {
                    throw new SyncException('invalid_field_value', 422);
                }
            }
            return implode(', ', $value);
        }
        throw new SyncException('invalid_field_value', 422);
    }

    private static function decimal(mixed $value): string
    {
        if (is_bool($value))
        {
            return $value ? '1' : '0';
        }
        // Use strings throughout so values such as 9007199254740993.01 keep every digit.
        $input = is_float($value) ? json_encode($value) : trim(self::text($value));
        if (!is_string($input) || !preg_match('/\A([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d{1,4}))?\z/', $input, $parts)
            || ($parts[2] === '' && ($parts[3] ?? '') === ''))
        {
            throw new SyncException('invalid_field_value', 422);
        }

        $digits = $parts[2] . ($parts[3] ?? '');
        $separator = strlen($parts[2]) + (int) ($parts[4] ?? 0);
        if ($separator <= 0)
        {
            $digits = str_repeat('0', 1 - $separator) . $digits;
            $separator = 1;
        }
        $digits = str_pad($digits, $separator, '0');
        $whole = ltrim(substr($digits, 0, $separator), '0') ?: '0';
        $fraction = rtrim(substr($digits, $separator), '0');
        $number = $whole . ($fraction === '' ? '' : '.' . $fraction);

        return $parts[1] === '-' && $number !== '0' ? '-' . $number : $number;
    }

    private static function date(mixed $value, bool $withTime): string
    {
        if (!is_string($value) || str_starts_with($value, '0000-')
            || !preg_match('/\A\d{4}-\d{2}-\d{2}(?:[ T](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)?)?\z/', $value))
        {
            throw new SyncException('invalid_field_value', 422);
        }

        $utc = new DateTimeZone('UTC');
        $date = new DateTimeImmutable($value, $utc);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        {
            throw new SyncException('invalid_field_value', 422);
        }
        return $withTime ? $date->setTimezone($utc)->format('Y-m-d H:i:s') : $date->format('Y-m-d');
    }

    private static function encodeDropdown(array $field, string $kind, mixed $value, int $entityId): mixed
    {
        $multiple = $kind === 'dropdown_list';
        if (is_string($value))
        {
            $value = trim($value);
        }
        if ($value === null || $value === '')
        {
            return $multiple ? [] : 0;
        }
        $labels = is_array($value) && array_is_list($value) ? $value : [$value];
        if (!$multiple && count($labels) > 1)
        {
            throw new SyncException('invalid_field_value', 422);
        }

        $ids = [];
        foreach ($labels as $label)
        {
            // Even for numeric labels, only look up the option by name.
            if (!is_string($label) || trim($label) === '')
            {
                throw new SyncException('invalid_field_value', 422);
            }
            $row = self::findChoice($field['dropdown_itemtype'] ?? null, $entityId, trim($label), null, null, false, $field['dropdown_criteria'] ?? []);
            $ids[] = (int) $row['id'];
        }
        return $multiple ? array_values(array_unique($ids)) : ($ids[0] ?? 0);
    }

    private static function decodeDropdown(array $field, string $kind, mixed $value, int $entityId): mixed
    {
        $multiple = $kind === 'dropdown_list';
        if ($value === null || $value === '')
        {
            return $multiple ? [] : null;
        }
        if ($multiple && is_string($value) && str_starts_with($value, '['))
        {
            $value = json_decode($value, true);
            if (!is_array($value) || !array_is_list($value))
            {
                throw new SyncException('reference_unavailable', 422);
            }
        }

        $type = $field['dropdown_itemtype'] ?? null;
        $labels = [];
        foreach (is_array($value) ? $value : [$value] as $id)
        {
            if ((int) $id === 0 && $type !== Entity::class)
            {
                continue;
            }
            $row = self::findChoice($type, $entityId, null, (int) $id, null, false, $field['dropdown_criteria'] ?? []);
            $labels[] = self::choiceLabel($type, $row);
        }
        return $multiple ? $labels : ($labels[0] ?? null);
    }

    private static function encodeItem(array $field, mixed $value, int $entityId): array
    {
        if ($value === null || $value === '')
        {
            return ['itemtype' => '', 'items_id' => 0];
        }
        $allowedTypes = $field['allowed_itemtypes'] ?? [];
        if (is_string($value) && count($allowedTypes) === 1)
        {
            $value = ['itemtype' => reset($allowedTypes), 'label' => $value];
        }
        if (!is_array($value) || !is_string($value['itemtype'] ?? null) || !is_string($value['label'] ?? null)
            || (isset($value['serial']) && !is_string($value['serial']))
            || (trim($value['label']) === '' && trim($value['serial'] ?? '') === ''))
        {
            throw new SyncException('invalid_field_value', 422);
        }

        $type = $value['itemtype'];
        self::assertAllowedItemtype($field, $type);
        $row = self::findChoice($type, $entityId, $value['label'], null, $value['serial'] ?? null, true);
        return ['itemtype' => $type, 'items_id' => (int) $row['id']];
    }

    private static function decodeItem(array $field, mixed $value, int $entityId): ?array
    {
        $id = (int) ($value['items_id'] ?? 0);
        if ($id < 1)
        {
            return null;
        }

        $type = $value['itemtype'] ?? '';
        self::assertAllowedItemtype($field, $type);
        $row = self::findChoice($type, $entityId, null, $id, null, true);
        $hasSerial = is_string($row['serial'] ?? null) && trim($row['serial']) !== '';
        $result = ['itemtype' => $type, 'label' => self::choiceLabel($type, $row, $hasSerial)];
        if ($hasSerial)
        {
            $result['serial'] = $row['serial'];
        }
        return $result;
    }

    private static function assertAllowedItemtype(array $field, string $type): void
    {
        if (!in_array($type, $field['allowed_itemtypes'] ?? [], true))
        {
            throw new SyncException('reference_unavailable', 422);
        }
    }

    private static function choiceLabel(string $type, array $row, bool $allowEmpty = false): string
    {
        foreach (['completename', $type::getNameField(), 'name'] as $column)
        {
            $label = $row[$column] ?? null;
            if (is_string($label) && trim($label) !== '')
            {
                return $label;
            }
        }
        if ($allowEmpty)
        {
            return '';
        }
        throw new SyncException('reference_unavailable', 422);
    }

    private static function findChoice(
        ?string $type,
        int $entityId,
        ?string $label,
        ?int $id,
        ?string $serial,
        bool $itemReference,
        array $extraCriteria = []
    ): array
    {
        global $DB;

        if ($type === null || !is_a($type, CommonDBTM::class, true)
            || !(($model = getItemForItemtype($type)) instanceof CommonDBTM))
        {
            throw new SyncException('field_unavailable', 422);
        }
        $table = $type::getTable();
        $where = $type::getSystemSQLCriteria();
        $names = [];
        $completeName = null;
        if ($extraCriteria !== [])
        {
            $where[] = $extraCriteria;
        }
        if ($id !== null)
        {
            $where[] = ['id' => $id];
        }
        elseif (is_string($serial) && trim($serial) !== '' && $DB->fieldExists($table, 'serial'))
        {
            $where[] = ['serial' => $serial];
        }
        else
        {
            if ($label === null || trim($label) === '')
            {
                throw new SyncException('reference_unavailable', 422);
            }
            if ($DB->fieldExists($table, 'completename'))
            {
                $completeName = $label;
            }
            foreach (array_unique([$type::getNameField(), 'name']) as $column)
            {
                if ($column !== 'completename' && $DB->fieldExists($table, $column))
                {
                    $names[$column] = $label;
                }
            }
            if ($names === [] && $completeName === null)
            {
                throw new SyncException('field_unavailable', 422);
            }
        }

        if ($type === Entity::class)
        {
            $where[] = ['id' => $entityId];
        }
        elseif ($type === User::class)
        {
            $profiles = Profile_User::getTable();
            $where[] = ['id' => new QuerySubQuery([
                'SELECT' => 'users_id',
                'FROM' => $profiles,
                'WHERE' => getEntitiesRestrictCriteria($profiles, '', $entityId, true),
            ])];
        }
        elseif ($DB->fieldExists($table, 'entities_id'))
        {
            $where[] = getEntitiesRestrictCriteria($table, '', $entityId, $DB->fieldExists($table, 'is_recursive'));
        }
        foreach (['is_deleted' => 0, 'is_template' => 0, 'is_active' => 1] as $column => $required)
        {
            if ($DB->fieldExists($table, $column))
            {
                $where[] = [$column => $required];
            }
        }

        // Common dropdown options are selectable even if the profile cannot administer their list.
        $checkRead = $itemReference || !($model instanceof CommonDropdown);
        $match = null;
        if ($completeName !== null)
        {
            // Prefer the full GLPI path. Use a leaf label only if it names one visible option.
            $match = self::uniqueChoice($model, [...$where, ['completename' => $completeName]], $checkRead);
        }
        if ($match === null && $names !== [])
        {
            $match = self::uniqueChoice($model, [...$where, ['OR' => $names]], $checkRead);
        }
        if ($names === [] && $completeName === null)
        {
            $match = self::uniqueChoice($model, $where, $checkRead);
        }
        return $match ?? throw new SyncException('reference_unavailable', 422);
    }

    private static function uniqueChoice(CommonDBTM $model, array $where, bool $checkRead): ?array
    {
        global $DB;

        $match = null;
        foreach ($DB->request(['FROM' => $model::getTable(), 'WHERE' => $where]) as $row)
        {
            $model->fields = $row;
            if ($checkRead && (!$model::canView() || !$model->canViewItem()))
            {
                continue;
            }
            if ($match !== null)
            {
                throw new SyncException('reference_unavailable', 422);
            }
            $match = $row;
        }
        return $match;
    }
}
