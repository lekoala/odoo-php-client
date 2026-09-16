<?php

namespace LeKoala\OdooClient\Services;

use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Models\Product;
use LeKoala\OdooClient\Models\Tax;
use LeKoala\OdooClient\QueryOptions;

/**
 * Service for managing the Odoo Product catalog.
 *
 * This service interacts primarily with the `product.product` model.
 *
 * @link https://www.odoo.com/documentation/master/applications/sales/sales/products_prices/products.html
 * @link https://www.odoo.com/documentation/master/developer/reference/external_api.html
 */
class ProductService
{
    private Client $client;
    private string $model = 'product.product';

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * List products based on a domain filter.
     *
     * @param array<int, mixed> $domain Odoo search domain
     * @param int|QueryOptions|null $options Detailed options, an explicit legacy limit, or null for all rows
     * @return Product[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function list(array $domain = [], int|QueryOptions|null $options = null): array
    {
        $options = QueryOptions::from($options);
        $fields = $this->queryFields($this->model, $options);
        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->searchRead(
            $this->model,
            $domain,
            $fields,
            $options->searchReadLimit(),
            $options->offset,
            $options->order,
            $options->context,
        );
        return array_map(fn(array $data) => new Product($data), $records);
    }

    /**
     * @param array<int, mixed> $domain
     * @return \Generator<int, Product>
     */
    public function iterate(array $domain = [], ?QueryOptions $options = null): \Generator
    {
        $options ??= new QueryOptions();
        foreach ($this->client->iterateSearchRead(
            $this->model,
            $domain,
            $this->queryFields($this->model, $options),
            $options,
        ) as $record) {
            yield new Product($record);
        }
    }

    /** @return array<int, string> */
    private function queryFields(string $model, QueryOptions $options): array
    {
        return $options->fields === []
            ? []
            : $this->client->getSafeFields($model, $options->fields, $options->context);
    }

    /**
     * Get a single product by ID.
     *
     * @param int $id The Odoo record ID
     * @return Product|null Returns null if not found
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function get(int $id): ?Product
    {
        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->read($this->model, [$id]);
        return !empty($records) ? new Product($records[0]) : null;
    }

    /**
     * Search products by name or internal reference using a case-insensitive LIKE search.
     *
     * @param string $query The search term
     * @return Product[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function search(string $query, int|QueryOptions|null $options = null): array
    {
        $domain = ['|', ['name', 'ilike', $query], ['default_code', 'ilike', $query]];
        return $this->list($domain, $options);
    }

    /**
     * Get products belonging to a specific category by its name.
     *
     * Performs a lookup on `product.category` first.
     *
     * @param string $categoryName The exact name of the category
     * @return Product[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function getByCategory(string $categoryName, int|QueryOptions|null $options = null): array
    {
        $options = QueryOptions::from($options);
        // First find the category ID
        /** @var array<int, array<string, mixed>> $categories */
        $categories = $this->client->searchRead(
            'product.category',
            [['name', '=', $categoryName]],
            ['id'],
            1,
            0,
            'id asc',
            $options->context,
        );
        if (empty($categories) || !isset($categories[0]['id']) || !is_numeric($categories[0]['id'])) {
            return [];
        }

        $categoryId = (int)$categories[0]['id'];
        return $this->list([['categ_id', '=', $categoryId]], $options);
    }

    /**
     * List available taxes in Odoo.
     *
     * @param string $type The tax type ('sale' or 'purchase')
     * @return Tax[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function listTaxes(string $type = 'sale', int|QueryOptions|null $options = null): array
    {
        $options = QueryOptions::from($options);
        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->searchRead(
            'account.tax',
            [['type_tax_use', '=', $type]],
            $this->queryFields('account.tax', $options),
            $options->searchReadLimit(),
            $options->offset,
            $options->order,
            $options->context,
        );
        return array_map(fn(array $data) => new Tax($data), $records);
    }

    /** @return \Generator<int, Tax> */
    public function iterateTaxes(string $type = 'sale', ?QueryOptions $options = null): \Generator
    {
        $options ??= new QueryOptions();
        foreach ($this->client->iterateSearchRead(
            'account.tax',
            [['type_tax_use', '=', $type]],
            $this->queryFields('account.tax', $options),
            $options,
        ) as $record) {
            yield new Tax($record);
        }
    }
}
