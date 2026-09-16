<?php

namespace LeKoala\OdooClient\Services;

use LeKoala\OdooClient\Models\ApiKey;
use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\OdooException;

class ApiKeyService
{
    public const DEFAULT_KEY_LIMIT = 10;

    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return array<int, ApiKey>
     * @throws OdooException
     */
    public function listKeys(): array
    {
        $fields = $this->client->getSafeFields('res.users.apikeys', ['name', 'scope', 'expiration_date']);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->client->searchRead('res.users.apikeys', [], $fields, 0, 0, 'id desc');

        return array_map(static fn(array $row): ApiKey => ApiKey::fromRow($row), $rows);
    }

    /**
     * Find a single key by its exact name.
     *
     * Queries Odoo directly instead of scanning a capped `listKeys()` page, so
     * a key beyond the default page size is still found.
     *
     * @throws OdooException
     */
    public function findByName(string $name): ?ApiKey
    {
        $fields = $this->client->getSafeFields('res.users.apikeys', ['name', 'scope', 'expiration_date']);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->client->searchRead(
            'res.users.apikeys',
            [['name', '=', $name]],
            $fields,
            1,
            0,
            'id desc',
        );

        $row = $rows[0] ?? null;

        return is_array($row) ? ApiKey::fromRow($row) : null;
    }

    /**
     * @throws OdooException
     */
    public function generate(string $name, ?string $expirationDate = null, ?string $scope = null): string
    {
        $result = $this->client->call('res.users.apikeys', 'generate', [
            'key' => $this->client->getApiKey(),
            'name' => $name,
            'scope' => $scope,
            'expiration_date' => $expirationDate,
        ]);

        $generated = $this->readGeneratedKey($result);

        if ($generated === '') {
            throw new OdooException(sprintf(
                'res.users.apikeys/generate() returned no readable key for "%s". Assume the key WAS created: '
                . 'list res.users.apikeys, delete the record named "%s", and only then try again. Do not '
                . 'retry generate() as-is.',
                $name,
                $name
            ));
        }

        return $generated;
    }

    /**
     * @throws OdooException
     */
    public function revoke(string $key): bool
    {
        if (trim($key) === '') {
            throw new OdooException('Refusing to revoke an empty API key.');
        }

        $result = $this->client->call('res.users.apikeys', 'revoke', ['key' => $key]);

        return $result !== false;
    }

    /**
     * @throws OdooException
     */
    public function programmaticManagementEnabled(): ?bool
    {
        $value = $this->systemParameter('base.enable_programmatic_api_keys');

        if ($value === null) {
            return null;
        }

        return in_array(strtolower(trim($value)), ['true', '1', 'yes', 't'], true);
    }

    /**
     * @throws OdooException
     */
    public function programmaticKeyLimit(): int
    {
        $value = $this->systemParameter('base.programmatic_api_keys_limit');

        if ($value === null || !is_numeric($value)) {
            return self::DEFAULT_KEY_LIMIT;
        }

        return max(1, (int) $value);
    }

    /**
     * @throws OdooException
     */
    private function systemParameter(string $key): ?string
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->client->searchRead('ir.config_parameter', [['key', '=', $key]], ['key', 'value'], 1);

        if ($rows === [] || !isset($rows[0]['value']) || !is_string($rows[0]['value'])) {
            return null;
        }

        return $rows[0]['value'];
    }

    private function readGeneratedKey(mixed $result): string
    {
        if (is_string($result)) {
            return trim($result);
        }

        if (!is_array($result)) {
            return '';
        }

        foreach (['result', 'key', 'api_key', 'value'] as $candidate) {
            if (isset($result[$candidate]) && is_string($result[$candidate]) && trim($result[$candidate]) !== '') {
                return trim($result[$candidate]);
            }
        }

        if (count($result) === 1) {
            $only = reset($result);
            if (is_string($only) && trim($only) !== '') {
                return trim($only);
            }
        }

        return '';
    }
}
