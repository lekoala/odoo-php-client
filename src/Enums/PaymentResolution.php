<?php

namespace LeKoala\OdooClient\Enums;

enum PaymentResolution: string
{
    case Explicit = 'explicit';
    case Reconciled = 'reconciled';
    case Ambiguous = 'ambiguous';
}
