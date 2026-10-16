<?php

namespace App\Audit;

use App\Models\PlatformUser;
use App\Models\User;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Appends to the tamper-evident audit log.
 *
 * Call it inside the same database transaction as the change it describes,
 * so the change and its audit entry commit or roll back together.
 * Never put passwords, tokens or full card/ID numbers in $data.
 */
class AuditLogger
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private TenantContext $tenant) {}

    /**
     * @param  int|false|null  $cooperativeId  null = current tenant (if any), false = global chain
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $data = [],
        ?Authenticatable $actor = null,
        int|false|null $cooperativeId = null,
    ): void {
        if ($cooperativeId === null) {
            $cooperativeId = $this->tenant->has() ? $this->tenant->cooperativeId() : false;
        }
        $cooperativeId = $cooperativeId === false ? null : $cooperativeId;
        $actor ??= Auth::guard(Auth::getDefaultDriver())->user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        $entry = [
            'chain' => $cooperativeId === null ? 'global' : "cooperative:{$cooperativeId}",
            'cooperative_id' => $cooperativeId,
            'actor_type' => match (true) {
                $actor instanceof User => 'user',
                $actor instanceof PlatformUser => 'platform_user',
                $actor === null => 'system',
                default => throw new InvalidArgumentException('Unknown actor type.'),
            },
            'actor_id' => $actor?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'data' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) ?: null : null,
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ];

        DB::transaction(function () use ($entry) {
            // One writer per chain at a time; the lock is held until the outer transaction commits.
            // ponytail: per-chain lock serialises audit writes of one cooperative; shard chains if it ever bottlenecks.
            DB::insert('INSERT INTO audit_chains (chain, last_sequence, last_hash) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE chain = chain', [$entry['chain'], self::GENESIS_HASH]);
            $head = DB::selectOne('SELECT last_sequence, last_hash FROM audit_chains WHERE chain = ? FOR UPDATE', [$entry['chain']]);

            $entry['sequence'] = $head->last_sequence + 1;
            $entry['prev_hash'] = $head->last_hash;
            $entry['hash'] = self::hash($entry);

            DB::table('audit_logs')->insert($entry);
            DB::update('UPDATE audit_chains SET last_sequence = ?, last_hash = ? WHERE chain = ?', [$entry['sequence'], $entry['hash'], $entry['chain']]);
        });
    }

    /** Hash of one entry, chained to the previous entry's hash. Order of fields is part of the format. */
    public static function hash(array $entry): string
    {
        $fields = [
            $entry['chain'], $entry['sequence'], $entry['cooperative_id'], $entry['actor_type'], $entry['actor_id'],
            $entry['action'], $entry['subject_type'], $entry['subject_id'], $entry['data'],
            $entry['ip_address'], $entry['user_agent'], $entry['occurred_at'], $entry['prev_hash'],
        ];

        return hash('sha256', json_encode(array_map(fn ($v) => $v === null ? null : (string) $v, $fields), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
