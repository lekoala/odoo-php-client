<?php

namespace LeKoala\OdooClient\Legacy;

/**
 * Translate an XML-RPC-shaped call into JSON-2 named parameters.
 *
 * This is the guessing layer, extracted so that it sits on exactly one code
 * path - the deprecated one - instead of on every call. JSON-2 accepts only
 * named arguments, so anything positional has to be given a name, and the only
 * information available for that is the method name plus the shape of the
 * value. That works for the methods below and it is a guess everywhere else.
 *
 * @deprecated For new code. Name your parameters and use Client::call().
 * @internal Compatibility implementation for deprecated Client::execute().
 */
final class LegacyRpcArgumentMapper
{
    /**
     * @var array<string, string>
     */
    private const PRIMARY_KEY_MAP = [
        'search' => 'domain',
        'search_read' => 'domain',
        'read' => 'ids',
        'write' => 'ids',
        'unlink' => 'ids',
        'name_search' => 'name',
        'name_get' => 'ids',
        'fields_get' => 'attributes',
        'action_confirm' => 'ids',
        'action_create_payments' => 'ids',
    ];

    /**
     * @param string $method
     * @param array<int, mixed> $args
     * @param array<string, mixed> $kwargs
     * @return array<string, mixed>
     */
    public function toNamedParams(string $method, array $args, array $kwargs = []): array
    {
        $body = [];

        if (isset(self::PRIMARY_KEY_MAP[$method]) && array_key_exists(0, $args)) {
            $body[self::PRIMARY_KEY_MAP[$method]] = $args[0];
        }

        if ($method === 'write' && array_key_exists(1, $args)) {
            $body['vals'] = $args[1];
        }

        if ($method === 'create' && array_key_exists(0, $args)) {
            $body['vals_list'] = $this->normalizeValsList($args[0]);
        }

        if (!isset(self::PRIMARY_KEY_MAP[$method]) && $method !== 'create' && array_key_exists(0, $args)) {
            if ($this->isIdsArray($args[0])) {
                $body['ids'] = (is_array($args[0]) && isset($args[0][0]) && is_array($args[0][0]) && count($args) === 1)
                    ? $args[0][0]
                    : $args[0];
            } elseif (is_string($args[0])) {
                $body['name'] = $args[0];
            }
        }

        return array_merge($body, $kwargs);
    }

    private function isIdsArray(mixed $value): bool
    {
        if (!is_array($value) || empty($value)) {
            return false;
        }

        if (array_keys($value) === range(0, count($value) - 1)
            && count(array_filter($value, 'is_int')) === count($value)
        ) {
            return true;
        }

        if (count($value) === 1 && is_array($value[0])) {
            $inner = $value[0];
            if (array_keys($inner) === range(0, count($inner) - 1)
                && count(array_filter($inner, 'is_int')) === count($inner)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private function normalizeValsList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (isset($value[0]) && is_array($value[0])) {
            $records = [];
            foreach ($value as $record) {
                if (!is_array($record)) {
                    return [];
                }
                /** @var array<string, mixed> $record */
                $records[] = $record;
            }
            return $records;
        }

        /** @var array<string, mixed> $value */
        return [$value];
    }
}
