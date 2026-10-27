<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\FactorySimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

final class FactoryController extends Controller
{
    public function index(): View
    {
        return view('factory.index');
    }

    public function status(FactorySimulator $factory): JsonResponse
    {
        return response()->json($factory->snapshot());
    }

    public function action(Request $request, FactorySimulator $factory): JsonResponse
    {
        // This demo is local-only. Authentication must be added before sharing the app.
        if (! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            abort(403, 'Local factory simulator only.');
        }
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:start_job,pause_job,resume_job,cancel_job,start_conveyor,stop_conveyor,set_conveyor_speed,inject_fault,clear_fault,emergency_stop,release_emergency,start_line,stop_line,start_factory,stop_factory,restart_production,set_auto_repeat,start_handling,stop_handling,set_handling_speed,restart_job,reset_demo,drive_forklift,pickup_pallet,drop_pallet'],
            'id' => ['nullable', 'string', 'max:30'],
            'value' => ['nullable', 'numeric', 'between:0,100'],
        ]);
        try {
            return response()->json($factory->dispatch($validated['action'], $validated['id'] ?? null, $validated['value'] ?? null));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
