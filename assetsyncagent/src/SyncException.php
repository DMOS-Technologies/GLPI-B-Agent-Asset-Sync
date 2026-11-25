<?php

namespace AssetSyncAgent;

// Messages are public error codes. Details may contain resolved fields and safe explanations.
// Keep submitted values and raw GLPI errors out of this exception.
final class SyncException extends \RuntimeException
{
    private array $details = [];

    public function __construct(string $error, int $status = 400)
    {
        parent::__construct($error, $status);
    }

    public function because(string $reason): self
    {
        $this->details['reason'] ??= $reason;
        return $this;
    }

    public function forField(array $field): self
    {
        // A native seed was submitted by column name, independently of search-option IDs.
        $seedColumn = $field['seed_column'] ?? null;
        $id = $field['id'] ?? null;
        if (is_string($seedColumn))
        {
            unset($this->details['field_id']);
            $this->details['column'] = $seedColumn;
        }
        elseif (is_int($id) && $id > 0)
        {
            unset($this->details['column']);
            $this->details['field_id'] = $id;
        }
        return $this;
    }

    public function forGroup(string $group, array $fields): self
    {
        $this->details['group'] = $group;
        $fieldIds = [];
        $columns = [];
        foreach ($fields as $field)
        {
            $seedColumn = $field['seed_column'] ?? null;
            $id = $field['id'] ?? null;
            if (is_string($seedColumn))
            {
                $columns[] = $seedColumn;
            }
            elseif (is_int($id) && $id > 0)
            {
                $fieldIds[] = $id;
            }
        }
        if ($fieldIds !== [])
        {
            $this->details['field_ids'] = $fieldIds;
        }
        if ($columns !== [])
        {
            $this->details['columns'] = $columns;
        }
        return $this;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
