<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use OneMediaLabs\MixpostMcp\Http\Requests\Reports;

class ReportsController extends Controller
{
    public function __invoke(Reports $reports): JsonResponse
    {
        return response()->json($reports->handle());
    }
}
