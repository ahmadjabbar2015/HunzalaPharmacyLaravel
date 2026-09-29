<?php

namespace App\Exceptions;

/*
 * A report was asked for something that does not exist - a session uuid that
 * was never opened, a date range that runs backwards.
 *
 * Reports never write, so this is the only way one can fail on purpose.
 */
class ReportException extends DomainRuleException {}
