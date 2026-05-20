<?php

namespace App\Console\Commands;

use App\Services\PhilSmsService;
use Illuminate\Console\Command;

class TestSms extends Command
{
    protected $signature   = 'sms:test {phone : Philippine mobile number to send to}
                                       {--message= : Custom message (optional)}';

    protected $description = 'Send a test SMS via PhilSMS to verify the integration';

    public function handle(PhilSmsService $sms): int
    {
        $phone   = $this->argument('phone');
        $message = $this->option('message') ?? 'CCDI Portal SMS test. If you received this, integration is working. -CCDI';

        $this->info("Sending SMS to: {$phone}");
        $this->line("Message: {$message}");
        $this->newLine();

        $result = $sms->send($phone, $message);

        if ($result) {
            $this->info('✓ SMS sent successfully.');
            return self::SUCCESS;
        }

        $this->error('✗ SMS failed. Check storage/logs/laravel.log for details.');
        return self::FAILURE;
    }
}