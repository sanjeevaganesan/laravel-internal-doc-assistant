<?php

/*
|--------------------------------------------------------------------------
| Pest Configuration
|--------------------------------------------------------------------------
|
| Pest is a PHP testing framework that provides an expressive, minimal
| API for writing tests. This file configures global test settings,
| dataset factories, and test lifecycle hooks used across all tests.
|
| Docs: https://pestphp.com
|
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Bind the TestCase base class to all Pest tests in the Feature and Unit
 * directories. This gives every test access to Laravel's testing helpers
 * (e.g. $this->get(), $this->postJson(), app(), etc.) while still using
 * Pest's function-based syntax.
 */
uses(TestCase::class)->in('Feature', 'Unit');

/*
 * Refresh the database between tests so each test starts with a clean
 * slate. This wraps each test in a transaction that is rolled back after
 * the test completes — fast and side-effect free.
 */
uses(RefreshDatabase::class)->in('Feature');
