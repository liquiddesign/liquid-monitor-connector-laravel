<?php

declare(strict_types=1);

use LiquidMonitorConnector\DbQuery\ReadOnlyQueryRunner;

// These cover the pre-connect guard, which runs before any PDO connection is attempted
// (and is therefore testable without a live MySQL/Postgres instance). A full round trip
// against a real database is exercised manually / in the host app's own test suite.

it('rejects an empty query', function () {
    $runner = new ReadOnlyQueryRunner(['driver' => 'mysql', 'host' => 'db', 'database' => 'app', 'username' => 'u']);

    expect(fn () => $runner->run('   '))
        ->toThrow(InvalidArgumentException::class, 'Empty query.');
});

it('rejects non-SELECT/WITH statements', function () {
    $runner = new ReadOnlyQueryRunner(['driver' => 'mysql', 'host' => 'db', 'database' => 'app', 'username' => 'u']);

    expect(fn () => $runner->run('UPDATE users SET name = "x"'))
        ->toThrow(InvalidArgumentException::class, 'Only SELECT or WITH queries are allowed.');
});

it('rejects multiple statements', function () {
    $runner = new ReadOnlyQueryRunner(['driver' => 'mysql', 'host' => 'db', 'database' => 'app', 'username' => 'u']);

    expect(fn () => $runner->run('SELECT 1; SELECT 2'))
        ->toThrow(InvalidArgumentException::class, 'Multiple statements are not allowed.');
});

it('rejects forbidden keywords even inside a SELECT', function () {
    $runner = new ReadOnlyQueryRunner(['driver' => 'mysql', 'host' => 'db', 'database' => 'app', 'username' => 'u']);

    expect(fn () => $runner->run('SELECT * FROM users WHERE id IN (SELECT id FROM logs) OR 1=1; DROP TABLE users'))
        ->toThrow(InvalidArgumentException::class);
});

it('allows a WITH statement to pass the read-only guard', function () {
    $runner = new ReadOnlyQueryRunner(['driver' => 'unsupported-driver', 'host' => 'db', 'database' => 'app', 'username' => 'u']);

    // Guard passes; it only fails later when actually trying to connect via the
    // (deliberately) unsupported driver — proving the WITH clause itself isn't rejected.
    expect(fn () => $runner->run('WITH t AS (SELECT 1) SELECT * FROM t'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported database driver.');
});

it('strips comments before evaluating forbidden keywords', function () {
    $runner = new ReadOnlyQueryRunner(['driver' => 'unsupported-driver', 'host' => 'db', 'database' => 'app', 'username' => 'u']);

    // A "DROP" mentioned only inside a comment must not trip the keyword guard —
    // it should reach the (intentionally unsupported) driver check instead.
    expect(fn () => $runner->run("SELECT 1 -- DROP TABLE users\n"))
        ->toThrow(InvalidArgumentException::class, 'Unsupported database driver.');
});
