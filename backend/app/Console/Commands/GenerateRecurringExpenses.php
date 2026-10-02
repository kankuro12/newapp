<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\NepaliDate;
use App\Service\RecurringExpenseService;
use App\Support\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class GenerateRecurringExpenses extends Command
{
    protected $signature = 'app:generate-recurring-expenses {--through= : BS date, default today}';

    protected $description = 'Record due monthly expenses and payables without moving cash';

    public function handle(RecurringExpenseService $service, CurrentTenant $context): int
    {
        try {
            $through = NepaliDate::normalize($this->option('through') ?: NepaliDate::today());
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($through > NepaliDate::today()) {
            $this->error('Future accrual unavailable.');

            return self::FAILURE;
        }
        $failed = false;
        $count = 0;
        DB::table('recurring_expenses')->where('enabled', true)->where('auto_generate', true)->where('next_date_bs', '<=', $through)->orderBy('id')->chunkById(100, function ($rules) use ($service, $context, $through, &$failed, &$count) {
            foreach ($rules as $rule) {
                try {
                    $context->set(Tenant::findOrFail($rule->tenant_id));
                    // ponytail: catch up twelve months per rule per run; later runs continue large backlogs.
                    for ($i = 0; $i < 12 && $service->generateDue((int) $rule->id, $through); $i++) {
                        $count++;
                    }
                } catch (\Throwable $e) {
                    $failed = true;
                    $message = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : ($e instanceof HttpExceptionInterface ? 'Automatic action blocked. Check setup author membership, business access and party/category.' : 'Automatic action failed. Administrator can inspect application logs.');
                    if (! $e instanceof ValidationException && ! $e instanceof HttpExceptionInterface) {
                        report($e);
                    }
                    DB::table('recurring_expenses')->where('tenant_id', $rule->tenant_id)->where('id', $rule->id)->update(['last_error' => $message, 'updated_at' => now()]);
                    $this->warn('Setup '.$rule->id.': '.$message);
                } finally {
                    $context->clear();
                }
            }
        });
        $this->info($count.' monthly expenses recorded.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
