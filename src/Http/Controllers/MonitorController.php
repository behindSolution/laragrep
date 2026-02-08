<?php

namespace LaraGrep\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraGrep\Monitor\MonitorRepository;

class MonitorController extends Controller
{
    public function __construct(protected MonitorRepository $repository)
    {
    }

    public function list(Request $request)
    {
        $filters = $request->only([
            'scope', 'status', 'user_id', 'date_from', 'date_to', 'search',
        ]);

        $entries = $this->repository->list($filters);
        $scopes = $this->repository->distinctScopes();

        return view('laragrep::monitor.list', compact('entries', 'filters', 'scopes'));
    }

    public function detail(int $id)
    {
        $entry = $this->repository->find($id);

        if (!$entry) {
            abort(404);
        }

        return view('laragrep::monitor.detail', compact('entry'));
    }

    public function overview(Request $request)
    {
        $days = (int) ($request->get('days', 30));
        $days = max(1, min(365, $days));
        $stats = $this->repository->overview($days);

        return view('laragrep::monitor.overview', compact('stats', 'days'));
    }
}
