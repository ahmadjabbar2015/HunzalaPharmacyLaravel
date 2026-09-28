<?php

namespace App\Exceptions;

/** An invalid return: no items, a line not on the sale, or over the cap. */
class ReturnException extends DomainRuleException {}
