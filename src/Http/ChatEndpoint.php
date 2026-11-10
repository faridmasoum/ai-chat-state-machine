<?php

namespace FaridM\AiChatStateMachine\Http;

use FaridM\AiChatStateMachine\Contracts\SessionStore;
use FaridM\AiChatStateMachine\Contracts\TurnInterpreter;
use FaridM\AiChatStateMachine\Exceptions\InvalidTurnException;
use FaridM\AiChatStateMachine\Exceptions\StepMismatchException;
use FaridM\AiChatStateMachine\FlowEngine;
use FaridM\AiChatStateMachine\Support\ArraySession;
use FaridM\AiChatStateMachine\Turn;
use FaridM\AiChatStateMachine\TurnResult;

/**
 * Framework-agnostic JSON protocol spoken by the browser widget.
 *
 * Request  {"flow": "name", "locale": "fa", "session": "<id>|null", "action": "start|restart|state|turn",
 *           "turn": {"step": "...", "type": "text|choice|list|skip|edit|submit", "value": ...}}
 * Response {"session", "status": "continue|invalid|completed", "step", "ui",
 *           "progress": {"current","total"}, "summary": [...], "error"}
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

    /** @var TurnInterpreter|null */
    private $interpreter;

    /** @var int */
    private $maxTurns;

    public function __construct(FlowRegistry $flows, SessionStore $store, ?TurnInterpreter $interpreter = null, int $maxTurns = 200)
    {
        $this->flows = $flows;
        $this->store = $store;
        $this->interpreter = $interpreter;
        $this->maxTurns = $maxTurns;
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
        $engine = new FlowEngine($this->flows->make($flowName, $locale), $this->interpreter, $this->maxTurns);
        $action = is_string($input['action'] ?? null) ? $input['action'] : 'state';
        $id = is_string($input['session'] ?? null) && preg_match('/^[a-f0-9]{32}$/', $input['session']) ? $input['session'] : null;
        $session = $id !== null ? $this->store->load($id) : null;

        if ($action === 'restart' || ($action === 'start' && $session === null) || ($action === 'state' && $session === null)) {
            $id = bin2hex(random_bytes(16));
            $session = new ArraySession();
            $engine->start($session);
            $this->store->save($id, $session);

            return $this->ok($engine, $id, $session, 'continue');
        }

        if ($session === null) {
            return $this->error(404, 'Session not found.');
        }

        if ($action === 'start' || $action === 'state') {
            return $this->ok($engine, $id, $session, $session->isCompleted() ? 'completed' : 'continue');
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
            $response = $this->ok($engine, $id, $session, 'continue');
            $response['status'] = 409;
            $response['body']['error'] = 'The conversation moved on. Showing the current step.';

            return $response;
        } catch (InvalidTurnException $e) {
            return $this->error(400, 'That action is not valid for this flow.');
        }

        $this->store->save($id, $session);

        return $this->ok($engine, $id, $session, $result->status, $result);
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
    private function ok(FlowEngine $engine, string $id, ArraySession $session, string $status, ?TurnResult $result = null): array
    {
        $state = $session->chatState();
        $summary = [];
        foreach ($engine->flow()->steps() as $key => $step) {
            if ($step->stepApplies($state) && $state->has($key)) {
                $summary[] = ['step' => $key, 'label' => $step->label, 'value' => $step->stepDisplay($state)];
            }
        }

        return [
            'status' => 200,
            'body' => [
                'session' => $id,
                'status' => $status,
                'step' => $session->currentStep(),
                'ui' => $engine->ui($session),
                'progress' => $engine->progress($session),
                'summary' => $summary,
                'error' => $result !== null && $result->isInvalid() ? $result->error : null,
            ],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function error(int $code, string $message): array
    {
        return ['status' => $code, 'body' => ['status' => 'error', 'error' => $message]];
    }
}
