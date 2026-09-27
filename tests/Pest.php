<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
 * The device id the tests write ledger rows under.
 *
 * Declared here rather than per file: a top-level const in two test files is a
 * redeclaration fatal, because Pest loads them into one process.
 */
const DEVICE = 'PC1';
