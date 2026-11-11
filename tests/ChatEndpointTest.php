<?php

namespace FaridM\StepChat\Tests;

use FaridM\StepChat\Flow;
use FaridM\StepChat\Http\ChatEndpoint;
use FaridM\StepChat\Http\FlowRegistry;
use FaridM\StepChat\Steps;
use FaridM\StepChat\Support\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

final class ChatEndpointTest extends TestCase
{
    private function endpoint(): ChatEndpoint
    {
        $registry = (new FlowRegistry())->register('lead', static function (): Flow {
            return Flow::make()
                ->add(Steps::text('name', 'Name'))
                ->add(Steps::email('email', 'Email'));
        });

        return new ChatEndpoint($registry, new InMemorySessionStore());
    }

    private function turn(string $id, string $step, string $type, $value = null): array
    {
        return ['session' => $id, 'action' => 'turn', 'turn' => ['step' => $step, 'type' => $type, 'value' => $value]];
    }

    public function test_start_creates_a_session_and_returns_first_step(): void
    {
        $r = $this->endpoint()->handle('lead', ['action' => 'start']);
        $this->assertSame(200, $r['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $r['body']['session']);
        $this->assertSame('name', $r['body']['step']);
        $this->assertSame(['current' => 1, 'total' => 2], $r['body']['progress']);
    }

    public function test_full_conversation_and_resume(): void
    {
        $ep = $this->endpoint();
        $id = $ep->handle('lead', ['action' => 'start'])['body']['session'];

        $r = $ep->handle('lead', $this->turn($id, 'name', 'text', 'Farid'));
        $this->assertSame('email', $r['body']['step']);

        $bad = $ep->handle('lead', $this->turn($id, 'email', 'text', 'nope'));
        $this->assertSame('invalid', $bad['body']['status']);
        $this->assertNotNull($bad['body']['error']);

        $ep->handle('lead', $this->turn($id, 'email', 'text', 'f@example.com'));

        // A reload resumes the same conversation at the review screen, with its summary.
        $resumed = $ep->handle('lead', ['action' => 'start', 'session' => $id]);
        $this->assertSame($id, $resumed['body']['session']);
        $this->assertSame('review', $resumed['body']['ui']['type']);
        $this->assertCount(2, $resumed['body']['summary']);

        $done = $ep->handle('lead', $this->turn($id, 'done', 'submit'));
        $this->assertSame('completed', $done['body']['status']);
    }

    public function test_stale_turn_returns_409_with_current_state(): void
    {
        $ep = $this->endpoint();
        $id = $ep->handle('lead', ['action' => 'start'])['body']['session'];
        $r = $ep->handle('lead', $this->turn($id, 'email', 'text', 'a@b.co'));
        $this->assertSame(409, $r['status']);
        $this->assertSame('name', $r['body']['step']);
    }

    public function test_bad_input_is_rejected(): void
    {
        $ep = $this->endpoint();
        $this->assertSame(404, $ep->handle('nope', [])['status']);
        $id = $ep->handle('lead', ['action' => 'start'])['body']['session'];
        $this->assertSame(400, $ep->handle('lead', ['session' => $id, 'action' => 'turn', 'turn' => ['step' => 'name', 'type' => 'bogus']])['status']);
        $this->assertSame(400, $ep->handle('lead', ['session' => $id, 'action' => 'explode'])['status']);
    }

    public function test_unknown_or_malformed_session_id_starts_fresh(): void
    {
        $r = $this->endpoint()->handle('lead', ['action' => 'start', 'session' => '../../etc']);
        $this->assertSame('name', $r['body']['step']);
        $this->assertNotSame('../../etc', $r['body']['session']);
    }

    public function test_locale_is_passed_to_the_flow_factory(): void
    {
        $seen = [];
        $registry = (new FlowRegistry())->register('lead', static function (?string $locale) use (&$seen): Flow {
            $seen[] = $locale;

            return Flow::make()->add(Steps::text('name', $locale === 'fa' ? 'نام شما؟' : 'Name?'));
        });
        $ep = new ChatEndpoint($registry, new InMemorySessionStore());

        $r = $ep->handle('lead', ['action' => 'start', 'locale' => 'fa']);
        $this->assertSame('نام شما؟', $r['body']['ui']['message']);
        $ep->handle('lead', ['action' => 'start', 'locale' => '<script>']);
        $this->assertSame(['fa', null], $seen);
    }

    public function test_responses_carry_the_conversation_messages_with_a_cursor(): void
    {
        $ep = $this->endpoint();
        $start = $ep->handle('lead', ['action' => 'start'])['body'];
        $this->assertSame([['id' => 0, 'role' => 'assistant', 'kind' => 'question', 'step' => 'name', 'text' => 'Name']], $start['messages']);
        $this->assertSame(1, $start['cursor']);

        $bad = $ep->handle('lead', $this->turn($start['session'], 'name', 'text', '') + ['cursor' => $start['cursor']])['body'];
        $this->assertSame(['user', 'assistant'], array_column($bad['messages'], 'role'));
        $this->assertSame('error', $bad['messages'][1]['kind']);
        $this->assertSame(3, $bad['cursor']);

        $ok = $ep->handle('lead', $this->turn($start['session'], 'name', 'text', 'Farid') + ['cursor' => $bad['cursor']])['body'];
        $this->assertSame(['Farid', 'Email'], array_column($ok['messages'], 'text'));

        $replay = $ep->handle('lead', ['action' => 'state', 'session' => $start['session']])['body'];
        $this->assertCount(5, $replay['messages'], 'a reloaded widget replays from cursor 0');
    }

    public function test_the_turn_limit_ends_the_session_through_the_endpoint(): void
    {
        $registry = (new FlowRegistry())->register('lead', static function (): Flow {
            return Flow::make()->add(Steps::text('name', 'Name'))->add(Steps::email('email', 'Email'));
        });
        $ep = new ChatEndpoint($registry, new InMemorySessionStore(), null, 1);
        $id = $ep->handle('lead', ['action' => 'start'])['body']['session'];
        $ep->handle('lead', $this->turn($id, 'name', 'text', 'Farid'));

        $r = $ep->handle('lead', $this->turn($id, 'email', 'text', 'a@b.co'))['body'];

        $this->assertSame('ended', $r['status']);
        $this->assertSame('ended', $r['ui']['type']);
        $this->assertSame('ended', $ep->handle('lead', ['action' => 'state', 'session' => $id])['body']['status']);
    }
}
