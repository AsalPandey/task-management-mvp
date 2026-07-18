<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_primary_navigation_has_accessible_mobile_menu_markup(): void
    {
        $response = $this->actingAs($this->userWithRole('manager'))->get(route('manager.dashboard'));

        $response->assertOk();

        [$xpath, $navigation] = $this->navigationFrom($response);
        $button = $xpath->query("//button[@id='mobileMenuBtn']")->item(0);

        $this->assertInstanceOf(DOMElement::class, $button);
        $this->assertSame('button', $button->getAttribute('type'));
        $this->assertSame('primaryNavigation', $button->getAttribute('aria-controls'));
        $this->assertSame('false', $button->getAttribute('aria-expanded'));
        $this->assertSame('Open primary navigation', $button->getAttribute('aria-label'));
        $this->assertSame('Primary navigation', $navigation->getAttribute('aria-label'));
        $this->assertSame(1, $xpath->query("//button[@id='mobileMenuBtn']/span[@aria-hidden='true']")->length);
    }

    public function test_navigation_links_are_correct_for_each_role(): void
    {
        $roleExpectations = [
            'manager' => [route('manager.dashboard'), true],
            'project_manager' => [route('manager.dashboard'), true],
            'team_member' => [route('team-dashboard'), false],
        ];

        foreach ($roleExpectations as $role => [$dashboardUrl, $hasManagementLinks]) {
            $response = $this->actingAs($this->userWithRole($role))->get(route('tasks'));

            $response->assertOk();

            [$xpath, $navigation] = $this->navigationFrom($response);
            $hrefs = [];

            foreach ($xpath->query('.//a', $navigation) as $link) {
                $hrefs[] = $link->getAttribute('href');
            }

            $this->assertContains($dashboardUrl, $hrefs, "Dashboard link missing for {$role}.");
            $this->assertContains(route('projects'), $hrefs, "Projects link missing for {$role}.");
            $this->assertContains(route('tasks'), $hrefs, "Tasks link missing for {$role}.");
            $this->assertContains(route('settings'), $hrefs, "Settings link missing for {$role}.");

            if ($hasManagementLinks) {
                $this->assertContains(route('analytics'), $hrefs, "Analytics link missing for {$role}.");
                $this->assertContains(route('team-management'), $hrefs, "Team link missing for {$role}.");
            } else {
                $this->assertNotContains(route('analytics'), $hrefs, "Analytics link exposed to {$role}.");
                $this->assertNotContains(route('team-management'), $hrefs, "Team link exposed to {$role}.");
            }
        }
    }

    public function test_current_navigation_link_has_visual_and_semantic_state(): void
    {
        $response = $this->actingAs($this->userWithRole('manager'))->get(route('tasks'));

        $response->assertOk();

        [$xpath, $navigation] = $this->navigationFrom($response);
        $taskLink = $xpath->query(".//a[@href='".route('tasks')."']", $navigation)->item(0);

        $this->assertInstanceOf(DOMElement::class, $taskLink);
        $this->assertContains('active', preg_split('/\s+/', trim($taskLink->getAttribute('class'))));
        $this->assertSame('page', $taskLink->getAttribute('aria-current'));
        $this->assertSame(1, $xpath->query(".//a[@aria-current='page']", $navigation)->length);
    }

    public function test_responsive_css_keeps_desktop_and_mobile_display_rules_in_safe_order(): void
    {
        $source = $this->navigationSource();
        $tabletRulePosition = strpos($source, '@media (max-width: 900px)');
        $mobileRulePosition = strpos($source, '@media (max-width: 768px)');

        $this->assertIsInt($tabletRulePosition);
        $this->assertIsInt($mobileRulePosition);
        $this->assertLessThan($mobileRulePosition, $tabletRulePosition);
        $this->assertMatchesRegularExpression('/\.nav-tabs\s*\{[^}]*display:\s*flex;/s', substr($source, 0, $mobileRulePosition));

        preg_match_all('/\.mobile-menu-btn\s*\{[^}]*display:\s*none;[^}]*\}/s', $source, $hiddenButtonRules, PREG_OFFSET_CAPTURE);

        $this->assertCount(1, $hiddenButtonRules[0]);
        $this->assertLessThan($mobileRulePosition, $hiddenButtonRules[0][0][1]);

        $mobileCss = substr($source, $mobileRulePosition);

        $this->assertMatchesRegularExpression('/\.mobile-menu-btn\s*\{[^}]*display:\s*inline-flex;/s', $mobileCss);
        $this->assertMatchesRegularExpression('/\.nav-tabs\s*\{[^}]*display:\s*none;/s', $mobileCss);
        $this->assertMatchesRegularExpression('/\.nav-tabs\.open\s*\{[^}]*display:\s*flex;/s', $mobileCss);
        $this->assertDoesNotMatchRegularExpression('/\.mobile-menu-btn\s*\{[^}]*display:\s*none;/s', $mobileCss);
    }

    public function test_mobile_navigation_script_updates_aria_and_supports_expected_close_paths(): void
    {
        $source = $this->navigationSource();

        $this->assertStringContainsString("mobileMenuBtn.setAttribute('aria-expanded', String(shouldOpen))", $source);
        $this->assertStringContainsString("navTabs.addEventListener('click'", $source);
        $this->assertStringContainsString("e.key === 'Escape'", $source);
        $this->assertStringContainsString('setMobileMenuState(false, true)', $source);
        $this->assertStringContainsString('mobileMenuBtn.focus()', $source);
        $this->assertStringContainsString("mobileViewport.addEventListener('change'", $source);
    }

    /**
     * @return array{DOMXPath, DOMElement}
     */
    private function navigationFrom(TestResponse $response): array
    {
        $document = new DOMDocument;
        $previousSetting = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        $xpath = new DOMXPath($document);
        $navigation = $xpath->query("//nav[@id='primaryNavigation']")->item(0);

        $this->assertInstanceOf(DOMElement::class, $navigation);

        return [$xpath, $navigation];
    }

    private function navigationSource(): string
    {
        $source = file_get_contents(resource_path('views/partials/navigation.blade.php'));

        $this->assertIsString($source);

        return $source;
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
