<?php

namespace Tests\Unit;

use App\Rules\PositiveResourceId;
use PHPUnit\Framework\TestCase;

class PositiveResourceIdTest extends TestCase
{
    public function test_resource_ids_preserve_scalar_type_and_signed_runtime_range(): void
    {
        foreach ([1, '1', 123, '123', PHP_INT_MAX, (string) PHP_INT_MAX] as $value) {
            $errors = [];
            (new PositiveResourceId)->validate('id', $value, function ($message) use (&$errors): void {
                $errors[] = $message;
            });
            $this->assertSame([], $errors);
        }
        foreach ([true, false, 1.0, 1.5, 0, -1, null, '', ' ', '01', '1e0', '1.0', [], ['id' => 1], (object) ['id' => 1], '9223372036854775808'] as $value) {
            $errors = [];
            (new PositiveResourceId)->validate('id', $value, function ($message) use (&$errors): void {
                $errors[] = $message;
            });
            $this->assertCount(1, $errors);
        }
    }
}
