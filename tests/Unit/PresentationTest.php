<?php

namespace Tests\Unit;

use App\Support\Presentation;
use PHPUnit\Framework\TestCase;

class PresentationTest extends TestCase
{
    public function test_initials_preserve_graphemes_and_whitespace(): void
    {
        foreach ([
            ['Asal Pandey', 'AP'], ['  Asal   Pandey  ', 'AP'], ['Asal', 'A'],
            ['आशा पाण्डे', 'आपा'], ['किरण शर्मा', 'किश'], ['👩🏽‍💻 Dev', '👩🏽‍💻D'],
            ['élise Noël', 'ÉN'], ['Asal पाण्डे', 'Aपा'], ['', '?'], [null, '?'],
        ] as [$name, $expected]) {
            $this->assertSame($expected, Presentation::initials($name));
        }
    }

    public function test_role_labels_preserve_internal_keys_only_for_logic(): void
    {
        $this->assertSame('Project Manager', Presentation::role('project_manager'));
        $this->assertSame('Team Member', Presentation::role('team_member'));
        $this->assertSame('Manager', Presentation::role('manager'));
        $this->assertSame('Unknown role', Presentation::role('unexpected'));
    }
}
