<?php

namespace App\Exceptions;

/** A sale was invalid: an empty cart, a bad discount, a zero quantity. */
class SaleException extends DomainRuleException {}
