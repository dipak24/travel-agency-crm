<?php

namespace App\Services\Dns;

/**
 * Thin wrapper around the resolver so DNS lookups can be swapped out in tests.
 */
class TxtRecordLookup
{
    /**
     * @return list<string>
     */
    public function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['txt'] ?? null,
            $records,
        )));
    }
}
