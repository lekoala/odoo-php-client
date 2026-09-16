<?php

namespace LeKoala\OdooClient;

interface OdooApiKeyProvider
{
    public function getApiKey(): string;
}
