<?php

namespace App\Http\Controllers\Tachograph;

use App\Http\Controllers\Controller;
use App\Services\Tachograph\ImportService;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Upload of local JSON fixtures (development / testing). Disabled unless TACHO_ALLOW_IMPORT=true.
 */
class ImportController extends Controller
{
    public function store(Request $request, ImportService $import)
    {
        abort_unless(config('tachograph.allow_import'), 404);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimetypes:application/json,text/plain'],
            'format' => ['required', 'in:local,mapon'],
            'driver_id' => ['nullable', 'required_if:format,mapon', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ]);

        try {
            $run = $import->import($request->file('file')->get(), $data['format'], $data['driver_id'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $message = __('Imported :n records (:invalid rejected).', ['n' => $run->records_processed, 'invalid' => $run->records_invalid]);

        return $run->driver
            ? redirect()->route('tachograph.drivers.show', $run->driver)->with('status', $message)
            : back()->with('status', $message);
    }
}
