<?php

namespace App\Services\Configuration;

use JsonException;

class CanonicalSnapshot
{
    /**
     * Produce stable JSON bytes: object keys are sorted recursively while list order is preserved.
     *
     * @throws JsonException
     */
    public function encode(array $snapshot): string
    {
        return json_encode(
            $this->sortRecursively($snapshot),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function sortRecursively(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn ($item) => is_array($item) ? $this->sortRecursively($item) : $item,
                $value
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }

        return $value;
    }
}
