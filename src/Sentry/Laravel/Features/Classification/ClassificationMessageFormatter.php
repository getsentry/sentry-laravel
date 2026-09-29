<?php

namespace Sentry\Laravel\Features\Classification;

use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Sentry\Laravel\Features\Ai\AiDataSanitizer;

/**
 * @internal
 */
class ClassificationMessageFormatter
{
    /**
     * @param string|array<string, mixed> $state
     * @param array<string, Question>     $questions
     */
    public static function formatInputMessages($state, array $questions): ?string
    {
        if (\is_string($state)) {
            $state = AiDataSanitizer::truncateContentString(AiDataSanitizer::redactBinaryInString($state));
        }

        $formattedQuestions = array_map(function ($question) {
            return self::formatQuestion($question);
        }, $questions);

        return self::encode([
            'type' => 'evaluation',
            'state' => $state,
            'questions' => (object) $formattedQuestions,
        ]);
    }

    /**
     * @param array<string, Answer> $answers
     */
    public static function formatOutputMessages(array $answers): ?string
    {
        $formattedAnswers = [];
        foreach ($answers as $key => $answer) {
            $formattedAnswers[$key] = self::formatAnswer($answer);
        }

        return self::encode([
            'type' => 'evaluation',
            'answers' => (object) $formattedAnswers,
        ]);
    }

    private static function formatQuestion(Question $question): array
    {
        if ($question instanceof Boolean) {
            $formatted = ['type' => 'noul', 'instructions' => $question->instructions];

            if ($question->criteria !== null) {
                $formatted['criteria'] = (object) $question->criteria;
            }

            return $formatted;
        }

        if ($question instanceof Choice) {
            // We cast the options to an object (aka stdClass) to force it to become an object in the shape of
            // {"0": "...", "1": "..."} . An array would be serialized as ["...", "...", ...] losing the index
            return ['type' => 'choice', 'instructions' => $question->instructions, 'criteria' => (object) $question->options];
        }

        if ($question instanceof Score) {
            return ['type' => 'score', 'instructions' => $question->instructions, 'criteria' => $question->levels];
        }

        return $question->toArray();
    }

    private static function formatAnswer(Answer $answer): array
    {
        if ($answer instanceof BooleanAnswer) {
            return ['type' => 'noul', 'noul' => $answer->probability];
        }

        if ($answer instanceof ChoiceAnswer) {
            return self::withConfidence([
                'type' => 'choice',
                'choice' => $answer->choice,
                'probabilities' => (object) $answer->probabilities,
            ], $answer->confidence);
        }

        if ($answer instanceof ScoreAnswer) {
            return self::withConfidence([
                'type' => 'score',
                'score' => $answer->score,
                'probabilities' => (object) $answer->probabilities,
                'legend' => (object) $answer->legend,
            ], $answer->confidence);
        }

        return $answer->toArray();
    }

    private static function withConfidence(array $formatted, ?float $confidence): array
    {
        if ($confidence !== null) {
            $formatted['confidence'] = $confidence;
        }

        return $formatted;
    }

    private static function encode(array $message): ?string
    {
        $encoded = json_encode([$message]);

        return $encoded !== false ? AiDataSanitizer::truncateString($encoded) : null;
    }
}
