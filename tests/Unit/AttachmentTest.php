<?php

use App\Models\Attachment;

test('isImage returns true for image mime types', function () {
    $attachment = new Attachment(['mime_type' => 'image/jpeg']);
    expect($attachment->isImage())->toBeTrue();

    $attachment->mime_type = 'image/png';
    expect($attachment->isImage())->toBeTrue();
});

test('isImage returns false for non-image mime types', function () {
    $attachment = new Attachment(['mime_type' => 'application/pdf']);
    expect($attachment->isImage())->toBeFalse();

    $attachment->mime_type = 'application/zip';
    expect($attachment->isImage())->toBeFalse();
});

test('human size returns bytes for small files', function () {
    $attachment = new Attachment(['size' => 512]);
    expect($attachment->human_size)->toBe('512 B');
});

test('human size returns KB for medium files', function () {
    $attachment = new Attachment(['size' => 2048]);
    expect($attachment->human_size)->toBe('2 KB');
});

test('human size returns MB for large files', function () {
    $attachment = new Attachment(['size' => 1024 * 1024 * 3]);
    expect($attachment->human_size)->toBe('3 MB');
});
