<?php

namespace AhidTechnologies\ZKTecoBiometric\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use AhidTechnologies\ZKTecoBiometric\Traits\HasLogging;
use AhidTechnologies\ZKTecoBiometric\Models\BiometricDevice;
use AhidTechnologies\ZKTecoBiometric\Models\BiometricCommand;
use AhidTechnologies\ZKTecoBiometric\Models\BiometricEmployee;
use AhidTechnologies\ZKTecoBiometric\Services\AttendanceProcessor;
use AhidTechnologies\ZKTecoBiometric\ZKTecoBiometric;

class ZKTecoController
{
    use HasLogging;

    protected AttendanceProcessor $attendanceProcessor;
    protected ZKTecoBiometric $zktecoBiometric;

    public function __construct(AttendanceProcessor $attendanceProcessor, ZKTecoBiometric $zktecoBiometric)
    {
        $this->attendanceProcessor = $attendanceProcessor;
        $this->zktecoBiometric = $zktecoBiometric;
    }

    /**
     * Handle incoming attendance data from ZKTeco devices
     */
    public function handleAttendanceData(Request $request): Response
    {
        // Log the endpoint hit
        $this->logApiRequest('/iclock/cdata', [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $request->headers->all(),
            'query_params' => $request->query(),
            'content_length' => strlen($request->getContent()),
        ]);

        $sn = strtoupper($request->input('SN'));

        if (!$sn) {
            $this->logError('Missing device SN', $request->all());
            return $this->deviceResponse("ERROR: Missing device SN", 400);
        }

        $device = BiometricDevice::where('serial_number', $sn)->first();

        if (!$device) {
            $this->logError("Device not found: {$sn}", $request->all());
            return $this->deviceResponse("Device not found", 404);
        }

        // Check for time drift and queue sync if needed
        $this->checkAndQueueTimeSync($device, $request);

        // Update device status
        $device->markOnline($request->ip());

        // Check for time drift and queue sync if needed
        $this->checkAndQueueTimeSync($device, $request);

        $rawContent = $request->getContent();
        $rows = preg_split('/\\r\\n|\\r|\\n/', $rawContent);

        $this->logInfo('Attendance data received', [
            'device_sn' => $sn,
            'rows_count' => count($rows),
            'rows' => $rows,
        ]);

        // Handle OPLOG events for face registration
        if (str_contains($rawContent, 'OPLOG')) {
            return $this->handleOplog($rawContent, $device);
        }

        // Check if the content contains biometric data (fingerprint, user, card, or photo, bioData)
        $hasBiometricData = (
            strpos($rawContent, 'FP PIN=') !== false ||
            strpos($rawContent, 'USER PIN=') !== false ||
            strpos($rawContent, 'Card=') !== false ||
            strpos($rawContent, 'BIOPHOTO PIN=') !== false ||
            (strpos($rawContent, 'BIODATA PIN=') !== false && strpos($rawContent, 'Type=9') !== false)
        );

        if ($hasBiometricData) {
            // Process biometric data
            BiometricEmployee::recordFingerprint($rows, $device);
            return $this->deviceResponse("OK");
        }

        // Process attendance data
        BiometricEmployee::markAttendanceTodeviceAndApplication($rows, $device, $request);

        return $this->deviceResponse('OK');
    }

    /**
     * Handle device handshake requests
     */
    public function handshake(Request $request): Response
    {
        // Log the endpoint hit
        $this->logApiRequest('/iclock/getrequest', [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query_params' => $request->query(),
        ]);

        $sn = strtoupper($request->input('SN', ''));

        if (!$sn) {
            $this->logError('Handshake: Missing device SN', $request->all());
            return $this->deviceResponse("ERROR: Missing device SN", 400);
        }

        $device = BiometricDevice::where('serial_number', $sn)->first();

        if (!$device) {
            $this->logError("Handshake: Device not found: {$sn}", $request->all());
            return $this->deviceResponse("Device not found", 404);
        }

        // Update device status
        $device->markOnline($request->ip());

        $timezoneToMinutes = BiometricCommand::timezoneToMinutes(
            config('zkteco-biometric.timezone', 'UTC')
        );

        $response = "GET OPTION FROM: {$sn}\r\n" .
            "Stamp=9999\r\n" .
            "OpStamp=" . time() . "\r\n" .
            "ErrorDelay=60\r\n" .
            "Delay=30\r\n" .
            "ResLogDay=18250\r\n" .
            "ResLogDelCount=10000\r\n" .
            "ResLogCount=50000\r\n" .
            "TransTimes=00:00;14:05\r\n" .
            "TransInterval=1\r\n" .
            "TransFlag=1111000000\r\n" .
            "TimeZone=" . $timezoneToMinutes . "\r\n" .
            "Realtime=1\r\n" .
            "Encrypt=0";

        $this->logInfo('Handshake response sent', [
            'device_sn' => $sn,
            'response' => $response,
        ]);

        return $this->deviceResponse($response);
    }

