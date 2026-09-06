<?php

declare(strict_types=1);

final class EmailListCleaner
{
    private const ROLE_PREFIXES = [
        'admin', 'billing', 'contact', 'hello', 'info', 'jobs', 'marketing',
        'media', 'news', 'office', 'press', 'sales', 'security', 'support',
        'team', 'webmaster', 'abuse', 'postmaster', 'noreply', 'no-reply'
    ];

    /**
     * Clean and validate a raw email list.
     *
     * @return array{summary: array<string,int>, rows: array<int,array<string,mixed>>, cleaned: array<int,string>}
     */
    public function clean(string $raw): array
    {
        $items = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $seen = [];
        $rows = [];
        $cleaned = [];

        $summary = [
            'input' => 0,
            'unique' => 0,
            'clean' => 0,
            'risky' => 0,
            'invalid' => 0,
            'duplicates' => 0,
        ];

        foreach ($items as $item) {
            $email = $this->normalize($item);
            if ($email === '') {
                continue;
            }

            $summary['input']++;
            $key = strtolower($email);

            if (isset($seen[$key])) {
                $summary['duplicates']++;
                continue;
            }

            $seen[$key] = true;
            $summary['unique']++;

            $row = $this->inspect($email);
            $rows[] = $row;

            if ($row['status'] === 'clean') {
                $summary['clean']++;
                $cleaned[] = $email;
            } elseif ($row['status'] === 'risky') {
                $summary['risky']++;
            } else {
                $summary['invalid']++;
            }
        }

        return [
            'summary' => $summary,
            'rows' => $rows,
            'cleaned' => $cleaned,
        ];
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = trim($value, " \t\n\r\0\x0B<>\"'");
        $value = preg_replace('/^mailto:/i', '', $value) ?? $value;

        return trim($value);
    }

    /** @return array<string,mixed> */
    private function inspect(string $email): array
    {
        $row = [
            'email' => $email,
            'status' => 'invalid',
            'reason' => '',
            'syntax' => false,
            'domain' => '',
            'mx' => false,
            'role' => false,
        ];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $row['reason'] = 'Invalid email syntax';
            return $row;
        }

        $row['syntax'] = true;
        [$local, $domain] = explode('@', $email, 2);
        $domain = strtolower(rtrim($domain, '.'));
        $row['domain'] = $domain;

        if ($domain === '') {
            $row['reason'] = 'Missing domain';
            return $row;
        }

        $mxHosts = [];
        $mxWeights = [];
        $hasMx = function_exists('getmxrr') && @getmxrr($domain, $mxHosts, $mxWeights);

        // RFC 5321 permits fallback to A/AAAA when MX is absent, so treat either as mail-capable.
        $hasAddressRecord = @checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA');
        $row['mx'] = $hasMx;

        if (!$hasMx && !$hasAddressRecord) {
            $row['reason'] = 'Domain has no MX, A, or AAAA record';
            return $row;
        }

        $localLower = strtolower($local);
        $row['role'] = in_array($localLower, self::ROLE_PREFIXES, true);

        if ($row['role']) {
            $row['status'] = 'risky';
            $row['reason'] = 'Role-based address';
            return $row;
        }

        $row['status'] = 'clean';
        $row['reason'] = $hasMx ? 'Syntax and MX checks passed' : 'Syntax passed; domain uses A/AAAA fallback';

        return $row;
    }
}
