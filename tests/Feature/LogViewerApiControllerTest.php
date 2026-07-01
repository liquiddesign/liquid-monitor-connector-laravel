<?php

declare(strict_types=1);

beforeEach(function () {
    $this->logDir = config('liquid-monitor.log_viewer.log_dir');
    @mkdir($this->logDir, 0777, true);
    \file_put_contents($this->logDir.'/app.log', "line1\nline2\nERROR something bad happened\nline4\n");
    \file_put_contents($this->logDir.'/dump.html', '<html><body>dump</body></html>');
});

afterEach(function () {
    foreach (\glob($this->logDir.'/*') ?: [] as $file) {
        @\unlink($file);
    }
    @\rmdir($this->logDir);
});

it('rejects requests without a valid token', function () {
    $this->getJson('/liquid-monitor/log-viewer/api/list')
        ->assertStatus(403)
        ->assertJson(['error' => 'Invalid API key', 'code' => 403]);
});

it('lists files in the log directory', function () {
    $response = $this->getJson('/liquid-monitor/log-viewer/api/list', ['X-Api-Key' => 'secret-token'])
        ->assertOk()
        ->json();

    $names = \array_column($response['items'], 'name');

    expect($names)->toContain('app.log', 'dump.html');
    expect($response['path'])->toBe('');
});

it('returns file metadata via stat', function () {
    $this->getJson('/liquid-monitor/log-viewer/api/stat?file=app.log', ['X-Api-Key' => 'secret-token'])
        ->assertOk()
        ->assertJson([
            'file' => 'app.log',
            'type' => 'log',
            'isHtml' => false,
        ]);
});

it('returns paginated content via view', function () {
    $this->getJson('/liquid-monitor/log-viewer/api/view?file=app.log&page=1', ['X-Api-Key' => 'secret-token'])
        ->assertOk()
        ->assertJsonFragment(['isHtml' => false])
        ->assertJsonPath('content', "line1\nline2\nERROR something bad happened\nline4\n");
});

it('loads a whole HTML file via view', function () {
    $this->getJson('/liquid-monitor/log-viewer/api/view?file=dump.html', ['X-Api-Key' => 'secret-token'])
        ->assertOk()
        ->assertJson([
            'isHtml' => true,
            'truncated' => false,
            'content' => '<html><body>dump</body></html>',
        ]);
});

it('finds a match with context via search', function () {
    $this->getJson('/liquid-monitor/log-viewer/api/search?file=app.log&q=ERROR&context=2', ['X-Api-Key' => 'secret-token'])
        ->assertOk()
        ->assertJson([
            'found' => true,
            'lineNumber' => 3,
        ])
        ->assertJsonFragment(['content' => "line2\nERROR something bad happened\nline4\n"]);
});

it('reports no match found via search', function () {
    $this->getJson('/liquid-monitor/log-viewer/api/search?file=app.log&q=nope', ['X-Api-Key' => 'secret-token'])
        ->assertOk()
        ->assertJson(['found' => false, 'lineNumber' => null, 'content' => '']);
});

it('rejects search on HTML files', function () {
    $this->getJson('/liquid-monitor/log-viewer/api/search?file=dump.html&q=dump', ['X-Api-Key' => 'secret-token'])
        ->assertStatus(400)
        ->assertJson(['error' => 'Search is not supported on HTML files', 'code' => 400]);
});

it('downloads the raw file', function () {
    $response = $this->get('/liquid-monitor/log-viewer/api/download?file=app.log', ['X-Api-Key' => 'secret-token']);

    $response->assertOk();
    expect($response->streamedContent())->toBe("line1\nline2\nERROR something bad happened\nline4\n");
});

it('rejects a missing file parameter', function () {
    $this->getJson('/liquid-monitor/log-viewer/api/stat', ['X-Api-Key' => 'secret-token'])
        ->assertStatus(400)
        ->assertJson(['error' => 'Missing required parameter "file".', 'code' => 400]);
});

it('rejects path traversal attempts', function () {
    $this->getJson('/liquid-monitor/log-viewer/api/view?file=../../../../etc/passwd', ['X-Api-Key' => 'secret-token'])
        ->assertStatus(400)
        ->assertJson(['error' => 'Invalid file path', 'code' => 400]);
});
