<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\Cooperative;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Settings → Import: runs migration:import (members) or migration:import-history
 * (backdated passbook rows) on uploaded CSVs for the signed-in cooperative.
 * Uploaded files hold member data: they are deleted as soon as the run ends.
 */
class ImportController
{
    private const COMMANDS = ['members' => 'migration:import', 'history' => 'migration:import-history'];

    public function run(Request $request, string $kind, TenantContext $tenant, AuditLogger $audit): JsonResponse
    {
        abort_unless(isset(self::COMMANDS[$kind]), 404);
        $file = ['required', 'file', 'mimes:csv,txt', 'max:51200'];
        $v = $request->validate(['file' => $file, 'expected' => $kind === 'history' ? ['nullable', ...array_slice($file, 1)] : ['prohibited'], 'dry_run' => ['required', 'boolean']]);
        $dryRun = (bool) $v['dry_run'];
        $coop = Cooperative::query()->findOrFail($tenant->cooperativeId());

        // ponytail: runs inside the request (history replays day by day, rows held in memory); queue it if imports outgrow 1 GB or the 15-minute proxy timeouts
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        $dir = 'imports/'.Str::uuid();
        $disk = Storage::disk('local');
        foreach ($disk->directories('imports') as $old) { // left behind by a run that crashed (e.g. out of memory)
            if ($disk->lastModified($old) < now()->subHour()->getTimestamp()) {
                $disk->deleteDirectory($old);
            }
        }
        try {
            $args = ['cooperative' => $coop->code, 'file' => $disk->path($request->file('file')->storeAs($dir, 'file.csv', 'local')), '--dry-run' => $dryRun];
            if ($request->hasFile('expected')) {
                $args['--expected'] = $disk->path($request->file('expected')->storeAs($dir, 'expected.csv', 'local'));
            }
            $ok = Artisan::call(self::COMMANDS[$kind], $args) === 0;
            $output = Artisan::output();
        } finally {
            $disk->deleteDirectory($dir);
        }
        $audit->record("migration.{$kind}_".($dryRun ? 'checked' : 'imported'), $coop, ['ok' => $ok, 'file' => mb_substr($request->file('file')->getClientOriginalName(), 0, 100)], $request->user());

        return response()->json(['data' => [
            'ok' => $ok, 'dry_run' => $dryRun,
            'lines' => array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($l) => $l !== '')),
        ]]); // 200 either way: the lines say what to fix
    }
}
