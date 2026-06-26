<?php

namespace AhidTechnologies\ZKTecoBiometric\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

namespace AhidTechnologies\ZKTecoBiometric\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricDevice extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'device_name',
        'serial_number',
        'device_ip',
        'status',
        'last_online',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_online' => 'datetime',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('zkteco-biometric.database.tables.devices', 'biometric_devices');
    }

    /**
     * Get all employees associated with this device.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(BiometricEmployee::class, 'device_serial_number', 'serial_number');
    }

    /**
     * Get all attendance records from this device.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(BiometricAttendance::class, 'device_serial_number', 'serial_number');
    }

    /**
     * Get all commands for this device.
     */
    public function commands(): HasMany
    {
        return $this->hasMany(BiometricCommand::class, 'device_serial_number', 'serial_number');
    }

    /**
     * Scope for online devices.
     */
    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }

    /**
     * Scope for offline devices.
     */
    public function scopeOffline($query)
    {
        return $query->where('status', 'offline');
    }

    /**
     * Check if device is online.
     */
    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    /**
     * Mark device as online.
     */
    public function markOnline(string $ip): void
    {
        $this->update([
            'status' => 'online',
            'device_ip' => $ip ?? $this->device_ip,
            'last_online' => now(),
        ]);
    }

    /**
     * Mark device as offline.
     */
    public function markOffline(): void
    {
        $this->update([
            'status' => 'offline',
        ]);
    }
}
