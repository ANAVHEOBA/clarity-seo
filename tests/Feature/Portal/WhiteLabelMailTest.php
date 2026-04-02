<?php

declare(strict_types=1);

use App\Mail\ReportReady;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;

it('brands report emails with the tenant sender name and reply-to', function () {
    $tenant = Tenant::factory()->create([
        'name' => 'Agency Workspace',
        'brand_name' => 'Agency Portal',
        'support_email' => 'support@agency.test',
        'reply_to_email' => 'hello@agency.test',
    ]);

    $user = User::factory()->create();

    $report = Report::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'type' => 'reviews',
        'format' => 'csv',
    ]);

    $mailable = new ReportReady($report->fresh());
    $envelope = $mailable->envelope();

    expect($envelope->from?->name)->toBe('Agency Portal');
    expect($envelope->from?->address)->toBe(config('mail.from.address'));
    expect($envelope->replyTo[0]->address)->toBe('hello@agency.test');
    expect($envelope->replyTo[0]->name)->toBe('Agency Portal');
});
