<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityAnswer;
use App\Models\CommunityQuestionnaire;
use App\Models\EventPoll;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommunityQuestionnaireResults
{
    /** Counts/distributions only; never a respondent or a free-text answer.
     * @return array<string,mixed>
     */
    public function summary(CommunityQuestionnaire $form): array
    {
        $form->loadMissing(['questions' => fn ($q) => $q->orderBy('position'), 'questions.options' => fn ($q) => $q->orderBy('position')]);
        $questions = [];
        foreach ($form->questions as $question) {
            $query = CommunityAnswer::withoutGlobalScopes()->where('tenant_id', $form->tenant_id)->where('questionnaire_id', $form->id)->where('question_id', $question->id);
            $count = $query->count();
            $distribution = [];
            $statistics = null;
            if (in_array($question->type, ['multiple_choice', 'quiz', 'yes_no', 'rating'], true) && ($question->type !== 'rating' || $count >= EventPoll::SUPPRESS_BELOW)) {
                $counts = (clone $query)->selectRaw('option_id, count(*) AS total')->groupBy('option_id')->pluck('total', 'option_id');
                $distribution = $question->options->map(fn ($option) => ['label' => $option->label, 'count' => (int) ($counts[$option->id] ?? 0)])->values()->all();
            } elseif ($question->type === 'multi_select') {
                $counts = (clone $query)->selectRaw('jsonb_array_elements_text(response_payload) AS choice, count(*) AS total')->groupBy('choice')->pluck('total', 'choice');
                $distribution = $question->options->map(fn ($option) => ['label' => $option->label, 'count' => (int) ($counts[$option->id] ?? 0)])->values()->all();
            } elseif ($question->type === 'ranking' && $count >= EventPoll::SUPPRESS_BELOW) {
                $totals = array_fill_keys($question->options->pluck('id')->all(), 0);
                foreach ((clone $query)->select(['id', 'response_payload'])->lazyById(200) as $answer) {
                    foreach ($answer->response_payload ?? [] as $rank => $id) {
                        if (array_key_exists($id, $totals)) {
                            $totals[$id] += $rank + 1;
                        }
                    }
                }
                $distribution = $question->options->map(fn ($option) => ['label' => $option->label, 'average_rank' => round($totals[$option->id] / max(1, $count), 2)])->values()->all();
            } elseif (in_array($question->type, ['scale', 'number'], true) && $count >= EventPoll::SUPPRESS_BELOW) {
                $row = (clone $query)->selectRaw('min(response_number) AS minimum, max(response_number) AS maximum, avg(response_number) AS average')->first();
                $statistics = ['minimum' => (float) $row->minimum, 'maximum' => (float) $row->maximum, 'average' => round((float) $row->average, 4)];
            }
            $questions[] = ['title' => $question->title, 'type' => $question->type, 'responses' => $count, 'distribution' => $distribution, 'statistics' => $statistics];
        }

        return ['responses' => $form->submissions()->where('tenant_id', $form->tenant_id)->count(), 'questions' => $questions];
    }

    public function export(CommunityQuestionnaire $form): StreamedResponse
    {
        $form->loadMissing('questions.options');

        return response()->streamDownload(function () use ($form): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new RuntimeException('Cannot open export stream.');
            }
            fputcsv($handle, ['Member ID', 'Name', 'Email', 'Submitted at', 'Question', 'Response'], ',', '"', '');
            foreach ($form->submissions()->where('tenant_id', $form->tenant_id)->with(['member', 'answers'])->lazyById(100) as $submission) {
                foreach ($submission->answers as $answer) {
                    $question = $form->questions->firstWhere('id', $answer->question_id);
                    $options = $question->options->pluck('label', 'id');
                    $value = $answer->option_id !== null ? ($options[$answer->option_id] ?? '') : ($answer->response_text ?? $answer->response_number);
                    if ($answer->response_payload !== null && $answer->response_text === null) {
                        $value = implode(' | ', array_map(fn ($id) => $options[$id] ?? $id, $answer->response_payload));
                    }
                    $row = [$submission->member_id, $submission->member?->name ?? 'Former member', $submission->member?->email ?? '', $submission->created_at->toIso8601String(), $question->title, (string) $value];
                    fputcsv($handle, array_map($this->safeCell(...), $row), ',', '"', '');
                }
            }
            fclose($handle);
        }, 'community-responses.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function safeCell(string $value): string
    {
        return preg_match('/^[\s]*[=+@\-\t\r\n]/u', $value) === 1 || preg_match('/^[\t\r\n]/',$value) === 1 ? "'".$value : $value;
    }
}