    /**
     * Handle device polling requests for commands
     */
    public function handleGetRequest(Request $request): Response
    {
        // Log the endpoint hit
        $this->logApiRequest('/iclock/getrequest', [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query_params' => $request->query(),
        ]);

        $sn = strtoupper($request->get('SN', ''));

        if (!$sn) {
            return $this->deviceResponse("OK");
        }

        // Lookup pending command for device
        $command = BiometricCommand::where('device_serial_number', $sn)
            ->where('status', 'pending')
            ->first();

        if ($command) {
            $this->logInfo('Sending command to device', [
                'device_sn' => $sn,
                'command_id' => $command->command_id,
                'command' => $command->command,
            ]);

            $command->markAsSent();

            return $this->deviceResponse($command->command);
        }

        return $this->deviceResponse("OK");
    }

    /**
     * Handle device command execution results
     */
    public function handleDeviceCommand(Request $request): Response
    {
        // Log the endpoint hit
        $this->logApiRequest('/iclock/devicecmd', [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query_params' => $request->query(),
            'content_length' => strlen($request->getContent()),
        ]);

        $sn = strtoupper($request->get('SN', ''));

        if (!$sn) {
            $this->logError('Command execution: Missing device SN', $request->all());
            return $this->deviceResponse('OK');
        }

        $device = BiometricDevice::where('serial_number', $sn)->first();

        if (!$device) {
            $this->logError('Command execution: Device not found', ['sn' => $sn]);
            return $this->deviceResponse('OK');
        }

        // Get the raw request body
        $rawBody = $request->getContent();

        // Parse the response body
        parse_str(str_replace("\n", "", $rawBody), $parsedResponse);

        // Extract command and return code
        $command = $parsedResponse['CMD'] ?? '';
        $returnCode = $parsedResponse['Return'] ?? '';
        $commandId = $parsedResponse['ID'] ?? '';

        $this->logInfo('Command execution result received', [
            'device_sn' => $sn,
            'command' => $command,
            'return_code' => $returnCode,
            'command_id' => $commandId,
            'parsed_response' => $parsedResponse,
        ]);

        if (!empty($commandId)) {
            $pendingCommand = BiometricCommand::where('command_id', $commandId)->first();

            if ($pendingCommand) {
                if ($returnCode == '0') {
                    BiometricCommand::commandExecuted($pendingCommand, $device);
                } else {
                    BiometricCommand::commandFailed($pendingCommand);
                    $this->logWarning('Command execution failed', [
                        'command_id' => $commandId,
                        'error_code' => $returnCode,
                    ]);
                }
            }
        }

        return $this->deviceResponse('OK');
    }

    /**
     * Handle device ping requests
     */
    public function handlePing(Request $request): Response
    {
        // Log the endpoint hit
        $this->logApiRequest('/iclock/ping', [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query_params' => $request->query(),
        ]);

        $sn = strtoupper($request->get('SN', ''));

        if ($sn) {
            $device = BiometricDevice::where('serial_number', $sn)->first();
            if ($device) {
                $device->markOnline($request->ip());
                $this->logDebug('Device ping received', [
                    'device_sn' => $sn,
                    'device_ip' => $request->ip(),
                ]);
            }
        }

        return $this->deviceResponse('OK');
    }

    /**
     * Create a standardized device response
     */
    protected function deviceResponse(string $content, int $status = 200): Response
    {
        return response($content, $status)->header('Content-Type', 'text/plain');
    }

