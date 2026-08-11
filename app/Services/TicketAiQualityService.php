<?php

namespace App\Services;

class TicketAiQualityService
{
    public const RATING_EXACT = 'exact';
    public const RATING_MINOR_EDIT = 'minor_edit';
    public const RATING_MAJOR_EDIT = 'major_edit';
    public const RATING_DISCARDED = 'discarded';
    public const RATING_UNSAFE = 'unsafe';

    /** @return array{draft_chars:int,final_chars:int,similarity_score:float,edit_ratio:float,quality_rating:string} */
    public function compare(string $draft, string $finalMessage): array
    {
        $normalizedDraft = $this->normalize($draft);
        $normalizedFinal = $this->normalize($finalMessage);
        $draftChars = $this->characters($normalizedDraft);
        $finalChars = $this->characters($normalizedFinal);
        $distance = $this->distance($draftChars, $finalChars);
        $maxLength = max(count($draftChars), count($finalChars));
        $similarity = $maxLength > 0 ? max(0.0, 1.0 - ($distance / $maxLength)) : 1.0;
        $editRatio = round(1.0 - $similarity, 4);

        return [
            'draft_chars' => mb_strlen($normalizedDraft),
            'final_chars' => mb_strlen($normalizedFinal),
            'similarity_score' => round($similarity, 4),
            'edit_ratio' => $editRatio,
            'quality_rating' => $editRatio === 0.0
                ? self::RATING_EXACT
                : ($editRatio <= 0.20 ? self::RATING_MINOR_EDIT : self::RATING_MAJOR_EDIT),
        ];
    }

    private function normalize(string $message): string
    {
        $message = trim(str_replace(["\r\n", "\r"], "\n", $message));
        $message = preg_replace('/[ \t]+/u', ' ', $message) ?? $message;
        $message = preg_replace('/\n{3,}/u', "\n\n", $message) ?? $message;

        return $message;
    }

    /** @return array<int, string> */
    private function characters(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_slice(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 1200);
    }

    /** @param array<int, string> $left @param array<int, string> $right */
    private function distance(array $left, array $right): int
    {
        if ($left === []) {
            return count($right);
        }
        if ($right === []) {
            return count($left);
        }
        if (count($left) > count($right)) {
            [$left, $right] = [$right, $left];
        }

        $previous = range(0, count($left));
        foreach ($right as $rightIndex => $rightCharacter) {
            $current = [$rightIndex + 1];
            foreach ($left as $leftIndex => $leftCharacter) {
                $current[] = min(
                    $current[$leftIndex] + 1,
                    $previous[$leftIndex + 1] + 1,
                    $previous[$leftIndex] + ($leftCharacter === $rightCharacter ? 0 : 1)
                );
            }
            $previous = $current;
        }

        return $previous[count($left)];
    }
}
