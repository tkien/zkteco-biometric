<?php

namespace AhidTechnologies\ZKTecoBiometric\Models;

use AhidTechnologies\ZKTecoBiometric\Events\BiometricAttendanceRecorded;
use AhidTechnologies\ZKTecoBiometric\Events\BiometricDataReceived;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BiometricEmployee extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'force_biometric_clockin',
        'biometric_employee_id',
        'user_id',
        'card_number',
        'has_fingerprint',
        'fingerprint_id',
        'fingerprint_template',
        'has_photo',
        'photo',
        'clock_in_method',
        'has_face',
        'face_template',
        'face_template_major_ver',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'has_fingerprint' => 'boolean',
        'has_photo' => 'boolean',
        'force_biometric_clockin' => 'boolean',
        'has_face' => 'boolean',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('zkteco-biometric.database.tables.employees', 'biometric_employees');
    }

    /**
     * Get the user associated with this biometric employee.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'), 'user_id');
    }

    /**
     * Get all attendance records for this employee.
     */
    public function attendances()
    {
        return $this->hasMany(BiometricAttendance::class, 'biometric_employee_id', 'biometric_employee_id');
    }

    /**
     * Record fingerprint data for the employee.
     */
    public static function recordFingerprint(array $rows, BiometricDevice $device): void
    {
        foreach ($rows as $row) {
            if (empty($row)) {
                continue;
            }

            $parts = explode("\t", $row);

            if (count($parts) > 0) {
                self::processRow($parts, $device);
            }
        }
    }

    /**
     * Process a single row of biometric data.
     */
    private static function processRow(array $parts, BiometricDevice $device): void
    {
        if (strpos($parts[0], 'FP PIN=') === 0) {
            self::handleFingerprintData($parts, $device);
        } elseif (strpos($parts[0], 'USER PIN=') === 0) {
            self::handleUserData($parts, $device);
        } elseif (strpos($parts[0], 'BIOPHOTO PIN=') === 0) {
            self::handlePhotoData($parts, $device);
        } elseif (strpos($parts[0], 'BIODATA') === 0) {
            self::handleFaceData($parts, $device);
        }
    }

    /**
     * Handle fingerprint data from device.
     */
    private static function handleFingerprintData(array $parts, BiometricDevice $device): void
    {
        $employeeId = str_replace('FP PIN=', '', $parts[0]);
        $fingerprintId = null;
        $template = null;

        foreach ($parts as $part) {
            if (strpos($part, 'FID=') === 0) {
                $fingerprintId = str_replace('FID=', '', $part);
            } elseif (strpos($part, 'TMP=') === 0) {
                $template = str_replace('TMP=', '', $part);
            }
        }

        if ($employeeId && $fingerprintId) {
            self::updateOrCreateBiometricEmployee(
                $employeeId,
                [
                    'has_fingerprint' => true,
                    'fingerprint_id' => $fingerprintId,
                    'fingerprint_template' => $template,
                ]
            );

            // BẮN EVENT RA NGOÀI HỆ THỐNG LARAVEL
            BiometricDataReceived::dispatch($device, $employeeId, 1, $template);
        }
    }

    /**
     * Handle user data from device.
     */
    private static function handleUserData(array $parts, BiometricDevice $device): void
    {
        $employeeId = str_replace('USER PIN=', '', $parts[0]);
        $cardNumber = null;

        foreach ($parts as $part) {
            if (strpos($part, 'Card=') === 0) {
                $cardNumber = str_replace('Card=', '', $part);
            }
        }

        if ($employeeId && $cardNumber) {
            self::updateOrCreateBiometricEmployee(
                $employeeId,
                ['card_number' => $cardNumber]
            );

            // BẮN EVENT RA NGOÀI HỆ THỐNG LARAVEL
            BiometricDataReceived::dispatch($device, $employeeId, 2, $cardNumber);
        }
    }

    /**
     * Handle photo data from device.
     */
    private static function handlePhotoData(array $parts, BiometricDevice $device): void
    {
        $employeeId = str_replace('BIOPHOTO PIN=', '', $parts[0]);
        $photo = null;

        foreach ($parts as $part) {
            if (strpos($part, 'Content=') === 0) {
                $photo = str_replace('Content=', '', $part);
            }
        }

        if ($employeeId && $photo) {
            self::updateOrCreateBiometricEmployee(
                $employeeId,
                [
                    'has_photo' => true,
                    'photo' => $photo,
                ]
            );

            // BẮN EVENT RA NGOÀI HỆ THỐNG LARAVEL
            BiometricDataReceived::dispatch($device, $employeeId, 3, $photo);
        }
    }

    /**
     * Handle face data from device.
     */
    private static function handleFaceData(array $parts, BiometricDevice $device): void
    {
        // 1. Tách chuỗi bằng dấu Tab (\t)
        $bioItems = explode("\t", trim($parts[0]));

        // Khởi tạo mảng tạm để gom dữ liệu key => value
        $parsedData = [];
        foreach ($bioItems as $item) {
            $keyValue = explode('=', $item, 2);
            if (count($keyValue) === 2) {
                $parsedData[$keyValue[0]] = $keyValue[1];
            }
        }

        // 2. Gán ra các biến riêng biệt để bạn tự lấy sử dụng
        $employeeId   = $parsedData['Pin'] ?? null;       // Mã nhân viên (Ví dụ: "0001")
        $no           = $parsedData['No'] ?? null;        // Số thứ tự data (Ví dụ: "0")
        $index        = $parsedData['Index'] ?? null;     // Chỉ mục khuôn mặt
        $valid        = $parsedData['Valid'] ?? null;     // Trạng thái hợp lệ (1: Có hiệu lực)
        $duress       = $parsedData['Duress'] ?? null;    // Cảnh báo cưỡng ép
        $bioType      = $parsedData['Type'] ?? null;      // Loại sinh trắc học (9: Face, 1: Fingerprint)
        $majorVer     = $parsedData['MajorVer'] ?? null;  // Phiên bản thuật toán lớn (Ví dụ: "35")
        $minorVer     = $parsedData['MinorVer'] ?? null;  // Phiên bản thuật toán nhỏ (Ví dụ: "4")
        $format       = $parsedData['Format'] ?? null;    // Định dạng template
        $faceTemplate = $parsedData['Tmp'] ?? null;       // CHUỖI FACE ENCODE (BASE64) BẠN CẦN

        // --- BẮT ĐẦU LOGIC TẠI ĐÂY ---
        if ($bioType == '9' && !empty($faceTemplate)) {
            self::updateOrCreateBiometricEmployee(
                $employeeId,
                [
                    'has_face' => true,
                    'face_template' => $faceTemplate,
                    'face_template_major_ver' => $majorVer ?? null,
                ]
            );

            // BẮN EVENT RA NGOÀI HỆ THỐNG LARAVEL
            BiometricDataReceived::dispatch($device, $employeeId, 9, $faceTemplate, $majorVer);
        }
    }

    /**
     * Update or create biometric employee record.
     */
    private static function updateOrCreateBiometricEmployee(string $employeeId, array $data): self
    {
        return self::updateOrCreate(
            ['biometric_employee_id' => $employeeId],
            $data
        );
    }

    /**
     * Process attendance data from device and create attendance records
     */
    public static function markAttendanceTodeviceAndApplication(array $rows, BiometricDevice $device, $request): void
    {
        foreach ($rows as $line) {
            $parts = explode("\t", $line);

            if (count($parts) >= 2) {
                $deviceEmployeeId = $parts[0];
                $timestamp = $parts[1];
                $status = $parts[2] ?? 0;

                // Skip if timestamp is 0 or not in a valid date time format
                if ($timestamp == 0 || ! strtotime($timestamp)) {
                    continue;
                }

                $timestamp = \Carbon\Carbon::parse((string) $timestamp, config('zkteco-biometric.timezone', 'UTC'))
                    ->format('Y-m-d H:i:s');

                // Check if the record already exists
                $existingRecord = DB::table(config('zkteco-biometric.database.tables.attendances', 'biometric_device_attendances'))
                    ->where('employee_id', $deviceEmployeeId)
                    ->where('timestamp', $timestamp)
                    ->where('device_serial_number', $device->serial_number)
                    ->first();

                if ($existingRecord) {
                    continue;
                }

                $biometricEmployee = self::where('biometric_employee_id', $deviceEmployeeId)->first();

                // Get the last attendance record for this employee on this day
                $timestampDate = date('Y-m-d', strtotime($timestamp));

                $lastRecord = DB::table(config('zkteco-biometric.database.tables.attendances', 'biometric_device_attendances'))
                    ->where('employee_id', $deviceEmployeeId)
                    ->whereDate('timestamp', $timestampDate)
                    ->orderBy('timestamp', 'desc')
                    ->first();

                // Default to clock in (0) if no record exists
                $status = 0;

                // If last record exists and is a clock in (0), then this should be a clock out (1)
                if ($lastRecord && $lastRecord->status1 == 0) {
                    $status = 1; // Clock out
                }
                // If last record exists and is a clock out (1), then this should be a clock in (0)
                elseif ($lastRecord && $lastRecord->status1 == 1) {
                    $status = 0; // Clock in
                }

                $attendances = [
                    'device_name' => $device->device_name,
                    'device_serial_number' => $device->serial_number,
                    'user_id' => $biometricEmployee ? $biometricEmployee->user_id : null,
                    'table' => $request->input('table') ?? ' ',
                    'stamp' => $request->input('Stamp') ?? ' ',
                    'employee_id' => $deviceEmployeeId,
                    'timestamp' => $timestamp,
                    'status1' => $status,
                    'status2' => self::validateAndFormatInteger($parts[3]) ?? -1,
                    'status3' => self::validateAndFormatInteger($parts[4]) ?? -1,
                    'status4' => self::validateAndFormatInteger($parts[5]) ?? -1,
                    'status5' => self::validateAndFormatInteger($parts[6]) ?? -1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Create the biometric attendance record
                $attendanceRecord = BiometricAttendance::create($attendances);

                // Auto-create biometric employee if enabled and user exists
                if (! $biometricEmployee && config('zkteco-biometric.attendance.auto_create_users', true)) {
                    $userModel = config('zkteco-biometric.models.user', config('auth.providers.users.model', 'App\Models\User'));
                    $employeeField = config('zkteco-biometric.attendance.employee_field', 'employee_code');
                    $user = $userModel::where($employeeField, $deviceEmployeeId)->first();

                    if ($user) {
                        $biometricEmployee = self::create([
                            'biometric_employee_id' => $deviceEmployeeId,
                            'user_id' => $user->id,
                        ]);

                        // Update the attendance record with the user_id
                        $attendanceRecord->update(['user_id' => $user->id]);
                    }
                }

                // Dispatch event for applications to handle attendance processing
                BiometricAttendanceRecorded::dispatch($attendanceRecord);
            }
        }
    }

    /**
     * Validate and format integer values.
     */
    private static function validateAndFormatInteger($value): ?int
    {
        if (isset($value) && $value !== '') {
            return is_numeric($value) ? (int) $value : null;
        }

        return null;
    }
}
