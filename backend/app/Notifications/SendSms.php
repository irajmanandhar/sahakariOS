<?php

namespace App\Notifications;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Delivers pending outbox messages. Driver from config('services.sms.driver'):
 * - "log" (default, local): writes to the application log, marks sent;
 * - anything else: fails until a real provider is integrated (none chosen yet).
 * Up to MAX_ATTEMPTS tries per message.
 */
class SendSms extends Command
{
    public const MAX_ATTEMPTS = 5;

    protected $signature = 'sms:send {--limit=100}';

    protected $description = 'Send pending member SMS from the outbox';

    public function handle(): int
    {
        $sent = $failed = 0;
        $driver = config('services.sms.driver', 'log');

        DB::transaction(function () use ($driver, &$sent, &$failed) {
            // SKIP LOCKED: two senders never pick the same message.
            $messages = DB::select('SELECT id, phone, body, attempts FROM sms_messages WHERE status = ? AND attempts < ? ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED',
                ['pending', self::MAX_ATTEMPTS, (int) $this->option('limit')]);
            foreach ($messages as $m) {
                try {
                    match ($driver) {
                        'log' => Log::info('SMS (log driver)', ['to' => substr($m->phone, 0, 3).'*****'.substr($m->phone, -2), 'body' => $m->body]),
                        default => throw new RuntimeException("SMS driver '{$driver}' is not implemented."),
                    };
                    DB::table('sms_messages')->where('id', $m->id)->update(['status' => 'sent', 'sent_at' => now(), 'attempts' => $m->attempts + 1, 'updated_at' => now()]);
                    $sent++;
                } catch (\Throwable $e) {
                    $attempts = $m->attempts + 1;
                    DB::table('sms_messages')->where('id', $m->id)->update([
                        'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending', 'attempts' => $attempts,
                        'last_error' => mb_substr($e->getMessage(), 0, 300), 'updated_at' => now(),
                    ]);
                    $failed++;
                }
            }
        });

        $this->info("Sent {$sent}, failed {$failed}.");

        return self::SUCCESS;
    }
}
