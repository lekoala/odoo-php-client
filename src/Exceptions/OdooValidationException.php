<?php

namespace LeKoala\OdooClient\Exceptions;

use LeKoala\OdooClient\OdooException;

/**
 * Thrown when Odoo JSON-2 returns an application-level error (e.g., validation error, missing field).
 */
class OdooValidationException extends OdooException {}
