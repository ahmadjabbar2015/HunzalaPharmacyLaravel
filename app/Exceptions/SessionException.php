<?php

namespace App\Exceptions;

use RuntimeException;

/*
 * A drawer-session lifecycle rule was violated.
 *
 * The one domain exception that is NOT a DomainRuleException: these are state
 * errors ("nothing is open", "one is already open"), not bad input, and the
 * caller cannot fix them by correcting a field. Ported from SessionError, which
 * subclasses RuntimeError rather than ValueError for the same reason.
 */
class SessionException extends RuntimeException {}
