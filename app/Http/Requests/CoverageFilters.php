<?php

namespace App\Http\Requests;

use App\Services\Coverage\CoverageMatrix;
use App\Services\Coverage\FieldRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CoverageFilters extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    } // All routes use the existing database-backed admin.auth middleware.

    public function rules(): array
    {
        return ['league' => ['nullable', 'integer', 'exists:leagues,id'], 'season' => ['nullable', 'integer', Rule::exists('seasons', 'id')->when($this->filled('league'), fn ($r) => $r->where('league_id', $this->integer('league')))], 'source' => ['nullable', 'integer', 'exists:data_sources,id'], 'field' => ['nullable', Rule::in(array_keys(app(FieldRegistry::class)->fields()))], 'state' => ['nullable', Rule::in(array_keys(CoverageMatrix::STATES))], 'status' => ['nullable', Rule::in(['new', 'ignored', 'resolved'])], 'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'per_page' => ['nullable', Rule::in([15, 30, 60])], 'game_id' => ['nullable', 'integer', 'exists:matches,id'], 'issue' => ['nullable', Rule::in(['all', 'incomplete', 'conflict', 'unmapped'])]];
    }
}
