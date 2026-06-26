<?php

namespace AhidTechnologies\ZKTecoBiometric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use AhidTechnologies\ZKTecoBiometric\Models\BiometricDevice;
use AhidTechnologies\ZKTecoBiometric\ZKTecoBiometric;

class UserStatusChanged
{
    use Dispatchable, SerializesModels;

    public string $employeeId;
    public int $userId;
    public string $employeeName;
    public bool $isActive;
    protected ZKTecoBiometric $biometric;

    /**
     * Khởi tạo sự kiện và TỰ ĐỘNG xử lý tạo lệnh ADMS trong thư viện.
     */
    public function __construct(string $employeeId, int $userId, string $employeeName, bool $isActive)
    {
        $this->employeeId = $employeeId;
        $this->userId = $userId;
        $this->employeeName = $employeeName;
        $this->isActive = $isActive;
        $this->biometric = new ZKTecoBiometric();

        // TỰ ĐỘNG XỬ LÝ LOGIC TẠO COMMAND NGAY TRONG LIB
        $this->generateDeviceCommands();
    }

    /**
     * Hàm xử lý check status để tạo câu lệnh tương ứng xuống database
     */
    protected function generateDeviceCommands(): void
    {
        // Lấy danh sách tất cả các máy chấm công từ bảng quản lý của thư viện
        $devices = BiometricDevice::where('status', 'online')->get();

        if($devices->isEmpty()) {
            // Nếu không có máy nào online, có thể log hoặc xử lý theo nhu cầu
            return;
        }

        foreach ($devices as $device) {
            if ($this->isActive) {
                // Nếu active: Tạo lệnh thêm user
                $this->biometric->createUserCommand($device->serial_number, $this->employeeId, $this->employeeName, $this->userId);
            } else {
                // Nếu không active: Tạo lệnh xóa user khỏi máy
                $this->biometric->deleteUserCommand($device->serial_number, $this->employeeId, $this->userId);
            }
        }
    }
}
