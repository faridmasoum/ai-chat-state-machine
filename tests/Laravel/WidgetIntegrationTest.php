<?php

namespace FaridM\AiChatStateMachine\Tests\Laravel;

// Laravel integration tests need orchestra/testbench (installed on PHP 8.2+ in CI).
if (!class_exists(\Orchestra\Testbench\TestCase::class)) {
    return;
}

use FaridM\AiChatStateMachine\Contracts\FlowFactory;
use FaridM\AiChatStateMachine\Flow;
use FaridM\AiChatStateMachine\Laravel\AiChatStateMachineServiceProvider;
use FaridM\AiChatStateMachine\Laravel\FlowCompleted;
use FaridM\AiChatStateMachine\Steps;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

final class TestLeadFlow implements FlowFactory
{
    public function make(?string $locale = null): Flow
    {
        return Flow::make()
            ->add(Steps::text('name', $locale === 'fa' ? 'نام؟' : 'Name?'))
            ->add(Steps::email('email', 'Email?'));
    }
}

final class WidgetIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiChatStateMachineServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('ai-chat-state-machine.widget.enabled', true);
        $app['config']->set('ai-chat-state-machine.widget.flows', ['lead' => TestLeadFlow::class]);
        $app['config']->set('ai-chat-state-machine.widget.middleware', []);
    }

    public function test_full_conversation_over_http_dispatches_event(): void
    {
        Event::fake([FlowCompleted::class]);

        $start = $this->postJson('/chat-flow/lead', ['action' => 'start', 'locale' => 'fa'])->assertOk();
        $start->assertJsonPath('ui.message', 'نام؟');
        $id = $start->json('session');

        $this->postJson('/chat-flow/lead', ['session' => $id, 'action' => 'turn', 'turn' => ['step' => 'name', 'type' => 'text', 'value' => 'Farid']])
            ->assertOk()->assertJsonPath('step', 'email');
        $this->postJson('/chat-flow/lead', ['session' => $id, 'action' => 'turn', 'turn' => ['step' => 'email', 'type' => 'text', 'value' => 'bad']])
            ->assertOk()->assertJsonPath('status', 'invalid');
        $this->postJson('/chat-flow/lead', ['session' => $id, 'action' => 'turn', 'turn' => ['step' => 'email', 'type' => 'text', 'value' => 'f@example.com']])
            ->assertOk()->assertJsonPath('ui.type', 'review');
        $this->postJson('/chat-flow/lead', ['session' => $id, 'action' => 'turn', 'turn' => ['step' => 'done', 'type' => 'submit']])
            ->assertOk()->assertJsonPath('status', 'completed');

        Event::assertDispatched(FlowCompleted::class, function (FlowCompleted $e) {
            return $e->flow === 'lead' && $e->answers === ['name' => 'Farid', 'email' => 'f@example.com'];
        });
    }

    public function test_unknown_flow_is_404(): void
    {
        $this->postJson('/chat-flow/nope', ['action' => 'start'])->assertStatus(404);
    }

    public function test_blade_directive_renders_one_line_script_tag(): void
    {
        $html = Blade::render("@aiChatWidget('lead', ['lang' => 'ar', 'color' => '#0ea5e9'])");
        $this->assertStringContainsString('<script', $html);
        $this->assertStringContainsString('data-flow="lead"', $html);
        $this->assertStringContainsString('data-lang="ar"', $html);
        $this->assertStringContainsString('data-color="#0ea5e9"', $html);
        $this->assertStringContainsString('/chat-flow/lead', $html);
        $this->assertStringContainsString('cdn.jsdelivr.net', $html);
    }

    public function test_component_renders(): void
    {
        $html = Blade::render('<x-ai-chat-widget flow="lead" lang="he" />');
        $this->assertStringContainsString('data-lang="he"', $html);
    }

    public function test_attributes_are_escaped(): void
    {
        $html = Blade::render("@aiChatWidget('lead', ['title' => '\"><script>x</script>'])");
        $this->assertStringNotContainsString('"><script>x', $html);
    }
}

final class DisabledByDefaultTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiChatStateMachineServiceProvider::class];
    }

    public function test_no_route_and_no_output_when_disabled(): void
    {
        $this->postJson('/chat-flow/lead', ['action' => 'start'])->assertStatus(404);
        $this->assertSame('', trim(Blade::render("@aiChatWidget('lead')")));
    }
}
