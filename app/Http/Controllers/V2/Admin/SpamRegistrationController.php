<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageDispatchLog;
use App\Models\SpamRegistrationCandidate;
use App\Services\SpamRegistrationService;
use Illuminate\Http\Request;

class SpamRegistrationController extends Controller
{
    private SpamRegistrationService $spamService;

    public function __construct(SpamRegistrationService $spamService)
    {
        $this->spamService = $spamService;
    }

    public function candidates(Request $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(1, min(100, (int) $request->input('pageSize', 20)));
        $status = trim((string) $request->input('status', ''));
        $keyword = trim((string) $request->input('keyword', ''));

        $query = SpamRegistrationCandidate::query()
            ->with(['user.plan:id,name'])
            ->orderByDesc('candidate_since')
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($keyword !== '') {
            $query->whereHas('user', function ($builder) use ($keyword): void {
                $builder->where('email', 'like', '%' . $keyword . '%');
            });
        }

        $page = $query->paginate($pageSize, ['*'], 'page', $current);
        return $this->paginate($page);
    }

    public function detail(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_spam_registration_candidate,id',
        ]);

        $candidate = SpamRegistrationCandidate::query()
            ->with(['user.plan:id,name'])
            ->findOrFail($data['id']);

        $user = $candidate->user;
        $snapshot = $user ? $this->spamService->getEvaluationSnapshot($user) : null;
        $recentLogs = $user
            ? MessageDispatchLog::query()
                ->where('channel', 'email')
                ->where('to_address', $user->email)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
            : [];

        return $this->success([
            'candidate' => $candidate,
            'snapshot' => $snapshot,
            'recent_logs' => $recentLogs,
        ]);
    }

    public function preserve(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_spam_registration_candidate,id',
            'note' => 'nullable|string|max:2000',
        ]);

        $candidate = SpamRegistrationCandidate::query()->with('user')->findOrFail($data['id']);
        return $this->success($this->spamService->preserveCandidate($candidate, $request->user()?->id, $data['note'] ?? null));
    }

    public function restore(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_spam_registration_candidate,id',
            'note' => 'nullable|string|max:2000',
        ]);

        $candidate = SpamRegistrationCandidate::query()->with('user')->findOrFail($data['id']);
        return $this->success($this->spamService->restoreCandidate($candidate, $request->user()?->id, $data['note'] ?? null));
    }

    public function freeze(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_spam_registration_candidate,id',
            'note' => 'nullable|string|max:2000',
        ]);

        $candidate = SpamRegistrationCandidate::query()->with('user')->findOrFail($data['id']);
        return $this->success($this->spamService->freezeCandidate($candidate, $request->user()?->id, $data['note'] ?? null));
    }

    public function softDelete(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_spam_registration_candidate,id',
            'note' => 'nullable|string|max:2000',
        ]);

        $candidate = SpamRegistrationCandidate::query()->with('user')->findOrFail($data['id']);
        return $this->success($this->spamService->softDeleteCandidate($candidate, $request->user()?->id, $data['note'] ?? null));
    }

    public function note(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_spam_registration_candidate,id',
            'note' => 'nullable|string|max:5000',
        ]);

        $candidate = SpamRegistrationCandidate::query()->with('user')->findOrFail($data['id']);
        return $this->success($this->spamService->saveCandidateNote($candidate, $data['note'] ?? null, $request->user()?->id));
    }
}
