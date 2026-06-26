<?php

namespace AhidTechnologies\ZKTecoBiometric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use AhidTechnologies\ZKTecoBiometric\Models\BiometricDevice;
class BiometricDataReceived
{
    use Dispatchable, SerializesModels;

    public string $employeeId;
    public int $bioType;
    public string $template;
    public ?string $majorVersion;

    /**
     * Khởi tạo Event nhận dữ liệu sinh trắc học thô từ máy.
     *
     * @param BiometricDevice $device  Thiết bị sinh trắc học
     * @param string $employeeId  Mã ID nhân viên (PIN)
     * @param int $bioType        Loại sinh trắc học (9: Khuôn mặt, 1: Vân tay...)
     * @param string $template    Chuỗi mã hóa khuôn mặt/vân tay (TMP)
     * @param string|null $majorVersion Phiên bản thuật toán khuôn mặt (Ví dụ: 35)
     */
    public function __construct(public BiometricDevice $device, string $employeeId, int $bioType, string $template, ?string $majorVersion = null)
    {
        $this->device = $device;
        $this->employeeId = $employeeId;
        $this->bioType = $bioType;
        $this->template = $template;
        $this->majorVersion = $majorVersion;
    }
}
