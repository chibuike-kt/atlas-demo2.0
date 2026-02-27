<?php

namespace App\Console\Commands;

use App\Models\Rule;
use App\Services\Engine\ExecutionEngine;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExecuteScheduledRules extends Command
{
    protected $signature   = 'atlas:run-rules';
    protected $description = 'Execute all scheduled rules that are due to run';

    public function handle(ExecutionEngine $engine): void
    {
        // Use app timezone consistently for all comparisons
        $now         = now()->setTimezone(config('app.timezone', 'Africa/Lagos'));
        $dayOfMonth  = (int) $now->format('j');
        $dayOfWeek   = (int) $now->format('N'); // 1=Mon, 7=Sun
        $currentTime = $now->format('H:i');
        $minute      = (int) $now->format('i');
        $hour        = (int) $now->format('G');

        $rules = Rule::where('is_active', true)
            ->where('trigger_type', 'schedule')
            ->with(['actions', 'connectedAccount', 'user'])
            ->get();

        $this->info("[Atlas Scheduler] {$now->toDateTimeString()} — checking {$rules->count()} rule(s)");

        foreach ($rules as $rule) {
            $config    = is_array($rule->trigger_config)
                ? $rule->trigger_config
                : json_decode($rule->trigger_config, true) ?? [];
            $frequency = $config['frequency'] ?? 'unknown';
            $due       = $this->isDue($rule, $config, $dayOfMonth, $dayOfWeek, $currentTime, $minute, $hour);

            $this->line("  Rule: [{$rule->name}] frequency={$frequency} due=" . ($due ? 'YES' : 'NO'));

            if (!$due) continue;

            try {
                $this->info("  → Executing: {$rule->name}");
                $engine->execute($rule, 'scheduler');
                $rule->update(['last_triggered_at' => $now]);
                $this->info("  ✓ Completed");
                Log::info("Atlas Scheduler: executed rule {$rule->id} ({$rule->name})");
            } catch (\Throwable $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
                Log::error("Atlas Scheduler: rule {$rule->id} failed — " . $e->getMessage());
            }
        }
    }

    private function isDue(Rule $rule, array $config, int $dayOfMonth, int $dayOfWeek, string $currentTime, int $minute, int $hour): bool
    {
        $frequency = $config['frequency'] ?? 'unknown';

        return match ($frequency) {
            'interval' => $this->checkInterval($rule, $config),
            'hourly'   => $minute === 0,
            'daily'    => ($config['time'] ?? '09:00') === $currentTime,
            'weekly'   => (int)($config['day_of_week'] ?? 1) === $dayOfWeek
                       && ($config['time'] ?? '09:00') === $currentTime,
            'monthly'  => (int)($config['day'] ?? 1) === $dayOfMonth
                       && ($config['time'] ?? '09:00') === $currentTime,
            default    => false,
        };
    }

    private function checkInterval(Rule $rule, array $config): bool
    {
        $intervalMinutes = max(1, (int) ($config['interval_minutes'] ?? 1));

        $lastRun = $rule->executions()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->value('completed_at');

        // No previous run — fire immediately
        if (!$lastRun) return true;

        $lastRunAt        = Carbon::parse($lastRun)->setTimezone(config('app.timezone', 'Africa/Lagos'));
        $minutesSinceLast = (int) now()->diffInMinutes($lastRunAt);

        Log::info("Atlas interval check: [{$rule->name}] last={$lastRunAt} minutes_ago={$minutesSinceLast} interval={$intervalMinutes}");

        return $minutesSinceLast >= $intervalMinutes;
    }
}
