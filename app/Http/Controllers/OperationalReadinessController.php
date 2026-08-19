<?php

namespace App\Http\Controllers;

use App\Services\OperationalReadinessService;
use Illuminate\View\View;

class OperationalReadinessController extends Controller
{
    public function __invoke(OperationalReadinessService $readiness): View
    {
        return view('operations.readiness', ['readiness' => $readiness->summary()]);
    }
}
