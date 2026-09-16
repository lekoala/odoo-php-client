<?php

namespace LeKoala\OdooClient\Enums;

/**
 * Common Odoo Models used in the library.
 * 
 * This enum provides type safety for the $model parameter in Client methods.
 */
enum OdooModel: string
{
    case ACCOUNT_MOVE = 'account.move';
    case RES_PARTNER = 'res.partner';
    case RES_COUNTRY = 'res.country';
    case PAYMENT_TOKEN = 'payment.token';
    case ACCOUNT_PAYMENT_REGISTER = 'account.payment.register';
    case ACCOUNT_JOURNAL = 'account.journal';
    case ACCOUNT_PAYMENT_METHOD_LINE = 'account.payment.method.line';
    case PAYMENT_PROVIDER = 'payment.provider';
    case PRODUCT_PRODUCT = 'product.product';
    case PRODUCT_TEMPLATE = 'product.template';
    case PRODUCT_CATEGORY = 'product.category';
    case ACCOUNT_TAX = 'account.tax';
    case SALE_ORDER = 'sale.order';
    case SALE_SUBSCRIPTION_PLAN = 'sale.subscription.plan';
    case IR_ACTIONS_REPORT = 'ir.actions.report';

    /**
     * Get the string value of the model.
     */
    public function value(): string
    {
        return $this->value;
    }
}
