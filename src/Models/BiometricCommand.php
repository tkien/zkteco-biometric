<?php

namespace AhidTechnologies\ZKTecoBiometric\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class BiometricCommand extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'type',
        'device_serial_number',
        'command_id',
        'command',
        'employee_id',
        'user_id',
        'status',
        'sent_at',
        'executed_at',
        'failed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sent_at' => 'datetime',
        'executed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('zkteco-biometric.database.tables.commands', 'biometric_commands');
    }

    /**
     * Get the device associated with this command.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_serial_number', 'serial_number');
    }

    /**
     * Get the user associated with this command.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'), 'user_id');
    }

    /**
     * Scope for pending commands.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for sent commands.
     */
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    /**
     * Scope for executed commands.
     */
    public function scopeExecuted($query)
    {
        return $query->where('status', 'executed');
    }

    /**
     * Scope for failed commands.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Create a user command for the device.
     */
    public static function createUserCommand(string $commandId, string $pin, string $name): string
    {
        // $commandId = "CREATEUSER-{$commandId}";
        return "C:$commandId:DATA USER PIN=$pin\tName=$name\n";
    }

    /**
     * Create a query user command for the device.
     */
    public static function queryUserCommand(string $commandId, string $pin): string
    {
        // $commandId = "QUERYUSER-{$commandId}";
        return "C:{$commandId}:DATA QUERY USERINFO PIN={$pin}\n";
    }

    /**
     * Create a delete user command for the device.
     */
    public static function deleteUserCommand(string $commandId, string $pin): string
    {
        // $commandId = "DELETEUSER-{$commandId}";
        return "C:$commandId:DATA DELETE USERINFO PIN=$pin\n";
    }

    /**
     * Create a query BIODATA command for the device.
     */
    public static function queryBioDataCommand(string $commandId, string $pin, int $bioType): string
    {
        // $commandId = "BIODATA-{$commandId}";
        return "C:$commandId:DATA QUERY BIODATA PIN=$pin\tTYPE=$bioType\n";
    }

    /**
     * Mark command as executed.
     */
    public static function commandExecuted(?self $pendingCommand, BiometricDevice $device): void
    {
        if (!$pendingCommand) {
            return;
        }

        // Check if this is a user creation command and extract the PIN
        if (strpos($pendingCommand->command_id, 'CREATEUSER') !== false) {
            $employeeId = $pendingCommand->employee_id;

            $biometricEmployee = BiometricEmployee::where('biometric_employee_id', $employeeId)->first();

            if (!$biometricEmployee && config('zkteco-biometric.attendance.auto_create_users', true)) {
                BiometricEmployee::create([
                    'biometric_employee_id' => $employeeId,
                    'user_id' => $pendingCommand->user_id,
                ]);
            }
        }

        $pendingCommand->update([
            'status' => 'executed',
            'executed_at' => now(),
        ]);
    }

    /**
     * Mark command as failed.
     */
    public static function commandFailed(?self $pendingCommand): void
    {
        if (!$pendingCommand) {
            return;
        }

        $pendingCommand->update([
            'status' => 'failed',
            'failed_at' => now(),
        ]);
    }

    /**
     * Convert timezone string to minutes offset.
     *
     * Examples:
     * - Asia/Kolkata (UTC+5:30) returns 330 (5*60 + 30)
     * - America/New_York (UTC-5:00) returns -300 (-5*60)
     *
     * @param string $timezone Timezone identifier (e.g. 'Asia/Kolkata')
     * @return int Timezone offset in minutes
     */
    public static function timezoneToMinutes(string $timezone): int
    {
        try {
            $dateTimeZone = new \DateTimeZone($timezone);
            $dateTime = new \DateTime('now', $dateTimeZone);
            $offset = $dateTimeZone->getOffset($dateTime) / 60; // Convert seconds to minutes

            return (int) $offset;
        } catch (\Exception $e) {
            Log::error('Error converting timezone to minutes', [
                'timezone' => $timezone,
                'error' => $e->getMessage(),
            ]);

            return 0; // Default to UTC if there's an error
        }
    }

    /**
     * Mark command as sent.
     */
    public function markAsSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Check if command is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if command was executed.
     */
    public function isExecuted(): bool
    {
        return $this->status === 'executed';
    }

    /**
     * Check if command failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
