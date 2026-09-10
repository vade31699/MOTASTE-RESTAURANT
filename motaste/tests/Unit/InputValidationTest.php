<?php

/**
 * Tests for the stored-XSS input guard (inputContainsUnsafeHtml /
 * rejectUnsafeInputOrExit in public/api/_helpers.php). Boots the app on the
 * in-memory testing database like the other helper tests.
 */
function bootInputValidationTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['APP_ENV'] = 'testing';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_DATABASE'] = ':memory:';

    $app = require __DIR__ . '/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    require_once __DIR__ . '/../../public/api/_helpers.php';
}

// Boot before the first test runs, not inside it (same pattern as the other
// helper tests) so the global error-handler accounting is not attributed to a
// specific test.
beforeAll(function () {
    bootInputValidationTestApp();
});

test('plain text input is accepted', function () {
    expect(inputContainsUnsafeHtml('Pork Sisig with Egg'))->toBeFalse();
    expect(inputContainsUnsafeHtml('Salted egg, tomato, onion, chili. Serves 2.'))->toBeFalse();
    expect(inputContainsUnsafeHtml(''))->toBeFalse();
    expect(inputContainsUnsafeHtml('Price: P120 — serves 2, less than 500 calories'))->toBeFalse();
    expect(inputContainsUnsafeHtml(120))->toBeFalse();
    expect(inputContainsUnsafeHtml(null))->toBeFalse();
});

test('script tags are rejected', function () {
    expect(inputContainsUnsafeHtml('<script>alert(1)</script>'))->toBeTrue();
    // The exact malformed variant from the bug report.
    expect(inputContainsUnsafeHtml('<script>alert<script>'))->toBeTrue();
    expect(inputContainsUnsafeHtml('dish <SCRIPT>alert(1)</SCRIPT> name'))->toBeTrue();
    expect(inputContainsUnsafeHtml('<script>'))->toBeTrue();
});

test('other HTML tags and event handlers are rejected', function () {
    expect(inputContainsUnsafeHtml('<img src=x onerror=alert(1)>'))->toBeTrue();
    expect(inputContainsUnsafeHtml('<iframe src="https://evil.example"></iframe>'))->toBeTrue();
    expect(inputContainsUnsafeHtml('<b>bold</b>'))->toBeTrue();
    expect(inputContainsUnsafeHtml('text onclick="alert(1)" more'))->toBeTrue();
    expect(inputContainsUnsafeHtml('onerror=alert(1)'))->toBeTrue();
});

test('executable URL schemes are rejected', function () {
    expect(inputContainsUnsafeHtml('javascript:alert(1)'))->toBeTrue();
    expect(inputContainsUnsafeHtml('<a href="javascript:alert(1)">click</a>'))->toBeTrue();
    expect(inputContainsUnsafeHtml('vbscript:msgbox(1)'))->toBeTrue();
    expect(inputContainsUnsafeHtml('data:text/html,<script>alert(1)</script>'))->toBeTrue();
});

test('legitimate image data URIs are allowed', function () {
    expect(inputContainsUnsafeHtml('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))->toBeFalse();
    expect(inputContainsUnsafeHtml('data:image/jpeg;base64,/9j/4AAQSkZJRg=='))->toBeFalse();
});

test('nested payloads are checked recursively', function () {
    $cleanMenu = [
        ['name' => 'Pork Sisig', 'description' => 'Crispy pork, onions, chili', 'price' => 120],
        ['name' => 'Bibingka', 'description' => 'Sweet rice cake', 'price' => 80],
    ];
    expect(inputContainsUnsafeHtml($cleanMenu))->toBeFalse();

    $injectedMenu = [
        ['name' => 'Pork Sisig', 'description' => 'Crispy pork', 'price' => 120],
        ['name' => 'Bibingka <script>alert(1)</script>', 'description' => 'Sweet rice cake', 'price' => 80],
    ];
    expect(inputContainsUnsafeHtml($injectedMenu))->toBeTrue();

    $cleanInventory = ['name' => 'Rice', 'description' => 'Steamed white rice', 'stock' => 50];
    expect(inputContainsUnsafeHtml($cleanInventory))->toBeFalse();

    $injectedInventory = ['name' => 'Rice', 'description' => 'Steamed <img src=x onerror=alert(1)> rice'];
    expect(inputContainsUnsafeHtml($injectedInventory))->toBeTrue();
});