    /**
     * Handle OPLOG events for face registration
     */
    protected function handleOplog(string $rawContent, BiometricDevice $device): Response
    {
        // 1. Phân tách chuỗi thô của ZKTeco bằng dấu Tab (\t) hoặc dấu xuống dòng (\n)
        $formattedContent = str_replace(["\n", "\t"], "&", $rawContent);
        parse_str($formattedContent, $opData);

        // Dựa trên log: OPLOG 114 0 2026-06-25 18:59:52 0001 0 0 0
        // parse_str sẽ bóc tách các trường. Cần tìm mã thao tác và ID nhân viên.
        // Lưu ý: Tùy cách parse chuỗi thô, bạn có thể lấy mảng trực tiếp bằng cách nổ chuỗi (explode)
        $parts = explode("\t", trim($rawContent));

        // Đảm bảo mảng có đủ phần tử và phần tử đầu tiên chứa 'OPLOG 114' hoặc từ khóa OPLOG
        if (count($parts) >= 5) {
            // Tách chữ "OPLOG 114" hoặc "OPLOG 101" thành mảng
            $opHeader = explode(" ", $parts[0]);
            $opType = $opHeader[1] ?? null; // Lấy mã số: 114 hoặc 101
            $employeeId = $parts[3] ?? null; // Lấy mã nhân viên: "0001"

            // Mã 114: Đăng ký khuôn mặt thành công trên dòng máy Visible Light
            if ($opType === '114' && $employeeId) {
                // Kiểm tra nhân viên
                $biometricEmployee = BiometricEmployee::where('biometric_employee_id', $employeeId)->first();

                // Không có thì bỏ qua
                if(!$biometricEmployee) {
                    return $this->deviceResponse("OK");
                }

                // 2. Tạo lệnh ép chính chiếc máy này phải gửi dữ liệu khuôn mặt lên
                // Cú pháp chuẩn ZKTeco: DATA QUERY BIODATA PIN=[Mã_NV] TYPE=9
                $this->zktecoBiometric->queryBioDataCommand(
                    deviceSerial: $device->serial_number,
                    pin: $employeeId,
                    bioType: 9, // 9 = Face, 1 = Fingerprint, 2 = Card
                    userId: $biometricEmployee->user_id
                );
            }
        }

        return $this->deviceResponse("OK");
    }

    /**
     * Check device time drift and queue sync command if needed
     */
    protected function checkAndQueueTimeSync(BiometricDevice $device, Request $request): void
    {
        // Get device timestamp from request (if available)
        $deviceTimestamp = $request->query('timestamp');

        if (!$deviceTimestamp) {
            return; // No timestamp to compare
        }

        try {
            $deviceTime = \Carbon\Carbon::parse($deviceTimestamp);
            $serverTime = now();
            $timeDiffMinutes = abs($deviceTime->diffInMinutes($serverTime));

            // If time difference is more than 5 minutes, queue sync command
            if ($timeDiffMinutes > 5) {
                $this->queueTimeSyncCommand($device);

                $this->logInfo('Time drift detected, sync command queued', [
                    'device_sn' => $device->serial_number,
                    'device_time' => $deviceTime->toISOString(),
                    'server_time' => $serverTime->toISOString(),
                    'diff_minutes' => $timeDiffMinutes,
                ]);
            }
        } catch (\Exception $e) {
            $this->logError('Failed to parse device timestamp', [
                'device_sn' => $device->serial_number,
                'timestamp' => $deviceTimestamp,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Queue a time sync command for a device
     */
    public function queueTimeSyncCommand(BiometricDevice $device): void
    {
        $currentTime = now()->format('Y-m-d H:i:s');
        $commandId = 'SYNCTIME_' . $device->serial_number . '_' . now()->timestamp;
        $command = sprintf('SET OPTIONS DateTime=%s', $currentTime);

        BiometricCommand::create([
            'type' => 'SYNCTIME',
            'device_serial_number' => $device->serial_number,
            'command_id' => $commandId,
            'command' => $command,
            'employee_id' => null,
            'user_id' => null,
            'status' => 'pending',
        ]);

        $this->logInfo('Time sync command queued', [
            'device_sn' => $device->serial_number,
            'command_id' => $commandId,
            'sync_time' => $currentTime,
        ]);
    }
}
