<?php

namespace FaridM\StepChat\Http;

use Closure;
use FaridM\StepChat\Contracts\ChangeDetector;
use FaridM\StepChat\Contracts\HandoffPolicy;
use FaridM\StepChat\Contracts\InterpretationPolicy;
use FaridM\StepChat\Contracts\Messages;
use FaridM\StepChat\Contracts\SessionStore;
use FaridM\StepChat\Contracts\Transcript;
use FaridM\StepChat\ConversationListener;
use FaridM\StepChat\Exceptions\InvalidTurnException;
use FaridM\StepChat\Exceptions\StepMismatchException;
use FaridM\StepChat\FlowEngine;
use FaridM\StepChat\Support\ArraySession;
use FaridM\StepChat\Support\CompositeTranscript;
use FaridM\StepChat\Support\EnglishMessages;
use FaridM\StepChat\Support\SessionTranscript;
use FaridM\StepChat\Turn;
use FaridM\StepChat\TurnResult;

/**
 * Framework-agnostic JSON protocol spoken by the browser widget.
 *
 * Request  {"flow": "name", "locale": "fa", "session": "<id>|null", "cursor": 0, "action": "start|restart|state|turn",
 *           "turn": {"step": "...", "type": "text|choice|list|skip|edit|submit", "value": ...}}
 * Response {"session", "status": "continue|invalid|submitting|completed|ended", "step", "ui",
 *           "progress": {"current","total"}, "summary": [...], "error",
 *           "messages": [{id, role, kind, step, text}...] written since "cursor", "cursor": <next cursor>}
 *
 * handle() returns ['status' => <HTTP code>, 'body' => <array>] so any
 * framework can wrap it in its own response object.
 */
final class ChatEndpoint
{
    /** @var FlowRegistry */
    private $flows;

    /** @var SessionStore */
    private $store;

    /** @var mixed */
    private $interpreter;

    /** @var int */
    private $maxTurns;

    /** @var Transcript|null */
    private $transcript;

    /** @var Messages|Closure(string|null): Messages|null */
    private $messages;

    /** @var ChangeDetector|null */
    private $changeDetector;

    /** @var HandoffPolicy|null */
    private $handoff;

    /** @var ConversationListener|null */
    private $listener;

    /** @var InterpretationPolicy|null */
    private $policy;

    /** @var bool */
    private $checkInvariants;

    /** @var bool */
    private $deterministicCorrections;

    /**
     * @param \FaridM\StepChat\Contracts\ConversationInterpreter|\FaridM\StepChat\Contracts\TurnInterpreter|null $interpreter
     * @param Messages|Closure(string|null): Messages|null $messages a Messages object, or a factory receiving the widget locale
     */
    public function __construct(
        FlowRegistry $flows,
        SessionStore $store,
        $interpreter = null,
        int $maxTurns = 200,
        ?Transcript $transcript = null,
        $messages = null,
        ?ChangeDetector $changeDetector = null,
        ?HandoffPolicy $handoff = null,
        ?ConversationListener $listener = null,
        ?InterpretationPolicy $interpretationPolicy = null,
        bool $checkInvariants = true,
        bool $deterministicCorrections = false
    ) {
        $this->flows = $flows;
        $this->store = $store;
        $this->interpreter = $interpreter;
        $this->maxTurns = $maxTurns;
        $this->transcript = $transcript;
        $this->messages = $messages;
        $this->changeDetector = $changeDetector;
        $this->handoff = $handoff;
        $this->listener = $listener;
        $this->policy = $interpretationPolicy;
        $this->checkInvariants = $checkInvariants;
        $this->deterministicCorrections = $deterministicCorrections;
    }

