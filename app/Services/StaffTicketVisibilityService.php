<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

final class StaffTicketVisibilityService
{
    private const WITHDRAW_SUBJECT_KEY = '[Commission Withdrawal Request] This ticket is opened by the system';

    /**
     * @param Builder<\App\Models\Ticket> $query
     * @return Builder<\App\Models\Ticket>
     */
    public function apply(Builder $query): Builder
    {
        foreach ($this->withdrawSubjectPrefixes() as $prefix) {
            $query->where('subject', 'not like', $prefix . '%');
        }

        return $query;
    }

    public function isVisibleSubject(?string $subject): bool
    {
        $subject = trim((string) $subject);
        foreach ($this->withdrawSubjectPrefixes() as $prefix) {
            if (str_starts_with($subject, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function withdrawSubjectPrefixes(): array
    {
        $subjects = [
            Lang::get(self::WITHDRAW_SUBJECT_KEY, [], 'zh-CN'),
            Lang::get(self::WITHDRAW_SUBJECT_KEY, [], 'zh-TW'),
            Lang::get(self::WITHDRAW_SUBJECT_KEY, [], 'en-US'),
            self::WITHDRAW_SUBJECT_KEY,
            '[提现申请]',
            '[提現申請]',
            '[Commission Withdrawal Request]',
        ];

        return array_values(array_unique(array_filter(array_map(
            static fn ($subject): string => trim((string) $subject),
            $subjects
        ))));
    }
}
