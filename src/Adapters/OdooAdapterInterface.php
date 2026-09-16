<?php

namespace LeKoala\OdooClient\Adapters;

use LeKoala\OdooClient\Enums\OdooModel;

/**
 * Interface for Odoo API adapters.
 */
interface OdooAdapterInterface
{
    /**
     * Prove the configured credentials actually work.
     *
     * Not a login - JSON-2 is stateless and authenticates every request on its
     * own. This is an authenticated probe: a call that cannot succeed unless
     * the key is valid and resolves to a usable user. Implementations must make
     * a real round trip; returning true without one is a false negative machine.
     *
     * @return bool True when the credentials resolved to a user
     * @throws \LeKoala\OdooClient\Exceptions\OdooAuthException The key is missing, unknown or expired (401)
     * @throws \LeKoala\OdooClient\Exceptions\OdooAccessException The key is good, the user has no rights (403)
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function authenticate(): bool;

    /**
     * Call a method on an Odoo model with named parameters.
     *
     * This is the protocol as Odoo 19 defines it: model and method address the
     * endpoint, and every argument is named. `$params` is the request body -
     * nothing is inferred from it, nothing is reordered, and a parameter the
     * caller did not name does not get invented.
     *
     * Recordset methods take their records as the named `ids` parameter.
     *
     * @param OdooModel|string $model The Odoo model name (e.g., 'res.partner')
     * @param string $method The method to call (e.g., 'search_read')
     * @param array<string, mixed> $params Named parameters, sent as the request body
     * @return mixed
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function call(OdooModel|string $model, string $method, array $params = []): mixed;
}