    /**
     * @param array<string, mixed> $input decoded JSON request body
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $flowName, array $input): array
    {
        if (!$this->flows->has($flowName)) {
            return $this->error(404, 'Unknown flow.');
        }

        $locale = is_string($input['locale'] ?? null) && preg_match('/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{2,8})*$/', $input['locale'])
            ? $input['locale']
            : null;
        $messages = $this->messages instanceof Closure ? ($this->messages)($locale) : $this->messages;
        $sessionTranscript = new SessionTranscript();
        $engine = new FlowEngine(
            $this->flows->make($flowName, $locale),
            $this->interpreter,
            $this->maxTurns,
            $this->transcript ? new CompositeTranscript([$sessionTranscript, $this->transcript]) : $sessionTranscript,
            $messages,
            $this->changeDetector,
            $this->handoff,
            $this->listener,
            $this->policy,
            $this->checkInvariants,
            $this->deterministicCorrections
        );
        $defaultMessages = $messages === null || get_class($messages) === EnglishMessages::class;
        $cursor = max(0, (int) ($input['cursor'] ?? 0));
        $action = is_string($input['action'] ?? null) ? $input['action'] : 'state';
        $id = is_string($input['session'] ?? null) && preg_match('/^[a-f0-9]{32}$/', $input['session']) ? $input['session'] : null;
        $session = $id !== null ? $this->store->load($id) : null;

        if ($action === 'restart' || ($action === 'start' && $session === null) || ($action === 'state' && $session === null)) {
            $id = bin2hex(random_bytes(16));
            $session = new ArraySession();
            $engine->start($session);
            $this->store->save($id, $session);

            return $this->ok($engine, $id, $session, 'continue', null, 0, $defaultMessages);
        }

        if ($session === null) {
            return $this->error(404, 'Session not found.');
        }

        if ($action === 'start' || $action === 'state') {
            return $this->ok($engine, $id, $session, $this->statusOf($session), null, $cursor, $defaultMessages);
        }

        if ($action !== 'turn') {
            return $this->error(400, 'Unknown action.');
        }

        $raw = $input['turn'] ?? null;
        if (!is_array($raw) || !is_string($raw['step'] ?? null) || !in_array($raw['type'] ?? null, Turn::TYPES, true)) {
            return $this->error(400, 'Malformed turn.');
        }
        $value = $raw['value'] ?? null;
        if (!is_scalar($value) && !is_array($value) && $value !== null) {
            return $this->error(400, 'Malformed turn value.');
        }

        try {
            $result = $engine->handleTurn($session, new Turn($raw['step'], $raw['type'], $value));
        } catch (StepMismatchException $e) {
            // Client is out of sync (stale tab, double click): hand it the truth.
            $response = $this->ok($engine, $id, $session, 'continue', null, $cursor, $defaultMessages);
            $response['status'] = 409;
            $response['body']['error'] = 'The conversation moved on. Showing the current step.';

            return $response;
        } catch (InvalidTurnException $e) {
            return $this->error(400, 'That action is not valid for this flow.');
        }

        $this->store->save($id, $session);

        return $this->ok($engine, $id, $session, $result->status, $result, $cursor, $defaultMessages);
    }

    /**
     * Convenience for plain PHP: read the request, emit headers and JSON.
     */
    public function serve(?string $flowName = null): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $input = is_array($decoded) ? $decoded : [];
        $name = $flowName ?? (is_string($input['flow'] ?? null) ? $input['flow'] : '');
        $response = $this->handle($name, $input);
        http_response_code($response['status']);
        echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ok(FlowEngine $engine, string $id, ArraySession $session, string $status, ?TurnResult $result = null, int $cursor = 0, bool $defaultMessages = true): array
    {
        $state = $session->chatState();
        $summary = [];
        foreach ($engine->flow()->steps() as $key => $step) {
            if ($step->stepApplies($state) && $state->has($key) && !$step->confirmation) {
                $summary[] = ['step' => $key, 'label' => $step->label, 'value' => $step->stepDisplay($state), 'editable' => $step->isEditable()];
            }
        }

        $ui = $engine->ui($session);
        if ($ui['type'] === 'ended' && $status === 'continue') {
            $status = 'ended';
        }

        return [
            'status' => 200,
            'body' => [
                'session' => $id,
                'status' => $status,
                'step' => $session->currentStep(),
                'ui' => $ui,
                'progress' => $engine->progress($session),
                'summary' => $summary,
                'error' => $result !== null && $result->isInvalid() ? $result->error : null,
                'messages' => $session->messagesSince($cursor),
                'cursor' => $session->messageCursor(),
                'defaultMessages' => $defaultMessages,
            ],
        ];
    }

    private function statusOf(ArraySession $session): string
    {
        if ($session->isCompleted()) {
            return 'completed';
        }
        if ($session->chatState()->ended()) {
            return 'ended';
        }

        return $session->isSubmitting() ? 'submitting' : 'continue';
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function error(int $code, string $message): array
    {
        return ['status' => $code, 'body' => ['status' => 'error', 'error' => $message]];
    }
}
