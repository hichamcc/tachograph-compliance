<?php

namespace App\Services\Tachograph;

use App\Tachograph\Reporting\ReportData;

/**
 * Self-contained HTML file (for downloads / archiving). The in-app page uses the
 * starter kit layout instead (tachograph.reports.show).
 */
class HtmlReport
{
    public function render(ReportData $report): string
    {
        return view('tachograph.reports.standalone', [
            'report' => $report,
            'css' => $this->compiledCss(),
        ])->render();
    }

    /** Inline the built Tailwind CSS when available so the file needs no external assets. */
    private function compiledCss(): string
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return '';
        }

        $entry = json_decode(file_get_contents($manifest), true)['resources/css/app.css']['file'] ?? null;

        return $entry && is_file(public_path("build/{$entry}")) ? file_get_contents(public_path("build/{$entry}")) : '';
    }
}
