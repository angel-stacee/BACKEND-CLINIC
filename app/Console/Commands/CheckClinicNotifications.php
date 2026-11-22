<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class CheckClinicNotifications extends Command
{
    protected $signature = 'clinic:check-notifications';
    protected $description = 'Run real-time checks for low medicine stock, expired medicines, patient reminders, and nurse alerts';

    public function handle(NotificationService $notificationService): int
    {
        $this->info('Checking clinic notifications...');
        $notificationService->syncAll();
        $this->info('Clinic notifications synchronized successfully.');
        return self::SUCCESS;
    }
}
