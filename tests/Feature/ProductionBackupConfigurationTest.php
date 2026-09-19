<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionBackupConfigurationTest extends TestCase
{
    public function test_blank_archive_password_disables_archive_encryption(): void
    {
        $this->assertNull(config('backup.backup.password'));
        $this->assertNull(config('backup.backup.encryption'));
    }
}
