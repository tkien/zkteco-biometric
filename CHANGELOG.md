# Changelog

All notable changes to the ZKTeco Biometric package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-06-26
### Added

- Face ID

## [1.0.2] - 2025-11-11

### Fixed

- Removed timezone conversion that was causing timestamp discrepancies
- Removed `->setTimezone('UTC')` from `BiometricEmployee::markAttendanceTodeviceAndApplication()` method
- Removed `->utc()` from `AttendanceProcessor::processAttendanceRecord()` method
- Timestamps now stored as-is from device, preserving actual punch time without unwanted conversions

## [1.0.1] - 2025-11-01

### Fixed

- Fixed time synchronization command format for ZKTeco devices
- Changed from `TIME {timestamp}` to `SET OPTIONS DateTime={timestamp}` format
- Updated both `ZKTecoController::queueTimeSyncCommand()` and `ZKTecoBiometric::syncTime()` methods

## [1.0.0] - 2024-12-29

### Added

- Initial release of ZKTeco Biometric Laravel Package
- Core models for device, employee, command, and attendance management
- RESTful API endpoints for device communication
- Real-time attendance processing
- Fingerprint, card, and photo data synchronization
- Command system for device management (create/delete users)
- Comprehensive logging and error handling
- Timezone support for global deployments
- Database migrations for all required tables
- Service provider for easy Laravel integration
- Configuration file for customization
- Facade for convenient access to package functionality
- Example usage and device setup documentation

### Features

- **Device Management**: Add, configure, and monitor ZKTeco devices
- **Employee Sync**: Synchronize employee biometric data with devices
- **Attendance Tracking**: Automatic attendance logging from device data
- **Command Processing**: Send and track commands to devices
- **Multi-timezone Support**: Proper timezone handling across different regions
- **Extensible Architecture**: Easy to extend and customize for specific needs

### Supported Devices

- ZKTeco F18/F19/F22 Series
- ZKTeco iClock Series
- ZKTeco SpeedFace Series
- Compatible with most ZKTeco devices supporting TCP/IP communication

### Requirements

- PHP 8.0+
- Laravel 10.0+
- MySQL/PostgreSQL database

### Security

- Secure device communication protocols
- Input validation and sanitization
- Protection against SQL injection
- Configurable middleware support
