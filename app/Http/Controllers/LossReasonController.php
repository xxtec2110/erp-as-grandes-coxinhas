<?php

namespace App\Http\Controllers;

use App\Http\Requests\LossReasonRequest;
use App\Models\LossReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LossReasonController extends Controller
{
    public function index(): View
    {
        return view('loss-reasons.index', ['reasons' => LossReason::query()->orderBy('name')->get()]);
    }

    public function store(LossReasonRequest $request): RedirectResponse
    {
        LossReason::query()->create($request->validated());

        return back()->with('success', 'Motivo cadastrado.');
    }

    public function update(LossReasonRequest $request, LossReason $lossReason): RedirectResponse
    {
        $lossReason->update($request->validated());

        return back()->with('success', 'Motivo atualizado.');
    }
}
