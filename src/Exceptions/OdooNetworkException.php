<?php

namespace LeKoala\OdooClient\Exceptions;

use LeKoala\OdooClient\OdooException;

/**
 * Thrown when the Odoo HTTP connection fails outside an application-level JSON-2 response.
 * Useful for catching transient timeout or 503 errors.
 */
class OdooNetworkException extends OdooException {}
