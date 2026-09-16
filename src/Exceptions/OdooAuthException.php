<?php

namespace LeKoala\OdooClient\Exceptions;

use LeKoala\OdooClient\OdooException;

/**
 * Thrown when Odoo authentication fails (for example, an invalid database or API key).
 */
class OdooAuthException extends OdooException {}
