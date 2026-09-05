<?php

namespace App\Http\Controllers;

use App\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolyglotController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function identity(): JsonResponse
    {
        return response()->json(Catalog::identity());
    }

    public function years(): JsonResponse
    {
        $rows = Catalog::query('SELECT year, slug, name, status FROM v1_years ORDER BY year DESC');

        return response()->json(['data' => array_map([Catalog::class, 'clean'], $rows)]);
    }

    public function speakers(Request $request): JsonResponse
    {
        $raw = $request->query('year', '');
        $year = $raw !== '' && $raw !== null ? (int) $raw : null;

        return response()->json(['data' => Catalog::listSpeakers($year)]);
    }

    public function speaker(string $slug): JsonResponse
    {
        $speaker = Catalog::clean(Catalog::queryOne('SELECT '.Catalog::SPEAKER_COLS.' FROM v1_speakers WHERE slug = ?', [$slug]));
        if (! $speaker) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $speaker['talks'] = Catalog::talksFor($slug);
        $speaker['years'] = Catalog::talkYears($slug);

        return response()->json(['data' => $speaker]);
    }

    public function speakerYear(int $year, string $slug): JsonResponse
    {
        $speaker = Catalog::clean(Catalog::queryOne('SELECT '.Catalog::SPEAKER_COLS.' FROM v1_speakers WHERE slug = ?', [$slug]));
        if (! $speaker) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $talks = Catalog::talksFor($slug, $year);
        if (! $talks) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $years = Catalog::talkYears($slug);
        $speaker['year'] = $year;
        $speaker['years'] = $years;
        $speaker['other_years'] = array_values(array_filter($years, fn ($y) => $y !== $year));
        $speaker['talks'] = $talks;
        $speaker['languages'] = Catalog::uniqTags($talks, 'languages');
        $speaker['topics'] = Catalog::uniqTags($talks, 'topics');

        return response()->json(['data' => $speaker]);
    }

    public function sponsors(Request $request): JsonResponse
    {
        $year = $request->query('year', '');
        if ($year !== '' && $year !== null) {
            $rows = Catalog::query('SELECT '.Catalog::YEAR_SPONSOR_COLS.' FROM v1_year_sponsors WHERE year = ? ORDER BY name', [(int) $year]);
        } else {
            $rows = Catalog::query('SELECT '.Catalog::SPONSOR_COLS.' FROM v1_sponsors ORDER BY name');
        }

        return response()->json(['data' => array_map([Catalog::class, 'clean'], $rows)]);
    }

    public function sponsor(string $slug): JsonResponse
    {
        $row = Catalog::clean(Catalog::queryOne('SELECT '.Catalog::SPONSOR_COLS.' FROM v1_sponsors WHERE slug = ?', [$slug]));
        if (! $row) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $row['sponsorships'] = array_map([Catalog::class, 'clean'], Catalog::query('SELECT * FROM v1_sponsorships WHERE sponsor_slug = ?', [$slug]));

        return response()->json(['data' => $row]);
    }

    public function sponsorYear(int $year, string $slug): JsonResponse
    {
        $row = Catalog::clean(Catalog::queryOne(
            'SELECT '.Catalog::YEAR_SPONSOR_COLS.' FROM v1_year_sponsors WHERE year = ? AND slug = ?',
            [$year, $slug]
        ));
        if (! $row) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $years = Catalog::sponsorYears($slug);
        $row['years'] = $years;
        $row['other_years'] = array_values(array_filter($years, fn ($y) => $y !== $year));

        return response()->json(['data' => $row]);
    }
}
