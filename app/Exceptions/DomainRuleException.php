<?php

namespace App\Exceptions;

use InvalidArgumentException;

/*
 * Base for every "the shop's rules say no" failure.
 *
 * Extends InvalidArgumentException, matching the Python services' choice of
 * ValueError: these are bad inputs, not broken infrastructure. The distinction
 * matters at the HTTP edge, where one of these becomes a message next to a form
 * field and anything else becomes a 500.
 *
 * Each service subclasses it so a caller can catch only what it understands -
 * a purchase screen should not silently swallow a sale's rule violation.
 */
class DomainRuleException extends InvalidArgumentException {}
