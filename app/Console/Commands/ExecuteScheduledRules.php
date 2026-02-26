<?php

namespace App\Console\Commands;

use App\Models\Rule;
use App\Services\Engine\ExecutionEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExecuteScheduledRules extends Command
{
    protected $signature   = 'atlas:run-rules';
    protected $description = 'Execute all scheduled rules that are due to run';

    public function handle(ExecutionEngine $engine): void
    {
        $now     = now();
        $dayOfMonth  = (int) $now->format('j');
        $dayOfWeek   = (int) $now->format('N');
        $currentTime = $now->format('H:i');
        $minute      = (int) $now->format('i');
        $hour        = (int) $now->format('G');

        $rules = Rule::where('is_active', true)
            ->where('trigger_type', 'schedule')
            ->with(['actions', 'connectedAccount', 'user'])
            ->get();

        $this->info("Checking {$rules->count()} scheduled rules at {$now->toTimeString()}");

        foreach ($rules as $rule) {
            $config    = $rule->trigger_config ?? [];
            $frequency = $config['frequency'] ?? 'manual';
            $due       = $this->isDue($rule, $dayOfMonth, $dayOfWeek, $currentTime, $minute, $hour);
            $this->info("Rule: {$rule->name} | frequency: {$frequency} | config: " . json_encode($config) . " | due: " . ($due ? 'YES' : 'NO'));

            try {
                if (!$due) continue;
                $this->info("Executing: {$rule->name}");
                $engine->execute($rule);
                $this->info("  ✓ Completed");
            } catch (\Throwable $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
            }
        }
    }

    private function isDue(Rule $rule, int $dayOfMonth, int $dayOfWeek, string $currentTime, int $minute, int $hour): bool
    {
        $config    = $rule->trigger_config ?? [];
        $frequency = $config['frequency'] ?? 'manual';

        return match ($frequency) {
            'interval' => $this->checkInterval($rule, $config),
            'hourly'   => $minute === 0,
            'daily'    => ($config['time'] ?? '09:00') === $currentTime,
            'weekly'   => ($config['day_of_week'] ?? 1) === $dayOfWeek && ($config['time'] ?? '09:00') === $currentTime,
            'monthly'  => ($config['day'] ?? 1) === $dayOfMonth && ($config['time'] ?? '09:00') === $currentTime,
            default    => false,
        };
    }

    private function checkInterval(Rule $rule, array $config): bool
    {
        $intervalMinutes = (int) ($config['interval_minutes'] ?? 1);

        $lastRun = $rule->executions()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->value('completed_at');

        if (!$lastRun) return true;

        // Force Carbon instance and use absolute diff
        $lastRunCarbon = \Carbon\Carbon::parse($lastRun);
        $minutesSinceLast = (int) now()->diffInMinutes($lastRunCarbon, true);

        \Illuminate\Support\Facades\Log::info("Atlas: rule {$rule->name} — last run {$minutesSinceLast} min ago, interval {$intervalMinutes} min");

        return $minutesSinceLast >= $intervalMinutes;
    }
}
