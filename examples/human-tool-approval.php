<?php

namespace Vos\TollTroll\Examples;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;
use Vos\TollTroll\Evaluation;

class ReportAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public Evaluation $evaluation, public Authenticatable $actor)
    {
        if (! $evaluation->allows('bug')) {
            throw new InvalidArgumentException('Only an actionable bug report may reach this agent.');
        }
    }

    public function instructions(): string
    {
        return $this->evaluation->context->instructions;
    }

    public function tools(): iterable
    {
        return [new RecordApprovedReport($this->evaluation, $this->actor)];
    }
}

class RecordApprovedReport implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(public Evaluation $evaluation, public Authenticatable $actor) {}

    public function description(): string
    {
        return 'Record the evaluated bug report as a work request. Requires human approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        // The model cannot substitute the report, destination, or evaluation ID.
        return [];
    }

    public function handle(Request $request): string
    {
        // Define this ability in the application. Human approval does not replace it.
        Gate::forUser($this->actor)->authorize('record-agent-report', $this->evaluation);

        $written = Storage::disk('local')->put(
            'work-requests/'.$this->evaluation->id.'.json',
            json_encode($this->evaluation, JSON_THROW_ON_ERROR),
        );

        throw_unless($written, \RuntimeException::class, 'Could not record the work request.');

        return 'Work request recorded.';
    }
}
