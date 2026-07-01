<?php

declare(strict_types=1);

it('rejects requests without a valid token', function () {
    $this->postJson('/liquid-monitor/db-query/api/query', ['sql' => 'SELECT 1'])
        ->assertStatus(403)
        ->assertJson(['error' => 'Invalid API key', 'code' => 403]);
});

it('rejects a missing sql field', function () {
    $this->postJson('/liquid-monitor/db-query/api/query', [
        'connection' => ['host' => 'h', 'database' => 'd', 'username' => 'u', 'driver' => 'mysql'],
    ], ['X-Api-Key' => 'secret-token'])
        ->assertStatus(422)
        ->assertJson(['error' => 'Missing or empty "sql" field.', 'code' => 422]);
});

it('rejects a missing connection object', function () {
    $this->postJson('/liquid-monitor/db-query/api/query', ['sql' => 'SELECT 1'], [
        'X-Api-Key' => 'secret-token',
    ])->assertStatus(422)->assertJson(['error' => 'Missing "connection" object.', 'code' => 422]);
});

it('rejects a connection object missing required fields', function () {
    $this->postJson('/liquid-monitor/db-query/api/query', [
        'sql' => 'SELECT 1',
        'connection' => ['host' => 'h'],
    ], ['X-Api-Key' => 'secret-token'])
        ->assertStatus(422)
        ->assertJson(['error' => 'Missing required connection field: database.', 'code' => 422]);
});

it('rejects a non-SELECT query before ever attempting to connect', function () {
    $this->postJson('/liquid-monitor/db-query/api/query', [
        'sql' => 'DELETE FROM users',
        'connection' => ['host' => 'h', 'database' => 'd', 'username' => 'u', 'driver' => 'mysql'],
    ], ['X-Api-Key' => 'secret-token'])
        ->assertStatus(422)
        ->assertJson(['error' => 'Only SELECT or WITH queries are allowed.', 'code' => 422]);
});
