<?php

namespace AssetSyncAgent;

// Messages are public error codes. Never put a submitted value in this exception.
final class SyncException extends \RuntimeException
{
    private ?int $fieldId = null;

    public function __construct(string $error, int $status = 400)
    {
        parent::__construct($error, $status);
    }

    public function forDropdown(?int $fieldId): self
    {
        // Only mention a resolved search-option ID for a dropdown lookup error.
        if ($fieldId > 0 && in_array($this->getMessage(), ['reference_unavailable', 'invalid_reference_value'], true))
        {
            $this->fieldId = $fieldId;
        }
        return $this;
    }

    public function getFieldId(): ?int
    {
        return $this->fieldId;
    }
}
