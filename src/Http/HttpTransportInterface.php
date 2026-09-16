<?php

namespace LeKoala\OdooClient\Http;

interface HttpTransportInterface
{
    /**
     * Send exactly one HTTP request without following redirects.
     */
    public function send(HttpRequest $request): HttpResponse;
}
