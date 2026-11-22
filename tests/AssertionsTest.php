<?php

declare(strict_types=1);

/*
 * The suite relies on assert() (the drivers assert their invariants, as upstream runs its suite with
 * -dzend.assertions=1 -dassert.exception=1). A run with assertions compiled out (zend.assertions=-1, the
 * php.ini-production default) or only switched off would skip those checks silently, so it fails here instead.
 * Run the suite with `composer test`, which sets both.
 */
it('runs with assertions enabled and throwing', function () {
    expect(ini_get('zend.assertions'))->toBe('1', 'zend.assertions must be 1: run the suite with `composer test`')
        ->and(fn () => assert(false))->toThrow(AssertionError::class);
});
